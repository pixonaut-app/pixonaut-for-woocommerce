<?php
/**
 * Applies `order.held` to the lines of the order.
 *
 * @package Pixonaut\WooCommerce
 */

declare(strict_types=1);

namespace Pixonaut\WooCommerce\Fulfilment;

use Pixonaut\WooCommerce\Webhooks\Event;
use RuntimeException;
use WC_Order;
use WC_Order_Item_Product;

/**
 * Pixonaut took the order and holds it: the line is ordered, shows as held,
 * and its order gets a note saying why and when it goes ahead. The store
 * usually knows already, from the answer that placed the order; then the
 * event changes nothing. So does one delivered after the release, or after a
 * later status: a held line is never moved back.
 */
final class OrderHeld {

	public const TYPE = 'order.held';

	/**
	 * Tries to apply the event when another request wrote the line meanwhile.
	 */
	private const MAX_ATTEMPTS = 3;

	/**
	 * @param OrderEventLines $lines Finds the lines an event is about.
	 */
	public function __construct( private readonly OrderEventLines $lines ) {
	}

	/**
	 * @param Event $event An `order.held` event.
	 * @throws RuntimeException When a line kept changing; handling the event again retries it.
	 */
	public function handle( Event $event ): void {
		foreach ( $this->lines->of( $event ) as $line ) {
			$this->apply_to( $line->item(), (string) $event->string( 'orderId' ), $event->payload );
		}
	}

	/**
	 * @param WC_Order_Item_Product $item     A line of the held order.
	 * @param string                $order_id The held order; a line a retry handed to another order since is left alone.
	 * @param array<string, mixed>  $hold     The event's payload, which carries the hold's fields.
	 * @throws RuntimeException When the line kept changing for MAX_ATTEMPTS tries.
	 */
	private function apply_to( WC_Order_Item_Product $item, string $order_id, array $hold ): void {
		$order = wc_get_order( $item->get_order_id() );
		if ( ! $order instanceof WC_Order ) {
			return;
		}
		for ( $attempt = 0; $attempt < self::MAX_ATTEMPTS; $attempt++ ) {
			$line    = LineState::of( $item );
			$applied = $line->ref( LineState::REF_ORDER_STATUS );
			if ( $line->ref( LineState::REF_ORDER_ID ) !== $order_id || null !== $line->ref( LineState::REF_RELEASED_BY ) ) {
				return;
			}
			// Shown as held already, or moved on since: a duplicate, or one delivered late.
			if ( ( OrderStatus::HELD === $applied && null !== $line->ref( LineState::REF_HOLD ) ) || ( null !== $applied && OrderStatus::HELD !== $applied ) ) {
				return;
			}
			$line->set_ref( LineState::REF_ORDER_STATUS, OrderStatus::HELD );
			$noted = OrderHold::store( $line, $hold );
			$move  = LineState::ORDERED !== $line->state() && LineState::can_move( $line->state(), LineState::ORDERED, $line->resume_from() );
			$saved = $move ? $line->transition( LineState::ORDERED ) : $line->save();
			if ( $saved ) {
				if ( $noted ) {
					OrderHold::note( $line );
				}
				return;
			}
		}
		throw new RuntimeException( sprintf( 'Order hold not applied to order item %d: the line kept changing.', (int) $item->get_id() ) );
	}
}
