<?php
/**
 * What the merchant can do about a line from the order screen.
 *
 * @package Pixonaut\WooCommerce
 */

declare(strict_types=1);

namespace Pixonaut\WooCommerce\Fulfilment;

use Closure;
use Pixonaut\WooCommerce\Api\Answers\Order;
use Pixonaut\WooCommerce\Api\Answers\OrderDetail;
use Pixonaut\WooCommerce\Api\Answers\OrderItem;
use Pixonaut\WooCommerce\Connection\StoredConnection;
use Pixonaut\WooCommerce\Vendor\Pixonaut\Sdk\Exception\ApiException;
use Pixonaut\WooCommerce\Vendor\Pixonaut\Sdk\Generated\ErrorCode;
use Pixonaut\WooCommerce\Vendor\Pixonaut\Sdk\Generated\Model\OrdersPrintshopOrdersUpdateRequest;
use Pixonaut\WooCommerce\Vendor\Pixonaut\Sdk\Pixonaut as Sdk;
use Pixonaut\WooCommerce\Vendor\Psr\Log\LoggerInterface;
use RuntimeException;
use WC_Order;

/**
 * The actions a line offers, and running them. Pixonaut decides what its
 * order allows: Retry only while the order's `canRetry` says so, and every
 * status move only when its `statusMoves` lists it, so the store never
 * offers what Pixonaut would refuse. A line stopped before it had a
 * printshop order is retried by the store itself, from where it stopped.
 *
 * Pixonaut sends the events of a move the store made with its own key
 * `via: store_key`, which the webhook handlers skip, so each action applies
 * Pixonaut's answer to the line itself. When that answer was lost, the next
 * read of the order brings the line in step with it (reconcile()).
 */
final class LineActions {

	/**
	 * Place the order again: Pixonaut's retry of a failed order, or the
	 * store's own next attempt at a line that has none.
	 */
	public const RETRY = 'retry';

	/**
	 * Place the line's order although its designs have a live order that
	 * the line did not place, as Pixonaut's `reorder` does.
	 */
	public const REORDER = 'reorder';

	/**
	 * The printshop has the order whose outcome was unknown.
	 */
	public const SETTLE_SUBMITTED = 'settle_submitted';

	/**
	 * The printshop does not have the order whose outcome was unknown: it
	 * is marked failed, then retried.
	 */
	public const SETTLE_FAILED = 'settle_failed';

	/**
	 * Ask the printshop to stop the order.
	 */
	public const CANCEL = 'cancel';

	/**
	 * Prefix of a sandbox move, followed by the status, e.g. `rehearse_shipped`.
	 */
	public const REHEARSE = 'rehearse_';

	/**
	 * Statuses a sandbox order can be moved to and the line follows. A move
	 * to `submitted` is left out: it only settles an unknown outcome.
	 */
	public const REHEARSALS = array( OrderStatus::QUEUED, OrderStatus::PRINTED, OrderStatus::SHIPPED, OrderStatus::FAILED );

	/**
	 * Statuses whose order Pixonaut's cancel can ask the printshop about.
	 */
	private const CANCELLABLE = array( OrderStatus::OUTCOME_UNKNOWN );

	/**
	 * Longest `reason` Pixonaut takes.
	 */
	public const REASON_MAX_LENGTH = 500;

	/**
	 * Longest `externalOrderRef` Pixonaut takes.
	 */
	public const ORDER_REF_MAX_LENGTH = 256;

	// What running an action came to, as LineActionResult codes.
	public const DONE                = 'done';
	public const NOT_AVAILABLE       = 'not_available';
	public const REASON_MISSING      = 'reason_missing';
	public const STATUS_NOT_ALLOWED  = 'status_not_allowed';
	public const REFERENCE_TAKEN     = 'reference_taken';
	public const REFUSED             = 'refused';
	public const LINE_CHANGED        = 'line_changed';
	public const RETRY_STILL_PENDING = 'retry_still_pending';
	public const UNREACHABLE         = 'unreachable';
	public const NOT_SCHEDULED       = 'not_scheduled';

	/**
	 * @param Sdk                $pixonaut       Reads and moves Pixonaut orders.
	 * @param Closure            $connection     `fn(): ?StoredConnection`, the connection to act for; null while there is none or it is paused.
	 * @param OrderStatusChanged $status_changed Applies a status the store moved an order to.
	 * @param OrderFailed        $failed         Applies a failure the store reported.
	 * @param Closure            $schedule       `fn(int $item_id): bool`, schedules the line's fulfilment job; false when Action Scheduler refused it.
	 * @param Closure            $scheduled      `fn(int $item_id): bool`, whether the line's fulfilment job is pending or running.
	 * @param LoggerInterface    $logger         Logs the actions Pixonaut refused or the store could not save.
	 */
	public function __construct(
		private readonly Sdk $pixonaut,
		private readonly Closure $connection,
		private readonly OrderStatusChanged $status_changed,
		private readonly OrderFailed $failed,
		private readonly Closure $schedule,
		private readonly Closure $scheduled,
		private readonly LoggerInterface $logger,
	) {
	}

