<?php
/**
 * What the merchant can do about a line from the order screen.
 *
 * @package Pixonaut\WooCommerce
 */

declare(strict_types=1);

namespace Pixonaut\WooCommerce\Fulfilment;

use Closure;
use Pixonaut\WooCommerce\Connection\StoredConnection;
use Pixonaut\WooCommerce\Milliseconds;
use Pixonaut\WooCommerce\Vendor\Pixonaut\Sdk\Exception\ApiException;
use Pixonaut\WooCommerce\Vendor\Pixonaut\Sdk\Generated\ErrorCode;
use Pixonaut\WooCommerce\Vendor\Pixonaut\Sdk\Generated\Model\OrdersRetryRequest;
use Pixonaut\WooCommerce\Vendor\Pixonaut\Sdk\Generated\Model\OrdersUpdateRequest;
use Pixonaut\WooCommerce\Vendor\Pixonaut\Sdk\Pixonaut as Sdk;
use Pixonaut\WooCommerce\Vendor\Pixonaut\Sdk\Webhooks\Event;
use Pixonaut\WooCommerce\Vendor\Psr\Log\LoggerInterface;
use Pixonaut\WooCommerce\Webhooks\Payload;
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

	/**
	 * Replacements of a failed order followed to the one that stands, for
	 * an order retried again while the answers to its retries were lost.
	 */
	private const MAX_REPLACEMENT_HOPS = 3;

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
	 * @return array<string, mixed>|null
	 *
	 * @throws ApiException When Pixonaut could not be reached or refused.
	 */
	public function order_of( LineState $line ): ?array {
		$order_id = self::order_id( $line );
		if ( null === $order_id || ! $this->connected() ) {
			return null;
		}
		return $this->pixonaut->call( 'orders.get', array( 'id' => $order_id ) )->object();
	}

	/**
	 * The line and its printshop order as Pixonaut has them now: the order
	 * is read, the line brought in step with it, and the order read again
	 * when the line took on the order that replaced it.
	 *
	 * @param LineState $line The line.
	 *
	 * @return array{0: LineState, 1: array<string, mixed>|null} The line, and its order from order_of().
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
	 * from. A status other than the one last applied is applied now, and a
	 * failed order that was retried hands the line to the order that
	 * replaced it.
	 *
	 * @param LineState            $line  The line.
	 * @param array<string, mixed> $order Its printshop order, from order_of().
	 *
	 * @return LineState The line as it stands now.
	 *
	 * @throws ApiException When Pixonaut could not be reached or refused while the replacement was looked for.
	 */
	public function reconcile( LineState $line, array $order ): LineState {
		$order_id = is_string( $order['id'] ?? null ) ? $order['id'] : '';
		$status   = is_string( $order['status'] ?? null ) ? $order['status'] : '';
		if ( '' === $status || self::order_id( $line ) !== $order_id ) {
			return $line;
		}
		$line = $this->keep_erasure( $line, $order );
		$item = $line->item();
		try {
			if ( self::applied( $line ) !== $status && $this->apply_status( $line, $order_id, $status, $order ) ) {
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
				OrderHold::keep( $item, $order_id, $order['hold'] ?? null );
			}
			$line = LineState::of( $item );
			if ( OrderStatus::FAILED === $status && true !== ( $order['canRetry'] ?? false ) && LineState::NEEDS_ATTENTION === $line->state() ) {
				$replacement = $this->replacement( $order );
				if ( null !== $replacement ) {
					$this->adopt( $line, $replacement );
				}
			}
		} catch ( ApiException $e ) {
			// A replacement that could not be read is the caller's to try again.
			throw $e;
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
	 * @param LineState            $line  The line.
	 * @param array<string, mixed> $order Its printshop order, from order_of().
	 *
	 * @return LineState The line as it stands now.
	 */
	public function keep_erasure( LineState $line, array $order ): LineState {
		$erased = $order['personalDataErasedAt'] ?? null;
		if ( ( ! is_int( $erased ) && ! is_float( $erased ) ) || ( $order['id'] ?? null ) !== self::order_id( $line )
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
	 * @param LineState                 $line  The line.
	 * @param array<string, mixed>|null $order Its printshop order from order_of(); null when it has none or it could not be read.
	 *
	 * @return list<string> Action ids, e.g. `retry` or `rehearse_shipped`.
	 */
	public function available( LineState $line, ?array $order ): array {
		$actions = array();
		if ( ! in_array( $line->state(), LineState::FINAL, true ) && $this->connected() ) {
			$actions = null === self::order_id( $line ) ? $this->store_actions( $line ) : $this->order_actions( $line, $order ?? array() );
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
		$filtered = (array) apply_filters( 'pixonaut_wc_fulfilment_line_actions', $actions, $line, $order );
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
			if ( self::RETRY === $action ) {
				return null === $order ? $this->retry_line( $line ) : $this->retry_order( $line, $order_id );
			}
			if ( self::REORDER === $action ) {
				return $this->reorder( $line );
			}
			if ( self::SETTLE_SUBMITTED === $action || self::SETTLE_FAILED === $action ) {
				if ( '' === trim( $reason ) ) {
					return new LineActionResult( self::REASON_MISSING );
				}
				return self::SETTLE_SUBMITTED === $action
					? $this->settle_submitted( $line, $order_id, $reason, $ref )
					: $this->settle_failed( $line, $order_id, $reason );
			}
			if ( self::CANCEL === $action ) {
				return $this->cancel( $line, $order_id );
			}
			return $this->rehearse( $line, $order_id, (string) ( $order['status'] ?? '' ), substr( $action, strlen( self::REHEARSE ) ) );
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
			return 'orders.retry' === $e->operation ? $this->retry_refused( $line, $e ) : self::refused( $e );
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
	 * @param LineState            $line  The line.
	 * @param array<string, mixed> $order Its printshop order; empty when it could not be read.
	 *
	 * @return list<string>
	 */
	private function order_actions( LineState $line, array $order ): array {
		$status  = is_string( $order['status'] ?? null ) ? $order['status'] : '';
		$moves   = array_values( array_filter( is_array( $order['statusMoves'] ?? null ) ? $order['statusMoves'] : array(), 'is_string' ) );
		$actions = array();
		if ( LineState::NEEDS_ATTENTION === $line->state() ) {
			if ( true === ( $order['canRetry'] ?? false ) ) {
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
	 * Pixonaut's retry of the line's failed printshop order. When an
	 * earlier retry placed one already, or an automation ordered the design
	 * again meanwhile, the line adopts that order.
	 *
	 * @param LineState $line     The line.
	 * @param string    $order_id Its failed order.
	 *
	 * @throws ApiException When Pixonaut refused or could not be reached.
	 */
	private function retry_order( LineState $line, string $order_id ): LineActionResult {
		$automation = null;
		try {
			// No reroute: the order goes back to the printshop it went to.
			$placed = $this->pixonaut->orders()->retry( $order_id, new OrdersRetryRequest() )->raw();
		} catch ( ApiException $e ) {
			$automation = AdoptedOrder::named_by( $e );
			$newer      = ErrorCode::ORDER_SUPERSEDED === $e->errorCode ? ( $e->details['newerOrderId'] ?? null ) : $automation;
			if ( ! is_string( $newer ) || '' === $newer ) {
				throw $e;
			}
			$placed = $this->pixonaut->call( 'orders.get', array( 'id' => $newer ) )->object();
			if ( null !== $automation && ! AdoptedOrder::takes( $line, $placed ) ) {
				// Only an automation's order of the item as bought is taken on; the merchant settles any other.
				throw $e;
			}
		}
		return $this->adopt( $line, $placed, null !== $automation );
	}

	/**
	 * Makes a new printshop order the line's. A status on the way to the
	 * customer is applied as an `order.status_changed` event would be, so a
	 * replacement that shipped already ships the line, with its tracking,
	 * and one Pixonaut holds shows as held, with its hold.
	 *
	 * @param LineState            $line       The line.
	 * @param array<string, mixed> $placed     The new order.
	 * @param bool                 $automation Whether one of the workspace's automations placed it, not the store's retry.
	 *
	 * @throws RuntimeException When the line kept changing while the status was applied.
	 */
	private function adopt( LineState $line, array $placed, bool $automation = false ): LineActionResult {
		$id     = is_string( $placed['id'] ?? null ) ? $placed['id'] : '';
		$status = is_string( $placed['status'] ?? null ) ? $placed['status'] : '';
		if ( '' === $id ) {
			return new LineActionResult( self::REFUSED, ApiException::UNEXPECTED_RESPONSE );
		}
		$line->replace_order( $id );
		$address = null;
		if ( $automation ) {
			AdoptedOrder::record( $line, $placed );
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
			$this->status_changed->apply_to( $line->item(), $this->event( $id, '', $placed ) );
			if ( OrderStatus::HELD === $status ) {
				OrderHold::keep( $line->item(), $id, $placed['hold'] ?? null );
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
	 * @param LineState $line     The line.
	 * @param string    $order_id Its order, whose outcome is unknown.
	 * @param string    $reason   What the printshop said.
	 * @param string    $ref      The printshop's own reference; empty for none.
	 *
	 * @throws ApiException When Pixonaut refused or could not be reached.
	 * @throws RuntimeException When the line kept changing.
	 */
	private function settle_submitted( LineState $line, string $order_id, string $reason, string $ref ): LineActionResult {
		$body = array(
			'status' => OrderStatus::SUBMITTED,
			'reason' => $reason,
		);
		if ( '' !== $ref ) {
			$body['externalOrderRef'] = $ref;
		}
		$settled = $this->pixonaut->orders()->update( $order_id, OrdersUpdateRequest::fromArray( $body ) )->raw();
		$this->note(
			$line,
			/* translators: 1: product name of the order line, 2: what the merchant learned from the printshop. */
			__( 'Pixonaut: the printshop has the order for "%1$s". %2$s', 'pixonaut-for-woocommerce' ),
			$reason
		);
		$this->status_changed->apply_to( $line->item(), $this->event( $order_id, OrderStatus::OUTCOME_UNKNOWN, $settled ) );
		return new LineActionResult( self::DONE );
	}

	/**
	 * The printshop does not have the order: Pixonaut marks it failed, and it
	 * is placed again at once.
	 *
	 * @param LineState $line     The line.
	 * @param string    $order_id Its order, whose outcome is unknown.
	 * @param string    $reason   What the printshop said.
	 *
	 * @throws ApiException When Pixonaut refused or could not be reached; a refused retry leaves the order failed, to retry.
	 */
	private function settle_failed( LineState $line, string $order_id, string $reason ): LineActionResult {
		$this->pixonaut->orders()->update( $order_id, new OrdersUpdateRequest( status: OrderStatus::FAILED, reason: $reason ) );
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
		return $this->retry_order( $line, $order_id );
	}

	/**
	 * Asks the printshop to stop the order; the line is cancelled once it did.
	 *
	 * @param LineState $line     The line.
	 * @param string    $order_id Its order.
	 *
	 * @throws ApiException When the printshop could not stop it, or Pixonaut refused or could not be reached.
	 */
	private function cancel( LineState $line, string $order_id ): LineActionResult {
		$cancelled = $this->pixonaut->orders()->cancel( $order_id )->raw();
		$line->set_ref( LineState::REF_ORDER_STATUS, is_string( $cancelled['status'] ?? null ) ? $cancelled['status'] : OrderStatus::CANCELLED );
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
	 * Moves a sandbox order on as its printshop would, and the line with it.
	 *
	 * @param LineState $line     The line.
	 * @param string    $order_id Its sandbox order.
	 * @param string    $from     The order's status now.
	 * @param string    $to       One of REHEARSALS.
	 *
	 * @throws ApiException When Pixonaut refused or could not be reached.
	 * @throws RuntimeException When the line kept changing.
	 */
	private function rehearse( LineState $line, string $order_id, string $from, string $to ): LineActionResult {
		$moved = $this->pixonaut->orders()->update( $order_id, new OrdersUpdateRequest( status: $to ) )->raw();
		if ( OrderStatus::FAILED === $to ) {
			/* translators: Reason in an order note when a merchant rehearses a failed printshop order in the Pixonaut sandbox. */
			$this->failed->apply_to( $line, __( 'Rehearsed in the Pixonaut sandbox.', 'pixonaut-for-woocommerce' ) );
		} else {
			$this->status_changed->apply_to( $line->item(), $this->event( $order_id, $from, $moved ) );
		}
		return new LineActionResult( self::DONE );
	}

	/**
	 * The `order.status_changed` event Pixonaut sends for a move the store
	 * made, built from its answer.
	 *
	 * @param string               $order_id The order.
	 * @param string               $previous Its status before the move.
	 * @param array<string, mixed> $moved    Pixonaut's answer: the order as it is now.
	 */
	private function event( string $order_id, string $previous, array $moved ): Event {
		return Payload::event(
			'store_' . wp_generate_uuid4(),
			OrderStatusChanged::TYPE,
			Milliseconds::of( time() ),
			array(
				'orderId'        => $order_id,
				'externalRef'    => $moved['externalRef'] ?? null,
				'previousStatus' => $previous,
				'status'         => $moved['status'] ?? null,
				'tracking'       => $moved['tracking'] ?? null,
				'via'            => 'store_key',
			)
		);
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
			ErrorCode::ORDER_NOT_RETRYABLE => new LineActionResult( self::STATUS_NOT_ALLOWED ),
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
	 * @param LineState            $line     The line.
	 * @param string               $order_id Its order.
	 * @param string               $status   The order's status, other than the one last applied.
	 * @param array<string, mixed> $order    The order as Pixonaut answered it.
	 *
	 * @return bool False for a status this version does not know, which is left alone.
	 *
	 * @throws RuntimeException When the line kept changing.
	 */
	private function apply_status( LineState $line, string $order_id, string $status, array $order ): bool {
		$item = $line->item();
		if ( isset( OrderStatus::LINE_STATES[ $status ] ) || OrderStatus::OUTCOME_UNKNOWN === $status ) {
			// Starting from the status last applied, so the move is not taken for a late one.
			$this->status_changed->apply_to( $item, $this->event( $order_id, (string) self::applied( $line ), $order ) );
			return true;
		}
		if ( OrderStatus::FAILED === $status ) {
			$this->failed->apply_to( $line, is_string( $order['lastError'] ?? null ) ? $order['lastError'] : '' );
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
	 * The order that stands in for a failed one Pixonaut retried: the one
	 * its `replacedByOrderId` names, or the one replacing that, and so on.
	 * A replacement placed under another `externalRef` is not the line's,
	 * as for its `order.*` events, so it is not followed.
	 *
	 * @param array<string, mixed> $order The failed order, as Pixonaut answered it.
	 *
	 * @return array<string, mixed>|null Null when nothing replaced it.
	 *
	 * @throws ApiException When Pixonaut could not be reached or refused.
	 */
	private function replacement( array $order ): ?array {
		$external_ref = is_string( $order['externalRef'] ?? null ) ? $order['externalRef'] : '';
		if ( '' === $external_ref ) {
			return null;
		}
		$found = null;
		$at    = $order;
		for ( $hop = 0; $hop < self::MAX_REPLACEMENT_HOPS; $hop++ ) {
			$next_id = $at['replacedByOrderId'] ?? null;
			if ( ! is_string( $next_id ) || '' === $next_id ) {
				break;
			}
			$at = $this->pixonaut->call( 'orders.get', array( 'id' => $next_id ) )->object();
			if ( ( $at['externalRef'] ?? null ) !== $external_ref ) {
				break;
			}
			$found = $at;
		}
		return $found;
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
