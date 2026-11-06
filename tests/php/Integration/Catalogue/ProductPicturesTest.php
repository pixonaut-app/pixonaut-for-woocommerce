<?php
/**
 * @package Pixonaut\WooCommerce
 */

declare(strict_types=1);

namespace Pixonaut\WooCommerce\Tests\Integration\Catalogue;

use Pixonaut\WooCommerce\Api\Hosts;
use Pixonaut\WooCommerce\Catalogue\PictureImage;
use Pixonaut\WooCommerce\Catalogue\PictureQueue;
use Pixonaut\WooCommerce\Catalogue\ProductConfig;
use Pixonaut\WooCommerce\Catalogue\ProductPictures;
use Pixonaut\WooCommerce\Catalogue\ProductRecheck;
use Pixonaut\WooCommerce\Connection\Connection;
use Pixonaut\WooCommerce\Tests\Support\ActionSchedulerRunner;
use Pixonaut\WooCommerce\Tests\Support\BlankFixture;
use Pixonaut\WooCommerce\Tests\Support\DesignFixture;
use Pixonaut\WooCommerce\Vendor\Pixonaut\Sdk\Webhooks\Event;
use Pixonaut\WooCommerce\Webhooks\Payload;
use WC_Product;
use WC_Product_Attribute;
use WC_Product_Simple;
use WC_Product_Variable;
use WC_Product_Variation;
use WP_REST_Request;
use WP_REST_Server;
use WP_UnitTestCase;

final class ProductPicturesTest extends WP_UnitTestCase {

	use PicturedProducts;

	public function set_up(): void {
		parent::set_up();
		$this->set_up_pictures();
	}

	public function tear_down(): void {
		$this->tear_down_pictures();
		parent::tear_down();
	}

	public function test_a_personalised_product_keeps_its_image_until_its_picture_is_drawn(): void {
		$product = $this->product( self::config() );

		$this->assertSame( $this->own_image, (int) $this->reload( $product )->get_image_id() );
		$this->assertCount( 1, ActionSchedulerRunner::pending( ProductPictures::ACTION ) );
		$this->assertSame( array(), $this->recorder->requests );
	}

	public function test_the_picture_is_its_template_on_its_variants_photo_of_the_first_placement(): void {
		$product = $this->product( self::config() );
		$this->recorder->raw( 200, self::png(), 'image/png' );

		ActionSchedulerRunner::run_pending( ProductPictures::ACTION );

		$request = $this->recorder->requests[0];
		$this->assertSame( 'GET', $request['method'] );
		$this->assertSame( Hosts::server_api() . '/t/mini-tee%402/mockup.png?blankId=' . DesignFixture::BLANK_ID . '&placementId=' . DesignFixture::PLACEMENT_ID . '&viewId=' . BlankFixture::VIEW_ID . '&variant=' . BlankFixture::VARIANT_REF . '&size=' . ProductPictures::SIZE, $request['url'] );
		$this->assertSame( 'Bearer ' . self::publishable_key(), $request['headers']['Authorization'] );
		$this->assertSame( Connection::origin( home_url() ), $request['headers']['Origin'] );
		$picture = $this->reload( $product )->get_image_id();
		$this->assertNotSame( $this->own_image, $picture );
		$this->assertSame( 'image/png', get_post_mime_type( (int) $picture ) );
		$this->assertSame( (int) $picture, get_post_thumbnail_id( $product->get_id() ) );
		$this->assertStringContainsString( (string) wp_get_attachment_image_url( (int) $picture, 'woocommerce_thumbnail' ), $this->reload( $product )->get_image() );
		$this->assertSame( array(), ActionSchedulerRunner::pending( ProductPictures::ACTION ) );
	}

	public function test_without_a_variant_the_picture_is_on_the_placements_first_photographed_view(): void {
		$product = $this->product( self::config(), false );
		$this->recorder->json( 200, BlankFixture::mockups( Hosts::server_api() ) );
		$this->recorder->raw( 200, self::png(), 'image/png' );

		ActionSchedulerRunner::run_pending( ProductPictures::ACTION );

		$this->assertStringContainsString( '/mockup.png?blankId=' . DesignFixture::BLANK_ID . '&placementId=' . DesignFixture::PLACEMENT_ID . '&viewId=view-front&size=', $this->recorder->requests[1]['url'] );
		$this->assertNotSame( $this->own_image, $this->reload( $product )->get_image_id() );
	}

