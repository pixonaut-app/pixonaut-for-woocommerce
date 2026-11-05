<?php
/**
 * @package Pixonaut\WooCommerce
 */

declare(strict_types=1);

namespace Pixonaut\WooCommerce\Tests\Integration\Bridge;

use Pixonaut\WooCommerce\Api\Hosts;
use Pixonaut\WooCommerce\Connection\Cipher;
use Pixonaut\WooCommerce\Connection\ConnectionStore;
use Pixonaut\WooCommerce\Connection\StoredConnection;
use Pixonaut\WooCommerce\Plugin;
use Pixonaut\WooCommerce\Tests\Support\HttpRecorder;
use WP_UnitTestCase;

/**
 * The functions another plugin reads the store's connection and abilities
 * with.
 */
final class BridgeTest extends WP_UnitTestCase {

	private const INTEGRATION_KEY = 'ik_live_fixture_bridge';

	private Plugin $plugin;

	private HttpRecorder $recorder;

	public function set_up(): void {
		parent::set_up();
		$this->plugin   = Plugin::boot( dirname( __DIR__, 4 ) . '/pixonaut-for-woocommerce.php' );
		$this->recorder = ( new HttpRecorder( Hosts::server_api() ) )->start();
		$this->plugin->connection_store()->save( self::connection() );
	}

	public function tear_down(): void {
		$this->recorder->stop();
		$this->plugin->connection_store()->clear();
		parent::tear_down();
	}

	public function test_the_functions_are_defined_once_the_plugin_has_loaded_with_woocommerce(): void {
		foreach ( array( 'pixonaut_wc_abilities', 'pixonaut_wc_status' ) as $function ) {
			$this->assertTrue( function_exists( $function ), $function );
		}
	}

	public function test_the_abilities_are_the_plugin_s_and_woocommerce_s_with_no_cap_and_a_filter(): void {
		$abilities = pixonaut_wc_abilities();

		$this->assertContains( 'pixonaut-for-woocommerce/list-templates', $abilities );
		$this->assertSame( array(), array_filter( $abilities, static fn ( string $name ): bool => ! str_starts_with( $name, 'pixonaut-for-woocommerce/' ) && ! str_starts_with( $name, 'woocommerce/product' ) && ! str_starts_with( $name, 'woocommerce/order' ) ) );

		add_filter( 'pixonaut_wc_bridge_abilities', static fn ( array $names ): array => array_values( array_diff( $names, array( 'pixonaut-for-woocommerce/list-templates' ) ) ) );

		$this->assertNotContains( 'pixonaut-for-woocommerce/list-templates', pixonaut_wc_abilities() );
	}

	public function test_the_status_names_the_workspace_and_never_a_key(): void {
		$status = pixonaut_wc_status();

		$this->assertSame(
			array(
				'connected'    => true,
				'paused'       => null,
				'workspace'    => 'Fixture Prints',
				'workspace_id' => 'tenant_fixture',
				'environment'  => 'live',
				'reason'       => null,
			),
			$status
		);
		$this->assertStringNotContainsString( self::INTEGRATION_KEY, (string) wp_json_encode( $status ) );
	}

	public function test_the_status_gives_the_first_reason_a_request_cannot_reach_pixonaut(): void {
		( new ConnectionStore( Cipher::for_site() ) )->pause( 'unauthorized' );
		$this->assertSame( 'paused_unauthorized', pixonaut_wc_status()['reason'] );

		$this->plugin->connection_store()->clear();
		$this->assertSame( 'not_connected', pixonaut_wc_status()['reason'] );
	}

	private static function connection(): StoredConnection {
		return new StoredConnection(
			'site_fixture',
			home_url(),
			1767225600,
			'tenant_fixture',
			'Fixture Prints',
			StoredConnection::LIVE,
			'conn_main',
			self::INTEGRATION_KEY,
			'ik_id',
			'WooCommerce · example.org',
			'pk_live_fixture_bridge',
			'pk_id',
			array( 'http://example.org' ),
			'wh_fixture_main',
			'http://example.org/wp-json/pixonaut/v1/webhook',
			'whsec_fixture_bridge'
		);
	}
}