	/**
	 * The line's printshop order as Pixonaut has it now, or null when the
	 * line has none or the store is not connected.
	 *
	 * @param LineState $line The line.
	 *
	 * @throws ApiException When Pixonaut could not be reached or refused, or answered without a field the store reads.
	 */
	public function order_of( LineState $line ): ?OrderDetail {
		$order_id = self::order_id( $line );
		if ( null === $order_id || ! $this->connected() ) {
			return null;
		}
		return OrderDetail::get( $this->pixonaut, $order_id );
	}

	/**
	 * The line and its printshop order as Pixonaut has them now: the order
	 * is read, the line brought in step with it, and the order read again
	 * when the line took on another order meanwhile.
	 *
	 * @param LineState $line The line.
	 *
	 * @return array{0: LineState, 1: OrderDetail|null} The line, and its order from order_of().
	 *
	 * @throws ApiException When Pixonaut could not be reached or refused.
	 */
	public function current( LineState $line ): array {
		$order = $this->order_of( $line );
		if ( null === $order ) {
			return array( $line, null );
		}
		$before = self::order_id( $line );
		$line   = $this->reconcile( $line, $order );
		if ( self::order_id( $line ) !== $before ) {
			$order = $this->order_of( $line );
		}
		return array( $line, $order );
	}

	/**
	 * Brings the line in step with its printshop order as Pixonaut just
	 * answered it. An action whose answer was lost changed the order but not
	 * the line, and Pixonaut's own event of it, sent `via: store_key`, is
	 * skipped; without this the line would keep a state no action leads on
	 * from. A status of the line's item other than the one last applied is
	 * applied now.
	 *
	 * @param LineState   $line The line.
	 * @param OrderDetail $read Its printshop order, from order_of().
	 *
	 * @return LineState The line as it stands now.
	 */
	public function reconcile( LineState $line, OrderDetail $read ): LineState {
		$order_id   = $read->order->id;
		$order_item = $line->item_of( $read->order );
		$status     = $order_item->status ?? '';
		if ( null === $order_item || '' === $status || self::order_id( $line ) !== $order_id ) {
			return $line;
		}
		$line = $this->keep_erasure( $line, $read );
		$item = $line->item();
		try {
			if ( self::applied( $line ) !== $status && $this->apply_status( $line, $order_id, $status, $read->order, $order_item ) ) {
				$line = LineState::of( $item );
				if ( self::applied( $line ) === $status ) {
					$this->note(
						$line,
						/* translators: 1: product name of the order line, 2: the order's status in Pixonaut. */
						__( 'Pixonaut: the printshop order for "%1$s" is now "%2$s" in Pixonaut.', 'pixonaut-for-woocommerce' ),
						$status
					);
				}
			}
			if ( OrderStatus::HELD === $status ) {
				OrderHold::keep( $item, $order_id, $read->order->hold );
			}
		} catch ( RuntimeException $e ) {
			// The next read tries again.
			$this->log_unsaved( 'reconcile', $line, $e );
		}
		return LineState::of( $item );
	}

	/**
	 * Whether the order screen reads a line's printshop order only to learn
	 * whether Pixonaut erased its recipient: Pixonaut sends no event for
	 * that, and erases once the order is finished, after the workspace's
	 * retention period. A line read for its actions learns it there; one
	 * whose erasure is kept is not read again.
	 *
	 * @param LineState $line The line.
	 */
	public function reads_erasure( LineState $line ): bool {
		return null !== self::order_id( $line ) && null === $line->ref( LineState::REF_ORDER_ERASED_AT )
			&& ( in_array( $line->state(), LineState::FINAL, true ) || LineState::FAILED === $line->state() )
			&& ! $this->reads_order( $line ) && $this->connected();
	}

	/**
	 * Keeps on the line when Pixonaut erased its printshop order's
	 * recipient, as a read of the order answers it.
	 *
	 * @param LineState   $line The line.
	 * @param OrderDetail $read Its printshop order, from order_of().
	 *
	 * @return LineState The line as it stands now.
	 */
	public function keep_erasure( LineState $line, OrderDetail $read ): LineState {
		$erased = $read->order->personal_data_erased_at;
		if ( null === $erased || self::order_id( $line ) !== $read->order->id
			|| null !== $line->ref( LineState::REF_ORDER_ERASED_AT ) ) {
			return $line;
		}
		$line->set_ref( LineState::REF_ORDER_ERASED_AT, (int) $erased );
		try {
			// A line changed meanwhile is read again on the next view.
			$line->save();
		} catch ( RuntimeException $e ) {
			$this->log_unsaved( 'keep_erasure', $line, $e );
		}
		return LineState::of( $line->item() );
	}

