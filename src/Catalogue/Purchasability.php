<?php
/**
 * Keeps products whose Pixonaut configuration is invalid out of carts.
 *
 * @package Pixonaut\WooCommerce
 */

declare(strict_types=1);

namespace Pixonaut\WooCommerce\Catalogue;

use Pixonaut\WooCommerce\Pricing\ProductPricing;
use Pixonaut\WooCommerce\Storefront\ProductPageCache;
use WC_Product;

/**
 * A personalised product with an invalid configuration cannot be bought: it
 * would reach the printshop as something Pixonaut cannot print. A variation is
 * judged by the configuration it resolves to; a product or variation
 * personalised on a blank, also by its blank variant (see `ProductVariant`);
 * a variable product can be bought while one of its variations can. A
 * standing side printed from a design per row of its blank's print areas
 * sells only the variants of a row it has a design for: a size its template
 * cannot be printed at has none. A book sold at a page count its printshop
 * gives no price for, as its cost was last read, cannot be bought either.
 */
final class Purchasability {

	/**
	 * The filters a variation's purchasability is read through.
	 */
	private const FILTERS = array( 'woocommerce_is_purchasable', 'woocommerce_variation_is_purchasable' );

	public function register(): void {
		foreach ( self::FILTERS as $filter ) {
			add_filter( $filter, array( $this, 'filter' ), 10, 2 );
		}
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
			return self::sellable( $config ) && ProductVariant::sellable( $product, $config ) && self::printed( $product, $config ) && ! ProductPricing::unpriced_pages( $product );
		}
		if ( 0 === $product->get_id() ) {
			return self::any_variation( $product );
		}
		// A page asks for a variable product's several times, its cards' and its own; the variations' configurations and labels it reads are part of the key.
		return RequestCache::remember(
			implode( '|', array( 'purchasable', ProductConfig::variations_key( $product ), RequestCache::filters_key( array_merge( self::FILTERS, array( 'woocommerce_attribute_label' ) ) ) ) ),
			// On the shop's pages, which ask for it of every card, kept for the next request with what else a product page keeps of it.
			static fn (): bool => is_admin()
				? self::any_variation( $product )
				: ProductPageCache::remember( $product, 'purchasable', '', static fn (): bool => self::any_variation( $product ) )
		);
	}

	/**
	 * Whether one of a variable product's variations can be bought.
	 *
	 * @param WC_Product $product A variable product.
	 */
	private static function any_variation( WC_Product $product ): bool {
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
