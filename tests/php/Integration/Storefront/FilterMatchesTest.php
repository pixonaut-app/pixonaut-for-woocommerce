<?php
/**
 * @package Pixonaut\WooCommerce
 */

declare(strict_types=1);

namespace Pixonaut\WooCommerce\Tests\Integration\Storefront;

use Pixonaut\WooCommerce\Catalogue\FilterIndex;
use Pixonaut\WooCommerce\Catalogue\SplitListing;
use Pixonaut\WooCommerce\Schema;
use Pixonaut\WooCommerce\Settings\Settings;
use Pixonaut\WooCommerce\Storefront\AttributeDependencies;
use Pixonaut\WooCommerce\Storefront\OpeningChoice;
use Pixonaut\WooCommerce\Storefront\QueryNames;
use Pixonaut\WooCommerce\Tests\Support\FreshRequest;
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

	/**
	 * Hanger or bare, in oak.
	 */
	private const HANGER_OR_BARE_IN_OAK = array(
		'filter_inramning' => 'hangare,ingen',
		'filter_ramfarg'   => 'ek',
	);

	private WC_Product $poster;

	private WC_Product $skog;

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
		$this->skog   = $this->product(
			'Skog',
			array(
				array( '30x40', 'ingen', '', '199' ),
				array( '50x70', 'ram', 'ek', '350' ),
			)
		);
	}

	public function tear_down(): void {
		FreshRequest::forget_filter_reads();
		$this->tear_down_posters();
		update_option( Settings::OPTION, array() );
		$_GET = array();
		$this->forget_chosen_filters();
		parent::tear_down();
	}

	public function test_a_bare_choice_with_a_frame_colour_lists_what_is_sold_bare(): void {
		$listed = $this->shop(
			array(
				'filter_inramning' => 'ingen',
				'filter_ramfarg'   => 'ek',
			)
		);

		$this->assertEqualsCanonicalizing( array( $this->poster->get_id(), $this->skog->get_id() ), $listed['ids'], 'the frame colour does not apply to what is sold bare' );
	}

	public function test_a_colour_without_a_framing_chosen_lists_only_what_comes_in_it(): void {
		$this->assertEqualsCanonicalizing( array( $this->poster->get_id(), $this->skog->get_id() ), $this->shop( array( 'filter_ramfarg' => 'ek' ) )['ids'], 'both are framed in oak; neither is bare in oak' );
		$this->assertSame(
			array(),
			$this->shop(
				array(
					'filter_ramfarg'   => 'ek',
					'filter_inramning' => 'hangare',
				)
			)['ids'],
			'the only hanger is black'
		);
	}

	public function test_hanger_and_bare_with_a_colour_list_every_bare_card_and_the_hangers_in_that_colour(): void {
		SplitListing::set_attribute_mode( $this->attributes[ self::PICTURE ], SplitListing::ON );
		$sea = $this->sea();

		$listed = $this->shop( self::HANGER_OR_BARE_IN_OAK );

		$this->assertSame( 4, $listed['found'], 'each product\'s bare card and the sea\'s oak hanger; the safari\'s hanger is black' );
		$this->assertEqualsCanonicalizing(
			array(
				array(
					'attribute_pa_inramning' => 'ingen',
					'attribute_pa_storlek'   => '30x40',
				),
				array(
					'attribute_pa_inramning' => 'hangare',
					'attribute_pa_storlek'   => '50x70',
					'attribute_pa_ramfarg'   => 'ek',
				),
			),
			array_map( array( $this, 'chosen' ), $listed['card_links'][ $sea->get_id() ] ?? array() ),
			'the bare card opens bare, the hanger in oak'
		);
		$this->assertCount( 1, $listed['card_links'][ $this->poster->get_id() ] ?? array(), 'the safari\'s bare card' );
		$this->assertCount( 1, $listed['card_links'][ $this->skog->get_id() ] ?? array(), 'the forest\'s bare card' );
	}

	public function test_values_of_one_attribute_combine_with_or_and_attributes_with_and(): void {
		SplitListing::set_attribute_mode( $this->attributes[ self::PICTURE ], SplitListing::ON );
		$bare_or_hanger_in_50x70 = array(
			'filter_inramning' => 'ingen,hangare',
			'filter_storlek'   => '50x70',
		);

		$this->assertSame( 2, $this->shop( $bare_or_hanger_in_50x70 )['found'], 'the safari\'s bare and hanger cards; the forest is bare only in 30x40' );
		$this->assertSame( 1, $this->shop( array_merge( $bare_or_hanger_in_50x70, array( 'max_price' => '400' ) ) )['found'], 'the hanger costs 450' );
		$this->assertSame(
			2,
			$this->shop(
				array(
					'filter_storlek' => '30x40,50x70',
					'max_price'      => '300',
				)
			)['found'],
			'each product\'s bare card, at 199 or 299'
		);
	}

	public function test_each_values_count_is_the_listing_with_that_value_chosen(): void {
		SplitListing::set_attribute_mode( $this->attributes[ self::PICTURE ], SplitListing::ON );
		$this->sea();

		$this->assert_counts_follow_the_listing( self::HANGER_OR_BARE_IN_OAK );
	}

	public function test_a_poster_never_sold_in_the_colour_keeps_its_bare_card(): void {
		SplitListing::set_attribute_mode( $this->attributes[ self::PICTURE ], SplitListing::ON );
		$fell   = $this->product(
			'Fjäll',
			array(
				array( '30x40', 'ingen', '', '199' ),
				array( '50x70', 'ram', 'svart', '350' ),
			),
			array( 'svart' )
		);
		$meadow = $this->product( 'Äng', array( array( '30x40', 'ingen', '', '199' ) ), null );

		foreach ( array( 'no', 'yes' ) as $lookup ) {
			update_option( 'woocommerce_attribute_lookup_enabled', $lookup );
			$listed = $this->shop( self::HANGER_OR_BARE_IN_OAK );
			$this->assertEqualsCanonicalizing( array( $this->poster->get_id(), $this->skog->get_id(), $fell->get_id(), $meadow->get_id() ), $listed['ids'], "lookup table {$lookup}: neither the fell nor the meadow holds oak; both are sold bare" );
			$this->assertSame( 4, $listed['found'], "lookup table {$lookup}: each bare card" );
			$this->assert_counts_follow_the_listing( self::HANGER_OR_BARE_IN_OAK );
		}
		delete_option( 'woocommerce_attribute_lookup_enabled' );
	}

	public function test_a_colour_every_variation_of_a_product_sets_is_not_waived_for_it(): void {
		$beach = $this->product(
			'Strand',
			array(
				array( '30x40', 'ingen', 'svart', '199' ),
				array( '30x40', 'ram', 'svart', '349' ),
			)
		);

		$listed = $this->shop(
			array(
				'filter_inramning' => 'ingen',
				'filter_ramfarg'   => 'ek',
			)
		);

		$this->assertNotContains( $beach->get_id(), $listed['ids'], 'the beach is bare in black, not in oak' );
		$this->assertEqualsCanonicalizing( array( $this->poster->get_id(), $this->skog->get_id() ), $listed['ids'] );
	}

	public function test_a_card_listed_by_the_colour_alone_keeps_the_bare_card(): void {
		SplitListing::set_attribute_mode( $this->attributes[ self::COLOUR ], SplitListing::ON );
		$bare_or_framed_in_oak = array(
			'filter_inramning' => 'ingen,ram',
			'filter_ramfarg'   => 'ek',
		);

		$this->assertSame( 4, $this->shop( $bare_or_framed_in_oak )['found'], 'each product\'s bare card, holding no colour, and its oak card' );
		$this->assertSame( 2, $this->shop( self::HANGER_OR_BARE_IN_OAK )['found'], 'each bare card; the oak cards are framed' );
		$this->assert_counts_follow_the_listing( $bare_or_framed_in_oak );
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
		SplitListing::set_attribute_mode( $this->attributes[ self::PICTURE ], SplitListing::ON );

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
		$this->assertSame( 2, $framing[ $this->term( 'ingen' ) ] ?? null, 'bare chosen beside oak keeps the bare cards: the colour does not apply to them' );
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

	public function test_an_attribute_listed_while_filtered_counts_its_cards_only_while_chosen(): void {
		SplitListing::set_attribute_mode( $this->attributes[ self::PICTURE ], SplitListing::FILTERED );

		$this->assertSame(
			array(
				$this->term( '30x40' ) => 2,
				$this->term( '50x70' ) => 2,
			),
			$this->counts( 'pa_storlek', array() ),
			'each product once while no framing is chosen'
		);
		$this->assertSame( 2, $this->shop( array() )['found'] );
		$this->assertSame(
			array(
				$this->term( 'ingen' )   => 2,
				$this->term( 'ram' )     => 2,
				$this->term( 'hangare' ) => 1,
			),
			$this->counts( 'pa_inramning', array() ),
			'a framing chosen shows each product\'s card of it'
		);
		$this->assertSame( 2, $this->shop( array( 'filter_inramning' => 'ram' ) )['found'] );
		$this->assertSame(
			array(
				$this->term( '30x40' ) => 1,
				$this->term( '50x70' ) => 2,
			),
			$this->counts( 'pa_storlek', array( 'filter_inramning' => 'ram' ) )
		);
	}

	public function test_several_values_of_an_attribute_listed_while_filtered_list_and_count_each_card_once(): void {
		SplitListing::set_attribute_mode( $this->attributes[ self::PICTURE ], SplitListing::FILTERED );
		$every_framing = array( 'filter_inramning' => 'ingen,ram,hangare' );

		$listed = $this->shop( $every_framing );
		$links  = array_merge( ...array_values( $listed['card_links'] ) );
		$this->assertSame( 5, $listed['found'], 'the safari\'s bare, framed and hanger cards, and the forest\'s bare and framed ones' );
		$this->assertCount( $listed['found'], array_unique( $links ), 'each card once, though the product holds every chosen value' );
		foreach ( $links as $link ) {
			wp_parse_str( (string) wp_parse_url( $link, PHP_URL_QUERY ), $chosen );
			$this->assertContains( $chosen['inramning'] ?? null, array( 'ingen', 'ram', 'hangare' ), "{$link} opens on one framing" );
		}
		$this->assert_counts_follow_the_listing( $every_framing );
		$this->assert_counts_follow_the_listing( $every_framing + array( 'filter_storlek' => '50x70' ) );
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

	public function test_a_card_opens_on_the_size_and_a_price_the_filters_chose(): void {
		$this->set_defaults( array( 'pa_storlek' => '50x70' ) );
		$filters = array(
			'filter_storlek' => '30x40',
			'max_price'      => self::PRICE_HIGH,
		);

		$link = $this->shop( $filters )['links'][ $this->poster->get_id() ] ?? '';

		$this->assertSame(
			array(
				'attribute_pa_storlek'   => '30x40',
				'attribute_pa_inramning' => 'ingen',
			),
			$this->chosen( $link ),
			'the cheapest 30x40 in range; the default 50x70 costs 299 bare, but is not the size chosen'
		);
		$this->assertSame( '199', $this->opened_price( $link ), 'the page opens on that variation' );
	}

	public function test_of_several_matching_variations_the_defaults_choose(): void {
		$this->set_defaults(
			array(
				'pa_inramning' => 'ram',
				'pa_ramfarg'   => 'svart',
			)
		);

		$link = $this->shop(
			array(
				'filter_storlek' => '30x40',
				'max_price'      => self::PRICE_HIGH,
			)
		)['links'][ $this->poster->get_id() ] ?? '';

		$this->assertSame(
			array(
				'attribute_pa_storlek'   => '30x40',
				'attribute_pa_inramning' => 'ram',
				'attribute_pa_ramfarg'   => 'svart',
			),
			$this->chosen( $link ),
			'framed in black, as the defaults, over the cheaper bare one'
		);
		$this->assertSame( '349', $this->opened_price( $link ) );
	}

	public function test_a_frame_and_colour_filter_opens_on_a_framed_oak_variation(): void {
		$filters = array(
			'filter_inramning' => 'ram',
			'filter_ramfarg'   => 'ek',
		);

		$this->assertSame(
			array(
				'attribute_pa_storlek'   => '30x40',
				'attribute_pa_inramning' => 'ram',
				'attribute_pa_ramfarg'   => 'ek',
			),
			$this->chosen( $this->shop( $filters )['links'][ $this->poster->get_id() ] ?? '' ),
			'the cheaper size without a default'
		);

		$this->set_defaults( array( 'pa_storlek' => '50x70' ) );
		$link = $this->shop( $filters )['links'][ $this->poster->get_id() ] ?? '';
		$this->assertSame(
			array(
				'attribute_pa_storlek'   => '50x70',
				'attribute_pa_inramning' => 'ram',
				'attribute_pa_ramfarg'   => 'ek',
			),
			$this->chosen( $link ),
			'the default size'
		);
		$this->assertSame( '499', $this->opened_price( $link ) );
	}

	public function test_each_card_opens_on_its_own_matching_variation(): void {
		SplitListing::set_attribute_mode( $this->attributes[ self::PICTURE ], SplitListing::ON );

		$links = $this->shop(
			array(
				'filter_storlek' => '30x40',
				'max_price'      => self::PRICE_HIGH,
			)
		)['card_links'];

		$this->assertSame(
			array(
				array(
					'attribute_pa_inramning' => 'ingen',
					'attribute_pa_storlek'   => '30x40',
				),
				array(
					'attribute_pa_inramning' => 'ram',
					'attribute_pa_storlek'   => '30x40',
					'attribute_pa_ramfarg'   => 'ek',
				),
			),
			array_map( array( $this, 'chosen' ), $links[ $this->poster->get_id() ] ?? array() ),
			'the bare card and the framed one, the card\'s value first; the hanger is 50x70 only'
		);
	}

	public function test_a_variation_is_chosen_only_when_its_link_opens_on_one_matching_the_filters(): void {
		$sea = $this->product(
			'Hav',
			array(
				array( '', 'ingen', '', '499' ),
				array( '30x40', 'ingen', '', '199' ),
				array( '30x40', 'ram', 'ek', '349' ),
			)
		);

		$link = $this->shop(
			array(
				'filter_storlek' => '30x40',
				'max_price'      => self::PRICE_HIGH,
			)
		)['links'][ $sea->get_id() ] ?? '';

		$this->assertSame(
			array(
				'attribute_pa_storlek'   => '30x40',
				'attribute_pa_inramning' => 'ram',
				'attribute_pa_ramfarg'   => 'ek',
			),
			$this->chosen( $link ),
			'bare in 30x40 opens on the bare one in any size, at 499'
		);
		$this->assertSame( '349', $this->opened_price( $link, $sea ) );
	}

	public function test_a_value_a_filter_overrides_does_not_count_as_agreeing_with_the_defaults(): void {
		$sea = $this->product(
			'Hav',
			array(
				array( '', 'ingen', '', '199' ),
				array( '30x40', 'ram', 'ek', '349' ),
			)
		);
		$sea->set_default_attributes(
			array(
				'pa_storlek'   => '50x70',
				'pa_inramning' => 'ram',
			)
		);
		$sea->save();

		$this->assertSame(
			array(
				'attribute_pa_storlek'   => '30x40',
				'attribute_pa_inramning' => 'ram',
				'attribute_pa_ramfarg'   => 'ek',
			),
			$this->chosen( $this->shop( array( 'filter_storlek' => '30x40' ) )['links'][ $sea->get_id() ] ?? '' ),
			'the bare one in any size opens in 30x40, not the default 50x70: the frame agrees with a default'
		);
	}

	public function test_without_a_filter_a_card_links_to_the_product_itself(): void {
		$this->set_defaults( array( 'pa_storlek' => '50x70' ) );

		$this->assertSame( (string) get_permalink( $this->poster->get_id() ), $this->shop( array() )['links'][ $this->poster->get_id() ] ?? '' );
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

	public function test_a_listing_takes_what_was_kept_of_a_product_until_woocommerce_saves_a_change(): void {
		$filters = array(
			'filter_storlek' => '30x40',
			'max_price'      => self::PRICE_HIGH,
		);
		$this->settle();
		$this->assertContains( $this->poster->get_id(), $this->shop( $filters )['ids'] );
		FilterIndex::keep();
		$this->assertIsArray( get_transient( Schema::FILTER_INDEX_PREFIX . $this->poster->get_id() ), 'kept for the next request' );

		$sizes = $this->variations_in( $this->poster, '30x40' );
		$this->price_unseen( array_slice( $sizes, 1 ), '999' );
		FreshRequest::forget_filter_reads();
		$this->assertContains( $this->poster->get_id(), $this->shop( $filters )['ids'], 'read as kept: nothing WooCommerce saved changed' );

		$saved = wc_get_product( $sizes[0] );
		$this->assertInstanceOf( WC_Product_Variation::class, $saved );
		$saved->set_regular_price( '999' );
		$saved->save();
		FreshRequest::forget_filter_reads();
		$this->assertNotContains( $this->poster->get_id(), $this->shop( $filters )['ids'], 'read again once a variation is saved: no 30x40 within the range' );
	}

	public function test_a_product_changed_moments_ago_is_not_kept(): void {
		$this->shop( array( 'filter_storlek' => '30x40' ) );
		FilterIndex::keep();

		$this->assertFalse( get_transient( Schema::FILTER_INDEX_PREFIX . $this->poster->get_id() ), 'a change within the same second would not change its stamp' );
	}

	public function test_renaming_an_attributes_value_drops_what_was_kept(): void {
		$filters = array(
			'filter_storlek' => '30x40',
			'max_price'      => self::PRICE_HIGH,
		);
		$this->settle();
		$this->shop( $filters );
		FilterIndex::keep();
		$this->price_unseen( $this->variations_in( $this->poster, '30x40' ), '999' );

		wp_update_term( $this->term( '50x70' ), 'pa_storlek', array( 'name' => '50 x 70' ) );
		FreshRequest::forget_filter_reads();

		$this->assertNotContains( $this->poster->get_id(), $this->shop( $filters )['ids'] );
	}

	public function test_a_filter_on_whether_a_variation_is_offered_is_read_on_each_request(): void {
		$this->set_defaults( array( 'pa_storlek' => '50x70' ) );
		$filters = array(
			'filter_storlek' => '30x40',
			'max_price'      => self::PRICE_HIGH,
		);
		$this->settle();
		$this->shop( $filters );
		FilterIndex::keep();
		$hide = $this->hiding( $this->variations_in( $this->poster, '30x40' )[0] );
		add_filter( 'woocommerce_variation_is_visible', $hide, 10, 2 );
		FreshRequest::forget_filter_reads();

		$link = $this->shop( $filters )['links'][ $this->poster->get_id() ] ?? '';
		remove_filter( 'woocommerce_variation_is_visible', $hide, 10 );

		$this->assertSame(
			array(
				'attribute_pa_storlek'   => '30x40',
				'attribute_pa_inramning' => 'ram',
				'attribute_pa_ramfarg'   => 'ek',
			),
			$this->chosen( $link ),
			'the bare 30x40 is not offered now, though it was when its offers were kept'
		);
	}

	public function test_offers_read_under_a_filter_since_removed_are_not_kept(): void {
		$this->set_defaults( array( 'pa_storlek' => '50x70' ) );
		$filters = array(
			'filter_storlek' => '30x40',
			'max_price'      => self::PRICE_HIGH,
		);
		$this->settle();
		$hide = $this->hiding( $this->variations_in( $this->poster, '30x40' )[0] );
		add_filter( 'woocommerce_variation_is_visible', $hide, 10, 2 );
		$this->shop( $filters );
		remove_filter( 'woocommerce_variation_is_visible', $hide, 10 );
		FilterIndex::keep();
		FreshRequest::forget_filter_reads();

		$this->assertSame( 'ingen', $this->chosen( $this->shop( $filters )['links'][ $this->poster->get_id() ] ?? '' )['attribute_pa_inramning'] ?? '', 'the bare 30x40 is offered again' );
	}

	public function test_a_filter_added_after_a_product_was_looked_up_still_counts(): void {
		$this->set_defaults( array( 'pa_storlek' => '50x70' ) );
		$filters = array(
			'filter_storlek' => '30x40',
			'max_price'      => self::PRICE_HIGH,
		);
		$this->settle();
		$this->shop( $filters );
		FilterIndex::keep();
		FreshRequest::forget_filter_reads();
		FilterIndex::look_up( array( $this->poster->get_id() ) );
		$hide = $this->hiding( $this->variations_in( $this->poster, '30x40' )[0] );
		add_filter( 'woocommerce_variation_is_visible', $hide, 10, 2 );

		$link = $this->shop( $filters )['links'][ $this->poster->get_id() ] ?? '';
		remove_filter( 'woocommerce_variation_is_visible', $hide, 10 );

		$this->assertSame( 'ram', $this->chosen( $link )['attribute_pa_inramning'] ?? '' );
	}

	public function test_nothing_is_kept_from_a_request_that_switched_sites_or_had_a_database_error(): void {
		$this->settle();
		$this->shop( array( 'filter_storlek' => '30x40' ) );
		do_action( 'switch_blog', 1, 1, 'switch' );
		FilterIndex::keep();
		$this->assertFalse( get_transient( Schema::FILTER_INDEX_PREFIX . $this->poster->get_id() ), 'another site\'s products share its ids' );

		FreshRequest::forget_filter_reads();
		$this->shop( array( 'filter_storlek' => '30x40' ) );
		global $EZSQL_ERROR;
		$errors = $EZSQL_ERROR;

		// phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- a failed query, as wpdb records it.
		$EZSQL_ERROR[] = array(
			'query'     => 'SELECT 1',
			'error_str' => 'Lost connection',
		);
		FilterIndex::keep();
		$EZSQL_ERROR = $errors; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- restores what the test added.
		$this->assertFalse( get_transient( Schema::FILTER_INDEX_PREFIX . $this->poster->get_id() ), 'a failed read may have found no variations' );
	}

	/**
	 * A third product sold bare, and with a hanger in oak or black.
	 */
	private function sea(): WC_Product {
		return $this->product(
			'Hav',
			array(
				array( '30x40', 'ingen', '', '199' ),
				array( '50x70', 'hangare', 'ek', '450' ),
				array( '50x70', 'hangare', 'svart', '450' ),
			)
		);
	}

	/**
	 * A visibility filter hiding one variation.
	 *
	 * @param int $hidden The variation.
	 * @return \Closure(mixed, mixed): mixed
	 */
	private function hiding( int $hidden ): \Closure {
		return static fn ( mixed $visible, mixed $variation_id ): mixed => $hidden === $variation_id ? false : $visible;
	}

	/**
	 * Dates every product and variation's last change well back, as a
	 * catalogue left alone since.
	 */
	private function settle(): void {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- the test's own posts, dated back.
		$wpdb->query( "UPDATE {$wpdb->posts} SET post_modified = '2020-01-01 00:00:00', post_modified_gmt = '2020-01-01 00:00:00' WHERE post_type IN ( 'product', 'product_variation' )" );
	}

	/**
	 * Writes variations' prices past WooCommerce, which changes nothing a
	 * kept read's stamp sees.
	 *
	 * @param int[]  $variation_ids Variations.
	 * @param string $price         Their new price.
	 * @phpstan-param list<int> $variation_ids
	 */
	private function price_unseen( array $variation_ids, string $price ): void {
		foreach ( $variation_ids as $id ) {
			foreach ( array( '_price', '_regular_price' ) as $key ) {
				update_post_meta( $id, $key, $price );
			}
			clean_post_cache( $id );
		}
	}

	/**
	 * A product's variations of one size, in its order of variations.
	 *
	 * @param WC_Product $product A product.
	 * @param string     $size    The size's slug.
	 * @return list<int>
	 */
	private function variations_in( WC_Product $product, string $size ): array {
		$ids = array();
		foreach ( $product->get_children() as $id ) {
			if ( get_post_meta( $id, 'attribute_pa_storlek', true ) === $size ) {
				$ids[] = (int) $id;
			}
		}
		return $ids;
	}

	/**
	 * Each value's count of the three attributes is the listing's card
	 * count with that value added to the filters.
	 *
	 * @param array<string, string> $filters Query parameters.
	 */
	private function assert_counts_follow_the_listing( array $filters ): void {
		foreach ( array( 'inramning', 'ramfarg', 'storlek' ) as $attribute ) {
			$counts = $this->counts( 'pa_' . $attribute, $filters );
			$terms  = get_terms(
				array(
					'taxonomy'   => 'pa_' . $attribute,
					'hide_empty' => false,
				)
			);
			$this->assertIsArray( $terms );
			foreach ( $terms as $term ) {
				$this->assertInstanceOf( \WP_Term::class, $term );
				$with            = $filters;
				$filter          = 'filter_' . $attribute;
				$with[ $filter ] = isset( $with[ $filter ] ) ? $with[ $filter ] . ',' . $term->slug : $term->slug;
				$this->assertSame( $this->shop( $with )['found'], $counts[ $term->term_id ] ?? 0, "{$attribute} {$term->slug}" );
			}
		}
	}

	/**
	 * A variable product in the category with the three attributes.
	 *
	 * @param string                                                  $name       Its name.
	 * @param list<array{0: string, 1: string, 2: string, 3: string}> $variations Size, framing, colour and price of each.
	 * @param list<string>|false|null                                 $colours    The colours it lists, by slug; every colour when false, no colour attribute when null.
	 */
	private function product( string $name, array $variations, array|false|null $colours = false ): WC_Product {
		$product = new WC_Product_Variable();
		$product->set_name( $name );
		$product->set_status( 'publish' );
		$product->set_category_ids( array( $this->category ) );
		$attributes = array( $this->product_attribute( self::SIZE ), $this->product_attribute( self::PICTURE ) );
		if ( null !== $colours ) {
			$colour = $this->product_attribute( self::COLOUR );
			if ( false !== $colours ) {
				$colour->set_options( array_map( array( $this, 'term' ), $colours ) );
			}
			$attributes[] = $colour;
		}
		$product->set_attributes( $attributes );
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
	 * @return array{ids: list<int>, found: int, links: array<int, string>, card_links: array<int, list<string>>} The links by product id: the first card's, and every card's.
	 */
	private function shop( array $args ): array {
		$this->go_to_listing( $args );
		global $wp_query;
		$ids   = array();
		$links = array();
		while ( $wp_query->have_posts() ) {
			$wp_query->the_post();
			$id             = (int) get_the_ID();
			$ids[]          = $id;
			$links[ $id ][] = (string) get_permalink();
		}
		wp_reset_postdata();
		return array(
			'ids'        => array_values( array_unique( $ids ) ),
			'found'      => (int) $wp_query->found_posts,
			'links'      => array_map( static fn ( array $each ): string => $each[0], $links ),
			'card_links' => $links,
		);
	}

	/**
	 * The poster's stored default attributes.
	 *
	 * @param array<string, string> $defaults Slugs by taxonomy.
	 */
	private function set_defaults( array $defaults ): void {
		$this->poster->set_default_attributes( $defaults );
		$this->poster->save();
	}

	/**
	 * The values a link chooses, by `attribute_*` field, in its order: a
	 * global attribute goes by its short name in it.
	 *
	 * @param string $link A card's link.
	 * @return array<string, string>
	 */
	private function chosen( string $link ): array {
		parse_str( (string) wp_parse_url( $link, PHP_URL_QUERY ), $query );
		$fields = array();
		foreach ( QueryNames::names() as $slug => $name ) {
			$fields[ $name ] = 'attribute_pa_' . $slug;
		}
		$chosen = array();
		foreach ( $query as $name => $value ) {
			$field = $fields[ $name ] ?? $name;
			if ( is_string( $field ) && str_starts_with( $field, 'attribute_' ) && is_string( $value ) ) {
				$chosen[ $field ] = $value;
			}
		}
		return $chosen;
	}

	/**
	 * The price of the variation a product's page opens on at a link.
	 *
	 * @param string          $link    A card's link to the product.
	 * @param WC_Product|null $product The product; the poster when null.
	 */
	private function opened_price( string $link, ?WC_Product $product = null ): string {
		$product ??= $this->poster;
		$this->forget_chosen_filters();
		$_GET = array();
		foreach ( $this->chosen( $link ) as $field => $value ) {
			$_REQUEST[ $field ] = $value;
		}
		$this->go_to( $link );
		$variations = array();
		foreach ( $product->get_children() as $id ) {
			$variations[ $id ] = wc_get_product( $id );
		}
		$opened = OpeningChoice::variation_among( $product, array_filter( $variations ) );
		foreach ( array_keys( $this->chosen( $link ) ) as $field ) {
			unset( $_REQUEST[ $field ] );
		}
		$variation = wc_get_product( $opened );
		$this->assertInstanceOf( WC_Product_Variation::class, $variation, 'the page opens on a variation' );
		return (string) $variation->get_price();
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
