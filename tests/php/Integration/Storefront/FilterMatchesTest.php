<?php
/**
 * @package Pixonaut\WooCommerce
 */

declare(strict_types=1);

namespace Pixonaut\WooCommerce\Tests\Integration\Storefront;

use Pixonaut\WooCommerce\Catalogue\SplitListing;
use Pixonaut\WooCommerce\Settings\Settings;
use Pixonaut\WooCommerce\Storefront\AttributeDependencies;
use Pixonaut\WooCommerce\Tests\Support\SplitPosters;
use WC_Product;
use WC_Product_Attribute;
use WC_Product_Grouped;
use WC_Product_Simple;
use WC_Product_Variable;
use WC_Product_Variation;
use WC_Query;
use WP_UnitTestCase;

/**
 * A poster sold bare, framed in oak or black, or with a black hanger, in
 * two sizes; the frame colour applies only to the framed and the hanger
 * variations. A print beside it is sold bare in 30x40 at 199 and framed in
 * oak in 50x70 at 350.
 */
final class FilterMatchesTest extends WP_UnitTestCase {

	use SplitPosters;

	/**
	 * The attribute the poster is listed by.
	 */
	private const PICTURE = 'Inramning';

	private const SIZE = 'Storlek';

	private const COLOUR = 'Ramfärg';

	private const PRICE_LOW = '299';

	private const PRICE_HIGH = '391';

	private WC_Product $poster;

	public function set_up(): void {
		parent::set_up();
		$this->attribute( self::PICTURE, array( 'Ingen', 'Ram', 'Hängare' ) );
		$this->attribute( self::SIZE, array( '30x40', '50x70' ) );
		$this->attribute( self::COLOUR, array( 'Ek', 'Svart' ) );
		$category = wp_insert_term( 'Tavlor', 'product_cat' );
		$this->assertIsArray( $category );
		$this->category = (int) $category['term_id'];
		delete_transient( 'pixonaut_wc_attribute_dependencies' );

		$this->poster = $this->product(
			'Safari',
			array(
				array( '30x40', 'ingen', '', '199' ),
				array( '50x70', 'ingen', '', '299' ),
				array( '30x40', 'ram', 'ek', '349' ),
				array( '30x40', 'ram', 'svart', '349' ),
				array( '50x70', 'ram', 'ek', '499' ),
				array( '50x70', 'hangare', 'svart', '450' ),
			)
		);
		$this->product(
			'Skog',
			array(
				array( '30x40', 'ingen', '', '199' ),
				array( '50x70', 'ram', 'ek', '350' ),
			)
		);
	}

	public function tear_down(): void {
		$this->tear_down_posters();
		update_option( Settings::OPTION, array() );
		$_GET = array();
		$this->forget_chosen_filters();
		parent::tear_down();
	}

	public function test_a_bare_choice_with_a_frame_colour_lists_nothing(): void {
		$listed = $this->shop(
			array(
				'filter_inramning' => 'ingen',
				'filter_ramfarg'   => 'ek',
			)
		);

		$this->assertSame( array(), $listed['ids'], 'no variation is bare and in oak' );
		$this->assertSame( 0, $listed['found'] );
	}

	public function test_a_size_and_a_price_list_only_products_with_that_size_in_the_range(): void {
		$listed = $this->shop(
			array(
				'filter_storlek' => '30x40',
				'min_price'      => self::PRICE_LOW,
				'max_price'      => self::PRICE_HIGH,
			)
		);

		$this->assertSame( array( $this->poster->get_id() ), $listed['ids'], 'the print\'s 30x40 is 199, its 350 is 50x70' );
		$this->assertSame(
			array(
				$this->term( '30x40' ) => 1,
				$this->term( '50x70' ) => 2,
			),
			$this->counts(
				'pa_storlek',
				array(
					'min_price' => self::PRICE_LOW,
					'max_price' => self::PRICE_HIGH,
				)
			),
			'the price counts with the values: 50x70 bare at 299, and the print framed at 350'
		);
	}

