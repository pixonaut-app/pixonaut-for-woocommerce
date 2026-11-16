<?php
/**
 * @package Pixonaut\WooCommerce
 */

declare(strict_types=1);

namespace Pixonaut\WooCommerce\Tests\Integration\Storefront;

use Pixonaut\WooCommerce\Admin\SplitListingFields;
use Pixonaut\WooCommerce\Catalogue\CardPages;
use Pixonaut\WooCommerce\Catalogue\ProductConfig;
use Pixonaut\WooCommerce\Catalogue\SplitCardLooks;
use Pixonaut\WooCommerce\Catalogue\SplitCards;
use Pixonaut\WooCommerce\Catalogue\SplitListing;
use Pixonaut\WooCommerce\Templates;
use Pixonaut\WooCommerce\Tests\Support\DependentPosters;
use WC_Product;
use WC_Product_Attribute;
use WC_Product_Variable;
use WC_Query;
use WP_UnitTestCase;

/**
 * A poster sold bare, with a hanger, framed or on canvas, listed by the
 * extra and by options only some of the extras have.
 */
final class SplitCombinationCardsTest extends WP_UnitTestCase {

	use DependentPosters;

	private int $category;

	private WC_Product_Variable $poster;

	public function set_up(): void {
		parent::set_up();
		$this->set_up_dependent_posters();
		$category = wp_insert_term( 'Posters', 'product_cat' );
		$this->assertIsArray( $category );
		$this->category = (int) $category['term_id'];
		$this->poster   = $this->dependent_poster( array( $this->category ) );
		foreach ( array( 'hangare-ek', 'hangare-svart' ) as $with_hanger ) {
			$this->poster_variations[ $with_hanger ]->set_regular_price( 'hangare-ek' === $with_hanger ? '15' : '17' );
			$this->poster_variations[ $with_hanger ]->save();
		}
	}

	public function tear_down(): void {
		$this->tear_down_dependent_posters();
		$_GET = array();
		$this->forget_chosen_filters();
		parent::tear_down();
	}

	public function test_a_product_listed_by_two_attributes_has_a_card_per_combination_a_variation_sells(): void {
		$this->list_by( 'Inramning', 'Hangarfarg' );

		$this->assertSame(
			array(
				$this->key( array( 'Inramning' => 'canvas' ) ),
				$this->key(
					array(
						'Inramning'  => 'hangare',
						'Hangarfarg' => 'ek',
					)
				),
				$this->key(
					array(
						'Inramning'  => 'hangare',
						'Hangarfarg' => 'svart',
					)
				),
				$this->key( array( 'Inramning' => 'ingen' ) ),
				$this->key( array( 'Inramning' => 'ram' ) ),
			),
			$this->stored_keys(),
			'in the order WooCommerce lists each attribute\'s values; no card for a bare poster with a hanger colour, nor for a hanger without one'
		);
	}

	public function test_each_card_opens_the_product_on_its_combination_and_shows_its_variations_price(): void {
		$this->list_by( 'Inramning', 'Hangarfarg' );
		$permalink = (string) get_permalink( $this->poster->get_id() );

		$listed = $this->shop();

		$this->assertSame( 5, $listed['found'] );
		$this->assertSame(
			array(
				add_query_arg( self::poster_field( 'Inramning' ), 'canvas', $permalink ),
				add_query_arg(
					array(
						self::poster_field( 'Inramning' )  => 'hangare',
						self::poster_field( 'Hangarfarg' ) => 'ek',
					),
					$permalink
				),
				add_query_arg(
					array(
						self::poster_field( 'Inramning' )  => 'hangare',
						self::poster_field( 'Hangarfarg' ) => 'svart',
					),
					$permalink
				),
				add_query_arg( self::poster_field( 'Inramning' ), 'ingen', $permalink ),
				add_query_arg( self::poster_field( 'Inramning' ), 'ram', $permalink ),
			),
			$listed['links']
		);
		$this->assertStringContainsString( '15.00', $listed['prices'][1] );
		$this->assertStringNotContainsString( '17.00', $listed['prices'][1], 'only the oak hanger is in its card' );
		$this->assertStringContainsString( '17.00', $listed['prices'][2] );
	}

