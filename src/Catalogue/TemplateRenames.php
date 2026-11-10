<?php
/**
 * The template ids Pixonaut changed.
 *
 * @package Pixonaut\WooCommerce
 */

declare(strict_types=1);

namespace Pixonaut\WooCommerce\Catalogue;

use Pixonaut\WooCommerce\Schema;
use RuntimeException;

/**
 * Every change of a template's base id Pixonaut reported, as `{from, to, at}`
 * with `at` when it was made. Pixonaut keeps answering an old id as an alias,
 * and moves the designs made under it to the new one, so a design read after
 * a change names the new id while something the store still holds names the
 * old. Comparing both by `current()` makes them the same template.
 *
 * The history is only ever added to, so a change handled again, late or out
 * of order, takes nothing away. It is read by time: a chain of changes (a to
 * b, then b to c) is followed to its end, and an id that becomes a
 * template's id again names that template from then on.
 */
final class TemplateRenames {

	public const OPTION = Schema::TEMPLATE_RENAMES_OPTION;

	/**
	 * Tries at writing the history over what another request wrote meanwhile.
	 */
	private const MAX_WRITE_ATTEMPTS = 5;

	/**
	 * The option row's `autoload`: the history is read only around designs and carts.
	 */
	private const NOT_AUTOLOADED = 'off';

	/**
	 * Notes that the template `$from` became `$to` at `$at`. Noting the same
	 * change again changes nothing.
	 *
	 * @param string $from The old base id.
	 * @param string $to   The new base id.
	 * @param int    $at   When it changed, in milliseconds since the Unix epoch.
	 * @throws RuntimeException When it could not be stored.
	 */
	public static function record( string $from, string $to, int $at ): void {
		if ( '' === $from || '' === $to || $from === $to ) {
			return;
		}
		$change = array(
			'from' => $from,
			'to'   => $to,
			'at'   => $at,
		);
		for ( $attempt = 0; $attempt < self::MAX_WRITE_ATTEMPTS; $attempt++ ) {
			$raw     = self::read_row();
			$changes = self::parse( null === $raw ? array() : maybe_unserialize( $raw ) );
			if ( in_array( $change, $changes, true ) ) {
				return;
			}
			$changes[] = $change;
			if ( self::write_row( $raw, maybe_serialize( $changes ) ) && in_array( $change, self::parse( maybe_unserialize( (string) self::read_row() ) ), true ) ) {
				return;
			}
		}
		throw new RuntimeException( sprintf( 'The change of template %s to %s could not be stored.', $from, $to ) );
	}

	/**
	 * The id a template has now: `$base_id`, as the template that last took
	 * it, followed forward through every later change, or `$base_id` itself
	 * when it was never changed.
	 *
	 * @param string $base_id A template's base id.
	 */
	public static function current( string $base_id ): string {
		$changes = self::all();
		$after   = self::latest_to( $changes, $base_id, PHP_INT_MAX )['at'] ?? PHP_INT_MIN;
		// Each step takes a strictly later change, so the walk ends.
		for ( $next = self::earliest_from( $changes, $base_id, $after ); null !== $next; $next = self::earliest_from( $changes, $base_id, $after ) ) {
			$base_id = $next['to'];
			$after   = $next['at'];
		}
		return $base_id;
	}

	/**
	 * The first id the template was known by here: `$base_id`, as the
	 * template that last took it, followed back through every earlier
	 * change. The same for every id the template had, so what is keyed by it
	 * does not change when the template's id does.
	 *
	 * @param string $base_id A template's base id.
	 */
	public static function first( string $base_id ): string {
		$changes = self::all();
		$before  = PHP_INT_MAX;
		// Each step takes a strictly earlier change, so the walk ends.
		for ( $back = self::latest_to( $changes, $base_id, $before ); null !== $back; $back = self::latest_to( $changes, $base_id, $before ) ) {
			$base_id = $back['from'];
			$before  = $back['at'];
		}
		return $base_id;
	}