	/**
	 * Whether the line's actions depend on its printshop order, so the order
	 * screen reads it: a line that needs attention, or any unfinished line in
	 * a sandbox, which can be rehearsed.
	 *
	 * @param LineState $line The line.
	 */
	public function reads_order( LineState $line ): bool {
		if ( null === self::order_id( $line ) || in_array( $line->state(), LineState::FINAL, true ) ) {
			return false;
		}
		return LineState::NEEDS_ATTENTION === $line->state() || $this->in_sandbox();
	}

	/**
	 * The line as Pixonaut has it now and the actions it offers, read as the
	 * order screen reads them: its printshop order only when the actions
	 * depend on it (reads_order()).
	 *
	 * @param LineState $line The line.
	 *
	 * @return array{0: LineState, 1: list<string>} The line, and the actions from available().
	 *
	 * @throws ApiException When Pixonaut could not be reached or refused.
	 */
	public function offered( LineState $line ): array {
		$order = null;
		if ( $this->reads_order( $line ) ) {
			list( $line, $order ) = $this->current( $line );
		}
		return array( $line, $this->available( $line, $order ) );
	}

	/**
	 * The actions the line offers now.
	 *
	 * @param LineState        $line The line.
	 * @param OrderDetail|null $read Its printshop order from order_of(); null when it has none or it could not be read.
	 *
	 * @return list<string> Action ids, e.g. `retry` or `rehearse_shipped`.
	 */
	public function available( LineState $line, ?OrderDetail $read ): array {
		$actions = array();
		if ( ! in_array( $line->state(), LineState::FINAL, true ) && $this->connected() ) {
			$actions = null === self::order_id( $line ) ? $this->store_actions( $line ) : $this->order_actions( $line, $read );
		}

		/**
		 * Filters the actions the order screen offers for a Pixonaut line.
		 * Pixonaut refuses an action its order does not allow, so adding one
		 * only adds a button that fails.
		 *
		 * @since 0.1.0
		 *
		 * @param list<string>              $actions Action ids: `retry`, `reorder`, `settle_submitted`, `settle_failed`, `cancel`, `rehearse_<status>`.
		 * @param LineState                 $line    The line's state and refs.
		 * @param array<string, mixed>|null $order   Its printshop order as Pixonaut has it; null when it has none or it could not be read.
		 *
		 * @example
		 * add_filter( 'pixonaut_wc_fulfilment_line_actions', function ( $actions ) {
		 *     return array_values( array_diff( $actions, array( 'cancel' ) ) );
		 * } );
		 */
		$filtered = (array) apply_filters( 'pixonaut_wc_fulfilment_line_actions', $actions, $line, $read?->order->as_sent );
		return array_values( array_filter( $filtered, 'is_string' ) );
	}

	/**
	 * Runs an action the line offers now; what it offers is read again
	 * first, so an action Pixonaut no longer allows is not sent.
	 *
	 * @param LineState $line   The line.
	 * @param string    $action An action id from available().
	 * @param string    $reason What the merchant learned from the printshop; required to settle.
	 * @param string    $ref    The printshop's own reference, when it has the order.
	 *
	 * @return LineActionResult What came of it.
	 */
	public function run( LineState $line, string $action, string $reason = '', string $ref = '' ): LineActionResult {
		$reason = mb_substr( sanitize_textarea_field( $reason ), 0, self::REASON_MAX_LENGTH );
		$ref    = mb_substr( sanitize_text_field( $ref ), 0, self::ORDER_REF_MAX_LENGTH );
		try {
			list( $line, $order ) = $this->current( $line );
		} catch ( ApiException $e ) {
			// Only read: nothing was sent that changes anything.
			return self::refused( $e );
		}
		if ( ! in_array( $action, $this->available( $line, $order ), true ) ) {
			return new LineActionResult( self::NOT_AVAILABLE );
		}
		try {
			$order_id = (string) self::order_id( $line );
			$item     = null === $order ? null : $line->item_of( $order->order );
			if ( self::RETRY === $action ) {
				if ( null === $order ) {
					return $this->retry_line( $line );
				}
				return null === $item ? new LineActionResult( self::REFUSED, ApiException::UNEXPECTED_RESPONSE ) : $this->retry_order( $line, $order_id, $item->id );
			}
			if ( self::REORDER === $action ) {
				return $this->reorder( $line );
			}
			if ( self::SETTLE_SUBMITTED === $action || self::SETTLE_FAILED === $action ) {
				if ( '' === trim( $reason ) ) {
					return new LineActionResult( self::REASON_MISSING );
				}
				return self::SETTLE_SUBMITTED === $action
					? $this->settle_submitted( $line, $order_id, (string) $item?->printshop_order_id(), $reason, $ref )
					: $this->settle_failed( $line, $order_id, (string) $item?->printshop_order_id(), (string) $item?->id, $reason );
			}
			if ( self::CANCEL === $action ) {
				return $this->cancel( $line, $order_id, (string) $item?->id );
			}
			return $this->rehearse( $line, $order_id, (string) $item?->printshop_order_id(), $item->status ?? '', substr( $action, strlen( self::REHEARSE ) ) );
		} catch ( ApiException $e ) {
			$this->logger->warning(
				'Order line action refused by Pixonaut.',
				array(
					'action'    => $action,
					'operation' => $e->operation,
					'status'    => $e->status,
					'code'      => $e->errorCode,
				)
			);
			// No answer arrived, so Pixonaut may have acted on it; the next read of the order shows whether.
			$unanswered = 0 === $e->status && ApiException::TRANSPORT_FAILED === $e->errorCode;
			if ( $unanswered ) {
				return new LineActionResult( self::UNREACHABLE );
			}
			return 'orders.items.retry' === $e->operation ? $this->retry_refused( $line, $e ) : self::refused( $e );
		} catch ( RuntimeException $e ) {
			$this->log_unsaved( $action, $line, $e );
			return new LineActionResult( self::LINE_CHANGED );
		}
	}

