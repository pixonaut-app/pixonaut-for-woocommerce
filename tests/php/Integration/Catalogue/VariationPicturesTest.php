<?php
/**
 * @package Pixonaut\WooCommerce
 */

declare(strict_types=1);

namespace Pixonaut\WooCommerce\Tests\Integration\Catalogue;

use Pixonaut\WooCommerce\Abilities\ProductSetup;
use Pixonaut\WooCommerce\Api\Hosts;
use Pixonaut\WooCommerce\Catalogue\PictureQueue;
use Pixonaut\WooCommerce\Catalogue\ProductPictures;
use Pixonaut\WooCommerce\Catalogue\ProductVariant;
use Pixonaut\WooCommerce\Catalogue\ProductVariants;
use Pixonaut\WooCommerce\Catalogue\ShowcaseImages;
use Pixonaut\WooCommerce\Catalogue\Side;
use Pixonaut\WooCommerce\Catalogue\SplitCards;
use Pixonaut\WooCommerce\Migrations\Migrations;
use Pixonaut\WooCommerce\Migrations\RedrawVariationPictures;
use Pixonaut\WooCommerce\Tests\Support\ActionSchedulerRunner;
use Pixonaut\WooCommerce\Tests\Support\BlankFixture;
use Pixonaut\WooCommerce\Tests\Support\DesignFixture;
use Pixonaut\WooCommerce\Tests\Support\OpenApiAssert;
use WC_Product;
use WC_Product_Attribute;
use WC_Product_Variable;
use WC_Product_Variation;
use WP_UnitTestCase;

/**
 * A variable product's picture is the one of a variation it sells, and a
 * variation on another template shows that template's showcase set.
 */
final class VariationPicturesTest extends WP_UnitTestCase {

	use PicturedProducts;

	private const ANNA = array(
		'id'   => 'showcase-anna',
		'name' => 'Anna, class of 2026',
		'kind' => ShowcaseImages::MOCKUP,
	);

	/**
	 * The template a variation is set up on instead of its product's.
	 */
	private const OTHER_TEMPLATE = 'photo-tee';

	private const OTHER_VERSION = 4;

	/**
	 * The price a variation customers can buy has.
	 */
	private const PRICE = '20';

	public function set_up(): void {
		parent::set_up();
		$this->set_up_pictures();
	}

	public function tear_down(): void {
		$this->tear_down_pictures();
		delete_option( PictureQueue::BACKFILL_OPTION );
		parent::tear_down();
	}

	public function test_the_products_picture_is_of_the_variation_with_its_default_values(): void {
		$product = $this->tee( array( 'Colour' => 'Blue' ) );
		$red     = $this->variation( $product, 'Red', 'v_red' );
		$blue    = $this->variation( $product, 'Blue', 'v_blue' );
		$this->answer_pictures( 2 );

		ActionSchedulerRunner::run_pending( ProductPictures::ACTION );

		$picture = (int) $this->reload( $product )->get_image_id();
		$this->assertNotSame( 0, $picture );
		$this->assertSame( (int) $this->reload( $blue )->get_image_id(), $picture );
		$this->assertNotSame( (int) $this->reload( $red )->get_image_id(), $picture );
		$this->assertSame( array(), $this->drawn_without_variant() );
	}

	public function test_without_default_values_it_is_of_the_first_variation_customers_can_buy(): void {
		$product = $this->tee();
		$this->variation( $product, 'Red', 'v_red', '' );
		$blue = $this->variation( $product, 'Blue', 'v_blue' );
		$this->answer_pictures( 2 );

		ActionSchedulerRunner::run_pending( ProductPictures::ACTION );

		$this->assertSame( (int) $this->reload( $blue )->get_image_id(), (int) $this->reload( $product )->get_image_id() );
		$this->assertSame( array(), $this->drawn_without_variant() );
	}

	public function test_a_default_value_no_buyable_variation_has_falls_back_to_the_first(): void {
		$product = $this->tee( array( 'Colour' => 'Green' ) );
		$red     = $this->variation( $product, 'Red', 'v_red' );
		$this->variation( $product, 'Blue', 'v_blue' );
		$this->answer_pictures( 2 );

		ActionSchedulerRunner::run_pending( ProductPictures::ACTION );

		$this->assertSame( (int) $this->reload( $red )->get_image_id(), (int) $this->reload( $product )->get_image_id() );
	}

