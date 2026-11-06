<?php
/**
 * Applies `order.cancelled` to the lines of the order.
 *
 * @package Pixonaut\WooCommerce
 */

declare(strict_types=1);

namespace Pixonaut\WooCommerce\Fulfilment;

use Pixonaut\WooCommerce\Vendor\Pixonaut\Sdk\Webhooks\Event;
use RuntimeException;
use WC_Order;

/**
 * The printshop stopped the order: each of its lines is `cancelled`, with an
 * order note, and records the order's status. The event comes for a cancel
 * the store asked for too; a line the store cancelled already when Pixonaut
 * answered is left as it is, so applying it twice changes nothing. Refunding
 * the customer stays the merchant's.
 */
final class OrderCancelled {

	public const TYPE = 'order.cancelled';

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
	 * @param Event $event An `order.cancelled` event.
	 * @throws RuntimeException When a line kept changing; handling the event again retries it.
	 */
	public function handle( Event $event ): void {
		foreach ( $this->lines->of( $event ) as $line ) {
			$this->apply_to( $line );
		}
	}

	/**
	 * Applies the cancel to one line. The store calls it directly for an
	 * order Pixonaut released straight to `cancelled`, which sends no
	 * `order.cancelled`.
	 *
	 * @param LineState $line A line of the cancelled order.
	 * @throws RuntimeException When the line kept changing for MAX_ATTEMPTS tries.
	 */
	public function apply_to( LineState $line ): void {
		$item     = $line->item();
		$order_id = $line->ref( LineState::REF_ORDER_ID );
		for ( $attempt = 0; $attempt < self::MAX_ATTEMPTS; $attempt++ ) {
			$line = LineState::of( $item );
			// A retry handed the line to another order meanwhile; the cancel was the old one's.
			if ( $line->ref( LineState::REF_ORDER_ID ) !== $order_id || ! LineState::can_move( $line->state(), LineState::CANCELLED ) ) {
				return;
			}
			$line->set_ref( LineState::REF_ORDER_STATUS, OrderStatus::CANCELLED );
			$line->set_ref( LineState::REF_CANCEL, null );
			if ( $line->transition( LineState::CANCELLED ) ) {
				$order = wc_get_order( $item->get_order_id() );
				if ( $order instanceof WC_Order ) {
					$order->add_order_note(
						sprintf(
							/* translators: %s: product name of the order line. */
							__( 'Pixonaut: the printshop order for "%s" was cancelled.', 'pixonaut-for-woocommerce' ),
							$item->get_name()
						)
					);
				}
				return;
			}
		}
		throw new RuntimeException( sprintf( 'Order cancellation not applied to order item %d: the line kept changing.', (int) $item->get_id() ) );
	}
}
