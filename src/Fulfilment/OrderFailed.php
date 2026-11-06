<?php
/**
 * Applies `order.failed` to the lines of the order.
 *
 * @package Pixonaut\WooCommerce
 */

declare(strict_types=1);

namespace Pixonaut\WooCommerce\Fulfilment;

use Pixonaut\WooCommerce\Vendor\Pixonaut\Sdk\Webhooks\Event;
use Pixonaut\WooCommerce\Webhooks\Payload;
use RuntimeException;
use WC_Order;

/**
 * A printshop order that failed needs the merchant: each of its lines moves
 * to `needs_attention` with the reason Pixonaut gave in the order note, and
 * records the order's status as `failed`. A line that is final, or in
 * `needs_attention` already, or that a retry handed to another order since
 * it was found, is left as it is.
 */
final class OrderFailed {

	public const TYPE = 'order.failed';

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
	 * @param Event $event An `order.failed` event.
	 * @throws RuntimeException When a line kept changing; handling the event again retries it.
	 */
	public function handle( Event $event ): void {
		$reason = Payload::string( $event, 'reason' ) ?? '';
		foreach ( $this->lines->of( $event ) as $line ) {
			$this->apply_to( $line, $reason );
		}
	}

	/**
	 * Applies the failure to one line. The store calls it directly for a
	 * failure it reported itself, which Pixonaut does not send back.
	 *
	 * @param LineState $line   A line of the failed order.
	 * @param string    $reason Why it failed, as Pixonaut put it.
	 * @throws RuntimeException When the line kept changing for MAX_ATTEMPTS tries.
	 */
	public function apply_to( LineState $line, string $reason ): void {
		$item  = $line->item();
		$order = wc_get_order( $item->get_order_id() );
		if ( ! $order instanceof WC_Order ) {
			return;
		}
		$order_id = $line->ref( LineState::REF_ORDER_ID );
		for ( $attempt = 0; $attempt < self::MAX_ATTEMPTS; $attempt++ ) {
			$line = LineState::of( $item );
			// A retry handed the line to another order meanwhile; the failure was the old one's.
			if ( $line->ref( LineState::REF_ORDER_ID ) !== $order_id ) {
				return;
			}
			if ( LineState::NEEDS_ATTENTION === $line->state() || ! LineState::can_move( $line->state(), LineState::NEEDS_ATTENTION ) ) {
				return;
			}
			$line->set_ref( LineState::REF_ORDER_STATUS, OrderStatus::FAILED );
			$flagged = NeedsAttention::flag(
				$line,
				$order,
				NeedsAttention::ORDER_FAILED,
				sprintf(
					/* translators: 1: product name of the order line, 2: the reason Pixonaut gave. */
					__( 'Pixonaut: the printshop order for "%1$s" failed: %2$s', 'pixonaut-for-woocommerce' ),
					$item->get_name(),
					/* translators: Fills in %2$s of an order note when the printshop order failed without a reason. Ends a sentence; lower case. */
					'' === $reason ? __( 'no reason given.', 'pixonaut-for-woocommerce' ) : sanitize_text_field( $reason )
				)
			);
			if ( $flagged ) {
				return;
			}
		}
		throw new RuntimeException( sprintf( 'Order failure not applied to order item %d: the line kept changing.', (int) $item->get_id() ) );
	}
}
