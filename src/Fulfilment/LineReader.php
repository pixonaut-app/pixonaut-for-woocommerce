<?php
/**
 * Reads every open line afresh from Pixonaut.
 *
 * @package Pixonaut\WooCommerce
 */

declare(strict_types=1);

namespace Pixonaut\WooCommerce\Fulfilment;

use Pixonaut\WooCommerce\Milliseconds;
use Pixonaut\WooCommerce\Schema;
use Pixonaut\WooCommerce\Vendor\Pixonaut\Sdk\Exception\ApiException;
use Pixonaut\WooCommerce\Vendor\Pixonaut\Sdk\Generated\ErrorCode;
use Pixonaut\WooCommerce\Vendor\Pixonaut\Sdk\Pixonaut as Sdk;
use Pixonaut\WooCommerce\Vendor\Psr\Log\LoggerInterface;
use RuntimeException;
use Throwable;
use WC_Order_Factory;
use WC_Order_Item_Product;

/**
 * When the event stream's first cursor is set, every line that is not final
 * is read again, one Action Scheduler job per line: its printshop order, when
 * it has one, and the design of each of its sides. What the reads answer is
 * applied by the rules the matching events follow, so a line catches up with
 * whatever happened while the store was not connected.
 *
 * The reads are queued a page of lines at a time by a job that queues the
 * next page after its own, so a large backlog is never queued in one request.
 * A read that fails is tried again later, up to MAX_ATTEMPTS times in all.
 */
final class LineReader {

	/**
	 * Action Scheduler hook of a line's read, with the order item id and the
	 * attempt as its arguments.
	 */
	public const ACTION = 'pixonaut_wc_line_read';

	/**
	 * Action Scheduler hook of the job queueing a page of reads, with the
	 * order item id the page starts after as its argument.
	 */
	public const QUEUE_ACTION = 'pixonaut_wc_line_read_queue';

	public const GROUP = Schema::ACTION_GROUP;

	/**
	 * Attempts at reading a line, the first one included, before it is given up on.
	 */
	public const MAX_ATTEMPTS = 5;

	/**
	 * Wait before the second attempt; it doubles with every attempt after.
	 */
	public const RETRY_BASE_SECONDS = 60;

	/**
	 * Lines one queueing job queues a read for.
	 */
	public const QUEUE_BATCH = 100;

	/**
	 * @param Sdk                $pixonaut     Reads a line's printshop order and designs.
	 * @param OrderStatusChanged $order_status Applies an order's status.
	 * @param DesignChanged      $design       Applies a design's revision, deletion and erasure.
	 * @param LoggerInterface    $logger       Logs a read that failed and a job that could not be queued.
	 */
	public function __construct(
		private readonly Sdk $pixonaut,
		private readonly OrderStatusChanged $order_status,
		private readonly DesignChanged $design,
		private readonly LoggerInterface $logger,
	) {
	}

	public function register(): void {
		add_action( 'pixonaut_wc_events_baseline_set', array( $this, 'start_queue' ), 10, 0 );
		add_action( self::QUEUE_ACTION, array( $this, 'queue_open_lines' ) );
		add_action( self::ACTION, array( $this, 'read' ), 10, 2 );
	}

	/**
	 * Queues the job that queues a read of every line that may not be final.
	 */
	public function start_queue(): void {
		$this->queue_page_after( 0 );
	}

	/**
	 * The queueing job: queues a read of each of a page of lines that may not
	 * be final, then the job for the page after when this one was full.
	 *
	 * @param int|string $after_item_id The order item the page starts after; 0 for the first.
	 */
	public function queue_open_lines( int|string $after_item_id = 0 ): void {
		$ids = LineState::open_item_ids( (int) $after_item_id, self::QUEUE_BATCH );
		foreach ( $ids as $item_id ) {
			if ( ! self::queue( $item_id ) ) {
				$this->logger->error( 'The read of an order line could not be queued.', array( 'item' => $item_id ) );
			}
		}
		if ( self::QUEUE_BATCH === count( $ids ) ) {
			$this->queue_page_after( $ids[ self::QUEUE_BATCH - 1 ] );
		}
	}

	/**
	 * Queues a read of one line, unless one is queued already.
	 *
	 * @param int $item_id The order item.
	 *
	 * @return bool False when it could not be queued.
	 */
	public static function queue( int $item_id ): bool {
		$args = self::args( $item_id, 1 );
		return as_has_scheduled_action( self::ACTION, $args, self::GROUP ) || 0 !== as_enqueue_async_action( self::ACTION, $args, self::GROUP, true );
	}

