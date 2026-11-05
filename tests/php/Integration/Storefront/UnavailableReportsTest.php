<?php
/**
 * @package Pixonaut\WooCommerce
 */

declare(strict_types=1);

namespace Pixonaut\WooCommerce\Tests\Integration\Storefront;

use Pixonaut\WooCommerce\Admin\Notices;
use Pixonaut\WooCommerce\Api\Catalogue;
use Pixonaut\WooCommerce\Api\Client;
use Pixonaut\WooCommerce\Api\Deprecations;
use Pixonaut\WooCommerce\Api\Operations;
use Pixonaut\WooCommerce\Api\WcLogger;
use Pixonaut\WooCommerce\Api\WpHttpTransport;
use Pixonaut\WooCommerce\Catalogue\ProductConfig;
use Pixonaut\WooCommerce\Catalogue\Side;
use Pixonaut\WooCommerce\Connection\ConnectionStore;
use Pixonaut\WooCommerce\Layout;
use Pixonaut\WooCommerce\Plugin;
use Pixonaut\WooCommerce\Schema;
use Pixonaut\WooCommerce\Settings\Settings;
use Pixonaut\WooCommerce\Storefront\Sdk;
use Pixonaut\WooCommerce\Storefront\UnavailableReports;
use Pixonaut\WooCommerce\Tests\Support\HttpRecorder;
use WC_Product;
use WP_REST_Request;
use WP_UnitTestCase;

final class UnavailableReportsTest extends WP_UnitTestCase {

	private const STAGING_CDN = 'https://cdn.staging.example.test';

	/**
	 * Loopback, so the test bootstrap's host guard lets it through to the recorder.
	 */
	private const BASE_URL = 'http://127.0.0.1:9';

	/**
	 * A `pre_http_request` priority that runs before the recorder's 10.
	 */
	private const BEFORE_RECORDER = 5;

	/**
	 * Seconds a throttle window is set to end before or after now.
	 */
	private const A_MINUTE = 60;

	private HttpRecorder $recorder;

	private ConnectionStore $store;

	private UnavailableReports $reports;

	private Notices $notices;

	public function set_up(): void {
		parent::set_up();
		$this->recorder = ( new HttpRecorder( self::BASE_URL ) )->start();
		$plugin         = Plugin::boot( dirname( __DIR__, 4 ) . '/pixonaut-for-woocommerce.php' );
		$this->store    = $plugin->connection_store();
		StorefrontTest::connect( $this->store );
		$client        = new Client( self::BASE_URL, Operations::plugin(), $this->store, new WpHttpTransport(), new WcLogger(), new Deprecations() );
		$this->notices = $plugin->notices();
		$this->reports = new UnavailableReports( $plugin->inline_products(), new Catalogue( $client ), $this->notices, new WcLogger() );
	}

	public function tear_down(): void {
		$this->recorder->stop();
		$this->store->clear();
		delete_option( Notices::OPTION );
		parent::tear_down();
	}

	public function test_the_route_is_public(): void {
		$routes = rest_get_server()->get_routes( Plugin::REST_NAMESPACE );
		$route  = $routes[ '/' . Plugin::REST_NAMESPACE . UnavailableReports::ROUTE ][0] ?? null;

		$this->assertIsArray( $route );
		$this->assertSame( '__return_true', $route['permission_callback'] );
	}

	public function test_a_placement_pixonaut_confirms_gone_flags_the_product_invalid(): void {
		$product = StorefrontTest::product( StorefrontTest::personalised() );
		$this->recorder->json( 422, array( 'error' => 'unknown_placement' ) );

		$response = $this->reports->handle( $this->report( $product, 'unknown_placement' ) );

		$this->assertSame( 202, $response->get_status() );
		$this->assertCount( 1, $this->recorder->requests );
		$this->assertSame( 'GET', $this->recorder->requests[0]['method'] );
		$this->assertSame( self::BASE_URL . '/v1/templates/mini-tee%402/constraints?blankId=blank_tee&placementId=front', $this->recorder->requests[0]['url'] );
		$config = ProductConfig::stored_on( $this->reload( $product ) );
		$this->assertFalse( $config->valid );
		$this->assertSame( UnavailableReports::INVALID_REASON, $config->invalid_reason );
		$this->assertTrue( $this->notices->has( 'storefront_product_' . $product->get_id() . '_unknown_placement' ) );
	}

