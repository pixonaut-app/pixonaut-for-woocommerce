<?php
/**
 * The variation a variable product's picture is drawn for.
 *
 * @package Pixonaut\WooCommerce
 */

declare(strict_types=1);

namespace Pixonaut\WooCommerce\Catalogue;

use Generator;
use WC_Product;
use WC_Product_Variation;

/**
 * A variable product's own picture shows one of the variations a customer
 * can buy, never a variant of its blank that it does not sell: the first
 * that agrees with the product's default attributes, else the first of
 * them, in the product's order of variations. A variation leaving a default
 * attribute open agrees with it. A product with no such variation is drawn
 * as itself, for no variant.
 */
final class PicturedVariation {

	/**
	 * The variation the product's picture is drawn for; null for a product
	 * that is not variable, or sells no variation.
	 *
	 * @param WC_Product $product A product or variation.
	 */
	public static function of( WC_Product $product ): ?WC_Product {
		if ( ! $product->is_type( 'variable' ) ) {
			return null;
		}
		$defaults = array_filter( array_map( 'strval', $product->get_default_attributes() ), static fn ( string $value ): bool => '' !== $value );
		$first    = null;
		foreach ( self::bought_of( $product ) as $variation ) {
			if ( self::agrees( $variation, $defaults ) ) {
				return $variation;
			}
			$first ??= $variation;
		}
		return $first;
	}

	/**
	 * The first variation a customer can buy that has each of `$values`, or
	 * leaves it open, in the product's order of variations; null when it
	 * sells none that does.
	 *
	 * @param WC_Product            $product A variable product.
	 * @param array<string, string> $values  The values to agree with, by attribute, as a variation keeps them.
	 */
	public static function agreeing_with( WC_Product $product, array $values ): ?WC_Product_Variation {
		if ( ! $product->is_type( 'variable' ) ) {
			return null;
		}
		foreach ( self::bought_of( $product ) as $variation ) {
			if ( self::agrees( $variation, $values ) ) {
				return $variation;
			}
		}
		return null;
	}

	/**
	 * The variations of a product a customer can buy personalised, in its
	 * order of variations.
	 *
	 * @param WC_Product $product A variable product.
	 * @return Generator<int, WC_Product_Variation>
	 */
	private static function bought_of( WC_Product $product ): Generator {
		// One at a time: the first that agrees ends the search, however many the product has.
		foreach ( $product->get_children() as $child_id ) {
			$variation = wc_get_product( $child_id );
			// A disabled variation is stored as private.
			if ( $variation instanceof WC_Product_Variation && ProductConfig::PUBLIC_STATUS === $variation->get_status() && self::bought( $variation, ProductConfig::config_for( $product, $variation ) ) ) {
				yield $variation;
			}
		}
	}

	/**
	 * What the product's picture depends on of the variation it is drawn
	 * for: which it is, its own configuration and its variant, as stored.
	 * Null when it is drawn for none.
	 *
	 * @param WC_Product $product A product or variation.
	 *
	 * @return array{variation: int, config: string|null, variant: string|null}|null
	 */
	public static function document_on( WC_Product $product ): ?array {
		$variation = self::of( $product );
		return null === $variation ? null : array(
			'variation' => $variation->get_id(),
			'config'    => ProductConfig::document_on( $variation ),
			'variant'   => ProductVariant::document_on( $variation ),
		);
	}

	/**
	 * Whether a customer can buy the variation personalised: WooCommerce
	 * offers it (it has a price, and is not hidden out of stock), and it
	 * sells a variant of its blank when it has one.
	 *
	 * @param WC_Product_Variation $variation A variation.
	 * @param ProductConfig        $config    The configuration it is sold under.
	 */
	private static function bought( WC_Product_Variation $variation, ProductConfig $config ): bool {
		return $config->personalise
			&& ProductVariant::sellable( $variation, $config )
			&& $variation->variation_is_visible()
			&& ( $variation->is_in_stock() || 'yes' !== get_option( 'woocommerce_hide_out_of_stock_items' ) );
	}

	/**
	 * Whether the variation has each value, or leaves it open.
	 *
	 * @param WC_Product_Variation  $variation A variation.
	 * @param array<string, string> $values    The values, by attribute.
	 */
	private static function agrees( WC_Product_Variation $variation, array $values ): bool {
		$held = array_map( 'strval', $variation->get_attributes() );
		foreach ( $values as $attribute => $value ) {
			$own = $held[ sanitize_title( (string) $attribute ) ] ?? '';
			if ( '' !== $own && $own !== $value ) {
				return false;
			}
		}
		return true;
	}
}
