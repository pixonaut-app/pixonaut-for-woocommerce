<?php
/**
 * Reads the shop's filter data again in the background.
 *
 * @package Pixonaut\WooCommerce
 */

declare(strict_types=1);

namespace Pixonaut\WooCommerce\Catalogue;

use Pixonaut\WooCommerce\Schema;
use Pixonaut\WooCommerce\Storefront\FilterMatches;

/**
 * An archive's filters read what is kept of each listed product
 * (`FilterIndex`) and the Product Filters block's counts
 * (`FilterMatches::counts()`). When either is out of date, because a
 * product changed or it is old, a shopper is served it as it was and it is
 * noted here; a job, queued ahead of the picture jobs, reads each noted
 * product and count again, so no shopper waits for it.
 *
 * The notes share one option. Each change reads it afresh and writes it at
 * once; a note the option lost, as when two requests wrote it at the same
 * moment, is noted again by the next request served the old data.
 */
final class FilterDataRefresh {

	/**
	 * The job reading the noted products and counts again.
	 */
	public const HOOK = 'pixonaut_wc_refresh_filter_data';

	public const OPTION = Schema::FILTER_DATA_DUE_OPTION;

	/**
	 * Before the queue's default 10: Action Scheduler runs a shop's jobs
	 * oldest first, and a backlog of picture jobs, which save products as
	 * they go, would hold the job back while the data it reads keeps
	 * changing.
	 */
	public const PRIORITY = 5;

	/**
	 * How long after the first note the job runs: a product saved with its
	 * variations, or several saved in a row, are read once.
	 */
	private const DELAY_SECONDS = 60;

	/**
	 * How long one run reads for, so a large shop's data spreads over
	 * several runs rather than one long request.
	 */
	private const RUN_SECONDS = 30;

	/**
	 * How many products are read at a time.
	 */
	private const BATCH = 50;

	/**
	 * How many counts may wait at once: each filter combination a visitor,
	 * or a crawler, opens has its own.
	 */
	private const MAX_COUNTS = 200;

	/**
	 * What the notes hold of each kind.
	 */
	private const PRODUCTS = 'products';

	private const COUNTS = 'counts';

	/**
	 * Whether the job is running here: it queues its next run once, at its end.
	 *
	 * @var bool
	 */
	private static bool $running = false;

	/**
	 * What this request noted already, so it writes the option once for each.
	 *
	 * @var array<string, array<int|string, true>>
	 */
	private static array $noted = array();

	public static function register(): void {
		add_action( self::HOOK, array( self::class, 'run' ) );
	}

	/**
	 * Notes products whose kept filter reads are to be read again.
	 *
	 * @param int[] $product_ids Products.
	 */
	public static function products( array $product_ids ): void {
		self::note( self::PRODUCTS, $product_ids );
	}

	/**
	 * Notes a count to work out again.
	 *
	 * @param string $transient The transient it is kept in.
	 */
	public static function counts( string $transient ): void {
		self::note( self::COUNTS, array( $transient ) );
	}

	/**
	 * The job: reads the noted products again, then the noted counts, for
	 * up to `RUN_SECONDS`, and runs again while some are left. A product
	 * changed moments ago waits for the next run.
	 */
	public static function run(): void {
		self::$running = true;
		$started       = microtime( true );
		$done          = array(
			self::PRODUCTS => array(),
			self::COUNTS   => array(),
		);
		try {
			$due = self::due();
			foreach ( array_chunk( array_map( 'intval', array_keys( $due[ self::PRODUCTS ] ) ), self::BATCH ) as $batch ) {
				if ( microtime( true ) - $started >= self::RUN_SECONDS ) {
					break;
				}
				$done[ self::PRODUCTS ] = array_merge( $done[ self::PRODUCTS ], FilterIndex::rebuild( $batch ) );
			}
			foreach ( array_keys( $due[ self::COUNTS ] ) as $transient ) {
				if ( microtime( true ) - $started >= self::RUN_SECONDS ) {
					break;
				}
				FilterMatches::recount( (string) $transient );
				$done[ self::COUNTS ][] = (string) $transient;
			}
		} finally {
			$left          = self::change(
				static function ( array $due ) use ( $done ): array {
					foreach ( $done as $kind => $keys ) {
						$due[ $kind ] = array_diff_key( $due[ $kind ], array_flip( $keys ) );
					}
					return $due;
				}
			);
			self::$running = false;
			self::$noted   = array();
			if ( array() !== $left[ self::PRODUCTS ] || array() !== $left[ self::COUNTS ] ) {
				self::schedule();
			}
		}
	}

	/**
	 * Notes keys of one kind, and queues the job for them.
	 *
	 * @param string            $kind `PRODUCTS` or `COUNTS`.
	 * @param array<int|string> $keys Product ids or transients.
	 */
	private static function note( string $kind, array $keys ): void {
		$new = array_diff_key( array_fill_keys( $keys, true ), self::$noted[ $kind ] ?? array() );
		if ( array() === $new ) {
			return;
		}
		self::$noted[ $kind ] = ( self::$noted[ $kind ] ?? array() ) + $new;
		if ( array() === array_diff_key( $new, self::due()[ $kind ] ) ) {
			return;
		}
		self::change(
			static function ( array $due ) use ( $kind, $new ): array {
				$due[ $kind ] += $new;
				if ( self::COUNTS === $kind ) {
					$due[ $kind ] = array_slice( $due[ $kind ], 0, self::MAX_COUNTS, true );
				}
				return $due;
			}
		);
		self::schedule();
	}

	/**
	 * Queues the job unless one is queued, or it is running and queues its next run itself.
	 */
	private static function schedule(): void {
		if ( self::$running || ! function_exists( 'as_schedule_single_action' ) || ! did_action( 'action_scheduler_init' ) || WaitingJob::exists( self::HOOK, array(), Schema::ACTION_GROUP ) ) {
			return;
		}
		as_schedule_single_action( time() + self::DELAY_SECONDS, self::HOOK, array(), Schema::ACTION_GROUP, false, self::PRIORITY );
	}

	/**
	 * Changes the notes as they are stored now, not as this request first
	 * read them, and stores them at once.
	 *
	 * @param \Closure $change `fn(array $due): array`.
	 * @return array{products: array<int|string, true>, counts: array<int|string, true>}
	 */
	private static function change( \Closure $change ): array {
		/** @var array{products: array<int|string, true>, counts: array<int|string, true>} $due */
		$due = $change( self::due() );
		update_option( self::OPTION, $due, false );
		return $due;
	}

	/**
	 * The notes as stored now.
	 *
	 * @return array{products: array<int|string, true>, counts: array<int|string, true>}
	 */
	private static function due(): array {
		wp_cache_delete( self::OPTION, 'options' );
		$stored = get_option( self::OPTION, array() );
		$due    = array(
			self::PRODUCTS => array(),
			self::COUNTS   => array(),
		);
		foreach ( array_keys( $due ) as $kind ) {
			$keys = is_array( $stored ) && is_array( $stored[ $kind ] ?? null ) ? $stored[ $kind ] : array();
			foreach ( array_keys( $keys ) as $key ) {
				$due[ $kind ][ $key ] = true;
			}
		}
		return $due;
	}
}