	/**
	 * Whether two base ids name the same template.
	 *
	 * @param string $one   A template's base id.
	 * @param string $other Another.
	 */
	public static function same( string $one, string $other ): bool {
		return $one === $other || self::current( $one ) === self::current( $other );
	}

	/**
	 * The latest change to `$id` made before `$before`.
	 *
	 * @param list<array{from: string, to: string, at: int}> $changes The history.
	 * @param string                                         $id      A base id.
	 * @param int                                            $before  Only changes made before this.
	 * @return array{from: string, to: string, at: int}|null
	 */
	private static function latest_to( array $changes, string $id, int $before ): ?array {
		$found = null;
		foreach ( $changes as $change ) {
			if ( $change['to'] === $id && $change['at'] < $before && ( null === $found || $change['at'] > $found['at'] ) ) {
				$found = $change;
			}
		}
		return $found;
	}

	/**
	 * The first change away from `$id` made after `$after`.
	 *
	 * @param list<array{from: string, to: string, at: int}> $changes The history.
	 * @param string                                         $id      A base id.
	 * @param int                                            $after   Only changes made after this.
	 * @return array{from: string, to: string, at: int}|null
	 */
	private static function earliest_from( array $changes, string $id, int $after ): ?array {
		$found = null;
		foreach ( $changes as $change ) {
			if ( $change['from'] === $id && $change['at'] > $after && ( null === $found || $change['at'] < $found['at'] ) ) {
				$found = $change;
			}
		}
		return $found;
	}

	/**
	 * @return list<array{from: string, to: string, at: int}> The history, as cached.
	 */
	private static function all(): array {
		return self::parse( get_option( self::OPTION, array() ) );
	}

	/**
	 * @param mixed $stored The option's value.
	 * @return list<array{from: string, to: string, at: int}> Its well-formed changes.
	 */
	private static function parse( mixed $stored ): array {
		$changes = array();
		foreach ( is_array( $stored ) ? $stored : array() as $change ) {
			if ( is_array( $change ) && is_string( $change['from'] ?? null ) && is_string( $change['to'] ?? null ) && is_int( $change['at'] ?? null ) && '' !== $change['from'] && '' !== $change['to'] ) {
				$changes[] = array(
					'from' => $change['from'],
					'to'   => $change['to'],
					'at'   => $change['at'],
				);
			}
		}
		return $changes;
	}

	/**
	 * The option's row as stored, past every cache: a change is compared to it.
	 *
	 * @return string|null Its value; null when there is no row.
	 * @throws RuntimeException When it could not be read.
	 */
	private static function read_row(): ?string {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- a compare-and-swap reads the row itself.
		$raw = $wpdb->get_var( $wpdb->prepare( "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s", self::OPTION ) );
		if ( null === $raw && '' !== $wpdb->last_error ) {
			throw new RuntimeException( 'The template id changes could not be read.' );
		}
		return null === $raw ? null : (string) $raw;
	}

	/**
	 * Writes the row, only if it still holds `$expected`. Not `add_option()`:
	 * its `ON DUPLICATE KEY UPDATE` overwrites a row that appeared meanwhile.
	 *
	 * @param string|null $expected The row's value as read; null when there was none.
	 * @param string      $value    What to write.
	 * @return bool Whether it was written; false when the row had changed or the database refused it.
	 */
	private static function write_row( ?string $expected, string $value ): bool {
		global $wpdb;
		$written = null === $expected
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- a compare-and-swap; the caches are cleared below.
			? $wpdb->query( $wpdb->prepare( "INSERT IGNORE INTO {$wpdb->options} (option_name, option_value, autoload) VALUES (%s, %s, %s)", self::OPTION, $value, self::NOT_AUTOLOADED ) )
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- a compare-and-swap; the caches are cleared below.
			: $wpdb->query( $wpdb->prepare( "UPDATE {$wpdb->options} SET option_value = %s WHERE option_name = %s AND option_value = %s", $value, self::OPTION, $expected ) );
		wp_cache_delete( self::OPTION, 'options' );
		wp_cache_delete( 'notoptions', 'options' );
		return is_int( $written ) && $written > 0;
	}
}
