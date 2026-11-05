<?php
/**
 * @package Pixonaut\WooCommerce
 */

declare(strict_types=1);

namespace Pixonaut\WooCommerce\Tests\Integration;

use Pixonaut\WooCommerce\Admin\ConnectNotice;
use Pixonaut\WooCommerce\Api\Hosts;
use Pixonaut\WooCommerce\Cart\CommitFailures;
use Pixonaut\WooCommerce\Connection\Cipher;
use Pixonaut\WooCommerce\Connection\ConnectionStore;
use Pixonaut\WooCommerce\Schema;
use Pixonaut\WooCommerce\Tests\Integration\Storefront\StorefrontTest;
use Pixonaut\WooCommerce\Tests\Support\HttpRecorder;
use WP_UnitTestCase;

/**
 * Runs only on a multisite install: `pnpm test:integration` runs this group
 * a second time with `WP_MULTISITE=1`.
 *
 * @group multisite
 */
final class UninstallMultisiteTest extends WP_UnitTestCase {

	/**
	 * Loopback, so the test bootstrap's host guard lets it through to the recorder.
	 */
	private const BASE_URL = 'http://127.0.0.1:9';

	private HttpRecorder $recorder;

	public function set_up(): void {
		parent::set_up();
		if ( ! is_multisite() ) {
			$this->markTestSkipped( 'Needs a multisite install (WP_MULTISITE=1).' );
		}
		$this->recorder = ( new HttpRecorder( self::BASE_URL ) )->start();
		Hosts::read_constants_with( static fn ( string $name ): string => Hosts::SERVER_API_CONSTANT === $name ? self::BASE_URL : '' );
	}

	public function tear_down(): void {
		if ( is_multisite() ) {
			$this->recorder->stop();
			Hosts::read_constants_with( null );
		}
		parent::tear_down();
	}

	/**
	 * What the plugin keeps on the current site, connected.
	 *
	 * @param int $user_id A user who dismissed the connect notice.
	 */
	private static function store_plugin_data( int $user_id ): void {
		StorefrontTest::connect( new ConnectionStore( Cipher::for_site() ) );
		update_option( Schema::SETTINGS_OPTION, array( 'approval_mode' => 'checkbox' ) );
		update_option(
			Schema::NOTICES_OPTION,
			array(
				CommitFailures::NOTICE => array(
					'message' => 'Failing.',
					'type'    => 'error',
				),
			)
		);
		update_option( CommitFailures::OPTION, array( 'failures' => array( time() ) ) );
		update_user_option( $user_id, ConnectNotice::META, '1' );
	}

	public function test_uninstall_revokes_and_cleans_every_site(): void {
		$user  = self::factory()->user->create();
		$user  = is_int( $user ) ? $user : 0;
		$other = self::factory()->blog->create();
		$other = is_int( $other ) ? $other : 0;
		$sites = array( get_current_blog_id(), $other );
		foreach ( $sites as $site ) {
			switch_to_blog( $site );
			self::store_plugin_data( $user );
			restore_current_blog();
			$this->recorder->raw( 204, '', 'application/json' );
		}

		if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
			define( 'WP_UNINSTALL_PLUGIN', 'pixonaut-for-woocommerce/pixonaut-for-woocommerce.php' );
		}
		require dirname( __DIR__, 3 ) . '/uninstall.php';

		$this->assertSame( array( 'DELETE', 'DELETE' ), array_column( $this->recorder->requests, 'method' ) );
		foreach ( $sites as $site ) {
			switch_to_blog( $site );
			foreach ( array( Schema::CONNECTION_OPTION, Schema::SETTINGS_OPTION, Schema::NOTICES_OPTION, CommitFailures::OPTION ) as $option ) {
				$this->assertFalse( get_option( $option ), sprintf( '%s on site %d', $option, $site ) );
			}
			$this->assertFalse( get_user_option( ConnectNotice::META, $user ), sprintf( 'The dismissed notice on site %d', $site ) );
			restore_current_blog();
		}
	}
}
