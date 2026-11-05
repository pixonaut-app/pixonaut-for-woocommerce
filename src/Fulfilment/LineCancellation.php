<?php
/**
 * Cancels a line when its order is cancelled or refunded.
 *
 * @package Pixonaut\WooCommerce
 */

declare(strict_types=1);

namespace Pixonaut\WooCommerce\Fulfilment;

use Closure;
use Pixonaut\WooCommerce\Api\ApiError;
use Pixonaut\WooCommerce\Api\Designs;
use Pixonaut\WooCommerce\Api\ErrorCodes;
use Pixonaut\WooCommerce\Api\Logger;
use Pixonaut\WooCommerce\Api\Orders;
use Pixonaut\WooCommerce\Connection\StoredConnection;
use RuntimeException;
use WC_Order;
use WC_Order_Factory;
use WC_Order_Item_Product;

/**
 * Cancels a line in Pixonaut and the store, as `CancelTriggers` (or another
 * caller) asks. How a line is cancelled depends on how far it got:
 *
 * - Not yet ordered: its fulfilment job is stopped, a design still waiting
 *   for approval is rejected (an approved or printed one needs no call), and
 *   the line is `cancelled`.
 * - Order sent, its answer lost: the order is looked up under the line's
 *   ref and, once found, cancelled as below.
 * - Ordered: Pixonaut asks the printshop to stop the order, and the line is
 *   `cancelled` once it did. A printshop that has the order already leaves
 *   the line as it is, with a note saying why; one that could not be asked
 *   yet is asked again on the fulfilment backoff. An order that had failed is
 *   cancelled in the store only.
 *
 * The request is stored on the line before its job is scheduled, so the
 * fulfilment job leaves the line alone from then on. Refunding money is the
 * store's: cancelling never moves any.
 */
final class LineCancellation {

	/**
	 * Action Scheduler hook of the job, with the order item id as its one argument.
	 */
	public const ACTION = 'pixonaut_cancel_item';

	// What asked for a line to be cancelled.
	public const ORDER_CANCELLED = 'order_cancelled';
	public const ORDER_REFUNDED  = 'order_refunded';
	public const LINE_REFUNDED   = 'line_refunded';
	// Its proof was denied: not approved by the deadline, or rejected by the customer.
	public const PROOF_EXPIRED  = 'proof_expired';
	public const PROOF_REJECTED = 'proof_rejected';

	/**
	 * Line states whose designs still wait for approval in Pixonaut.
	 */
	private const AWAITING_APPROVAL = array( LineState::COMMITTED, LineState::AWAITING_CUSTOMER );

	/**
	 * Tries to store the request when another request wrote the line meanwhile.
	 */
	private const MAX_ATTEMPTS = 3;

	/**
	 * Why the job is tried again when the line changed while it ran, for the log.
	 */
	private const LINE_CHANGED = 'line_changed';

	/**
	 * Why the job is tried again when a printshop order the line sent may
	 * still be on its way, for the log.
	 */
	private const ORDER_UNRESOLVED = 'order_unresolved';

	/**
	 * @param Designs $designs    Rejects designs.
	 * @param Orders  $orders     Cancels printshop orders.
	 * @param Closure $connection `fn(): ?StoredConnection`, the connection to act for; null while there is none or it is paused.
	 * @param Logger  $logger     Logs the cancellations Pixonaut refused or the store could not schedule.
	 */
	public function __construct(
		private readonly Designs $designs,
		private readonly Orders $orders,
		private readonly Closure $connection,
		private readonly Logger $logger,
	) {
	}

	public function register(): void {
		add_action( self::ACTION, array( $this, 'run' ) );
	}

