<?php
/**
 * Starts fulfilment when an order is paid.
 *
 * @package Pixonaut\WooCommerce
 */

declare(strict_types=1);

namespace Pixonaut\WooCommerce\Fulfilment;

use ActionScheduler_Store;
use Closure;
use Pixonaut\WooCommerce\Api\ApiError;
use Pixonaut\WooCommerce\Api\ErrorCodes;
use Pixonaut\WooCommerce\Api\Logger;
use Pixonaut\WooCommerce\Api\Operations;
use Pixonaut\WooCommerce\Connection\StoredConnection;
use RuntimeException;
use WC_Order;
use WC_Order_Factory;
use WC_Order_Item_Product;

/**
 * Schedules one Action Scheduler job per Pixonaut line of a paid order, and
 * runs it. Payment completing and the order moving into any paid status
 * (`wc_get_is_paid_statuses()`: an On hold order marked Completed, or one
 * created as Completed, never passes Processing) both trigger it; a line with
 * a job already pending or running, or one past the printshop order, is left
 * alone, so a second trigger does nothing. FulfilmentSweep schedules a line
 * whose job was lost.
 *
 * A job whose step fails is not left failed: the RetryPolicy either schedules
 * the line's next job on its backoff, or moves the line to `needs_attention`.
 */
final class FulfilmentJobs {

	/**
	 * Action Scheduler hook of the job, with the order item id as its one argument.
	 */
	public const ACTION = 'pixonaut_fulfil_item';

	public const GROUP = 'pixonaut-for-woocommerce';

	/**
	 * Status of an answer that says Pixonaut does not know the id asked for.
	 */
	private const NOT_FOUND_STATUS = 404;

	/**
	 * Calls on one of the line's designs, which answer `404 not_found` for a deleted one.
	 */
	private const DESIGN_OPERATIONS = array( Operations::GET_DESIGN, Operations::SET_DESIGN_EXTERNAL_REF, Operations::APPROVE_DESIGN );

	/**
	 * @param Fulfiller $fulfiller  Takes a line on.
	 * @param Closure   $connection `fn(): ?StoredConnection`, the store's connection; null while there is none.
	 * @param Logger    $logger     Logs the failures retried and the jobs Action Scheduler refused.
	 */
	public function __construct(
		private readonly Fulfiller $fulfiller,
		private readonly Closure $connection,
		private readonly Logger $logger,
	) {
	}

	public function register(): void {
		add_action( 'woocommerce_payment_complete', array( $this, 'schedule_order' ) );
		add_action( 'woocommerce_order_status_changed', array( $this, 'schedule_paid' ), 10, 3 );
		add_action( self::ACTION, array( $this, 'run' ) );
	}

	/**
	 * Schedules the order's lines when it moved into a paid status, also from
	 * another one: Processing to Completed picks up a line whose job was lost.
	 *
	 * @param int|string $order_id The order.
	 * @param string     $from     The status it left; any.
	 * @param string     $to       The status it moved to.
	 */
	public function schedule_paid( int|string $order_id, string $from, string $to ): void {
		if ( in_array( $to, wc_get_is_paid_statuses(), true ) ) {
			$this->schedule_order( $order_id );
		}
	}

	/**
	 * Schedules a job for each of the order's lines that can go on.
	 *
	 * @param int|string $order_id The order.
	 */
	public function schedule_order( int|string $order_id ): void {
		$order = wc_get_order( (int) $order_id );
		if ( ! $order instanceof WC_Order ) {
			return;
		}
		foreach ( $order->get_items() as $item ) {
			if ( $item instanceof WC_Order_Item_Product && LineState::is_tracked( $item ) && in_array( LineState::of( $item )->state(), LineState::FULFILLABLE, true ) ) {
				if ( ! self::schedule( $item->get_id() ) ) {
					$this->logger->error( 'Fulfilment job could not be scheduled.', array( 'item' => $item->get_id() ) );
				}
			}
		}
	}

	/**
	 * Schedules the line's job unless it has one pending or running.
	 *
	 * @param int $item_id The order item.
	 * @return bool False when Action Scheduler would not store the job.
	 */
	public static function schedule( int $item_id ): bool {
		if ( self::is_scheduled( $item_id ) ) {
			return true;
		}
		// Not `unique`: Action Scheduler would match any job of the group, so one line's job would hold back every other line's.
		return 0 !== as_enqueue_async_action( self::ACTION, array( 'item_id' => $item_id ), self::GROUP );
	}

	/**
	 * Whether the line's job is pending or running.
	 *
	 * @param int $item_id The order item.
	 */
	public static function is_scheduled( int $item_id ): bool {
		return false !== as_has_scheduled_action( self::ACTION, array( 'item_id' => $item_id ), self::GROUP );
	}

