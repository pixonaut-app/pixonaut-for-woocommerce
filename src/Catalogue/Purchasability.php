<?php
/**
 * Keeps products whose Pixonaut configuration is invalid out of carts.
 *
 * @package Pixonaut\WooCommerce
 */

declare(strict_types=1);

namespace Pixonaut\WooCommerce\Catalogue;

use WC_Product;

/**
 * A personalised product with an invalid configuration cannot be bought: it
 * would reach the printshop as something Pixonaut cannot print. A variation is
 * judged by the configuration it resolves to; a product or variation
 * personalised on a blank, also by its blank variant (see `ProductVariant`);
 * a variable product can be bought while one of its variations can. A
 * standing side printed from a design per row of its blank's print areas
 * sells only the variants of a row it has a design for: a size its template
 * cannot be printed at has none.
 */
final class Purchasability {

	public function register(): void {
		add_filter( 'woocommerce_is_purchasable', array( $this, 'filter' ), 10, 2 );
		add_filter( 'woocommerce_variation_is_purchasable', array( $this, 'filter' ), 10, 2 );
	}

	/**
	 * @param bool       $purchasable What WooCommerce decided.
	 * @param WC_Product $product     The product or variation.
	 */
	public function filter( bool $purchasable, WC_Product $product ): bool {
		if ( ! $purchasable ) {
			return false;
		}
		if ( ! $product->is_type( 'variable' ) || array() === $product->get_children() ) {
			$config = ProductConfig::config_for( $product );
			// A product sells one of its blank's variants: without one, or with a retired one, Pixonaut refuses its order.
			return self::sellable( $config ) && ProductVariant::sellable( $product, $config ) && self::printed( $product, $config );
		}
		foreach ( $product->get_children() as $child_id ) {
			$variation = wc_get_product( $child_id );
			// The variation's own purchasability runs this filter on its resolved configuration.
			if ( $variation instanceof WC_Product && $variation->is_purchasable() ) {
				return true;
			}
		}
		return false;
	}

	private static function sellable( ProductConfig $config ): bool {
		return ! $config->personalise || $config->valid;
	}

	/**
	 * Whether every standing side printed per row has a design for the
	 * product's blank variant.
	 *
	 * @param WC_Product    $product The product or variation.
	 * @param ProductConfig $config  Its configuration.
	 */
	private static function printed( WC_Product $product, ProductConfig $config ): bool {
		if ( ! $config->personalise || null === $config->blank_id ) {
			return true;
		}
		$variant = null;
		foreach ( $config->sides as $side ) {
			if ( ! $side->is_standing() || array() === $side->standing_rows ) {
				continue;
			}
			$variant ??= ProductVariant::sold_by( $product, $config );
			if ( null === $side->standing_design_for( $variant ) ) {
				return false;
			}
		}
		return true;
	}
}
