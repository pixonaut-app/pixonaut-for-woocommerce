<?php
/**
 * @package Pixonaut\WooCommerce
 */

declare(strict_types=1);

namespace Pixonaut\WooCommerce\Tests\Integration\ShopTools;

use Pixonaut\WooCommerce\Abilities\ReadPluginSettings;
use Pixonaut\WooCommerce\Abilities\ReadPricingSettings;
use Pixonaut\WooCommerce\Abilities\UpdatePricingSettings;
use Pixonaut\WooCommerce\Connection\Connection;
use Pixonaut\WooCommerce\Connection\StoreAccess;
use Pixonaut\WooCommerce\Connection\StoredConnection;
use Pixonaut\WooCommerce\Plugin;
use Pixonaut\WooCommerce\Settings\Settings;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;
use WP_UnitTestCase;

/**
 * The shop tools that read and change the plugin's settings, called on the
 * shop tool route as Pixonaut calls them, and the list of settings Pixonaut's
 * own tools are held to.
 */
final class PluginSettingsShopToolsTest extends WP_UnitTestCase {

	private const ROUTE = '/pixonaut/v1/shop-tools/';

	private const SECRET = 'a-store-credential';

	private const GET = 'get-plugin-settings';

	private const UPDATE = 'update-plugin-settings';

	private const OK = 200;

	private const UNPROCESSABLE = 422;

	private const SITE = 'site_fixture';

	/**
	 * When the fixture's connection was made, as Unix time.
	 */
	private const CONNECTED_AT = 1790000000;

	/**
	 * The settings each settings tool carries, by tool: the settings read
	 * repeats the prices tab's, which the pricing tools change. Pixonaut keeps a
	 * copy (`apps/api/test/fixtures/woocommerce-plugin-settings.json`) and
	 * fails its tests when a setting here has no shop tool of its own.
	 */
	private const CARRIED = __DIR__ . '/../../../fixtures/shop-tool-settings.json';

	private Plugin $plugin;

	public function set_up(): void {
		parent::set_up();
		$this->plugin = Plugin::boot( dirname( __DIR__, 4 ) . '/pixonaut-for-woocommerce.php' );
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
		delete_option( Settings::OPTION );
		global $wp_rest_server;
		$wp_rest_server = null;
		parent::tear_down();
	}

	public function test_pixonaut_reads_every_setting_of_the_settings_page(): void {
		$read = $this->succeed( self::GET, array() );

		$this->assertSame( array_keys( ReadPluginSettings::settings( $this->plugin->settings() ) ), array_keys( $read ) );
		$this->assertFalse( $read[ Settings::PRINT_AREA_FRAME ] );
	}

	public function test_pixonaut_changes_the_settings_named_and_keeps_the_others(): void {
		$before = $this->succeed( self::GET, array() );

		$changed = $this->succeed( self::UPDATE, array( Settings::PRINT_AREA_FRAME => true ) );

		$this->assertTrue( $changed[ Settings::PRINT_AREA_FRAME ] );
		$this->assertTrue( $this->plugin->settings()->get( Settings::PRINT_AREA_FRAME ) );
		$this->assertSame( array_merge( $before, array( Settings::PRINT_AREA_FRAME => true ) ), $changed );
	}

	public function test_a_value_a_setting_does_not_take_or_a_setting_that_is_not_there_changes_nothing(): void {
		$wrong_value = $this->call( self::UPDATE, array( Settings::DEFAULT_APPROVAL_MODE => 'by_post' ) );
		$unknown     = $this->call( self::UPDATE, array( 'no_such_setting' => true ) );
		$developer   = $this->call( self::UPDATE, array( Settings::SDK_DEBUG => true ) );

		foreach ( array( $wrong_value, $unknown, $developer ) as $answer ) {
			$this->assertSame( self::UNPROCESSABLE, $answer->get_status(), (string) wp_json_encode( $answer->get_data() ) );
			$this->assertSame( 'invalid_input', $answer->get_data()['code'] );
		}
		$this->assertFalse( $this->plugin->settings()->get( Settings::SDK_DEBUG ) );
	}

	public function test_a_price_ending_not_below_the_step_is_refused_as_the_pricing_settings_refuse_it(): void {
		$step_and_ending = array(
			Settings::PRICE_STEP   => '10',
			Settings::PRICE_ENDING => '20',
		);

		$kept = $this->plugin->settings()->get( Settings::PRICE_ENDING );

		foreach ( array( self::UPDATE, UpdatePricingSettings::NAME ) as $tool ) {
			$answer = $this->call( $tool, $step_and_ending );

			$this->assertSame( self::UNPROCESSABLE, $answer->get_status(), $tool );
			$this->assertSame( 'invalid_input', $answer->get_data()['code'], $tool );
			$this->assertSame( $kept, $this->plugin->settings()->get( Settings::PRICE_ENDING ), $tool );
		}
	}

	public function test_the_settings_the_tools_carry_are_the_ones_pixonaut_is_held_to(): void {
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- A fixture of this repository, on disk.
		$carried = json_decode( (string) file_get_contents( self::CARRIED ), true, 512, JSON_THROW_ON_ERROR );

		$why = 'A setting was added or removed: update tests/fixtures/shop-tool-settings.json, and give Pixonaut\'s shop tools the setting (its copy of the file fails there until a tool names it).';
		$this->assertSame( array_keys( ReadPluginSettings::settings( $this->plugin->settings() ) ), $carried[ self::GET ], $why );
		$this->assertSame( ReadPricingSettings::KEYS, $carried[ ReadPricingSettings::NAME ], $why );
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
