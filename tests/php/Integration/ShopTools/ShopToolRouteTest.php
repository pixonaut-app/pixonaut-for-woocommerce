<?php
/**
 * The route Pixonaut calls the shop tools on.
 *
 * @package Pixonaut\WooCommerce
 */

declare(strict_types=1);

namespace Pixonaut\WooCommerce\Tests\Integration\ShopTools;

use Pixonaut\WooCommerce\Connection\Connection;
use Pixonaut\WooCommerce\Connection\StoreAccess;
use Pixonaut\WooCommerce\Connection\StoredConnection;
use Pixonaut\WooCommerce\Plugin;
use RuntimeException;
use WC_Order;
use WC_Product_Simple;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;
use WP_UnitTestCase;

final class ShopToolRouteTest extends WP_UnitTestCase {

	private const ROUTE = '/pixonaut/v1/shop-tools/';

	private const SECRET = 'a-store-credential';

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
					'site'                  => 'site-a',
					'home_url'              => Connection::site_url(),
					'connected_at'          => 1790000000,
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
		$this->switch_on();
		wp_set_current_user( 0 );
	}

	public function tear_down(): void {
		$this->plugin->connection_store()->clear();
		delete_option( StoreAccess::OPTION );
		global $wp_rest_server;
		$wp_rest_server = null;
		parent::tear_down();
	}

	/**
	 * What the switch being on keeps, for the known secret.
	 */
	private function switch_on(): void {
		update_option(
			StoreAccess::OPTION,
			array(
				'site'       => $this->plugin->connection_store()->load()?->site,
				'hash'       => hash( 'sha256', self::SECRET ),
				'cut'        => false,
				'changed_at' => 0,
			),
			false
		);
	}

	/**
	 * Calls a tool.
	 *
	 * @param string       $tool   The tool.
	 * @param array<mixed> $input  Its input.
	 * @param string|null  $secret The credential sent; null sends none.
	 */
	private function call( string $tool, array $input = array(), ?string $secret = self::SECRET ): WP_REST_Response {
		$request = new WP_REST_Request( 'POST', self::ROUTE . $tool );
		$request->set_header( 'Content-Type', 'application/json' );
		$request->set_body( (string) wp_json_encode( (object) $input ) );
		if ( null !== $secret ) {
			$request->set_header( 'Authorization', 'Bearer ' . $secret );
		}
		return rest_do_request( $request );
	}

	/**
	 * Asserts a refusal.
	 *
	 * @param int              $status   The status.
	 * @param string           $code     The refusal's code.
	 * @param WP_REST_Response $response The answer.
	 */
	private function assert_refused( int $status, string $code, WP_REST_Response $response ): void {
		$this->assertSame( $status, $response->get_status() );
		$data = $response->get_data();
		$this->assertIsArray( $data );
		$this->assertSame( $code, $data['code'] );
		$this->assertNotEmpty( $data['message'] );
	}

	public function test_no_credential_is_refused(): void {
		$this->assert_refused( 401, 'credential_invalid', $this->call( 'list-products', array(), null ) );
	}

	public function test_an_unknown_credential_is_refused(): void {
		$this->assert_refused( 401, 'credential_invalid', $this->call( 'list-products', array(), 'not-it' ) );
	}

	public function test_a_call_with_the_switch_off_is_refused(): void {
		delete_option( StoreAccess::OPTION );

		$this->assert_refused( 403, 'store_access_off', $this->call( 'list-products' ) );
	}

	public function test_a_tool_there_is_not_is_refused(): void {
		$this->assert_refused( 404, 'unknown_tool', $this->call( 'read-the-options' ) );
	}

	public function test_a_tool_outside_the_list_is_refused_even_if_it_is_an_ability(): void {
		foreach ( array( 'list-coupons', 'get-store-setting', 'create-page', 'delete-media', 'update-order', 'create-order', 'list-product-brands' ) as $tool ) {
			$this->assert_refused( 404, 'unknown_tool', $this->call( $tool ) );
		}
	}

	public function test_input_that_does_not_fit_the_tool_is_refused(): void {
		$this->assert_refused( 422, 'invalid_input', $this->call( 'get-product', array( 'wrong' => 1 ) ) );
	}

	public function test_a_body_that_is_not_json_is_refused(): void {
		$request = new WP_REST_Request( 'POST', self::ROUTE . 'list-products' );
		$request->set_header( 'Content-Type', 'application/json' );
		$request->set_header( 'Authorization', 'Bearer ' . self::SECRET );
		$request->set_body( '{not json' );

		$this->assert_refused( 422, 'invalid_input', rest_do_request( $request ) );
	}

	public function test_a_body_that_is_not_json_without_the_credential_says_nothing_of_it(): void {
		$request = new WP_REST_Request( 'POST', self::ROUTE . 'list-products' );
		$request->set_header( 'Content-Type', 'application/json' );
		$request->set_body( '{not json' );

		$this->assert_refused( 401, 'credential_invalid', rest_do_request( $request ) );
	}

	public function test_something_that_is_not_there_is_refused_as_input(): void {
		$this->assert_refused( 422, 'invalid_input', $this->call( 'get-product', array( 'id' => 999999 ) ) );
	}

	/**
	 * Makes WooCommerce's routes answer as given.
	 *
	 * @param callable $answer `fn(): mixed` what a route answers, or throws.
	 */
	private static function shop_answers( callable $answer ): void {
		add_filter(
			'rest_pre_dispatch',
			static function ( mixed $response, mixed $server, WP_REST_Request $request ) use ( $answer ): mixed {
				return str_starts_with( $request->get_route(), '/wc/' ) ? $answer() : $response;
			},
			10,
			3
		);
	}

	public function test_a_shop_that_answers_a_server_error_is_a_store_error(): void {
		self::shop_answers( static fn (): WP_Error => new WP_Error( 'db_gone', 'Database gone', array( 'status' => 500 ) ) );

		$this->assert_refused( 502, 'store_error', $this->call( 'list-products' ) );
	}

	public function test_a_shop_error_with_no_status_is_a_store_error(): void {
		self::shop_answers( static fn (): WP_Error => new WP_Error( 'db_gone', 'Database gone' ) );

		$this->assert_refused( 502, 'store_error', $this->call( 'list-products' ) );
	}

	public function test_a_shop_that_throws_is_a_store_error_and_says_nothing_of_why(): void {
		self::shop_answers(
			static function (): never {
				throw new RuntimeException( 'database gone' );
			}
		);

		$answer = $this->call( 'list-products' );

		$this->assert_refused( 502, 'store_error', $answer );
		$this->assertStringNotContainsString( 'database gone', (string) wp_json_encode( $answer->get_data() ) );
	}

	public function test_it_creates_reads_updates_lists_and_deletes_a_product(): void {
		$created = $this->call( 'create-product', array( 'fields' => array( 'name' => 'Mug' ) ) );
		$this->assertSame( 200, $created->get_status() );
		$id = $created->get_data()['result']['id'];
		$this->assertSame( 'Mug', get_the_title( $id ) );

		$read = $this->call( 'get-product', array( 'id' => $id ) );
		$this->assertSame( 'Mug', $read->get_data()['result']['name'] );

		$this->call(
			'update-product',
			array(
				'id'     => $id,
				'fields' => array( 'name' => 'Big mug' ),
			)
		);
		$this->assertSame( 'Big mug', get_the_title( $id ) );

		$list = $this->call( 'list-products' );
		$this->assertContains( $id, array_column( $list->get_data()['result']['items'], 'id' ) );

		$this->assertSame(
			200,
			$this->call(
				'delete-product',
				array(
					'id'    => $id,
					'force' => true,
				)
			)->get_status()
		);
		$this->assertFalse( wc_get_product( $id ) );
	}

	public function test_it_manages_categories_and_tags_and_attributes_with_no_user(): void {
		foreach ( array( 'create-product-category', 'create-product-tag', 'create-product-attribute' ) as $tool ) {
			$created = $this->call( $tool, array( 'fields' => array( 'name' => 'Shop tools' ) ) );
			$this->assertSame( 200, $created->get_status(), $tool );
		}
	}

	public function test_it_reads_orders_and_changes_none(): void {
		$order = new WC_Order();
		$order->save();

		$read = $this->call( 'get-order', array( 'id' => $order->get_id() ) );

		$this->assertSame( $order->get_id(), $read->get_data()['result']['id'] );
		$this->assert_refused(
			404,
			'unknown_tool',
			$this->call(
				'delete-order',
				array( 'id' => $order->get_id() )
			)
		);
	}

	public function test_linking_a_product_refuses_a_template_id_that_is_not_one(): void {
		$product = new WC_Product_Simple();
		$product->save();

		$this->assert_refused(
			422,
			'invalid_input',
			$this->call(
				'link-product',
				array(
					'product_id'  => $product->get_id(),
					'template_id' => 'no-version@0',
				)
			)
		);
	}

	public function test_the_credential_is_no_login_anywhere_else(): void {
		$this->call( 'list-products' );
		$this->assertSame( 0, get_current_user_id() );

		$request = new WP_REST_Request( 'GET', '/wc/v3/products' );
		$request->set_header( 'Authorization', 'Bearer ' . self::SECRET );
		$this->assertContains( rest_do_request( $request )->get_status(), array( 401, 403 ) );

		$users = new WP_REST_Request( 'POST', '/wp/v2/users' );
		$users->set_header( 'Authorization', 'Bearer ' . self::SECRET );
		$users->set_body_params( array( 'username' => 'x' ) );
		$this->assertContains( rest_do_request( $users )->get_status(), array( 400, 401, 403 ) );
	}

	public function test_the_permission_filter_does_not_outlive_a_call(): void {
		$this->call( 'list-products' );

		$this->assertFalse( has_filter( 'woocommerce_rest_check_permissions', '__return_true' ) );
	}

	public function test_a_call_runs_as_nobody_whoever_is_signed_in(): void {
		$admin = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( is_int( $admin ) ? $admin : 0 );

		$this->assertSame( 200, $this->call( 'list-products' )->get_status() );

		$this->assertSame( 0, get_current_user_id() );
	}
}
