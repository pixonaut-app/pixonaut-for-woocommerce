<?php
/**
 * @package Pixonaut\WooCommerce
 */

declare(strict_types=1);

namespace Pixonaut\WooCommerce\Tests\Integration\Catalogue;

use Pixonaut\WooCommerce\Admin\PictureFields;
use Pixonaut\WooCommerce\Catalogue\PictureImage;
use Pixonaut\WooCommerce\Catalogue\PictureQueue;
use Pixonaut\WooCommerce\Catalogue\ProductPictures;
use Pixonaut\WooCommerce\Migrations\ReplaceKeptImages;
use Pixonaut\WooCommerce\Schema;
use Pixonaut\WooCommerce\Settings\Settings;
use Pixonaut\WooCommerce\Tests\Support\ActionSchedulerRunner;
use WC_Product_Simple;
use WP_UnitTestCase;

final class PictureImageTest extends WP_UnitTestCase {

	use PicturedProducts;

	public function set_up(): void {
		parent::set_up();
		$this->set_up_pictures();
	}

	public function tear_down(): void {
		$this->tear_down_pictures();
		parent::tear_down();
	}

	public function test_the_first_picture_remembers_the_image_it_replaces(): void {
		$picture = $this->drawn( $this->product( self::config() ) );

		$this->assertTrue( PictureImage::remembers( $picture['product'] ) );
		$this->assertSame( (string) $this->own_image, get_post_meta( $picture['product']->get_id(), PictureImage::PREVIOUS_META, true ) );
	}

	public function test_an_image_the_store_manager_sets_is_never_replaced_by_a_redraw(): void {
		$picture = $this->drawn( $this->product( self::config() ) );
		$mine    = $this->image();

		update_post_meta( $picture['product']->get_id(), PictureImage::THUMBNAIL_META, $mine );
		$this->assertSame( array(), ActionSchedulerRunner::pending( ProductPictures::ACTION ) );
		self::config( null )->save_to( $this->reload( $picture['product'] ) );
		ProductPictures::redraw_all();
		ActionSchedulerRunner::run_pending( PictureQueue::QUEUE_ACTION );
		ActionSchedulerRunner::run_pending( ProductPictures::ACTION );

		$this->assertSame( $mine, get_post_thumbnail_id( $picture['product']->get_id() ) );
		$this->assertCount( 1, $this->recorder->requests );
	}

	public function test_removing_the_store_managers_image_brings_the_picture_back(): void {
		$picture = $this->drawn( $this->product( self::config() ) );
		update_post_meta( $picture['product']->get_id(), PictureImage::THUMBNAIL_META, $this->image() );

		delete_post_thumbnail( $picture['product']->get_id() );
		ActionSchedulerRunner::run_pending( ProductPictures::ACTION );

		$this->assertSame( $picture['id'], get_post_thumbnail_id( $picture['product']->get_id() ) );
		$this->assertCount( 1, $this->recorder->requests );
	}

	public function test_unlinking_keeps_an_image_the_store_manager_set_since(): void {
		$picture = $this->drawn( $this->product( self::config() ) );
		$mine    = $this->image();
		update_post_meta( $picture['product']->get_id(), PictureImage::THUMBNAIL_META, $mine );

		self::config()->with_personalise( false )->save_to( $this->reload( $picture['product'] ) );
		ActionSchedulerRunner::run_pending( ProductPictures::ACTION );

		$this->assertSame( $mine, get_post_thumbnail_id( $picture['product']->get_id() ) );
		$this->assertFalse( PictureImage::remembers( $picture['product'] ) );
	}

	public function test_turning_the_setting_off_puts_back_each_image_and_on_again_draws_the_pictures(): void {
		$picture = $this->drawn( $this->product( self::config() ) );

		$this->plugin->settings()->set( Settings::PRODUCT_PICTURES, false );
		ActionSchedulerRunner::run_pending( PictureQueue::QUEUE_ACTION );
		ActionSchedulerRunner::run_pending( ProductPictures::ACTION );

		$this->assertSame( $this->own_image, get_post_thumbnail_id( $picture['product']->get_id() ) );
		$this->assertNull( get_post( $picture['id'] ) );
		self::config( null )->save_to( $this->reload( $picture['product'] ) );
		$this->assertSame( array(), ActionSchedulerRunner::pending( ProductPictures::ACTION ) );

		$this->plugin->settings()->set( Settings::PRODUCT_PICTURES, true );
		ActionSchedulerRunner::run_pending( PictureQueue::QUEUE_ACTION );
		$this->drawn( $picture['product'] );
	}

