<?php
/**
 * @package Pixonaut\WooCommerce
 */

declare(strict_types=1);

namespace Pixonaut\WooCommerce\Tests\Integration\Admin;

use Pixonaut\WooCommerce\Admin\RegeneratePictures;
use Pixonaut\WooCommerce\Catalogue\PictureImage;
use Pixonaut\WooCommerce\Catalogue\PictureQueue;
use Pixonaut\WooCommerce\Catalogue\PictureRunner;
use Pixonaut\WooCommerce\Catalogue\ProductConfig;
use Pixonaut\WooCommerce\Catalogue\ProductPictures;
use Pixonaut\WooCommerce\Catalogue\ShowcaseImages;
use Pixonaut\WooCommerce\Plugin;
use Pixonaut\WooCommerce\Settings\Settings;
use Pixonaut\WooCommerce\Tests\Integration\Catalogue\PicturedProducts;
use Pixonaut\WooCommerce\Tests\Support\ActionSchedulerRunner;
use Pixonaut\WooCommerce\Tests\Support\DesignFixture;
use WC_Product;
use WC_Product_Attribute;
use WC_Product_Simple;
use WC_Product_Variable;
use WC_Product_Variation;
use WP_REST_Request;
use WP_REST_Server;
use WP_UnitTestCase;

/**
 * Regenerate product image, on the product's Pixonaut tab and as a bulk
 * action on the products list, draws its pictures again.
 */
final class RegeneratePicturesTest extends WP_UnitTestCase {

	use PicturedProducts;

	private const REDIRECT = 'http://example.org/wp-admin/edit.php?post_type=product';

	private const FORBIDDEN = 403;

	private const CONFLICT = 409;

	private const ANNA = array(
		'id'   => 'showcase-anna',
		'name' => 'Anna, class of 2026',
		'kind' => ShowcaseImages::MOCKUP,
	);

	private const TEAM = array(
		'id'   => 'showcase-team',
		'name' => 'Team photo',
		'kind' => ShowcaseImages::PREVIEW,
	);

	public function set_up(): void {
		parent::set_up();
		$this->set_up_pictures();
		wp_set_current_user( self::user( 'shop_manager' ) );
		$GLOBALS['wp_rest_server'] = new WP_REST_Server();
		do_action( 'rest_api_init', $GLOBALS['wp_rest_server'] );
	}

	public function tear_down(): void {
		$GLOBALS['wp_rest_server'] = null;
		$this->tear_down_pictures();
		parent::tear_down();
	}

	public function test_regenerating_draws_the_picture_again_and_drops_the_one_it_replaces(): void {
		$picture = $this->drawn( $this->product( self::config() ) );

		ProductPictures::regenerate( $this->reload( $picture['product'] ) );
		$this->recorder->raw( 200, self::png(), 'image/png' );
		ActionSchedulerRunner::run_pending( ProductPictures::ACTION );

		$this->assertCount( 2, $this->recorder->requests );
		$this->assertSame( $this->recorder->requests[0]['url'], $this->recorder->requests[1]['url'] );
		$redrawn = (int) $this->reload( $picture['product'] )->get_image_id();
		$this->assertNotSame( $picture['id'], $redrawn );
		$this->assertSame( $redrawn, get_post_thumbnail_id( $picture['product']->get_id() ) );
		$this->assertNull( get_post( $picture['id'] ) );
	}

	public function test_a_product_never_regenerated_is_not_drawn_again(): void {
		$picture = $this->drawn( $this->product( self::config() ) );

		( new PictureQueue( $this->plugin->settings() ) )->changed( $picture['product']->get_id() );

		$this->assertSame( array(), ActionSchedulerRunner::pending( ProductPictures::ACTION ) );
	}

	public function test_regenerating_a_variable_product_draws_its_variations_again(): void {
		$product   = $this->variable();
		$variation = $product->get_children()[0];
		$this->recorder->raw( 200, self::png(), 'image/png' );
		$this->recorder->raw( 200, self::png(), 'image/png' );
		ActionSchedulerRunner::run_pending( PictureQueue::VARIATIONS_ACTION );
		ActionSchedulerRunner::run_pending( ProductPictures::ACTION );
		$drawn = get_post_thumbnail_id( $variation );

		ProductPictures::regenerate( $this->reload( $product ) );
		ActionSchedulerRunner::run_pending( PictureQueue::VARIATIONS_ACTION );
		$this->recorder->raw( 200, self::png(), 'image/png' );
		$this->recorder->raw( 200, self::png(), 'image/png' );
		ActionSchedulerRunner::run_pending( ProductPictures::ACTION );

		$this->assertNotSame( $drawn, get_post_thumbnail_id( $variation ) );
		$this->assertNull( get_post( (int) $drawn ) );
	}

