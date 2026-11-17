<?php
/**
 * Short-lived cache of Pixonaut catalogue answers.
 *
 * @package Pixonaut\WooCommerce
 */

declare(strict_types=1);

namespace Pixonaut\WooCommerce\Catalogue;

use Closure;
use Pixonaut\WooCommerce\Connection\ConnectionStore;
use Pixonaut\WooCommerce\Schema;
use Pixonaut\WooCommerce\Vendor\Pixonaut\Sdk\Exception\ApiException;

/**
 * Searches repeat as the store manager types, and the product screen reads
 * the linked blank on every load: answers are kept in transients for a few
 * minutes, per connection, so a reconnect to another workspace never shows
 * the old one's catalogue. Failures are not cached.
 */
final class CatalogueCache {

	/**
	 * Five minutes: long enough to absorb typing, short enough that a
	 * template published in the studio shows up while the merchant waits.
	 */
	public const TTL_SECONDS = 300;

	public const PREFIX = Schema::CATALOGUE_CACHE_PREFIX;

	public const GENERATION_OPTION = Schema::CATALOGUE_CACHE_GENERATION_OPTION;

	/**
	 * After an answer's key, the copy `remember_or_kept()` keeps past the
	 * answer's few minutes.
	 */
	private const KEPT_SUFFIX = '_kept';

	/**
	 * An hour, in seconds: how long a kept copy serves. The short-lived
	 * links an answer carries, such as a mockup's `thumbnailUrl`, open for
	 * at least that long.
	 */
	private const KEPT_SECONDS = 3600;

	/**
	 * A minute, in seconds: how long a read in a kept answer's place holds
	 * off the next, and so how soon one that failed is tried again.
	 */
	private const REFRESH_HOLD_SECONDS = 60;

	/**
	 * The reads to make once the page is sent, by key.
	 *
	 * @var array<string, Closure(): array<int|string, mixed>>
	 */
	private array $refreshes = array();

	/**
	 * Finishes the response to the visitor, so the request's remaining
	 * work runs without them waiting; null where the server offers no way.
	 *
	 * @var (Closure(): bool)|null
	 */
	private ?Closure $finish_response;

	/**
	 * @param ConnectionStore        $connections     Names the connection the answers belong to.
	 * @param (Closure(): bool)|null $finish_response Finishes the response; by default PHP-FPM's or LiteSpeed's, where the server has one.
	 */
	public function __construct( private readonly ConnectionStore $connections, ?Closure $finish_response = null ) {
		$this->finish_response = $finish_response ?? self::server_finish_response();
	}

	/**
	 * The server's way to finish a response before the request ends: PHP-FPM's or LiteSpeed's.
	 *
	 * @return (Closure(): bool)|null
	 */
	public static function server_finish_response(): ?Closure {
		foreach ( array( 'fastcgi_finish_request', 'litespeed_finish_request' ) as $finish ) {
			if ( function_exists( $finish ) ) {
				return static fn (): bool => (bool) call_user_func( $finish );
			}
		}
		return null;
	}

	/**
	 * The cached answer, or `$load`'s, cached.
	 *
	 * @param string                              $what Which answer, e.g. `blanks`.
	 * @param array<string, string>               $args What it was asked with.
	 * @param Closure(): array<int|string, mixed> $load Asks Pixonaut; may throw, which is not cached.
	 * @return array<int|string, mixed>
	 */
	public function remember( string $what, array $args, Closure $load ): array {
		$key    = $this->key( $what, $args );
		$cached = get_transient( $key );
		if ( is_array( $cached ) ) {
			return $cached;
		}
		$answer = $load();
		set_transient( $key, $answer, self::TTL_SECONDS );
		return $answer;
	}

