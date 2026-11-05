<?php
/**
 * The orders of the person a privacy request is about.
 *
 * @package Pixonaut\WooCommerce
 */

declare(strict_types=1);

namespace Pixonaut\WooCommerce\Privacy;

use Pixonaut\WooCommerce\Fulfilment\LineState;
use WC_Order;
use WC_Order_Item_Product;
use WP_User;

/**
 * Finds orders as WooCommerce's own privacy exporter and eraser do: those
 * whose billing email is the address, and those of the customer account
 * that has it, a page at a time.
 */
final class CustomerOrders {

	/**
	 * Orders one page holds, as WooCommerce's own exporter and eraser page them.
	 */
	public const PAGE_SIZE = 10;

	/**
	 * @param string $email An email address.
	 * @param int    $page  The page, from 1.
	 *
	 * @return list<WC_Order>
	 */
	public static function page( string $email, int $page ): array {
		$customers = array( $email );
		$user      = get_user_by( 'email', $email );
		if ( $user instanceof WP_User ) {
			$customers[] = (int) $user->ID;
		}
		$orders = wc_get_orders(
			array(
				'limit'    => self::PAGE_SIZE,
				'page'     => max( 1, $page ),
				'customer' => $customers,
				'orderby'  => 'ID',
				'order'    => 'ASC',
			)
		);
		return is_array( $orders ) ? array_values( $orders ) : array();
	}

	/**
	 * Whether a page was the last one.
	 *
	 * @param WC_Order[] $orders What page() answered.
	 * @phpstan-param list<WC_Order> $orders
	 */
	public static function is_last( array $orders ): bool {
		return count( $orders ) < self::PAGE_SIZE;
	}

	/**
	 * The order's Pixonaut lines.
	 *
	 * @param WC_Order $order The order.
	 *
	 * @return list<LineState>
	 */
	public static function lines( WC_Order $order ): array {
		$lines = array();
		foreach ( $order->get_items() as $item ) {
			if ( $item instanceof WC_Order_Item_Product && LineState::is_tracked( $item ) ) {
				$lines[] = LineState::of( $item );
			}
		}
		return $lines;
	}
}