	public function test_each_card_counts_and_the_counts_follow_the_filters(): void {
		SplitListing::set_attribute_default( $this->attributes[ self::PICTURE ], true );

		$unfiltered = $this->counts( 'pa_storlek', array() );
		$this->assertSame(
			array(
				$this->term( '30x40' ) => 3,
				$this->term( '50x70' ) => 4,
			),
			$unfiltered,
			'the poster\'s bare and framed cards in 30x40, all three in 50x70, and the print once in each'
		);

		$oak = $this->counts( 'pa_storlek', array( 'filter_ramfarg' => 'ek' ) );
		$this->assertSame(
			array(
				$this->term( '30x40' ) => 1,
				$this->term( '50x70' ) => 2,
			),
			$oak,
			'oak takes away the bare and the hanger cards'
		);
		$this->assertSame( 2, $this->shop( array( 'filter_ramfarg' => 'ek' ) )['found'], 'the poster\'s framed card and the print\'s' );
		$this->assertSame(
			1,
			$this->shop(
				array(
					'filter_ramfarg' => 'ek',
					'filter_storlek' => '30x40',
				)
			)['found']
		);
		$this->assertSame(
			2,
			$this->shop(
				array(
					'filter_ramfarg' => 'ek',
					'filter_storlek' => '50x70',
				)
			)['found']
		);

		$framing = $this->counts( 'pa_inramning', array( 'filter_ramfarg' => 'ek' ) );
		$this->assertArrayNotHasKey( $this->term( 'ingen' ), $framing, 'nothing bare is oak' );
		$this->assertArrayNotHasKey( $this->term( 'hangare' ), $framing, 'the hanger is only black' );
		$this->assertSame( 2, $framing[ $this->term( 'ram' ) ] ?? null );
		$this->assertSame(
			2,
			$this->shop(
				array(
					'filter_ramfarg'   => 'ek',
					'filter_inramning' => 'ram',
				)
			)['found']
		);

		$colour = $this->counts( 'pa_ramfarg', array( 'filter_inramning' => 'hangare' ) );
		$this->assertSame( array( $this->term( 'svart' ) => 1 ), $colour );
	}

	public function test_with_one_card_per_product_a_product_counts_once(): void {
		SplitListing::set_attribute_default( $this->attributes[ self::PICTURE ], true );
		( new Settings() )->set( Settings::ONE_CARD_PER_PRODUCT, true );

		$this->assertSame(
			array(
				$this->term( '30x40' ) => 2,
				$this->term( '50x70' ) => 2,
			),
			$this->counts( 'pa_storlek', array() )
		);
		$this->assertSame(
			0,
			$this->shop(
				array(
					'filter_inramning' => 'ingen',
					'filter_ramfarg'   => 'ek',
				)
			)['found']
		);
		$this->assertSame(
			1,
			$this->shop(
				array(
					'min_price' => '450',
					'max_price' => '460',
				)
			)['found'],
			'a price alone leaves out the print'
		);
	}

	public function test_the_counts_keep_the_blocks_other_filters_and_their_query_types(): void {
		$sold_out = new WC_Product_Simple();
		$sold_out->set_name( 'Slutsåld' );
		$sold_out->set_status( 'publish' );
		$sold_out->set_category_ids( array( $this->category ) );
		$sold_out->set_attributes( array( $this->plain_attribute( self::SIZE, '30x40' ) ) );
		$sold_out->set_regular_price( '100' );
		$sold_out->set_stock_status( 'outofstock' );
		$sold_out->save();

		$this->assertSame( 3, $this->counts( 'pa_storlek', array() )[ $this->term( '30x40' ) ] ?? null, 'both posters and the sold-out one' );
		$this->assertSame( 2, $this->counts( 'pa_storlek', array( 'filter_stock_status' => 'instock' ) )[ $this->term( '30x40' ) ] ?? null, 'the stock filter stays on' );

		$all = array(
			'filter_inramning'     => 'ram',
			'query_type_inramning' => 'and',
		);
		$this->assertSame(
			array(
				$this->term( 'ingen' )   => 2,
				$this->term( 'ram' )     => 2,
				$this->term( 'hangare' ) => 1,
			),
			$this->counts( 'pa_inramning', $all, false ),
			'with "and", a product counts for a value only while it holds every chosen one too: the print has no hanger'
		);
	}

	public function test_a_grouped_product_matches_a_price_any_of_its_children_has(): void {
		$cheap = $this->child( '100' );
		$dear  = $this->child( '500' );
		$group = new WC_Product_Grouped();
		$group->set_name( 'Paket' );
		$group->set_status( 'publish' );
		$group->set_category_ids( array( $this->category ) );
		$group->set_attributes( array( $this->plain_attribute( self::SIZE, '30x40' ) ) );
		$group->set_children( array( $cheap->get_id(), $dear->get_id() ) );
		$group->save();

		$range = array(
			'min_price' => '450',
			'max_price' => '600',
		);
		$this->assertContains( $group->get_id(), $this->shop( $range )['ids'] );
		$this->assertSame( 1, $this->counts( 'pa_storlek', $range )[ $this->term( '30x40' ) ] ?? null, 'the group\'s 500 child; no 30x40 variation costs that much' );
	}

	public function test_the_theme_reads_which_attribute_a_dependent_one_applies_under(): void {
		$this->assertSame(
			array( 'pa_ramfarg' => array( 'pa_inramning' => array( 'ram', 'hangare' ) ) ),
			apply_filters( AttributeDependencies::FILTER, array() )
		);
		$this->assertSame(
			array(
				'pa_other'   => array( 'pa_size' => array( 'l' ) ),
				'pa_ramfarg' => array( 'pa_inramning' => array( 'ram', 'hangare' ) ),
			),
			apply_filters( AttributeDependencies::FILTER, array( 'pa_other' => array( 'pa_size' => array( 'l' ) ) ) ),
			'added to what another callback answered'
		);
	}

