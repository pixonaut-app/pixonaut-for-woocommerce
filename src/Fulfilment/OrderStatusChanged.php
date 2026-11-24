<?php
/**
 * Applies `item.status_changed` to the line of the item.
 *
 * @package Pixonaut\WooCommerce
 */

declare(strict_types=1);

namespace Pixonaut\WooCommerce\Fulfilment;

use Pixonaut\WooCommerce\Api\Answers\Order;
use Pixonaut\WooCommerce\Api\Answers\OrderDetail;
use Pixonaut\WooCommerce\Api\Answers\OrderHistoryEntry;
use Pixonaut\WooCommerce\Api\Answers\OrderItem;
use Pixonaut\WooCommerce\Milliseconds;
use Pixonaut\WooCommerce\Vendor\Pixonaut\Sdk\Webhooks\Event;
use Pixonaut\WooCommerce\Webhooks\Payload;
use RuntimeException;
use WC_Order;
use WC_Order_Item_Product;

/**
 * Finds the line by the Pixonaut order and item ids it stored, with
 * `externalRef` as a cross-check, and moves it along by its item's status: `submitted`, `queued` and `printed` mean
 * `ordered` (`printed` with an order note), `shipped` means `shipped`, with
 * the tracking in the line and an order note. A change older than one already
 * applied never moves a line back. When every line of the WooCommerce order is
 * a shipped Pixonaut line, the order is completed; a `shipped` change handled
 * again finishes what a run that stopped left undone: completing the order,
 * or the note and email of a shipment that left it open. `outcome_unknown`
 * moves the line to `needs_attention`.
 */
final class OrderStatusChanged {

	public const TYPE = 'item.status_changed';

	/**
	 * `via` of a change the store made with its own key, which the plugin
	 * applied when it made it.
	 */
	private const VIA_STORE_KEY = 'store_key';

	/**
	 * Tries to write a line when another request wrote it meanwhile.
	 */
	public const MAX_ATTEMPTS = 3;

	/**
	 * The read whose answer `apply_read()` applies, as the log names it.
	 */
	private const READ_OPERATION = 'GET /v1/orders/{id}';

	/**
	 * @param OrderEventLines $lines     Finds the lines an event is about.
	 * @param Shipments       $shipments Completes the order, or tells the customer, once a line ships.
	 */
	public function __construct(
		private readonly OrderEventLines $lines,
		private readonly Shipments $shipments,
	) {
	}

	/**
	 * @param Event $event An `item.status_changed` event.
	 * @throws RuntimeException When a line kept changing while the change was applied; handling the event again retries it.
	 */
	public function handle( Event $event ): void {
		$status = Payload::string( $event, 'status' );
		if ( null === $status || self::VIA_STORE_KEY === Payload::string( $event, 'via' ) ) {
			return;
		}
		foreach ( $this->lines->of( $event ) as $line ) {
			$this->apply_to( $line->item(), $event );
		}
	}

	/**
	 * Applies a change to one line. The store calls it directly for a move
	 * it made itself, which Pixonaut's own event, sent `via` the store's
	 * key, does not repeat.
	 *
	 * @param WC_Order_Item_Product $item  The line.
	 * @param Event                 $event An `item.status_changed` event, or one built from Pixonaut's answer to the store's move.
	 * @throws RuntimeException When the line kept changing for MAX_ATTEMPTS tries.
	 */
	public function apply_to( WC_Order_Item_Product $item, Event $event ): void {
		$status = Payload::string( $event, 'status' );
		if ( null === $status ) {
			return;
		}
		$this->apply( $item, Payload::string( $event, 'orderId' ), $status, Payload::string( $event, 'previousStatus' ) ?? '', Tracking::from_payload( $event->payload['tracking'] ?? null ), $event->createdAt, $event );
	}

	/**
	 * Applies the status of the line's item as a read of the order answered,
	 * by the same rules as an `item.status_changed` event: a status already applied, or one
	 * behind it, changes nothing. A change the store made with its own key is
	 * applied too, in case the store stopped before applying it itself. A
	 * held order's hold is kept, once, as `order.held` would keep it.
	 *
	 * @param LineState   $line A line holding the order's id.
	 * @param OrderDetail $read `GET /v1/orders/{id}`'s answer.
	 * @throws RuntimeException When the line kept changing while the status was applied.
	 */
	public function apply_read( LineState $line, OrderDetail $read ): void {
		$order = $read->order;
		$item  = $line->item_of( $order );
		if ( null === $item || '' === $item->status || ! $this->lines->holds( $line, $order->external_ref, self::READ_OPERATION ) ) {
			return;
		}
		if ( OrderStatus::FAILED === $item->status ) {
			// A failure is applied as `item.failed` would apply it, with the reason the read gives.
			( new OrderFailed( $this->lines ) )->apply_to( $line, $item->last_error ?? '' );
			return;
		}
		if ( OrderStatus::CANCELLED === $item->status ) {
			( new OrderCancelled( $this->lines ) )->apply_to( $line );
			return;
		}
		$order_id = $line->ref( LineState::REF_ORDER_ID );
		$parcel   = $order->shipped_parcel_of( $item->id );
		$this->apply( $line->item(), is_string( $order_id ) ? $order_id : null, $item->status, self::previous_in( $read->history, $item->id ), Tracking::from_answer( $parcel?->tracking ), $parcel?->shipped_at_ms(), null );
		if ( OrderStatus::HELD === $item->status && is_string( $order_id ) ) {
			OrderHold::keep( $line->item(), $order_id, $order->hold );
		}
	}

