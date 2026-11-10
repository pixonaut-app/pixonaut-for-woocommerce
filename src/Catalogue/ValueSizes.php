<?php
/**
 * A side's print size per value of one of its product's attributes.
 *
 * @package Pixonaut\WooCommerce
 */

declare(strict_types=1);

namespace Pixonaut\WooCommerce\Catalogue;

use WC_Product;

/**
 * On a variable product, a side can be given a print size per value of one
 * attribute its variations are chosen by, once: Posterstorlek 30×40 cm
 * prints at 300 × 400 mm. Every variation with that value prints at it,
 * unless it has a size of its own (`VariationSize`); a variation set to any
 * value of the attribute prints at the product's sizes.
 */
final class ValueSizes {

	/**
	 * @param string                   $attribute The attribute, as a variation names it: `pa_size`, or a custom attribute's name as a slug.
	 * @param array<string, PrintSize> $sizes     Each value's size, by the value as a variation stores it: a term's slug, or a custom attribute's value as entered.
	 */
	public function __construct(
		public readonly string $attribute,
		public readonly array $sizes,
	) {
	}

	/**
	 * The size of a variation's value; null when it has none, or is set to any value.
	 *
	 * @param array<string, mixed> $attributes The variation's attributes, as `WC_Product::get_attributes()` gives them.
	 */
	public function size_for( array $attributes ): ?PrintSize {
		$value = $attributes[ $this->attribute ] ?? null;
		return is_string( $value ) && '' !== $value ? $this->sizes[ $value ] ?? null : null;
	}

	/**
	 * Whether it holds no size: a side entered with it has none.
	 */
	public function is_empty(): bool {
		return array() === $this->sizes;
	}

	/**
	 * The attributes of a variation, as `size_for()` takes them.
	 *
	 * @param WC_Product $variation A variation.
	 * @return array<string, mixed>
	 */
	public static function attributes_of( WC_Product $variation ): array {
		return $variation->is_type( 'variation' ) ? $variation->get_attributes() : array();
	}

	/**
	 * @param mixed $data Stored shape, see to_array().
	 * @return self|null Null when the data names no attribute or no size.
	 */
	public static function from_array( mixed $data ): ?self {
		if ( ! is_array( $data ) || ! is_string( $data['attribute'] ?? null ) || '' === $data['attribute'] || ! is_array( $data['sizes'] ?? null ) ) {
			return null;
		}
		$sizes = array();
		foreach ( $data['sizes'] as $value => $size ) {
			$parsed = PrintSize::from_array( $size );
			if ( null !== $parsed ) {
				$sizes[ (string) $value ] = $parsed;
			}
		}
		return array() === $sizes ? null : new self( $data['attribute'], $sizes );
	}

	/**
	 * @return array{attribute: string, sizes: array<string, array<string, float|string|bool>>}
	 */
	public function to_array(): array {
		return array(
			'attribute' => $this->attribute,
			'sizes'     => array_map( static fn ( PrintSize $size ): array => $size->stored(), $this->sizes ),
		);
	}
}