	public function test_a_product_selling_no_variation_is_drawn_for_no_variant(): void {
		$product = $this->tee();
		$this->variation( $product, 'Red', 'v_red', '' );
		$this->recorder->json( 200, BlankFixture::mockups( Hosts::server_api() ) );
		$this->answer_pictures( 2 );

		ActionSchedulerRunner::run_pending( ProductPictures::ACTION );

		$this->assertCount( 1, $this->drawn_without_variant() );
		$this->assertNotSame( 0, (int) $this->reload( $product )->get_image_id() );
	}

	public function test_a_variation_customers_can_buy_at_last_draws_its_products_picture_as_it(): void {
		$product = $this->tee();
		$red     = $this->variation( $product, 'Red', 'v_red', '' );
		$this->recorder->json( 200, BlankFixture::mockups( Hosts::server_api() ) );
		$this->answer_pictures( 2 );
		ActionSchedulerRunner::run_pending( ProductPictures::ACTION );
		$before = (int) $this->reload( $product )->get_image_id();

		$red = $this->reload( $red );
		$red->set_regular_price( self::PRICE );
		$red->save();
		$this->plugin->picture_queue()->parents_changed();
		ActionSchedulerRunner::run_pending( ProductPictures::ACTION );

		$picture = (int) $this->reload( $product )->get_image_id();
		$this->assertNotSame( $before, $picture );
		$this->assertSame( (int) $this->reload( $red )->get_image_id(), $picture );
	}

	public function test_a_variation_on_another_template_shows_that_templates_set_of_the_same_name(): void {
		$variation = $this->variation_on_another_template();
		$this->answer_sets(
			array(
				array(
					'id'   => 'showcase-first',
					'name' => 'Leo',
				),
				array(
					'id'   => 'showcase-other-anna',
					'name' => ' anna, Class of 2026',
				),
			)
		);
		$this->answer_pictures( 1 );

		ActionSchedulerRunner::run_pending( ProductPictures::ACTION );

		$url = $this->recorder->requests[1]['url'];
		$this->assertStringContainsString( '/t/' . self::OTHER_TEMPLATE . '%40' . self::OTHER_VERSION . '/mockup.png?', $url );
		$this->assertStringEndsWith( '&showcase=showcase-other-anna', $url );
		$this->assertSame( array(), ProductPictures::stored( $this->reload( $variation ) )['gone'] );
		$this->assertSame( ProductSetup::DRAWN_WITH_SHOWCASE, $this->picture_shows( $variation ) );
	}

	public function test_a_variation_on_another_template_without_that_name_shows_its_first_set(): void {
		$variation = $this->variation_on_another_template();
		$this->answer_sets(
			array(
				array(
					'id'   => 'showcase-first',
					'name' => 'Leo',
				),
				array(
					'id'   => 'showcase-second',
					'name' => 'Mia',
				),
			)
		);
		$this->answer_pictures( 1 );

		ActionSchedulerRunner::run_pending( ProductPictures::ACTION );

		$this->assertStringEndsWith( '&showcase=showcase-first', $this->recorder->requests[1]['url'] );
		$this->assertSame( ProductSetup::DRAWN_WITH_SHOWCASE, $this->picture_shows( $variation ) );
	}

	public function test_a_variation_on_a_template_without_sets_shows_its_defaults_and_says_so(): void {
		$variation = $this->variation_on_another_template();
		$this->answer_sets( array() );
		$this->recorder->json( 404, array( 'error' => 'showcase_not_found' ) );
		$this->answer_pictures( 1 );

		ActionSchedulerRunner::run_pending( ProductPictures::ACTION );

		$this->assertSame( array( self::ANNA['id'] ), ProductPictures::stored( $this->reload( $variation ) )['gone'] );
		$this->assertSame( ProductSetup::DRAWN_WITH_DEFAULTS, $this->picture_shows( $variation ) );
	}