	/**
	 * Asks for the line to be cancelled: stores the request, stops its
	 * fulfilment job, and schedules the cancellation's own job. A final line
	 * is left as it is; for a line being cancelled already, only a job that
	 * is missing, because the store could not schedule it, is scheduled.
	 *
	 * @param LineState $line    The line.
	 * @param string    $trigger What asked for it: ORDER_CANCELLED, ORDER_REFUNDED, LINE_REFUNDED, PROOF_EXPIRED, PROOF_REJECTED, or a caller's own.
	 * @param bool      $start   False to only resume a cancellation asked for before.
	 *
	 * @return bool Whether the line's cancellation is scheduled.
	 */
	public function request( LineState $line, string $trigger, bool $start = true ): bool {
		$item = $line->item();
		if ( in_array( $line->state(), LineState::FINAL, true ) ) {
			return false;
		}
		if ( null !== $line->ref( LineState::REF_CANCEL ) ) {
			return $this->schedule( $item );
		}
		if ( ! $start ) {
			return false;
		}

		/**
		 * Filters whether a Pixonaut line is cancelled, in Pixonaut and at the
		 * printshop, because its order was cancelled or refunded, or its proof
		 * was denied. Return false
		 * to keep the line going, e.g. for a goodwill refund of an item the
		 * customer still gets.
		 *
		 * @since 0.1.0
		 *
		 * @param bool                  $cancel  Whether to cancel the line.
		 * @param WC_Order_Item_Product $item    The order line.
		 * @param string                $trigger What asked for it: `order_cancelled`, `order_refunded`, `line_refunded`, `proof_expired` (not approved by the deadline) or `proof_rejected` (rejected by the customer).
		 * @param LineState             $line    The line's state and refs.
		 *
		 * @example
		 * add_filter( 'pixonaut_wc_fulfilment_cancel_line', function ( $cancel, $item, $trigger ) {
		 *     return 'line_refunded' === $trigger ? false : $cancel;
		 * }, 10, 3 );
		 */
		if ( true !== apply_filters( 'pixonaut_wc_fulfilment_cancel_line', true, $item, $trigger, $line ) ) {
			return false;
		}
		if ( ! $this->store_request( $item, $trigger ) ) {
			$this->logger->error( 'Line cancellation not stored: the line kept changing.', array( 'item' => $item->get_id() ) );
			return false;
		}
		as_unschedule_all_actions( FulfilmentJobs::ACTION, array( 'item_id' => $item->get_id() ), FulfilmentJobs::GROUP );
		return $this->schedule( $item );
	}

	/**
	 * Schedules the line's cancellation job unless one is pending or running.
	 *
	 * @param WC_Order_Item_Product $item The line.
	 *
	 * @return bool False when Action Scheduler would not store the job.
	 */
	private function schedule( WC_Order_Item_Product $item ): bool {
		$args = array( 'item_id' => $item->get_id() );
		if ( false !== as_has_scheduled_action( self::ACTION, $args, FulfilmentJobs::GROUP ) ) {
			return true;
		}
		if ( 0 !== as_enqueue_async_action( self::ACTION, $args, FulfilmentJobs::GROUP ) ) {
			return true;
		}
		$this->logger->error( 'Line cancellation could not be scheduled.', array( 'item' => $item->get_id() ) );
		$this->note(
			$item,
			/* translators: %s: product name of the order line. */
			__( 'Pixonaut: "%s" is not cancelled yet: the store could not schedule it. It is not sent to the printshop meanwhile. Update the order to try again.', 'pixonaut-for-woocommerce' )
		);
		return false;
	}

	/**
	 * The job: cancels the line as far as it got.
	 *
	 * @param int|string $item_id The order item.
	 */
	public function run( int|string $item_id ): void {
		$item = WC_Order_Factory::get_order_item( (int) $item_id );
		if ( ! $item instanceof WC_Order_Item_Product || ! LineState::is_tracked( $item ) ) {
			return;
		}
		$line = LineState::of( $item );
		if ( ! is_array( $line->ref( LineState::REF_CANCEL ) ) || in_array( $line->state(), LineState::FINAL, true ) ) {
			return;
		}
		$order_id = $line->ref( LineState::REF_ORDER_ID );
		try {
			if ( is_string( $order_id ) && '' !== $order_id ) {
				$this->cancel_order_at_printshop( $line, $order_id );
			} elseif ( null !== $line->ref( LineState::REF_ORDER_REQUEST ) ) {
				$this->cancel_unresolved_order( $line );
			} else {
				$this->cancel_before_order( $line );
			}
		} catch ( RuntimeException $e ) {
			// The line changed while it was cancelled, e.g. a fulfilment run placed its order: start over from where it stands.
			if ( ! $this->again( $line, self::LINE_CHANGED, null ) ) {
				$this->logger->error( 'Line cancellation given up: ' . $e->getMessage(), array( 'item' => $item->get_id() ) );
			}
		}
	}

