<?php
/**
 * Moves the catalogue's waiting jobs behind orders, webhooks and fulfilment.
 *
 * @package Pixonaut\WooCommerce
 */

declare(strict_types=1);

namespace Pixonaut\WooCommerce\Migrations;

use ActionScheduler_Store;
use Pixonaut\WooCommerce\Catalogue\PictureQueue;
use Pixonaut\WooCommerce\Catalogue\ProductPictures;
use Pixonaut\WooCommerce\Catalogue\ProductRecheck;
use Pixonaut\WooCommerce\Schema;

/**
 * Picture and re-check jobs used to be queued at Action Scheduler's default
 * priority, beside the jobs of orders, webhook events and fulfilment, so a
 * blank or template change queueing a thousand of them held those back for
 * hours. They are queued at `Schema::CATALOGUE_JOB_PRIORITY` now; moves the
 * ones still waiting there too.
 */
final class MoveCatalogueJobsBack implements Migration {

	/**
	 * The priority they were queued at: Action Scheduler's default.
	 */
	private const DEFAULT_PRIORITY = 10;

	/**
	 * The hooks of the jobs moved.
	 */
	private const HOOKS = array(
		ProductPictures::ACTION,
		ProductPictures::RETRY_ACTION,
		PictureQueue::QUEUE_ACTION,
		PictureQueue::VARIATIONS_ACTION,
		ProductRecheck::ACTION,
		ProductRecheck::QUEUE_ACTION,
	);

	public function version(): int {
		return 19;
	}

	/**
	 * @throws MigrationPostponed When the database refused a query; the jobs stay where they are until the next try.
	 */
	public function up(): void {
		global $wpdb;
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- Action Scheduler has no call changing a job's priority; one update of its own table, a placeholder per hook.
		$found = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $wpdb->prefix . 'actionscheduler_actions' ) ) );
		self::check( $wpdb->last_error );
		if ( null === $found ) {
			// Jobs kept as posts, by an Action Scheduler older than its own tables: they stay where they are.
			return;
		}
		$hooks = implode( ', ', array_fill( 0, count( self::HOOKS ), '%s' ) );
		$wpdb->query(
			$wpdb->prepare(
				"UPDATE {$wpdb->prefix}actionscheduler_actions SET priority = %d WHERE status = %s AND priority = %d AND hook IN ( {$hooks} )",
				array_merge( array( Schema::CATALOGUE_JOB_PRIORITY, ActionScheduler_Store::STATUS_PENDING, self::DEFAULT_PRIORITY ), self::HOOKS )
			)
		);
		// phpcs:enable
		self::check( $wpdb->last_error );
	}

	/**
	 * @param string $error The database's last error; empty for none.
	 * @throws MigrationPostponed When there is one.
	 */
	private static function check( string $error ): void {
		if ( '' !== $error ) {
			throw new MigrationPostponed( esc_html( 'Catalogue jobs could not be moved behind the store\'s other jobs: ' . $error ) );
		}
	}
}
