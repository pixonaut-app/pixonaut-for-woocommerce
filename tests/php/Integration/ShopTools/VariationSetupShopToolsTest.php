<?php
/**
 * @package Pixonaut\WooCommerce
 */

declare(strict_types=1);

namespace Pixonaut\WooCommerce\Tests\Integration\ShopTools;

use Pixonaut\WooCommerce\Abilities\Store\ResourceAbility;
use Pixonaut\WooCommerce\Admin\Notices;
use Pixonaut\WooCommerce\Api\Catalogue;
use Pixonaut\WooCommerce\Api\Hosts;
use Pixonaut\WooCommerce\Catalogue\PrintSize;
use Pixonaut\WooCommerce\Catalogue\ProductConfig;
use Pixonaut\WooCommerce\Catalogue\Side;
use Pixonaut\WooCommerce\Connection\Connection;
use Pixonaut\WooCommerce\Connection\StoreAccess;
use Pixonaut\WooCommerce\Connection\StoredConnection;
use Pixonaut\WooCommerce\Plugin;
use Pixonaut\WooCommerce\Tests\Support\BlankFixture;
use Pixonaut\WooCommerce\Tests\Support\HttpRecorder;
use Pixonaut\WooCommerce\Tests\Support\OpenApiAssert;
use Pixonaut\WooCommerce\Tests\Support\VerdictAnswers;
use WC_Product;
use WC_Product_Attribute;
use WC_Product_Variable;
use WC_Product_Variation;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;
use WP_UnitTestCase;

/**
 * Setting a variation's own setup with the shop tool, and the setup the
 * variation reads give, called on the shop tool route as Pixonaut calls
 * them, against a recorder that answers as Pixonaut.
 */
final class VariationSetupShopToolsTest extends WP_UnitTestCase {

	private const TOOL = 'set-product-setup';

	private const ROUTE = '/pixonaut/v1/shop-tools/';

	private const SECRET = 'a-store-credential';

	/**
	 * The template the product is sold with.
	 */
	private const PRODUCT_TEMPLATE_BASE_ID = 'mini-poster';

	private const PRODUCT_TEMPLATE_VERSION = 1;

	/**
	 * The template a variation is given of its own.
	 */
	private const OWN_TEMPLATE_ID = 'mini-tee@2';

	/**
	 * A print size of the own template's ratio (280 × 360), in millimetres.
	 */
	private const OWN_WIDTH_MM = 280;

	private const OWN_HEIGHT_MM = 360;

	private const PRODUCT_SIZE_MM = 200.0;

