<?php
/**
 * Forgets each user's link to their Pixonaut member, and the setting of what Pixel ran without asking.
 *
 * @package Pixonaut\WooCommerce
 */

declare(strict_types=1);

namespace Pixonaut\WooCommerce\Migrations;

// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- exception messages here are reasons and log text, never printed as they are: whoever shows one escapes it.

use Pixonaut\WooCommerce\Schema;
use Pixonaut\WooCommerce\Settings\Settings;

/**
 * Pixel now runs in the Pixonaut studio, so the store no longer keeps a
 * member key for any user or a list of abilities Pixel may run without
 * asking. A member key stored in the user's options goes with the rest:
 * nothing reads it any more.
 */
final class ForgetMemberLinks implements Migration {

	/**
	 * The setting's key while it existed.
	 */
	private const ALLOWED_ABILITIES_SETTING = 'pixel_allowed_abilities';

	public function version(): int {
		return 8;
	}

	/**
	 * What came back from the writes is not what tells: deleting nothing also
	 * answers false, so the rows and the setting are checked afterwards.
	 *
	 * @throws MigrationPostponed When a key or the setting is still there: nothing
	 *                            reads them, so the store carries on while the
	 *                            runner tries again later.
	 */
	public function up(): void {
		global $wpdb;
		$meta_keys = array(
			Schema::MEMBER_LINK_ATTEMPT_META,
			// Kept as user options, under the site's table prefix.
			$wpdb->get_blog_prefix() . Schema::MEMBER_LINK_META,
			$wpdb->get_blog_prefix() . Schema::MEMBER_LINK_REVOKED_META,
		);
		foreach ( $meta_keys as $meta_key ) {
			delete_metadata( 'user', 0, $meta_key, '', true );
		}

		$settings = get_option( Settings::OPTION );
		if ( self::keeps_setting( $settings ) ) {
			unset( $settings[ self::ALLOWED_ABILITIES_SETTING ] );
			update_option( Settings::OPTION, $settings );
		}

		foreach ( $meta_keys as $meta_key ) {
			$rows = $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->usermeta} WHERE meta_key = %s", $meta_key ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- the check in a migration must read the table.
			// A query that failed answers null, which says nothing is gone.
			if ( null === $rows || (int) $rows > 0 ) {
				throw new MigrationPostponed( sprintf( 'The user meta %s could not be deleted.', $meta_key ) );
			}
		}
		if ( self::keeps_setting( get_option( Settings::OPTION ) ) ) {
			throw new MigrationPostponed( sprintf( 'The setting %s could not be removed.', self::ALLOWED_ABILITIES_SETTING ) );
		}
	}

	/**
	 * @param mixed $settings The stored settings.
	 * @phpstan-assert-if-true array<mixed> $settings
	 */
	private static function keeps_setting( mixed $settings ): bool {
		return is_array( $settings ) && array_key_exists( self::ALLOWED_ABILITIES_SETTING, $settings );
	}
}
