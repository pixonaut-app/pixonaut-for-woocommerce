<?php
/**
 * @package Pixonaut\WooCommerce
 */

declare(strict_types=1);

namespace Pixonaut\WooCommerce\Tests\Integration\Catalogue;

use Pixonaut\WooCommerce\Catalogue\PictureQueue;
use Pixonaut\WooCommerce\Catalogue\PictureStorage;
use Pixonaut\WooCommerce\Catalogue\ProductPictures;
use Pixonaut\WooCommerce\Catalogue\ProductVariant;
use Pixonaut\WooCommerce\Catalogue\ShowcaseImages;
use Pixonaut\WooCommerce\Catalogue\VariationPictures;
use Pixonaut\WooCommerce\Settings\Settings;
use Pixonaut\WooCommerce\Tests\Support\ActionSchedulerRunner;
use Pixonaut\WooCommerce\Tests\Support\BlankFixture;
use Pixonaut\WooCommerce\Tests\Support\DependentPosters;
use Pixonaut\WooCommerce\Tests\Support\DesignFixture;
use WC_Product_Variable;
use WC_Product_Variation;
use WP_UnitTestCase;

/**
 * A poster whose showcase set is drawn flat: its variations share one flat
 * picture, unless a picture is drawn for every variation, each then on its
 * blank's photo as the variant it sells.
 */
final class EveryVariationPicturesTest extends WP_UnitTestCase {

	use DependentPosters;
	use PicturedProducts;

	/**
	 * A showcase set drawn flat, as the template's preview.
	 */
	private const LEO = array(
		'id'   => 'showcase-leo',
		'name' => 'Leo',
		'kind' => ShowcaseImages::PREVIEW,
	);

	/**
	 * Seconds Pixonaut asks the store to wait in `Retry-After`.
	 */
	private const RETRY_AFTER = 42;

	private WC_Product_Variable $poster;

	public function set_up(): void {
		parent::set_up();
		$this->set_up_pictures();
		$this->set_up_dependent_posters();
		$this->poster = $this->dependent_poster();
		self::config()->save_to( $this->poster );
		ShowcaseImages::save( $this->poster, array( self::LEO ) );
		foreach ( $this->poster_variations as $key => $variation ) {
			( new ProductVariant( DesignFixture::BLANK_ID, 'v_' . $key, 'v_' . $key, '', null, BlankFixture::VARIANT_VIEWS ) )->save_to( $variation );
		}
	}

	public function tear_down(): void {
		$this->tear_down_dependent_posters();
		$this->tear_down_pictures();
		delete_option( PictureQueue::BACKFILL_OPTION );
		parent::tear_down();
	}

	public function test_off_by_default_the_variations_share_one_flat_picture(): void {
		$this->assertFalse( VariationPictures::on_for( $this->poster ) );

		$this->draw_all();

		$this->assertCount( 1, $this->recorder->requests, 'one picture for every variation' );
		$this->assertStringContainsString( '/preview.png?', $this->recorder->requests[0]['url'] );
		$this->assertCount( 1, array_unique( $this->images() ) );
	}

	public function test_on_for_a_product_each_variation_is_drawn_on_its_blanks_photo(): void {
		$this->draw_all();
		$flat                     = $this->images();
		$this->recorder->requests = array();

		VariationPictures::choose( $this->poster, VariationPictures::ON );
		$this->draw_all();

		$variants = array_map( fn ( array $request ): string => (string) ( wp_parse_args( (string) wp_parse_url( $request['url'], PHP_URL_QUERY ) )['variant'] ?? '' ), $this->recorder->requests );
		sort( $variants );
		$expected = array_map( static fn ( string $key ): string => 'v_' . $key, array_keys( $this->poster_variations ) );
		sort( $expected );
		$this->assertSame( $expected, $variants, 'each variant on its photo, once' );
		foreach ( $this->recorder->requests as $request ) {
			$this->assertStringContainsString( '/mockup.png?', $request['url'] );
			$this->assertStringContainsString( 'showcase=' . self::LEO['id'], $request['url'], 'with its showcase set' );
		}
		$images = $this->images();
		$this->assertCount( count( $this->poster_variations ), array_unique( $images ), 'a picture each' );
		$this->assertSame( array(), array_intersect( $images, $flat ) );
		$this->assertSame( array(), ActionSchedulerRunner::pending( ProductPictures::ACTION ), 'all drawn' );
	}

