<?php
/**
 * @package Pixonaut\WooCommerce
 */

declare(strict_types=1);

namespace Pixonaut\WooCommerce\Tests\Integration\Storefront;

use Pixonaut\WooCommerce\Catalogue\InvalidReason;
use Pixonaut\WooCommerce\Catalogue\PrintSize;
use Pixonaut\WooCommerce\Catalogue\ProductConfig;
use Pixonaut\WooCommerce\Catalogue\Side;
use Pixonaut\WooCommerce\Catalogue\SplitCards;
use Pixonaut\WooCommerce\Catalogue\SplitListing;
use Pixonaut\WooCommerce\Migrations\WriteSplitCards;
use Pixonaut\WooCommerce\Storefront\SplitCardListings;
use Pixonaut\WooCommerce\Tests\Support\DesignFixture;
use Pixonaut\WooCommerce\Tests\Support\SplitPosters;
use WC_Product;
use WC_Product_Simple;
use WC_Query;
use WP_Query;
use WP_UnitTestCase;

final class SplitCardListingsTest extends WP_UnitTestCase {

	use SplitPosters;

	/**
	 * The attribute the poster is listed by: with a photo, without one, and
	 * framed, which no variation sells.
	 */
	private const PICTURE = 'Bild';

	private const SIZE = 'Storlek';

	private SplitCardListings $listings;

	public function set_up(): void {
		parent::set_up();
		// The plugin's own listings and card writers are registered at boot; this one only tells keys apart.
		$this->listings = new SplitCardListings();
		$this->set_up_posters();
	}

	public function tear_down(): void {
		$this->tear_down_posters();
		$_GET = array();
		$this->forget_chosen_filters();
		parent::tear_down();
	}

	public function test_a_split_product_has_a_card_per_value_a_variation_sells(): void {
		$poster = $this->poster();
		$this->assertSame( array(), $this->cards( $poster ), 'listed once until the attribute splits' );

		SplitListing::set_attribute_default( $this->attributes[ self::PICTURE ], true );
		$this->assertSame( array( $this->term( 'med-bild' ), $this->term( 'utan-bild' ) ), $this->cards( $poster ), 'framed has no variation' );

		$this->choose( $poster, array( $this->attributes[ self::PICTURE ] => SplitListing::OFF ) );
		$this->assertSame( array(), $this->cards( $poster ), 'the product turns it off for itself' );

		$this->choose( $poster, array( $this->attributes[ self::SIZE ] => SplitListing::ON ) );
		$this->assertSame( array( $this->term( '30x40' ), $this->term( '50x70' ) ), $this->cards( $poster ), 'or picks another attribute' );

		SplitListing::set_attribute_default( $this->attributes[ self::PICTURE ], false );
		$this->choose( $poster, array() );
		$this->assertSame( array(), $this->cards( $poster ) );
	}

	public function test_a_variations_change_rewrites_its_products_cards(): void {
		SplitListing::set_attribute_default( $this->attributes[ self::PICTURE ], true );
		$poster = $this->poster();
		$framed = $this->variation( $poster, 'ram', '30x40' );
		$this->assertSame( array( $this->term( 'med-bild' ), $this->term( 'ram' ), $this->term( 'utan-bild' ) ), $this->cards( $poster ), 'a new variation adds its value, in the order WooCommerce lists the values' );

		$framed->set_status( 'private' );
		$framed->save();
		$this->assertSame( array( $this->term( 'med-bild' ), $this->term( 'utan-bild' ) ), $this->cards( $poster ), 'a disabled variation sells nothing' );

		$framed->set_status( 'publish' );
		$framed->save();
		$framed->delete( true );
		$this->assertSame( array( $this->term( 'med-bild' ), $this->term( 'utan-bild' ) ), $this->cards( $poster ), 'a deleted one neither' );

		wp_delete_term( $this->term( 'utan-bild' ), 'pa_bild' );
		$this->assertSame( array( $this->term( 'med-bild' ) ), $this->cards( $poster ), 'a deleted value has no card' );
	}