	/**
	 * Schedules the line's job again unless one is still pending. A running
	 * job does not count: it may have read the line before what made it go
	 * again was saved.
	 *
	 * @param int $item_id The order item.
	 * @return bool False when Action Scheduler refused the job.
	 */
	public function schedule_again( int $item_id ): bool {
		$args    = array( 'item_id' => $item_id );
		$pending = as_get_scheduled_actions(
			array(
				'hook'     => self::ACTION,
				'args'     => $args,
				'group'    => self::GROUP,
				'status'   => ActionScheduler_Store::STATUS_PENDING,
				'per_page' => 1,
			),
			'ids'
		);
		if ( array() !== $pending ) {
			return true;
		}
		return 0 !== as_enqueue_async_action( self::ACTION, $args, self::GROUP, false );
	}

	/**
	 * The job: takes the line as far as it can go. A job that runs while the
	 * store is not connected, or its connection is paused, fails rather than
	 * completes, so FulfilmentSweep schedules the line again once it is: a
	 * completed job says the line was left where it is on purpose.
	 *
	 * @param int|string $item_id The order item.
	 *
	 * @throws RuntimeException When the store is not connected; Action Scheduler records the job as failed.
	 */
	public function run( int|string $item_id ): void {
		$item = WC_Order_Factory::get_order_item( (int) $item_id );
		if ( ! $item instanceof WC_Order_Item_Product || ! LineState::is_tracked( $item ) ) {
			return;
		}
		if ( ! ( $this->connection )() instanceof StoredConnection && in_array( LineState::of( $item )->state(), LineState::FULFILLABLE, true ) ) {
			throw new RuntimeException( 'The store is not connected to Pixonaut, or its connection is paused; the line is scheduled again once it is.' );
		}
		try {
			$this->fulfiller->fulfil( $item );
		} catch ( ApiError $e ) {
			$this->failed( $item, $e );
		}
	}

	/**
	 * Retries the line later, or moves it to `needs_attention`.
	 *
	 * @param WC_Order_Item_Product $item  The line.
	 * @param ApiError              $error Why its step failed.
	 */
	private function failed( WC_Order_Item_Product $item, ApiError $error ): void {
		$line  = LineState::of( $item );
		$order = wc_get_order( $item->get_order_id() );
		if ( ! $order instanceof WC_Order || ! in_array( $line->state(), LineState::FULFILLABLE, true ) ) {
			return;
		}
		// A sandbox whose data was reset answers the same; its own note says so.
		if ( self::design_gone( $error ) && ! $this->in_sandbox() ) {
			NeedsAttention::flag(
				$line,
				$order,
				NeedsAttention::DESIGN_DELETED,
				sprintf(
					/* translators: %s: product name */
					__( 'Pixonaut: the design for "%s" was deleted in Pixonaut, so the item cannot be printed. Check the order with the customer.', 'pixonaut-for-woocommerce' ),
					$item->get_name()
				)
			);
			return;
		}
		$failures = self::failures( $line );
		if ( RetryPolicy::RETRY === RetryPolicy::decide( $error ) ) {
			$delay = RetryPolicy::delay( $failures, $error->retry_after );
			if ( null !== $delay ) {
				$this->retry( $line, $failures + 1, $delay, $error );
				return;
			}
			NeedsAttention::flag(
				$line,
				$order,
				NeedsAttention::RETRIES_EXHAUSTED,
				sprintf(
					/* translators: 1: product name of the order line, 2: number of attempts, 3: the error code Pixonaut answered. */
					__( 'Pixonaut: "%1$s" was not sent to the printshop after %2$d attempts. Pixonaut last answered "%3$s". Check the order in Pixonaut, then retry.', 'pixonaut-for-woocommerce' ),
					$item->get_name(),
					$failures + 1,
					$error->error_code
				)
			);
			return;
		}
		NeedsAttention::flag( $line, $order, $error->error_code, $this->refusal_note( $item, $error ) );
	}

	/**
	 * Whether the step failed because Pixonaut no longer knows one of the
	 * line's designs: it was deleted after checkout, before the line was
	 * approved, which the `design.deleted` event may not have said yet.
	 *
	 * @param ApiError $error Why the step failed.
	 */
	private static function design_gone( ApiError $error ): bool {
		return ErrorCodes::NOT_FOUND === $error->error_code && in_array( $error->operation, self::DESIGN_OPERATIONS, true );
	}

	/**
	 * Whether the store is connected to the Pixonaut sandbox.
	 */
	private function in_sandbox(): bool {
		$connection = ( $this->connection )();
		return $connection instanceof StoredConnection && StoredConnection::SANDBOX === $connection->environment;
	}

