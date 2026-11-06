<?php
/**
 * Applies `order.tracking_added` and `order.tracking_changed`.
 *
 * @package Pixonaut\WooCommerce
 */

declare(strict_types=1);

namespace Pixonaut\WooCommerce\Fulfilment;

use Pixonaut\WooCommerce\Emails\TrackingChangedEmail;
use Pixonaut\WooCommerce\Vendor\Pixonaut\Sdk\Webhooks\Event;
use Pixonaut\WooCommerce\Webhooks\Payload;
use RuntimeException;
use WC_Order;
use WC_Order_Item_Product;

/**
 * Pixonaut sends `order.tracking_added` when the printshop gives tracking for
 * an order that shipped without any, and `order.tracking_changed` when it
 * corrects the tracking of a shipped order. Both carry the order's tracking as
 * it now is, which the line keeps in place of its own. For a shipped line the
 * order gets a note, and the customer the "Tracking updated" email with every
 * line of the order whose tracking the event changed. A line that has not
 * shipped yet, the event having arrived before `order.status_changed`, keeps
 * the tracking for its shipment and tells nobody. An event older than the one
 * that set the line's tracking, or one that brings the tracking the line has,
 * changes nothing.
 */
final class OrderTrackingChanged {

	public const ADDED = 'order.tracking_added';

	public const CHANGED = 'order.tracking_changed';

	/**
	 * @param OrderEventLines $lines Finds the lines an event is about.
	 */
	public function __construct( private readonly OrderEventLines $lines ) {
	}

	/**
	 * @param Event $event An `order.tracking_added` or `order.tracking_changed` event.
	 * @throws RuntimeException When a line kept changing; handling the event again retries it.
	 */
	public function handle( Event $event ): void {
		$tracking = Tracking::from_payload( $event->payload['tracking'] ?? null );
		$order_id = Payload::string( $event, 'orderId' );
		if ( null === $tracking || null === $order_id ) {
			return;
		}
		$to_tell = array();
		foreach ( $this->lines->of( $event ) as $line ) {
			$item = $this->apply( $line->item(), $order_id, $tracking, $event );
			if ( null !== $item ) {
				$to_tell[ $item->get_order_id() ][] = $item;
			}
		}
		foreach ( $to_tell as $items ) {
			self::email( $items );
			foreach ( $items as $item ) {
				self::told( $item );
			}
		}
	}

	/**
	 * Keeps the tracking on one line. A shipped line is noted, and holds
	 * `trackingNotice` until the customer was emailed, so handling the event
	 * again after a run that stopped part way still emails the customer.
	 *
	 * @param WC_Order_Item_Product                                 $item     The line.
	 * @param string                                                $order_id The order the event is about; a line a retry handed to another order since is left alone.
	 * @param array{carrier?: string, number: string, url?: string} $tracking The order's tracking.
	 * @param Event                                                 $event    The event.
	 * @return WC_Order_Item_Product|null The line, when the customer is to be emailed about it.
	 * @throws RuntimeException When the line kept changing for OrderStatusChanged::MAX_ATTEMPTS tries.
	 */
	private function apply( WC_Order_Item_Product $item, string $order_id, array $tracking, Event $event ): ?WC_Order_Item_Product {
		for ( $attempt = 0; $attempt < OrderStatusChanged::MAX_ATTEMPTS; $attempt++ ) {
			$line = LineState::of( $item );
			if ( $line->ref( LineState::REF_ORDER_ID ) !== $order_id || ! Tracking::may_set( $line, $event->createdAt ) ) {
				return null;
			}
			$previous = Tracking::of( $line );
			if ( $previous === $tracking ) {
				// Nothing to tell, but a later older event must not replace it.
				if ( $line->ref( LineState::REF_TRACKING_AT ) !== $event->createdAt ) {
					$line->set_ref( LineState::REF_TRACKING_AT, $event->createdAt );
					if ( ! $line->save() ) {
						continue;
					}
				}
				return true === $line->ref( LineState::REF_TRACKING_NOTICE ) ? $item : null;
			}
			$shipped = LineState::SHIPPED === $line->state();
			Tracking::set( $line, $tracking, $event->createdAt );
			$line->set_ref( LineState::REF_TRACKING_NOTICE, $shipped ? true : null );
			if ( ! $line->save() ) {
				continue;
			}
			if ( ! $shipped ) {
				return null;
			}
			self::note( $item, $tracking, $previous );
			return $item;
		}
		throw new RuntimeException( sprintf( 'Tracking not applied to order item %d: the line kept changing.', (int) $item->get_id() ) );
	}

	/**
	 * Records that the customer was emailed about the line's tracking.
	 *
	 * @param WC_Order_Item_Product $item The line.
	 * @throws RuntimeException When the line kept changing for OrderStatusChanged::MAX_ATTEMPTS tries.
	 */
	private static function told( WC_Order_Item_Product $item ): void {
		for ( $attempt = 0; $attempt < OrderStatusChanged::MAX_ATTEMPTS; $attempt++ ) {
			$line = LineState::of( $item );
			$line->set_ref( LineState::REF_TRACKING_NOTICE, null );
			if ( $line->save() ) {
				return;
			}
		}
		throw new RuntimeException( sprintf( 'Order item %d not marked as told about its tracking: the line kept changing.', (int) $item->get_id() ) );
	}

	/**
	 * Adds a private note to the line's order.
	 *
	 * @param WC_Order_Item_Product                                      $item     The line.
	 * @param array{carrier?: string, number: string, url?: string}      $tracking Its new tracking.
	 * @param array{carrier?: string, number: string, url?: string}|null $previous Its tracking before, if any.
	 */
	private static function note( WC_Order_Item_Product $item, array $tracking, ?array $previous ): void {
		$order = wc_get_order( $item->get_order_id() );
		if ( ! $order instanceof WC_Order ) {
			return;
		}
		$order->add_order_note(
			null === $previous
				/* translators: 1: product name, 2: carrier, tracking number and link. Order note the merchant reads. */
				? sprintf( __( 'Pixonaut: tracking was added for "%1$s": %2$s', 'pixonaut-for-woocommerce' ), $item->get_name(), Tracking::details( $tracking ) )
				/* translators: 1: product name, 2: new carrier, tracking number and link, 3: the carrier and tracking number before. Order note the merchant reads. */
				: sprintf( __( 'Pixonaut: the tracking of "%1$s" was corrected to %2$s. It was %3$s.', 'pixonaut-for-woocommerce' ), $item->get_name(), Tracking::details( $tracking ), Tracking::text( $previous ) )
		);
	}

	/**
	 * Sends the customer the "Tracking updated" email, at once: WooCommerce's
	 * deferred emails would carry the lines through a JSON job.
	 *
	 * @param WC_Order_Item_Product[] $items The shipped lines of one order whose tracking changed.
	 */
	private static function email( array $items ): void {
		$email = WC()->mailer()->get_emails()[ TrackingChangedEmail::class ] ?? null;
		if ( $email instanceof TrackingChangedEmail ) {
			$email->trigger( $items );
		}
	}
}
