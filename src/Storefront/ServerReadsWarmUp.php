<?php
/**
 * Keeps product pages' Pixonaut reads from running out while the shop is quiet.
 *
 * @package Pixonaut\WooCommerce
 */

declare(strict_types=1);

namespace Pixonaut\WooCommerce\Storefront;

use ActionScheduler_Abstract_Schedule;
use ActionScheduler_Action;
use ActionScheduler_Store;
use Closure;
use DateTimeInterface;
use Pixonaut\WooCommerce\Catalogue\CatalogueCache;
use Pixonaut\WooCommerce\Schema;

/**
 * A product page's reads (`ServerReads`) are cached for a few minutes, and
 * a copy of each serves for an hour past them while a new one is read
 * after the page is sent (`CatalogueCache::remember_or_kept()`). The copy
 * cannot serve longer: the links an answer carries open for little more
 * than that. So the first page view after an hour of quiet waited for every
 * one of its reads, about a dozen on a product page, a second or two.
 *
 * Each read a page asks for is noted, with when it was last read and last
 * asked for, and a job reads it again shortly before its copy runs out, for
 * as long as a page asked for it in the last few days: a visitor after a
 * quiet spell is served the copy, as during the day.
 *
 * The notes share one option. Each change reads it afresh and writes it at
 * once, and a page asking for a read the option lost, as when two requests
 * wrote it at the same moment, notes it again.
 */
final class ServerReadsWarmUp {

	/**
	 * The job reading the noted answers again.
	 */
	public const HOOK = 'pixonaut_wc_warm_server_reads';

	public const OPTION = Schema::SERVER_READS_IN_USE_OPTION;

	/**
	 * How long before a kept copy runs out it is read again: a few runs of
	 * WordPress's cron, which only starts jobs on its own schedule.
	 */
	private const LEAD_SECONDS = 15 * MINUTE_IN_SECONDS;

	/**
	 * How long after a page last asked for an answer it is still read
	 * again: over a weekend, not for a product nobody looks at.
	 */
	private const IN_USE_SECONDS = 3 * DAY_IN_SECONDS;

	/**
	 * How often a page asking for an answer renews when it was last asked
	 * for: well within `IN_USE_SECONDS`, and no write on every view.
	 */
	private const ASKED_RENEWAL_SECONDS = DAY_IN_SECONDS;

	/**
	 * How long a read Pixonaut failed waits before the job tries it again,
	 * while the others go on.
	 */
	private const RETRY_SECONDS = 10 * MINUTE_IN_SECONDS;

	/**
	 * After this many failed reads in a row the run stops: Pixonaut is
	 * down, not one answer gone.
	 */
	private const FAILURES_IN_A_ROW = 3;

	/**
	 * How long one run reads for, so a large shop's reads spread over
	 * several runs a minute apart rather than one long request.
	 */
	private const RUN_SECONDS = 30;

	/**
	 * How soon a run that left answers due runs again.
	 */
	private const NEXT_RUN_SECONDS = MINUTE_IN_SECONDS;

	private readonly Closure $clock;

	/**
	 * Whether the job is running here: it queues its next run once, at its end.
	 *
	 * @var bool
	 */
	private bool $warming = false;

	/**
	 * @param Closure|null $clock `fn(): int` Unix time; `time()` when null.
	 */
	public function __construct( ?Closure $clock = null ) {
		$this->clock = $clock ?? static fn (): int => time();
	}

	/**
	 * Notes that a page asked for an answer, cached or not: once a day per
	 * answer, or when the answer is not noted at all.
	 *
	 * @param string                $what Which read, as `ServerReads` caches it.
	 * @param array<string, string> $args What it was asked with.
	 */
	public function asked( string $what, array $args ): void {
		$now   = ( $this->clock )();
		$key   = self::key( $what, $args );
		$entry = self::entries()[ $key ] ?? null;
		if ( null !== $entry && $now - $entry['viewed_at'] < self::ASKED_RENEWAL_SECONDS ) {
			return;
		}
		$this->change(
			static function ( array $entries ) use ( $key, $what, $args, $now ): array {
				// An answer not noted may be served from a copy of any age: it is due at once.
				$entries[ $key ] = array(
					'what'      => $what,
					'args'      => $args,
					'read_at'   => $entries[ $key ]['read_at'] ?? 0,
					'viewed_at' => $now,
					'retry_at'  => $entries[ $key ]['retry_at'] ?? 0,
				);
				return $entries;
			}
		);
		$this->schedule( $now );
	}

	/**
	 * Notes that an answer was just read and kept, by a page or the job.
	 *
	 * @param string                $what Which read.
	 * @param array<string, string> $args What it was asked with.
	 */
	public function read( string $what, array $args ): void {
		$now = ( $this->clock )();
		$key = self::key( $what, $args );
		$this->change(
			static function ( array $entries ) use ( $key, $what, $args, $now ): array {
				$entries[ $key ] = array(
					'what'      => $what,
					'args'      => $args,
					'read_at'   => $now,
					'viewed_at' => $entries[ $key ]['viewed_at'] ?? $now,
					'retry_at'  => 0,
				);
				return $entries;
			}
		);
		$this->schedule( $now );
	}

