<?php
/**
 * One variant of a Pixonaut blank.
 *
 * @package Pixonaut\WooCommerce
 */

declare(strict_types=1);

namespace Pixonaut\WooCommerce\Catalogue;

/**
 * A variant as `GET /v1/blanks/{id}/variants` lists it: the ref an order
 * names, its option labels by axis (`Color: Red, Size: M`), whether it is
 * retired, and the views it has a photo in. Read in a language, it keeps
 * its options as written beside them, which a product's attributes may
 * still name.
 */
final class BlankVariant {

	/**
	 * @param string                $ref     The variant's ref, `v_…`.
	 * @param array<string, string> $options Each axis's label, in the blank's axis order; empty on a blank without options.
	 * @param string|null           $retired Why the variant is retired (`retired`, `no_longer_sold`, …); null while it is sold.
	 * @param array[]               $views   The views the variant has a photo in, in the blank's order: each one's
	 *                                       `id`, the `placement` it photographs and its `label`; empty when it has none.
	 * @param array<string, string> $written The options as written in Pixonaut, when `$options` names them in another language; empty when it does not.
	 * @phpstan-param list<array{id: string, placement: string, label: string}> $views
	 */
	public function __construct(
		public readonly string $ref,
		public readonly array $options = array(),
		public readonly ?string $retired = null,
		public readonly array $views = array(),
		public readonly array $written = array(),
	) {
	}

	/**
	 * Variants read in a language, each with its options as written from
	 * the same variant read without one. A variant missing from `$written`
	 * keeps none.
	 *
	 * @param BlankVariant[] $variants The variants in the language.
	 * @param BlankVariant[] $written  The same variants with their names as written.
	 * @phpstan-param list<BlankVariant> $variants
	 * @phpstan-param list<BlankVariant> $written
	 * @return list<self>
	 */
	public static function with_written( array $variants, array $written ): array {
		$options = array();
		foreach ( $written as $variant ) {
			$options[ $variant->ref ] = $variant->options;
		}
		return array_map(
			static fn ( self $variant ): self => new self( $variant->ref, $variant->options, $variant->retired, $variant->views, $options[ $variant->ref ] ?? array() ),
			$variants
		);
	}

	/**
	 * The variants in an answer's `variants` list; an entry without a ref is
	 * left out.
	 *
	 * @param array<mixed> $rows The list.
	 * @return list<self>
	 */
	public static function list_from( array $rows ): array {
		$variants = array();
		foreach ( $rows as $row ) {
			if ( ! is_array( $row ) || ! is_string( $row['ref'] ?? null ) || '' === $row['ref'] ) {
				continue;
			}
			$options = array();
			foreach ( is_array( $row['options'] ?? null ) ? $row['options'] : array() as $axis => $label ) {
				if ( is_string( $axis ) && is_string( $label ) ) {
					$options[ $axis ] = $label;
				}
			}
			$retired = $row['retired'] ?? null;
			$views   = array();
			foreach ( is_array( $row['views'] ?? null ) ? $row['views'] : array() as $view ) {
				if ( is_array( $view ) && is_string( $view['viewId'] ?? null ) && is_string( $view['placementId'] ?? null ) && is_string( $view['label'] ?? null ) ) {
					$views[] = array(
						'id'        => $view['viewId'],
						'placement' => $view['placementId'],
						'label'     => $view['label'],
					);
				}
			}
			$variants[] = new self(
				$row['ref'],
				$options,
				// A retirement whose reason this version does not know is a retirement all the same.
				is_array( $retired ) ? ( is_string( $retired['reason'] ?? null ) ? $retired['reason'] : 'retired' ) : null,
				$views
			);
		}
		return $variants;
	}

	/**
	 * Whether options by axis name this variant: every axis it has and no
	 * other, each with its label, case and spacing aside, as read or as
	 * written in Pixonaut. No options name a variant without any.
	 *
	 * @param array<string, string> $options Labels by axis.
	 */
	public function is_named_by( array $options ): bool {
		$named = ProductVariant::normalised( $options );
		return ProductVariant::normalised( $this->options ) === $named
			|| ( array() !== $this->written && ProductVariant::normalised( $this->written ) === $named );
	}

	/**
	 * The variant's options as the store manager reads them, `Color: Red, Size: M`;
	 * its ref on a blank without options.
	 */
	public function label(): string {
		if ( array() === $this->options ) {
			return $this->ref;
		}
		$parts = array();
		foreach ( $this->options as $axis => $label ) {
			$parts[] = $axis . ': ' . $label;
		}
		return implode( ', ', $parts );
	}
}