	public function test_a_trashed_and_restored_product_has_its_cards_again(): void {
		SplitListing::set_attribute_default( $this->attributes[ self::PICTURE ], true );
		$poster = $this->poster();
		$both   = array( $this->term( 'med-bild' ), $this->term( 'utan-bild' ) );

		wp_trash_post( $poster->get_id() );
		$this->assertSame( array(), $this->cards( $poster ), 'its variations are trashed with it' );

		wp_untrash_post( $poster->get_id() );
		$this->assertSame( $both, $this->cards( $poster ) );
	}

	public function test_a_trashed_restored_or_deleted_variation_rewrites_its_products_cards(): void {
		SplitListing::set_attribute_default( $this->attributes[ self::PICTURE ], true );
		$poster  = $this->poster();
		$without = $this->variation_of( $poster, 'Utan bild' );

		wp_trash_post( $without->get_id() );
		$this->assertSame( array( $this->term( 'med-bild' ) ), $this->cards( $poster ) );

		wp_untrash_post( $without->get_id() );
		$this->assertSame( array( $this->term( 'med-bild' ), $this->term( 'utan-bild' ) ), $this->cards( $poster ) );

		wp_delete_post( $without->get_id(), true );
		$this->assertSame( array( $this->term( 'med-bild' ) ), $this->cards( $poster ), 'deleted without WooCommerce' );
	}

	public function test_an_upgrade_writes_the_cards_of_a_product_set_up_before_they_were_kept(): void {
		SplitListing::set_attribute_default( $this->attributes[ self::PICTURE ], true );
		$poster = $this->poster();
		delete_post_meta( $poster->get_id(), SplitCards::META );

		( new WriteSplitCards() )->up();
		SplitCards::queue_backfill();
		SplitCards::write_all( 0 );

		$this->assertSame( array( $this->term( 'med-bild' ), $this->term( 'utan-bild' ) ), $this->cards( $poster ) );
		as_unschedule_all_actions( SplitCards::ALL_HOOK );
	}

	public function test_the_shop_lists_a_card_per_value_and_counts_them(): void {
		SplitListing::set_attribute_default( $this->attributes[ self::PICTURE ], true );
		$poster = $this->poster();
		$mug    = $this->simple( 'Uggla' );

		$listed = $this->shop();

		$this->assertSame( 3, $listed['found'] );
		$this->assertSame( array( $poster->get_id(), $poster->get_id(), $mug->get_id() ), $listed['ids'] );
		$this->assertSame(
			array(
				add_query_arg( 'attribute_pa_bild', 'med-bild', (string) get_permalink( $poster->get_id() ) ),
				add_query_arg(
					array(
						'attribute_pa_bild'    => 'utan-bild',
						'attribute_pa_storlek' => '30x40',
					),
					(string) get_permalink( $poster->get_id() )
				),
				(string) get_permalink( $mug->get_id() ),
			),
			$listed['links'],
			'without a photo comes only in 30x40, which its card chooses too'
		);
		$this->assertSame( (string) get_permalink( $poster->get_id() ), get_permalink( $poster->get_id() ), 'out of the loop the product links as itself' );
	}

	public function test_the_shop_pages_by_card(): void {
		SplitListing::set_attribute_default( $this->attributes[ self::PICTURE ], true );
		$poster = $this->poster();
		$this->simple( 'Uggla' );
		$per_page = static fn (): int => 2;
		add_filter( 'loop_shop_per_page', $per_page );

		$first  = $this->shop();
		$second = $this->shop( array( 'paged' => 2 ) );

		remove_filter( 'loop_shop_per_page', $per_page );
		$this->assertSame( 3, $first['found'] );
		$this->assertSame( 2, $first['pages'] );
		$this->assertSame( array( $poster->get_id(), $poster->get_id() ), $first['ids'] );
		$this->assertCount( 1, $second['ids'] );
		$this->assertNotSame( $poster->get_id(), $second['ids'][0] );
	}

