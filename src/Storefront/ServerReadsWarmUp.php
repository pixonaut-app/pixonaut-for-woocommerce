<?php
/**
 * Keeps product pages' Pixonaut reads from running out while the shop is quiet.
 *
 * @package Pixonaut\WooCommerce
 */

declare(strict_types=1);

namespace Pixonaut\WooCommerce\Storefront;

use Closure;
use Pixonaut\WooCommerce\Catalogue\CatalogueCache;
use Pixonaut\WooCommerce\Catalogue\WaitingJob;
use Pixonaut\WooCommerce\Schema;

/**
 * A product page's reads (`ServerReads`) are cached for a few minutes, and
 * a copy of each serves for an hour past them while a new one is read
 * after the page is sent (`CatalogueCache::remember_or_kept()`). The copy
 * cannot serve longer: the links an answer carries open for little more
 * than that. So the first page view after an hour of quiet waited for every
 * one of its reads, about a dozen on a product page, a second or two.
 *
 * Each read a page view made is noted, and a job reads it again shortly
 * before its copy runs out, for as long as a page asked for it in the last
 * few days: a visitor after a quiet spell is served the copy, as during
 * the day.
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
	 * The most answers one run reads, so a large shop spreads its reads
	 * over several runs rather than ask Pixonaut for all at once.
	 */
	private const READS_PER_RUN = 40;

	/**
	 * How soon a run that left answers due runs again.
	 */
	private const NEXT_RUN_SECONDS = MINUTE_IN_SECONDS;

	private readonly Closure $clock;

	/**
	 * @param Closure|null $clock `fn(): int` Unix time; `time()` when null.
	 */
	public function __construct( ?Closure $clock = null ) {
		$this->clock = $clock ?? static fn (): int => time();
	}

	/**
	 * Notes an answer just read, and when: by a page, which keeps it read
	 * again for a few days, or by the job.
	 *
	 * @param string                $what      Which read, as `ServerReads` caches it.
	 * @param array<string, string> $args      What it was asked with.
	 * @param bool                  $for_a_page Whether a page asked for it, rather than the job.
	 */
	public function note( string $what, array $args, bool $for_a_page ): void {
		$now     = ( $this->clock )();
		$entries = self::entries();
		$key     = self::key( $what, $args );
		$viewed  = $for_a_page ? $now : (int) ( $entries[ $key ]['viewed_at'] ?? $now );
		// Two requests noting at once may lose one note: that answer is read on its next page view, as before.
		$entries[ $key ] = array(
			'what'      => $what,
			'args'      => $args,
			'read_at'   => $now,
			'viewed_at' => $viewed,
		);
		update_option( self::OPTION, $entries, false );
		if ( $for_a_page && ! WaitingJob::exists( self::HOOK, array(), Schema::ACTION_GROUP ) ) {
			as_schedule_single_action( self::due( $entries[ $key ] ), self::HOOK, array(), Schema::ACTION_GROUP );
		}
	}

	/**
	 * The job: reads again each noted answer whose copy runs out soon,
	 * forgets those no page asked for in a few days, and runs again when
	 * the next is due.
	 *
	 * @param Closure $read_again `fn(string $what, array $args): bool`, true once the answer is read and kept again.
	 */
	public function warm( Closure $read_again ): void {
		$now     = ( $this->clock )();
		$entries = array_filter( self::entries(), static fn ( array $entry ): bool => $now - $entry['viewed_at'] <= self::IN_USE_SECONDS );
		$due     = array_filter( $entries, static fn ( array $entry ): bool => self::due( $entry ) <= $now );
		uasort( $due, static fn ( array $first, array $second ): int => $first['read_at'] <=> $second['read_at'] );
		update_option( self::OPTION, $entries, false );
		foreach ( array_slice( $due, 0, self::READS_PER_RUN, true ) as $entry ) {
			if ( ! $read_again( $entry['what'], $entry['args'] ) ) {
				// Pixonaut failed: the copies serve on, and the next run tries again.
				break;
			}
		}
		$left = self::entries();
		if ( array() === $left || WaitingJob::exists( self::HOOK, array(), Schema::ACTION_GROUP ) ) {
			return;
		}
		$next = min( array_map( static fn ( array $entry ): int => self::due( $entry ), $left ) );
		as_schedule_single_action( max( $next, ( $this->clock )() + self::NEXT_RUN_SECONDS ), self::HOOK, array(), Schema::ACTION_GROUP );
	}

	/**
	 * When an answer is to be read again: before its copy runs out.
	 *
	 * @param array{read_at: int} $entry A noted answer.
	 */
	private static function due( array $entry ): int {
		return $entry['read_at'] + CatalogueCache::KEPT_SECONDS - self::LEAD_SECONDS;
	}

	/**
	 * The noted answers, by key.
	 *
	 * @return array<string, array{what: string, args: array<string, string>, read_at: int, viewed_at: int}>
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
