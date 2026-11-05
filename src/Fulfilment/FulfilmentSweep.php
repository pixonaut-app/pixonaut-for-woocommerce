<?php
/**
 * Schedules again the lines of paid orders whose fulfilment job was lost.
 *
 * @package Pixonaut\WooCommerce
 */

declare(strict_types=1);

namespace Pixonaut\WooCommerce\Fulfilment;

use ActionScheduler;
use ActionScheduler_Store;
use Closure;
use Pixonaut\WooCommerce\Api\Logger;
use Pixonaut\WooCommerce\Connection\StoredConnection;
use WC_Order;
use WC_Order_Item_Product;

/**
 * A daily job that schedules each line of a paid order still in a state
 * fulfilment takes on (`LineState::FULFILLABLE`) that has no job pending or
 * running and whose last job did not run to its end: it failed (a PHP fatal,
 * a timeout, the plugin inactive when it ran) or there is none (the order
 * was paid in a way no trigger saw, or the job was deleted). A line whose
 * last job completed was left where it is on purpose, held back by
 * `pixonaut_wc_fulfilment_can_fulfil` or made under another workspace, and
 * is not scheduled again; Retry on the order screen sends it.
 *
 * Only orders created within WINDOW_SECONDS are looked at, newest first,
 * a page of BATCH orders per job.
 */
final class FulfilmentSweep {

	/**
	 * Action Scheduler hook of the daily job.
	 */
	public const HOOK = 'pixonaut_wc_fulfilment_sweep';

	/**
	 * How far back the sweep looks: 30 days, how long Action Scheduler keeps
	 * a finished job by default. Past it a line held back on purpose no
	 * longer shows a completed job, so it would be scheduled again every day.
	 */
	public const WINDOW_SECONDS = 30 * self::INTERVAL_SECONDS;

	/**
	 * Action Scheduler hook of one page of the sweep, with its state, the
	 * order id it reads below and the window's start as arguments.
	 */
	public const PAGE_HOOK = 'pixonaut_wc_fulfilment_sweep_page';

	/**
	 * Orders read per page, each page its own job, so a large store's sweep
	 * never runs into one request's time limit.
	 */
	public const BATCH = 100;

	/**
	 * How often the sweep runs: a day.
	 */
	private const INTERVAL_SECONDS = 86400;

	private readonly Closure $clock;

	/**
	 * @param Closure      $schedule   `fn(int $item_id): bool`, schedules the line's job unless it has one; false when Action Scheduler refused it.
	 * @param Closure      $connection `fn(): ?StoredConnection`, the connection to act for; null while there is none or it is paused.
	 * @param Logger       $logger     Logs the jobs Action Scheduler refused.
	 * @param Closure|null $clock      `fn(): int` Unix time; `time()` when null.
	 * @param int          $batch      Orders per page.
	 */
	public function __construct(
		private readonly Closure $schedule,
		private readonly Closure $connection,
		private readonly Logger $logger,
		?Closure $clock = null,
		private readonly int $batch = self::BATCH,
	) {
		$this->clock = $clock ?? static fn (): int => time();
	}

	public function register(): void {
		add_action( self::HOOK, array( $this, 'sweep' ), 10, 0 );
		add_action( self::PAGE_HOOK, array( $this, 'sweep_page' ), 10, 3 );
		add_action( 'action_scheduler_init', array( $this, 'schedule_sweep' ), 10, 0 );
	}

	public function schedule_sweep(): void {
		if ( ! as_has_scheduled_action( self::HOOK, null, FulfilmentJobs::GROUP ) ) {
			as_schedule_recurring_action( ( $this->clock )() + self::INTERVAL_SECONDS, self::INTERVAL_SECONDS, self::HOOK, array(), FulfilmentJobs::GROUP );
		}
	}

	/**
	 * Starts the day's sweep at its first page, unless the last one still
	 * runs.
	 */
	public function sweep(): void {
		if ( as_has_scheduled_action( self::PAGE_HOOK, null, FulfilmentJobs::GROUP ) ) {
			return;
		}
		$this->queue_page( LineState::FULFILLABLE[0], 0, ( $this->clock )() - self::WINDOW_SECONDS );
	}