	/**
	 * The `item.status_changed` event Pixonaut sends for a move the store
	 * made with its own key, built from its answer to the move.
	 *
	 * @param string    $order_id The order.
	 * @param string    $previous The item's status before the move.
	 * @param Order     $moved    Pixonaut's answer: the order as it is now.
	 * @param OrderItem $item     The line's item in it.
	 */
	public static function store_move( string $order_id, string $previous, Order $moved, OrderItem $item ): Event {
		return Payload::event(
			'store_' . wp_generate_uuid4(),
			self::TYPE,
			Milliseconds::of( time() ),
			array(
				'orderId'        => $order_id,
				'externalRef'    => $moved->external_ref,
				'itemId'         => $item->id,
				'previousStatus' => $previous,
				'status'         => $item->status,
				'tracking'       => Tracking::as_sent( $moved->shipped_parcel_of( $item->id )?->tracking ),
				'via'            => self::VIA_STORE_KEY,
			)
		);
	}

	/**
	 * The status before the item's current one, from the order's history.
	 *
	 * @param OrderHistoryEntry[] $history `GET /v1/orders/{id}`'s `history`.
	 * @param string              $item_id The item.
	 * @phpstan-param list<OrderHistoryEntry> $history
	 */
	private static function previous_in( array $history, string $item_id ): string {
		$statuses = array();
		foreach ( $history as $entry ) {
			if ( $entry->item_id === $item_id ) {
				$statuses[] = $entry->status;
			}
		}
		// The last status is the current one; the one before it led there.
		$count = count( $statuses );
		return $count > 1 ? $statuses[ $count - 2 ] : '';
	}

	/**
	 * @param WC_Order_Item_Product                                      $item        The line.
	 * @param string|null                                                $order_id    The order whose status it is; a line a retry handed to another order since is left alone. Null to apply it whatever order the line holds.
	 * @param string                                                     $status      The order's new status.
	 * @param string                                                     $previous    Its status before.
	 * @param array{carrier?: string, number: string, url?: string}|null $tracking    The shipment's tracking, when it carries any.
	 * @param int|null                                                   $tracking_at When that tracking was set, epoch milliseconds; null when not known.
	 * @param Event|null                                                 $event       The event; null for a read of the order.
	 * @throws RuntimeException When the line kept changing for MAX_ATTEMPTS tries.
	 */
	private function apply( WC_Order_Item_Product $item, ?string $order_id, string $status, string $previous, ?array $tracking, ?int $tracking_at, ?Event $event ): void {
		if ( OrderStatus::OUTCOME_UNKNOWN === $status ) {
			$this->outcome_unknown( $item, $order_id, $previous, $event );
			return;
		}
		$map = self::line_states( $event );
		if ( ! isset( $map[ $status ] ) ) {
			if ( ! in_array( $status, OrderStatus::OFF_CHAIN, true ) ) {
				$this->note(
					$item,
					/* translators: 1: product name, 2: status Pixonaut reported */
					sprintf( __( 'Pixonaut reported the status "%2$s" for "%1$s", which this plugin version does not know; nothing was changed.', 'pixonaut-for-woocommerce' ), $item->get_name(), $status )
				);
			}
			return;
		}
		for ( $attempt = 0; $attempt < self::MAX_ATTEMPTS; $attempt++ ) {
			$line = LineState::of( $item );
			if ( ! self::still_holds( $line, $order_id ) ) {
				return;
			}
			if ( ! OrderStatus::moves_on( self::applied( $line ), $previous, $status ) ) {
				// Applied already, perhaps by a run that stopped before the order was taken on.
				if ( OrderStatus::SHIPPED === $status && LineState::SHIPPED === $line->state() ) {
					$this->keep_parcel_tracking( $line, $tracking, $event );
					$this->shipped_again( $line );
				}
				return;
			}
			$line->set_ref( LineState::REF_ORDER_STATUS, $status );
			$shipped_with = OrderStatus::SHIPPED === $status ? self::tracking_to_keep( $line, $tracking, $tracking_at ) : null;
			$target       = $map[ $status ];
			$needs_move   = $line->state() !== $target && LineState::can_move( $line->state(), $target, $line->resume_from() );
			// A move the guard refuses still records the status applied.
			$saved = $needs_move ? $line->transition( $target ) : $line->save();
			if ( $saved ) {
				$this->after( $line, $status, $shipped_with );
				return;
			}
		}
		throw new RuntimeException( sprintf( 'Order status change not applied to order item %d: the line kept changing.', (int) $item->get_id() ) );
	}

