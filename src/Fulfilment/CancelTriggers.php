<?php
/**
 * What in WooCommerce asks for a line to be cancelled.
 *
 * @package Pixonaut\WooCommerce
 */

declare(strict_types=1);

namespace Pixonaut\WooCommerce\Fulfilment;

use WC_Order;
use WC_Order_Item_Product;
use WC_Order_Refund;

/**
 * A cancelled or fully refunded order asks for each of its Pixonaut lines
 * to be cancelled, and a refund that brings a line's refunded quantity to
 * its whole quantity asks for that line; a refund of part of a line's
 * quantity is only noted. Saving the order on its screen schedules again a
 * cancellation the store could not schedule before.
 */
final class CancelTriggers {

	/**
	 * Order item meta WooCommerce stores on a refund's item: the id of the
	 * order item it refunds.
	 */
	private const REFUNDED_ITEM_META = '_refunded_item_id';

	/**
	 * Priority on `woocommerce_process_shop_order_meta`: after WooCommerce
	 * saved the order's items (priorities 10 to 50), so the lines read are
	 * the ones it saved.
	 */
	private const RESUME_PRIORITY = 100;

	/**
	 * @param LineCancellation $cancellation Cancels the lines.
	 */
	public function __construct( private readonly LineCancellation $cancellation ) {
	}

	public function register(): void {
		add_action( 'woocommerce_order_status_cancelled', array( $this, 'order_cancelled' ) );
		add_action( 'woocommerce_order_status_refunded', array( $this, 'order_refunded' ) );
		add_action( 'woocommerce_order_refunded', array( $this, 'refunded' ), 10, 2 );
		add_action( 'woocommerce_process_shop_order_meta', array( $this, 'resume' ), self::RESUME_PRIORITY );
	}

	/**
	 * The order was saved on its screen: a cancellation whose job the store
	 * could not schedule before is scheduled now.
	 *
	 * @param int|string $order_id The order.
	 */
	public function resume( int|string $order_id ): void {
		$order = wc_get_order( (int) $order_id );
		if ( ! $order instanceof WC_Order ) {
			return;
		}
		foreach ( $order->get_items() as $item ) {
			if ( $item instanceof WC_Order_Item_Product && LineState::is_tracked( $item ) ) {
				$this->cancellation->request( LineState::of( $item ), LineCancellation::ORDER_CANCELLED, false );
			}
		}
	}

	/**
	 * The order was cancelled: so is each of its lines.
	 *
	 * @param int|string $order_id The order.
	 */
	public function order_cancelled( int|string $order_id ): void {
		$this->cancel_order( (int) $order_id, LineCancellation::ORDER_CANCELLED );
	}

	/**
	 * The whole order was refunded: each of its lines is cancelled.
	 *
	 * @param int|string $order_id The order.
	 */
	public function order_refunded( int|string $order_id ): void {
		$this->cancel_order( (int) $order_id, LineCancellation::ORDER_REFUNDED );
	}

	/**
	 * A refund was made: each line it refunds in full, counting earlier
	 * refunds, is cancelled; one refunded in part gets a note.
	 *
	 * @param int|string $order_id  The order.
	 * @param int|string $refund_id The refund.
	 */
	public function refunded( int|string $order_id, int|string $refund_id ): void {
		$order  = wc_get_order( (int) $order_id );
		$refund = wc_get_order( (int) $refund_id );
		if ( ! $order instanceof WC_Order || ! $refund instanceof WC_Order_Refund ) {
			return;
		}
		foreach ( $refund->get_items() as $refunded ) {
			$item_id = (int) $refunded->get_meta( self::REFUNDED_ITEM_META );
			$item    = $order->get_item( $item_id );
			if ( ! $item instanceof WC_Order_Item_Product || ! LineState::is_tracked( $item ) ) {
				continue;
			}
			$line = LineState::of( $item );
			if ( in_array( $line->state(), LineState::FINAL, true ) ) {
				continue;
			}
			// WooCommerce counts refunded quantities as negative numbers.
			$quantity = absint( $order->get_qty_refunded_for_item( $item_id ) );
			if ( $quantity >= $item->get_quantity() ) {
				$this->cancellation->request( $line, LineCancellation::LINE_REFUNDED );
			} elseif ( $quantity > 0 ) {
				$order->add_order_note(
					sprintf(
						/* translators: 1: refunded quantity, 2: ordered quantity, 3: product name of the order line. */
						__( 'Pixonaut: %1$d of %2$d of "%3$s" refunded. Nothing was changed in Pixonaut: only a refund of the whole item cancels it there.', 'pixonaut-for-woocommerce' ),
						$quantity,
						$item->get_quantity(),
						$item->get_name()
					)
				);
			}
		}
	}

	/**
	 * Cancels each of the order's lines.
	 *
	 * @param int    $order_id The order.
	 * @param string $trigger  What asked for it.
	 */
	private function cancel_order( int $order_id, string $trigger ): void {
		$order = wc_get_order( $order_id );
		if ( ! $order instanceof WC_Order ) {
			return;
		}
		foreach ( $order->get_items() as $item ) {
			if ( $item instanceof WC_Order_Item_Product && LineState::is_tracked( $item ) ) {
				$this->cancellation->request( LineState::of( $item ), $trigger );
			}
		}
	}
}