	public function test_a_product_with_no_blank_shows_its_templates_picture(): void {
		$product = $this->product( self::config( null ), false );
		$this->recorder->raw( 200, self::png(), 'image/png' );

		ActionSchedulerRunner::run_pending( ProductPictures::ACTION );

		$this->assertSame( Hosts::server_api() . '/t/mini-tee%402/preview.png?size=' . ProductPictures::SIZE, $this->recorder->requests[0]['url'] );
		$this->assertNotSame( $this->own_image, $this->reload( $product )->get_image_id() );
	}

	public function test_each_variation_shows_its_own_picture(): void {
		$product   = new WC_Product_Variable();
		$attribute = new WC_Product_Attribute();
		$attribute->set_name( 'Colour' );
		$attribute->set_options( array( 'Red' ) );
		$attribute->set_variation( true );
		$product->set_attributes( array( $attribute ) );
		$product->set_image_id( $this->own_image );
		$product->save();
		self::config()->save_to( $product );
		$variation = new WC_Product_Variation();
		$variation->set_parent_id( $product->get_id() );
		$variation->set_attributes( array( 'colour' => 'Red' ) );
		$variation->set_regular_price( '20' );
		$variation->save();
		DesignFixture::sell_variant( $variation );
		// The variable product's picture first, on its placement's first photographed view.
		$this->recorder->json( 200, BlankFixture::mockups( Hosts::server_api() ) );
		$this->recorder->raw( 200, self::png(), 'image/png' );
		$this->recorder->raw( 200, self::png(), 'image/png' );

		ActionSchedulerRunner::run_pending( ProductPictures::ACTION );

		$this->assertStringContainsString( '&variant=' . BlankFixture::VARIANT_REF . '&', $this->recorder->requests[2]['url'] );
		$picture = $this->reload( $variation )->get_image_id();
		$this->assertNotSame( 0, $picture );
		$this->assertSame( $picture, get_post_thumbnail_id( $variation->get_id() ) );
		$this->assertNotSame( $picture, get_post_thumbnail_id( $product->get_id() ) );
		$parent = wc_get_product( $product->get_id() );
		$this->assertInstanceOf( WC_Product_Variable::class, $parent );
		$available = $parent->get_available_variation( $this->reload( $variation ) );
		$this->assertIsArray( $available );
		$this->assertSame( $picture, $available['image_id'] );
	}

	public function test_the_store_api_shows_the_picture(): void {
		$product = $this->product( self::config() );
		$this->recorder->raw( 200, self::png(), 'image/png' );
		ActionSchedulerRunner::run_pending( ProductPictures::ACTION );
		$GLOBALS['wp_rest_server'] = new WP_REST_Server();
		do_action( 'rest_api_init', $GLOBALS['wp_rest_server'] );

		$response = rest_get_server()->dispatch( new WP_REST_Request( 'GET', '/wc/store/v1/products/' . $product->get_id() ) );

		$images = $response->get_data()['images'] ?? array();
		$this->assertSame( (int) $this->reload( $product )->get_image_id(), $images[0]->id ?? null );
		$GLOBALS['wp_rest_server'] = null;
	}

	public function test_a_product_pixonaut_does_not_personalise_draws_no_picture(): void {
		$product = new WC_Product_Simple();
		$product->set_image_id( $this->own_image );
		$product->save();

		$this->assertSame( $this->own_image, (int) $this->reload( $product )->get_image_id() );
		$this->assertSame( array(), ActionSchedulerRunner::pending( ProductPictures::ACTION ) );
	}

