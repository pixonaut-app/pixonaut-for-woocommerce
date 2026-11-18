<?php
/**
 * @package Pixonaut\WooCommerce
 */

declare(strict_types=1);

namespace Pixonaut\WooCommerce\Tests\Integration\ShopTools;

use Pixonaut\WooCommerce\Abilities\RegenerateProductPictures;
use Pixonaut\WooCommerce\Admin\RegeneratePictures;
use Pixonaut\WooCommerce\Catalogue\PictureQueue;
use Pixonaut\WooCommerce\Catalogue\ProductPictures;
use Pixonaut\WooCommerce\Connection\StoreAccess;
use Pixonaut\WooCommerce\Tests\Integration\Catalogue\PicturedProducts;
use Pixonaut\WooCommerce\Tests\Support\ActionSchedulerRunner;
use WC_Product_Simple;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;
use WP_UnitTestCase;

/**
 * The shop tool that draws products' Pixonaut pictures again, called on the
 * shop tool route as Pixonaut calls it, with no user signed in.
 */
final class RegeneratePicturesShopToolTest extends WP_UnitTestCase {

	use PicturedProducts;

	private const ROUTE = '/pixonaut/v1/shop-tools/regenerate-product-pictures';

	private const SECRET = 'a-store-credential';

	private const OK = 200;

	private const UNPROCESSABLE = 422;

	public function set_up(): void {
		parent::set_up();
		$this->set_up_pictures();
		global $wp_rest_server;
		$wp_rest_server = new WP_REST_Server();
		do_action( 'rest_api_init', $wp_rest_server );
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
		wp_set_current_user( 0 );
	}

	public function tear_down(): void {
		delete_option( StoreAccess::OPTION );
		global $wp_rest_server;
		$wp_rest_server = null;
		$this->tear_down_pictures();
		parent::tear_down();
	}

	public function test_several_products_pictures_are_drawn_again(): void {
		$first  = $this->drawn( $this->product( self::config() ) )['product'];
		$second = $this->drawn( $this->product( self::config() ) )['product'];

		$response = $this->call( array( $first->get_id(), $second->get_id() ) );

		$this->assertSame( self::OK, $response->get_status() );
		$this->assertSame(
			array(
				'products' => array(
					array(
						'product_id' => $first->get_id(),
						'state'      => RegeneratePictures::DRAWING,
					),
					array(
						'product_id' => $second->get_id(),
						'state'      => RegeneratePictures::DRAWING,
					),
				),
			),
			$response->get_data()['result']
		);
		$this->recorder->raw( 200, self::png(), 'image/png' );
		$this->recorder->raw( 200, self::png(), 'image/png' );
		ActionSchedulerRunner::run_pending( ProductPictures::ACTION );
		$this->assertSame( RegeneratePictures::DRAWN, RegeneratePictures::state( $this->reload( $first ) ) );
		$this->assertSame( RegeneratePictures::DRAWN, RegeneratePictures::state( $this->reload( $second ) ) );
	}

	public function test_a_product_showing_no_picture_is_refused_as_input_and_nothing_is_drawn_again(): void {
		$pictured = $this->drawn( $this->product( self::config() ) )['product'];
		$plain    = new WC_Product_Simple();
		$plain->save();

		$response = $this->call( array( $pictured->get_id(), $plain->get_id() ) );

		$this->assertSame( self::UNPROCESSABLE, $response->get_status() );
		$this->assertSame( 'invalid_input', $response->get_data()['code'] );
		$this->assertStringContainsString( (string) $plain->get_id(), $response->get_data()['message'] );
		$this->assertFalse( PictureQueue::drawing( $this->reload( $pictured ) ) );
	}

	public function test_more_products_than_one_call_draws_are_refused_as_input(): void {
		$response = $this->call( range( 1, RegenerateProductPictures::MAX_PRODUCTS + 1 ) );

		$this->assertSame( self::UNPROCESSABLE, $response->get_status() );
		$this->assertSame( 'invalid_input', $response->get_data()['code'] );
	}

	/**
	 * Calls the tool.
	 *
	 * @param array<int> $product_ids The products named.
	 */
	private function call( array $product_ids ): WP_REST_Response {
		$request = new WP_REST_Request( 'POST', self::ROUTE );
		$request->set_header( 'Content-Type', 'application/json' );
		$request->set_header( 'Authorization', 'Bearer ' . self::SECRET );
		$request->set_body( (string) wp_json_encode( array( 'product_ids' => $product_ids ) ) );
		return rest_do_request( $request );
	}
}
