<?php
/**
 * @package Pixonaut\WooCommerce
 */

declare(strict_types=1);

namespace Pixonaut\WooCommerce\Tests\Integration\Catalogue;

use Pixonaut\WooCommerce\Api\Hosts;
use Pixonaut\WooCommerce\Catalogue\PictureGallery;
use Pixonaut\WooCommerce\Catalogue\PictureImage;
use Pixonaut\WooCommerce\Catalogue\ProductPictures;
use Pixonaut\WooCommerce\Catalogue\ShowcaseImages;
use Pixonaut\WooCommerce\Tests\Support\ActionSchedulerRunner;
use Pixonaut\WooCommerce\Tests\Support\BlankFixture;
use Pixonaut\WooCommerce\Tests\Support\DesignFixture;
use WC_Product;
use WC_Product_Attribute;
use WC_Product_Variable;
use WC_Product_Variation;
use WP_UnitTestCase;

final class ShowcasePicturesTest extends WP_UnitTestCase {

	use PicturedProducts;

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
	}

	public function tear_down(): void {
		$this->tear_down_pictures();
		parent::tear_down();
	}

	public function test_the_first_set_is_the_image_and_the_others_lead_the_gallery(): void {
		$product = $this->showcased( array( self::ANNA, self::TEAM ) );
		$own     = $this->image();
		update_post_meta( $product->get_id(), PictureGallery::GALLERY_META, (string) $own );
		$this->recorder->raw( 200, self::png(), 'image/png' );
		$this->recorder->raw( 200, self::png(), 'image/png' );

		ActionSchedulerRunner::run_pending( ProductPictures::ACTION );

		$this->assertSame( Hosts::server_api() . '/t/mini-tee%402/mockup.png?blankId=' . DesignFixture::BLANK_ID . '&placementId=' . DesignFixture::PLACEMENT_ID . '&viewId=' . BlankFixture::VIEW_ID . '&variant=' . BlankFixture::VARIANT_REF . '&size=' . ProductPictures::SIZE . '&showcase=showcase-anna', $this->recorder->requests[0]['url'] );
		$this->assertSame( Hosts::server_api() . '/t/mini-tee%402/preview.png?size=' . ProductPictures::SIZE . '&showcase=showcase-team', $this->recorder->requests[1]['url'] );
		$product = $this->reload( $product );
		$image   = (int) $product->get_image_id();
		$this->assertTrue( PictureImage::is_picture( $image, $product ) );
		$gallery = $product->get_gallery_image_ids();
		$this->assertCount( 2, $gallery );
		$this->assertTrue( PictureImage::is_picture( (int) $gallery[0], $product ) );
		$this->assertNotSame( $image, (int) $gallery[0] );
		$this->assertSame( $own, (int) $gallery[1] );
	}

	public function test_without_sets_the_picture_is_drawn_as_before_and_the_gallery_is_left_alone(): void {
		$product = $this->product( self::config() );
		$own     = array( $this->image(), $this->image() );
		update_post_meta( $product->get_id(), PictureGallery::GALLERY_META, implode( ',', $own ) );
		$this->recorder->raw( 200, self::png(), 'image/png' );

		ActionSchedulerRunner::run_pending( ProductPictures::ACTION );

		$this->assertCount( 1, $this->recorder->requests );
		$this->assertStringNotContainsString( 'showcase', $this->recorder->requests[0]['url'] );
		$product = $this->reload( $product );
		$this->assertTrue( PictureImage::is_picture( (int) $product->get_image_id(), $product ) );
		$this->assertSame( $own, array_map( 'intval', $product->get_gallery_image_ids() ) );
		$this->assertSame( array(), ProductPictures::stored( $product )['gallery'] );
	}

	public function test_without_sets_the_store_managers_image_and_gallery_are_left_alone(): void {
		$product = $this->product( self::config() );
		$this->recorder->raw( 200, self::png(), 'image/png' );
		ActionSchedulerRunner::run_pending( ProductPictures::ACTION );
		$own     = $this->image();
		$gallery = $this->image();
		update_post_meta( $product->get_id(), PictureImage::THUMBNAIL_META, $own );
		update_post_meta( $product->get_id(), PictureGallery::GALLERY_META, (string) $gallery );

		ProductPictures::redraw_all();
		ActionSchedulerRunner::run_pending( ProductPictures::ACTION );

		$this->assertCount( 1, $this->recorder->requests );
		$product = $this->reload( $product );
		$this->assertSame( $own, (int) $product->get_image_id() );
		$this->assertSame( array( $gallery ), array_map( 'intval', $product->get_gallery_image_ids() ) );
	}

	public function test_a_drawing_again_asks_only_for_what_changed(): void {
		$product = $this->showcased( array( self::ANNA ) );
		$this->recorder->raw( 200, self::png(), 'image/png' );
		ActionSchedulerRunner::run_pending( ProductPictures::ACTION );
		$first = (int) $this->reload( $product )->get_image_id();

		ShowcaseImages::save( $this->reload( $product ), array( self::ANNA, self::TEAM ) );
		$this->recorder->raw( 200, self::png(), 'image/png' );
		ActionSchedulerRunner::run_pending( ProductPictures::ACTION );

		$this->assertCount( 2, $this->recorder->requests );
		$this->assertStringContainsString( 'showcase=showcase-team', $this->recorder->requests[1]['url'] );
		$product = $this->reload( $product );
		$this->assertSame( $first, (int) $product->get_image_id() );
		$this->assertCount( 1, $product->get_gallery_image_ids() );
	}

	public function test_a_set_the_template_no_longer_has_shows_its_defaults_and_is_recorded_gone(): void {
		$product = $this->showcased( array( self::ANNA ) );
		$this->recorder->json( 404, array( 'error' => 'showcase_not_found' ) );
		$this->recorder->raw( 200, self::png(), 'image/png' );

		ActionSchedulerRunner::run_pending( ProductPictures::ACTION );

		$this->assertStringContainsString( 'showcase=showcase-anna', $this->recorder->requests[0]['url'] );
		$this->assertStringNotContainsString( 'showcase', $this->recorder->requests[1]['url'] );
		$product = $this->reload( $product );
		$this->assertTrue( PictureImage::is_picture( (int) $product->get_image_id(), $product ) );
		$this->assertSame( array( 'showcase-anna' ), ProductPictures::stored( $product )['gone'] );
		$this->assertSame( array(), ActionSchedulerRunner::pending( ProductPictures::RETRY_ACTION ) );
	}

	public function test_unticking_the_sets_takes_their_pictures_out_of_the_gallery(): void {
		$product = $this->showcased( array( self::ANNA, self::TEAM ) );
		$own     = $this->image();
		update_post_meta( $product->get_id(), PictureGallery::GALLERY_META, (string) $own );
		$this->recorder->raw( 200, self::png(), 'image/png' );
		$this->recorder->raw( 200, self::png(), 'image/png' );
		ActionSchedulerRunner::run_pending( ProductPictures::ACTION );
		$gallery_picture = (int) $this->reload( $product )->get_gallery_image_ids()[0];

		ShowcaseImages::save( $this->reload( $product ), array() );
		$this->recorder->raw( 200, self::png(), 'image/png' );
		ActionSchedulerRunner::run_pending( ProductPictures::ACTION );

		$this->assertStringNotContainsString( 'showcase', $this->recorder->requests[2]['url'] );
		$this->assertSame( array( $own ), array_map( 'intval', $this->reload( $product )->get_gallery_image_ids() ) );
		$this->assertNull( get_post( $gallery_picture ) );
	}

	public function test_a_product_no_longer_personalised_gets_its_gallery_back(): void {
		$product = $this->showcased( array( self::ANNA, self::TEAM ) );
		$this->recorder->raw( 200, self::png(), 'image/png' );
		$this->recorder->raw( 200, self::png(), 'image/png' );
		ActionSchedulerRunner::run_pending( ProductPictures::ACTION );

		self::config()->with_personalise( false )->save_to( $this->reload( $product ) );
		ActionSchedulerRunner::run_pending( ProductPictures::ACTION );

		$product = $this->reload( $product );
		$this->assertSame( $this->own_image, (int) $product->get_image_id() );
		$this->assertSame( array(), $product->get_gallery_image_ids() );
	}

	public function test_a_variation_shows_its_products_first_set(): void {
		$product   = new WC_Product_Variable();
		$attribute = new WC_Product_Attribute();
		$attribute->set_name( 'Colour' );
		$attribute->set_options( array( 'Red' ) );
		$attribute->set_variation( true );
		$product->set_attributes( array( $attribute ) );
		$product->save();
		self::config()->save_to( $product );
		ShowcaseImages::save( $product, array( self::ANNA, self::TEAM ) );
		$variation = new WC_Product_Variation();
		$variation->set_parent_id( $product->get_id() );
		$variation->set_attributes( array( 'colour' => 'Red' ) );
		$variation->set_regular_price( '20' );
		$variation->save();
		DesignFixture::sell_variant( $variation );
		$this->recorder->json( 200, BlankFixture::mockups( Hosts::server_api() ) );
		foreach ( range( 1, 3 ) as $picture ) {
			$this->recorder->raw( 200, self::png(), 'image/png' );
		}

		ActionSchedulerRunner::run_pending( ProductPictures::ACTION );

		$variation_requests = array_values( array_filter( $this->recorder->requests, static fn ( array $request ): bool => str_contains( $request['url'], '&variant=' ) ) );
		$this->assertCount( 1, $variation_requests );
		$this->assertStringEndsWith( '&showcase=showcase-anna', $variation_requests[0]['url'] );
		$this->assertSame( array(), $this->reload( $variation )->get_gallery_image_ids() );
	}

	public function test_a_set_added_while_the_store_managers_image_is_kept_joins_the_gallery(): void {
		$product = $this->showcased( array( self::ANNA ) );
		$this->recorder->raw( 200, self::png(), 'image/png' );
		ActionSchedulerRunner::run_pending( ProductPictures::ACTION );
		$own = $this->image();
		update_post_meta( $product->get_id(), PictureImage::THUMBNAIL_META, $own );

		ShowcaseImages::save( $this->reload( $product ), array( self::ANNA, self::TEAM ) );
		$this->recorder->raw( 200, self::png(), 'image/png' );
		ActionSchedulerRunner::run_pending( ProductPictures::ACTION );

		$product = $this->reload( $product );
		$this->assertSame( $own, (int) $product->get_image_id() );
		$gallery = $product->get_gallery_image_ids();
		$this->assertCount( 1, $gallery );
		$this->assertTrue( PictureImage::is_picture( (int) $gallery[0], $product ) );
	}

	public function test_unticking_the_sets_while_the_store_managers_image_is_kept_empties_the_gallery(): void {
		$product = $this->showcased( array( self::ANNA, self::TEAM ) );
		$this->recorder->raw( 200, self::png(), 'image/png' );
		$this->recorder->raw( 200, self::png(), 'image/png' );
		ActionSchedulerRunner::run_pending( ProductPictures::ACTION );
		$own = $this->image();
		update_post_meta( $product->get_id(), PictureImage::THUMBNAIL_META, $own );

		ShowcaseImages::save( $this->reload( $product ), array() );
		ActionSchedulerRunner::run_pending( ProductPictures::ACTION );

		$this->assertCount( 2, $this->recorder->requests );
		$product = $this->reload( $product );
		$this->assertSame( $own, (int) $product->get_image_id() );
		$this->assertSame( array(), $product->get_gallery_image_ids() );
		$this->assertSame( array(), ProductPictures::stored( $product )['gallery'] );
	}

	public function test_flat_sets_alone_do_not_read_the_blanks_views(): void {
		$product   = new WC_Product_Variable();
		$attribute = new WC_Product_Attribute();
		$attribute->set_name( 'Colour' );
		$attribute->set_options( array( 'Red' ) );
		$attribute->set_variation( true );
		$product->set_attributes( array( $attribute ) );
		$product->save();
		self::config()->save_to( $product );
		ShowcaseImages::save( $product, array( self::TEAM ) );
		$this->recorder->raw( 200, self::png(), 'image/png' );

		ActionSchedulerRunner::run_pending( ProductPictures::ACTION );

		$this->assertCount( 1, $this->recorder->requests );
		$this->assertSame( Hosts::server_api() . '/t/mini-tee%402/preview.png?size=' . ProductPictures::SIZE . '&showcase=showcase-team', $this->recorder->requests[0]['url'] );
	}

	public function test_a_job_leaves_the_lock_another_job_took_over_while_it_drew(): void {
		$product = $this->showcased( array( self::ANNA ) );
		$this->recorder->raw( 200, self::png(), 'image/png' );
		$lock      = ProductPictures::LOCK_OPTION_PREFIX . $product->get_id();
		$take_over = static function ( mixed $response ) use ( $lock ): mixed {
			update_option( $lock, '1', false );
			return $response;
		};
		add_filter( 'pre_http_request', $take_over, 1 );

		ActionSchedulerRunner::run_pending( ProductPictures::ACTION );
		remove_filter( 'pre_http_request', $take_over, 1 );

		wp_cache_delete( $lock, 'options' );
		$this->assertSame( '1', get_option( $lock ) );
		delete_option( $lock );
	}

	/**
	 * A personalised product whose images show the sets.
	 *
	 * @param list<array{id: string, name: string, kind: string}> $sets The chosen sets.
	 */
	private function showcased( array $sets ): WC_Product {
		$product = $this->product( self::config() );
		ShowcaseImages::save( $product, $sets );
		return $this->reload( $product );
	}
}