	public function test_an_address_naming_a_combination_is_its_cards_page(): void {
		$this->list_by( 'Inramning', 'Hangarfarg' );
		$poster = $this->fresh();
		$oak    = array(
			'Inramning'  => 'hangare',
			'Hangarfarg' => 'ek',
		);
		$named  = array(
			wc_attribute_taxonomy_name( 'Inramning' )  => 'hangare',
			wc_attribute_taxonomy_name( 'Hangarfarg' ) => 'ek',
		);

		$this->assertSame( $this->key( $oak ), CardPages::named_by( $poster, $named ) );
		$this->assertSame( $this->key( $oak ), CardPages::named_by( $poster, $named + array( wc_attribute_taxonomy_name( 'Canvasdjup' ) => '2-cm' ) ), 'whatever it chooses of an attribute the product is not listed by' );
		$this->assertNull( CardPages::named_by( $poster, array( wc_attribute_taxonomy_name( 'Inramning' ) => 'hangare' ) ), 'a hanger without its colour is no card' );
		$this->assertSame( $this->key( array( 'Inramning' => 'ingen' ) ), CardPages::named_by( $poster, array( wc_attribute_taxonomy_name( 'Inramning' ) => 'ingen' ) ) );
		$this->assertSame(
			$this->key( array( 'Inramning' => 'ram' ) ),
			CardPages::named_by(
				$poster,
				array(
					wc_attribute_taxonomy_name( 'Inramning' )  => 'ram',
					wc_attribute_taxonomy_name( 'Hangarfarg' ) => 'ek',
				)
			),
			'a hanger colour the form holds for a framed poster, which has none, is a choice on top of the card'
		);
		$this->assertSame( 'Poster – Hängare, Ek', CardPages::title( $poster, $this->key( $oak ) ) );
		$this->assertSame(
			add_query_arg(
				array(
					self::poster_field( 'Inramning' )  => 'hangare',
					self::poster_field( 'Hangarfarg' ) => 'ek',
				),
				'https://example.org/poster/'
			),
			CardPages::url( $poster, $this->key( $oak ), 'https://example.org/poster/' )
		);
	}

	public function test_the_variations_a_listed_attribute_does_not_apply_to_share_a_card_showing_the_product(): void {
		$this->list_by( 'Hangarfarg' );

		$this->assertSame(
			array(
				SplitCards::NO_VALUE,
				$this->key( array( 'Hangarfarg' => 'ek' ) ),
				$this->key( array( 'Hangarfarg' => 'svart' ) ),
			),
			$this->stored_keys()
		);
		$listed = $this->shop();
		$this->assertSame( 3, $listed['found'] );
		$this->assertSame( (string) get_permalink( $this->poster->get_id() ), $listed['links'][0], 'bare, framed and on canvas: nothing to choose for it' );
		$this->assertSame(
			array( $this->poster_variations['hangare-ek']->get_id() ),
			SplitCards::card_variations( $this->fresh(), $this->key( array( 'Hangarfarg' => 'ek' ) ) ),
			'a variation without a hanger is in no hanger colour\'s card'
		);
		$this->assertCount( 5, SplitCards::card_variations( $this->fresh(), SplitCards::NO_VALUE ) );
	}

	public function test_an_attribute_no_variation_sets_still_counts_for_each_of_its_values(): void {
		foreach ( array( 'ram-svart', 'ram-vit' ) as $framed ) {
			$attributes = $this->poster_variations[ $framed ]->get_attributes();
			unset( $attributes[ wc_attribute_taxonomy_name( 'Ramfarg' ) ] );
			$this->poster_variations[ $framed ]->set_attributes( $attributes );
			$this->poster_variations[ $framed ]->save();
		}
		$this->list_by( 'Ramfarg' );

		$this->assertSame(
			array(
				$this->key( array( 'Ramfarg' => 'svart' ) ),
				$this->key( array( 'Ramfarg' => 'vit' ) ),
			),
			$this->stored_keys(),
			'every variation sells any frame colour'
		);
		$this->assertCount( 7, SplitCards::card_variations( $this->fresh(), $this->key( array( 'Ramfarg' => 'vit' ) ) ) );
	}

