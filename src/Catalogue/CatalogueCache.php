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
	 * @param ConnectionStore $connections Names the connection the answers belong to.
	 */
	public function __construct( private readonly ConnectionStore $connections ) {
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
	 * Drops one cached answer, so the next read asks Pixonaut again.
	 *
	 * @param string                $what Which answer.
	 * @param array<string, string> $args What it was asked with.
	 */
	public function forget( string $what, array $args ): void {
		delete_transient( $this->key( $what, $args ) );
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
