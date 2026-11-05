<?php
/**
 * The Pixonaut reads a product page's designer starts from, made on the server.
 *
 * @package Pixonaut\WooCommerce
 */

declare(strict_types=1);

namespace Pixonaut\WooCommerce\Storefront;

use Closure;
use Pixonaut\WooCommerce\Api\ApiError;
use Pixonaut\WooCommerce\Api\Catalogue;
use Pixonaut\WooCommerce\Api\Designs;
use Pixonaut\WooCommerce\Catalogue\TemplateForm;
use Pixonaut\WooCommerce\Catalogue\CatalogueCache;
use Pixonaut\WooCommerce\Connection\WorkspaceSettings;
use Pixonaut\WooCommerce\DesignerLanguage;
use Pixonaut\WooCommerce\Schema;

/**
 * Reads what each side's designer opens with — its template, the placement's
 * box, and the blank's views and variants — so the page prints at its final
 * layout and the SDK starts without reading them again in the browser.
 * Answers are cached like the admin's catalogue reads, as the JSON text
 * Pixonaut sent, which the page passes on unchanged. When Pixonaut fails,
 * the page stops reading for a minute and the SDK reads in the browser as it
 * would without them, so an outage does not hold every product page up for
 * the API's timeout.
 */
final class ServerReads {

	/**
	 * Set for a minute after a read failed; product pages skip the reads
	 * while it is.
	 */
	public const PAUSE_TRANSIENT = Schema::SERVER_READS_PAUSE_TRANSIENT;

	/**
	 * A minute, in seconds.
	 */
	public const PAUSE_SECONDS = 60;

	/**
	 * @param Catalogue      $catalogue Reads Pixonaut.
	 * @param CatalogueCache $cache     Keeps the answers for a few minutes.
	 * @param Designs        $designs   Reads the designs a correction opens.
	 */
	public function __construct(
		private readonly Catalogue $catalogue,
		private readonly CatalogueCache $cache,
		private readonly Designs $designs,
	) {
	}

	/**
	 * Each side's reads, by side key, for the sides that open a template by
	 * its id; a side whose read failed is left out.
	 *
	 * @param array<string, array<string, mixed>> $mounts Mount options by side key.
	 * @return array<string, array<string, mixed>> `template`, and with a blank `placement` and `blank` (`mockups`, `variants`), each a JSON object's text.
	 */
	public function for_mounts( array $mounts ): array {
		$reads = array();
		foreach ( $mounts as $side => $options ) {
			if ( false !== get_transient( self::PAUSE_TRANSIENT ) ) {
				break;
			}
			$template_id = $options['templateId'] ?? null;
			// A stored design or a correction opens from its own reads.
			if ( ! is_string( $template_id ) || isset( $options['design'] ) ) {
				continue;
			}
			try {
				$reads[ (string) $side ] = $this->side( $template_id, $options['blankId'] ?? null, $options['placementId'] ?? null, $options['locale'] ?? null );
			} catch ( ApiError ) {
				set_transient( self::PAUSE_TRANSIENT, 1, self::PAUSE_SECONDS );
			}
		}
		return $reads;
	}

	/**
	 * The ids of a template's groups, for a side the page has no read of: a
	 * correction's, or a variation's on another template. Null when Pixonaut
	 * could not be asked.
	 *
	 * @param string $template_id `<baseId>@<version>`.
	 * @param mixed  $locale      The mount's `locale`, when it names one.
	 * @return list<string>|null
	 */
	public function template_groups( string $template_id, mixed $locale ): ?array {
		if ( false !== get_transient( self::PAUSE_TRANSIENT ) ) {
			return null;
		}
		try {
			$template = json_decode( $this->template( $template_id, self::supported( $locale ) ), true );
		} catch ( ApiError ) {
			set_transient( self::PAUSE_TRANSIENT, 1, self::PAUSE_SECONDS );
			return null;
		}
		return array_column( TemplateForm::groups( $template ), 'id' );
	}

