<?php
/**
 * @package Pixonaut\WooCommerce
 */

declare(strict_types=1);

namespace Pixonaut\WooCommerce\Tests\Integration\ShopTools;

use Pixonaut\WooCommerce\Api\Hosts;
use Pixonaut\WooCommerce\Catalogue\CatalogueCache;
use Pixonaut\WooCommerce\Catalogue\ProductConfig;
use Pixonaut\WooCommerce\Catalogue\ShowcaseImages;
use Pixonaut\WooCommerce\Catalogue\Side;
use Pixonaut\WooCommerce\Connection\StoreAccess;
use Pixonaut\WooCommerce\Connection\StoredConnection;
use Pixonaut\WooCommerce\Plugin;
use Pixonaut\WooCommerce\Settings\Settings;
use Pixonaut\WooCommerce\Tests\Support\HttpRecorder;
use Pixonaut\WooCommerce\Tests\Support\OpenApiAssert;
use WC_Product;
use WC_Product_Simple;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;
use WP_UnitTestCase;

/**
 * The shop tools that choose and read a product's showcase pictures,
 * called on the shop tool route as Pixonaut calls them, against a recorder
 * that answers as Pixonaut.
 */
final class ShowcaseShopToolsTest extends WP_UnitTestCase {

	private const ROUTE = '/pixonaut/v1/shop-tools/';

	private const SECRET = 'a-store-credential';

	private const SET = 'set-product-showcase';

	private const GET = 'get-product-picture-views';

	private const UNPROCESSABLE = 422;

	/**
	 * When the fixture's connection was made, as Unix time.
	 */
	private const CONNECTED_AT = 1767225600;

	private const GRADUATE = array(
		'id'   => 'sc_graduate',
		'name' => 'Graduate',
	);

	private const WEDDING = array(
		'id'   => 'sc_wedding',
		'name' => 'Wedding',
	);

	private Plugin $plugin;

	private HttpRecorder $recorder;

	public function set_up(): void {
		parent::set_up();
		$this->plugin   = Plugin::boot( dirname( __DIR__, 4 ) . '/pixonaut-for-woocommerce.php' );
		$this->recorder = ( new HttpRecorder( Hosts::server_api() ) )->start();
		global $wp_rest_server;
		$wp_rest_server = new WP_REST_Server();
		do_action( 'rest_api_init', $wp_rest_server );
		$this->plugin->connection_store()->save( self::connection() );
		( new CatalogueCache( $this->plugin->connection_store() ) )->flush();
		update_option(
			StoreAccess::OPTION,
			array(
				'site'       => self::connection()->site,
				'hash'       => hash( 'sha256', self::SECRET ),
				'cut'        => false,
				'changed_at' => 0,
			),
			false
		);
		update_option( Settings::OPTION, array( Settings::PRODUCT_PICTURES => true ) + (array) get_option( Settings::OPTION, array() ) );
		wp_set_current_user( 0 );
	}

	public function tear_down(): void {
		$this->recorder->stop();
		$this->plugin->connection_store()->clear();
		delete_option( StoreAccess::OPTION );
		delete_option( Settings::OPTION );
		global $wp_rest_server;
		$wp_rest_server = null;
		parent::tear_down();
	}

	public function test_it_sets_the_showcase_sets_and_reads_them_back_with_those_it_can_show(): void {
		$product = $this->product();
		$this->answer_sets( array( self::GRADUATE, self::WEDDING ) );

		$set = $this->succeed(
			self::SET,
			array(
				'product_id' => $product->get_id(),
				'images'     => array(
					array(
						'id'   => self::WEDDING['id'],
						'kind' => ShowcaseImages::PREVIEW,
					),
					array(
						'id'   => self::GRADUATE['id'],
						'kind' => ShowcaseImages::MOCKUP,
					),
				),
			)
		);

		// A product without a blank has only the flat design to draw on.
		$chosen = array(
			self::WEDDING + array( 'kind' => ShowcaseImages::PREVIEW ),
			self::GRADUATE + array( 'kind' => ShowcaseImages::PREVIEW ),
		);
		$this->assertSame( $product->get_id(), $set['product_id'] );
		$this->assertSame( $chosen, $set['showcase_images'] );
		$this->assertSame( array( self::GRADUATE, self::WEDDING ), $set['available_showcases'] );
		$this->assertSame( $chosen, ShowcaseImages::on( $this->reload( $product ) ) );
		$this->assertSame( 'pending', $set['picture'], 'drawn again with the sets' );

		$read = $this->succeed( self::GET, array( 'product_id' => $product->get_id() ) );

		$this->assertSame( $product->get_id(), $read['product_id'] );
		$this->assertSame( array( self::WEDDING['id'], self::GRADUATE['id'] ), array_column( $read['showcase_images'], 'id' ) );
		$this->assertSame( array( ShowcaseImages::PREVIEW, ShowcaseImages::PREVIEW ), array_column( $read['showcase_images'], 'kind' ) );
		$this->assertSame( array( self::GRADUATE, self::WEDDING ), $read['available_showcases'] );
		$this->assertCount( 1, $this->recorder->requests, 'the template\'s sets are listed once' );
	}