	public function test_a_placement_gone_from_an_inheriting_variation_flags_its_product(): void {
		[ $product, $variations ] = VariationSwitchesTest::variable( StorefrontTest::personalised(), array( 'M' => null ) );
		$this->recorder->json( 422, array( 'error' => 'unknown_placement' ) );

		$this->reports->handle( $this->report( $variations['M'], 'unknown_placement' ) );

		$this->assertFalse( ProductConfig::stored_on( $this->reload( $product ) )->valid );
		$this->assertFalse( ProductConfig::is_stored_on( $this->reload( $variations['M'] ) ) );
	}

	public function test_a_placement_gone_from_an_overriding_variation_flags_that_variation(): void {
		[ $product, $variations ] = VariationSwitchesTest::variable(
			StorefrontTest::personalised(),
			array( 'XL' => VariationSwitchesTest::front( array( 'blank' => 'blank_hoodie' ) ) )
		);
		$this->recorder->json( 422, array( 'error' => 'unknown_placement' ) );

		$this->reports->handle( $this->report( $variations['XL'], 'unknown_placement' ) );

		$this->assertStringContainsString( 'blankId=blank_hoodie', $this->recorder->requests[0]['url'] ?? '' );
		$config = ProductConfig::stored_on( $this->reload( $variations['XL'] ) );
		$this->assertFalse( $config->valid );
		$this->assertTrue( $config->override );
		$this->assertTrue( ProductConfig::stored_on( $this->reload( $product ) )->valid );
	}

	public function test_a_filtered_side_still_lets_a_report_flag_the_product(): void {
		add_filter(
			'pixonaut_wc_catalogue_side_config',
			static fn ( Side $side ): Side => new Side( $side->key, 'Chest', $side->placement_id, $side->placement_name, $side->template_base_id, $side->template_version, $side->previous_version, $side->template_name, $side->print_sizes )
		);
		$product = StorefrontTest::product( StorefrontTest::personalised() );
		$this->recorder->json( 422, array( 'error' => 'unknown_placement' ) );

		$this->reports->handle( $this->report( $product, 'unknown_placement' ) );

		$config = ProductConfig::stored_on( $this->reload( $product ) );
		$this->assertFalse( $config->valid );
		$this->assertSame( UnavailableReports::INVALID_REASON, $config->invalid_reason );
		// The stored side is saved as it was, unfiltered.
		$this->assertNotSame( 'Chest', $config->sides[0]->label );
	}

	public function test_a_placement_pixonaut_still_resolves_changes_nothing(): void {
		$product = StorefrontTest::product( StorefrontTest::personalised() );
		$this->recorder->json( 200, $this->constraints() );

		$this->reports->handle( $this->report( $product, 'unknown_placement' ) );

		$this->assertTrue( ProductConfig::stored_on( $this->reload( $product ) )->valid );
		$this->assertSame( array(), $this->notices->all() );
	}

	public function test_a_theme_filtered_placement_is_not_what_is_checked(): void {
		add_filter(
			'pixonaut_wc_storefront_mount_options',
			static function ( array $options ): array {
				$options['placementId'] = 'back';
				return $options;
			}
		);
		$product = StorefrontTest::product( StorefrontTest::personalised() );
		$this->recorder->json( 200, $this->constraints() );

		$this->reports->handle( $this->report( $product, 'unknown_placement' ) );

		$this->assertCount( 1, $this->recorder->requests );
		$this->assertStringEndsWith( '&placementId=front', $this->recorder->requests[0]['url'] );
		$this->assertTrue( ProductConfig::stored_on( $this->reload( $product ) )->valid );
	}

	public function test_a_configuration_changed_during_the_check_is_kept(): void {
		$product = StorefrontTest::product( StorefrontTest::personalised() );
		$changed = StorefrontTest::personalised( Layout::BUTTON );
		// As a store manager saving the product while Pixonaut is asked; runs before the recorder answers.
		add_filter(
			'pre_http_request',
			function ( $preempt ) use ( $product, $changed ) {
				$changed->save_to( $this->reload( $product ) );
				return $preempt;
			},
			self::BEFORE_RECORDER
		);
		$this->recorder->json( 422, array( 'error' => 'unknown_placement' ) );

		$this->reports->handle( $this->report( $product, 'unknown_placement' ) );

		$stored = ProductConfig::stored_on( $this->reload( $product ) );
		$this->assertTrue( $stored->valid );
		$this->assertSame( Layout::BUTTON, $stored->layout );
		$this->assertSame( array(), $this->notices->all() );
	}

