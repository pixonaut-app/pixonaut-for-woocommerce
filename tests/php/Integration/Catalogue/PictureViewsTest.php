<?php
/**
 * @package Pixonaut\WooCommerce
 */

declare(strict_types=1);

namespace Pixonaut\WooCommerce\Tests\Integration\Catalogue;

use Pixonaut\WooCommerce\Api\Hosts;
use Pixonaut\WooCommerce\Catalogue\PictureImage;
use Pixonaut\WooCommerce\Catalogue\PictureView;
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

final class PictureViewsTest extends WP_UnitTestCase {

	use PicturedProducts;

	/**
	 * The view `BlankFixture::mockups()` lists for the placement, and its photo.
	 */
	private const VIEW = 'view-' . DesignFixture::PLACEMENT_ID;

	private const PHOTO = 'mock-' . DesignFixture::PLACEMENT_ID;

	public function set_up(): void {
		parent::set_up();
		$this->set_up_pictures();
	}

	public function tear_down(): void {
		$this->tear_down_pictures();
		parent::tear_down();
	}

	public function test_a_chosen_photo_is_drawn_by_its_id_whatever_the_variant(): void {
		$product = $this->product( self::config() );
		PictureView::save( $product, self::photo() );
		$this->answer_mockups();
		$this->recorder->raw( 200, self::png(), 'image/png' );

		ActionSchedulerRunner::run_pending( ProductPictures::ACTION );

		$this->assertSame( self::mockup_url( 'mockupId=' . self::PHOTO ), $this->last_url() );
		$product = $this->reload( $product );
		$this->assertTrue( PictureImage::is_picture( (int) $product->get_image_id(), $product ) );
	}

	public function test_a_chosen_view_draws_its_photo_of_the_variant_sold(): void {
		$product = $this->product( self::config() );
		ShowcaseImages::save(
			$product,
			array(
				array(
					'id'   => 'showcase-anna',
					'name' => 'Anna',
					'kind' => ShowcaseImages::MOCKUP,
					'view' => self::VIEW,
				),
			)
		);
		$this->answer_mockups();
		$this->recorder->raw( 200, self::png(), 'image/png' );

		ActionSchedulerRunner::run_pending( ProductPictures::ACTION );

		$this->assertSame( self::mockup_url( 'viewId=' . self::VIEW . '&variant=' . BlankFixture::VARIANT_REF ) . '&showcase=showcase-anna', $this->last_url() );
	}

	public function test_a_photo_the_blank_no_longer_has_is_drawn_as_without_a_choice(): void {
		$product = $this->product( self::config() );
		PictureView::save(
			$product,
			array(
				'kind'  => PictureView::MOCKUP,
				'view'  => self::VIEW,
				'photo' => 'mock-gone',
			)
		);
		$this->answer_mockups();
		$this->recorder->raw( 200, self::png(), 'image/png' );

		ActionSchedulerRunner::run_pending( ProductPictures::ACTION );

		$this->assertSame( self::mockup_url( 'viewId=' . BlankFixture::VIEW_ID . '&variant=' . BlankFixture::VARIANT_REF ), $this->last_url() );
		$this->assertTrue( PictureImage::is_picture( (int) $this->reload( $product )->get_image_id(), $product ) );
	}

	public function test_a_flat_choice_draws_the_template_alone(): void {
		$product = $this->product( self::config() );
		PictureView::save( $product, array( 'kind' => PictureView::PREVIEW ) );
		$this->recorder->raw( 200, self::png(), 'image/png' );

		ActionSchedulerRunner::run_pending( ProductPictures::ACTION );

		$this->assertCount( 1, $this->recorder->requests );
		$this->assertSame( Hosts::server_api() . '/t/mini-tee%402/preview.png?size=' . ProductPictures::SIZE, $this->last_url() );
	}

	public function test_changing_the_choice_draws_the_picture_again(): void {
		$product = $this->product( self::config() );
		$drawn   = $this->drawn( $product )['id'];

		PictureView::save( $this->reload( $product ), self::photo() );
		$this->answer_mockups();
		$this->recorder->raw( 200, self::png(), 'image/png' );
		ActionSchedulerRunner::run_pending( ProductPictures::ACTION );

		$this->assertSame( self::mockup_url( 'mockupId=' . self::PHOTO ), $this->last_url() );
		$this->assertNotSame( $drawn, (int) $this->reload( $product )->get_image_id() );
	}