	/**
	 * A line stopped before its printshop order: the store's next attempt.
	 * A line of a paid order that fulfilment takes on, with no job pending
	 * or running, lost its job and is retried too.
	 *
	 * @param LineState $line The line.
	 *
	 * @return list<string>
	 */
	private function store_actions( LineState $line ): array {
		if ( $this->stranded( $line ) ) {
			return array( self::RETRY );
		}
		if ( LineState::NEEDS_ATTENTION !== $line->state() || ! in_array( $line->resume_from(), LineState::FULFILLABLE, true ) ) {
			return array();
		}
		// A standing design is never refused as ordered, so a line of standing designs alone is never ordered again.
		$ordered_elsewhere = ErrorCode::DESIGN_ALREADY_ORDERED === $line->reason() && true !== $line->ref( LineState::REF_REORDER ) && array() !== $line->sides();
		return $ordered_elsewhere ? array( self::RETRY, self::REORDER ) : array( self::RETRY );
	}

	/**
	 * Whether the line waits in a state fulfilment takes on, its order is
	 * paid, and it has no job pending or running.
	 *
	 * @param LineState $line The line.
	 */
	private function stranded( LineState $line ): bool {
		if ( ! in_array( $line->state(), LineState::FULFILLABLE, true ) ) {
			return false;
		}
		$order = wc_get_order( $line->item()->get_order_id() );
		return $order instanceof WC_Order && $order->is_paid() && true !== ( $this->scheduled )( $line->item()->get_id() );
	}

	/**
	 * What the line's printshop order allows.
	 *
	 * @param LineState        $line The line.
	 * @param OrderDetail|null $read Its printshop order; null when it could not be read.
	 *
	 * @return list<string>
	 */
	private function order_actions( LineState $line, ?OrderDetail $read ): array {
		$item   = null === $read ? null : $line->item_of( $read->order );
		$status = $item->status ?? '';
		// Moves and settles are of the printshop order the item was sent in; one not sent yet has none.
		$moves   = null === $item?->printshop_order_id() ? array() : $item->status_moves;
		$actions = array();
		if ( LineState::NEEDS_ATTENTION === $line->state() ) {
			if ( true === $item?->can_retry ) {
				$actions[] = self::RETRY;
			}
			if ( OrderStatus::OUTCOME_UNKNOWN === $status ) {
				if ( in_array( OrderStatus::SUBMITTED, $moves, true ) ) {
					$actions[] = self::SETTLE_SUBMITTED;
				}
				if ( in_array( OrderStatus::FAILED, $moves, true ) ) {
					$actions[] = self::SETTLE_FAILED;
				}
			}
			if ( in_array( $status, self::CANCELLABLE, true ) ) {
				$actions[] = self::CANCEL;
			}
		}
		if ( $this->in_sandbox() ) {
			foreach ( self::REHEARSALS as $rehearsal ) {
				// A settled outcome is the settling actions' to report.
				$settles = OrderStatus::OUTCOME_UNKNOWN === $status && OrderStatus::FAILED === $rehearsal;
				if ( in_array( $rehearsal, $moves, true ) && ! $settles ) {
					$actions[] = self::REHEARSE . $rehearsal;
				}
			}
		}
		return $actions;
	}

