<?php
/**
 * @package Pixonaut\WooCommerce
 */

declare(strict_types=1);

namespace Pixonaut\WooCommerce\Tests\Unit;

use Brain\Monkey\Actions;
use Brain\Monkey\Functions;
use Pixonaut\WooCommerce\Migrations\MigrationRunner;
use Pixonaut\WooCommerce\Plugin;
use Pixonaut\WooCommerce\Requirements;
use Pixonaut\WooCommerce\Tests\Support\MigrationLog;
use Pixonaut\WooCommerce\Tests\Support\RecordingMigration;

final class PluginTest extends TestCase {

	use FakeOptions;

	private const FILE = '/var/www/html/wp-content/plugins/pixonaut-for-woocommerce/pixonaut-for-woocommerce.php';

	protected function setUp(): void {
		parent::setUp();
		$this->fake_options();
		// A header without an Update URI, so booting starts no update checker.
		Functions\when( 'get_file_data' )->justReturn( array( 'update_uri' => '' ) );
	}

	public function test_boot_registers_hooks_once(): void {
		Actions\expectAdded( 'before_woocommerce_init' )->once();
		Actions\expectAdded( 'init' )->once();
		Actions\expectAdded( 'plugins_loaded' )->once();

		$first  = Plugin::boot( self::FILE );
		$second = Plugin::boot( self::FILE );

		$this->assertSame( $first, $second );
		$this->assertSame( self::FILE, $first->file() );
	}

	public function test_runs_migrations_when_woocommerce_is_active(): void {
		$log = new MigrationLog();
		$this->fake_options();
		Functions\when( 'wp_salt' )->justReturn( 'auth-key-and-salt' );
		Functions\when( 'admin_url' )->alias( static fn ( string $path = '' ): string => 'https://shop.test/wp-admin/' . $path );
		Functions\when( 'add_query_arg' )->returnArg( 2 );
		Functions\when( 'plugin_basename' )->justReturn( 'pixonaut-for-woocommerce/pixonaut-for-woocommerce.php' );
		Functions\when( 'is_admin' )->justReturn( true );
		Functions\when( 'home_url' )->justReturn( 'https://shop.test/' );
		Functions\when( 'untrailingslashit' )->alias( static fn ( string $value ): string => rtrim( $value, '/\\' ) );
		Functions\when( 'wp_parse_url' )->alias( static fn ( string $url, int $component = -1 ) => parse_url( $url, $component ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.parse_url_parse_url
		$plugin = new Plugin( self::FILE, new MigrationRunner( array( new RecordingMigration( 1, $log ) ) ), Requirements::MIN_WOOCOMMERCE . '.0' );

		do_action( 'woocommerce_loaded' );
		$plugin->on_plugins_loaded();

		$this->assertSame( array( 1 ), $log->versions );
		$this->assertSame( 1, $this->options[ MigrationRunner::VERSION_OPTION ] );
	}

	public function test_refuses_to_boot_without_woocommerce(): void {
		$log = new MigrationLog();
		Actions\expectAdded( 'admin_notices' )->once()->with( array( Requirements::class, 'woocommerce_notice' ) );
		$plugin = new Plugin( self::FILE, new MigrationRunner( array( new RecordingMigration( 1, $log ) ) ) );

		$plugin->on_plugins_loaded();

		$this->assertSame( array(), $log->versions );
	}

	public function test_refuses_to_boot_on_woocommerce_below_the_minimum(): void {
		$log = new MigrationLog();
		Actions\expectAdded( 'admin_notices' )->once()->with( array( Requirements::class, 'woocommerce_version_notice' ) );
		$plugin = new Plugin( self::FILE, new MigrationRunner( array( new RecordingMigration( 1, $log ) ) ), '10.5.2' );

		do_action( 'woocommerce_loaded' );
		$plugin->on_plugins_loaded();

		$this->assertSame( array(), $log->versions );
	}

	public function test_woocommerce_below_the_minimum_is_not_supported(): void {
		$this->assertFalse( Requirements::woocommerce_supported( '10.5.2' ) );
		$this->assertTrue( Requirements::woocommerce_supported( Requirements::MIN_WOOCOMMERCE ) );
		$this->assertTrue( Requirements::woocommerce_supported( Requirements::MIN_WOOCOMMERCE . '.0' ) );
		$this->assertTrue( Requirements::woocommerce_supported( '11.2.1' ) );
		$this->assertFalse( Requirements::woocommerce_supported( '' ) );
	}

	public function test_php_below_the_minimum_is_not_supported(): void {
		$this->assertFalse( Requirements::php_supported( '8.0.30' ) );
		$this->assertTrue( Requirements::php_supported( Requirements::MIN_PHP . '.0' ) );
		$this->assertTrue( Requirements::php_supported( '8.5.0' ) );
	}
}