	public function test_a_filter_keeps_the_cards_holding_a_chosen_value(): void {
		$this->list_by( 'Inramning', 'Hangarfarg' );

		$oak = $this->shop( array( 'filter_hangarfarg' => 'ek' ) );
		$this->assertSame( 1, $oak['found'], 'the bare poster\'s card holds no hanger colour' );
		$this->assertStringContainsString( self::poster_field( 'Hangarfarg' ) . '=ek', $oak['links'][0] );

		$hanger = $this->shop( array( 'filter_inramning' => 'hangare' ) );
		$this->assertSame( 2, $hanger['found'] );

		$framed = $this->shop( array( 'filter_ramfarg' => 'svart' ) );
		$this->assertSame( 1, $framed['found'], 'a filter by an attribute the product is not listed by keeps the cards selling a matching variation: only the frame has a frame colour' );
	}

	public function test_a_combinations_card_takes_a_title_of_its_own(): void {
		$this->list_by( 'Inramning', 'Hangarfarg' );
		$oak = $this->key(
			array(
				'Inramning'  => 'hangare',
				'Hangarfarg' => 'ek',
			)
		);
		SplitCardLooks::save(
			$this->poster->get_id(),
			array(
				$oak                 => array( 'title' => 'Poster on an oak hanger' ),
				SplitCards::NO_VALUE => array( 'title' => 'Never shown' ),
				'12,x'               => array( 'title' => 'Not a card' ),
			)
		);

		$this->assertSame( array( $oak ), array_map( 'strval', array_keys( SplitCardLooks::stored_on( $this->poster->get_id() ) ) ) );
		$this->assertSame( array( 'Poster', 'Poster on an oak hanger', 'Poster', 'Poster', 'Poster' ), $this->shop()['titles'] );
	}

	public function test_the_attributes_tab_lists_each_card_once_on_the_row_of_its_last_value(): void {
		$this->list_by( 'Inramning', 'Hangarfarg' );
		$user = self::factory()->user->create( array( 'role' => 'administrator' ) );
		$this->assertIsInt( $user );
		wp_set_current_user( $user );
		$fields                    = new SplitListingFields( Templates::for_plugin( dirname( __DIR__, 4 ) . '/pixonaut-for-woocommerce.php' ) );
		$GLOBALS['product_object'] = $this->fresh();
		$attributes                = $this->fresh()->get_attributes();
		$row                       = static function ( string $name, int $index ) use ( $fields, $attributes ): string {
			ob_start();
			$fields->render_row( $attributes[ wc_attribute_taxonomy_name( $name ) ], $index );
			return (string) ob_get_clean();
		};
		$oak                       = $this->key(
			array(
				'Inramning'  => 'hangare',
				'Hangarfarg' => 'ek',
			)
		);

		$framing = $row( 'Inramning', 0 );
		$colour  = $row( 'Hangarfarg', 1 );
		$frame   = $row( 'Ramfarg', 2 );
		unset( $GLOBALS['product_object'] );

		$this->assertStringContainsString( 'Title for “Ingen”', $framing );
		$this->assertStringContainsString( 'Title for “Canvas”', $framing );
		$this->assertStringNotContainsString( 'Title for “Hängare”', $framing, 'every card with a hanger goes on with its colour' );
		$this->assertStringContainsString( 'Title for “Hängare, Ek”', $colour );
		$this->assertStringContainsString( 'name="' . SplitListingFields::CARD_FIELD . '[' . $oak . '][title]"', $colour );
		$this->assertStringNotContainsString( 'Title for “Ek”', $colour );
		$this->assertStringContainsString( 'Title for “Svart”', $frame, 'an attribute the product is not listed by keeps a row per value' );
	}