	public function test_the_route_queues_the_drawing_and_says_when_it_is_done(): void {
		$picture = $this->drawn( $this->product( self::config() ) );
		$id      = $picture['product']->get_id();

		$queued = $this->request( 'POST', $id );
		$this->assertSame( array( 'state' => RegeneratePictures::DRAWING ), $queued->get_data() );
		$this->assertSame( array( 'state' => RegeneratePictures::DRAWING ), $this->request( 'GET', $id )->get_data() );

		$this->recorder->raw( 200, self::png(), 'image/png' );
		ActionSchedulerRunner::run_pending( ProductPictures::ACTION );

		$this->assertSame( array( 'state' => RegeneratePictures::DRAWN ), $this->request( 'GET', $id )->get_data() );
	}

	public function test_the_route_says_when_pixonaut_could_not_draw_it(): void {
		$picture = $this->drawn( $this->product( self::config() ) );
		$id      = $picture['product']->get_id();

		$this->request( 'POST', $id );
		$this->recorder->fail();
		ActionSchedulerRunner::run_pending( ProductPictures::ACTION );

		$this->assertSame(
			array(
				'state'  => RegeneratePictures::FAILED,
				'failed' => 'Pixonaut could not draw the pictures of “Personalised tee”. They keep the images they have, and are tried again later.',
			),
			$this->request( 'GET', $id )->get_data()
		);
		$this->assertSame( $picture['id'], (int) $this->reload( $picture['product'] )->get_image_id() );
	}

	public function test_the_route_says_the_own_image_is_kept(): void {
		$picture = $this->drawn( $this->product( self::config() ) );
		$product = $this->reload( $picture['product'] );
		$product->set_image_id( $this->image() );
		$product->save();
		ActionSchedulerRunner::run_pending( ProductPictures::ACTION );

		$this->assertSame( array( 'state' => RegeneratePictures::KEPT ), $this->request( 'POST', $product->get_id() )->get_data() );
		$this->assertSame( array(), ActionSchedulerRunner::pending( ProductPictures::ACTION ) );
	}

	public function test_the_route_says_the_own_image_is_kept_while_the_gallery_is_drawn_again(): void {
		$product = $this->product( self::config() );
		ShowcaseImages::save( $product, array( self::ANNA, self::TEAM ) );
		$this->recorder->raw( 200, self::png(), 'image/png' );
		$this->recorder->raw( 200, self::png(), 'image/png' );
		ActionSchedulerRunner::run_pending( ProductPictures::ACTION );
		update_post_meta( $product->get_id(), PictureImage::THUMBNAIL_META, $this->image() );
		ActionSchedulerRunner::run_pending( ProductPictures::ACTION );

		$this->assertSame( array( 'state' => RegeneratePictures::DRAWING ), $this->request( 'POST', $product->get_id() )->get_data() );
		$this->recorder->raw( 200, self::png(), 'image/png' );
		$this->recorder->raw( 200, self::png(), 'image/png' );
		ActionSchedulerRunner::run_pending( ProductPictures::ACTION );

		$this->assertSame( array( 'state' => RegeneratePictures::KEPT ), $this->request( 'GET', $product->get_id() )->get_data() );
	}

	public function test_the_route_says_when_the_drawing_could_not_be_queued(): void {
		$picture = $this->drawn( $this->product( self::config() ) );
		add_filter( 'pre_as_enqueue_async_action', static fn (): int => 0 );

		$this->assertSame( array( 'state' => RegeneratePictures::NOT_QUEUED ), $this->request( 'POST', $picture['product']->get_id() )->get_data() );
		$redirect = $this->plugin->regenerate_pictures()->handle_bulk( self::REDIRECT, RegeneratePictures::BULK_ACTION, array( $picture['product']->get_id() ) );

		$this->assertSame( add_query_arg( RegeneratePictures::DONE_ARG, 0, self::REDIRECT ), $redirect );
	}