	/**
	 * The cached answer, for a page a visitor waits on: once its few minutes
	 * are up, the answer kept from before serves for up to an hour while
	 * `$load` reads a new one after the response is finished
	 * (`refresh_kept()`). Where the server cannot finish a response early,
	 * the kept answer serves until its hour is up instead, rather than hold
	 * the visitor for the read. With none kept, `$load`'s, cached.
	 *
	 * @param string                              $what Which answer, e.g. `storefront-template`.
	 * @param array<string, string>               $args What it was asked with.
	 * @param Closure(): array<int|string, mixed> $load Asks Pixonaut; may throw, which is not cached.
	 * @return array<int|string, mixed>
	 */
	public function remember_or_kept( string $what, array $args, Closure $load ): array {
		$key    = $this->key( $what, $args );
		$cached = get_transient( $key );
		if ( is_array( $cached ) ) {
			return $cached;
		}
		$kept = get_transient( $key . self::KEPT_SUFFIX );
		if ( ! is_array( $kept ) ) {
			$answer = $load();
			$this->keep( $key, $answer );
			return $answer;
		}
		if ( null !== $this->finish_response && ! isset( $this->refreshes[ $key ] ) && $this->hold_refresh( $key ) ) {
			$this->refreshes[ $key ] = $load;
			add_action( 'shutdown', array( $this, 'refresh_kept' ), PHP_INT_MAX );
		}
		return $kept;
	}

	/**
	 * Reads the answers kept ones served in place of, after every other
	 * shutdown callback and once the response is finished, so the visitor
	 * does not wait for it. A read Pixonaut refuses, or that cannot reach
	 * it, leaves the kept answer serving, and is tried again a minute later.
	 */
	public function refresh_kept(): void {
		$refreshes       = $this->refreshes;
		$this->refreshes = array();
		if ( array() === $refreshes || null === $this->finish_response ) {
			return;
		}
		( $this->finish_response )();
		foreach ( $refreshes as $key => $load ) {
			try {
				$this->keep( $key, $load() );
			} catch ( ApiException ) {
				// The kept answer serves on until it expires; the hold on refreshing it lapses in a minute.
				continue;
			}
			delete_option( self::refresh_hold_option( $key ) );
		}
	}

	/**
	 * Takes the right to read a new answer in place of a kept one.
	 * `add_option()` inserts or fails atomically, so two page views never
	 * both read it; a hold older than a minute is taken over.
	 *
	 * @param string $key The answer's key.
	 */
	private function hold_refresh( string $key ): bool {
		$option = self::refresh_hold_option( $key );
		$now    = time();
		if ( add_option( $option, (string) $now, '', false ) ) {
			return true;
		}
		$held_since = get_option( $option );
		if ( is_numeric( $held_since ) && $now - (int) $held_since < self::REFRESH_HOLD_SECONDS ) {
			return false;
		}
		delete_option( $option );
		return add_option( $option, (string) $now, '', false );
	}

	/**
	 * The option holding the right to refresh an answer.
	 *
	 * @param string $key The answer's key.
	 */
	private static function refresh_hold_option( string $key ): string {
		return Schema::CATALOGUE_REFRESH_HOLD_OPTION_PREFIX . md5( $key );
	}

	/**
	 * Caches an answer, and keeps a copy for `remember_or_kept()`.
	 *
	 * @param string                   $key    The answer's key.
	 * @param array<int|string, mixed> $answer The answer.
	 */
	private function keep( string $key, array $answer ): void {
		set_transient( $key, $answer, self::TTL_SECONDS );
		set_transient( $key . self::KEPT_SUFFIX, $answer, self::KEPT_SECONDS );
	}

	/**
	 * Drops one cached answer, so the next read asks Pixonaut again.
	 *
	 * @param string                $what Which answer.
	 * @param array<string, string> $args What it was asked with.
	 */
	public function forget( string $what, array $args ): void {
		$key = $this->key( $what, $args );
		delete_transient( $key );
		delete_transient( $key . self::KEPT_SUFFIX );
	}

	/**
	 * Drops every cached answer, by moving every key to a new generation;
	 * the old answers expire on their own.
	 */
	public function flush(): void {
		update_option( self::GENERATION_OPTION, self::generation() + 1, false );
	}

	/**
	 * @param string                $what Which answer.
	 * @param array<string, string> $args What it was asked with.
	 */
	private function key( string $what, array $args ): string {
		ksort( $args );
		$connection = $this->connections->load()->connection_id ?? '';
		return self::PREFIX . md5( (string) wp_json_encode( array( $connection, self::generation(), $what, $args ) ) );
	}

	private static function generation(): int {
		return (int) get_option( self::GENERATION_OPTION, 0 );
	}
}