	public function test_a_variation_on_its_products_template_shows_the_products_set(): void {
		$product = $this->tee();
		ShowcaseImages::save( $product, array( self::ANNA ) );
		$red = $this->variation( $product, 'Red', 'v_red' );
		$this->answer_pictures( 1 );

		ActionSchedulerRunner::run_pending( ProductPictures::ACTION );

		$this->assertCount( 1, $this->recorder->requests );
		$this->assertStringEndsWith( '&showcase=' . self::ANNA['id'], $this->recorder->requests[0]['url'] );
		$this->assertSame( ProductSetup::DRAWN_WITH_SHOWCASE, $this->picture_shows( $red ) );
	}

	public function test_a_variation_of_a_product_listed_as_cards_is_drawn_flat_too_with_the_same_set(): void {
		$product = $this->tee();
		ShowcaseImages::save( $product, array( self::ANNA ) );
		$red  = $this->variation( $product, 'Red', 'v_red' );
		$blue = $this->variation( $product, 'Blue', 'v_blue' );
		$this->answer_pictures( 2 );
		ActionSchedulerRunner::run_pending( ProductPictures::ACTION );
		$this->assertSame( array(), ProductPictures::stored( $this->reload( $red ) )['gallery'], 'listed once, only its studio picture' );
		$this->recorder->requests = array();

		// Listed as cards: its card rows are written.
		add_post_meta( $product->get_id(), SplitCards::META, SplitCards::NO_VALUE );
		$this->answer_pictures( 1 );
		ActionSchedulerRunner::run_pending( PictureQueue::VARIATIONS_ACTION );
		ActionSchedulerRunner::run_pending( ProductPictures::ACTION );

		$flat = array_values( array_filter( array_column( $this->recorder->requests, 'url' ), static fn ( string $url ): bool => str_contains( $url, '/preview.png?' ) ) );
		$this->assertCount( 1, $flat, 'one flat picture of the design, which both colours share' );
		$this->assertStringEndsWith( '&showcase=' . self::ANNA['id'], $flat[0] );
		$this->assertCount( 1, $this->recorder->requests, 'the studio pictures are kept, not drawn again' );
		$studio = (int) $this->reload( $red )->get_image_id();
		$this->assertNotSame( 0, ProductPictures::flat( $this->reload( $red ) ) );
		$this->assertNotSame( $studio, ProductPictures::flat( $this->reload( $red ) ), 'the image stays the studio picture' );
		$this->assertSame( ProductPictures::flat( $this->reload( $red ) ), ProductPictures::flat( $this->reload( $blue ) ) );
	}

	public function test_a_variation_showing_the_store_managers_own_image_is_drawn_flat_too(): void {
		$product = $this->tee();
		$red     = $this->variation( $product, 'Red', 'v_red' );
		$this->answer_pictures( 1 );
		ActionSchedulerRunner::run_pending( ProductPictures::ACTION );
		$own   = $this->image();
		$fresh = $this->reload( $red );
		$fresh->set_image_id( $own );
		$fresh->save();
		$this->recorder->requests = array();

		add_post_meta( $product->get_id(), SplitCards::META, SplitCards::NO_VALUE );
		$this->answer_pictures( 1 );
		ActionSchedulerRunner::run_pending( PictureQueue::VARIATIONS_ACTION );
		ActionSchedulerRunner::run_pending( ProductPictures::ACTION );

		$this->assertCount( 1, $this->recorder->requests, 'only the flat picture is drawn' );
		$this->assertStringContainsString( '/preview.png?', $this->recorder->requests[0]['url'] );
		$this->assertSame( $own, (int) $this->reload( $red )->get_image_id(), 'the store manager\'s image is kept' );
		$this->assertNotSame( 0, ProductPictures::flat( $this->reload( $red ) ) );
		$this->assertNotSame( $own, ProductPictures::flat( $this->reload( $red ) ) );
	}

