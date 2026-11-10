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
use WC_Product;
use WC_Product_Attribute;
use WC_Product_Simple;
use WC_Product_Variable;
use WC_Product_Variation;
use WC_Query;
use WP_Query;
use WP_UnitTestCase;

final class SplitCardListingsTest extends WP_UnitTestCase {

	/**
	 * The attribute the poster is listed by: with a photo, without one, and
	 * framed, which no variation sells.
	 */
	private const PICTURE = 'Bild';

	private const SIZE = 'Storlek';

	/**
	 * Attribute ids by name.
	 *
	 * @var array<string, int>
	 */
	private array $attributes = array();

	private SplitCardListings $listings;

	/**
	 * The category every product of a test is in, whose page lists them.
	 *
	 * @var int
	 */
	private int $category;

	public function set_up(): void {
		parent::set_up();
		// The plugin's own listings and card writers are registered at boot; this one only tells keys apart.
		$this->listings = new SplitCardListings();
		$this->attribute( self::PICTURE, array( 'Med bild', 'Utan bild', 'Ram' ) );
		$this->attribute( self::SIZE, array( '30x40', '50x70' ) );
		$category = wp_insert_term( 'Posters', 'product_cat' );
		$this->assertIsArray( $category );
		$this->category = (int) $category['term_id'];
	}

	public function tear_down(): void {
		foreach ( array_keys( $this->attributes ) as $name ) {
			$taxonomy = wc_attribute_taxonomy_name( $name );
			unregister_taxonomy( $taxonomy );
			unset( $GLOBALS['wc_product_attributes'][ $taxonomy ] );
		}
		delete_transient( 'wc_attribute_taxonomies' );
		delete_option( SplitListing::OPTION );
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

	private function term( string $slug ): int {
		foreach ( array_keys( $this->attributes ) as $name ) {
			$term = get_term_by( 'slug', $slug, wc_attribute_taxonomy_name( $name ) );
			if ( $term instanceof \WP_Term ) {
				return $term->term_id;
			}
		}
		$this->fail( "No term {$slug}" );
	}

	/**
	 * A poster sold with a photo in both sizes, and without one in 30x40.
	 */
	private function poster(): WC_Product {
		$product = new WC_Product_Variable();
		$product->set_name( 'Safari' );
		$product->set_status( 'publish' );
		$product->set_category_ids( array( $this->category ) );
		$product->set_attributes( array( $this->product_attribute( self::PICTURE ), $this->product_attribute( self::SIZE ) ) );
		$product->save();
		$this->variation( $product, 'med-bild', '30x40' );
		$this->variation( $product, 'med-bild', '50x70' );
		$this->variation( $product, 'utan-bild', '30x40' );
		WC_Product_Variable::sync( $product->get_id() );
		$fresh = wc_get_product( $product->get_id() );
		$this->assertInstanceOf( WC_Product::class, $fresh );
		return $fresh;
	}

	private function variation( WC_Product $product, string $picture, string $size ): WC_Product_Variation {
		$variation = new WC_Product_Variation();
		$variation->set_parent_id( $product->get_id() );
		$variation->set_attributes(
			array(
				'pa_bild'    => $picture,
				'pa_storlek' => $size,
			)
		);
		$variation->set_regular_price( '10' );
		$variation->save();
		return $variation;
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

	private function product_attribute( string $name ): WC_Product_Attribute {
		$taxonomy  = wc_attribute_taxonomy_name( $name );
		$attribute = new WC_Product_Attribute();
		$attribute->set_id( $this->attributes[ $name ] );
		$attribute->set_name( $taxonomy );
		$terms = get_terms(
			array(
				'taxonomy'   => $taxonomy,
				'hide_empty' => false,
				'fields'     => 'ids',
				'orderby'    => 'term_id',
			)
		);
		$attribute->set_options( is_array( $terms ) ? $terms : array() );
		$attribute->set_variation( true );
		return $attribute;
	}

	/**
	 * @param string   $name   The attribute's name.
	 * @param string[] $values Its values.
	 * @phpstan-param list<string> $values
	 */
	private function attribute( string $name, array $values ): void {
		$id = wc_create_attribute( array( 'name' => $name ) );
		$this->assertIsInt( $id );
		$taxonomy = wc_attribute_taxonomy_name( $name );
		register_taxonomy( $taxonomy, array( 'product', 'product_variation' ) );
		// What WooCommerce holds for an attribute from `init` on.
		$GLOBALS['wc_product_attributes'][ $taxonomy ] = current( wp_list_filter( wc_get_attribute_taxonomies(), array( 'attribute_id' => $id ) ) );
		foreach ( $values as $value ) {
			wp_insert_term( $value, $taxonomy );
		}
		$this->attributes[ $name ] = $id;
	}
}
