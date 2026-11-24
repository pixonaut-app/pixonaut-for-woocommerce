<?php
/**
 * Applies `order.released` to the lines of the order.
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
 * Pixonaut released a held order, because the workspace upgraded or the
 * month reset, and the order gets one note naming what released it. The
 * event carries the whole order's status, not the items': each line's item
 * is read for its own (LineReader), and an order released straight to
 * `cancelled` cancels its lines as `order.cancelled` would, with that
 * outcome's own note. A release delivered before the hold, or again, is
 * applied once.
 */
final class OrderReleased {

	public const TYPE = 'order.released';

	/**
	 * Tries to record the release when another request wrote the line meanwhile.
	 */
	private const MAX_ATTEMPTS = 3;

	/**
	 * @param OrderEventLines $lines     Finds the lines an event is about.
	 * @param OrderCancelled  $cancelled Applies a release to `cancelled`.
	 */
	public function __construct(
		private readonly OrderEventLines $lines,
		private readonly OrderCancelled $cancelled,
	) {
	}

	/**
	 * @param Event $event An `order.released` event.
	 * @throws RuntimeException When a line kept changing; handling the event again retries it.
	 */
	public function handle( Event $event ): void {
		$status   = Payload::string( $event, 'status' );
		$order_id = Payload::string( $event, 'orderId' );
		if ( null === $status || null === $order_id ) {
			return;
		}
		$goes_on = OrderStatus::CANCELLED !== $status;
		foreach ( $this->lines->of( $event ) as $line ) {
			$this->record( $line->item(), $order_id, Payload::string( $event, 'releasedBy' ) ?? '', $goes_on );
			if ( $goes_on ) {
				LineReader::queue( $line->item()->get_id() );
			} else {
				$this->cancelled->apply_to( $line );
			}
		}
	}

	/**
	 * Records the release on the line once, with its note.
	 *
	 * @param WC_Order_Item_Product $item        A line of the released order.
	 * @param string                $order_id    The released order.
	 * @param string                $released_by What released it.
	 * @param bool                  $goes_on     Whether the order goes on to the printshop; false when it was released to `cancelled`.
	 * @throws RuntimeException When the line kept changing for MAX_ATTEMPTS tries.
	 */
	private function record( WC_Order_Item_Product $item, string $order_id, string $released_by, bool $goes_on ): void {
		$order = wc_get_order( $item->get_order_id() );
		for ( $attempt = 0; $attempt < self::MAX_ATTEMPTS; $attempt++ ) {
			$line = LineState::of( $item );
			if ( $line->ref( LineState::REF_ORDER_ID ) !== $order_id || null !== $line->ref( LineState::REF_RELEASED_BY ) ) {
				return;
			}
			$line->set_ref( LineState::REF_RELEASED_BY, $released_by );
			$line->set_ref( LineState::REF_HOLD, null );
			if ( $line->save() ) {
				if ( $order instanceof WC_Order ) {
					$order->add_order_note( OrderHold::released_note( $item, $released_by, $goes_on ) );
				}
				return;
			}
		}
		throw new RuntimeException( sprintf( 'Order release not recorded on order item %d: the line kept changing.', (int) $item->get_id() ) );
	}
}