	public function test_an_empty_list_takes_every_set_off(): void {
		$product = $this->product();
		ShowcaseImages::save( $product, array( self::GRADUATE + array( 'kind' => ShowcaseImages::PREVIEW ) ) );
		$this->answer_sets( array( self::GRADUATE ) );

		$this->succeed(
			self::SET,
			array(
				'product_id' => $product->get_id(),
				'images'     => array(),
			)
		);

		$this->assertSame( array(), ShowcaseImages::on( $this->reload( $product ) ) );
	}

	public function test_the_sets_it_can_show_are_null_when_pixonaut_cannot_be_asked(): void {
		$product = $this->product();
		$this->recorder->fail();

		$read = $this->succeed( self::GET, array( 'product_id' => $product->get_id() ) );

		$this->assertArrayHasKey( 'available_showcases', $read );
		$this->assertNull( $read['available_showcases'] );
	}

	public function test_a_product_without_a_template_can_show_no_sets(): void {
		$product = new WC_Product_Simple();
		$product->save();

		$read = $this->succeed( self::GET, array( 'product_id' => $product->get_id() ) );

		$this->assertSame( array(), $read['available_showcases'] );
		$this->assertSame( array(), $this->recorder->requests );
	}

	public function test_a_set_the_template_does_not_offer_is_invalid_input(): void {
		$product = $this->product();
		$this->answer_sets( array( self::GRADUATE ) );

		$answer = $this->call(
			self::SET,
			array(
				'product_id' => $product->get_id(),
				'images'     => array( array( 'id' => 'sc_none' ) ),
			)
		);

		$this->assert_invalid_input( $answer );
		$this->assertStringContainsString( self::GRADUATE['id'], $answer->get_data()['message'] );
		$this->assertSame( array(), ShowcaseImages::on( $this->reload( $product ) ) );
	}

	public function test_a_product_without_a_template_is_invalid_input(): void {
		$product = new WC_Product_Simple();
		$product->save();

		$this->assert_invalid_input(
			$this->call(
				self::SET,
				array(
					'product_id' => $product->get_id(),
					'images'     => array( array( 'id' => self::GRADUATE['id'] ) ),
				)
			)
		);
	}

	public function test_a_product_that_is_not_there_is_invalid_input_to_both(): void {
		$this->assert_invalid_input(
			$this->call(
				self::SET,
				array(
					'product_id' => PHP_INT_MAX,
					'images'     => array(),
				)
			)
		);
		$this->assert_invalid_input( $this->call( self::GET, array( 'product_id' => PHP_INT_MAX ) ) );
	}

	/**
	 * Answers the next `GET /v1/templates/{id}` with the sets.
	 *
	 * @param list<array{id: string, name: string}> $sets The sets.
	 */
	private function answer_sets( array $sets ): void {
		$this->recorder->raw(
			200,
			OpenApiAssert::example(
				'GET',
				'/v1/templates/{id}',
				200,
				array(
					'showcases' => array_map(
						static fn ( array $set ): array => $set + array( 'previewUrl' => Hosts::server_api() . '/i/' . $set['id'] ),
						$sets
					),
				)
			),
			'application/json'
		);
	}

	/**
	 * Calls a tool with the credential.
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
	 * Calls a tool that is to answer.
	 *
	 * @param string       $tool  The tool.
	 * @param array<mixed> $input Its input.
	 * @return array<string, mixed>
	 */
	private function succeed( string $tool, array $input ): array {
		$answer = $this->call( $tool, $input );
		$this->assertSame( 200, $answer->get_status(), (string) wp_json_encode( $answer->get_data() ) );
		$data = $answer->get_data();
		$this->assertIsArray( $data );
		$this->assertIsArray( $data['result'] );
		return $data['result'];
	}

	private function assert_invalid_input( WP_REST_Response $answer ): void {
		$this->assertSame( self::UNPROCESSABLE, $answer->get_status() );
		$this->assertSame( 'invalid_input', $answer->get_data()['code'] );
	}

	/**
	 * A simple product personalised with a template and no blank.
	 */
	private function product(): WC_Product {
		$product = new WC_Product_Simple();
		$product->set_name( 'Personalised card' );
		$product->set_regular_price( '25' );
		$product->save();
		( new ProductConfig( personalise: true, sides: array( new Side( 'design', 'Front print', null, null, 'mini-tee', 2, null, 'Mini tee', array() ) ) ) )->save_to( $product );
		return $this->reload( $product );
	}

	private function reload( WC_Product $product ): WC_Product {
		$read = wc_get_product( $product->get_id() );
		$this->assertInstanceOf( WC_Product::class, $read );
		return $read;
	}

	private static function connection(): StoredConnection {
		return new StoredConnection(
			'site_fixture',
			home_url(),
			self::CONNECTED_AT,
			'tenant_fixture',
			'Fixture Prints',
			StoredConnection::LIVE,
			'conn_main',
			'ik_live_fixture_main',
			'ik_id',
			'WooCommerce · example.org',
			'pk_live_fixture_main',
			'pk_id',
			array( 'http://example.org' ),
			'wh_fixture_main',
			'http://example.org/wp-json/pixonaut/v1/webhook',
			'whsec_fixture_main'
		);
	}
}