	/**
	 * Takes the line back to the state it stopped in and schedules its job.
	 *
	 * @param LineState $line A line in `needs_attention` without a printshop order, or one that lost its job.
	 */
	private function retry_line( LineState $line ): LineActionResult {
		$item    = $line->item();
		$resume  = (string) $line->resume_from();
		$stopped = LineState::NEEDS_ATTENTION === $line->state();
		if ( $stopped && ! $line->transition( $resume, '' ) ) {
			return new LineActionResult( self::LINE_CHANGED );
		}
		if ( true !== ( $this->schedule )( $item->get_id() ) ) {
			$this->logger->error( 'Order line retry could not be scheduled.', array( 'item' => $item->get_id() ) );
			$order = wc_get_order( $item->get_order_id() );
			if ( $order instanceof WC_Order ) {
				NeedsAttention::flag(
					LineState::of( $item ),
					$order,
					NeedsAttention::RETRY_NOT_SCHEDULED,
					sprintf(
						/* translators: %s: product name of the order line. */
						__( 'Pixonaut: "%s" could not be sent again: the store could not schedule it. Retry the item.', 'pixonaut-for-woocommerce' ),
						$item->get_name()
					)
				);
			}
			return new LineActionResult( self::NOT_SCHEDULED );
		}
		$this->note(
			$line,
			/* translators: %s: product name of the order line. */
			__( 'Pixonaut: "%s" will be sent to the printshop again.', 'pixonaut-for-woocommerce' )
		);
		return new LineActionResult( self::DONE );
	}

	/**
	 * Sends the line's order again with `reorder`, under a key of its own,
	 * which the next run builds.
	 *
	 * @param LineState $line A line stopped by `design_already_ordered`.
	 */
	private function reorder( LineState $line ): LineActionResult {
		$line->set_ref( LineState::REF_REORDER, true );
		$line->set_ref( LineState::REF_ORDER_KEY, null );
		$line->set_ref( LineState::REF_ORDER_REQUEST, null );
		return $line->save() ? $this->retry_line( $line ) : new LineActionResult( self::LINE_CHANGED );
	}

	/**
	 * Pixonaut's retry of the line's failed item, in its order. When an
	 * automation ordered the design again meanwhile, or an earlier retry
	 * placed another order already, the line adopts that order's item.
	 *
	 * @param LineState $line     The line.
	 * @param string    $order_id Its order.
	 * @param string    $item_id  Its failed item.
	 *
	 * @throws ApiException When Pixonaut refused or could not be reached.
	 */
	private function retry_order( LineState $line, string $order_id, string $item_id ): LineActionResult {
		$by_automation = null;
		try {
			// No reroute: the item goes back to the printshop it went to.
			$placed = Order::retry( $this->pixonaut, $order_id, $item_id );
			$item   = $placed->item( $item_id, null );
		} catch ( ApiException $e ) {
			$automation = AdoptedOrder::named_by( $e );
			$newer      = ErrorCode::ORDER_SUPERSEDED === $e->errorCode ? ( $e->details['newerOrderId'] ?? null ) : $automation;
			if ( ! is_string( $newer ) || '' === $newer ) {
				throw $e;
			}
			$read = OrderDetail::get( $this->pixonaut, $newer );
			$item = null === $automation ? $line->item_of( $read->order ) : AdoptedOrder::takes( $line, $read, AdoptedOrder::item_named_by( $e ) );
			if ( null === $item ) {
				// Only an automation's order of the item as bought is taken on; the merchant settles any other.
				throw $e;
			}
			$placed        = $read->order;
			$by_automation = null === $automation ? null : $read;
		}
		if ( null === $item ) {
			return new LineActionResult( self::REFUSED, ApiException::UNEXPECTED_RESPONSE );
		}
		return $this->adopt( $line, $placed, $item, $by_automation );
	}

	/**
	 * Makes an order's item the line's: its own item sent again, or one of
	 * another order. A status on the way to the customer is applied as an
	 * `item.status_changed` event would be, so an item that shipped already
	 * ships the line, with its tracking, and one Pixonaut holds shows as
	 * held, with its hold.
	 *
	 * @param LineState        $line          The line.
	 * @param Order            $placed        The order.
	 * @param OrderItem        $item          The line's item in it.
	 * @param OrderDetail|null $by_automation The same order read on its own, when one of the workspace's automations placed it, not the store's retry.
	 *
	 * @throws RuntimeException When the line kept changing while the status was applied.
	 */
	private function adopt( LineState $line, Order $placed, OrderItem $item, ?OrderDetail $by_automation = null ): LineActionResult {
		$id     = $placed->id;
		$status = $item->status;
		if ( '' === $id ) {
			return new LineActionResult( self::REFUSED, ApiException::UNEXPECTED_RESPONSE );
		}
		if ( self::order_id( $line ) === $id ) {
			// Sent again in the same order: its statuses start over from the retry.
			$line->set_ref( LineState::REF_ORDER_STATUS, null );
		}
		$line->replace_order( $id, $item->id );
		$address = null;
		if ( null !== $by_automation ) {
			AdoptedOrder::record( $line, $by_automation, $item );
			// The delivery the failed order had is not this order's: it ships where the automation sent it.
			$address = AdoptedOrder::address( $placed );
			$line->set_ref( LineState::REF_DELIVERY, array( DeliveryChange::FIELD_ADDRESS => $address ) );
		}
		$on_the_way = isset( OrderStatus::LINE_STATES[ $status ] );
		if ( ! $on_the_way ) {
			$line->set_ref( LineState::REF_ORDER_STATUS, $status );
		}
		if ( ! $line->save() ) {
			return new LineActionResult( self::LINE_CHANGED );
		}
		$order = null === $address ? null : wc_get_order( $line->item()->get_order_id() );
		if ( $order instanceof WC_Order && OrderAddress::fingerprint( OrderAddress::of( $order, $line->item() ) ) !== $address ) {
			DeliveryChange::schedule( $line->item()->get_id() );
		}
		if ( $on_the_way ) {
			$this->note(
				$line,
				/* translators: 1: product name of the order line, 2: the new Pixonaut order id. */
				__( 'Pixonaut: "%1$s" was sent to the printshop again, as order %2$s.', 'pixonaut-for-woocommerce' ),
				$id
			);
			$this->status_changed->apply_to( $line->item(), OrderStatusChanged::store_move( $id, '', $placed, $item ) );
			if ( OrderStatus::HELD === $status ) {
				OrderHold::keep( $line->item(), $id, $placed->hold );
			}
			return new LineActionResult( self::DONE );
		}
		$this->note(
			$line,
			/* translators: 1: product name of the order line, 2: the new Pixonaut order id, 3: its status. */
			__( 'Pixonaut: "%1$s" was sent to the printshop again, as order %2$s, which is "%3$s".', 'pixonaut-for-woocommerce' ),
			$id,
			$status
		);
		return new LineActionResult( self::RETRY_STILL_PENDING, $status );
	}