	public function test_a_repeated_report_is_not_checked_again(): void {
		$product = StorefrontTest::product( StorefrontTest::personalised() );
		$this->recorder->json( 200, $this->constraints() );

		$this->reports->handle( $this->report( $product, 'unknown_placement' ) );
		$this->reports->handle( $this->report( $product, 'unknown_placement' ) );

		$this->assertCount( 1, $this->recorder->requests );
	}

	public function test_an_sdk_that_could_not_start_raises_a_notice_only(): void {
		$product = StorefrontTest::product( StorefrontTest::personalised() );

		$response = $this->reports->handle( $this->report( $product, UnavailableReports::WASM_INTEGRITY ) );

		$this->assertSame( 202, $response->get_status() );
		$this->assertSame( array(), $this->recorder->requests );
		$this->assertTrue( ProductConfig::stored_on( $this->reload( $product ) )->valid );
		$this->assertTrue( $this->notices->has( 'storefront_sdk_' . UnavailableReports::WASM_INTEGRITY ) );
	}

	public function test_an_sdk_script_that_did_not_load_raises_a_notice_only(): void {
		$product = StorefrontTest::product( StorefrontTest::personalised() );

		$response = $this->reports->handle( $this->report( $product, UnavailableReports::SDK_LOAD ) );

		$this->assertSame( 202, $response->get_status() );
		$this->assertSame( array(), $this->recorder->requests );
		$this->assertTrue( ProductConfig::stored_on( $this->reload( $product ) )->valid );
		$this->assertTrue( $this->notices->has( 'storefront_sdk_' . UnavailableReports::SDK_LOAD ) );
	}

	public function test_a_template_newer_than_the_sdk_asks_for_an_update(): void {
		$product = StorefrontTest::product( StorefrontTest::personalised() );

		$response = $this->reports->handle( $this->report( $product, UnavailableReports::SDK_OUTDATED ) );

		$this->assertSame( 202, $response->get_status() );
		$this->assertSame( array(), $this->recorder->requests );
		$this->assertTrue( ProductConfig::stored_on( $this->reload( $product ) )->valid );
		$notices = $this->notices->all();
		$this->assertArrayHasKey( UnavailableReports::outdated_notice_key(), $notices );
		$notice = $notices[ UnavailableReports::outdated_notice_key() ];
		$this->assertSame( 'error', $notice['type'] );
		$this->assertSame(
			'Customers cannot personalise “' . $product->get_name() . '”: this version of Pixonaut for WooCommerce is older than the product’s template. Update the Pixonaut for WooCommerce plugin to fix this.',
			$notice['message']
		);
	}

	public function test_the_outdated_notice_names_the_pinned_sdk_release(): void {
		$this->assertSame( 'storefront_sdk_sdk_outdated_' . str_replace( '.', '-', Sdk::VERSION ), UnavailableReports::outdated_notice_key() );
	}

	public function test_an_sdk_update_drops_the_outdated_notice_raised_before_it(): void {
		$this->notices->add( 'storefront_sdk_' . UnavailableReports::SDK_OUTDATED, 'Raised before the notice named its SDK release.', 'error' );
		$this->notices->add( 'storefront_sdk_sdk_outdated_0-16-0', 'Raised under SDK 0.16.0.', 'error' );
		$this->notices->add( UnavailableReports::outdated_notice_key(), 'Raised under the pinned SDK.', 'error' );
		$this->notices->add( 'storefront_sdk_' . UnavailableReports::SDK_LOAD, 'The SDK did not load.' );

		$this->reports->register();
		$this->assertSame( 10, has_action( 'admin_init', array( $this->reports, 'forget_outdated_notices' ) ) );
		$this->reports->forget_outdated_notices();

		$this->assertSame(
			array( UnavailableReports::outdated_notice_key(), 'storefront_sdk_' . UnavailableReports::SDK_LOAD ),
			array_keys( $this->notices->all() )
		);
	}

	public function test_the_notice_names_the_host_the_sdk_loads_from(): void {
		$product = StorefrontTest::product( StorefrontTest::personalised() );
		Plugin::boot( dirname( __DIR__, 4 ) . '/pixonaut-for-woocommerce.php' )->settings()->set( Settings::CDN_BASE, self::STAGING_CDN );

		$this->reports->handle( $this->report( $product, UnavailableReports::SDK_LOAD ) );

		$message = $this->notices->all()[ 'storefront_sdk_' . UnavailableReports::SDK_LOAD ]['message'] ?? '';
		$this->assertStringContainsString( 'cdn.staging.example.test', $message );
		$this->assertStringNotContainsString( 'cdn.pixonaut.app', $message );
	}

