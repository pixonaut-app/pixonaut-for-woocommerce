<?php
/**
 * The webhook events received.
 *
 * @package Pixonaut\WooCommerce
 */

declare(strict_types=1);

namespace Pixonaut\WooCommerce\Webhooks;

use Pixonaut\WooCommerce\Schema;
use Pixonaut\WooCommerce\Vendor\Pixonaut\Sdk\Webhooks\Event;

/**
 * One row per event id: the receiver adds it, which tells a new event from a
 * retry or redelivery of one already taken, and the job that handles the
 * event reads its body from there. Rows are kept RETENTION_SECONDS, longer
 * than Pixonaut keeps retrying a delivery.
 */
final class EventStore {

	/**
	 * Longest event id kept; the primary key's length.
	 */
	public const MAX_ID_LENGTH = 191;

	/**
	 * Longest event type kept.
	 */
	public const MAX_TYPE_LENGTH = 100;

	public const RETENTION_SECONDS = 2592000; // Thirty days.

	/**
	 * What add() returns when the event was new and is kept now.
	 */
	public const ADDED = 'added';

	/**
	 * What add() returns when the event id was kept already.
	 */
	public const DUPLICATE = 'duplicate';

	/**
	 * What add() returns when the database refused the write; nothing is kept.
	 */
	public const FAILED = 'failed';

	public static function table(): string {
		global $wpdb;
		return $wpdb->prefix . Schema::WEBHOOK_EVENTS_TABLE;
	}

	/**
	 * Keeps a new event.
	 *
	 * @param Event  $event The event.
	 * @param string $body  The request body it came in, as signed.
	 * @param int    $now   Unix time.
	 * @return string ADDED, DUPLICATE when the event id is kept already, or
	 *                FAILED when the database refused the write.
	 * @phpstan-return self::ADDED|self::DUPLICATE|self::FAILED
	 */
	public function add( Event $event, string $body, int $now ): string {
		global $wpdb;
		$table = self::table();
		// IGNORE skips a duplicate key; the receiver refuses ids and types longer than their columns.
		$added = $wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- the plugin's own table.
			$wpdb->prepare(
				"INSERT IGNORE INTO {$table} (event_id, type, body, received_at) VALUES (%s, %s, %s, %d)", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- the table name.
				$event->id,
				$event->type,
				$body,
				$now
			)
		);
		if ( false === $added ) {
			return self::FAILED;
		}
		return 1 === $added ? self::ADDED : self::DUPLICATE;
	}

	/**
	 * Forgets an event, so its next delivery is taken as new.
	 *
	 * @param string $id Event id.
	 */
	public function remove( string $id ): void {
		global $wpdb;
		$wpdb->delete( self::table(), array( 'event_id' => $id ), array( '%s' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- the plugin's own table.
	}

	/**
	 * The body an event came in.
	 *
	 * @param string $id Event id.
	 * @return string|null Null when it is not kept (any more).
	 */
	public function body( string $id ): ?string {
		global $wpdb;
		$table = self::table();
		$body  = $wpdb->get_var( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- the plugin's own table, read once per event.
			$wpdb->prepare( "SELECT body FROM {$table} WHERE event_id = %s", $id ) // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- the table name.
		);
		return is_string( $body ) ? $body : null;
	}

	/**
	 * Counts one more attempt at handling an event.
	 *
	 * @param string $id Event id.
	 * @return int The attempts made, this one included; 0 when the event is not kept.
	 */
	public function begin_attempt( string $id ): int {
		global $wpdb;
		$table = self::table();
		$wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- the plugin's own table.
			$wpdb->prepare( "UPDATE {$table} SET attempts = attempts + 1 WHERE event_id = %s", $id ) // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- the table name.
		);
		$attempts = $wpdb->get_var( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- the plugin's own table, read right after the write.
			$wpdb->prepare( "SELECT attempts FROM {$table} WHERE event_id = %s", $id ) // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- the table name.
		);
		return (int) $attempts;
	}

	/**
	 * Events received before $before that were never handled and have
	 * attempts left, oldest first.
	 *
	 * @param int $before       Unix time.
	 * @param int $limit        At most this many.
	 * @param int $max_attempts Events tried this often are left out.
	 * @return list<string> Event ids.
	 */
	public function stranded( int $before, int $limit, int $max_attempts ): array {
		global $wpdb;
		$table = self::table();
		$ids   = $wpdb->get_col( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- the plugin's own table, read once a day.
			$wpdb->prepare(
				"SELECT event_id FROM {$table} WHERE processed_at IS NULL AND received_at < %d AND attempts < %d ORDER BY received_at LIMIT %d", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- the table name.
				$before,
				$max_attempts,
				$limit
			)
		);
		return array_values( array_map( 'strval', $ids ) );
	}

	/**
	 * Records that the event was handled.
	 *
	 * @param string $id  Event id.
	 * @param int    $now Unix time.
	 */
	public function mark_processed( string $id, int $now ): void {
		global $wpdb;
		$wpdb->update( self::table(), array( 'processed_at' => $now ), array( 'event_id' => $id ), array( '%d' ), array( '%s' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- the plugin's own table.
	}

	/**
	 * Whether the event was handled.
	 *
	 * @param string $id Event id.
	 */
	public function processed( string $id ): bool {
		global $wpdb;
		$table = self::table();
		$at    = $wpdb->get_var( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- the plugin's own table.
			$wpdb->prepare( "SELECT processed_at FROM {$table} WHERE event_id = %s", $id ) // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- the table name.
		);
		return null !== $at;
	}

	/**
	 * Drops events received more than RETENTION_SECONDS ago.
	 *
	 * @param int $now Unix time.
	 * @return int The number dropped.
	 */
	public function prune( int $now ): int {
		global $wpdb;
		$table   = self::table();
		$dropped = $wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- the plugin's own table.
			$wpdb->prepare( "DELETE FROM {$table} WHERE received_at < %d", $now - self::RETENTION_SECONDS ) // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- the table name.
		);
		return is_int( $dropped ) ? $dropped : 0;
	}
}
