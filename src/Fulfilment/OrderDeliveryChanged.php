<?php
/**
 * Applies `order.delivery_changed`.
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
 * Pixonaut sends `order.delivery_changed` for each change of an order's
 * address after it was sent, and again once a change that was `sending` is
 * answered; it never carries the address. The line keeps the outcome for the
 * order screen. A change the store made itself (`by: store_key`) is noted
 * only when its outcome is news, since DeliveryChange noted the answer it
 * got; once the printshop answers one Pixonaut was still telling it, the
 * address it awaited becomes the printshop order's, or the refused one. One
 * made in Pixonaut, by a studio member or the customer, is always noted, as
 * the store's own order still has the address it had; the store then takes
 * that address as the one to compare with, so only a change of it in
 * WooCommerce is sent. An event older than the outcome the line holds, or
 * about a line whose order's personal data was erased, is left out: events
 * need not arrive in the order they were sent.
 */
final class OrderDeliveryChanged {

	public const TYPE = 'order.delivery_changed';

	/**
	 * Who made the change when it was the store's own integration key.
	 */
	private const BY_STORE = 'store_key';

	/**
	 * Outcomes that give the printshop order the address the store sent.
	 */
	private const TAKEN = array( DeliveryChange::ACCEPTED, DeliveryChange::CARRIED );

	/**
	 * Tries to write a line when another request wrote it meanwhile.
	 */
	private const MAX_ATTEMPTS = 3;

	/**
	 * @param OrderEventLines $lines Finds the lines an event is about.
	 */
	public function __construct( private readonly OrderEventLines $lines ) {
	}

	/**
	 * @param Event $event An `order.delivery_changed` event.
	 * @throws RuntimeException When a line kept changing; handling the event again retries it.
	 */
	public function handle( Event $event ): void {
		$outcome = Payload::string( $event, 'outcome' );
		if ( null === $outcome ) {
			return;
		}
		foreach ( $this->lines->of( $event ) as $line ) {
			$this->apply( $line->item(), $outcome, $event );
		}
	}

	/**
	 * @param WC_Order_Item_Product $item    The line.
	 * @param string                $outcome What the printshop made of the change.
	 * @param Event                 $event   The event.
	 * @throws RuntimeException When the line kept changing for MAX_ATTEMPTS tries.
	 */
	private function apply( WC_Order_Item_Product $item, string $outcome, Event $event ): void {
		$words = Payload::string( $event, 'words' );
		$words = null === $words ? null : sanitize_text_field( $words );
		$ours  = self::BY_STORE === Payload::string( $event, 'by' );
		for ( $attempt = 0; $attempt < self::MAX_ATTEMPTS; $attempt++ ) {
			$line = LineState::of( $item );
			// The erasure dropped the line's fingerprints.
			if ( null !== $line->ref( LineState::REF_ORDER_ERASED_AT ) ) {
				return;
			}
			$delivery = DeliveryChange::of( $line );
			$at       = $delivery[ DeliveryChange::FIELD_AT ] ?? null;
			if ( is_int( $at ) && $at > $event->createdAt ) {
				return;
			}
			$news     = ( $delivery[ DeliveryChange::FIELD_STATE ] ?? null ) !== $outcome || ( $delivery[ DeliveryChange::FIELD_WORDS ] ?? null ) !== $words;
			$awaiting = $delivery[ DeliveryChange::FIELD_AWAITING ] ?? null;
			if ( $ours ) {
				if ( is_string( $awaiting ) && in_array( $outcome, self::TAKEN, true ) ) {
					$delivery[ DeliveryChange::FIELD_ADDRESS ] = $awaiting;
				} elseif ( is_string( $awaiting ) && DeliveryChange::REFUSED === $outcome ) {
					$delivery[ DeliveryChange::FIELD_REFUSED ] = $awaiting;
				}
				if ( DeliveryChange::SENDING !== $outcome ) {
					unset( $delivery[ DeliveryChange::FIELD_AWAITING ] );
				}
			} elseif ( DeliveryChange::REFUSED !== $outcome ) {
				// The printshop order has an address the store has no fingerprint of: the order's own is the one a change in WooCommerce is told from.
				$order                                     = wc_get_order( $item->get_order_id() );
				$delivery[ DeliveryChange::FIELD_ADDRESS ] = $order instanceof WC_Order ? OrderAddress::fingerprint( OrderAddress::of( $order, $item ) ) : null;
				unset( $delivery[ DeliveryChange::FIELD_AWAITING ] );
			}
			unset( $delivery[ DeliveryChange::FIELD_CODE ] );
			$delivery[ DeliveryChange::FIELD_STATE ] = $outcome;
			$delivery[ DeliveryChange::FIELD_AT ]    = $event->createdAt;
			$delivery[ DeliveryChange::FIELD_WORDS ] = $words;
			$line->set_ref( LineState::REF_DELIVERY, array_filter( $delivery, static fn ( mixed $value ): bool => null !== $value ) );
			if ( $line->save() ) {
				if ( ! $ours ) {
					$this->note(
						$item,
						sprintf(
							/* translators: %s: product name of the order line. */
							__( 'Pixonaut: the delivery address of the printshop order for "%s" was changed in Pixonaut. The address of this order was not changed.', 'pixonaut-for-woocommerce' ),
							$item->get_name()
						)
					);
				}
				if ( ! $ours || $news ) {
					$this->note( $item, DeliveryChange::explain( $outcome, $words, $item->get_name() ) );
				}
				return;
			}
		}
		throw new RuntimeException( sprintf( 'Delivery change not applied to order item %d: the line kept changing.', (int) $item->get_id() ) );
	}

	/**
	 * @param WC_Order_Item_Product $item The line.
	 * @param string                $note The note, as it reads.
	 */
	private function note( WC_Order_Item_Product $item, string $note ): void {
		$order = wc_get_order( $item->get_order_id() );
		if ( $order instanceof WC_Order ) {
			$order->add_order_note( $note );
		}
	}
}
