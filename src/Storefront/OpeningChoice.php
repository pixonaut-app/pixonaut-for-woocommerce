<?php
/**
 * The variation a variable product's page opens on.
 *
 * @package Pixonaut\WooCommerce
 */

declare(strict_types=1);

namespace Pixonaut\WooCommerce\Storefront;

use WC_Product;
use WC_Product_Variable;
use WC_Product_Variation;

/**
 * Reads the attribute values a variable product's add-to-cart form opens on,
 * as WooCommerce chooses them: the request's `attribute_*` values, else the
 * product's defaults. A split card's link chooses some of them this way.
 */
final class OpeningChoice {

	/**
	 * The value the form opens each attribute on, by its field name, as
	 * `wc_dropdown_variation_attribute_options()` chooses it; an empty
	 * string for an attribute it opens on no value of.
	 *
	 * @param WC_Product $product A variable product.
	 * @return array<string, string>
	 */
	public static function values( WC_Product $product ): array {
		if ( ! $product instanceof WC_Product_Variable ) {
			return array();
		}
		$chosen = array();
		foreach ( array_keys( $product->get_variation_attributes() ) as $name ) {
			$field = 'attribute_' . sanitize_title( (string) $name );
			// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read as WooCommerce reads it to choose the option; nothing is changed.
			$chosen[ $field ] = isset( $_REQUEST[ $field ] ) ? sanitize_text_field( wp_unslash( (string) $_REQUEST[ $field ] ) ) : $product->get_variation_default_attribute( (string) $name );
		}
		return $chosen;
	}

	/**
	 * The first of `$variations` the product's page opens on: one whose
	 * attributes hold every value the form opens on, an attribute of "any"
	 * value holding all, and that the form offers. 0 for none, for a choice
	 * of no value at all, and anywhere but the product's own page.
	 *
	 * @param WC_Product             $product    The variable product.
	 * @param array<int, WC_Product> $variations Its variations by id, in the order to try them.
	 */
	public static function variation_among( WC_Product $product, array $variations ): int {
		if ( ! is_product() || get_queried_object_id() !== $product->get_id() ) {
			return 0;
		}
		$chosen = array_filter( self::values( $product ), static fn ( string $value ): bool => '' !== $value );
		if ( array() === $chosen ) {
			return 0;
		}
		foreach ( $variations as $id => $variation ) {
			if ( ! $variation instanceof WC_Product_Variation || ! self::offered( $variation ) ) {
				continue;
			}
			$attributes = $variation->get_variation_attributes();
			$held       = array_filter( $chosen, static fn ( string $value, string $field ): bool => '' === (string) ( $attributes[ $field ] ?? '' ) || 0 === strcasecmp( (string) $attributes[ $field ], $value ), ARRAY_FILTER_USE_BOTH );
			if ( count( $held ) === count( $chosen ) ) {
				return (int) $id;
			}
		}
		return 0;
	}

	/**
	 * Whether the add-to-cart form offers the variation, as
	 * `WC_Product_Variable::get_available_variations()` decides: a variation
	 * out of stock while the store hides those, or one not visible, is left
	 * out of the form, which then opens on none.
	 *
	 * @param WC_Product_Variation $variation A variation.
	 */
	public static function offered( WC_Product_Variation $variation ): bool {
		if ( 'yes' === get_option( 'woocommerce_hide_out_of_stock_items' ) && ! $variation->is_in_stock() ) {
			return false;
		}
		return $variation->exists() && $variation->variation_is_visible();
	}
}