	public function test_on_store_wide_draws_every_variation_unless_a_product_turns_it_off(): void {
		$this->draw_all();
		$this->recorder->requests = array();

		$this->plugin->settings()->set( Settings::VARIATION_PICTURES, true );
		ActionSchedulerRunner::run_pending( PictureQueue::QUEUE_ACTION );
		$this->draw_all();
		$this->assertCount( count( $this->poster_variations ), $this->recorder->requests );

		$this->recorder->requests = array();
		VariationPictures::choose( $this->poster, VariationPictures::OFF );
		$this->draw_all();
		$this->assertSame( array(), $this->recorder->requests, 'the flat picture is kept by the product' );
		$this->assertCount( 1, array_unique( $this->images() ), 'flat again' );
	}

	public function test_turning_it_off_deletes_the_variation_pictures_nothing_else_uses(): void {
		VariationPictures::choose( $this->poster, VariationPictures::ON );
		$this->draw_all();
		$pictures = $this->images();
		$storage  = new PictureStorage();
		$bytes    = array_sum( $storage->bytes( array_values( array_unique( $pictures ) ) ) );
		$this->assertGreaterThan( 0, $bytes );

		$freed = $this->plugin->variation_picture_cost()->turning_off( array( $this->reload( $this->poster ) ) );
		$this->assertSame( count( $this->poster_variations ), $freed['pictures'] );
		$this->assertSame( $bytes, $freed['bytes'], 'the bytes it frees' );

		VariationPictures::choose( $this->reload( $this->poster ), VariationPictures::OFF );
		$this->draw_all();

		foreach ( $pictures as $picture ) {
			$this->assertNull( get_post( $picture ), 'deleted' );
		}
		$this->assertSame( 0, $storage->totals()['variation_pictures'] );
	}

	public function test_a_429_from_the_mockup_route_draws_it_again_later_rather_than_failing(): void {
		VariationPictures::choose( $this->poster, VariationPictures::ON );
		ActionSchedulerRunner::run_pending( PictureQueue::VARIATIONS_ACTION );
		$variation = $this->poster_variations['ram-vit'];
		as_unschedule_all_actions( ProductPictures::ACTION );
		$this->plugin->picture_queue()->running( $variation->get_id() );
		PictureQueue::schedule( $variation->get_id() );
		$this->recorder->json(
			429,
			array(
				'error'   => 'rate_limited',
				'message' => 'Slow down.',
			),
			array( 'Retry-After' => (string) self::RETRY_AFTER )
		);

		$before = time();
		$job    = ActionSchedulerRunner::pending( ProductPictures::ACTION );
		// A drawing queued meanwhile, as Regenerate now queues one before it draws.
		add_action(
			ProductPictures::ACTION,
			static function () use ( $variation ): void {
				as_enqueue_async_action( ProductPictures::ACTION, array( $variation->get_id() ), ProductPictures::GROUP );
			},
			0
		);
		ActionSchedulerRunner::run_once( ProductPictures::ACTION );
		$this->assertNotContains( $job[0], ActionSchedulerRunner::pending( ProductPictures::ACTION ) );

		$due = ActionSchedulerRunner::due_at( ProductPictures::ACTION );
		$this->assertCount( 1, $due, 'drawn again later' );
		$this->assertGreaterThanOrEqual( $before + self::RETRY_AFTER, $due[0], 'after the wait Pixonaut asked for' );
		$this->assertNull( ProductPictures::stored( $this->reload( $variation ) )['failed_at'], 'not a failure' );
		$this->assertFalse( as_has_scheduled_action( ProductPictures::RETRY_ACTION, array( $variation->get_id() ) ), 'no failure retry' );
	}