	/**
	 * Keeps the shipment's tracking on the line, unless a parcel's event set
	 * newer tracking already, having arrived first; the line's tracking is
	 * then the one the shipment is noted with.
	 * Tracking whose time is not known replaces only tracking no event set.
	 *
	 * @param LineState                                                  $line        The line, about to ship.
	 * @param array{carrier?: string, number: string, url?: string}|null $tracking    The shipment's tracking, when it carries any.
	 * @param int|null                                                   $tracking_at When it was set, epoch milliseconds; null when not known.
	 * @return array{carrier?: string, number: string, url?: string}|null The tracking the line ships with.
	 */
	private static function tracking_to_keep( LineState $line, ?array $tracking, ?int $tracking_at ): ?array {
		$set_by_event = null !== $line->ref( LineState::REF_TRACKING_AT );
		if ( null === $tracking || ( null === $tracking_at ? $set_by_event : ! Tracking::may_set( $line, $tracking_at ) ) ) {
			return Tracking::of( $line );
		}
		if ( null === $tracking_at ) {
			$line->set_ref( LineState::REF_TRACKING, $tracking );
		} else {
			Tracking::set( $line, $tracking, $tracking_at );
		}
		return $tracking;
	}

	/**
	 * The printshop order's submission went unanswered: the line needs the
	 * merchant to ask the printshop, and is never sent again on its own. A
	 * change that does not start where the line stands is older than one
	 * applied since, and is left alone. So is an event Pixonaut created
	 * before the line last moved, when the line is no longer waiting: the
	 * merchant settled the outcome after Pixonaut reported it, and the event
	 * arrived only now.
	 *
	 * @param WC_Order_Item_Product $item     The line.
	 * @param string|null           $order_id The order whose outcome is unknown; null for whatever order the line holds.
	 * @param string                $previous The order's status before.
	 * @param Event|null            $event    The event; null for a read of the order.
	 * @throws RuntimeException When the line kept changing for MAX_ATTEMPTS tries.
	 */
	private function outcome_unknown( WC_Order_Item_Product $item, ?string $order_id, string $previous, ?Event $event ): void {
		$order = wc_get_order( $item->get_order_id() );
		for ( $attempt = 0; $attempt < self::MAX_ATTEMPTS; $attempt++ ) {
			$line = LineState::of( $item );
			if ( ! self::still_holds( $line, $order_id ) ) {
				return;
			}
			$applied = self::applied( $line );
			if ( null !== $applied && $previous !== $applied ) {
				return;
			}
			$settled_since = null !== $event && LineState::NEEDS_ATTENTION !== $line->state() && $event->createdAt < Milliseconds::of( $line->changed_at() );
			if ( $settled_since ) {
				return;
			}
			$line->set_ref( LineState::REF_ORDER_STATUS, OrderStatus::OUTCOME_UNKNOWN );
			$flag  = $order instanceof WC_Order && LineState::NEEDS_ATTENTION !== $line->state() && LineState::can_move( $line->state(), LineState::NEEDS_ATTENTION );
			$saved = $flag
				? NeedsAttention::flag( $line, $order, NeedsAttention::OUTCOME_UNKNOWN, NeedsAttention::outcome_unknown_note( $item ) )
				: $line->save();
			if ( $saved ) {
				return;
			}
		}
		throw new RuntimeException( sprintf( 'Unknown order outcome not applied to order item %d: the line kept changing.', (int) $item->get_id() ) );
	}

	/**
	 * Notes the change on the order, and hands a shipped line to Shipments.
	 *
	 * @param LineState                                                  $line     The line, as saved.
	 * @param string                                                     $status   The status applied.
	 * @param array{carrier?: string, number: string, url?: string}|null $tracking The shipment's tracking.
	 */
	private function after( LineState $line, string $status, ?array $tracking ): void {
		$item = $line->item();
		if ( OrderStatus::PRINTED === $status ) {
			/* translators: %s: product name */
			$this->note( $item, sprintf( __( 'Pixonaut: "%s" was printed at the printshop.', 'pixonaut-for-woocommerce' ), $item->get_name() ) );
		}
		if ( OrderStatus::SHIPPED !== $status || LineState::SHIPPED !== $line->state() ) {
			return;
		}
		$this->note( $item, self::shipped_note( $item, $tracking ) );
		$this->shipments->shipped( $line );
		if ( null === $tracking ) {
			// An item's event carries no tracking: its parcel does, which a read of the order finds.
			LineReader::queue( $item->get_id() );
		}
	}

