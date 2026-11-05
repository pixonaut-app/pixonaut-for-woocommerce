<?php
/**
 * Refunds one order line.
 *
 * @package Pixonaut\WooCommerce
 */

declare(strict_types=1);

namespace Pixonaut\WooCommerce\Approval;

use WC_Order;
use WC_Order_Item_Product;
use WC_Payment_Gateway;
use WP_Error;

/**
 * Refunds what is left of a line: its quantity, total and taxes less what
 * earlier refunds took, never the order's shipping. The money goes back
 * through the payment gateway when it supports refunds. Otherwise the refund
 * is recorded in the store only, and a note asks the merchant to return the
 * money themselves. A refund the gateway refused records nothing and leaves
 * a note.
 */
final class LineRefund {

	/**
	 * What a gateway declares it supports when it refunds through its API.
	 */
	private const GATEWAY_REFUNDS = 'refunds';

	/**
	 * @param WC_Order_Item_Product $item   The line.
	 * @param WC_Order              $order  Its order.
	 * @param string                $reason Why, as the refund records it.
	 *
	 * @return bool Whether a refund was recorded.
	 */
	public function refund( WC_Order_Item_Product $item, WC_Order $order, string $reason ): bool {
		// Read afresh: the refunds made since the caller loaded the order count.
		$fresh   = wc_get_order( $order->get_id() );
		$order   = $fresh instanceof WC_Order ? $fresh : $order;
		$item_id = $item->get_id();
		// WooCommerce counts refunded quantities as negative numbers.
		$quantity = $item->get_quantity() - absint( $order->get_qty_refunded_for_item( $item_id ) );
		$total    = (float) $item->get_total() - (float) $order->get_total_refunded_for_item( $item_id );
		$taxes    = array();
		$item_tax = $item->get_taxes();
		foreach ( (array) ( $item_tax['total'] ?? array() ) as $tax_id => $amount ) {
			$left = (float) $amount - (float) $order->get_tax_refunded_for_item( $item_id, (int) $tax_id );
			if ( $left > 0 ) {
				$taxes[ $tax_id ] = wc_format_decimal( $left );
			}
		}
		$amount = $total + array_sum( array_map( 'floatval', $taxes ) );
		if ( $quantity <= 0 || $amount <= 0 ) {
			return false;
		}
		$gateway  = wc_get_payment_gateway_by_order( $order );
		$supports = $gateway instanceof WC_Payment_Gateway && $gateway->supports( self::GATEWAY_REFUNDS );
		$refund   = wc_create_refund(
			array(
				'order_id'       => $order->get_id(),
				'amount'         => wc_format_decimal( $amount ),
				'reason'         => $reason,
				'line_items'     => array(
					$item_id => array(
						'qty'          => $quantity,
						'refund_total' => wc_format_decimal( max( 0, $total ) ),
						'refund_tax'   => $taxes,
					),
				),
				'refund_payment' => $supports,
				'restock_items'  => true,
			)
		);
		if ( $refund instanceof WP_Error ) {
			$order->add_order_note(
				/* translators: 1: product name of the order line, 2: why the refund failed. */
				sprintf( __( 'Pixonaut: "%1$s" could not be refunded: %2$s Refund it by hand.', 'pixonaut-for-woocommerce' ), $item->get_name(), $refund->get_error_message() )
			);
			return false;
		}
		if ( ! $supports ) {
			$order->add_order_note(
				/* translators: 1: the amount refunded, 2: product name of the order line. */
				sprintf( __( 'Pixonaut: a refund of %1$s for "%2$s" was recorded, but the payment method cannot return money by itself. Return it to the customer by hand.', 'pixonaut-for-woocommerce' ), wp_strip_all_tags( wc_price( $amount, array( 'currency' => $order->get_currency() ) ) ), $item->get_name() )
			);
		}
		return true;
	}
}
