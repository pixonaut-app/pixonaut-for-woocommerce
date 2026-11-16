<?php
/**
 * A personalised variable product's page prints all of its variations.
 *
 * @package Pixonaut\WooCommerce
 */

declare(strict_types=1);

namespace Pixonaut\WooCommerce\Storefront;

use WC_Product;

/**
 * On the page of a variable product that shows the inline designer, the
 * add-to-cart form is given every variation up front, however many the
 * product has. Above WooCommerce's threshold (30 variations by default) the
 * form would ask the server for each choice instead: it blocks itself under
 * a spinner, disables Add to cart and shows the product's range while it
 * waits, which makes every switch of a variation on another blank flash and
 * lag.
 */
final class VariationsUpFront {

	/**
	 * After the store's and its other plugins' own thresholds, which this
	 * raises for these products alone.
	 */
	private const FILTER_PRIORITY = 100;

	/**
	 * @param InlineProducts $products Resolves a product to its designer.
	 */
	public function __construct(
		private readonly InlineProducts $products,
	) {
	}

	public function register(): void {
		add_filter( 'woocommerce_ajax_variation_threshold', array( $this, 'threshold' ), self::FILTER_PRIORITY, 2 );
	}

	/**
	 * The number of variations up to which the form is given them all: at
	 * least the product's own count on a product the designer mounts on.
	 *
	 * @param mixed $threshold The threshold so far.
	 * @param mixed $product   The product the form is for.
	 */
	public function threshold( $threshold, $product ): int {
		$threshold = is_numeric( $threshold ) ? (int) $threshold : 0;
		if ( ! $product instanceof WC_Product || ! $product->is_type( 'variable' ) || null === $this->products->for_product( $product ) ) {
			return $threshold;
		}
		return max( $threshold, count( $product->get_children() ) );
	}
}