	public function test_a_picture_drawn_without_a_set_shows_the_templates_defaults(): void {
		$product = $this->tee();
		$red     = $this->variation( $product, 'Red', 'v_red' );

		$this->assertNull( $this->picture_shows( $red ), 'Nothing is drawn yet.' );
		$this->answer_pictures( 1 );
		ActionSchedulerRunner::run_pending( ProductPictures::ACTION );

		$this->assertSame( ProductSetup::DRAWN_WITH_DEFAULTS, $this->picture_shows( $red ) );
		$this->assertSame( ProductSetup::DRAWN_WITH_DEFAULTS, $this->picture_shows( $product ) );
	}

	public function test_what_a_picture_shows_holds_until_another_is_drawn(): void {
		$product = $this->tee();
		$red     = $this->variation( $product, 'Red', 'v_red' );
		$this->answer_pictures( 1 );
		ActionSchedulerRunner::run_pending( ProductPictures::ACTION );

		ShowcaseImages::save( $this->reload( $product ), array( self::ANNA ) );

		$this->assertSame( ProductSetup::DRAWN_WITH_DEFAULTS, $this->picture_shows( $red ), 'The set is not drawn yet.' );
		$this->recorder->fail();
		$this->recorder->fail();
		ActionSchedulerRunner::run_pending( PictureQueue::VARIATIONS_ACTION );
		ActionSchedulerRunner::run_pending( ProductPictures::ACTION );
		$this->assertSame( ProductSetup::DRAWN_WITH_DEFAULTS, $this->picture_shows( $product ), 'Pixonaut could not draw it.' );
	}

	public function test_a_set_pixonaut_no_longer_has_shows_the_defaults_on_every_product_sharing_the_picture(): void {
		$product = $this->tee();
		ShowcaseImages::save( $product, array( self::ANNA ) );
		$red = $this->variation( $product, 'Red', 'v_red' );
		$this->recorder->json( 404, array( 'error' => 'showcase_not_found' ) );
		$this->answer_pictures( 1 );

		ActionSchedulerRunner::run_pending( ProductPictures::ACTION );

		$this->assertSame( (int) $this->reload( $red )->get_image_id(), (int) $this->reload( $product )->get_image_id() );
		$this->assertSame( ProductSetup::DRAWN_WITH_DEFAULTS, $this->picture_shows( $product ) );
		$this->assertSame( ProductSetup::DRAWN_WITH_DEFAULTS, $this->picture_shows( $red ) );
	}

	public function test_a_set_of_another_template_gone_before_it_is_drawn_shows_the_defaults(): void {
		$variation = $this->variation_on_another_template();
		$this->answer_sets(
			array(
				array(
					'id'   => 'showcase-first',
					'name' => 'Leo',
				),
			)
		);
		$this->recorder->json( 404, array( 'error' => 'showcase_not_found' ) );
		$this->answer_pictures( 1 );

		ActionSchedulerRunner::run_pending( ProductPictures::ACTION );

		$this->assertSame( ProductSetup::DRAWN_WITH_DEFAULTS, $this->picture_shows( $variation ) );
	}

	public function test_the_upgrade_checks_every_products_picture_again(): void {
		$product = $this->tee();
		$red     = $this->variation( $product, 'Red', 'v_red' );
		$this->answer_pictures( 1 );
		ActionSchedulerRunner::run_pending( ProductPictures::ACTION );
		// As drawn before the upgrade: for no variation.
		$stale = array( 'basis' => 'before-the-upgrade' ) + ProductPictures::stored( $this->reload( $product ) );
		update_post_meta( $product->get_id(), ProductPictures::META_KEY, wp_slash( (string) wp_json_encode( $stale ) ) );

		( new RedrawVariationPictures() )->up();
		$this->plugin->picture_queue()->queue_backfill();
		ActionSchedulerRunner::run_pending( PictureQueue::QUEUE_ACTION );

		$this->assertContains( RedrawVariationPictures::class, array_map( 'get_class', Migrations::all() ) );
		$this->assertFalse( get_option( PictureQueue::BACKFILL_OPTION ) );
		$this->assertCount( 1, ActionSchedulerRunner::pending( ProductPictures::ACTION ) );
		ActionSchedulerRunner::run_pending( ProductPictures::ACTION );
		$this->assertSame( (int) $this->reload( $red )->get_image_id(), (int) $this->reload( $product )->get_image_id() );
	}

