<?php
/**
 * Applies `order.submitted` for an order that replaced another.
 *
 * @package Pixonaut\WooCommerce
 */

declare(strict_types=1);

namespace Pixonaut\WooCommerce\Fulfilment;

use Pixonaut\WooCommerce\Vendor\Pixonaut\Sdk\Webhooks\Event;
use Pixonaut\WooCommerce\Webhooks\Payload;
use RuntimeException;
use WC_Order;
use WC_Order_Item_Product;

/**
 * A retry of a failed printshop order, made in Pixonaut, places a new order
 * with its own id, `replacesOrderId` naming the failed one. The line holding
 * the failed order's id takes on the new one, keeping the old in its
 * `replacedOrderIds`, so the new order's events find it; then `submitted` is
 * applied as `order.status_changed` would apply it, which moves the line out
 * of `needs_attention`. Events are handled in jobs that need not run in the
 * order they were sent, so the new order is then read and the line brought in
 * step with it (LineActions::current()): a status whose event was handled
 * before this one is applied, and an order that failed and was retried again
 * hands the line on to its own replacement. The lines are found as for any
 * `order.*` event, so a line whose `externalRef` differs is left alone. A
 * final line is left as it is. An order that replaced none is the store's
 * own, placed when the line was ordered: its submission only matters to a
 * line still waiting for its prints, which Pixonaut has now handed to the
 * printshop. That line is brought in step with the order as Pixonaut has it
 * now, so a status whose event was handled first, such as an unknown
 * outcome of the hand-on, is applied too.
 */
final class OrderSubmitted {

	public const TYPE = 'order.submitted';

	/**
	 * Tries to write a line when another request wrote it meanwhile.
	 */
	private const MAX_ATTEMPTS = 3;

	/**
	 * @param OrderEventLines    $lines          Finds the lines an event is about.
	 * @param OrderStatusChanged $status_changed Applies the new order's status.
	 * @param LineActions        $actions        Reads the new order and brings the line in step with it.
	 */
	public function __construct(
		private readonly OrderEventLines $lines,
		private readonly OrderStatusChanged $status_changed,
		private readonly LineActions $actions,
	) {
	}

	/**
	 * @param Event $event An `order.submitted` event.
	 * @throws RuntimeException When a line kept changing, or ApiException when the new order could not be read; handling the event again retries it.
	 */
	public function handle( Event $event ): void {
		$order_id = Payload::string( $event, 'orderId' );
		$replaces = Payload::string( $event, 'replacesOrderId' );
		if ( null === $order_id ) {
			return;
		}
		if ( null === $replaces ) {
			$this->handed_on( $order_id, $event );
			return;
		}
		if ( $order_id === $replaces ) {
			return;
		}
		// A line holding the new id already took it on in a run that stopped before it was brought in step,
		// perhaps before the order id index was written.
		$found = array_merge(
			$this->lines->holding( $replaces, $event ),
			$this->lines->holding( $order_id, $event ),
			$this->lines->indexed_under_replaced( $replaces, $event )
		);
		$items = array();
		foreach ( $found as $line ) {
			$items[ $line->item()->get_id() ] = $line->item();
		}
		foreach ( $items as $item ) {
			$this->follow( $item, $replaces, $order_id, $event );
		}
	}

	/**
	 * Brings the lines whose order waited for its prints in step with the
	 * order. Nothing is written before the order is read, so an event whose
	 * read failed finds the line still waiting when it is handled again.
	 * Any other line took its order's status when the order was placed.
	 *
	 * @param string $order_id The order handed to the printshop.
	 * @param Event  $event    The `order.submitted` event.
	 * @throws RuntimeException When a line kept changing, or ApiException when the order could not be read.
	 */
	private function handed_on( string $order_id, Event $event ): void {
		foreach ( $this->lines->holding( $order_id, $event ) as $line ) {
			if ( OrderStatus::AWAITING_PRINTS === $line->ref( LineState::REF_ORDER_STATUS ) ) {
				$this->actions->current( $line );
			}
		}
	}

	/**
	 * Hands one line to the new order, applies its status, then brings it in
	 * step with the order as Pixonaut has it now.
	 *
	 * @param WC_Order_Item_Product $item     The line.
	 * @param string                $replaces The order it held.
	 * @param string                $order_id The order that replaced it.
	 * @param Event                 $event    The `order.submitted` event.
	 * @throws RuntimeException When the line kept changing for MAX_ATTEMPTS tries, or ApiException when the new order could not be read.
	 */
	private function follow( WC_Order_Item_Product $item, string $replaces, string $order_id, Event $event ): void {
		for ( $attempt = 0; $attempt < self::MAX_ATTEMPTS; $attempt++ ) {
			$line      = LineState::of( $item );
			$holds_new = $line->ref( LineState::REF_ORDER_ID ) === $order_id;
			if ( ! $holds_new && ( $line->ref( LineState::REF_ORDER_ID ) !== $replaces || in_array( $line->state(), LineState::FINAL, true ) ) ) {
				return;
			}
			if ( ! $holds_new ) {
				$line->replace_order( $order_id );
			}
			// Saving a line that holds the new id already writes an order id index a stopped run left behind.
			if ( $line->save() ) {
				if ( ! $holds_new ) {
					$this->note( $item, $order_id );
				}
				$this->status_changed->apply_to( $item, self::submitted( $order_id, $event ) );
				list( $line, $order ) = $this->actions->current( LineState::of( $item ) );
				// The new order failed and was retried again: its replacement's status is applied too.
				if ( null !== $order && $line->ref( LineState::REF_ORDER_ID ) !== $order_id ) {
					$this->actions->reconcile( $line, $order );
				}
				return;
			}
		}
		throw new RuntimeException( sprintf( 'Replacement order not applied to order item %d: the line kept changing.', (int) $item->get_id() ) );
	}

	/**
	 * The `order.status_changed` the submission amounts to: the new order's
	 * first status.
	 *
	 * @param string $order_id The new order.
	 * @param Event  $event    The `order.submitted` event.
	 */
	private static function submitted( string $order_id, Event $event ): Event {
		return Payload::event(
			$event->id,
			OrderStatusChanged::TYPE,
			$event->createdAt,
			array(
				'orderId'        => $order_id,
				'externalRef'    => Payload::string( $event, 'externalRef' ),
				'previousStatus' => '',
				'status'         => OrderStatus::SUBMITTED,
			)
		);
	}

	/**
	 * @param WC_Order_Item_Product $item     The line.
	 * @param string                $order_id The new order.
	 */
	private function note( WC_Order_Item_Product $item, string $order_id ): void {
		$order = wc_get_order( $item->get_order_id() );
		if ( $order instanceof WC_Order ) {
			$order->add_order_note(
				sprintf(
					/* translators: 1: product name of the order line, 2: the new Pixonaut order id. */
					__( 'Pixonaut: "%1$s" was sent to the printshop again, as order %2$s.', 'pixonaut-for-woocommerce' ),
					$item->get_name(),
					$order_id
				)
			);
		}
	}
}
