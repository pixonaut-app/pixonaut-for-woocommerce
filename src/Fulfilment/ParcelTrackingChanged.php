<?php
/**
 * Applies `parcel.tracking_changed`.
 *
 * @package Pixonaut\WooCommerce
 */

declare(strict_types=1);

namespace Pixonaut\WooCommerce\Fulfilment;

use Pixonaut\WooCommerce\Emails\TrackingChangedEmail;
use Pixonaut\WooCommerce\Vendor\Pixonaut\Sdk\Webhooks\Event;
use RuntimeException;
use WC_Order;

/**
 * Pixonaut sends `parcel.tracking_changed` when a printshop gives tracking
 * for a parcel that shipped without any, or corrects it. The parcel's
 * lines keep it in place of their own, the order gets a note, and the
 * customer one "Tracking updated" email for the parcel with each of its
 * lines. A parcel whose `parcel.shipped` has not arrived yet keeps the
 * tracking for its shipment and tells no one. An event older than the one
 * that set the parcel's tracking changes nothing; a newer one bringing the
 * tracking it has tells no one, and only moves the time it was set on, so an
 * older event delivered after it cannot bring back what came between.
 */
final class ParcelTrackingChanged {

	public const TYPE = 'parcel.tracking_changed';

	/**
	 * @param OrderEventLines $lines Finds the lines a parcel holds.
	 */
	public function __construct( private readonly OrderEventLines $lines ) {
	}

	/**
	 * @param Event $event A `parcel.tracking_changed` event.
	 * @throws RuntimeException When a line kept changing; handling the event again retries it.
	 */
	public function handle( Event $event ): void {
		$parcel = Parcel::from_payload( $event->payload['parcel'] ?? null );
		if ( null === $parcel || null === $parcel->tracking ) {
			return;
		}
		$held = $this->lines->in_parcel( $parcel, $event );
		if ( array() === $held ) {
			return;
		}
		ParcelLock::run( (int) $held[0][0]->item()->get_order_id(), fn ( WC_Order $order ) => self::apply( $order, $parcel, $held, $event ) );
	}

	/**
	 * Keeps the parcel's tracking and tells the customer, under the order's lock.
	 *
	 * @param WC_Order                                 $order  The order, read once the lock was taken.
	 * @param Parcel                                   $parcel The parcel, with tracking.
	 * @param list<array{0: LineState, 1: ParcelItem}> $held   The parcel's lines.
	 * @param Event                                    $event  The `parcel.tracking_changed` event.
	 * @throws RuntimeException When a line kept changing.
	 */
	private static function apply( WC_Order $order, Parcel $parcel, array $held, Event $event ): void {
		if ( null === $parcel->tracking ) {
			return;
		}
		$record   = ParcelRecord::of( $order, $parcel->id );
		$previous = $record->tracking();
		$newer    = $record->may_set_tracking( $event->createdAt );
		if ( $newer && $previous === $parcel->tracking ) {
			// Nothing to tell, but an older event delivered later must not replace it.
			foreach ( $held as [ $line ] ) {
				Tracking::keep( $line->item(), $parcel->tracking, $event->createdAt );
			}
			$record->set_tracking( $parcel->tracking, $event->createdAt );
			$record->save();
		}
		if ( ! $newer || $previous === $parcel->tracking ) {
			if ( $record->tracking_notice() ) {
				self::tell( $record, $order, $held );
			}
			return;
		}
		foreach ( $held as [ $line ] ) {
			Tracking::keep( $line->item(), $parcel->tracking, $event->createdAt );
		}
		$record->set_tracking( $parcel->tracking, $event->createdAt );
		$record->set_tracking_notice( $record->shipped() );
		$record->save();
		if ( ! $record->shipped() ) {
			return;
		}
		self::note( $order, $held, $parcel->tracking, $previous );
		self::tell( $record, $order, $held );
	}

	/**
	 * Adds a private note to the order.
	 *
	 * @param WC_Order                                                   $order    The order.
	 * @param list<array{0: LineState, 1: ParcelItem}>                   $held     The parcel's lines.
	 * @param array{carrier?: string, number: string, url?: string}      $tracking Its new tracking.
	 * @param array{carrier?: string, number: string, url?: string}|null $previous Its tracking before, if any.
	 */
	public static function note( WC_Order $order, array $held, array $tracking, ?array $previous ): void {
		$names = implode( ', ', array_map( static fn ( array $line ): string => '"' . $line[0]->item()->get_name() . '"', $held ) );
		$order->add_order_note(
			null === $previous
				/* translators: 1: product names, each in quotes, 2: carrier, tracking number and link. Order note the merchant reads. */
				? sprintf( __( 'Pixonaut: tracking was added for the parcel with %1$s: %2$s', 'pixonaut-for-woocommerce' ), $names, Tracking::details( $tracking ) )
				/* translators: 1: product names, each in quotes, 2: new carrier, tracking number and link, 3: the carrier and tracking number before. Order note the merchant reads. */
				: sprintf( __( 'Pixonaut: the tracking of the parcel with %1$s was corrected to %2$s. It was %3$s.', 'pixonaut-for-woocommerce' ), $names, Tracking::details( $tracking ), Tracking::text( $previous ) )
		);
	}

	/**
	 * Sends the customer the "Tracking updated" email for the parcel, at once,
	 * then records that it went.
	 *
	 * @param ParcelRecord                             $record The parcel's record.
	 * @param WC_Order                                 $order  The order.
	 * @param list<array{0: LineState, 1: ParcelItem}> $held   The parcel's lines.
	 */
	public static function tell( ParcelRecord $record, WC_Order $order, array $held ): void {
		$email = WC()->mailer()->get_emails()[ TrackingChangedEmail::class ] ?? null;
		if ( $email instanceof TrackingChangedEmail ) {
			$email->trigger( $order, ParcelLine::all( $held ), $record->tracking() );
		}
		$record->set_tracking_notice( false );
		$record->save();
	}
}
