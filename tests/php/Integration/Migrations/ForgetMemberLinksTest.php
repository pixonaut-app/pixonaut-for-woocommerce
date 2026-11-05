<?php
/**
 * @package Pixonaut\WooCommerce
 */

declare(strict_types=1);

namespace Pixonaut\WooCommerce\Tests\Integration\Migrations;

use Pixonaut\WooCommerce\Migrations\ForgetMemberLinks;
use Pixonaut\WooCommerce\Migrations\MigrationRunner;
use Pixonaut\WooCommerce\Schema;
use Pixonaut\WooCommerce\Settings\Settings;
use Pixonaut\WooCommerce\Tests\Support\RecordingLogger;
use WP_UnitTestCase;

/**
 * Upgrading forgets what the store kept for Pixel in the admin, and nothing else.
 */
final class ForgetMemberLinksTest extends WP_UnitTestCase {

	private const PREVIOUS_VERSION = 7;

	private const ALLOWED_ABILITIES_SETTING = 'pixel_allowed_abilities';

	public function test_every_user_s_link_and_the_setting_are_forgotten_and_other_settings_stay(): void {
		$first  = self::factory()->user->create();
		$second = self::factory()->user->create();
		$this->assertIsInt( $first );
		$this->assertIsInt( $second );
		update_user_option( $first, Schema::MEMBER_LINK_META, 'sealed' );
		update_user_option( $second, Schema::MEMBER_LINK_REVOKED_META, time() );
		update_user_meta( $second, Schema::MEMBER_LINK_ATTEMPT_META, array( 'state_hash' => 'abc' ) );
		update_user_meta( $first, Schema::CONNECT_ATTEMPT_META, array( 'state_hash' => 'kept' ) );
		update_option(
			Settings::OPTION,
			array(
				self::ALLOWED_ABILITIES_SETTING => array( 'pixonaut-for-woocommerce/link-product' ),
				Settings::SHOW_FIELD_VALUES     => true,
			)
		);

		( new ForgetMemberLinks() )->up();

		$this->assertFalse( get_user_option( Schema::MEMBER_LINK_META, $first ) );
		$this->assertFalse( get_user_option( Schema::MEMBER_LINK_REVOKED_META, $second ) );
		$this->assertSame( '', get_user_meta( $second, Schema::MEMBER_LINK_ATTEMPT_META, true ) );
		$this->assertSame( array( 'state_hash' => 'kept' ), get_user_meta( $first, Schema::CONNECT_ATTEMPT_META, true ) );
		$this->assertSame( array( Settings::SHOW_FIELD_VALUES => true ), get_option( Settings::OPTION ) );
	}

	public function test_a_member_key_that_stays_leaves_the_migration_pending_until_it_is_gone(): void {
		global $wpdb;
		$user = self::factory()->user->create();
		$this->assertIsInt( $user );
		update_user_option( $user, Schema::MEMBER_LINK_META, 'sealed' );
		$kept_key = $wpdb->get_blog_prefix() . Schema::MEMBER_LINK_META;
		$refuse   = static fn ( ?bool $check, int $object_id, string $meta_key ): ?bool => $kept_key === $meta_key ? false : $check;
		add_filter( 'delete_user_metadata', $refuse, 10, 3 );
		$logger   = new RecordingLogger();
		$runner   = self::runner_at_the_previous_version( $logger );

		$this->assert_run_postpones( $runner, $logger, $kept_key );

		remove_filter( 'delete_user_metadata', $refuse, 10 );
		self::let_the_lock_expire();

		$this->assertSame( 1, $runner->run() );
		$this->assertSame( ( new ForgetMemberLinks() )->version(), $runner->current_version() );
		$this->assertFalse( get_user_option( Schema::MEMBER_LINK_META, $user ) );
	}

	public function test_a_setting_that_stays_leaves_the_migration_pending_until_it_is_gone(): void {
		$stored = array( self::ALLOWED_ABILITIES_SETTING => array( 'pixonaut-for-woocommerce/link-product' ) );
		update_option( Settings::OPTION, $stored );
		// The filter answering the old value makes update_option() write nothing.
		$refuse = static fn (): array => $stored;
		add_filter( 'pre_update_option_' . Settings::OPTION, $refuse );
		$logger = new RecordingLogger();
		$runner = self::runner_at_the_previous_version( $logger );

		$this->assert_run_postpones( $runner, $logger, self::ALLOWED_ABILITIES_SETTING );

		remove_filter( 'pre_update_option_' . Settings::OPTION, $refuse );
		self::let_the_lock_expire();

		$this->assertSame( 1, $runner->run() );
		$this->assertSame( array(), get_option( Settings::OPTION ) );
	}

	public function test_it_succeeds_with_nothing_left_to_forget(): void {
		delete_option( Settings::OPTION );
		$runner = self::runner_at_the_previous_version();

		$this->assertSame( 1, $runner->run() );
		$this->assertSame( ( new ForgetMemberLinks() )->version(), $runner->current_version() );

		// Run again on data that is already clean.
		( new ForgetMemberLinks() )->up();
		$this->assertFalse( get_option( Settings::OPTION ) );
	}

	private static function runner_at_the_previous_version( RecordingLogger $logger = new RecordingLogger() ): MigrationRunner {
		update_option( MigrationRunner::VERSION_OPTION, self::PREVIOUS_VERSION );
		return new MigrationRunner( array( new ForgetMemberLinks() ), $logger );
	}

	private static function let_the_lock_expire(): void {
		update_option( MigrationRunner::LOCK_OPTION, time() - MigrationRunner::LOCK_TTL_SECONDS );
	}

	/**
	 * The run does not throw, so the request that made it carries on, and it stays
	 * pending without trying again until the lock expires.
	 *
	 * @param MigrationRunner $runner At the previous version.
	 * @param RecordingLogger $logger The runner's logger.
	 * @param string          $naming What the logged error names.
	 */
	private function assert_run_postpones( MigrationRunner $runner, RecordingLogger $logger, string $naming ): void {
		$this->assertSame( 0, $runner->run() );
		$this->assertSame( self::PREVIOUS_VERSION, $runner->current_version() );
		$this->assertCount( 1, $logger->entries );
		$this->assertSame( 'error', $logger->entries[0]['level'] );
		$this->assertStringContainsString( $naming, $logger->entries[0]['message'] );

		$this->assertSame( 0, $runner->run() );
		$this->assertCount( 1, $logger->entries );
	}
}
