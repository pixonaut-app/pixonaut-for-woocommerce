<?php
/**
 * @package Pixonaut\WooCommerce
 */

declare(strict_types=1);

namespace Pixonaut\WooCommerce\Tests\Integration\Storefront;

use ActionScheduler;
use Pixonaut\WooCommerce\Catalogue\PictureQueue;
use Pixonaut\WooCommerce\Catalogue\ProductPictures;
use Pixonaut\WooCommerce\Catalogue\SplitCardLooks;
use Pixonaut\WooCommerce\Catalogue\SplitCards;
use Pixonaut\WooCommerce\Catalogue\SplitListing;
use Pixonaut\WooCommerce\Tests\Integration\Catalogue\PicturedProducts;
use Pixonaut\WooCommerce\Tests\Support\ActionSchedulerRunner;
use Pixonaut\WooCommerce\Tests\Support\DependentPosters;
use WC_Product;
use WC_Product_Variable;
use WC_Query;
use WP_UnitTestCase;

/**
 * A poster listed by its framing while a filter is on one, each framing's
 * card showing the picture of the variation the filters open it on: the
 * framing in the colour chosen, not only the framing.
 */
final class FilteredCardPicturesTest extends WP_UnitTestCase {

	use DependentPosters;
	use PicturedProducts;

	private int $category;

	private WC_Product_Variable $poster;

	public function set_up(): void {
		parent::set_up();
		$this->set_up_pictures();
		$this->set_up_dependent_posters();
		$category = wp_insert_term( 'Posters', 'product_cat' );
		$this->assertIsArray( $category );
		$this->category = (int) $category['term_id'];
		$this->poster   = $this->dependent_poster( array( $this->category ) );
		self::config( null )
			->with_split_listing( array( $this->poster_attributes['Inramning'] => SplitListing::FILTERED ) )
			->save_to( $this->poster );
		$this->forget_queued();
	}

	public function tear_down(): void {
		$this->tear_down_dependent_posters();
		$this->tear_down_pictures();
		$_GET = array();
		$this->forget_chosen_filters();
		delete_option( PictureQueue::BACKFILL_OPTION );
		parent::tear_down();
	}

	public function test_a_framings_card_shows_the_picture_of_the_filtered_colour(): void {
		$pictures = $this->picture_each_variation();

		$this->assertSame(
			array( $pictures['ram-vit'] ),
			$this->shop(
				array(
					'filter_inramning' => 'ram',
					'filter_ramfarg'   => 'vit',
				)
			),
			'framed in white'
		);
		$this->assertSame(
			array( $pictures['hangare-svart'] ),
			$this->shop(
				array(
					'filter_inramning'  => 'hangare',
					'filter_hangarfarg' => 'svart',
				)
			),
			'on a black hanger'
		);
		$this->assertSame( array( $pictures['ram-svart'] ), $this->shop( array( 'filter_inramning' => 'ram' ) ), 'a filter on the framing alone shows the colour the card opens on' );
		$this->assertSame( array(), ActionSchedulerRunner::pending( ProductPictures::ACTION ), 'nothing to draw' );
	}

	public function test_a_filtered_colour_without_a_picture_is_drawn_and_shown_meanwhile_by_its_framing(): void {
		$pictures = $this->picture_each_variation( array( 'ram-vit' ) );

		$this->assertSame(
			array( $pictures['ram-svart'] ),
			$this->shop(
				array(
					'filter_inramning' => 'ram',
					'filter_ramfarg'   => 'vit',
				)
			),
			'meanwhile the framing in another colour'
		);
		$this->assertSame( array( $this->poster_variations['ram-vit']->get_id() ), $this->queued(), 'only the picture the card waits for is queued' );

		$this->shop(
			array(
				'filter_inramning' => 'ram',
				'filter_ramfarg'   => 'vit',
			)
		);
		$this->assertCount( 1, ActionSchedulerRunner::pending( ProductPictures::ACTION ), 'queued once' );

		$this->recorder->raw( 200, self::png(), 'image/png' );
		ActionSchedulerRunner::run_pending( ProductPictures::ACTION );
		$drawn = (int) $this->reload( $this->poster_variations['ram-vit'] )->get_image_id( 'edit' );
		$this->assertGreaterThan( 0, $drawn );
		$this->assertNotContains( $drawn, $pictures );

		$this->assertSame(
			array( $drawn ),
			$this->shop(
				array(
					'filter_inramning' => 'ram',
					'filter_ramfarg'   => 'vit',
				)
			),
			'once drawn, framed in white'
		);
		$this->assertSame( array(), ActionSchedulerRunner::pending( ProductPictures::ACTION ) );
	}