	public function test_when_pixonaut_cannot_draw_it_the_product_keeps_its_image_and_tries_later(): void {
		$product = $this->product( self::config() );
		$this->recorder->fail();

		ActionSchedulerRunner::run_once( ProductPictures::ACTION );

		$this->assertSame( $this->own_image, (int) $this->reload( $product )->get_image_id() );
		$retry = ActionSchedulerRunner::due_at( ProductPictures::RETRY_ACTION );
		$this->assertCount( 1, $retry );
		$this->assertGreaterThan( time() + ProductPictures::RETRY_SECONDS - MINUTE_IN_SECONDS, $retry[0] );

		// A change fails again before the retry: still one retry, which queues the drawing.
		self::config( DesignFixture::BLANK_ID, DesignFixture::LIVE_VERSION + 1 )->save_to( $this->reload( $product ) );
		$this->recorder->fail();
		ActionSchedulerRunner::run_once( ProductPictures::ACTION );
		$this->assertCount( 1, ActionSchedulerRunner::pending( ProductPictures::RETRY_ACTION ) );
		ActionSchedulerRunner::run_once( ProductPictures::RETRY_ACTION );
		$this->assertCount( 1, ActionSchedulerRunner::pending( ProductPictures::ACTION ) );
	}

	public function test_a_changed_template_draws_the_picture_again_and_deletes_the_old_one(): void {
		$product = $this->product( self::config() );
		$this->recorder->raw( 200, self::png(), 'image/png' );
		ActionSchedulerRunner::run_pending( ProductPictures::ACTION );
		$old = $this->reload( $product )->get_image_id();

		self::config( DesignFixture::BLANK_ID, DesignFixture::LIVE_VERSION + 1 )->save_to( $this->reload( $product ) );
		$this->assertSame( $old, $this->reload( $product )->get_image_id() );
		$this->recorder->raw( 200, self::png(), 'image/png' );
		ActionSchedulerRunner::run_pending( ProductPictures::ACTION );

		$this->assertStringContainsString( '/t/mini-tee%403/mockup.png', $this->recorder->requests[1]['url'] );
		$this->assertNotSame( $old, $this->reload( $product )->get_image_id() );
		$this->assertSame( (int) $this->reload( $product )->get_image_id(), get_post_thumbnail_id( $product->get_id() ) );
		$this->assertNull( get_post( (int) $old ) );
	}

	public function test_a_product_no_longer_personalised_gets_its_image_back_and_drops_its_picture(): void {
		$picture = $this->drawn( $this->product( self::config() ) );

		self::config()->with_personalise( false )->save_to( $this->reload( $picture['product'] ) );
		ActionSchedulerRunner::run_pending( ProductPictures::ACTION );

		$this->assertSame( $this->own_image, get_post_thumbnail_id( $picture['product']->get_id() ) );
		$this->assertNull( get_post( $picture['id'] ) );
		$this->assertFalse( PictureImage::remembers( $picture['product'] ) );
	}

	public function test_deleting_the_product_deletes_its_picture(): void {
		$product = $this->product( self::config() );
		$this->recorder->raw( 200, self::png(), 'image/png' );
		ActionSchedulerRunner::run_pending( ProductPictures::ACTION );
		$picture = $this->reload( $product )->get_image_id();

		$this->reload( $product )->delete( true );

		$this->assertNull( get_post( (int) $picture ) );
		$this->assertNotNull( get_post( $this->own_image ) );
	}

	public function test_a_picture_deleted_from_the_media_library_is_drawn_again(): void {
		$picture = $this->drawn( $this->product( self::config() ) );
		$product = $this->reload( $picture['product'] );
		wp_delete_attachment( $picture['id'], true );

		$this->assertSame( 0, get_post_thumbnail_id( $product->get_id() ) );
		$this->recorder->raw( 200, self::png(), 'image/png' );
		ActionSchedulerRunner::run_pending( ProductPictures::ACTION );

		$this->assertTrue( PictureImage::is_picture( (int) get_post_thumbnail_id( $product->get_id() ), $product ) );
	}

	public function test_a_product_whose_configuration_is_deleted_gets_its_image_back_and_drops_its_picture(): void {
		$picture = $this->drawn( $this->product( self::config() ) );

		ProductConfig::delete_from( $this->reload( $picture['product'] ) );
		ActionSchedulerRunner::run_pending( ProductPictures::ACTION );

		$this->assertSame( $this->own_image, get_post_thumbnail_id( $picture['product']->get_id() ) );
		$this->assertNull( get_post( $picture['id'] ) );
		$this->assertSame( '', get_post_meta( $picture['product']->get_id(), ProductPictures::META_KEY, true ) );
	}

