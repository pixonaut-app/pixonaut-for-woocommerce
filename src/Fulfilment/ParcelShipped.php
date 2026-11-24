<?php
/**
 * Applies `parcel.shipped`.
 *
 * @package Pixonaut\WooCommerce
 */

declare(strict_types=1);

namespace Pixonaut\WooCommerce\Fulfilment;

use Pixonaut\WooCommerce\Emails\PartShippedEmail;
use Pixonaut\WooCommerce\Vendor\Pixonaut\Sdk\Webhooks\Event;
use Pixonaut\WooCommerce\Webhooks\Payload;
use RuntimeException;
use WC_Order;

/**
 * Pixonaut sends `parcel.shipped` once a printshop ships a parcel, with the
 * items in it, how many copies of each when the printshop says, and the
 * order's status after it. A line all of whose copies have now shipped moves
 * to `shipped` as `item.status_changed` would move it, with the parcel's
 * tracking; that completes the order once every line has. A line has
 * shipped whole when the parcel holds every copy, when the copies of the
 * order's shipped parcels add up to it, or when the order reads as shipped.
 * A line with copies still to come stays where it is, and the order gets a
 * note of the copies that left.
 *
 * When the order stays open, the customer gets one "Part of your order has
 * shipped" email for the parcel, naming each of its lines, how many copies
 * are in it, and its tracking once. An order of a single line that ships
 * whole gets none, nor does the parcel that completes the order: its
 * customer gets WooCommerce's completed email. The parcel's ParcelRecord
 * keeps that the email went, so the event delivered again, or by the events
 * pull too, tells no one twice. When the items' own `item.status_changed`
 * were handled first, their lines shipped without tracking: the parcel gives
 * it to them, the order gets a note, and a customer whose order is no longer
 * open a "Tracking updated" email.
 */
final class ParcelShipped {

	public const TYPE = 'parcel.shipped';

	/**
	 * The order statuses after which every item of the order has shipped.
	 */
	private const ORDER_SHIPPED = array( 'shipped', 'done' );

	/**
	 * @param OrderEventLines    $lines          Finds the lines a parcel holds.
	 * @param OrderStatusChanged $status_changed Moves a line whose every copy shipped to `shipped`.
	 */
	public function __construct(
		private readonly OrderEventLines $lines,
		private readonly OrderStatusChanged $status_changed,
	) {
	}

	/**
	 * @param Event $event A `parcel.shipped` event.
	 * @throws RuntimeException When a line kept changing while it was shipped; handling the event again retries it.
	 */
	public function handle( Event $event ): void {
		$order_id = Payload::string( $event, 'orderId' );
		$parcel   = Parcel::from_payload( $event->payload['parcel'] ?? null );
		if ( null === $order_id || null === $parcel ) {
			return;
		}
		$held = $this->lines->in_parcel( $parcel, $event );
		if ( array() === $held ) {
			return;
		}
		$order_number = (int) $held[0][0]->item()->get_order_id();
		ParcelLock::run( $order_number, fn ( WC_Order $order ) => $this->ship( $order, $order_id, $parcel, $held, $event ) );
	}

	/**
	 * Ships the parcel's lines and tells the customer, under the order's lock.
	 *
	 * @param WC_Order                                 $order    The order, read once the lock was taken.
	 * @param string                                   $order_id The parcel's Pixonaut order.
	 * @param Parcel                                   $parcel   The parcel.
	 * @param list<array{0: LineState, 1: ParcelItem}> $held     The parcel's lines.
	 * @param Event                                    $event    The `parcel.shipped` event.
	 * @throws RuntimeException When a line kept changing while it was shipped.
	 */
	private function ship( WC_Order $order, string $order_id, Parcel $parcel, array $held, Event $event ): void {
		if ( ParcelRecord::of( $order, $parcel->id )->shipped() ) {
			return;
		}
		$order_number  = $order->get_id();
		$order_shipped = in_array( Payload::string( $event, 'orderStatus' ), self::ORDER_SHIPPED, true );
		$ships         = array();
		foreach ( $held as [ $line, $item ] ) {
			if ( $order_shipped || self::ships_whole( $order, $parcel, $line, $item ) ) {
				$ships[] = $line;
			}
		}
		self::promise_tracking( $order, $parcel, $ships );
		foreach ( $ships as $line ) {
			if ( null !== $parcel->tracking ) {
				Tracking::keep( $line->item(), $parcel->tracking, $event->createdAt );
			}
			$this->status_changed->apply_to( $line->item(), self::line_shipped( $event, $order_id, $line ) );
		}

		// Shipping the lines may have completed the order.
		$order = wc_get_order( $order_number );
		if ( ! $order instanceof WC_Order ) {
			return;
		}
		$record = ParcelRecord::of( $order, $parcel->id );
		// A parcel that shipped without tracking keeps what `parcel.tracking_changed` brought first.
		if ( null !== $parcel->tracking && $record->may_set_tracking( $event->createdAt ) ) {
			$record->set_tracking( $parcel->tracking, $event->createdAt );
		}
		$lines = ParcelLine::all( $held );
		self::note_part( $order, $lines, $record->tracking() );
		$tracking = $record->tracking();
		if ( $record->tracking_notice() && null !== $tracking ) {
			ParcelTrackingChanged::note( $order, $held, $tracking, null );
			if ( ! $order->has_status( 'processing' ) ) {
				ParcelTrackingChanged::tell( $record, $order, $held );
			}
			$record->set_tracking_notice( false );
		}
		if ( $order->has_status( 'processing' ) && self::tells( $order, $lines ) ) {
			self::email( $order, $lines, $record->tracking() );
		}
		$record->set_quantities( $lines );
		$record->mark_shipped();
		$record->save();
	}

