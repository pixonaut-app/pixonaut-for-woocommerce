<?php
/**
 * @package Pixonaut\WooCommerce
 */

declare(strict_types=1);

namespace Pixonaut\WooCommerce\Tests\Integration\ShopTools;

use Pixonaut\WooCommerce\Connection\Connection;
use Pixonaut\WooCommerce\Connection\StoreAccess;
use Pixonaut\WooCommerce\Connection\StoredConnection;
use Pixonaut\WooCommerce\Plugin;
use Pixonaut\WooCommerce\Tests\Integration\Abilities\ThemeSettingsAbilitiesTest;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;
use WP_UnitTestCase;

/**
 * The shop tools that read and change the active theme's settings, called
 * on the shop tool route as Pixonaut calls them, with the store's
 * credential and no user.
 */
final class ThemeSettingsShopToolsTest extends WP_UnitTestCase {

	private const ROUTE = '/pixonaut/v1/shop-tools/';

	private const SECRET = 'a-store-credential';

	private const GET = 'get-theme-settings';

	private const UPDATE = 'update-theme-settings';

	private const OK = 200;

	private const UNPROCESSABLE = 422;

	private const SITE = 'site_fixture';

	private const LAYOUT = 'example_theme_layout';

	private const BANNER = 'example_theme_banner';

	/**
	 * When the fixture's connection was made, as Unix time.
	 */
	private const CONNECTED_AT = 1790000000;

	private Plugin $plugin;

	public function set_up(): void {
		parent::set_up();
		$this->plugin = Plugin::boot( dirname( __DIR__, 4 ) . '/pixonaut-for-woocommerce.php' );
		add_filter( 'pixonaut_wc_theme_settings', array( ThemeSettingsAbilitiesTest::class, 'declare_settings' ) );
		global $wp_rest_server;
		$wp_rest_server = new WP_REST_Server();
		do_action( 'rest_api_init', $wp_rest_server );
		$this->plugin->connection_store()->save(
			StoredConnection::from_array(
				array(
					'site'                  => self::SITE,
					'home_url'              => Connection::site_url(),
					'connected_at'          => self::CONNECTED_AT,
					'tenant_id'             => 'tenant',
					'tenant_name'           => 'Acme',
					'environment'           => 'live',
					'connection_id'         => 'sc_1',
					'integration_key_id'    => 'key-id',
					'integration_key'       => 'ik_live_0123456789abcdef01234567',
					'integration_key_label' => 'Shop',
					'publishable_key_id'    => 'pub-id',
					'publishable_key'       => 'pk_live_89abcdef0123456789abcdef',
					'allowed_origins'       => array( 'http://example.org' ),
					'webhook_id'            => 'hook',
					'webhook_url'           => 'http://example.org/hook',
					'webhook_secret'        => 'whsec_0123456789abcdef0123456789abcdef',
				)
			)
		);
		update_option(
			StoreAccess::OPTION,
			array(
				'site'       => self::SITE,
				'hash'       => hash( 'sha256', self::SECRET ),
				'cut'        => false,
				'changed_at' => 0,
			),
			false
		);
		wp_set_current_user( 0 );
	}

	public function tear_down(): void {
		$this->plugin->connection_store()->clear();
		delete_option( StoreAccess::OPTION );
		delete_option( self::LAYOUT );
		delete_option( self::BANNER );
		global $wp_rest_server;
		$wp_rest_server = null;
		parent::tear_down();
	}

	public function test_pixonaut_reads_the_theme_and_its_settings_with_the_store_credential_alone(): void {
		update_option( self::LAYOUT, 'narrow' );

		$read = $this->succeed( self::GET, array() );

		$this->assertSame( wp_get_theme()->get_stylesheet(), $read['theme']['stylesheet'] );
		$this->assertSame( array( self::LAYOUT, self::BANNER, 'example_theme_note' ), array_column( $read['settings'], 'key' ) );
		$this->assertSame( 'narrow', $read['settings'][0]['value'] );
	}

	public function test_pixonaut_changes_and_deletes_the_settings_named(): void {
		update_option( self::LAYOUT, 'narrow' );

		$changed = $this->succeed(
			self::UPDATE,
			array(
				'settings' => array(
					self::LAYOUT => null,
					self::BANNER => array(
						'text' => 'Sale',
						'link' => 'https://example.org/sale',
					),
				),
			)
		);

		$this->assertFalse( get_option( self::LAYOUT ) );
		$this->assertSame( 'wide', $changed['settings'][0]['value'] );
		$this->assertSame( 'Sale', get_option( self::BANNER )['text'] );
	}

	public function test_an_unknown_setting_a_value_that_does_not_fit_or_no_setting_changes_nothing(): void {
		$answers = array(
			$this->call(
				self::UPDATE,
				array(
					'settings' => array(
						self::LAYOUT => 'narrow',
						'blogname'   => 'Shop',
					),
				)
			),
			$this->call(
				self::UPDATE,
				array(
					'settings' => array(
						self::BANNER => array(
							'text' => 'Sale',
							'link' => 'https://example.org/sale',
						),
						self::LAYOUT => 'sideways',
					),
				)
			),
			$this->call( self::UPDATE, array( 'settings' => array() ) ),
		);

		foreach ( $answers as $answer ) {
			$this->assertSame( self::UNPROCESSABLE, $answer->get_status(), (string) wp_json_encode( $answer->get_data() ) );
			$this->assertSame( 'invalid_input', $answer->get_data()['code'] );
		}
		$this->assertFalse( get_option( self::LAYOUT ) );
		$this->assertFalse( get_option( self::BANNER ) );
		$this->assertNotSame( 'Shop', get_option( 'blogname' ) );
	}

	/**
	 * Calls a shop tool as Pixonaut does.
	 *
	 * @param string       $tool  The tool.
	 * @param array<mixed> $input Its input.
	 */
	private function call( string $tool, array $input ): WP_REST_Response {
		$request = new WP_REST_Request( 'POST', self::ROUTE . $tool );
		$request->set_header( 'Content-Type', 'application/json' );
		$request->set_header( 'Authorization', 'Bearer ' . self::SECRET );
		$request->set_body( (string) wp_json_encode( (object) $input ) );
		return rest_do_request( $request );
	}

	/**
	 * @param string       $tool  The tool.
	 * @param array<mixed> $input Its input.
	 * @return array<string, mixed> What it answered.
	 */
	private function succeed( string $tool, array $input ): array {
		$answer = $this->call( $tool, $input );
		$this->assertSame( self::OK, $answer->get_status(), (string) wp_json_encode( $answer->get_data() ) );
		$data = $answer->get_data();
		$this->assertIsArray( $data );
		$this->assertIsArray( $data['result'] );
		return $data['result'];
	}
}