	/**
	 * A configured tee sold in colours, without variations yet.
	 *
	 * @param array<string, string> $defaults Its default attribute values.
	 */
	private function tee( array $defaults = array() ): WC_Product {
		$product   = new WC_Product_Variable();
		$attribute = new WC_Product_Attribute();
		$attribute->set_name( 'Colour' );
		$attribute->set_options( array( 'Red', 'Blue', 'Green' ) );
		$attribute->set_variation( true );
		$product->set_name( 'Tee' );
		$product->set_attributes( array( $attribute ) );
		$product->set_default_attributes( $defaults );
		$product->save();
		self::config()->save_to( $product );
		return $this->reload( $product );
	}

	/**
	 * A variation of the tee in a colour, selling its own variant of the blank.
	 *
	 * @param WC_Product $product The tee.
	 * @param string     $colour  Its colour.
	 * @param string     $variant The blank's variant it sells.
	 * @param string     $price   Its price; empty for one customers cannot buy.
	 */
	private function variation( WC_Product $product, string $colour, string $variant, string $price = self::PRICE ): WC_Product {
		$variation = new WC_Product_Variation();
		$variation->set_parent_id( $product->get_id() );
		$variation->set_attributes( array( 'colour' => $colour ) );
		$variation->set_regular_price( $price );
		$variation->save();
		( new ProductVariant( DesignFixture::BLANK_ID, null, $variant, '', null, BlankFixture::VARIANT_VIEWS, ProductVariants::attributes( $variation ) ) )->save_to( $variation );
		return $this->reload( $variation );
	}

	/**
	 * A tee showing Anna's set, drawn, and then its Blue variation set up
	 * on another template, its drawing queued.
	 */
	private function variation_on_another_template(): WC_Product {
		$product = $this->tee();
		ShowcaseImages::save( $product, array( self::ANNA ) );
		$this->variation( $product, 'Red', 'v_red' );
		$this->answer_pictures( 1 );
		ActionSchedulerRunner::run_pending( ProductPictures::ACTION );
		$this->recorder->requests = array();
		$blue                     = $this->variation( $product, 'Blue', 'v_blue' );
		$side                     = new Side( 'front', 'Front', DesignFixture::PLACEMENT_ID, null, self::OTHER_TEMPLATE, self::OTHER_VERSION, null, 'Photo tee' );
		self::config()->with_override( true )->with_sides( array( $side ) )->save_to( $blue );
		return $this->reload( $blue );
	}

	/**
	 * Answers the next drawings with a picture each.
	 *
	 * @param int $pictures How many.
	 */
	private function answer_pictures( int $pictures ): void {
		for ( $picture = 0; $picture < $pictures; ++$picture ) {
			$this->recorder->raw( 200, self::png(), 'image/png' );
		}
	}

	/**
	 * Answers a template's read with its showcase sets.
	 *
	 * @param list<array{id: string, name: string}> $sets Its sets.
	 */
	private function answer_sets( array $sets ): void {
		$listed = array_map( static fn ( array $set ): array => $set + array( 'previewUrl' => Hosts::server_api() . '/i/' . $set['id'] ), $sets );
		$this->recorder->raw( 200, OpenApiAssert::example( 'GET', '/v1/templates/{id}', 200, array( 'showcases' => $listed ) ), 'application/json' );
	}

	/**
	 * The pictures asked for on the blank's photo for no variant.
	 *
	 * @return list<string> Their URLs.
	 */
	private function drawn_without_variant(): array {
		return array_values( array_filter( array_column( $this->recorder->requests, 'url' ), static fn ( string $url ): bool => str_contains( $url, '/mockup.png?' ) && ! str_contains( $url, '&variant=' ) ) );
	}

	/**
	 * What the abilities say the product's picture shows.
	 *
	 * @param WC_Product $product A product or variation.
	 */
	private function picture_shows( WC_Product $product ): ?string {
		$shows = ( new ProductSetup( $this->plugin->settings() ) )->of( $this->reload( $product ) )['picture_shows'];
		$this->assertTrue( null === $shows || is_string( $shows ) );
		return $shows;
	}
}
