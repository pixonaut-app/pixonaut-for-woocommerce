<?php
/**
 * Integration suite bootstrap: the WP test suite with WooCommerce and the
 * plugin loaded. PIXONAUT_WC_HPOS=1 runs it with HPOS as the order store.
 *
 * @package Pixonaut\WooCommerce
 */

declare(strict_types=1);

$pixonaut_plugin_root = dirname( __DIR__, 2 );

require_once $pixonaut_plugin_root . '/vendor/autoload.php';

$pixonaut_tests_dir = getenv( 'WP_TESTS_DIR' );
if ( false === $pixonaut_tests_dir || '' === $pixonaut_tests_dir ) {
	$pixonaut_tests_dir = '/wordpress-phpunit';
}

/*
 * The WP test suite drops and reinstalls every table under its prefix, and
 * the e2e site lives in the same database under `wp_`. wp-env's
 * wp-tests-config.php reads the prefix from this variable.
 */
const PIXONAUT_INTEGRATION_TABLE_PREFIX = 'wptests_';
$pixonaut_prefix                        = getenv( 'WORDPRESS_TABLE_PREFIX' );
if ( false !== $pixonaut_prefix && '' !== $pixonaut_prefix && PIXONAUT_INTEGRATION_TABLE_PREFIX !== $pixonaut_prefix ) {
	fwrite( STDERR, "Refusing to run: WORDPRESS_TABLE_PREFIX must be unset or '" . PIXONAUT_INTEGRATION_TABLE_PREFIX . "', not '$pixonaut_prefix'.\n" ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite
	exit( 1 );
}
putenv( 'WORDPRESS_TABLE_PREFIX=' . PIXONAUT_INTEGRATION_TABLE_PREFIX ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.runtime_configuration_putenv -- read by wp-tests-config.php.

require_once $pixonaut_tests_dir . '/includes/functions.php';

tests_add_filter(
	'muplugins_loaded',
	static function () use ( $pixonaut_plugin_root ): void {
		require_once WP_PLUGIN_DIR . '/woocommerce/woocommerce.php';
		require_once $pixonaut_plugin_root . '/pixonaut-for-woocommerce.php';
	}
);

// The tests-cli user cannot write to wp-content, so uploads go to a temp dir.
tests_add_filter(
	'upload_dir',
	static function ( array $dirs ): array {
		$base = sys_get_temp_dir() . '/pixonaut-wc-uploads';
		wp_mkdir_p( $base . $dirs['subdir'] );
		$dirs['basedir'] = $base;
		$dirs['path']    = $base . $dirs['subdir'];
		return $dirs;
	}
);

tests_add_filter(
	'setup_theme',
	static function (): void {
		WC_Install::install();
		// On a fresh database WooCommerce stores role capabilities without
		// updating the roles already loaded: reload them before any test runs.
		wp_roles()->for_site();
		$hpos = '1' === getenv( 'PIXONAUT_WC_HPOS' );
		// Option names from WooCommerce's CustomOrdersTableController and DataSynchronizer.
		update_option( 'woocommerce_custom_orders_table_enabled', $hpos ? 'yes' : 'no' );
		update_option( 'woocommerce_custom_orders_table_data_sync_enabled', 'no' );
	}
);

// Every hook of the plugin's that fires, for the hook inventory test.
tests_add_filter( 'all', array( \Pixonaut\WooCommerce\Tests\Support\Hooks\HookRecorder::class, 'record' ) );

// Tests reach local fakes only: every other host is refused before any request
// leaves, unless a test's own filter already answered it (FakeGitHub).
const PIXONAUT_TEST_ALLOWED_HOSTS = array( 'localhost', '127.0.0.1', '::1' );
tests_add_filter(
	'pre_http_request',
	static function ( $response, array $args, string $url ) {
		$host = wp_parse_url( $url, PHP_URL_HOST );
		if ( false !== $response || in_array( $host, PIXONAUT_TEST_ALLOWED_HOSTS, true ) ) {
			return $response;
		}
		return new WP_Error( 'pixonaut_test_host_blocked', sprintf( 'Tests may not reach %s.', $url ) );
	},
	PHP_INT_MAX,
	3
);

require_once $pixonaut_tests_dir . '/includes/bootstrap.php';