	/**
	 * The job: reads again each noted answer whose copy runs out soon, for
	 * up to `RUN_SECONDS`, forgets those no page asked for in a few days,
	 * and runs again when the next is due. A read Pixonaut fails waits
	 * `RETRY_SECONDS` while the others go on.
	 *
	 * @param Closure $read_again `fn(string $what, array $args): bool`, true once the answer is read and kept again.
	 */
	public function warm( Closure $read_again ): void {
		$now     = ( $this->clock )();
		$entries = $this->change( static fn ( array $entries ): array => array_filter( $entries, static fn ( array $entry ): bool => $now - $entry['viewed_at'] <= self::IN_USE_SECONDS ) );
		$due     = array_filter( $entries, static fn ( array $entry ): bool => self::due( $entry ) <= $now );
		uasort( $due, static fn ( array $first, array $second ): int => self::due( $first ) <=> self::due( $second ) );
		$started       = microtime( true );
		$failures      = 0;
		$this->warming = true;
		foreach ( $due as $key => $entry ) {
			if ( $failures >= self::FAILURES_IN_A_ROW || microtime( true ) - $started >= self::RUN_SECONDS ) {
				break;
			}
			if ( $read_again( $entry['what'], $entry['args'] ) ) {
				$failures = 0;
				continue;
			}
			++$failures;
			$retry_at = ( $this->clock )() + self::RETRY_SECONDS;
			$this->change(
				static function ( array $entries ) use ( $key, $retry_at ): array {
					if ( isset( $entries[ $key ] ) ) {
						$entries[ $key ]['retry_at'] = $retry_at;
					}
					return $entries;
				}
			);
		}
		$this->warming = false;
		$this->schedule( ( $this->clock )() );
	}

	/**
	 * Queues the job for the next answer due, unless it is queued by then;
	 * none while nothing is noted.
	 *
	 * @param int $now The time.
	 */
	private function schedule( int $now ): void {
		$entries = self::entries();
		if ( array() === $entries || $this->warming ) {
			return;
		}
		$next    = max( min( array_map( static fn ( array $entry ): int => self::due( $entry ), $entries ) ), $now + self::NEXT_RUN_SECONDS );
		$pending = self::pending_at();
		if ( null !== $pending && $pending <= $next ) {
			return;
		}
		if ( null !== $pending ) {
			as_unschedule_action( self::HOOK, array(), Schema::ACTION_GROUP );
		}
		as_schedule_single_action( $next, self::HOOK, array(), Schema::ACTION_GROUP );
	}

	/**
	 * When the queued job runs; null while none waits (one running does not).
	 */
	private static function pending_at(): ?int {
		$actions = as_get_scheduled_actions(
			array(
				'hook'     => self::HOOK,
				'group'    => Schema::ACTION_GROUP,
				'status'   => ActionScheduler_Store::STATUS_PENDING,
				'per_page' => 1,
				'orderby'  => 'date',
				'order'    => 'ASC',
			)
		);
		$action  = reset( $actions );
		if ( ! $action instanceof ActionScheduler_Action ) {
			return null;
		}
		$schedule = $action->get_schedule();
		$date     = $schedule instanceof ActionScheduler_Abstract_Schedule ? $schedule->get_date() : null;
		return $date instanceof DateTimeInterface ? $date->getTimestamp() : null;
	}

	/**
	 * When an answer is to be read again: before its copy runs out, and
	 * not before a failed read's retry.
	 *
	 * @param array{read_at: int, retry_at: int} $entry A noted answer.
	 */
	private static function due( array $entry ): int {
		return max( $entry['read_at'] + CatalogueCache::KEPT_SECONDS - self::LEAD_SECONDS, $entry['retry_at'] );
	}

	/**
	 * Changes the notes as they are stored now, not as this request first
	 * read them, and stores them at once.
	 *
	 * @param Closure $change `fn(array $entries): array`.
	 * @return array<string, array{what: string, args: array<string, string>, read_at: int, viewed_at: int, retry_at: int}>
	 */
	private function change( Closure $change ): array {
		wp_cache_delete( self::OPTION, 'options' );
		/** @var array<string, array{what: string, args: array<string, string>, read_at: int, viewed_at: int, retry_at: int}> $entries */
		$entries = $change( self::entries() );
		update_option( self::OPTION, $entries, false );
		return $entries;
	}

	/**
	 * The noted answers, by key.
	 *
	 * @return array<string, array{what: string, args: array<string, string>, read_at: int, viewed_at: int, retry_at: int}>
	 */
	private static function entries(): array {
		$stored  = get_option( self::OPTION, array() );
		$entries = array();
		foreach ( is_array( $stored ) ? $stored : array() as $key => $entry ) {
			if ( is_array( $entry ) && is_string( $entry['what'] ?? null ) && is_array( $entry['args'] ?? null ) && is_int( $entry['read_at'] ?? null ) && is_int( $entry['viewed_at'] ?? null ) ) {
				$entries[ (string) $key ] = array(
					'what'      => $entry['what'],
					'args'      => array_map( 'strval', $entry['args'] ),
					'read_at'   => $entry['read_at'],
					'viewed_at' => $entry['viewed_at'],
					'retry_at'  => is_int( $entry['retry_at'] ?? null ) ? $entry['retry_at'] : 0,
				);
			}
		}
		return $entries;
	}

	/**
	 * @param string                $what Which read.
	 * @param array<string, string> $args What it was asked with.
	 */
	private static function key( string $what, array $args ): string {
		ksort( $args );
		return md5( (string) wp_json_encode( array( $what, $args ) ) );
	}
}