	public function test_bytes_that_are_no_image_keep_the_last_picture(): void {
		$picture = $this->drawn( $this->product( self::config() ) );
		ProductPictures::redraw_all();
		ActionSchedulerRunner::run_pending( PictureQueue::QUEUE_ACTION );

		$this->recorder->raw( 200, "\x89PNG\r\n\x1a\nnot an image", 'image/png' );
		ActionSchedulerRunner::run_pending( ProductPictures::ACTION );

		$this->assertSame( $picture['id'], (int) $this->reload( $picture['product'] )->get_image_id() );
		$this->assertCount(
			1,
			get_posts(
				array(
					'post_type'   => 'attachment',
					'post_parent' => $picture['product']->get_id(),
					'post_status' => 'any',
					'fields'      => 'ids',
				)
			)
		);
	}

	public function test_redrawing_all_draws_a_picture_of_the_same_ids_again(): void {
		$picture = $this->drawn( $this->product( self::config() ) );

		ProductPictures::redraw_all();
		ActionSchedulerRunner::run_pending( PictureQueue::QUEUE_ACTION );
		$this->recorder->raw( 200, self::png(), 'image/png' );
		ActionSchedulerRunner::run_pending( ProductPictures::ACTION );

		$this->assertCount( 2, $this->recorder->requests );
		$this->assertNotSame( $picture['id'], (int) $this->reload( $picture['product'] )->get_image_id() );
		$this->assertNull( get_post( $picture['id'] ) );
	}

	public function test_a_blank_update_redraws_every_picture(): void {
		$picture = $this->drawn( $this->product( self::config() ) );

		$this->plugin->product_recheck()->handle( Payload::event( 'evt_1', ProductRecheck::BLANK_UPDATED, 0, array( 'blankId' => DesignFixture::BLANK_ID ) ) );

		ActionSchedulerRunner::run_pending( PictureQueue::QUEUE_ACTION );
		$this->assertCount( 1, ActionSchedulerRunner::pending( ProductPictures::ACTION ) );
	}

	public function test_a_product_another_job_is_drawing_is_drawn_after_it(): void {
		$product = $this->product( self::config() );
		add_option( ProductPictures::LOCK_OPTION_PREFIX . $product->get_id(), (string) time(), '', false );

		ActionSchedulerRunner::run_once( ProductPictures::ACTION );

		$this->assertSame( array(), $this->recorder->requests );
		$this->assertCount( 1, ActionSchedulerRunner::pending( ProductPictures::ACTION ) );
		delete_option( ProductPictures::LOCK_OPTION_PREFIX . $product->get_id() );
	}

	public function test_a_change_while_pixonaut_draws_the_picture_is_drawn_after_it(): void {
		$product = $this->product( self::config() );
		$changes = static function ( $preempt ) use ( $product ) {
			$changed = wc_get_product( $product->get_id() );
			if ( $changed instanceof WC_Product ) {
				self::config( DesignFixture::BLANK_ID, DesignFixture::LIVE_VERSION + 1 )->save_to( $changed );
			}
			return $preempt;
		};
		add_filter( 'pre_http_request', $changes, 1 );
		$this->recorder->raw( 200, self::png(), 'image/png' );

		ActionSchedulerRunner::run_once( ProductPictures::ACTION );
		remove_filter( 'pre_http_request', $changes, 1 );

		$this->assertSame( $this->own_image, get_post_thumbnail_id( $product->get_id() ) );
		$this->assertCount( 1, ActionSchedulerRunner::pending( ProductPictures::ACTION ) );
	}

	public function test_a_configuration_written_directly_queues_the_picture(): void {
		$picture  = $this->drawn( $this->product( self::config() ) );
		$product  = $this->reload( $picture['product'] );
		$document = ProductConfig::document_on( $product );
		$this->assertIsString( $document );

		$this->assertTrue( self::config( DesignFixture::BLANK_ID, DesignFixture::LIVE_VERSION + 1 )->replace_on( $product, $document ) );

		$this->assertCount( 1, ActionSchedulerRunner::pending( ProductPictures::ACTION ) );
	}
}