	/**
	 * Whether a template's background shows what stands behind it: null when
	 * Pixonaut does not say, or could not be asked.
	 *
	 * @param string $template_id `<baseId>@<version>`.
	 */
	public function background_see_through( string $template_id ): ?bool {
		if ( false !== get_transient( self::PAUSE_TRANSIENT ) ) {
			return null;
		}
		try {
			$template = json_decode( $this->template( $template_id, null ), true );
		} catch ( ApiError ) {
			set_transient( self::PAUSE_TRANSIENT, 1, self::PAUSE_SECONDS );
			return null;
		}
		$see_through = is_array( $template ) ? ( $template['backgroundSeeThrough'] ?? null ) : null;
		return is_bool( $see_through ) ? $see_through : null;
	}

	/**
	 * A template as the designer reads it, cached.
	 *
	 * @param string      $template_id `<baseId>@<version>`.
	 * @param string|null $language    The language to read it in; null for the workspace's default.
	 *
	 * @throws ApiError When Pixonaut refused or could not be reached.
	 */
	private function template( string $template_id, ?string $language ): string {
		return $this->remember(
			'storefront-template',
			array( 'id' => $template_id ) + ( null === $language ? array() : array( 'lang' => $language ) ),
			fn (): string => $this->catalogue->template_answer( $template_id, $language )
		);
	}

	/**
	 * @param string $template_id  `<baseId>@<version>`.
	 * @param mixed  $blank_id     The blank, when the side has one.
	 * @param mixed  $placement_id Its placement.
	 * @param mixed  $locale       The mount's `locale`, when it names one.
	 * @return array<string, mixed>
	 *
	 * @throws ApiError When Pixonaut refused or could not be reached.
	 */
	private function side( string $template_id, mixed $blank_id, mixed $placement_id, mixed $locale ): array {
		// Without a `locale` the designer speaks the workspace's default language, which the template is read in without `lang`.
		$mount_language = self::supported( $locale );
		$reads          = array( 'template' => $this->template( $template_id, $mount_language ) );
		if ( ! is_string( $blank_id ) || ! is_string( $placement_id ) ) {
			return $reads;
		}
		$reads['placement'] = $this->remember(
			'storefront-constraints',
			array(
				'id'          => $template_id,
				'blankId'     => $blank_id,
				'placementId' => $placement_id,
			),
			fn (): string => $this->catalogue->constraints_answer( $template_id, $blank_id, $placement_id )
		);
		$template           = json_decode( $reads['template'], true );
		$reads['blank']     = $this->blank( $blank_id, self::language( $locale, is_array( $template ) ? ( $template['workspaceLanguage'] ?? null ) : null ) );
		return $reads;
	}

	/**
	 * What the page prints each side that corrects a stored design at, by
	 * side key: the design's print size, whose shape the SDK draws the
	 * correction in, and on a blank the blank's views, for the strip of
	 * views at its final layout. The SDK reads the views again through the
	 * correction link, so none of it is passed on to it. A side whose read
	 * failed is left out.
	 *
	 * @param array<string, array<string, mixed>> $mounts Mount options by side key.
	 * @return array<string, array<string, mixed>> `printSize` (`w`, `h`) and with a blank `blank` (`mockups`, `variants`), each a JSON object's text.
	 */
	public function correction_views( array $mounts ): array {
		$reads = array();
		foreach ( $mounts as $side => $options ) {
			if ( false !== get_transient( self::PAUSE_TRANSIENT ) ) {
				break;
			}
			$design_id = $options['design'] ?? null;
			if ( ! is_string( $design_id ) ) {
				continue;
			}
			$blank_id = $options['blankId'] ?? null;
			try {
				$reads[ (string) $side ]['printSize'] = $this->print_size( $design_id );
				if ( is_string( $blank_id ) && is_string( $options['placementId'] ?? null ) ) {
					$reads[ (string) $side ]['blank'] = $this->blank( $blank_id, self::language( $options['locale'] ?? null ) );
				}
			} catch ( ApiError ) {
				// The print size read before a blank read failed still shapes the frame.
				set_transient( self::PAUSE_TRANSIENT, 1, self::PAUSE_SECONDS );
			}
		}
		return $reads;
	}

	/**
	 * The size a design prints at, as `{w, h}` in millimetres, cached: a
	 * correction keeps the size the design was bought at, so it does not
	 * change. `{}` for a design that holds none.
	 *
	 * @param string $design_id The design.
	 *
	 * @throws ApiError When Pixonaut refused or could not be reached.
	 */
	private function print_size( string $design_id ): string {
		return $this->remember(
			'storefront-design-size',
			array( 'id' => $design_id ),
			function () use ( $design_id ): string {
				$size = $this->designs->get( $design_id )[ Designs::PRINT_SIZE ] ?? null;
				return (string) wp_json_encode(
					is_array( $size ) ? array(
						'w' => $size['widthMm'] ?? null,
						'h' => $size['heightMm'] ?? null,
					) : (object) array()
				);
			}
		);
	}