	/**
	 * Stores the failure count and schedules the line's next job.
	 *
	 * @param LineState $line     The line.
	 * @param int       $failures Failures in a row, this one counted.
	 * @param int       $delay    Seconds to wait.
	 * @param ApiError  $error    Why the step failed.
	 */
	private function retry( LineState $line, int $failures, int $delay, ApiError $error ): void {
		$line->set_ref(
			LineState::REF_RETRY,
			array(
				'state'    => $line->state(),
				'failures' => $failures,
			)
		);
		if ( ! $line->save() ) {
			// Another run changed the line; it carries on from there.
			return;
		}
		$item = $line->item();
		// The running job counts as scheduled, so the next one is not checked against it.
		$job = as_schedule_single_action( time() + $delay, self::ACTION, array( 'item_id' => $item->get_id() ), self::GROUP );
		if ( 0 === $job ) {
			$this->retry_not_scheduled( $line, $error );
			return;
		}
		$this->logger->warning(
			'Fulfilment step failed; retrying later.',
			array(
				'item'      => $item->get_id(),
				'operation' => $error->operation,
				'status'    => $error->status,
				'code'      => $error->error_code,
				'failures'  => $failures,
				'delay'     => $delay,
			)
		);
	}

	/**
	 * Moves a line whose next job Action Scheduler would not store to
	 * `needs_attention`, rather than leave it waiting for a job that never runs.
	 *
	 * @param LineState $line  The line.
	 * @param ApiError  $error Why its step failed.
	 */
	private function retry_not_scheduled( LineState $line, ApiError $error ): void {
		$item = $line->item();
		$this->logger->error(
			'Fulfilment retry could not be scheduled.',
			array(
				'item'      => $item->get_id(),
				'operation' => $error->operation,
				'status'    => $error->status,
				'code'      => $error->error_code,
			)
		);
		$order = wc_get_order( $item->get_order_id() );
		if ( ! $order instanceof WC_Order ) {
			return;
		}
		NeedsAttention::flag(
			$line,
			$order,
			NeedsAttention::RETRY_NOT_SCHEDULED,
			sprintf(
				/* translators: 1: product name of the order line, 2: the error code Pixonaut answered. */
				__( 'Pixonaut: "%1$s" was not sent to the printshop. Pixonaut answered "%2$s", and the store could not schedule another attempt. Retry the item.', 'pixonaut-for-woocommerce' ),
				$item->get_name(),
				$error->error_code
			)
		);
	}

	/**
	 * Transient failures in a row while the line held its current state.
	 *
	 * @param LineState $line The line.
	 */
	private static function failures( LineState $line ): int {
		$retry = $line->ref( LineState::REF_RETRY );
		if ( ! is_array( $retry ) || $line->state() !== ( $retry['state'] ?? null ) || ! is_int( $retry['failures'] ?? null ) ) {
			return 0;
		}
		return max( 0, $retry['failures'] );
	}

	/**
	 * The order note for a refusal the store cannot cure by retrying.
	 *
	 * @param WC_Order_Item_Product $item  The line.
	 * @param ApiError              $error The refusal.
	 */
	private function refusal_note( WC_Order_Item_Product $item, ApiError $error ): string {
		if ( self::NOT_FOUND_STATUS === $error->status && $this->in_sandbox() ) {
			return sprintf(
				/* translators: 1: product name of the order line, 2: the error code Pixonaut answered. */
				__( 'Pixonaut: "%1$s" needs attention. The Pixonaut sandbox no longer knows its design, print or order ("%2$s"); this happens when the sandbox\'s data was reset. Place the item again.', 'pixonaut-for-woocommerce' ),
				$item->get_name(),
				$error->error_code
			);
		}
		$ordered_as = $error->details['orderId'] ?? null;
		if ( ErrorCodes::DESIGN_ALREADY_ORDERED === $error->error_code && is_string( $ordered_as ) && '' !== $ordered_as ) {
			return sprintf(
				/* translators: 1: product name of the order line, 2: the error code Pixonaut answered, 3: the Pixonaut order the design already has, 4: what the merchant can do. */
				__( 'Pixonaut: "%1$s" needs attention. Pixonaut refused it with "%2$s": its design already has the Pixonaut order %3$s. %4$s', 'pixonaut-for-woocommerce' ),
				$item->get_name(),
				$error->error_code,
				$ordered_as,
				NeedsAttention::explain( $error->error_code )
			);
		}
		return sprintf(
			/* translators: 1: product name of the order line, 2: the error code Pixonaut answered, 3: what the merchant can do. */
			__( 'Pixonaut: "%1$s" needs attention. Pixonaut refused it with "%2$s". %3$s', 'pixonaut-for-woocommerce' ),
			$item->get_name(),
			$error->error_code,
			NeedsAttention::explain( $error->error_code )
		);
	}
}