	public function test_a_products_variations_are_queued_a_page_at_a_time(): void {
		$product = $this->sign_of( PictureQueue::VARIATIONS_PAGE + 1 );
		as_unschedule_all_actions( ProductPictures::ACTION );
		as_unschedule_all_actions( PictureQueue::VARIATIONS_ACTION );
		foreach ( $product->get_children() as $child_id ) {
			// What the job tells the queue as it starts: each may be queued again.
			$this->plugin->picture_queue()->running( $child_id );
		}
		VariationPictures::choose( $product, VariationPictures::ON );

		ActionSchedulerRunner::run_once( PictureQueue::VARIATIONS_ACTION );

		$this->assertCount( PictureQueue::VARIATIONS_PAGE, ActionSchedulerRunner::pending( ProductPictures::ACTION ), 'one page' );
		$next = ActionSchedulerRunner::due_at( PictureQueue::VARIATIONS_ACTION );
		$this->assertCount( 1, $next, 'the rest later' );
		$this->assertGreaterThan( time(), $next[0] );
		$this->assertTrue( PictureQueue::drawing( $product ) );

		ActionSchedulerRunner::run_once( PictureQueue::VARIATIONS_ACTION );
		$this->assertCount( PictureQueue::VARIATIONS_PAGE + 1, ActionSchedulerRunner::pending( ProductPictures::ACTION ), 'and the last' );
	}

	public function test_a_job_reads_a_bounded_number_of_variations_however_few_are_due(): void {
		$product = $this->sign_of( PictureQueue::VARIATIONS_EXAMINED + 1 );
		// With pictures off and none drawn, no variation is due.
		$this->plugin->settings()->set( Settings::PRODUCT_PICTURES, false );
		as_unschedule_all_actions( ProductPictures::ACTION );
		as_unschedule_all_actions( PictureQueue::VARIATIONS_ACTION );

		( new PictureQueue( $this->plugin->settings() ) )->queue_variations( $product->get_id() );

		$this->assertSame( array(), ActionSchedulerRunner::pending( ProductPictures::ACTION ) );
		$this->assertSame( array( array( $product->get_id(), PictureQueue::VARIATIONS_EXAMINED ) ), $this->variation_pages(), 'the rest later' );
	}

	/**
	 * Arguments of the variations jobs waiting.
	 *
	 * @return list<array<mixed>>
	 */
	private function variation_pages(): array {
		return array_map( static fn ( int $id ): array => \ActionScheduler::store()->fetch_action( (string) $id )->get_args(), ActionSchedulerRunner::pending( PictureQueue::VARIATIONS_ACTION ) );
	}

	/**
	 * Runs every queued variations check and drawing, Pixonaut answering each drawing with a PNG.
	 */
	private function draw_all(): void {
		ActionSchedulerRunner::run_pending( PictureQueue::VARIATIONS_ACTION );
		$count = count( ActionSchedulerRunner::pending( ProductPictures::ACTION ) ) + count( $this->poster_variations );
		for ( $answer = 0; $answer < $count; ++$answer ) {
			$this->recorder->raw( 200, self::png(), 'image/png' );
		}
		ActionSchedulerRunner::run_pending( ProductPictures::ACTION );
		$this->recorder->stop();
		$this->recorder->start();
	}

	/**
	 * Each variation's image, by key.
	 *
	 * @return array<string, int>
	 */
	private function images(): array {
		$images = array();
		foreach ( $this->poster_variations as $key => $variation ) {
			$images[ $key ] = (int) $this->reload( $variation )->get_image_id( 'edit' );
			$this->assertGreaterThan( 0, $images[ $key ] );
		}
		return $images;
	}

	/**
	 * A sign in so many sizes, each a variation selling its own variant, drawn flat.
	 *
	 * @param int $sizes How many.
	 */
	private function sign_of( int $sizes ): WC_Product_Variable {
		$product   = new WC_Product_Variable();
		$attribute = new \WC_Product_Attribute();
		$names     = array_map( static fn ( int $size ): string => 'Size ' . $size, range( 1, $sizes ) );
		$attribute->set_name( 'Size' );
		$attribute->set_options( $names );
		$attribute->set_variation( true );
		$product->set_name( 'Sign' );
		$product->set_attributes( array( $attribute ) );
		$product->save();
		self::config()->save_to( $product );
		ShowcaseImages::save( $product, array( self::LEO ) );
		foreach ( $names as $index => $name ) {
			$variation = new WC_Product_Variation();
			$variation->set_parent_id( $product->get_id() );
			$variation->set_attributes( array( 'size' => sanitize_title( $name ) ) );
			$variation->set_regular_price( '20' );
			$variation->save();
			( new ProductVariant( DesignFixture::BLANK_ID, 'v_size_' . $index, 'v_size_' . $index, '', null, BlankFixture::VARIANT_VIEWS ) )->save_to( $variation );
		}
		$fresh = wc_get_product( $product->get_id() );
		$this->assertInstanceOf( WC_Product_Variable::class, $fresh );
		return $fresh;
	}
}
