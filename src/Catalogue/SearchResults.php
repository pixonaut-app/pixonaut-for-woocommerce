<?php
/**
 * Pixonaut search answers as the admin's search fields list them.
 *
 * @package Pixonaut\WooCommerce
 */

declare(strict_types=1);

namespace Pixonaut\WooCommerce\Catalogue;

use Pixonaut\WooCommerce\Api\Catalogue;

/**
 * Maps Pixonaut's catalogue answers to `{id, text}` options, plus what the
 * field needs next (a blank's placements) and the picture shown beside each
 * name (`thumbnail`, empty without one). Never a key leaves the server; the
 * picture links work for an hour or two, so they are shown, never stored.
 */
final class SearchResults {

	/**
	 * `GET /v1/templates` items: one option per template, at its latest live
	 * version, saying whether that version offers the customer a field and
	 * whether its background shows through, when Pixonaut says so.
	 *
	 * @param list<array<string, mixed>> $items The answer's `items`.
	 * @return list<array{id: string, text: string, thumbnail: string, offersProperties?: bool, backgroundSeeThrough?: bool}>
	 */
	public static function templates( array $items ): array {
		$options = array();
		foreach ( $items as $item ) {
			$base_id = self::text( $item['baseId'] ?? null );
			$version = $item['latestVersion'] ?? null;
			if ( null === $base_id || ! is_int( $version ) || $version < 1 ) {
				continue;
			}
			$id         = (string) new TemplateId( $base_id, $version );
			$offers     = $item['offersProperties'] ?? null;
			$background = $item['backgroundSeeThrough'] ?? null;
			$options[]  = array(
				'id'        => $id,
				'text'      => self::text( $item['name'] ?? null ) ?? $id,
				'thumbnail' => self::text( $item['previewUrl'] ?? null ) ?? '',
			) + ( is_bool( $offers ) ? array( 'offersProperties' => $offers ) : array() )
				+ ( is_bool( $background ) ? array( 'backgroundSeeThrough' => $background ) : array() );
		}
		return $options;
	}

	/**
	 * `GET /v1/blanks` blanks: one option per blank, with its placements and
	 * its first photo, leaving out a blank whose import is running or failed.
	 *
	 * @param list<array<string, mixed>> $blanks The answer's `blanks`.
	 * @return list<array{id: string, text: string, thumbnail: string, placements: list<array{id: string, text: string, maxWidthMm?: float, maxHeightMm?: float}>}>
	 */
	public static function blanks( array $blanks ): array {
		$options = array();
		foreach ( $blanks as $blank ) {
			$id     = self::text( $blank['id'] ?? null );
			$status = $blank['status'] ?? Catalogue::BLANK_READY;
			if ( null === $id || Catalogue::BLANK_READY !== $status ) {
				continue;
			}
			$options[] = array(
				'id'         => $id,
				'text'       => self::text( $blank['name'] ?? null ) ?? $id,
				'thumbnail'  => self::text( $blank['thumbnailUrl'] ?? null ) ?? '',
				'placements' => self::placements( $blank ),
			);
		}
		return $options;
	}

	/**
	 * A blank's placements, named as customers see them (`shopName`) when it
	 * has one, with the largest print each takes when Pixonaut says.
	 *
	 * @param array<string, mixed> $blank A blank from `GET /v1/blanks` or `GET /v1/blanks/{id}`.
	 * @return list<array{id: string, text: string, maxWidthMm?: float, maxHeightMm?: float}>
	 */
	public static function placements( array $blank ): array {
		$options = array();
		foreach ( is_array( $blank['placements'] ?? null ) ? $blank['placements'] : array() as $placement ) {
			$id = is_array( $placement ) ? self::text( $placement['id'] ?? null ) : null;
			if ( null === $id ) {
				continue;
			}
			$options[] = array(
				'id'   => $id,
				'text' => self::text( $placement['shopName'] ?? null ) ?? self::text( $placement['name'] ?? null ) ?? $id,
			) + self::limit( $placement );
		}
		return $options;
	}

	/**
	 * A placement's largest print, `maxWidthMm` and `maxHeightMm`; empty
	 * unless both are millimetres above zero.
	 *
	 * @param array<mixed> $placement A placement as Pixonaut gave it.
	 * @return array{maxWidthMm?: float, maxHeightMm?: float}
	 */
	public static function limit( array $placement ): array {
		$width  = $placement['maxWidthMm'] ?? null;
		$height = $placement['maxHeightMm'] ?? null;
		$width  = is_int( $width ) || is_float( $width ) ? $width : 0;
		$height = is_int( $height ) || is_float( $height ) ? $height : 0;
		if ( $width <= 0 || $height <= 0 ) {
			return array();
		}
		return array(
			'maxWidthMm'  => (float) $width,
			'maxHeightMm' => (float) $height,
		);
	}

	/**
	 * Fitting-templates items: only the templates whose artwork is legal on
	 * the placement, each with its preview (none for a draft never published)
	 * and, on a placement whose print area changes with an option, the sizes
	 * it cannot be printed at, with the note and the warning that name them.
	 *
	 * @param list<array<string, mixed>> $items The answer's `items`.
	 * @return list<array{id: string, text: string, thumbnail: string, misfits: list<string>, note: string, warning: string}>
	 */
	public static function fitting_templates( array $items ): array {
		$options = array();
		foreach ( $items as $item ) {
			$verdict = is_array( $item['verdict'] ?? null ) ? $item['verdict'] : array();
			$id      = self::text( $item['templateId'] ?? null );
			if ( true !== ( $verdict['legal'] ?? null ) || null === $id || null === TemplateId::parse( $id ) ) {
				continue;
			}
			$options[] = array(
				'id'        => $id,
				'text'      => self::text( $item['name'] ?? null ) ?? $id,
				'thumbnail' => self::text( $item['previewUrl'] ?? null ) ?? '',
			) + self::misfit_fields( MisfitSizes::of( $item['sizes'] ?? null ) );
		}
		return $options;
	}

	/**
	 * A fitting-templates item naming other sizes it cannot be printed at
	 * than it did: those a product sells, of those the blank has.
	 *
	 * @param array<string, mixed> $option  The item.
	 * @param string[]             $misfits The sizes.
	 * @phpstan-param list<string> $misfits
	 * @return array<string, mixed>
	 */
	public static function naming_misfits( array $option, array $misfits ): array {
		return array_merge( $option, self::misfit_fields( $misfits ) );
	}

	/**
	 * What a fitting-templates item says of the sizes it cannot be printed
	 * at: the sizes, and the note and the warning that name them.
	 *
	 * @param string[] $misfits The sizes.
	 * @phpstan-param list<string> $misfits
	 * @return array{misfits: list<string>, note: string, warning: string}
	 */
	private static function misfit_fields( array $misfits ): array {
		return array(
			'misfits' => $misfits,
			'note'    => MisfitSizes::note( $misfits ),
			'warning' => MisfitSizes::warning( $misfits ),
		);
	}

	private static function text( mixed $value ): ?string {
		return is_string( $value ) && '' !== $value ? $value : null;
	}
}