	public function test_the_product_image_box_offers_the_picture_beside_the_store_managers_image(): void {
		$picture = $this->drawn( $this->product( self::config() ) );
		update_post_meta( $picture['product']->get_id(), PictureImage::THUMBNAIL_META, $this->image() );

		$fields = new PictureFields( $this->plugin->templates(), $this->plugin->settings() );

		$html = $fields->product_field( '<p>image</p>', $picture['product']->get_id(), get_post_thumbnail_id( $picture['product']->get_id() ) );

		$this->assertIsString( $html );
		$this->assertStringContainsString( 'class="pixonaut-use-picture" data-picture="' . $picture['id'] . '"', $html );
		$this->assertStringContainsString( 'Use the Pixonaut picture', $html );
		$this->assertStringNotContainsString( 'type="checkbox"', $html );
		// The box drawn again for the picture chosen by the link, before the product is saved.
		$chosen = $fields->product_field( '<p>image</p>', $picture['product']->get_id(), $picture['id'] );
		$this->assertIsString( $chosen );
		$this->assertStringContainsString( 'pixonaut-picture--' . PictureFields::PICTURE, $chosen );
	}

	public function test_the_product_image_box_says_when_the_image_is_the_picture(): void {
		$picture = $this->drawn( $this->product( self::config() ) );

		$html = ( new PictureFields( $this->plugin->templates(), $this->plugin->settings() ) )->product_field( '<p>image</p>', $picture['product']->get_id(), $picture['id'] );

		$this->assertIsString( $html );
		$this->assertStringContainsString( 'pixonaut-picture--' . PictureFields::PICTURE, $html );
		$this->assertStringNotContainsString( 'pixonaut-use-picture', $html );
	}

	public function test_the_product_image_box_is_unchanged_when_not_personalised_or_the_setting_is_off(): void {
		$product = new WC_Product_Simple();
		$product->save();
		$fields = new PictureFields( $this->plugin->templates(), $this->plugin->settings() );
		$this->assertSame( '<p>image</p>', $fields->product_field( '<p>image</p>', $product->get_id() ) );

		$this->plugin->settings()->set( Settings::PRODUCT_PICTURES, false );

		$this->assertSame( '<p>image</p>', $fields->product_field( '<p>image</p>', $this->product( self::config() )->get_id() ) );
	}

	public function test_the_upgrade_keeps_the_image_of_a_product_that_kept_it(): void {
		$kept = $this->product( self::config() );
		update_post_meta( $kept->get_id(), Schema::PRODUCT_KEEPS_IMAGE_META, 'yes' );
		$shown = $this->product( self::config() );
		update_post_meta( $shown->get_id(), Schema::PRODUCT_KEEPS_IMAGE_META, 'no' );
		$trashed = $this->product( self::config() );
		update_post_meta( $trashed->get_id(), Schema::PRODUCT_KEEPS_IMAGE_META, 'yes' );
		wp_trash_post( $trashed->get_id() );

		( new ReplaceKeptImages() )->up();

		$this->assertTrue( PictureImage::is_own( $kept ) );
		$this->assertTrue( PictureImage::is_own( $trashed ) );
		$this->assertFalse( PictureImage::remembers( $shown ) );
		$this->assertSame( '', get_post_meta( $kept->get_id(), Schema::PRODUCT_KEEPS_IMAGE_META, true ) );
		$this->assertSame( '', get_post_meta( $shown->get_id(), Schema::PRODUCT_KEEPS_IMAGE_META, true ) );
		( new PictureQueue( $this->plugin->settings() ) )->queue_backfill();
		$this->assertSame( false, get_option( PictureQueue::BACKFILL_OPTION ) );
		$this->assertFalse( ( new PictureQueue( $this->plugin->settings() ) )->is_due( $kept ) );
	}
}