	/**
	 * Schedules the stranded lines of one page of orders holding a line in
	 * $state. The next page is queued before this one is read through, so a
	 * page that dies does not end the sweep.
	 *
	 * @param string $state  One of LineState::FULFILLABLE.
	 * @param int    $before Only orders with a lower id; 0 for the newest.
	 * @param int    $since  Unix time: orders created before it are left alone.
	 */
	public function sweep_page( string $state, int $before, int $since ): void {
		$position = array_search( $state, LineState::FULFILLABLE, true );
		if ( false === $position || ! ( $this->connection )() instanceof StoredConnection ) {
			// Not connected: a job now would fail at once, so the sweep waits for the next day.
			return;
		}
		$order_ids = LineIndex::order_ids( $state, $before, $this->batch );
		$next      = LineState::FULFILLABLE[ $position + 1 ] ?? null;
		if ( count( $order_ids ) === $this->batch ) {
			$this->queue_page( $state, (int) end( $order_ids ), $since );
		} elseif ( null !== $next ) {
			$this->queue_page( $next, 0, $since );
		}
		foreach ( $order_ids as $order_id ) {
			$order   = wc_get_order( $order_id );
			$created = $order instanceof WC_Order ? $order->get_date_created() : null;
			// Order ids do not follow creation dates (a backdated or imported order), so an old one is skipped, not a stop.
			if ( $order instanceof WC_Order && $order->is_paid() && ( null === $created || $created->getTimestamp() >= $since ) ) {
				$this->sweep_order( $order );
			}
		}
	}

	/**
	 * Queues a page of the sweep.
	 *
	 * @param string $state  One of LineState::FULFILLABLE.
	 * @param int    $before Only orders with a lower id; 0 for the newest.
	 * @param int    $since  Unix time: orders created before it are left alone.
	 */
	private function queue_page( string $state, int $before, int $since ): void {
		if ( 0 === as_enqueue_async_action( self::PAGE_HOOK, array( $state, $before, $since ), FulfilmentJobs::GROUP ) ) {
			$this->logger->error(
				'Fulfilment sweep page could not be scheduled.',
				array(
					'state'  => $state,
					'before' => $before,
				)
			);
		}
	}

	/**
	 * Whether the line's last job completed. A line with no job at all, or
	 * whose last one failed or was cancelled, lost it.
	 *
	 * @param int $item_id The order item.
	 */
	public static function last_job_completed( int $item_id ): bool {
		$ids = as_get_scheduled_actions(
			array(
				'hook'     => FulfilmentJobs::ACTION,
				'args'     => array( 'item_id' => $item_id ),
				'group'    => FulfilmentJobs::GROUP,
				// The newest job, by id: scheduled dates have whole seconds and need not follow the order jobs ran in.
				'orderby'  => 'action_id',
				'order'    => 'DESC',
				'per_page' => 1,
			),
			'ids'
		);
		if ( array() === $ids ) {
			return false;
		}
		return ActionScheduler_Store::STATUS_COMPLETE === ActionScheduler::store()->get_status( (string) $ids[0] );
	}

	/**
	 * Schedules the order's stranded lines.
	 *
	 * @param WC_Order $order A paid order.
	 */
	private function sweep_order( WC_Order $order ): void {
		foreach ( $order->get_items() as $item ) {
			if ( ! $item instanceof WC_Order_Item_Product || ! LineState::is_tracked( $item )
				|| ! in_array( LineState::of( $item )->state(), LineState::FULFILLABLE, true )
				|| FulfilmentJobs::is_scheduled( $item->get_id() ) || self::last_job_completed( $item->get_id() ) ) {
				continue;
			}
			if ( true !== ( $this->schedule )( $item->get_id() ) ) {
				$this->logger->error( 'Stranded fulfilment job could not be scheduled.', array( 'item' => $item->get_id() ) );
				continue;
			}
			$this->logger->warning( 'Fulfilment job scheduled again for a line that had none.', array( 'item' => $item->get_id() ) );
		}
	}
}