	public function test_a_card_with_its_own_image_draws_no_picture_for_the_filtered_colour(): void {
		$this->picture_each_variation( array( 'ram-vit' ) );
		// A card's own image must be one the media library knows as an image.
		$own = self::factory()->attachment->create_object(
			array(
				'file'           => 'card.png',
				'post_mime_type' => 'image/png',
			)
		);
		$this->assertIsInt( $own );
		SplitCardLooks::save(
			$this->poster->get_id(),
			array(
				SplitCards::key( array( $this->poster_term( 'Inramning', 'ram' ) ) ) => array(
					'title' => '',
					'image' => $own,
				),
			)
		);

		$this->assertSame(
			array( $own ),
			$this->shop(
				array(
					'filter_inramning' => 'ram',
					'filter_ramfarg'   => 'vit',
				)
			)
		);
		$this->assertSame( array(), ActionSchedulerRunner::pending( ProductPictures::ACTION ), 'the card never shows the drawing' );
	}

	/**
	 * Gives each of the poster's variations an image of its own, as a drawn picture is.
	 *
	 * @param string[] $without The variations left without one, by key.
	 * @return array<string, int> Each image, by variation key.
	 */
	private function picture_each_variation( array $without = array() ): array {
		$pictures = array();
		foreach ( $this->poster_variations as $key => $variation ) {
			if ( in_array( $key, $without, true ) ) {
				continue;
			}
			$pictures[ $key ] = $this->image();
			$fresh            = $this->reload( $variation );
			$fresh->set_image_id( $pictures[ $key ] );
			$fresh->save();
		}
		$this->forget_queued();
		return $pictures;
	}

	/**
	 * Drops every drawing the poster's changes queued: each test draws what it needs.
	 */
	private function forget_queued(): void {
		as_unschedule_all_actions( ProductPictures::ACTION );
		as_unschedule_all_actions( PictureQueue::VARIATIONS_ACTION );
		foreach ( array_merge( array( $this->poster->get_id() ), $this->poster->get_children() ) as $id ) {
			// What the job tells the queue as it starts: the product may be queued again.
			$this->plugin->picture_queue()->running( $id );
		}
	}

	/**
	 * The variations whose drawing waits to start.
	 *
	 * @return list<int>
	 */
	private function queued(): array {
		$ids = array();
		foreach ( ActionSchedulerRunner::pending( ProductPictures::ACTION ) as $action_id ) {
			$ids[] = (int) ( ActionScheduler::store()->fetch_action( (string) $action_id )->get_args()[0] ?? 0 );
		}
		return $ids;
	}

	/**
	 * The images of the category page's cards, as a theme prints them.
	 *
	 * @param array<string, string> $filters The listing's filters.
	 * @return list<int>
	 */
	private function shop( array $filters ): array {
		$this->forget_chosen_filters();
		$_GET = $filters;
		$page = get_term_link( $this->category, 'product_cat' );
		$this->assertIsString( $page );
		$this->go_to( add_query_arg( $filters, $page ) );
		global $wp_query;
		$images = array();
		while ( $wp_query->have_posts() ) {
			$wp_query->the_post();
			$product = wc_get_product( (int) get_the_ID() );
			$this->assertInstanceOf( WC_Product::class, $product );
			$images[] = (int) $product->get_image_id();
		}
		wp_reset_postdata();
		return $images;
	}

	/**
	 * WooCommerce reads the chosen filters once a request.
	 */
	private function forget_chosen_filters(): void {
		( new \ReflectionProperty( WC_Query::class, 'chosen_attributes' ) )->setValue( null, null );
	}
}
