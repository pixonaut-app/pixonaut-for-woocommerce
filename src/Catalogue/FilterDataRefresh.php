<?php
/**
 * Reads the shop's filter data again in the background.
 *
 * @package Pixonaut\WooCommerce
 */

declare(strict_types=1);

namespace Pixonaut\WooCommerce\Catalogue;

use Pixonaut\WooCommerce\Schema;
use Pixonaut\WooCommerce\Storefront\AttributeDependencies;
use Pixonaut\WooCommerce\Storefront\FilterMatches;

/**
 * An archive's filters read what is kept of each listed product
 * (`FilterIndex`), which attribute applies under which
 * (`AttributeDependencies`) and the Product Filters block's counts
 * (`FilterMatches::counts()`). When any is out of date, because a
 * product changed or it is old, a shopper is served it as it was and it is
 * noted here; a job, queued ahead of the picture jobs, reads each noted
 * product, the dependencies and each noted count again, in that order, so
 * no shopper waits for it.
 *
 * The notes share one option, each with a token. A change, such as a save,
 * gives its note a new token, and the job takes off only the notes whose
 * token is still the one it read: a product saved while the job reads it
 * is read again on the next run. Each change reads the option afresh and
 * writes it at once; a note the option lost, as when two requests wrote it
 * at the same moment, is noted again by the next request served the old
 * data.
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

	private const DEPENDENCIES = 'dependencies';

	/**
	 * The one key the dependencies are noted under: they are the store's.
	 */
	private const STORE = 'store';

	/**
	 * Whether the job is running here: it queues its next run once, at its end.
	 *
	 * @var bool
	 */
	private static bool $running = false;

	/**
	 * What this request noted already, so it writes the option once for
	 * each; null before its first note.
	 *
	 * @var array<string, array<int|string, true>>|null
	 */
	private static ?array $noted = null;

	public static function register(): void {
		add_action( self::HOOK, array( self::class, 'run' ) );
		// Another site has notes of its own.
		add_action( 'switch_blog', array( self::class, 'site_switched' ) );
	}

	/**
	 * Forgets what this request noted, after it switched to another site.
	 */
	public static function site_switched(): void {
		self::$noted = null;
	}

	/**
	 * Notes products whose kept filter reads are to be read again.
	 *
	 * @param int[] $product_ids Products.
	 * @param bool  $changed     Whether they changed now, rather than were found out of date: a change is read again even when the job is reading them already.
	 */
	public static function products( array $product_ids, bool $changed = false ): void {
		self::note( self::PRODUCTS, $product_ids, $changed );
		// What the store's attributes depend on may have changed with them: noted once a request, however many products it saves.
		if ( $changed && ! isset( self::$noted[ self::DEPENDENCIES ][ self::STORE ] ) ) {
			self::note( self::DEPENDENCIES, array( self::STORE ), true );
		}
	}

	/**
	 * Notes the store's attribute dependencies to work out again.
	 */
	public static function dependencies(): void {
		self::note( self::DEPENDENCIES, array( self::STORE ), false );
	}

	/**
	 * Notes a count to work out again.
	 *
	 * @param string $transient The transient it is kept in.
	 * @param bool   $changed   Whether it was just worked out from what is out of date.
	 */
	public static function counts( string $transient, bool $changed = false ): void {
		self::note( self::COUNTS, array( $transient ), $changed );
	}

	/**
	 * The job: reads the noted products again, then the dependencies,
	 * which the products' reads tell, then the noted counts, which the
	 * dependencies tell, for up to `RUN_SECONDS`, and runs again while some
	 * are left. A product changed moments ago waits for the next run.
	 */
	public static function run(): void {
		self::$running = true;
		$started       = microtime( true );
		$done          = array(
			self::PRODUCTS     => array(),
			self::DEPENDENCIES => array(),
			self::COUNTS       => array(),
		);
		$due           = self::due();
		try {
			foreach ( array_chunk( array_map( 'intval', array_keys( $due[ self::PRODUCTS ] ) ), self::BATCH ) as $batch ) {
				if ( microtime( true ) - $started >= self::RUN_SECONDS ) {
					break;
				}
				$done[ self::PRODUCTS ] = array_merge( $done[ self::PRODUCTS ], FilterIndex::rebuild( $batch ) );
			}
			if ( array() !== $due[ self::DEPENDENCIES ] && microtime( true ) - $started < self::RUN_SECONDS && AttributeDependencies::rebuild() ) {
				$done[ self::DEPENDENCIES ][] = self::STORE;
			}
			foreach ( array_keys( $due[ self::COUNTS ] ) as $transient ) {
				if ( microtime( true ) - $started >= self::RUN_SECONDS ) {
					break;
				}
				if ( FilterMatches::recount( (string) $transient ) ) {
					$done[ self::COUNTS ][] = (string) $transient;
				}
			}
		} finally {
			$left          = self::change(
				static function ( array $now ) use ( $done, $due ): array {
					foreach ( $done as $kind => $keys ) {
						foreach ( $keys as $key ) {
							// A note changed since the job read it stays for the next run.
							if ( isset( $now[ $kind ][ $key ] ) && $now[ $kind ][ $key ] === $due[ $kind ][ $key ] ) {
								unset( $now[ $kind ][ $key ] );
							}
						}
					}
					return $now;
				}
			);
			self::$running = false;
			self::$noted   = null;
			if ( array() !== array_filter( $left ) ) {
				self::schedule();
			}
		}
	}

	/**
	 * Notes keys of one kind, and queues the job for them. A key found out
	 * of date is noted once; a change gives it a new token. The first note
	 * a request makes queues the job if none waits, as for notes left by a
	 * request that could not queue it.
	 *
	 * @param string            $kind    `PRODUCTS`, `DEPENDENCIES` or `COUNTS`.
	 * @param array<int|string> $keys    Product ids, `STORE` or transients.
	 * @param bool              $changed Whether they changed now.
	 */
	private static function note( string $kind, array $keys, bool $changed ): void {
		$first         = null === self::$noted;
		self::$noted ??= array();
		$new           = array_fill_keys( $keys, true );
		if ( ! $changed ) {
			$new = array_diff_key( $new, self::$noted[ $kind ] ?? array() );
		}
		if ( array() === $new ) {
			return;
		}
		self::$noted[ $kind ] = ( self::$noted[ $kind ] ?? array() ) + $new;
		if ( $changed || array() !== array_diff_key( $new, self::due()[ $kind ] ) ) {
			$token = wp_generate_uuid4();
			self::change(
				static function ( array $due ) use ( $kind, $new, $changed, $token ): array {
					foreach ( array_keys( $new ) as $key ) {
						if ( $changed || ! isset( $due[ $kind ][ $key ] ) ) {
							$due[ $kind ][ $key ] = $token;
						}
					}
					if ( self::COUNTS === $kind ) {
						$due[ $kind ] = array_slice( $due[ $kind ], 0, self::MAX_COUNTS, true );
					}
					return $due;
				}
			);
		} elseif ( ! $first ) {
			return;
		}
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
	 * @return array{products: array<int|string, string>, dependencies: array<int|string, string>, counts: array<int|string, string>}
	 */
	private static function change( \Closure $change ): array {
		/** @var array{products: array<int|string, string>, dependencies: array<int|string, string>, counts: array<int|string, string>} $due */
		$due = $change( self::due() );
		update_option( self::OPTION, $due, false );
		return $due;
	}

	/**
	 * The notes as stored now.
	 *
	 * @return array{products: array<int|string, string>, dependencies: array<int|string, string>, counts: array<int|string, string>}
	 */
	private static function due(): array {
		wp_cache_delete( self::OPTION, 'options' );
		$stored = get_option( self::OPTION, array() );
		$due    = array(
			self::PRODUCTS     => array(),
			self::DEPENDENCIES => array(),
			self::COUNTS       => array(),
		);
		foreach ( array_keys( $due ) as $kind ) {
			$keys = is_array( $stored ) && is_array( $stored[ $kind ] ?? null ) ? $stored[ $kind ] : array();
			foreach ( $keys as $key => $token ) {
				$due[ $kind ][ $key ] = is_string( $token ) ? $token : '';
			}
		}
		return $due;
	}
}
