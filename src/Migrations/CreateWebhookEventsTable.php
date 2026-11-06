<?php
/**
 * Creates the webhook events table.
 *
 * @package Pixonaut\WooCommerce
 */

declare(strict_types=1);

namespace Pixonaut\WooCommerce\Migrations;

// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- exception messages here are reasons and log text, never printed as they are: whoever shows one escapes it.

use Pixonaut\WooCommerce\Webhooks\EventStore;
use RuntimeException;

/**
 * The table the webhook receiver dedupes on and hands events to their job
 * through.
 */
final class CreateWebhookEventsTable implements Migration {

	public function version(): int {
		return 1;
	}

	/**
	 * @throws RuntimeException When the table is not there afterwards; the
	 *                          version is then not recorded, so the next
	 *                          request tries again.
	 */
	public function up(): void {
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		$table   = EventStore::table();
		$charset = $wpdb->get_charset_collate();
		// dbDelta's format: two spaces after PRIMARY KEY, one column per line.
		$id_length   = EventStore::MAX_ID_LENGTH;
		$type_length = EventStore::MAX_TYPE_LENGTH;
		dbDelta(
			"CREATE TABLE {$table} (
event_id varchar({$id_length}) NOT NULL,
type varchar({$type_length}) NOT NULL,
body longtext NOT NULL,
received_at bigint(20) unsigned NOT NULL,
processed_at bigint(20) unsigned NULL,
attempts smallint(5) unsigned NOT NULL DEFAULT 0,
PRIMARY KEY  (event_id),
KEY received_at (received_at)
) {$charset};"
		);
		// dbDelta reports no failure: check the table is there.
		$found = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $table ) ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- schema check in a migration.
		if ( $table !== $found ) {
			throw new RuntimeException( sprintf( 'The table %s could not be created.', $table ) );
		}
	}
}
