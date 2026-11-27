<?php
/**
 * A change to one post's meta, made alone.
 *
 * @package Pixonaut\WooCommerce
 */

declare(strict_types=1);

namespace Pixonaut\WooCommerce\Catalogue;

/**
 * Runs a change to one post's meta under a MySQL lock of its own
 * (`GET_LOCK()`), so two requests changing the same meta at once take turns:
 * postmeta has no unique key, and a read and a write are two queries. The
 * change reads what is stored once it holds the lock, with WordPress's meta
 * cache of the post dropped, whatever the request read before.
 */
final class PostLock {

	/**
	 * How long a change waits for another one of the same post.
	 */
	public const WAIT_SECONDS = 5;

	/**
	 * What MySQL's `GET_LOCK()` answers once the lock is taken.
	 */
	private const TAKEN = '1';

	/**
	 * Runs `$change` alone among the changes under `$prefix` to the post.
	 * When another change still holds the lock after the wait, `$not_taken`
	 * answers instead; without one, the change runs beside it, as one taken
	 * to be stuck.
	 *
	 * @template T
	 * @param string               $prefix    Names the meta the lock is for, e.g. `pixonaut_side_values_`.
	 * @param int                  $post_id   The post.
	 * @param callable(): T        $change    The change: it reads, then writes.
	 * @param (callable(): T)|null $not_taken What to answer when the lock was not taken; null to run the change anyway.
	 * @return T
	 */
	public static function alone( string $prefix, int $post_id, callable $change, ?callable $not_taken = null ): mixed {
		global $wpdb;
		$lock = $prefix . md5( $wpdb->prefix . $post_id );
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- a lock, never cached.
		$taken = self::TAKEN === $wpdb->get_var( $wpdb->prepare( 'SELECT GET_LOCK( %s, %d )', $lock, self::WAIT_SECONDS ) );
		if ( ! $taken && null !== $not_taken ) {
			return $not_taken();
		}
		try {
			wp_cache_delete( (string) $post_id, 'post_meta' );
			return $change();
		} finally {
			if ( $taken ) {
				$wpdb->query( $wpdb->prepare( 'SELECT RELEASE_LOCK( %s )', $lock ) );
			}
		}
		// phpcs:enable
	}
}