	public function test_a_combinations_card_keeps_its_title_when_the_attributes_change_places(): void {
		$this->list_by( 'Inramning', 'Hangarfarg' );
		$oak = $this->key(
			array(
				'Inramning'  => 'hangare',
				'Hangarfarg' => 'ek',
			)
		);
		SplitCardLooks::save( $this->poster->get_id(), array( $oak => array( 'title' => 'Poster on an oak hanger' ) ) );
		$product    = $this->fresh();
		$attributes = $product->get_attributes();
		$colour     = $attributes['pa_hangarfarg'];
		$framing    = $attributes['pa_inramning'];
		$colour->set_position( 0 );
		$framing->set_position( 1 );
		$product->set_attributes( array( $colour, $framing, $attributes['pa_ramfarg'], $attributes['pa_canvasdjup'] ) );
		$product->save();

		$this->assertContains( $oak, $this->stored_keys() );
		$this->assertSame( array( $this->poster_term( 'Hangarfarg', 'ek' ), $this->poster_term( 'Inramning', 'hangare' ) ), SplitCards::card_terms( $this->fresh(), $oak ), 'in the order of the attributes now' );
		$this->assertContains( 'Poster on an oak hanger', $this->shop()['titles'] );
	}

	public function test_a_card_the_product_is_not_listed_by_now_keeps_its_title_through_a_save(): void {
		$this->list_by( 'Inramning', 'Hangarfarg' );
		$oak    = $this->key(
			array(
				'Inramning'  => 'hangare',
				'Hangarfarg' => 'ek',
			)
		);
		$canvas = $this->key( array( 'Inramning' => 'canvas' ) );
		$hanger = $this->key( array( 'Inramning' => 'hangare' ) );
		SplitCardLooks::save(
			$this->poster->get_id(),
			array(
				$oak    => array( 'title' => 'Oak' ),
				$hanger => array( 'title' => 'Any hanger' ),
			)
		);

		// As the rows of a product listed by both post: the hanger's own card is not drawn.
		SplitCardLooks::change(
			$this->poster->get_id(),
			array(
				$canvas => array( 'title' => 'Canvas' ),
				$oak    => array(
					'title' => '',
					'image' => '',
				),
			)
		);

		$this->assertEquals(
			array(
				$canvas => array(
					'title' => 'Canvas',
					'image' => 0,
				),
				$hanger => array(
					'title' => 'Any hanger',
					'image' => 0,
				),
			),
			SplitCardLooks::stored_on( $this->poster->get_id() ),
			'the card cleared loses its title, the one not posted keeps it'
		);
	}

	public function test_a_card_loses_its_title_once_the_product_loses_one_of_its_values(): void {
		$oak    = $this->key(
			array(
				'Inramning'  => 'hangare',
				'Hangarfarg' => 'ek',
			)
		);
		$canvas = $this->key( array( 'Inramning' => 'canvas' ) );
		SplitCardLooks::save(
			$this->poster->get_id(),
			array(
				$oak    => array( 'title' => 'Oak' ),
				$canvas => array( 'title' => 'Canvas' ),
			)
		);
		$product    = $this->fresh();
		$attributes = $product->get_attributes();
		unset( $attributes['pa_hangarfarg'] );
		$product->set_attributes( $attributes );
		$product->save();

		SplitCardLooks::forget_removed_values( $this->fresh() );

		$this->assertSame( array( $canvas ), array_map( 'strval', array_keys( SplitCardLooks::stored_on( $this->poster->get_id() ) ) ) );
	}

	public function test_a_product_with_more_combinations_than_the_shop_lists_is_listed_once(): void {
		// Every variation sells any hanger colour, frame colour and canvas depth: two cards each, eight in all.
		foreach ( $this->poster_variations as $variation ) {
			$variation->set_attributes( array( 'pa_inramning' => $variation->get_attributes()['pa_inramning'] ) );
			$variation->save();
		}
		$this->list_by( 'Hangarfarg', 'Ramfarg', 'Canvasdjup' );
		$this->assertCount( 8, $this->stored_keys() );

		$terms = array();
		for ( $value = 0; $value <= SplitCards::MAX_CARDS / 4; $value++ ) {
			$term = wp_insert_term( 'Depth ' . $value, 'pa_canvasdjup' );
			$this->assertIsArray( $term );
			$terms[] = (int) $term['term_id'];
		}
		$product    = $this->fresh();
		$attributes = $product->get_attributes();
		// A copy: the product takes the attribute it already holds as unchanged.
		$this->assertInstanceOf( WC_Product_Attribute::class, $attributes['pa_canvasdjup'] );
		$depth = clone $attributes['pa_canvasdjup'];
		$depth->set_options( array_merge( $depth->get_options(), $terms ) );
		$attributes['pa_canvasdjup'] = $depth;
		$product->set_attributes( $attributes );
		$product->save();

		$this->assertSame( array(), $this->stored_keys() );
		$this->assertSame( 1, $this->shop()['found'] );
	}

