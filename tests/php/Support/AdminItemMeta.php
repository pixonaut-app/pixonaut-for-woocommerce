<?php
/**
 * What the admin order screen prints under an order line's meta.
 *
 * @package Pixonaut\WooCommerce
 */

declare(strict_types=1);

namespace Pixonaut\WooCommerce\Tests\Support;

use WC_Order_Item_Product;

/**
 * Fires the actions WooCommerce's admin order screen fires around a line's
 * meta table, and returns what they print.
 */
final class AdminItemMeta {

	public static function html( WC_Order_Item_Product $item ): string {
		ob_start();
		do_action( 'woocommerce_before_order_itemmeta', $item->get_id(), $item, $item->get_product() );
		do_action( 'woocommerce_after_order_itemmeta', $item->get_id(), $item, $item->get_product() );
		return (string) ob_get_clean();
	}
}