	/**
	 * @param WC_Order_Item_Product $item    The line.
	 * @param string                $trigger What asked for it.
	 *
	 * @return bool False when the line kept changing.
	 */
	private function store_request( WC_Order_Item_Product $item, string $trigger ): bool {
		for ( $attempt = 0; $attempt < self::MAX_ATTEMPTS; $attempt++ ) {
			$line = LineState::of( $item );
			$line->set_ref(
				LineState::REF_CANCEL,
				array(
					'trigger'  => $trigger,
					'failures' => 0,
				)
			);
			if ( $line->save() ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * A line with no printshop order: rejects the designs still waiting for
	 * approval, then cancels the line. A design Pixonaut no longer counts
	 * as waiting (`not_committed`) needs nothing more.
	 *
	 * @param LineState $line The line.
	 *
	 * @throws RuntimeException When the line changed meanwhile.
	 */
	private function cancel_before_order( LineState $line ): void {
		$stage = LineState::NEEDS_ATTENTION === $line->state() ? $line->resume_from() : $line->state();
		if ( in_array( $stage, self::AWAITING_APPROVAL, true ) && $this->acts_for( $line ) ) {
			$refusals = array();
			foreach ( $line->sides() as $side ) {
				$design_id = $line->side_ref( $side, LineState::SIDE_DESIGN_ID );
				if ( ! is_string( $design_id ) || '' === $design_id || true === $line->side_ref( $side, LineState::SIDE_APPROVED ) ) {
					continue;
				}
				try {
					$this->reject( $design_id );
				} catch ( ApiError $e ) {
					// Each side is rejected on its own: one refusal does not keep another side's design open.
					$refusals[] = $e;
				}
			}
			foreach ( $refusals as $e ) {
				// A rejection already made answers success again, so the retry sends every side once more.
				if ( $this->retries( $e ) && $this->again( $line, $e->error_code, $e->retry_after ) ) {
					return;
				}
			}
			$line = LineState::of( $line->item() );
			foreach ( $refusals as $e ) {
				$this->log_refusal( $line, $e );
				$this->note(
					$line->item(),
					/* translators: 1: product name of the order line, 2: the error code Pixonaut answered. */
					__( 'Pixonaut: a design of "%1$s" could not be rejected in Pixonaut, which answered "%2$s". It stays unapproved there, so it is not printed.', 'pixonaut-for-woocommerce' ),
					$e->error_code
				);
			}
		}
		$this->cancelled(
			$line,
			null,
			/* translators: %s: product name of the order line. */
			__( 'Pixonaut: "%s" was cancelled before it was sent to the printshop.', 'pixonaut-for-woocommerce' )
		);
	}

	/**
	 * A line that sent its printshop order without learning its id: the
	 * answer was lost, or the order is still on its way. The order is looked
	 * up under the line's ref and cancelled when found. When none is found,
	 * the line is looked at once more later, so an order still on its way is
	 * not missed; after that the request is taken to have placed nothing.
	 *
	 * @param LineState $line The line.
	 *
	 * @throws RuntimeException When the line changed meanwhile.
	 */
	private function cancel_unresolved_order( LineState $line ): void {
		if ( ! $this->acts_for( $line ) ) {
			$this->give_up(
				$line,
				/* translators: %s: product name of the order line. */
				__( 'Pixonaut: "%s" may have a printshop order, which was not cancelled: the store is not connected to the Pixonaut workspace it was ordered under. Check the order in Pixonaut.', 'pixonaut-for-woocommerce' )
			);
			return;
		}
		try {
			$placed = $this->orders->with_external_ref( (string) $line->ref( LineState::REF_EXTERNAL_REF ) );
		} catch ( ApiError $e ) {
			$this->refused( $line, $e );
			return;
		}
		$order_id = is_string( $placed[0]['id'] ?? null ) ? $placed[0]['id'] : '';
		if ( '' !== $order_id ) {
			$line->set_ref( LineState::REF_ORDER_ID, $order_id );
			// The request holds the customer's name and address; its outcome is known now.
			$line->set_ref( LineState::REF_ORDER_REQUEST, null );
			if ( ! $line->save() ) {
				throw new RuntimeException( sprintf( 'Order item %d did not take on its printshop order: the line changed meanwhile.', (int) $line->item()->get_id() ) );
			}
			$this->cancel_order_at_printshop( $line, $order_id );
			return;
		}
		if ( 0 === self::failures( $line ) && $this->again( $line, self::ORDER_UNRESOLVED, null ) ) {
			return;
		}
		$line = LineState::of( $line->item() );
		$line->set_ref( LineState::REF_ORDER_REQUEST, null );
		$this->cancel_before_order( $line );
	}

	/**
	 * Times the line's cancellation was tried again.
	 *
	 * @param LineState $line The line.
	 */
	private static function failures( LineState $line ): int {
		$cancel = $line->ref( LineState::REF_CANCEL );
		return is_array( $cancel ) && is_int( $cancel['failures'] ?? null ) ? $cancel['failures'] : 0;
	}

	/**
	 * @param string $design_id A design waiting for approval.
	 *
	 * @throws ApiError When Pixonaut refused, other than for a design no longer waiting, or could not be reached.
	 */
	private function reject( string $design_id ): void {
		try {
			$this->designs->reject( $design_id );
		} catch ( ApiError $e ) {
			if ( ErrorCodes::NOT_COMMITTED !== $e->error_code ) {
				throw $e;
			}
		}
	}

	/**
	 * A line with a printshop order: asks the printshop to stop it.
	 *
	 * @param LineState $line     The line.
	 * @param string    $order_id Its printshop order.
	 *
	 * @throws RuntimeException When the line changed meanwhile.
	 */
	private function cancel_order_at_printshop( LineState $line, string $order_id ): void {
		if ( ! $this->acts_for( $line ) ) {
			$this->give_up(
				$line,
				/* translators: %s: product name of the order line. */
				__( 'Pixonaut: the printshop order for "%s" was not cancelled: the store is not connected to the Pixonaut workspace it was ordered under. Cancel it in Pixonaut.', 'pixonaut-for-woocommerce' )
			);
			return;
		}
		try {
			$answer = $this->orders->cancel( $order_id );
		} catch ( ApiError $e ) {
			$this->refused( $line, $e );
			return;
		}
		$this->cancelled(
			$line,
			is_string( $answer['status'] ?? null ) ? $answer['status'] : OrderStatus::CANCELLED,
			/* translators: %s: product name of the order line. */
			__( 'Pixonaut: the printshop order for "%s" was cancelled.', 'pixonaut-for-woocommerce' )
		);
	}

	/**
	 * What a refused cancel comes to.
	 *
	 * @param LineState $line  The line.
	 * @param ApiError  $error The refusal.
	 *
	 * @throws RuntimeException When the line changed meanwhile.
	 */
	private function refused( LineState $line, ApiError $error ): void {
		if ( ErrorCodes::ORDER_AT_PRINTSHOP === $error->error_code ) {
			$this->give_up(
				$line,
				/* translators: 1: product name of the order line, 2: why the printshop keeps the order. */
				__( 'Pixonaut: the printshop order for "%1$s" was not cancelled: %2$s Ask the printshop.', 'pixonaut-for-woocommerce' ),
				self::explain_cause( is_string( $error->details['cause'] ?? null ) ? $error->details['cause'] : '' )
			);
			return;
		}
		if ( ErrorCodes::ORDER_STATUS_NOT_ALLOWED === $error->error_code && OrderStatus::FAILED === ( $error->details['from'] ?? null ) ) {
			$this->cancelled(
				$line,
				OrderStatus::FAILED,
				/* translators: %s: product name of the order line. */
				__( 'Pixonaut: "%s" was cancelled. Its printshop order had failed, so there was nothing to stop at the printshop.', 'pixonaut-for-woocommerce' )
			);
			return;
		}
		if ( $this->retries( $error ) && $this->again( $line, $error->error_code, $error->retry_after ) ) {
			return;
		}
		$line = LineState::of( $line->item() );
		$this->log_refusal( $line, $error );
		$reason = is_string( $error->details['reason'] ?? null ) ? sanitize_text_field( $error->details['reason'] ) : '';
		$this->give_up(
			$line,
			/* translators: 1: product name of the order line, 2: the error code Pixonaut answered, 3: Pixonaut's reason, if it gave one, with a leading space. */
			__( 'Pixonaut: the printshop order for "%1$s" could not be cancelled: Pixonaut answered "%2$s".%3$s Ask the printshop to stop it.', 'pixonaut-for-woocommerce' ),
			$error->error_code,
			'' === $reason ? '' : ' ' . $reason
		);
	}

	/**
	 * Whether a refusal may be cured by asking again: an order whose
	 * submission is still unanswered, a printshop Pixonaut could not reach
	 * yet, or a failure the fulfilment policy retries.
	 *
	 * @param ApiError $error The refusal.
	 */
	private function retries( ApiError $error ): bool {
		return ErrorCodes::ORDER_IN_FLIGHT === $error->error_code || RetryPolicy::RETRY === RetryPolicy::decide( $error );
	}

	/**
	 * Schedules the line's job again on the fulfilment backoff.
	 *
	 * @param LineState $line        The line.
	 * @param string    $code        Why it is tried again.
	 * @param int|null  $retry_after Seconds Pixonaut asked to wait, when it did.
	 *
	 * @return bool False when the backoff is used up or the job could not be scheduled; the caller gives up.
	 */
	private function again( LineState $line, string $code, ?int $retry_after ): bool {
		$item     = $line->item();
		$line     = LineState::of( $item );
		$cancel   = (array) $line->ref( LineState::REF_CANCEL );
		$failures = self::failures( $line );
		$delay    = RetryPolicy::delay( $failures, $retry_after );
		if ( null === $delay ) {
			return false;
		}
		$cancel['failures'] = $failures + 1;
		$line->set_ref( LineState::REF_CANCEL, $cancel );
		// A line changed meanwhile keeps its own count; the job is scheduled all the same.
		$line->save();
		if ( 0 === as_schedule_single_action( time() + $delay, self::ACTION, array( 'item_id' => $item->get_id() ), FulfilmentJobs::GROUP ) ) {
			$this->logger->error( 'Line cancellation retry could not be scheduled.', array( 'item' => $item->get_id() ) );
			return false;
		}
		$this->logger->warning(
			'Line cancellation failed; retrying later.',
			array(
				'item'     => $item->get_id(),
				'code'     => $code,
				'failures' => $failures + 1,
				'delay'    => $delay,
			)
		);
		return true;
	}

	/**
	 * Cancels the line in the store and notes it.
	 *
	 * @param LineState   $line   The line.
	 * @param string|null $status The printshop order's status to record; null when it has none.
	 * @param string      $note   A translated note with `%s` for the product name.
	 *
	 * @throws RuntimeException When the line changed meanwhile.
	 */
	private function cancelled( LineState $line, ?string $status, string $note ): void {
		$line->set_ref( LineState::REF_CANCEL, null );
		$line->set_ref( LineState::REF_ORDER_REQUEST, null );
		if ( null !== $status ) {
			$line->set_ref( LineState::REF_ORDER_STATUS, $status );
		}
		if ( ! $line->transition( LineState::CANCELLED ) ) {
			if ( LineState::CANCELLED === LineState::of( $line->item() )->state() ) {
				// Pixonaut's `order.cancelled` got there first.
				return;
			}
			throw new RuntimeException( sprintf( 'Order item %d not cancelled: the line changed meanwhile.', (int) $line->item()->get_id() ) );
		}
		$this->note( $line->item(), $note );
	}

	/**
	 * Leaves the line as it is, drops the request and notes why.
	 *
	 * @param LineState $line    The line.
	 * @param string    $note    A translated note with `%1$s` (or `%s`) for the product name.
	 * @param string    ...$args The note's further values.
	 *
	 * @throws RuntimeException When the line changed meanwhile.
	 */
	private function give_up( LineState $line, string $note, string ...$args ): void {
		$line->set_ref( LineState::REF_CANCEL, null );
		if ( ! $line->save() ) {
			throw new RuntimeException( sprintf( 'Cancellation of order item %d not settled: the line changed meanwhile.', (int) $line->item()->get_id() ) );
		}
		$this->note( $line->item(), $note, ...$args );
	}

	/**
	 * Whether the store may act on the line in Pixonaut: it is connected, to
	 * the workspace the line was made under.
	 *
	 * @param LineState $line The line.
	 */
	private function acts_for( LineState $line ): bool {
		$connection = ( $this->connection )();
		return $connection instanceof StoredConnection && $line->ref( LineState::REF_TENANT_ID ) === $connection->tenant_id;
	}

	/**
	 * Why a printshop keeps an order, in words a merchant can act on.
	 *
	 * @param string $cause The refusal's `cause`.
	 */
	private static function explain_cause( string $cause ): string {
		return match ( $cause ) {
			/* translators: Why the printshop did not cancel an order, filling in %2$s of an order note. Ends a sentence; lower case. */
			'made'          => __( 'the printshop has made it already.', 'pixonaut-for-woocommerce' ),
			/* translators: Why the printshop did not cancel an order, filling in %2$s of an order note. Ends a sentence; lower case. */
			'begun'         => __( 'the printshop has begun it and did not cancel it.', 'pixonaut-for-woocommerce' ),
			/* translators: Why the printshop did not cancel an order, filling in %2$s of an order note. Ends a sentence; lower case. */
			'cannot_cancel' => __( 'its printshop cannot stop orders through Pixonaut.', 'pixonaut-for-woocommerce' ),
			/* translators: Why the printshop did not cancel an order, filling in %2$s of an order note. Ends a sentence; lower case. */
			'not_connected' => __( 'its printshop is no longer connected to Pixonaut.', 'pixonaut-for-woocommerce' ),
			/* translators: Why the printshop did not cancel an order, filling in %2$s of an order note. Ends a sentence; lower case. */
			'status_moved'  => __( 'the printshop cancelled it, but it shipped meanwhile.', 'pixonaut-for-woocommerce' ),
			/* translators: Why the printshop did not cancel an order, filling in %2$s of an order note. Ends a sentence; lower case. */
			default         => __( 'the printshop has it.', 'pixonaut-for-woocommerce' ),
		};
	}

	/**
	 * @param LineState $line  The line.
	 * @param ApiError  $error What Pixonaut answered.
	 */
	private function log_refusal( LineState $line, ApiError $error ): void {
		$this->logger->warning(
			'Line cancellation refused by Pixonaut.',
			array(
				'item'      => $line->item()->get_id(),
				'operation' => $error->operation,
				'status'    => $error->status,
				'code'      => $error->error_code,
			)
		);
	}

	/**
	 * @param WC_Order_Item_Product $item    The line.
	 * @param string                $message A translated message with `%1$s` (or `%s`) for the product name.
	 * @param string                ...$args The message's further values.
	 */
	private function note( WC_Order_Item_Product $item, string $message, string ...$args ): void {
		$order = wc_get_order( $item->get_order_id() );
		if ( $order instanceof WC_Order ) {
			$order->add_order_note( sprintf( $message, $item->get_name(), ...$args ) );
		}
	}
}