	public function test_a_deleted_value_takes_its_combinations_cards_with_it(): void {
		$this->list_by( 'Inramning', 'Hangarfarg' );
		$ek = $this->poster_term( 'Hangarfarg', 'ek' );

		wp_delete_term( $ek, 'pa_hangarfarg' );

		foreach ( $this->stored_keys() as $key ) {
			$this->assertNotContains( $ek, SplitCards::term_ids( $key ) );
		}
	}

	public function test_a_key_is_its_values_term_ids(): void {
		$this->assertSame( '7,12', SplitCards::key( array( 12, 7 ) ), 'lowest first, whatever the order of the attributes' );
		$this->assertSame( array( 7, 12 ), SplitCards::term_ids( '7,12' ) );
		$this->assertSame( SplitCards::NO_VALUE, SplitCards::key( array() ) );
		$this->assertSame( array(), SplitCards::term_ids( SplitCards::NO_VALUE ) );
		foreach ( array( '', '12,', ',12', '012', '1,0', '1 OR 1=1', null, 1.5 ) as $not_a_key ) {
			$this->assertFalse( SplitCards::is_key( $not_a_key ) );
		}
		$this->assertTrue( SplitCards::is_key( 12 ) );
	}

	/**
	 * Turns the listing by these attributes on for the poster.
	 *
	 * @param string ...$names Attribute names.
	 */
	private function list_by( string ...$names ): void {
		$choices = array();
		foreach ( $names as $name ) {
			$choices[ $this->poster_attributes[ $name ] ] = SplitListing::ON;
		}
		ProductConfig::stored_on( $this->fresh() )->with_split_listing( $choices )->save_to( $this->fresh() );
	}

	private function fresh(): WC_Product {
		$fresh = wc_get_product( $this->poster->get_id() );
		$this->assertInstanceOf( WC_Product::class, $fresh );
		return $fresh;
	}

	/**
	 * The key of the card of these values.
	 *
	 * @param array<string, string> $values Slugs by attribute name, in the product's order of attributes.
	 */
	private function key( array $values ): string {
		return SplitCards::key( array_map( fn ( string $name, string $slug ): int => $this->poster_term( $name, $slug ), array_keys( $values ), $values ) );
	}

	/**
	 * @return list<string>
	 */
	private function stored_keys(): array {
		return array_values( array_map( 'strval', get_post_meta( $this->poster->get_id(), SplitCards::META, false ) ) );
	}

	/**
	 * The shop's main query on the category page and its loop, as a theme runs them.
	 *
	 * @param array<string, string> $args Query parameters.
	 * @return array{links: list<string>, titles: list<string>, prices: list<string>, found: int}
	 */
	private function shop( array $args = array() ): array {
		$this->forget_chosen_filters();
		$_GET = $args;
		$page = get_term_link( $this->category, 'product_cat' );
		$this->assertIsString( $page );
		$this->go_to( add_query_arg( $args, $page ) );
		global $wp_query;
		$this->assertSame( 'product_query', $wp_query->get( 'wc_query' ), 'a listing of the shop\'s' );
		$links  = array();
		$titles = array();
		$prices = array();
		while ( $wp_query->have_posts() ) {
			$wp_query->the_post();
			$product  = wc_get_product( get_the_ID() );
			$links[]  = (string) get_permalink();
			$titles[] = get_the_title();
			$prices[] = $product instanceof WC_Product ? wp_strip_all_tags( $product->get_price_html() ) : '';
		}
		wp_reset_postdata();
		return array(
			'links'  => $links,
			'titles' => $titles,
			'prices' => $prices,
			'found'  => (int) $wp_query->found_posts,
		);
	}

	/**
	 * WooCommerce reads the chosen filters once a request.
	 */
	private function forget_chosen_filters(): void {
		$property = new \ReflectionProperty( WC_Query::class, 'chosen_attributes' );
		$property->setValue( null, null );
	}
}
