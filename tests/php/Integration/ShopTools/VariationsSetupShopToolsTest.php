<?php
/**
 * @package Pixonaut\WooCommerce
 */

declare(strict_types=1);

namespace Pixonaut\WooCommerce\Tests\Integration\ShopTools;

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
 * Setting many variations' own setup alike with the shop tool, called on
 * the shop tool route as Pixonaut calls it, against a recorder that answers
 * as Pixonaut.
 */
final class VariationsSetupShopToolsTest extends WP_UnitTestCase {

	private const TOOL = 'set-variations-setup';

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

	public function test_variations_get_the_same_template_of_their_own_with_its_sides_checked_once(): void {
		$product = $this->poster();
		$small   = $this->variation( $product, 'Small' );
		$large   = $this->variation( $product, 'Large' );
		// Scripted once: a second variation asking again is an unscripted request.
		$this->recorder->json( 200, array( 'verdicts' => array( VerdictAnswers::LEGAL ) ) );
		$this->recorder->raw( 200, OpenApiAssert::example( 'GET', '/v1/templates/{id}', 200, array( 'status' => Catalogue::LIVE ) ), 'application/json' );

		$answer = $this->succeed(
			array(
				'product_id'    => $product->get_id(),
				'variation_ids' => array( $small->get_id(), $large->get_id() ),
				'own_setup'     => true,
				'blank_id'      => '',
				'sides'         => array( self::own_side() ),
			)
		);

		$this->assertSame( $product->get_id(), $answer['product_id'] );
		$this->assertSame( array( $small->get_id(), $large->get_id() ), array_column( $answer['variations'], 'variation_id' ), 'in the order given' );
		foreach ( $answer['variations'] as $one ) {
			$this->assertArrayNotHasKey( 'error', $one );
			$this->assertSame( $one['variation_id'], $one['product_id'] );
			$this->assertTrue( $one['own_setup'] );
			$this->assertTrue( $one['valid'], (string) $one['invalid_reason'] );
			$this->assertSame( self::OWN_TEMPLATE_ID, $one['sides'][0]['template_id'] );
		}
		foreach ( array( $small, $large ) as $variation ) {
			$stored = ProductConfig::stored_on( $this->reload( $variation ) );
			$this->assertTrue( $stored->override );
			$this->assertSame( 'mini-tee', $stored->sides[0]->template_base_id );
		}
		$this->assertCount( 2, $this->recorder->requests, 'the shared side is checked once' );
		$this->assertSame( self::PRODUCT_TEMPLATE_BASE_ID, ProductConfig::stored_on( $this->reload( $product ) )->sides[0]->template_base_id, 'the product keeps its own' );
	}

	public function test_variations_follow_their_product_again_and_keep_their_own_for_later(): void {
		$product = $this->poster();
		$small   = $this->variation( $product, 'Small' );
		$large   = $this->variation( $product, 'Large' );
		$own     = new Side( 'front', 'Front', null, null, 'mini-tee', 2, null, 'Mini tee' );
		foreach ( array( $small, $large ) as $variation ) {
			( new ProductConfig( personalise: true, override: true, sides: array( $own ) ) )->save_to( $variation );
		}

		$answer = $this->succeed(
			array(
				'product_id'    => $product->get_id(),
				'variation_ids' => array( $small->get_id(), $large->get_id() ),
				'own_setup'     => false,
			)
		);

		foreach ( $answer['variations'] as $one ) {
			$this->assertFalse( $one['own_setup'] );
			$this->assertSame( self::PRODUCT_TEMPLATE_BASE_ID . '@' . self::PRODUCT_TEMPLATE_VERSION, $one['sides'][0]['template_id'] );
		}
		foreach ( array( $small, $large ) as $variation ) {
			$stored = ProductConfig::stored_on( $this->reload( $variation ) );
			$this->assertFalse( $stored->override );
			$this->assertSame( 'mini-tee', $stored->sides[0]->template_base_id, 'its own is kept for later' );
		}
		$this->assertSame( array(), $this->recorder->requests, 'no call to Pixonaut' );
	}

	public function test_an_id_that_is_not_a_variation_of_the_product_saves_nothing(): void {
		$product = $this->poster();
		$small   = $this->variation( $product, 'Small' );
		$other   = $this->variation( $this->poster(), 'Large' );

		$answer = $this->call(
			array(
				'product_id'    => $product->get_id(),
				'variation_ids' => array( $small->get_id(), $other->get_id() ),
				'own_setup'     => true,
				'blank_id'      => '',
				'sides'         => array( self::own_side() ),
			)
		);

		$this->assertSame( 422, $answer->get_status() );
		$this->assertSame( 'invalid_input', $answer->get_data()['code'] );
		$this->assertStringContainsString( (string) $other->get_id(), $answer->get_data()['message'] );
		$this->assertFalse( ProductConfig::is_stored_on( $this->reload( $small ) ) );
		$this->assertSame( array(), $this->recorder->requests, 'no call to Pixonaut' );
	}

	/**
	 * The side a variation is given of its own, as the tool takes it.
	 *
	 * @return array<string, mixed>
	 */
	private static function own_side(): array {
		return array(
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
		);
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
