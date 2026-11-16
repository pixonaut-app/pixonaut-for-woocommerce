<?php
/**
 * A product page's structured data, of its own product only.
 *
 * @package Pixonaut\WooCommerce
 */

declare(strict_types=1);

namespace Pixonaut\WooCommerce\Storefront;

use WC_Product;

/**
 * On a block theme's product page WooCommerce runs the
 * `woocommerce_single_product_summary` hooks at every Product Summary block,
 * the product cards' too: related products' cards then each add their
 * product to the page's structured data, as though the page sold five
 * products, and read every variation's attributes to do so. Only the
 * product the page shows is described: WooCommerce's description runs, at
 * its own place, for that product alone.
 */
final class CardStructuredData {

	/**
	 * The hook WooCommerce describes the product on.
	 */
	private const SUMMARY_HOOK = 'woocommerce_single_product_summary';

	/**
	 * The priority WooCommerce describes the product at.
	 */
	private const DESCRIBE_PRIORITY = 60;

	public function register(): void {
		add_action( 'wp', array( $this, 'take_over' ) );
	}

	/**
	 * Puts the description of the page's product alone in the place of
	 * WooCommerce's, while WooCommerce describes products there.
	 */
	public function take_over(): void {
		$describer = WC()->structured_data;
		// WooCommerce sets it up on front-end requests alone.
		if ( null === $describer || self::DESCRIBE_PRIORITY !== has_action( self::SUMMARY_HOOK, array( $describer, 'generate_product_data' ) ) ) { // @phpstan-ignore identical.alwaysFalse
			return;
		}
		remove_action( self::SUMMARY_HOOK, array( $describer, 'generate_product_data' ), self::DESCRIBE_PRIORITY );
		add_action( self::SUMMARY_HOOK, array( $this, 'describe' ), self::DESCRIBE_PRIORITY );
	}

	/**
	 * Describes the summary's product, unless a product page shows another.
	 */
	public function describe(): void {
		global $product;
		if ( is_product() && $product instanceof WC_Product && get_queried_object_id() !== $product->get_id() ) {
			return;
		}
		WC()->structured_data->generate_product_data();
	}
}