	/**
	 * The language a side's designer is shown in, which names the blank's
	 * options and views in it too: the mount's `locale`, else the workspace's
	 * default language, which the designer uses without one, as the template
	 * read with it names it (`workspaceLanguage`), else as the store last
	 * read it.
	 *
	 * @param mixed $locale    The mount's `locale`.
	 * @param mixed $workspace The template answer's `workspaceLanguage`; null without one.
	 * @return string|null One of `DesignerLanguage::languages()`; null when the store does not know the workspace's.
	 */
	private static function language( mixed $locale, mixed $workspace = null ): ?string {
		return self::supported( $locale ) ?? self::supported( $workspace ) ?? WorkspaceSettings::default_language();
	}

	/**
	 * @param mixed $language A language code, or anything else.
	 * @return string|null The code when it is one of `DesignerLanguage::languages()`, else null.
	 */
	private static function supported( mixed $language ): ?string {
		return is_string( $language ) && in_array( $language, DesignerLanguage::languages(), true ) ? $language : null;
	}

	/**
	 * The blank's views and variants, cached.
	 *
	 * @param string      $blank_id The blank.
	 * @param string|null $language The language to name its options and views in; null for their names as written.
	 * @return array{mockups: string, variants: string}
	 *
	 * @throws ApiError When Pixonaut refused or could not be reached.
	 */
	private function blank( string $blank_id, ?string $language ): array {
		$args  = array( 'id' => $blank_id ) + ( null === $language ? array() : array( 'lang' => $language ) );
		$blank = $this->cache->remember( 'storefront-blank-views', $args, fn (): array => $this->catalogue->blank_views_answers( $blank_id, $language ) );
		return array(
			'mockups'  => is_string( $blank['mockups'] ?? null ) ? $blank['mockups'] : '{}',
			'variants' => is_string( $blank['variants'] ?? null ) ? $blank['variants'] : '{}',
		);
	}

	/**
	 * One answer's text, cached.
	 *
	 * @param string                $what Which answer.
	 * @param array<string, string> $args What it was asked with.
	 * @param Closure               $load Asks Pixonaut.
	 * @phpstan-param Closure(): string $load
	 *
	 * @throws ApiError When Pixonaut refused or could not be reached.
	 */
	private function remember( string $what, array $args, Closure $load ): string {
		$answer = $this->cache->remember( $what, $args, static fn (): array => array( 'json' => $load() ) );
		return is_string( $answer['json'] ?? null ) ? $answer['json'] : '{}';
	}

	/**
	 * A side's reads decoded, for working out its layout.
	 *
	 * @param array<string, mixed> $reads A side's reads, from `for_mounts()`.
	 * @return array<string, mixed>
	 */
	public static function decoded( array $reads ): array {
		$decoded = array();
		foreach ( $reads as $key => $read ) {
			$decoded[ $key ] = is_array( $read ) ? self::decoded( $read ) : json_decode( (string) $read, true );
		}
		return $decoded;
	}

	/**
	 * The reads as one JSON object, by side key, for a script element: each
	 * answer as Pixonaut sent it, with `<`, `>` and `&` escaped so the text
	 * cannot close the element.
	 *
	 * @param array<string, array<string, mixed>> $reads The reads, from `for_mounts()`.
	 */
	public static function script_json( array $reads ): string {
		return strtr(
			self::json_object( $reads ),
			array(
				'<' => '\u003C',
				'>' => '\u003E',
				'&' => '\u0026',
			)
		);
	}

	/**
	 * @param array<string, mixed> $reads Reads, or one side's.
	 */
	private static function json_object( array $reads ): string {
		$members = array();
		foreach ( $reads as $key => $read ) {
			$members[] = wp_json_encode( (string) $key ) . ':' . ( is_array( $read ) ? self::json_object( $read ) : (string) $read );
		}
		return '{' . implode( ',', $members ) . '}';
	}
}