	/**
	 * The job: reads the line's order and designs and applies what changed.
	 * When a read fails, the line is read again later while it has attempts
	 * left, and the exception is rethrown so Action Scheduler records the
	 * failure.
	 *
	 * @param int|string $item_id The order item.
	 * @param int|string $attempt Which attempt this is, from 1.
	 * @throws Throwable What the read threw: ApiException when Pixonaut could not be reached, RuntimeException when the line kept changing while a change was applied.
	 */
	public function read( int|string $item_id, int|string $attempt = 1 ): void {
		$item_id = (int) $item_id;
		$attempt = max( 1, (int) $attempt );
		try {
			$this->read_line( $item_id, $attempt );
		} catch ( Throwable $e ) {
			$this->logger->error(
				'Order line not read: ' . $e->getMessage(),
				array(
					'item'    => $item_id,
					'attempt' => $attempt,
				)
			);
			if ( $attempt < self::MAX_ATTEMPTS ) {
				$delay = self::RETRY_BASE_SECONDS * 2 ** ( $attempt - 1 );
				if ( 0 === as_schedule_single_action( time() + $delay, self::ACTION, self::args( $item_id, $attempt + 1 ), self::GROUP ) ) {
					$this->logger->error( 'The next read of an order line could not be queued.', array( 'item' => $item_id ) );
				}
			}
			throw $e;
		}
	}

	/**
	 * @param int $item_id The order item.
	 * @param int $attempt Which attempt this is. A first one skips a final
	 *                     line; a later one finishes its design reads, since
	 *                     the attempt before may have moved the line to final
	 *                     itself before they failed.
	 * @throws ApiException When Pixonaut could not be reached.
	 * @throws RuntimeException When the line kept changing.
	 */
	private function read_line( int $item_id, int $attempt ): void {
		$item = WC_Order_Factory::get_order_item( $item_id );
		if ( ! $item instanceof WC_Order_Item_Product || ! LineState::is_tracked( $item ) ) {
			return;
		}
		$line = LineState::of( $item );
		if ( ( 1 === $attempt && in_array( $line->state(), LineState::FINAL, true ) ) || ! $this->design->belongs_here( $line ) ) {
			return;
		}
		$order_id = $line->ref( LineState::REF_ORDER_ID );
		if ( is_string( $order_id ) && '' !== $order_id ) {
			$order = $this->found( fn (): array => $this->pixonaut->orders()->get( $order_id )->raw() );
			if ( null !== $order ) {
				$this->order_status->apply_read( $line, $order );
			}
		}
		foreach ( LineState::of( $item )->sides() as $side ) {
			$this->read_design( $item, $side );
		}
	}

	/**
	 * Queues the queueing job for the page of lines after $after_item_id.
	 *
	 * @param int $after_item_id The order item the page starts after.
	 */
	private function queue_page_after( int $after_item_id ): void {
		if ( 0 === as_enqueue_async_action( self::QUEUE_ACTION, array( 'after_item_id' => $after_item_id ), self::GROUP, true ) ) {
			$this->logger->error( 'The reads of open order lines could not be queued.', array( 'after_item' => $after_item_id ) );
		}
	}

	/**
	 * @param int $item_id The order item.
	 * @param int $attempt Which attempt.
	 * @return array{item_id: int, attempt: int} A read's job arguments.
	 */
	private static function args( int $item_id, int $attempt ): array {
		return array(
			'item_id' => $item_id,
			'attempt' => $attempt,
		);
	}

	/**
	 * @param WC_Order_Item_Product $item The line.
	 * @param string                $side A side key.
	 * @throws ApiException When Pixonaut could not be reached.
	 * @throws RuntimeException When the line kept changing.
	 */
	private function read_design( WC_Order_Item_Product $item, string $side ): void {
		$line      = LineState::of( $item );
		$design_id = $line->side_ref( $side, LineState::SIDE_DESIGN_ID );
		if ( ! is_string( $design_id ) || '' === $design_id ) {
			return;
		}
		$design = $this->found( fn (): array => $this->pixonaut->designs()->get( $design_id )->raw() );
		if ( null === $design ) {
			// Gone from Pixonaut; when is not known, so now stands for it.
			$this->design->deleted( array( $line ), $design_id, Milliseconds::of( time() ) );
			return;
		}
		$this->design->apply_read( array( $line ), $design_id, $design );
	}

	/**
	 * @param callable $read A read of one record.
	 * @phpstan-param callable(): array<string, mixed> $read
	 * @return array<string, mixed>|null Null when Pixonaut answered that it has no such record.
	 * @throws ApiException When Pixonaut refused otherwise or could not be reached.
	 */
	private function found( callable $read ): ?array {
		try {
			return $read();
		} catch ( ApiException $e ) {
			if ( in_array( $e->errorCode, array( ErrorCode::NOT_FOUND, ErrorCode::ORDER_NOT_FOUND ), true ) ) {
				return null;
			}
			throw $e;
		}
	}
}
