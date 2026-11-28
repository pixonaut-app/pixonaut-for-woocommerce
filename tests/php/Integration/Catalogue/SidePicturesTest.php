<?php
/**
 * @package Pixonaut\WooCommerce
 */

declare(strict_types=1);

namespace Pixonaut\WooCommerce\Tests\Integration\Catalogue;

use Pixonaut\WooCommerce\Api\Hosts;
use Pixonaut\WooCommerce\Api\ValueSetQuery;
use Pixonaut\WooCommerce\Catalogue\PictureImage;
use Pixonaut\WooCommerce\Catalogue\PictureQueue;
use Pixonaut\WooCommerce\Catalogue\PrintSize;
use Pixonaut\WooCommerce\Catalogue\ProductConfig;
use Pixonaut\WooCommerce\Catalogue\ProductPictures;
use Pixonaut\WooCommerce\Catalogue\ShowcaseImages;
use Pixonaut\WooCommerce\Catalogue\Side;
use Pixonaut\WooCommerce\Catalogue\SidePictures;
use Pixonaut\WooCommerce\Catalogue\SideValue;
use Pixonaut\WooCommerce\Catalogue\SideValues;
use Pixonaut\WooCommerce\Tests\Support\ActionSchedulerRunner;
use Pixonaut\WooCommerce\Tests\Support\DesignFixture;
use WC_Product;
use WC_Product_Attribute;
use WC_Product_Variable;
use WC_Product_Variation;
use WP_UnitTestCase;

/**
 * A product's gallery shows the other sides chosen for it, each drawn with
 * its side's template and values.
 */
final class SidePicturesTest extends WP_UnitTestCase {

	use PicturedProducts;

	/**
	 * The template of the product's second side, and its version.
	 */
	private const BACK_TEMPLATE = 'mini-tee-back';

	private const BACK_VERSION = 1;

	/**
	 * The value set the second side is sold with.
	 */
	private const BACK_VALUES = 'vs_5a6b7c8d9e0f1a2b3c4d5e6f';

	public function set_up(): void {
		parent::set_up();
		$this->set_up_pictures();
	}

	public function tear_down(): void {
		$this->tear_down_pictures();
		parent::tear_down();
	}

	public function test_a_chosen_side_follows_the_first_sides_picture_drawn_with_its_template_and_values(): void {
		$product = $this->product( self::two_sided() );
		SideValues::replace_on( $product, array( 'back' => self::back_values() ), null );
		SidePictures::save( $product, array( array( 'side' => 'back' ) ) );
		$this->recorder->raw( 200, self::png(), 'image/png' );
		$this->recorder->raw( 200, self::png(), 'image/png' );

		ActionSchedulerRunner::run_pending( ProductPictures::ACTION );

		$urls = array_column( $this->recorder->requests, 'url' );
		$this->assertSame(
			array(
				Hosts::server_api() . '/t/mini-tee%402/preview.png?size=' . ProductPictures::SIZE,
				Hosts::server_api() . '/t/' . self::BACK_TEMPLATE . '%40' . self::BACK_VERSION . '/preview.png?size=' . ProductPictures::SIZE . '&' . ValueSetQuery::NAME . '=' . self::BACK_VALUES,
			),
			$urls
		);
		$product = $this->reload( $product );
		$gallery = $product->get_gallery_image_ids();
		$this->assertCount( 1, $gallery );
		$this->assertTrue( PictureImage::is_picture( (int) $gallery[0], $product ) );
		$this->assertNotSame( (int) $product->get_image_id(), (int) $gallery[0] );
	}

	public function test_the_side_pictures_follow_the_showcase_pictures(): void {
		$product = $this->product( self::two_sided() );
		ShowcaseImages::save(
			$product,
			array(
				array(
					'id'   => 'showcase-anna',
					'name' => 'Anna',
					'kind' => ShowcaseImages::PREVIEW,
				),
			)
		);
		SidePictures::save( $product, array( array( 'side' => 'back' ) ) );
		$this->recorder->raw( 200, self::png(), 'image/png' );
		$this->recorder->raw( 200, self::png(), 'image/png' );

		ActionSchedulerRunner::run_pending( ProductPictures::ACTION );

		$urls = array_column( $this->recorder->requests, 'url' );
		$this->assertCount( 2, $urls );
		$this->assertStringContainsString( 'showcase=showcase-anna', $urls[0] );
		$this->assertStringContainsString( '/t/' . self::BACK_TEMPLATE . '%40', $urls[1] );
		$this->assertStringNotContainsString( 'showcase=', $urls[1], 'a side is drawn with its own template\'s defaults' );
		$this->assertCount( 1, $this->reload( $product )->get_gallery_image_ids() );
	}

	public function test_a_side_the_product_no_longer_has_draws_no_picture(): void {
		$product = $this->product( self::two_sided() );
		SidePictures::save( $product, array( array( 'side' => 'sleeve' ) ) );
		$this->recorder->raw( 200, self::png(), 'image/png' );

		ActionSchedulerRunner::run_pending( ProductPictures::ACTION );

		$this->assertCount( 1, $this->recorder->requests );
		$this->assertSame( array(), $this->reload( $product )->get_gallery_image_ids() );
	}