	/**
	 * Keeps the tracking of the parcel a shipped line left in, as a read of
	 * its order found it, when the line shipped without any.
	 *
	 * @param LineState                                                  $line     A shipped line.
	 * @param array{carrier?: string, number: string, url?: string}|null $tracking The parcel's tracking, when the read found one.
	 * @param Event|null                                                 $event    The event; null for a read of the order.
	 */
	private function keep_parcel_tracking( LineState $line, ?array $tracking, ?Event $event ): void {
		if ( null !== $event || null === $tracking || null !== $line->ref( LineState::REF_TRACKING ) ) {
			return;
		}
		$line->set_ref( LineState::REF_TRACKING, $tracking );
		// A line changed meanwhile is read again with its order.
		$line->save();
	}

	/**
	 * Finishes a shipped line a run stopped short of handling; for a line
	 * handled already, checks only whether its order can complete now.
	 *
	 * @param LineState $line The line, in `shipped`.
	 */
	private function shipped_again( LineState $line ): void {
		if ( true === $line->ref( LineState::REF_SHIPMENT_HANDLED ) ) {
			$this->shipments->complete_order_of( $line->item() );
			return;
		}
		$this->shipments->shipped( $line );
	}

	/**
	 * @param WC_Order_Item_Product                                      $item     The line.
	 * @param array{carrier?: string, number: string, url?: string}|null $tracking The shipment's tracking.
	 */
	private static function shipped_note( WC_Order_Item_Product $item, ?array $tracking ): string {
		if ( null === $tracking ) {
			/* translators: %s: product name */
			return sprintf( __( 'Pixonaut: "%s" was shipped.', 'pixonaut-for-woocommerce' ), $item->get_name() );
		}
		/* translators: 1: product name, 2: carrier, tracking number and link */
		return sprintf( __( 'Pixonaut: "%1$s" was shipped. Tracking: %2$s', 'pixonaut-for-woocommerce' ), $item->get_name(), Tracking::details( $tracking ) );
	}

	/**
	 * Whether the line still holds the order a status is about: a retry may
	 * have handed it to another order since it was found.
	 *
	 * @param LineState   $line     The line, as just read.
	 * @param string|null $order_id The order; null for any.
	 */
	private static function still_holds( LineState $line, ?string $order_id ): bool {
		return null === $order_id || $line->ref( LineState::REF_ORDER_ID ) === $order_id;
	}

	/**
	 * The status last applied to the line.
	 *
	 * @param LineState $line The line.
	 */
	private static function applied( LineState $line ): ?string {
		$status = $line->ref( LineState::REF_ORDER_STATUS );
		return is_string( $status ) ? $status : null;
	}

	/**
	 * The line state each Pixonaut order status puts a line in.
	 *
	 * @param Event|null $event The event being applied; null for a read of the order.
	 * @return array<string, string>
	 */
	private static function line_states( ?Event $event ): array {
		/**
		 * Filters the line state each status of a Pixonaut order item puts
		 * its line in when `item.status_changed` reports it. A status left out
		 * changes nothing; a line never moves back, whatever the map says.
		 *
		 * Since 2.0.0 `$event` is the SDK's
		 * `Pixonaut\WooCommerce\Vendor\Pixonaut\Sdk\Webhooks\Event`. Since
		 * 1.31.0 the statuses are an order item's, and `$event` is an
		 * `item.status_changed` event.
		 *
		 * @since 0.1.0
		 *
		 * @param array<string, string> $map   Pixonaut order item status => line state.
		 * @param Event|null            $event The event being applied; null when a read of the order applies the status.
		 *
		 * @example
		 * add_filter( 'pixonaut_wc_fulfilment_order_status_map', function ( $map ) {
		 *     $map['printed'] = 'printed';
		 *     return $map;
		 * } );
		 */
		$map = (array) apply_filters( 'pixonaut_wc_fulfilment_order_status_map', OrderStatus::LINE_STATES, $event );
		return array_filter( $map, 'is_string' );
	}

	/**
	 * Adds a private note to the line's order.
	 *
	 * @param WC_Order_Item_Product $item The line.
	 * @param string                $note The note.
	 */
	private function note( WC_Order_Item_Product $item, string $note ): void {
		$order = wc_get_order( $item->get_order_id() );
		if ( $order instanceof WC_Order ) {
			$order->add_order_note( $note );
		}
	}
}
