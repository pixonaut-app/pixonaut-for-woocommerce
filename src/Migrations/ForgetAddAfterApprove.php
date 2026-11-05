<?php
/**
 * Forgets the setting that added a design to the cart on returning from the hosted designer.
 *
 * @package Pixonaut\WooCommerce
 */

declare(strict_types=1);

namespace Pixonaut\WooCommerce\Migrations;

use Pixonaut\WooCommerce\Settings\Settings;

/**
 * Removed the setting while returning from the hosted designer could not tell
 * a customer's own trip from another's. The setting is back, gated on each
 * trip's state, but this migration stays as it ran: a store that had it on
 * turns it on again, whether it ran this migration before or after the
 * setting came back, so every store starts from the same default.
 */
final class ForgetAddAfterApprove implements Migration {

	/**
	 * The setting's key while it existed.
	 */
	private const SETTING = 'add_after_approve';

	public function version(): int {
		return 9;
	}

	/**
	 * @throws MigrationPostponed When the setting is still there: the store
	 *                            carries on with it while the runner tries
	 *                            again later.
	 */
	public function up(): void {
		$settings = get_option( Settings::OPTION );
		if ( self::keeps_setting( $settings ) ) {
			unset( $settings[ self::SETTING ] );
			update_option( Settings::OPTION, $settings );
		}
		if ( self::keeps_setting( get_option( Settings::OPTION ) ) ) {
			throw new MigrationPostponed( sprintf( 'The setting %s could not be removed.', self::SETTING ) );
		}
	}

	/**
	 * @param mixed $settings The stored settings.
	 * @phpstan-assert-if-true array<mixed> $settings
	 */
	private static function keeps_setting( mixed $settings ): bool {
		return is_array( $settings ) && array_key_exists( self::SETTING, $settings );
	}
}