	/**
	 * Records, before the lines take the parcel's tracking, that the store
	 * and the customer are to be told of it: an `item.status_changed` handled
	 * before the parcel's event shipped a line without any.
	 *
	 * @param WC_Order    $order  The order.
	 * @param Parcel      $parcel The parcel.
	 * @param LineState[] $ships  The parcel's lines whose every copy has shipped.
	 * @phpstan-param list<LineState> $ships
	 */
	private static function promise_tracking( WC_Order $order, Parcel $parcel, array $ships ): void {
		if ( null === $parcel->tracking ) {
			return;
		}
		foreach ( $ships as $line ) {
			$line = LineState::of( $line->item() );
			if ( LineState::SHIPPED === $line->state() && null === Tracking::of( $line ) ) {
				$record = ParcelRecord::of( $order, $parcel->id );
				$record->set_tracking_notice( true );
				$record->save();
				return;
			}
		}
	}

	/**
	 * Whether, with this parcel, every copy of the line has shipped: the
	 * parcel holds them all, or the copies of the order's shipped parcels add
	 * up to them.
	 *
	 * @param WC_Order   $order  The order.
	 * @param Parcel     $parcel The parcel.
	 * @param LineState  $line   A line of the parcel.
	 * @param ParcelItem $item   The line's item in the parcel.
	 */
	private static function ships_whole( WC_Order $order, Parcel $parcel, LineState $line, ParcelItem $item ): bool {
		if ( $item->whole ) {
			return true;
		}
		if ( null === $item->quantity ) {
			return false;
		}
		return $item->quantity + ParcelRecord::shipped_elsewhere( $order, (int) $line->item()->get_id(), $parcel->id ) >= $item->of_quantity;
	}

	/**
	 * The `item.status_changed` that would ship a line all of whose copies
	 * have now shipped.
	 *
	 * @param Event     $event    The `parcel.shipped` event.
	 * @param string    $order_id The parcel's order.
	 * @param LineState $line     The line.
	 */
	private static function line_shipped( Event $event, string $order_id, LineState $line ): Event {
		$applied = $line->ref( LineState::REF_ORDER_STATUS );
		$payload = array(
			'orderId'        => $order_id,
			'previousStatus' => is_string( $applied ) ? $applied : '',
			'status'         => OrderStatus::SHIPPED,
		);
		return Payload::event( $event->id, OrderStatusChanged::TYPE, $event->createdAt, $payload );
	}

	/**
	 * Whether the customer is emailed about the parcel: the order has more
	 * than one line, or the parcel holds only part of a line.
	 *
	 * @param WC_Order     $order The order, still `processing`.
	 * @param ParcelLine[] $lines The parcel's lines.
	 * @phpstan-param list<ParcelLine> $lines
	 */
	private static function tells( WC_Order $order, array $lines ): bool {
		if ( count( $order->get_items() ) > 1 ) {
			return true;
		}
		foreach ( $lines as $line ) {
			if ( ! $line->whole ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Notes on the order each line the parcel holds only part of, with
	 * copies still to come; a line it finishes shipping gets the shipped note
	 * as it moves.
	 *
	 * @param WC_Order                                                   $order    The order.
	 * @param ParcelLine[]                                               $lines    The parcel's lines.
	 * @param array{carrier?: string, number: string, url?: string}|null $tracking The parcel's tracking.
	 * @phpstan-param list<ParcelLine> $lines
	 */
	private static function note_part( WC_Order $order, array $lines, ?array $tracking ): void {
		foreach ( $lines as $line ) {
			if ( ! $line->rest_to_come ) {
				continue;
			}
			$name = $line->item->get_name();
			$note = null === $line->quantity
				/* translators: %s: product name. Order note the merchant reads. */
				? sprintf( __( 'Pixonaut: part of "%s" was shipped. The rest follow in another parcel.', 'pixonaut-for-woocommerce' ), $name )
				/* translators: 1: copies shipped, 2: copies ordered, 3: product name. Order note the merchant reads. */
				: sprintf( __( 'Pixonaut: %1$d of %2$d of "%3$s" were shipped. The rest follow in another parcel.', 'pixonaut-for-woocommerce' ), $line->quantity, $line->of_quantity, $name );
			if ( null !== $tracking ) {
				/* translators: 1: what was shipped, an order note, 2: carrier, tracking number and link. Order note the merchant reads. */
				$note = sprintf( __( '%1$s Tracking: %2$s', 'pixonaut-for-woocommerce' ), $note, Tracking::details( $tracking ) );
			}
			$order->add_order_note( $note );
		}
	}

	/**
	 * Sends the customer the "Part of your order has shipped" email, at once:
	 * WooCommerce's deferred emails would carry the parcel through a JSON job.
	 *
	 * @param WC_Order                                                   $order    The order.
	 * @param ParcelLine[]                                               $lines    The parcel's lines.
	 * @param array{carrier?: string, number: string, url?: string}|null $tracking The parcel's tracking as last set.
	 * @phpstan-param list<ParcelLine> $lines
	 */
	private static function email( WC_Order $order, array $lines, ?array $tracking ): void {
		$email = WC()->mailer()->get_emails()[ PartShippedEmail::class ] ?? null;
		if ( $email instanceof PartShippedEmail ) {
			$email->trigger( $order, $lines, $tracking );
		}
	}
}