	/**
	 * The printshop has the order: Pixonaut follows it from here.
	 *
	 * @param LineState $line               The line.
	 * @param string    $order_id           Its order.
	 * @param string    $printshop_order_id The printshop order its item went in, whose outcome is unknown.
	 * @param string    $reason             What the printshop said.
	 * @param string    $ref                The printshop's own reference; empty for none.
	 *
	 * @throws ApiException When Pixonaut refused or could not be reached.
	 * @throws RuntimeException When the line kept changing.
	 */
	private function settle_submitted( LineState $line, string $order_id, string $printshop_order_id, string $reason, string $ref ): LineActionResult {
		$body = array(
			'status' => OrderStatus::SUBMITTED,
			'reason' => $reason,
		);
		if ( '' !== $ref ) {
			$body['externalOrderRef'] = $ref;
		}
		$settled = Order::update_printshop_order( $this->pixonaut, $order_id, $printshop_order_id, OrdersPrintshopOrdersUpdateRequest::fromArray( $body ) );
		$this->note(
			$line,
			/* translators: 1: product name of the order line, 2: what the merchant learned from the printshop. */
			__( 'Pixonaut: the printshop has the order for "%1$s". %2$s', 'pixonaut-for-woocommerce' ),
			$reason
		);
		$this->apply_move( $line, $order_id, OrderStatus::OUTCOME_UNKNOWN, $settled );
		return new LineActionResult( self::DONE );
	}

	/**
	 * The printshop does not have the order: Pixonaut marks it failed, and
	 * the item is placed again at once.
	 *
	 * @param LineState $line               The line.
	 * @param string    $order_id           Its order.
	 * @param string    $printshop_order_id The printshop order its item went in, whose outcome is unknown.
	 * @param string    $item_id            The line's item.
	 * @param string    $reason             What the printshop said.
	 *
	 * @throws ApiException When Pixonaut refused or could not be reached; a refused retry leaves the item failed, to retry.
	 */
	private function settle_failed( LineState $line, string $order_id, string $printshop_order_id, string $item_id, string $reason ): LineActionResult {
		$moved = Order::update_printshop_order( $this->pixonaut, $order_id, $printshop_order_id, new OrdersPrintshopOrdersUpdateRequest( status: OrderStatus::FAILED, reason: $reason ) );
		$this->fail_moved_with( $line, $order_id, $printshop_order_id, $moved, $reason );
		$line->set_ref( LineState::REF_ORDER_STATUS, OrderStatus::FAILED );
		if ( ! $line->save() ) {
			return new LineActionResult( self::LINE_CHANGED );
		}
		$this->note(
			$line,
			/* translators: 1: product name of the order line, 2: what the merchant learned from the printshop. */
			__( 'Pixonaut: the printshop does not have the order for "%1$s". %2$s', 'pixonaut-for-woocommerce' ),
			$reason
		);
		return $this->retry_order( $line, $order_id, $item_id );
	}