	public function test_a_window_another_report_holds_is_not_acted_on(): void {
		$product = StorefrontTest::product( StorefrontTest::personalised() );
		add_option( UnavailableReports::throttle_option( $product->get_id(), 'front', UnavailableReports::WASM_BOOT ), (string) ( time() + self::A_MINUTE ), '', false );

		$this->reports->handle( $this->report( $product, UnavailableReports::WASM_BOOT ) );

		$this->assertSame( array(), $this->notices->all() );
	}

	public function test_a_window_held_under_another_sdk_release_does_not_hold_an_outdated_report(): void {
		$product = StorefrontTest::product( StorefrontTest::personalised() );
		// The window a report under an earlier release claimed: no release in its key.
		add_option( Schema::REPORT_THROTTLE_OPTION_PREFIX . md5( $product->get_id() . '|front|' . UnavailableReports::SDK_OUTDATED ), (string) ( time() + self::A_MINUTE ), '', false );
		$this->reports->forget_outdated_notices();

		$this->reports->handle( $this->report( $product, UnavailableReports::SDK_OUTDATED ) );

		$this->assertTrue( $this->notices->has( UnavailableReports::outdated_notice_key() ) );
	}

	public function test_an_ended_window_is_claimed_again(): void {
		$product = StorefrontTest::product( StorefrontTest::personalised() );
		$option  = UnavailableReports::throttle_option( $product->get_id(), 'front', UnavailableReports::WASM_BOOT );
		add_option( $option, (string) ( time() - self::A_MINUTE ), '', false );

		$this->reports->handle( $this->report( $product, UnavailableReports::WASM_BOOT ) );

		$this->assertTrue( $this->notices->has( 'storefront_sdk_' . UnavailableReports::WASM_BOOT ) );
		wp_cache_delete( $option, 'options' );
		$this->assertGreaterThan( time(), (int) get_option( $option ) );
	}

	public function test_a_product_without_the_side_is_not_found(): void {
		$product = StorefrontTest::product( null );

		$response = $this->reports->handle( $this->report( $product, UnavailableReports::WASM_BOOT ) );

		$this->assertSame( 404, $response->get_status() );
		$this->assertSame( array(), $this->notices->all() );
	}

	public function test_the_route_refuses_an_unknown_reason(): void {
		$product = StorefrontTest::product( StorefrontTest::personalised() );
		$request = new WP_REST_Request( 'POST', '/' . Plugin::REST_NAMESPACE . UnavailableReports::ROUTE );
		$request->set_body_params(
			array(
				'product' => $product->get_id(),
				'side'    => 'front',
				'reason'  => 'checkout_broken',
			)
		);

		$this->assertSame( 400, rest_do_request( $request )->get_status() );
	}

	/**
	 * @param WC_Product $product The product reported.
	 * @param string     $reason  Why.
	 * @return WP_REST_Request<array<string, mixed>>
	 */
	private function report( WC_Product $product, string $reason ): WP_REST_Request {
		$request = new WP_REST_Request( 'POST', '/' . Plugin::REST_NAMESPACE . UnavailableReports::ROUTE );
		$request->set_param( 'product', $product->get_id() );
		$request->set_param( 'side', 'front' );
		$request->set_param( 'reason', $reason );
		return $request;
	}

	/**
	 * A `200` of `GET /v1/templates/{id}/constraints` with the pair.
	 *
	 * @return array<string, mixed>
	 */
	private function constraints(): array {
		return array(
			'templateId'     => 'mini-tee@2',
			'ratio'          => array(
				'w' => 5,
				'h' => 6,
			),
			'ratioTolerance' => 0.01,
			'fit'            => 'contain',
			'placement'      => array(
				'blankId'            => 'blank_tee',
				'placementId'        => 'front',
				'maxWidthMm'         => 330,
				'maxHeightMm'        => 420,
				'defaultWidthMm'     => 300,
				'defaultHeightMm'    => 360,
				'printWidthMm'       => 300,
				'printHeightMm'      => 360,
				'bleedMm'            => 3,
				'safeAreaMm'         => 5,
				// Answered by every placement; the plugin does not draw colours.
				'cmykPreviewProfile' => 'ISOcoated_v2_eci',
				'coverage'           => 'within',
				'sizes'              => array(
					'fits'    => array(),
					'misfits' => array(),
				),
			),
		);
	}

	private function reload( WC_Product $product ): WC_Product {
		$reloaded = wc_get_product( $product->get_id() );
		$this->assertInstanceOf( WC_Product::class, $reloaded );
		return $reloaded;
	}
}