	public function test_a_filter_by_the_split_attribute_keeps_the_chosen_values_cards(): void {
		SplitListing::set_attribute_default( $this->attributes[ self::PICTURE ], true );
		$poster = $this->poster();

		$listed = $this->shop( array( 'filter_bild' => 'utan-bild' ) );

		$this->assertSame( array( $poster->get_id() ), $listed['ids'] );
		$this->assertSame( 1, $listed['found'] );
		$this->assertStringContainsString( 'attribute_pa_bild=utan-bild', $listed['links'][0] );
	}

	public function test_a_filter_by_another_attribute_keeps_every_card_of_a_product_it_keeps(): void {
		SplitListing::set_attribute_default( $this->attributes[ self::PICTURE ], true );
		$poster = $this->poster();

		$listed = $this->shop( array( 'filter_storlek' => '30x40' ) );

		$this->assertSame( array( $poster->get_id(), $poster->get_id() ), $listed['ids'] );
		$this->assertSame( 2, $listed['found'] );
	}

	public function test_a_value_sold_only_on_a_template_that_is_not_live_has_no_card(): void {
		SplitListing::set_attribute_default( $this->attributes[ self::PICTURE ], true );
		$poster = $this->poster();
		self::personalised()->save_to( $poster );
		$paused = self::personalised()->with_override( true )->with_validity( false, InvalidReason::TEMPLATE_NOT_LIVE );
		$sizes  = array();
		foreach ( $poster->get_children() as $id ) {
			$variation = wc_get_product( $id );
			$this->assertInstanceOf( WC_Product::class, $variation );
			$picture = $variation->get_attribute( 'pa_bild' );
			if ( 'Utan bild' === $picture || '50x70' === $variation->get_attribute( 'pa_storlek' ) ) {
				$paused->save_to( $variation );
			}
			$sizes[ $picture ][] = $variation->get_attribute( 'pa_storlek' );
		}
		$this->assertSame( array( '30x40', '50x70' ), $sizes['Med bild'] );

		$listed = $this->shop();

		$this->assertSame( array( $poster->get_id() ), $listed['ids'], 'without a photo is only on the paused template' );
		$this->assertSame( 1, $listed['found'] );
		$this->assertSame(
			add_query_arg(
				array(
					'attribute_pa_bild'    => 'med-bild',
					'attribute_pa_storlek' => '30x40',
				),
				(string) get_permalink( $poster->get_id() )
			),
			$listed['links'][0],
			'the card chooses the one size it is offered in on a live template'
		);

		self::personalised()->with_override( true )->save_to( $this->variation_of( $poster, 'Utan bild' ) );
		$this->assertSame( 2, $this->shop()['found'], 'live again, it is listed again' );
	}

	public function test_another_product_query_lists_the_product_once(): void {
		SplitListing::set_attribute_default( $this->attributes[ self::PICTURE ], true );
		$poster = $this->poster();
		$this->shop();

		$query = new WP_Query(
			array(
				'post_type' => 'product',
				'wc_query'  => 'product_query',
			)
		);

		$this->assertSame( array( $poster->get_id() ), wp_list_pluck( (array) $query->posts, 'ID' ) );
		$cached = get_post( $poster->get_id() );
		$this->assertInstanceOf( \WP_Post::class, $cached );
		$this->assertObjectNotHasProperty( 'pixonaut_wc_split_term', $cached, 'the cached post holds no card' );
		$query->the_post();
		$this->assertSame( (string) get_permalink( $poster->get_id() ), get_permalink( $poster->get_id() ) );
		wp_reset_postdata();
	}

	public function test_a_product_templates_cards_get_keys_of_their_own(): void {
		$this->listings->start_template( null, array( 'blockName' => 'woocommerce/product-template' ) );
		$first = new \WP_Post( (object) array( 'ID' => 7 ) );
		$again = new \WP_Post( (object) array( 'ID' => 7 ) );
		$other = new \WP_Post( (object) array( 'ID' => 9 ) );
		$this->set_cards( array( array( $first, 11 ), array( $again, 12 ) ) );
		foreach ( array( $first, $again, $other ) as $post ) {
			$this->listings->enter( $post );
		}

		$keyed = $this->listings->keys( '<li data-wp-key="product-item-7"></li><li data-wp-key="product-item-7"></li><li data-wp-key="product-item-9"></li>' );

		$this->assertSame( '<li data-wp-key="product-item-7-11"></li><li data-wp-key="product-item-7-12"></li><li data-wp-key="product-item-9"></li>', $keyed );
	}