	public function test_choosing_the_default_again_forgets_the_choice(): void {
		$product = $this->product( self::config() );
		PictureView::save( $product, self::photo() );

		PictureView::save( $this->reload( $product ), array( 'kind' => PictureView::MOCKUP ) );

		$this->assertNull( PictureView::document_on( $this->reload( $product ) ) );
	}

	public function test_a_variation_follows_its_products_photo_only_when_it_shows_its_variant(): void {
		$variation = $this->variation();
		$parent    = wc_get_product( $variation->get_parent_id() );
		$this->assertInstanceOf( WC_Product::class, $parent );
		PictureView::save( $parent, self::photo() );
		$this->answer_mockups( array( 'v_other' ) );
		$this->recorder->raw( 200, self::png(), 'image/png' );
		$this->recorder->raw( 200, self::png(), 'image/png' );

		ActionSchedulerRunner::run_pending( ProductPictures::ACTION );

		$urls = array_column( $this->recorder->requests, 'url' );
		$this->assertContains( self::mockup_url( 'viewId=' . self::VIEW . '&variant=' . BlankFixture::VARIANT_REF ), $urls );
		$this->assertContains( self::mockup_url( 'mockupId=' . self::PHOTO ), $urls, 'The product, standing for every variant, shows the photo.' );
	}

	public function test_a_variations_own_photo_is_drawn_whatever_its_variant(): void {
		$variation = $this->variation();
		PictureView::save( $variation, self::photo() );
		$this->answer_mockups( array( 'v_other' ) );
		$this->recorder->raw( 200, self::png(), 'image/png' );
		$this->recorder->raw( 200, self::png(), 'image/png' );

		ActionSchedulerRunner::run_pending( ProductPictures::ACTION );

		$variation_urls = array_values( array_filter( array_column( $this->recorder->requests, 'url' ), static fn ( string $url ): bool => str_contains( $url, 'mockupId=' ) ) );
		$this->assertSame( array( self::mockup_url( 'mockupId=' . self::PHOTO ) ), $variation_urls );
	}

	/**
	 * A configured variable product with one variation selling the fixture's variant.
	 */
	private function variation(): WC_Product {
		$product   = new WC_Product_Variable();
		$attribute = new WC_Product_Attribute();
		$attribute->set_name( 'Colour' );
		$attribute->set_options( array( 'Red' ) );
		$attribute->set_variation( true );
		$product->set_attributes( array( $attribute ) );
		$product->save();
		self::config()->save_to( $product );
		$variation = new WC_Product_Variation();
		$variation->set_parent_id( $product->get_id() );
		$variation->set_attributes( array( 'colour' => 'Red' ) );
		$variation->set_regular_price( '20' );
		$variation->save();
		DesignFixture::sell_variant( $variation );
		return $this->reload( $variation );
	}

	/**
	 * Answers the blank's mockups read, its photo showing the variants given.
	 *
	 * @param string[] $variants The photo's variant refs; empty for every variant.
	 * @phpstan-param list<string> $variants
	 */
	private function answer_mockups( array $variants = array() ): void {
		$mockups = BlankFixture::mockups( Hosts::server_api() );
		$mockups['placements'][0]['views'][0]['photos'][0]['variantRefs'] = $variants;
		$this->recorder->json( 200, $mockups );
	}

	/**
	 * @return array{kind: string, view: string, photo: string}
	 */
	private static function photo(): array {
		return array(
			'kind'  => PictureView::MOCKUP,
			'view'  => self::VIEW,
			'photo' => self::PHOTO,
		);
	}

	/**
	 * The mockup drawn on the fixture's blank and placement.
	 *
	 * @param string $where The view or photo part of the query.
	 */
	private static function mockup_url( string $where ): string {
		return Hosts::server_api() . '/t/mini-tee%402/mockup.png?blankId=' . DesignFixture::BLANK_ID . '&placementId=' . DesignFixture::PLACEMENT_ID . '&' . $where . '&size=' . ProductPictures::SIZE;
	}

	private function last_url(): string {
		$last = end( $this->recorder->requests );
		$this->assertIsArray( $last );
		return $last['url'];
	}
}