	/**
	 * The blank a variation's own setup is printed on.
	 */
	private const BLANK_ID = 'blank_abc';

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
		// A product without a blank is checked against the printshops it may be sent to.
		BlankFixture::remember_printshops( $this->plugin->catalogue_cache() );
		update_option(
			StoreAccess::OPTION,
			array(
				'site'       => 'site-a',
				'hash'       => hash( 'sha256', self::SECRET ),
				'cut'        => false,
				'changed_at' => 0,
			),
			false
		);
		wp_set_current_user( 0 );
	}

	public function tear_down(): void {
		$this->recorder->stop();
		$this->plugin->connection_store()->clear();
		delete_option( StoreAccess::OPTION );
		delete_option( Notices::OPTION );
		global $wp_rest_server;
		$wp_rest_server = null;
		parent::tear_down();
	}

	public function test_a_variation_gets_a_template_of_its_own_and_follows_its_product_again(): void {
		$product   = $this->poster();
		$variation = $this->variation( $product, 'Small' );
		$this->recorder->json( 200, array( 'verdicts' => array( VerdictAnswers::LEGAL ) ) );
		$this->recorder->raw( 200, OpenApiAssert::example( 'GET', '/v1/templates/{id}', 200, array( 'status' => Catalogue::LIVE ) ), 'application/json' );

		$own = $this->succeed(
			array(
				'product_id' => $variation->get_id(),
				'own_setup'  => true,
				'blank_id'   => '',
				'sides'      => array(
					array(
						'template_id'     => self::OWN_TEMPLATE_ID,
						'template_pinned' => false,
						'label'           => 'Front',
						'mode'            => Side::PERSONALISED,
						'print_sizes'     => array(
							array(
								'width_mm'  => self::OWN_WIDTH_MM,
								'height_mm' => self::OWN_HEIGHT_MM,
							),
						),
					),
				),
			)
		);

		$this->assertSame( $variation->get_id(), $own['product_id'] );
		$this->assertTrue( $own['own_setup'] );
		$this->assertTrue( $own['valid'], (string) $own['invalid_reason'] );
		$this->assertNull( $own['blank_id'] );
		$this->assertSame( self::OWN_TEMPLATE_ID, $own['sides'][0]['template_id'] );
		$this->assertSame( 'Front', $own['sides'][0]['label'] );
		$stored = ProductConfig::stored_on( $this->reload( $variation ) );
		$this->assertTrue( $stored->override );
		$this->assertSame( 'mini-tee', $stored->sides[0]->template_base_id, 'the variation has that template' );
		$this->assertSame( self::PRODUCT_TEMPLATE_BASE_ID, ProductConfig::stored_on( $this->reload( $product ) )->sides[0]->template_base_id, 'the product keeps its own' );

		$following = $this->succeed(
			array(
				'product_id' => $variation->get_id(),
				'own_setup'  => false,
			)
		);

		$this->assertFalse( $following['own_setup'] );
		$this->assertSame( self::PRODUCT_TEMPLATE_BASE_ID . '@' . self::PRODUCT_TEMPLATE_VERSION, $following['sides'][0]['template_id'], 'it gives its product\'s setup' );
		$this->assertFalse( ProductConfig::stored_on( $this->reload( $variation ) )->override );
	}

	public function test_a_variation_with_input_the_ability_refuses_is_bad_input(): void {
		$product   = $this->poster();
		$variation = $this->variation( $product, 'Small' );

		$answer = $this->call(
			array(
				'product_id'    => $variation->get_id(),
				'group_numbers' => '',
			)
		);

		$this->assertSame( 422, $answer->get_status() );
		$this->assertSame( 'invalid_input', $answer->get_data()['code'] );
		$this->assertFalse( ProductConfig::is_stored_on( $this->reload( $variation ) ) );
	}

	public function test_the_variation_reads_give_the_setup_each_variation_is_sold_under(): void {
		$product   = $this->poster();
		$own       = $this->variation( $product, 'Small' );
		$following = $this->variation( $product, 'Large' );
		$own_side  = new Side( 'front', '', 'front', 'Front', 'mini-tee', 2, null, 'Mini tee', mode: Side::STANDING, pinned: true );
		( new ProductConfig( personalise: true, override: true, blank_id: self::BLANK_ID, sides: array( $own_side ) ) )->save_to( $own );
		// Its own setup, unused while it follows its product.
		( new ProductConfig( personalise: true, override: false, blank_id: self::BLANK_ID, sides: array( $own_side ) ) )->save_to( $following );

		$listed = $this->succeed( array( 'product_id' => $product->get_id() ), 'list-product-variations' );
		$read   = $this->succeed(
			array(
				'product_id' => $product->get_id(),
				'id'         => $own->get_id(),
			),
			'get-product-variation'
		);

		$own_setup       = array(
			'own_setup' => true,
			'blank_id'  => self::BLANK_ID,
			'sides'     => array(
				array(
					'mode'            => Side::STANDING,
					'template_id'     => 'mini-tee@2',
					'template_pinned' => true,
					'placement_id'    => 'front',
				),
			),
		);
		$following_setup = array(
			'own_setup' => false,
			'blank_id'  => null,
			'sides'     => array(
				array(
					'label'           => 'Front',
					'mode'            => Side::PERSONALISED,
					'template_id'     => self::PRODUCT_TEMPLATE_BASE_ID . '@' . self::PRODUCT_TEMPLATE_VERSION,
					'template_pinned' => false,
					'placement_id'    => null,
				),
			),
		);
		$this->assertSame( $own_setup, $read[ ResourceAbility::SETUP_FIELD ] );
		$by_id = array_column( $listed['items'], ResourceAbility::SETUP_FIELD, 'id' );
		$this->assertSame( $own_setup, $by_id[ $own->get_id() ] );
		$this->assertSame( $following_setup, $by_id[ $following->get_id() ] );
		$this->assertArrayNotHasKey( ResourceAbility::SETUP_FIELD, $this->succeed( array( 'id' => $product->get_id() ), 'get-product' ), 'a product gives none' );
		$this->assertSame( array(), $this->recorder->requests, 'no call to Pixonaut' );
	}

	/**
	 * @param array<mixed> $input Its input.
	 * @param string       $tool  The tool.
	 * @return array<string, mixed> Its result.
	 */
	private function succeed( array $input, string $tool = self::TOOL ): array {
		$answer = $this->call( $input, $tool );
		$this->assertSame( 200, $answer->get_status(), (string) wp_json_encode( $answer->get_data() ) );
		$data = $answer->get_data();
		$this->assertIsArray( $data );
		$this->assertIsArray( $data['result'] );
		return $data['result'];
	}

	/**
	 * @param array<mixed> $input Its input.
	 * @param string       $tool  The tool.
	 */
	private function call( array $input, string $tool = self::TOOL ): WP_REST_Response {
		$request = new WP_REST_Request( 'POST', self::ROUTE . $tool );
		$request->set_header( 'Content-Type', 'application/json' );
		$request->set_header( 'Authorization', 'Bearer ' . self::SECRET );
		$request->set_body( (string) wp_json_encode( (object) $input ) );
		return rest_do_request( $request );
	}

	/**
	 * A variable poster personalised without a blank, sold in one size.
	 */
	private function poster(): WC_Product_Variable {
		$product   = new WC_Product_Variable();
		$attribute = new WC_Product_Attribute();
		$attribute->set_name( 'Size' );
		$attribute->set_options( array( 'Small', 'Large' ) );
		$attribute->set_variation( true );
		$product->set_name( 'Poster' );
		$product->set_attributes( array( $attribute ) );
		$product->save();
		$side = new Side( 'front', 'Front', null, null, self::PRODUCT_TEMPLATE_BASE_ID, self::PRODUCT_TEMPLATE_VERSION, null, 'Mini poster', array( new PrintSize( self::PRODUCT_SIZE_MM, self::PRODUCT_SIZE_MM, '' ) ) );
		( new ProductConfig( personalise: true, sides: array( $side ) ) )->save_to( $product );
		return $product;
	}

	/**
	 * @param WC_Product_Variable $product The variable product.
	 * @param string              $size    Its size.
	 */
	private function variation( WC_Product_Variable $product, string $size ): WC_Product_Variation {
		$variation = new WC_Product_Variation();
		$variation->set_parent_id( $product->get_id() );
		$variation->set_attributes( array( 'size' => $size ) );
		$variation->set_regular_price( '25' );
		$variation->save();
		return $variation;
	}

	private function reload( WC_Product $product ): WC_Product {
		wp_cache_delete( (string) $product->get_id(), 'post_meta' );
		$reloaded = wc_get_product( $product->get_id() );
		$this->assertInstanceOf( WC_Product::class, $reloaded );
		return $reloaded;
	}

	private static function connection(): StoredConnection {
		return StoredConnection::from_array(
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
		);
	}
}
