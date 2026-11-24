<?php
/**
 * Applies `parcel.delivered`.
 *
 * @package Pixonaut\WooCommerce
 */

declare(strict_types=1);

namespace Pixonaut\WooCommerce\Fulfilment;

use Pixonaut\WooCommerce\Vendor\Pixonaut\Sdk\Webhooks\Event;
use WC_Order;

/**
 * Pixonaut sends `parcel.delivered` when the printshop reports that a parcel
 * reached the customer. The order gets a note naming the parcel's lines,
 * once per parcel however often it is reported; nothing else changes.
 */
final class ParcelDelivered {

	public const TYPE = 'parcel.delivered';

	/**
	 * @param OrderEventLines $lines Finds the lines a parcel holds.
	 */
	public function __construct( private readonly OrderEventLines $lines ) {
	}

	/**
	 * @param Event $event A `parcel.delivered` event.
	 * @throws \RuntimeException When another request is handling the order's parcels; handling the event again retries it.
	 */
	public function handle( Event $event ): void {
		$parcel = Parcel::from_payload( $event->payload['parcel'] ?? null );
		if ( null === $parcel ) {
			return;
		}
		$held = $this->lines->in_parcel( $parcel, $event );
		if ( array() === $held ) {
			return;
		}
		ParcelLock::run( (int) $held[0][0]->item()->get_order_id(), static fn ( WC_Order $order ) => self::note( $order, $parcel, $held ) );
	}

	/**
	 * Notes the delivery once, under the order's lock.
	 *
	 * @param WC_Order                                 $order  The order, read once the lock was taken.
	 * @param Parcel                                   $parcel The parcel.
	 * @param list<array{0: LineState, 1: ParcelItem}> $held   The parcel's lines.
	 */
	private static function note( WC_Order $order, Parcel $parcel, array $held ): void {
		$record = ParcelRecord::of( $order, $parcel->id );
		if ( $record->delivered() ) {
			return;
		}
		$names = implode( ', ', array_map( static fn ( array $line ): string => '"' . $line[0]->item()->get_name() . '"', $held ) );
		/* translators: %s: product names, each in quotes. Order note the merchant reads. */
		$order->add_order_note( sprintf( __( 'Pixonaut: the parcel with %s was delivered.', 'pixonaut-for-woocommerce' ), $names ) );
		$record->mark_delivered();
		$record->save();
	}
}