	public function test_the_route_follows_a_variable_products_variations_and_names_the_one_that_could_not_be_drawn(): void {
		$product = $this->variable();
		$id      = $product->get_id();
		// Drawn from another template version than the product, so the variation has a picture of its own.
		$override   = new ProductConfig( personalise: true, blank_id: null, sides: self::config( null, DesignFixture::LIVE_VERSION - 1 )->sides, override: true );
		$own_config = wc_get_product( $product->get_children()[0] );
		$this->assertInstanceOf( WC_Product::class, $own_config );
		$override->save_to( $own_config );
		$this->recorder->raw( 200, self::png(), 'image/png' );
		$this->recorder->raw( 200, self::png(), 'image/png' );
		ActionSchedulerRunner::run_pending( PictureQueue::VARIATIONS_ACTION );
		ActionSchedulerRunner::run_pending( ProductPictures::ACTION );

		$this->request( 'POST', $id );
		$this->assertSame( array( 'state' => RegeneratePictures::DRAWING ), $this->request( 'GET', $id )->get_data() );
		// The product's drawing was queued first, its variation's second.
		$this->recorder->raw( 200, self::png(), 'image/png' );
		$this->recorder->fail();
		$this->assertGreaterThanOrEqual( 2, ( new PictureRunner() )->run() );

		$variation = wc_get_product( $product->get_children()[0] );
		$this->assertInstanceOf( WC_Product::class, $variation );
		$this->assertSame(
			array(
				'state'  => RegeneratePictures::FAILED,
				'failed' => sprintf( 'Pixonaut could not draw the pictures of “%s”. They keep the images they have, and are tried again later.', $variation->get_name() ),
			),
			$this->request( 'GET', $id )->get_data()
		);
	}

	public function test_the_route_queues_the_variations_at_once_and_starts_the_runners(): void {
		$product = $this->variable();
		$id      = $product->get_id();
		$this->recorder->raw( 200, self::png(), 'image/png' );
		$this->recorder->raw( 200, self::png(), 'image/png' );
		ActionSchedulerRunner::run_pending( PictureQueue::VARIATIONS_ACTION );
		ActionSchedulerRunner::run_pending( ProductPictures::ACTION );
		$runners = array();
		add_filter(
			'pre_http_request',
			static function ( $preempt, array $args, string $url ) use ( &$runners ) {
				if ( str_contains( $url, 'action=pixonaut_wc_pictures' ) ) {
					$runners[] = $args['blocking'];
					return array(
						'headers'  => array(),
						'body'     => '',
						'response' => array(
							'code'    => 200,
							'message' => 'OK',
						),
						'cookies'  => array(),
					);
				}
				return $preempt;
			},
			10,
			3
		);

		$this->request( 'POST', $id );

		$this->assertSame( array_fill( 0, PictureRunner::RUNNERS, false ), $runners );
		$this->assertSame( array(), ActionSchedulerRunner::pending( PictureQueue::VARIATIONS_ACTION ) );
		$queued = array_map(
			static fn ( $action ): int => (int) $action->get_args()[0],
			as_get_scheduled_actions(
				array(
					'hook'   => ProductPictures::ACTION,
					'status' => \ActionScheduler_Store::STATUS_PENDING,
				)
			)
		);
		sort( $queued );
		$this->assertSame( array( $id, $product->get_children()[0] ), $queued );
	}

	public function test_a_products_image_and_gallery_pictures_are_drawn_at_once(): void {
		$product = $this->product( self::config() );
		ShowcaseImages::save( $product, array( self::ANNA, self::TEAM ) );
		$sequence = array();
		add_action(
			'pixonaut_wc_api_before_request',
			static function () use ( &$sequence ): void {
				$sequence[] = 'sent';
			}
		);
		add_action(
			'pixonaut_wc_api_after_request',
			static function () use ( &$sequence ): void {
				$sequence[] = 'answered';
			}
		);
		$this->recorder->raw( 200, self::png(), 'image/png' );
		$this->recorder->raw( 200, self::png(), 'image/png' );

		ActionSchedulerRunner::run_pending( ProductPictures::ACTION );

		$this->assertSame( array( 'sent', 'sent', 'answered', 'answered' ), array_slice( $sequence, -4 ) );
		$this->assertCount( 1, $this->reload( $product )->get_gallery_image_ids() );
		$this->assertSame( RegeneratePictures::DRAWN, RegeneratePictures::state( $this->reload( $product ) ) );
	}

	public function test_the_route_refuses_a_product_that_shows_no_picture(): void {
		$product = new WC_Product_Simple();
		$product->save();

		$this->assertSame( self::CONFLICT, $this->request( 'POST', $product->get_id() )->get_status() );
		$this->assertSame( '', $product->get_meta( ProductPictures::PRODUCT_GENERATION_META ) );
	}

	public function test_the_route_refuses_a_user_who_cannot_edit_the_product(): void {
		$picture = $this->drawn( $this->product( self::config() ) );
		wp_set_current_user( self::user( 'customer' ) );

		$this->assertSame( self::FORBIDDEN, $this->request( 'POST', $picture['product']->get_id() )->get_status() );
		$this->assertSame( array(), ActionSchedulerRunner::pending( ProductPictures::ACTION ) );
	}