	public function test_choosing_a_side_draws_the_pictures_again(): void {
		$product = $this->product( self::two_sided() );
		$this->recorder->raw( 200, self::png(), 'image/png' );
		ActionSchedulerRunner::run_pending( ProductPictures::ACTION );
		$basis = ProductPictures::stored( $this->reload( $product ) )['basis'];

		SidePictures::save( $this->reload( $product ), array( array( 'side' => 'back' ) ) );

		$this->assertNotSame( $basis, ProductPictures::basis( $this->reload( $product ) ) );
		$this->assertCount( 1, ActionSchedulerRunner::pending( ProductPictures::ACTION ) );
	}

	public function test_new_values_on_a_chosen_side_draw_the_pictures_again(): void {
		$product = $this->product( self::two_sided() );
		SidePictures::save( $product, array( array( 'side' => 'back' ) ) );
		$this->recorder->raw( 200, self::png(), 'image/png' );
		$this->recorder->raw( 200, self::png(), 'image/png' );
		ActionSchedulerRunner::run_pending( ProductPictures::ACTION );
		$this->assertSame( array(), ActionSchedulerRunner::pending( ProductPictures::ACTION ) );

		SideValues::replace_on( $this->reload( $product ), array( 'back' => self::back_values() ), null );

		$this->assertCount( 1, ActionSchedulerRunner::pending( ProductPictures::ACTION ) );
	}

	public function test_a_side_past_a_full_gallery_is_not_read(): void {
		$product = $this->product( self::two_sided() );
		$sets    = array();
		for ( $set = 0; $set < ShowcaseImages::MAX_IMAGES; $set++ ) {
			$sets[] = array(
				'id'   => 'showcase-' . $set,
				'name' => 'Set ' . $set,
				'kind' => ShowcaseImages::PREVIEW,
			);
			$this->recorder->raw( 200, self::png(), 'image/png' );
		}
		ShowcaseImages::save( $product, $sets );
		SidePictures::save( $product, array( array( 'side' => 'back' ) ) );

		ActionSchedulerRunner::run_pending( ProductPictures::ACTION );

		$urls = array_column( $this->recorder->requests, 'url' );
		$this->assertCount( ShowcaseImages::MAX_IMAGES, $urls );
		$this->assertSame( array(), array_filter( $urls, static fn ( string $url ): bool => str_contains( $url, self::BACK_TEMPLATE ) ) );
	}

	public function test_a_variable_products_sides_are_those_of_the_variation_it_is_pictured_as_with_its_values(): void {
		$product   = new WC_Product_Variable();
		$attribute = new WC_Product_Attribute();
		$attribute->set_name( 'Size' );
		$attribute->set_options( array( 'A4' ) );
		$attribute->set_variation( true );
		$product->set_name( 'Calendar' );
		$product->set_attributes( array( $attribute ) );
		$product->save();
		// The product is set up on its first side alone; its variation on both.
		( new ProductConfig( personalise: true, sides: array( self::two_sided()->sides[0] ) ) )->save_to( $product );
		$variation = new WC_Product_Variation();
		$variation->set_parent_id( $product->get_id() );
		$variation->set_attributes( array( 'size' => 'A4' ) );
		$variation->set_regular_price( '20' );
		$variation->save();
		( new ProductConfig( personalise: true, override: true, sides: self::two_sided()->sides ) )->save_to( $variation );
		SideValues::replace_on( $variation, array( 'back' => self::back_values() ), null );
		$product = $this->reload( $product );
		$this->assertSame( array( 'design', 'back' ), array_map( static fn ( Side $side ): string => $side->key, SidePictures::sides_of( $product ) ) );
		SidePictures::save( $product, array( array( 'side' => 'back' ) ) );
		( new PictureQueue( $this->plugin->settings() ) )->queue_variations_now( $product->get_id() );
		ActionSchedulerRunner::run_pending( PictureQueue::VARIATIONS_ACTION );
		for ( $picture = 0; $picture < 3; $picture++ ) {
			$this->recorder->raw( 200, self::png(), 'image/png' );
		}

		ActionSchedulerRunner::run_pending( ProductPictures::ACTION );

		$side_pictures = array_filter( array_column( $this->recorder->requests, 'url' ), static fn ( string $url ): bool => str_contains( $url, '/t/' . self::BACK_TEMPLATE . '%40' ) );
		$this->assertCount( 1, $side_pictures, 'only the product draws the other side; its variation shows its first' );
		$this->assertStringContainsString( ValueSetQuery::NAME . '=' . self::BACK_VALUES, (string) reset( $side_pictures ) );
		$this->assertCount( 1, $this->reload( $product )->get_gallery_image_ids() );
	}

	/**
	 * A configuration without a blank on two sides, each on its own template.
	 */
	private static function two_sided(): ProductConfig {
		$size = array( new PrintSize( DesignFixture::WIDTH_MM, DesignFixture::HEIGHT_MM, 'Large' ) );
		return new ProductConfig(
			personalise: true,
			sides: array(
				new Side( 'design', 'Front', null, null, DesignFixture::TEMPLATE_BASE_ID, DesignFixture::LIVE_VERSION, null, 'Mini tee', $size ),
				new Side( 'back', 'Back', null, null, self::BACK_TEMPLATE, self::BACK_VERSION, null, 'Mini tee back', $size ),
			)
		);
	}

	private static function back_values(): SideValue {
		return new SideValue( self::BACK_TEMPLATE, self::BACK_VALUES, array( 'motif' => array( 'src' => 'up_5a6b7c8d9e0f1a2b3c4d5e6f' ) ) );
	}
}
