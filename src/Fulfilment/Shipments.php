<?php
/**
 * Takes an order on once one of its lines ships.
 *
 * @package Pixonaut\WooCommerce
 */

declare(strict_types=1);

namespace Pixonaut\WooCommerce\Fulfilment;

use Pixonaut\WooCommerce\Emails\PartShippedEmail;
use RuntimeException;
use WC_Order;
use WC_Order_Item_Product;

/**
 * Completes an order once every line is a Pixonaut line and shipped; the
 * plugin never completes an order with a line it does not fulfil. When the
 * shipment leaves the order `processing`, the customer gets the "Part of your
 * order has shipped" email, unless the order has a single line; and when every
 * Pixonaut line of an order with other lines has shipped, an order note says
 * so, for the store to ship the rest. The line's `shipmentHandled` ref records
 * that this was done, so a run that stopped before it is finished again.
 */
final class Shipments {

	/**
	 * Handles a line that has shipped: takes the order on, then marks the line
	 * handled.
	 *
	 * @param LineState $line A line in `shipped`, not yet handled.
	 * @throws RuntimeException When the line kept changing while it was marked handled; handling the event again retries it.
	 */
	public function shipped( LineState $line ): void {
		$item  = $line->item();
		$order = wc_get_order( $item->get_order_id() );
		if ( $order instanceof WC_Order && ! $this->complete_if_shipped( $order ) && $order->has_status( 'processing' ) ) {
			$this->tell( $order, $item );
		}
		self::mark_handled( $line );
	}

	/**
	 * Completes the line's order when it is done.
	 *
	 * @param WC_Order_Item_Product $item A shipped line.
	 */
	public function complete_order_of( WC_Order_Item_Product $item ): void {
		$order = wc_get_order( $item->get_order_id() );
		if ( $order instanceof WC_Order ) {
			$this->complete_if_shipped( $order );
		}
	}

	/**
	 * Completes a paid order once every line is a Pixonaut line and shipped.
	 * An order with a line the plugin does not fulfil is never completed.
	 *
	 * @param WC_Order $order The order.
	 * @return bool Whether it completed the order.
	 */
	private function complete_if_shipped( WC_Order $order ): bool {
		$lines = $order->get_items();
		if ( ! $order->has_status( 'processing' ) || array() === $lines || ! self::every_line_from_pixonaut( $lines ) ) {
			return false;
		}
		$complete = self::every_pixonaut_line_shipped( $lines );

		/**
		 * Filters whether a shipped line completes an order whose every line
		 * is a Pixonaut line. By default it does once every line has shipped.
		 * An order with other lines is never completed by the plugin, whatever
		 * this returns.
		 *
		 * @since 0.1.0
		 *
		 * @param bool     $complete Whether to complete the order.
		 * @param WC_Order $order    The order, still `processing`.
		 *
		 * @example
		 * add_filter( 'pixonaut_wc_fulfilment_complete_order', function ( $complete, $order ) {
		 *     return $complete && ! $order->get_meta( '_awaiting_pickup' );
		 * }, 10, 2 );
		 */
		if ( ! (bool) apply_filters( 'pixonaut_wc_fulfilment_complete_order', $complete, $order ) ) {
			return false;
		}
		$order->update_status( 'completed', __( 'Pixonaut: every item has shipped.', 'pixonaut-for-woocommerce' ) );
		return true;
	}

	/**
	 * Tells the store and the customer about a shipment that left the order
	 * open: a note once every Pixonaut line of a mixed order has shipped, and
	 * the "Part of your order has shipped" email unless the order has a single
	 * line.
	 *
	 * @param WC_Order              $order The order, still `processing`.
	 * @param WC_Order_Item_Product $item  The line that shipped.
	 */
	private function tell( WC_Order $order, WC_Order_Item_Product $item ): void {
		$lines = $order->get_items();
		if ( self::every_pixonaut_line_shipped( $lines ) && ! self::every_line_from_pixonaut( $lines ) ) {
			$order->add_order_note( __( 'Pixonaut: All personalised items shipped. The order\'s other items are yours to ship.', 'pixonaut-for-woocommerce' ) );
		}
		if ( count( $lines ) > 1 ) {
			$this->email( $item );
		}
	}

	/**
	 * Records that the shipped line was handled, reading the line again when
	 * another request wrote it meanwhile.
	 *
	 * @param LineState $line The shipped line.
	 * @throws RuntimeException When the line kept changing for OrderStatusChanged::MAX_ATTEMPTS tries.
	 */
	private static function mark_handled( LineState $line ): void {
		for ( $attempt = 0; $attempt < OrderStatusChanged::MAX_ATTEMPTS; $attempt++ ) {
			$line->set_ref( LineState::REF_SHIPMENT_HANDLED, true );
			if ( $line->save() ) {
				return;
			}
			$line = LineState::of( $line->item() );
		}
		throw new RuntimeException( sprintf( 'Order item %d not marked as handled after shipping: the line kept changing.', (int) $line->item()->get_id() ) );
	}

	/**
	 * Sends the customer the "Part of your order has shipped" email, at once:
	 * WooCommerce's deferred emails would carry the line through a JSON job.
	 *
	 * @param WC_Order_Item_Product $item The line that shipped.
	 */
	private function email( WC_Order_Item_Product $item ): void {
		$email = WC()->mailer()->get_emails()[ PartShippedEmail::class ] ?? null;
		if ( $email instanceof PartShippedEmail ) {
			$email->trigger( $item );
		}
	}

	/**
	 * @param array<mixed> $lines An order's lines.
	 */
	private static function every_line_from_pixonaut( array $lines ): bool {
		foreach ( $lines as $line ) {
			if ( ! $line instanceof WC_Order_Item_Product || ! LineState::is_tracked( $line ) ) {
				return false;
			}
		}
		return true;
	}

	/**
	 * @param array<mixed> $lines An order's lines.
	 */
	private static function every_pixonaut_line_shipped( array $lines ): bool {
		foreach ( $lines as $line ) {
			if ( $line instanceof WC_Order_Item_Product && LineState::is_tracked( $line ) && LineState::SHIPPED !== LineState::of( $line )->state() ) {
				return false;
			}
		}
		return true;
	}
}