	/**
	 * A personalised configuration on a live template.
	 */
	private static function personalised(): ProductConfig {
		return new ProductConfig(
			personalise: true,
			blank_id: DesignFixture::BLANK_ID,
			blank_name: 'Fixture tee',
			sides: array(
				new Side( 'front', 'Front', DesignFixture::PLACEMENT_ID, 'Front', DesignFixture::TEMPLATE_BASE_ID, DesignFixture::LIVE_VERSION, null, 'Mini tee', array( new PrintSize( DesignFixture::WIDTH_MM, DesignFixture::HEIGHT_MM, 'Large' ) ) ),
			)
		);
	}

	/**
	 * A product's variation with a picture.
	 *
	 * @param WC_Product $product A poster.
	 * @param string     $picture The picture's name.
	 */
	private function variation_of( WC_Product $product, string $picture ): WC_Product {
		foreach ( $product->get_children() as $id ) {
			$variation = wc_get_product( $id );
			if ( $variation instanceof WC_Product && $picture === $variation->get_attribute( 'pa_bild' ) ) {
				return $variation;
			}
		}
		$this->fail( "No variation {$picture}" );
	}

	/**
	 * Gives posts card values as a listing's results would.
	 *
	 * @param list<array{0: \WP_Post, 1: int}> $cards Each post and its value.
	 */
	private function set_cards( array $cards ): void {
		$map = ( fn (): \WeakMap => $this->cards )->call( $this->listings );
		foreach ( $cards as list( $post, $term_id ) ) {
			$map[ $post ] = $term_id;
		}
	}

	/**
	 * The shop's main query on the category page and its loop, as a theme runs them.
	 *
	 * @param array<string, string|int> $args Query parameters.
	 * @return array{ids: list<int>, links: list<string>, found: int, pages: int}
	 */
	private function shop( array $args = array() ): array {
		$this->forget_chosen_filters();
		$_GET = array_map( 'strval', $args );
		$page = get_term_link( $this->category, 'product_cat' );
		$this->assertIsString( $page );
		$this->go_to( add_query_arg( $args, $page ) );
		global $wp_query;
		$this->assertSame( 'product_query', $wp_query->get( 'wc_query' ), 'a listing of the shop\'s' );
		$ids   = array();
		$links = array();
		while ( $wp_query->have_posts() ) {
			$wp_query->the_post();
			$ids[]   = (int) get_the_ID();
			$links[] = (string) get_permalink();
		}
		wp_reset_postdata();
		return array(
			'ids'   => $ids,
			'links' => $links,
			'found' => (int) $wp_query->found_posts,
			'pages' => (int) $wp_query->max_num_pages,
		);
	}

	/**
	 * WooCommerce reads the chosen filters once a request.
	 */
	private function forget_chosen_filters(): void {
		$property = new \ReflectionProperty( WC_Query::class, 'chosen_attributes' );
		$property->setValue( null, null );
	}

	/**
	 * @param WC_Product $product A product.
	 * @return list<int>
	 */
	private function cards( WC_Product $product ): array {
		return array_values( array_map( 'intval', get_post_meta( $product->get_id(), SplitCards::META, false ) ) );
	}

	/**
	 * @param WC_Product         $product A product.
	 * @param array<int, string> $choices Choices by attribute id.
	 */
	private function choose( WC_Product $product, array $choices ): void {
		$fresh = wc_get_product( $product->get_id() );
		$this->assertInstanceOf( WC_Product::class, $fresh );
		ProductConfig::stored_on( $fresh )->with_split_listing( $choices )->save_to( $fresh );
	}

	private function simple( string $name ): WC_Product {
		$product = new WC_Product_Simple();
		$product->set_name( $name );
		$product->set_status( 'publish' );
		$product->set_category_ids( array( $this->category ) );
		$product->set_regular_price( '5' );
		$product->save();
		return $product;
	}
}
