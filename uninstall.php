<?php
/**
 * Removes what the plugin stored when it is deleted.
 *
 * Runs without the plugin's PHP version check, so it loads only classes kept
 * to PHP 7.2 syntax, and the plugin's others only on a PHP it supports. On
 * multisite it cleans every site.
 *
 * @package Pixonaut\WooCommerce
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

require_once __DIR__ . '/src/Schema.php';
require_once __DIR__ . '/src/Requirements.php';

// Revoking the connection needs the plugin's own classes, and so its PHP.
// The Unix time every site's revoke must be over by; 0 when this PHP cannot run the plugin's classes.
$pixonaut_wc_revoke_until = 0;
if ( \Pixonaut\WooCommerce\Requirements::php_supported( PHP_VERSION ) && is_readable( __DIR__ . '/vendor/autoload.php' ) ) {
	require_once __DIR__ . '/vendor/autoload.php';
	$pixonaut_wc_revoke_until = \Pixonaut\WooCommerce\Connection\UninstallRevoke::deadline();
}

if ( ! function_exists( 'pixonaut_wc_uninstall_site' ) ) {
	/**
	 * Revokes the current site's connection at Pixonaut, then removes what the
	 * plugin stored on the site.
	 *
	 * @param int $revoke_until Unix time the revokes of every site must be over by; 0 revokes nothing.
	 */
	function pixonaut_wc_uninstall_site( int $revoke_until ): void {
		global $wpdb;
		// First: it reads the connection and the API address deleted below.
		if ( $revoke_until > 0 ) {
			\Pixonaut\WooCommerce\Connection\UninstallRevoke::run( $revoke_until );
		}
		delete_option( \Pixonaut\WooCommerce\Schema::COMMIT_FAILURES_OPTION );
		delete_option( \Pixonaut\WooCommerce\Schema::COMMIT_FAILURES_LOCK_OPTION );
		// Kept as a user option, under the site's table prefix.
		delete_metadata( 'user', 0, $wpdb->get_blog_prefix() . \Pixonaut\WooCommerce\Schema::CONNECT_NOTICE_DISMISSED_META, '', true );
		delete_option( \Pixonaut\WooCommerce\Schema::VERSION_OPTION );
		delete_option( \Pixonaut\WooCommerce\Schema::MIGRATION_LOCK_OPTION );
		delete_option( \Pixonaut\WooCommerce\Schema::API_DEPRECATIONS_OPTION );
		delete_option( \Pixonaut\WooCommerce\Schema::SETTINGS_OPTION );
		delete_option( \Pixonaut\WooCommerce\Schema::NOTICES_OPTION );
		delete_option( \Pixonaut\WooCommerce\Schema::CONNECTION_OPTION );
		delete_option( \Pixonaut\WooCommerce\Schema::CONNECTION_LOCK_OPTION );
		delete_option( \Pixonaut\WooCommerce\Schema::WEBHOOK_PING_OPTION );
		delete_option( \Pixonaut\WooCommerce\Schema::WEBHOOK_EVENT_TYPES_OPTION );
		delete_option( \Pixonaut\WooCommerce\Schema::STORE_ACCESS_OPTION );
		delete_option( \Pixonaut\WooCommerce\Schema::EVENTS_CURSOR_OPTION );
		delete_option( \Pixonaut\WooCommerce\Schema::EVENTS_BASELINE_OPTION );
		delete_option( \Pixonaut\WooCommerce\Schema::VARIANTS_BACKFILL_OPTION );
		delete_option( \Pixonaut\WooCommerce\Schema::DESIGNER_LINK_WITHDRAWALS_OPTION );
		delete_option( \Pixonaut\WooCommerce\Schema::WORKSPACE_SETTINGS_OPTION );
		delete_option( \Pixonaut\WooCommerce\Schema::CATALOGUE_CACHE_GENERATION_OPTION );
		delete_option( \Pixonaut\WooCommerce\Schema::REQUEST_LOG_OPTION );
		delete_option( \Pixonaut\WooCommerce\Schema::PICTURE_GENERATION_OPTION );
		delete_option( \Pixonaut\WooCommerce\Schema::PICTURES_BACKFILL_OPTION );
		// Cached catalogue answers and proof images expire on their own; this removes the ones still waiting to.
		foreach ( array( \Pixonaut\WooCommerce\Schema::CATALOGUE_CACHE_PREFIX, \Pixonaut\WooCommerce\Schema::PROOF_CACHE_PREFIX ) as $pixonaut_wc_cache_prefix ) {
			$wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
				$wpdb->prepare(
					"DELETE FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s",
					$wpdb->esc_like( '_transient_' . $pixonaut_wc_cache_prefix ) . '%',
					$wpdb->esc_like( '_transient_timeout_' . $pixonaut_wc_cache_prefix ) . '%'
				)
			);
		}
		$wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->prepare(
				"DELETE FROM {$wpdb->options} WHERE option_name LIKE %s",
				$wpdb->esc_like( \Pixonaut\WooCommerce\Schema::ERASED_CART_DESIGN_PREFIX ) . '%'
			)
		);
		delete_metadata( 'user', 0, \Pixonaut\WooCommerce\Schema::CONNECT_ATTEMPT_META, '', true );
		delete_metadata( 'user', 0, \Pixonaut\WooCommerce\Schema::MEMBER_LINK_ATTEMPT_META, '', true );
		// Kept as user options, under the site's table prefix.
		delete_metadata( 'user', 0, $wpdb->get_blog_prefix() . \Pixonaut\WooCommerce\Schema::MEMBER_LINK_META, '', true );
		delete_metadata( 'user', 0, $wpdb->get_blog_prefix() . \Pixonaut\WooCommerce\Schema::MEMBER_LINK_REVOKED_META, '', true );
		foreach ( \Pixonaut\WooCommerce\Schema::UPDATE_STATE_OPTIONS as $pixonaut_wc_update_state_option ) {
			delete_site_option( $pixonaut_wc_update_state_option );
		}
		wp_clear_scheduled_hook( \Pixonaut\WooCommerce\Schema::UPDATE_CHECK_CRON_HOOK );
		// Action Scheduler is WooCommerce's: gone when WooCommerce is, and without tables on a site of a network that never ran it.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- once per site, at uninstall.
		if ( function_exists( 'as_unschedule_all_actions' ) && null !== $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $wpdb->prefix . 'actionscheduler_actions' ) ) ) ) {
			as_unschedule_all_actions( '', array(), \Pixonaut\WooCommerce\Schema::ACTION_GROUP );
		}
		$GLOBALS['wpdb']->query( 'DROP TABLE IF EXISTS ' . $GLOBALS['wpdb']->prefix . \Pixonaut\WooCommerce\Schema::WEBHOOK_EVENTS_TABLE ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared -- the plugin's own table; the name is a constant.
		// Order item meta stays: it is part of each order's history.
		delete_post_meta_by_key( \Pixonaut\WooCommerce\Schema::PRODUCT_CONFIG_META );
		delete_post_meta_by_key( \Pixonaut\WooCommerce\Schema::PRODUCT_TEMPLATE_INDEX_META );
		delete_post_meta_by_key( \Pixonaut\WooCommerce\Schema::PRODUCT_BLANK_INDEX_META );
		delete_post_meta_by_key( \Pixonaut\WooCommerce\Schema::PRODUCT_VARIANT_META );
		delete_post_meta_by_key( \Pixonaut\WooCommerce\Schema::VARIATION_PRINT_SIZE_META );
		delete_post_meta_by_key( \Pixonaut\WooCommerce\Schema::PRODUCT_MOVED_ATTRIBUTES_META );
		// A product whose image is its Pixonaut picture gets back the image it had before; one the store set since stays.
		// By the meta rows, whatever the product's status: a product in the trash may be restored later.
		$pixonaut_wc_previous_batch = 100; // Products read per query.
		do {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- once, at uninstall; each row read is deleted below.
			$pixonaut_wc_products = $GLOBALS['wpdb']->get_col( $GLOBALS['wpdb']->prepare( "SELECT DISTINCT post_id FROM {$GLOBALS['wpdb']->postmeta} WHERE meta_key = %s ORDER BY post_id LIMIT %d", \Pixonaut\WooCommerce\Schema::PRODUCT_PREVIOUS_IMAGE_META, $pixonaut_wc_previous_batch ) );
			foreach ( $pixonaut_wc_products as $pixonaut_wc_product ) {
				$pixonaut_wc_product  = (int) $pixonaut_wc_product;
				$pixonaut_wc_image    = (int) get_post_meta( $pixonaut_wc_product, '_thumbnail_id', true );
				$pixonaut_wc_previous = (int) get_post_meta( $pixonaut_wc_product, \Pixonaut\WooCommerce\Schema::PRODUCT_PREVIOUS_IMAGE_META, true );
				delete_post_meta( $pixonaut_wc_product, \Pixonaut\WooCommerce\Schema::PRODUCT_PREVIOUS_IMAGE_META );
				if ( $pixonaut_wc_image > 0 && ! in_array( (string) $pixonaut_wc_product, get_post_meta( $pixonaut_wc_image, \Pixonaut\WooCommerce\Schema::PICTURE_OF_META, false ), true ) ) {
					continue;
				}
				if ( $pixonaut_wc_previous > 0 && null !== get_post( $pixonaut_wc_previous ) ) {
					update_post_meta( $pixonaut_wc_product, '_thumbnail_id', $pixonaut_wc_previous );
				} else {
					delete_post_meta( $pixonaut_wc_product, '_thumbnail_id' );
				}
			}
			$pixonaut_wc_full = count( $pixonaut_wc_products ) === $pixonaut_wc_previous_batch;
		} while ( $pixonaut_wc_full );
		// The products' Pixonaut pictures are the plugin's own attachments; a product's own image stays.
		// In batches: a large catalogue has one per product and variation.
		$pixonaut_wc_picture_batch = 100; // Attachments read, then deleted, per query.
		do {
			$pixonaut_wc_pictures = get_posts(
				array(
					'post_type'      => 'attachment',
					'post_status'    => 'any',
					'posts_per_page' => $pixonaut_wc_picture_batch,
					'fields'         => 'ids',
					'meta_key'       => \Pixonaut\WooCommerce\Schema::PICTURE_OF_META, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- once, at uninstall.
				)
			);
			$pixonaut_wc_deleted  = 0;
			foreach ( $pixonaut_wc_pictures as $pixonaut_wc_picture ) {
				// A picture in the gallery of a product showing it leaves it; the gallery's other images stay.
				foreach ( get_post_meta( (int) $pixonaut_wc_picture, \Pixonaut\WooCommerce\Schema::PICTURE_OF_META, false ) as $pixonaut_wc_of ) {
					$pixonaut_wc_gallery = explode( ',', (string) get_post_meta( (int) $pixonaut_wc_of, '_product_image_gallery', true ) );
					if ( in_array( (string) $pixonaut_wc_picture, $pixonaut_wc_gallery, true ) ) {
						update_post_meta( (int) $pixonaut_wc_of, '_product_image_gallery', implode( ',', array_diff( $pixonaut_wc_gallery, array( (string) $pixonaut_wc_picture ) ) ) );
					}
				}
				$pixonaut_wc_deleted += wp_delete_attachment( (int) $pixonaut_wc_picture, true ) ? 1 : 0;
			}
			// A batch none of which could be deleted would be read again forever.
		} while ( $pixonaut_wc_deleted > 0 );
		delete_post_meta_by_key( \Pixonaut\WooCommerce\Schema::PRODUCT_PICTURE_META );
		delete_post_meta_by_key( \Pixonaut\WooCommerce\Schema::PRODUCT_PICTURE_GENERATION_META );
		delete_post_meta_by_key( \Pixonaut\WooCommerce\Schema::PRODUCT_SHOWCASE_IMAGES_META );
		delete_post_meta_by_key( \Pixonaut\WooCommerce\Schema::PICTURE_VIEW_META );
		delete_post_meta_by_key( \Pixonaut\WooCommerce\Schema::PRODUCT_KEEPS_IMAGE_META );
		// Pixonaut withdrew the links when the store disconnected, or does when it is removed in the studio.
		delete_post_meta_by_key( \Pixonaut\WooCommerce\Schema::DESIGNER_LINKS_META );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- options by prefix, once at uninstall.
		$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s", $wpdb->esc_like( \Pixonaut\WooCommerce\Schema::REPORT_THROTTLE_OPTION_PREFIX ) . '%' ) );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- options by prefix, once at uninstall.
		$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s", $wpdb->esc_like( \Pixonaut\WooCommerce\Schema::PROOF_LOCK_OPTION_PREFIX ) . '%' ) );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- options by prefix, once at uninstall.
		$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s", $wpdb->esc_like( \Pixonaut\WooCommerce\Schema::DESIGNER_LINK_LOCK_OPTION_PREFIX ) . '%' ) );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- options by prefix, once at uninstall.
		$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s", $wpdb->esc_like( \Pixonaut\WooCommerce\Schema::PICTURE_LOCK_OPTION_PREFIX ) . '%' ) );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- options by prefix, once at uninstall.
		$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s", $wpdb->esc_like( \Pixonaut\WooCommerce\Schema::PICTURE_RUNNER_OPTION_PREFIX ) . '%' ) );
	}
}

if ( is_multisite() ) {
	// Every site, whether or not the plugin was active on it: each may have been connected once.
	foreach ( get_sites(
		array(
			'fields' => 'ids',
			'number' => 0,
		)
	) as $pixonaut_wc_site ) {
		switch_to_blog( (int) $pixonaut_wc_site );
		pixonaut_wc_uninstall_site( $pixonaut_wc_revoke_until );
		restore_current_blog();
	}
} else {
	pixonaut_wc_uninstall_site( $pixonaut_wc_revoke_until );
}
