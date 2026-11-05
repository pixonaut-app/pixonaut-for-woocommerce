<?php
/**
 * @package Pixonaut\WooCommerce
 */

declare(strict_types=1);

namespace Pixonaut\WooCommerce\Tests\Integration\Migrations;

use Pixonaut\WooCommerce\Migrations\ForgetAddAfterApprove;
use Pixonaut\WooCommerce\Migrations\MigrationPostponed;
use Pixonaut\WooCommerce\Migrations\Migrations;
use Pixonaut\WooCommerce\Settings\Settings;
use WP_UnitTestCase;

/**
 * The setting that added a design to the cart on return goes from stored settings.
 */
final class ForgetAddAfterApproveTest extends WP_UnitTestCase {

	private const SETTING = 'add_after_approve';

	public function test_the_setting_is_forgotten_and_other_settings_stay(): void {
		update_option(
			Settings::OPTION,
			array(
				self::SETTING               => true,
				Settings::SHOW_FIELD_VALUES => true,
			)
		);

		( new ForgetAddAfterApprove() )->up();

		$this->assertSame( array( Settings::SHOW_FIELD_VALUES => true ), get_option( Settings::OPTION ) );
	}

	public function test_a_setting_that_stays_postpones_the_migration_until_it_is_gone(): void {
		$stored = array( self::SETTING => true );
		update_option( Settings::OPTION, $stored );
		// The filter answering the old value makes update_option() write nothing.
		$refuse = static fn (): array => $stored;
		add_filter( 'pre_update_option_' . Settings::OPTION, $refuse );

		try {
			( new ForgetAddAfterApprove() )->up();
			$this->fail( 'the migration should have been postponed' );
		} catch ( MigrationPostponed $postponed ) {
			$this->assertStringContainsString( self::SETTING, $postponed->getMessage() );
		} finally {
			remove_filter( 'pre_update_option_' . Settings::OPTION, $refuse );
		}

		( new ForgetAddAfterApprove() )->up();
		$this->assertSame( array(), get_option( Settings::OPTION ) );
	}

	public function test_it_succeeds_with_nothing_left_to_forget(): void {
		delete_option( Settings::OPTION );

		( new ForgetAddAfterApprove() )->up();

		$this->assertFalse( get_option( Settings::OPTION ) );
	}

	public function test_it_is_the_last_migration(): void {
		$all = Migrations::all();

		$this->assertInstanceOf( ForgetAddAfterApprove::class, end( $all ) );
	}
}