	/**
	 * Asks the printshop to stop the line's item; the line is cancelled once
	 * it did. A printshop that stops whole orders stops the items sent with
	 * it too, and their lines follow from their own `item.cancelled`.
	 *
	 * @param LineState $line     The line.
	 * @param string    $order_id Its order.
	 * @param string    $item_id  The line's item.
	 *
	 * @throws ApiException When the printshop could not stop it, or Pixonaut refused or could not be reached.
	 */
	private function cancel( LineState $line, string $order_id, string $item_id ): LineActionResult {
		$cancelled = Order::cancel_item( $this->pixonaut, $order_id, $item_id )->item( $item_id, null );
		$line->set_ref( LineState::REF_ORDER_STATUS, $cancelled->status ?? OrderStatus::CANCELLED );
		if ( ! $line->transition( LineState::CANCELLED ) ) {
			return new LineActionResult( self::LINE_CHANGED );
		}
		$this->note(
			$line,
			/* translators: %s: product name of the order line. */
			__( 'Pixonaut: the printshop order for "%s" was cancelled.', 'pixonaut-for-woocommerce' )
		);
		return new LineActionResult( self::DONE );
	}

	/**
	 * Moves a sandbox printshop order on as its printshop would, and the line with it.
	 *
	 * @param LineState $line               The line.
	 * @param string    $order_id           Its sandbox order.
	 * @param string    $printshop_order_id The printshop order its item went in.
	 * @param string    $from               The item's status now.
	 * @param string    $to                 One of REHEARSALS.
	 *
	 * @throws ApiException When Pixonaut refused or could not be reached.
	 * @throws RuntimeException When the line kept changing.
	 */
	private function rehearse( LineState $line, string $order_id, string $printshop_order_id, string $from, string $to ): LineActionResult {
		$moved = Order::update_printshop_order( $this->pixonaut, $order_id, $printshop_order_id, new OrdersPrintshopOrdersUpdateRequest( status: $to ) );
		if ( OrderStatus::FAILED === $to ) {
			/* translators: Reason in an order note when a merchant rehearses a failed printshop order in the Pixonaut sandbox. */
			$reason = __( 'Rehearsed in the Pixonaut sandbox.', 'pixonaut-for-woocommerce' );
			$this->failed->apply_to( $line, $reason );
			$this->fail_moved_with( $line, $order_id, $printshop_order_id, $moved, $reason );
		} else {
			$this->apply_move( $line, $order_id, $from, $moved );
		}
		return new LineActionResult( self::DONE );
	}

	/**
	 * Fails the order's other lines whose items a failure the store reported
	 * moved with the line's: the whole printshop order failed, and their
	 * events of it come `via: store_key`, which the handlers skip.
	 *
	 * @param LineState $line               The line the merchant acted on.
	 * @param string    $order_id           Its order.
	 * @param string    $printshop_order_id The printshop order that failed.
	 * @param Order     $moved              Pixonaut's answer: the order as it is now.
	 * @param string    $reason             Why it failed.
	 *
	 * @throws RuntimeException When a line kept changing.
	 */
	private function fail_moved_with( LineState $line, string $order_id, string $printshop_order_id, Order $moved, string $reason ): void {
		foreach ( LineState::for_order_id( $order_id ) as $other ) {
			$item = $other->item()->get_id() === $line->item()->get_id() ? null : $other->item_of( $moved );
			if ( null !== $item && OrderStatus::FAILED === $item->status && $printshop_order_id === $item->printshop_order_id() ) {
				$this->failed->apply_to( $other, $reason );
			}
		}
	}

	/**
	 * Applies a move the store made to the line, from Pixonaut's answer, and
	 * to the order's other lines its printshop order moved with it: their
	 * events of it come `via: store_key`, which the handlers skip.
	 *
	 * @param LineState $line     The line.
	 * @param string    $order_id Its order.
	 * @param string    $from     The item's status before the move.
	 * @param Order     $moved    Pixonaut's answer: the order as it is now.
	 *
	 * @throws RuntimeException When the line kept changing.
	 */
	private function apply_move( LineState $line, string $order_id, string $from, Order $moved ): void {
		$item = $line->item_of( $moved );
		if ( null === $item ) {
			return;
		}
		$this->status_changed->apply_to( $line->item(), OrderStatusChanged::store_move( $order_id, $from, $moved, $item ) );
		$printshop_order = $item->printshop_order_id();
		foreach ( LineState::for_order_id( $order_id ) as $other ) {
			$moved_with = $other->item()->get_id() === $line->item()->get_id() ? null : $other->item_of( $moved );
			if ( null !== $moved_with && null !== $printshop_order && $moved_with->printshop_order_id() === $printshop_order ) {
				$applied = $other->ref( LineState::REF_ORDER_STATUS );
				$this->status_changed->apply_to( $other->item(), OrderStatusChanged::store_move( $order_id, is_string( $applied ) ? $applied : '', $moved, $moved_with ) );
			}
		}
	}

	/**
	 * What a refusal comes to: a move Pixonaut no longer allows and a
	 * reference another order holds are told apart, so the order screen can
	 * say what to do.
	 *
	 * @param ApiException $error The refusal.
	 */
	private static function refused( ApiException $error ): LineActionResult {
		return match ( $error->errorCode ) {
			ErrorCode::ORDER_STATUS_NOT_ALLOWED,
			ErrorCode::ORDER_NOT_RETRYABLE,
			ErrorCode::RETRY_REFUSED => new LineActionResult( self::STATUS_NOT_ALLOWED ),
			ErrorCode::ORDER_REFERENCE_TAKEN => new LineActionResult( self::REFERENCE_TAKEN ),
			default => new LineActionResult( self::REFUSED, $error->errorCode ),
		};
	}

