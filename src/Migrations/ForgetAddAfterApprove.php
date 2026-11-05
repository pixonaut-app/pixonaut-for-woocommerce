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
 * Returning from the hosted designer no longer adds to the cart, so nothing
 * reads the setting.
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
	 * @throws MigrationPostponed When the setting is still there: nothing reads
	 *                            it, so the store carries on while the runner
	 *                            tries again later.
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