	public function test_the_pixonaut_tab_has_the_button_while_the_product_shows_a_picture(): void {
		$product      = $this->product( self::config() );
		$regenerate   = $this->plugin->regenerate_pictures();
		$unconfigured = new WC_Product_Simple();
		$unconfigured->save();

		$html = $regenerate->html( $product );

		$this->assertStringContainsString( 'Regenerate in background', $html );
		$this->assertStringContainsString( 'class="button pixonaut-regenerate__now" data-product-id="' . $product->get_id() . '"', $html );
		$this->assertStringContainsString( 'data-url="' . esc_url( rest_url( Plugin::REST_NAMESPACE . '/products/' . $product->get_id() . '/pictures' ) ) . '"', $html );
		$this->assertSame( '', $regenerate->html( $unconfigured ) );
		update_option( Settings::OPTION, array( Settings::PRODUCT_PICTURES => false ) );
		$this->assertSame( '', $regenerate->html( $this->reload( $product ) ) );
	}

	public function test_the_bulk_action_regenerates_the_selected_products_that_show_a_picture(): void {
		$first        = $this->drawn( $this->product( self::config() ) )['product'];
		$second       = $this->drawn( $this->product( self::config() ) )['product'];
		$unconfigured = new WC_Product_Simple();
		$unconfigured->save();
		$regenerate = $this->plugin->regenerate_pictures();

		$this->assertArrayHasKey( RegeneratePictures::BULK_ACTION, $regenerate->bulk_actions( array() ) );
		$redirect = $regenerate->handle_bulk( self::REDIRECT, RegeneratePictures::BULK_ACTION, array( $first->get_id(), $second->get_id(), $unconfigured->get_id() ) );

		$this->assertSame( add_query_arg( RegeneratePictures::DONE_ARG, 2, self::REDIRECT ), $redirect );
		$this->assertCount( 2, ActionSchedulerRunner::pending( ProductPictures::ACTION ) );
		$this->assertSame( 'other', $regenerate->handle_bulk( 'other', 'trash', array( $first->get_id() ) ) );
	}

	public function test_the_bulk_action_does_not_count_a_product_keeping_its_own_image_with_nothing_else_to_draw(): void {
		$drawn = $this->drawn( $this->product( self::config() ) )['product'];
		$kept  = $this->reload( $this->drawn( $this->product( self::config() ) )['product'] );
		$kept->set_image_id( $this->image() );
		$kept->save();
		ActionSchedulerRunner::run_pending( ProductPictures::ACTION );

		$redirect = $this->plugin->regenerate_pictures()->handle_bulk( self::REDIRECT, RegeneratePictures::BULK_ACTION, array( $drawn->get_id(), $kept->get_id() ) );

		$this->assertSame( add_query_arg( RegeneratePictures::DONE_ARG, 1, self::REDIRECT ), $redirect );
	}

	public function test_the_bulk_action_is_offered_only_while_pictures_are_product_images(): void {
		update_option( Settings::OPTION, array( Settings::PRODUCT_PICTURES => false ) );

		$this->assertSame( array(), $this->plugin->regenerate_pictures()->bulk_actions( array() ) );
	}

	/**
	 * A new user with the role.
	 *
	 * @param string $role A role.
	 */
	private static function user( string $role ): int {
		$user = self::factory()->user->create( array( 'role' => $role ) );
		self::assertIsInt( $user );
		return $user;
	}

	/**
	 * @param string $method GET or POST.
	 * @param int    $id     The product.
	 */
	private function request( string $method, int $id ): \WP_REST_Response {
		return rest_get_server()->dispatch( new WP_REST_Request( $method, '/' . Plugin::REST_NAMESPACE . '/products/' . $id . '/pictures' ) );
	}

	/**
	 * A configured variable product with one variation selling the fixture's variant.
	 */
	private function variable(): WC_Product {
		$product   = new WC_Product_Variable();
		$attribute = new WC_Product_Attribute();
		$attribute->set_name( 'Colour' );
		$attribute->set_options( array( 'Red' ) );
		$attribute->set_variation( true );
		$product->set_attributes( array( $attribute ) );
		$product->set_image_id( $this->own_image );
		$product->save();
		self::config( null )->save_to( $product );
		$variation = new WC_Product_Variation();
		$variation->set_parent_id( $product->get_id() );
		$variation->set_attributes( array( 'colour' => 'Red' ) );
		$variation->set_regular_price( '20' );
		$variation->save();
		return $this->reload( $product );
	}
}