	/**
	 * A refused retry of the line's order: the order screen and an order
	 * note say why in words, so the merchant knows what to do.
	 *
	 * @param LineState    $line  The line.
	 * @param ApiException $error The refusal.
	 */
	private function retry_refused( LineState $line, ApiException $error ): LineActionResult {
		$words = RetryRefusal::explain( $error->errorCode );
		$this->note(
			$line,
			/* translators: 1: product name of the order line, 2: why Pixonaut refused, a full sentence or two. */
			__( 'Pixonaut: "%1$s" was not sent to the printshop again. %2$s', 'pixonaut-for-woocommerce' ),
			$words
		);
		$refused = self::refused( $error );
		return new LineActionResult( $refused->code, $refused->detail, $words );
	}

	/**
	 * @param LineState $line    The line.
	 * @param string    $message A translated message with `%1$s` (or `%s`) for the product name.
	 * @param string    ...$args The message's further values.
	 */
	private function note( LineState $line, string $message, string ...$args ): void {
		$order = wc_get_order( $line->item()->get_order_id() );
		if ( $order instanceof WC_Order ) {
			$order->add_order_note( sprintf( $message, $line->item()->get_name(), ...$args ) );
		}
	}

	/**
	 * Applies a status the order reached that the line did not follow.
	 *
	 * @param LineState $line     The line.
	 * @param string    $order_id Its order.
	 * @param string    $status   Its item's status, other than the one last applied.
	 * @param Order     $order    The order as Pixonaut answered it.
	 * @param OrderItem $placed   The line's item in it.
	 *
	 * @return bool False for a status this version does not know, which is left alone.
	 *
	 * @throws RuntimeException When the line kept changing.
	 */
	private function apply_status( LineState $line, string $order_id, string $status, Order $order, OrderItem $placed ): bool {
		$item = $line->item();
		if ( isset( OrderStatus::LINE_STATES[ $status ] ) || OrderStatus::OUTCOME_UNKNOWN === $status ) {
			// Starting from the status last applied, so the move is not taken for a late one.
			$this->status_changed->apply_to( $item, OrderStatusChanged::store_move( $order_id, (string) self::applied( $line ), $order, $placed ) );
			return true;
		}
		if ( OrderStatus::FAILED === $status ) {
			$this->failed->apply_to( $line, $placed->last_error ?? '' );
			// A line waiting already is left as it is, but follows the order's status.
			$this->record_status( LineState::of( $item ), $status, null );
			return true;
		}
		if ( OrderStatus::CANCELLED === $status ) {
			$this->record_status( $line, $status, LineState::CANCELLED );
			return true;
		}
		return false;
	}

	/**
	 * Records the order's status on the line, and moves the line when it can.
	 *
	 * @param LineState   $line   The line.
	 * @param string      $status The order's status.
	 * @param string|null $to     The state to move the line to; null to only record the status.
	 *
	 * @throws RuntimeException When the line changed elsewhere meanwhile.
	 */
	private function record_status( LineState $line, string $status, ?string $to ): void {
		if ( self::applied( $line ) === $status ) {
			return;
		}
		$line->set_ref( LineState::REF_ORDER_STATUS, $status );
		$moves = null !== $to && LineState::can_move( $line->state(), $to, $line->resume_from() );
		if ( ! ( $moves ? $line->transition( $to ) : $line->save() ) ) {
			throw new RuntimeException( sprintf( 'Order status not recorded on order item %d: the line changed meanwhile.', (int) $line->item()->get_id() ) );
		}
	}

	/**
	 * Logs a line the store could not save, without the merchant's words.
	 *
	 * @param string           $action The action, or `reconcile`.
	 * @param LineState        $line   The line.
	 * @param RuntimeException $error  Why.
	 */
	private function log_unsaved( string $action, LineState $line, RuntimeException $error ): void {
		$item = $line->item();
		$this->logger->error(
			'Order line not saved: ' . $error->getMessage(),
			array(
				'action'   => $action,
				'order'    => $item->get_order_id(),
				'item'     => $item->get_id(),
				'pixonaut' => (string) self::order_id( $line ),
			)
		);
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

	private static function order_id( LineState $line ): ?string {
		$order_id = $line->ref( LineState::REF_ORDER_ID );
		return is_string( $order_id ) && '' !== $order_id ? $order_id : null;
	}

	private function connected(): bool {
		return ( $this->connection )() instanceof StoredConnection;
	}

	private function in_sandbox(): bool {
		$connection = ( $this->connection )();
		return $connection instanceof StoredConnection && StoredConnection::SANDBOX === $connection->environment;
	}
}