	/**
	 * A variable product in the category with the three attributes.
	 *
	 * @param string                                                  $name       Its name.
	 * @param list<array{0: string, 1: string, 2: string, 3: string}> $variations Size, framing, colour and price of each.
	 */
	private function product( string $name, array $variations ): WC_Product {
		$product = new WC_Product_Variable();
		$product->set_name( $name );
		$product->set_status( 'publish' );
		$product->set_category_ids( array( $this->category ) );
		$product->set_attributes( array( $this->product_attribute( self::SIZE ), $this->product_attribute( self::PICTURE ), $this->product_attribute( self::COLOUR ) ) );
		$product->save();
		foreach ( $variations as list( $size, $framing, $colour, $price ) ) {
			$this->sell( $product, $size, $framing, $colour, $price );
		}
		WC_Product_Variable::sync( $product->get_id() );
		$fresh = wc_get_product( $product->get_id() );
		$this->assertInstanceOf( WC_Product::class, $fresh );
		return $fresh;
	}

	private function sell( WC_Product $product, string $size, string $framing, string $colour, string $price ): void {
		$variation = new WC_Product_Variation();
		$variation->set_parent_id( $product->get_id() );
		$variation->set_attributes(
			array(
				'pa_storlek'   => $size,
				'pa_inramning' => $framing,
				'pa_ramfarg'   => $colour,
			)
		);
		$variation->set_regular_price( $price );
		$variation->save();
	}

	/**
	 * A product attribute holding one value, not used for variations.
	 *
	 * @param string $name  The attribute's name.
	 * @param string $value The value's slug.
	 */
	private function plain_attribute( string $name, string $value ): WC_Product_Attribute {
		$attribute = $this->product_attribute( $name );
		$attribute->set_options( array( $this->term( $value ) ) );
		$attribute->set_variation( false );
		return $attribute;
	}

	/**
	 * A simple product outside the category, for a group.
	 *
	 * @param string $price Its price.
	 */
	private function child( string $price ): WC_Product {
		$child = new WC_Product_Simple();
		$child->set_name( 'Del ' . $price );
		$child->set_status( 'publish' );
		$child->set_regular_price( $price );
		$child->save();
		return $child;
	}

	/**
	 * The shop's main query on the category page and its loop.
	 *
	 * @param array<string, string> $args Query parameters.
	 * @return array{ids: list<int>, found: int}
	 */
	private function shop( array $args ): array {
		$this->go_to_listing( $args );
		global $wp_query;
		$ids = array();
		while ( $wp_query->have_posts() ) {
			$wp_query->the_post();
			$ids[] = (int) get_the_ID();
		}
		wp_reset_postdata();
		return array(
			'ids'   => array_values( array_unique( $ids ) ),
			'found' => (int) $wp_query->found_posts,
		);
	}

	/**
	 * The Product Filters block's counts for an attribute, as its checkbox
	 * list asks for them: the listing's query without the attribute's own
	 * filter, its values combining with `or`.
	 *
	 * @param string                $taxonomy The counted attribute.
	 * @param array<string, string> $args     Query parameters.
	 * @param bool                  $any      Whether its values combine with `or`, so the block drops its own filter.
	 * @return array<int, int> Counts by term id.
	 */
	private function counts( string $taxonomy, array $args, bool $any = true ): array {
		$this->go_to_listing( $args );
		global $wp_query;
		$query_vars = array_merge( $wp_query->query_vars, $args );
		if ( $any ) {
			unset( $query_vars[ 'filter_' . substr( $taxonomy, strlen( 'pa_' ) ) ] );
		}
		// As WooCommerce's FilterData asks before counting itself.
		$counts = apply_filters( 'woocommerce_pre_product_filter_data', null, 'attribute', $query_vars, array( 'taxonomy' => $taxonomy ) );
		$this->assertIsArray( $counts );
		ksort( $counts );
		return $counts;
	}

	/**
	 * @param array<string, string> $args Query parameters.
	 */
	private function go_to_listing( array $args ): void {
		$this->forget_chosen_filters();
		$_GET = $args;
		$page = get_term_link( $this->category, 'product_cat' );
		$this->assertIsString( $page );
		$this->go_to( add_query_arg( $args, $page ) );
	}

	/**
	 * WooCommerce reads the chosen filters once a request.
	 */
	private function forget_chosen_filters(): void {
		( new \ReflectionProperty( WC_Query::class, 'chosen_attributes' ) )->setValue( null, null );
	}
}
