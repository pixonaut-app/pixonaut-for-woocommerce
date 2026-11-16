<?php
/**
 * @package Pixonaut\WooCommerce
 */

declare(strict_types=1);

namespace Pixonaut\WooCommerce\Tests\Integration\Storefront;

// phpcs:disable WordPress.Security.NonceVerification, WordPress.Security.ValidatedSanitizedInput -- reads the request as the code under test leaves it.

use Pixonaut\WooCommerce\Catalogue\CardPages;
use Pixonaut\WooCommerce\Catalogue\SplitListing;
use Pixonaut\WooCommerce\Storefront\CardPageHead;
use Pixonaut\WooCommerce\Storefront\CardSitemapProvider;
use Pixonaut\WooCommerce\Storefront\OpeningChoice;
use Pixonaut\WooCommerce\Storefront\QueryNames;
use Pixonaut\WooCommerce\Storefront\ShortAddresses;
use Pixonaut\WooCommerce\Tests\Support\SplitPosters;
use WC_Query;
use WP_UnitTestCase;

final class ShortAddressesTest extends WP_UnitTestCase {

	use SplitPosters;

	private const PICTURE = 'Bild';

	private const SIZE = 'Storlek';

	/**
	 * The names a request may carry, cleared between requests.
	 */
	private const ARGS = array( 'bild', 'storlek', 'motiv', 'attribute_pa_bild', 'attribute_pa_storlek', 'filter_bild', 'filter_storlek', 'query_type_bild', 'query_type_storlek' );

	public function set_up(): void {
		parent::set_up();
		$this->set_up_posters();
	}

	public function tear_down(): void {
		$this->tear_down_posters();
		$this->forget_request();
		parent::tear_down();
	}

	public function test_each_attribute_goes_by_its_slug_or_the_name_the_store_gave_it(): void {
		$this->assertSame(
			array(
				'bild'    => 'bild',
				'storlek' => 'storlek',
			),
			QueryNames::names()
		);

		$this->assertNull( QueryNames::set( $this->attributes[ self::PICTURE ], 'motiv' ) );
		$this->assertSame( 'motiv', QueryNames::of( $this->attributes[ self::PICTURE ] ) );
		$this->assertSame( 'motiv', QueryNames::own( $this->attributes[ self::PICTURE ] ) );
		$this->assertSame( 'bild', QueryNames::default_name( $this->attributes[ self::PICTURE ] ) );

		$this->assertNull( QueryNames::set( $this->attributes[ self::PICTURE ], 'bild' ), 'its own slug' );
		$this->assertSame( '', QueryNames::own( $this->attributes[ self::PICTURE ] ), 'gives it back its slug' );
		$this->assertFalse( get_option( QueryNames::OPTION ) );

		QueryNames::set( $this->attributes[ self::PICTURE ], 'motiv' );
		wc_delete_attribute( $this->attributes[ self::PICTURE ] );
		$this->assertFalse( get_option( QueryNames::OPTION ), 'a deleted attribute is forgotten' );
	}

	public function test_a_name_wordpress_woocommerce_or_another_attribute_reads_is_refused(): void {
		$picture = $this->attributes[ self::PICTURE ];
		foreach ( array( 'orderby', 'min_price', 'add-to-cart', 'paged', 's', 'p', 'page_id', 'product', 'product_cat', 'filter_bild', 'attribute_pa_bild', 'query_type_bild', 'pa_bild', 'categories', 'utm_source', 'pixonaut_edit' ) as $reserved ) {
			$this->assertNotNull( QueryNames::refusal( $reserved, $picture ), $reserved );
		}
		foreach ( array( 'Big', 'two words', 'a--b', '-a', str_repeat( 'a', QueryNames::MAX_LENGTH + 1 ), 'ö' ) as $malformed ) {
			$this->assertNotNull( QueryNames::refusal( $malformed, $picture ), $malformed );
		}
		$this->assertStringContainsString( 'Storlek', (string) QueryNames::refusal( 'storlek', $picture ), 'names the attribute that has it' );
		$this->assertNotNull( QueryNames::set( $picture, 'storlek' ), 'refused' );
		$this->assertSame( '', QueryNames::own( $picture ), 'and left as it was' );

		$this->assertNull( QueryNames::set( $this->attributes[ self::SIZE ], 'size' ) );
		$this->assertNull( QueryNames::refusal( 'storlek', $picture ), 'free once the other goes by another name' );
		$this->assertNull( QueryNames::refusal( 'ram-farg_2', $picture ) );
		$this->assertNull( QueryNames::refusal( '', $picture ), 'empty gives it back its slug' );
	}

	public function test_an_attribute_whose_slug_is_taken_keeps_woocommerces_names(): void {
		$this->assertNotNull( QueryNames::set( $this->attributes[ self::SIZE ], 'bild' ), 'another attribute goes by it' );
		// As when the attribute was added after the other was given its slug.
		update_option( QueryNames::OPTION, array( $this->attributes[ self::SIZE ] => 'bild' ) );

		$this->assertSame( array( 'storlek' => 'bild' ), QueryNames::names(), 'the name the store gave wins over a slug' );
		$this->assertSame( '', QueryNames::of( $this->attributes[ self::PICTURE ] ) );
		$this->assertSame( 'https://shop.test/?attribute_pa_bild=a&bild=b', QueryNames::shorten( 'https://shop.test/?attribute_pa_bild=a&attribute_pa_storlek=b' ) );
	}

	public function test_an_address_names_attributes_by_their_short_names(): void {
		QueryNames::set( $this->attributes[ self::PICTURE ], 'motiv' );

		$this->assertSame( 'https://shop.test/p/?utm=1&motiv=med-bild&storlek=30x40#a', QueryNames::shorten( 'https://shop.test/p/?utm=1&attribute_pa_bild=med-bild&attribute_pa_storlek=30x40#a' ) );
		$this->assertSame( 'https://shop.test/shop/?motiv=med-bild%2Cutan-bild&storlek=30x40&query_type_storlek=and&filter_colour=red', QueryNames::shorten( 'https://shop.test/shop/?filter_bild=med-bild%2Cutan-bild&query_type_bild=or&filter_storlek=30x40&query_type_storlek=and&filter_colour=red' ) );
		$this->assertSame( 'https://shop.test/shop/?storlek=a,b&query_type_storlek=and', QueryNames::shorten( 'https://shop.test/shop/?filter_storlek=a,b' ), 'a filter naming no way of combining holds all its values' );
		$this->assertSame( 'https://shop.test/p/?storlek=50x70', QueryNames::shorten( 'https://shop.test/p/?storlek=30x40&attribute_pa_storlek=50x70' ), 'WooCommerce\'s name wins over a short one' );
		$this->assertSame( 'https://shop.test/p/?storlek=50x70', QueryNames::shorten( 'https://shop.test/p/?attribute_pa_storlek=50x70&storlek=30x40' ), 'in either order' );
		$this->assertSame( 'https://shop.test/shop/?motiv=ram&query_type_bild=and', QueryNames::shorten( 'https://shop.test/shop/?filter_bild=ram&motiv=utan-bild&query_type_bild=and' ), 'and for a filter' );
		$this->assertSame( 'https://shop.test/p/?attribute_size=s', QueryNames::shorten( 'https://shop.test/p/?attribute_size=s' ), 'a custom attribute keeps its field' );
		$this->assertSame( 'https://shop.test/p/', QueryNames::shorten( 'https://shop.test/p/' ) );
	}

	public function test_a_product_page_opens_on_the_values_its_address_names_by_their_short_names(): void {
		QueryNames::set( $this->attributes[ self::PICTURE ], 'motiv' );
		$poster = $this->poster();

		$this->open(
			add_query_arg(
				array(
					'motiv'   => 'utan-bild',
					'storlek' => '30x40',
				),
				(string) get_permalink( $poster->get_id() )
			)
		);

		$this->assertTrue( is_product() );
		$this->assertSame(
			array(
				'attribute_pa_bild'    => 'utan-bild',
				'attribute_pa_storlek' => '30x40',
			),
			OpeningChoice::values( $poster )
		);
		ob_start();
		wc_dropdown_variation_attribute_options(
			array(
				'attribute' => 'pa_bild',
				'product'   => $poster,
				'options'   => array( 'med-bild', 'utan-bild' ),
			)
		);
		$this->assertMatchesRegularExpression( '/value="utan-bild"\s+selected/', (string) ob_get_clean(), 'WooCommerce\'s form opens on it' );
		$this->assertFalse( isset( $_GET['filter_bild'] ), 'a product\'s page filters nothing' );

		$this->open(
			add_query_arg(
				array(
					'motiv'             => 'utan-bild',
					'attribute_pa_bild' => 'med-bild',
				),
				(string) get_permalink( $poster->get_id() )
			)
		);
		$this->assertSame( 'med-bild', OpeningChoice::values( $poster )['attribute_pa_bild'], 'WooCommerce\'s own name for it stands' );
	}

	public function test_a_cards_page_canonical_address_and_sitemap_entry_use_the_short_names(): void {
		SplitListing::set_attribute_mode( $this->attributes[ self::PICTURE ], SplitListing::ON );
		QueryNames::set( $this->attributes[ self::PICTURE ], 'motiv' );
		$poster = $this->poster();
		$card   = add_query_arg( 'motiv', 'med-bild', (string) get_permalink( $poster->get_id() ) );

		$this->assertSame( $card, CardPages::url( $poster, (string) $this->term( 'med-bild' ), (string) get_permalink( $poster->get_id() ) ) );
		$this->open(
			add_query_arg(
				array(
					'motiv'   => 'med-bild',
					'storlek' => '50x70',
				),
				(string) get_permalink( $poster->get_id() )
			)
		);
		$this->assertSame( $card, ( new CardPageHead() )->canonical( 'x', get_post( $poster->get_id() ) ), 'a size on top of the card is the card\'s page' );
		$this->assertContains( $card, array_column( ( new CardSitemapProvider() )->get_url_list( 1 ), 'loc' ) );
	}

	public function test_an_archive_filters_by_the_short_names(): void {
		QueryNames::set( $this->attributes[ self::PICTURE ], 'motiv' );
		$poster = $this->poster();
		$page   = $this->category_page();

		$this->assertSame( array( $poster->get_id() ), $this->listed( add_query_arg( 'motiv', 'utan-bild', $page ) ) );
		$this->assertSame( 'or', $_GET['query_type_bild'] ?? null, 'any of the values, as the Product Filters block means it' );
		$this->assertFalse( isset( $_GET['attribute_pa_bild'] ), 'an archive chooses no option' );
		$this->assertSame( array(), $this->listed( add_query_arg( 'motiv', 'ram', $page ) ), 'a value no variation sells' );
		$this->assertSame( array( $poster->get_id() ), $this->listed( add_query_arg( 'motiv', 'ram,utan-bild', $page ) ) );
		$this->listed(
			add_query_arg(
				array(
					'motiv'           => 'ram,utan-bild',
					'query_type_bild' => 'and',
				),
				$page
			)
		);
		$this->assertSame( 'and', $_GET['query_type_bild'] ?? null, 'a filter asking for every value keeps its way' );
		$this->assertSame(
			array(),
			$this->listed(
				add_query_arg(
					array(
						'motiv'       => 'med-bild',
						'storlek'     => '50x70,30x40',
						'filter_bild' => 'ram',
					),
					$page
				)
			),
			'WooCommerce\'s own name for it stands'
		);
	}

	public function test_the_product_filters_block_reads_and_counts_the_short_names(): void {
		QueryNames::set( $this->attributes[ self::PICTURE ], 'motiv' );
		$this->poster();
		$page = $this->category_page();
		$this->open( add_query_arg( 'motiv', 'utan-bild', $page ) );
		$uri = $_SERVER['REQUEST_URI'] ?? '';

		$html = do_blocks( '<!-- wp:woocommerce/product-filters --><div class="wp-block-woocommerce-product-filters wc-block-product-filters"><!-- wp:woocommerce/product-filter-attribute {"attributeId":' . $this->attributes[ self::PICTURE ] . ',"showCounts":true} --><div class="wp-block-woocommerce-product-filter-attribute"><!-- wp:woocommerce/product-filter-checkbox-list --><div class="wp-block-woocommerce-product-filter-checkbox-list wc-block-product-filter-checkbox-list"></div><!-- /wp:woocommerce/product-filter-checkbox-list --></div><!-- /wp:woocommerce/product-filter-attribute --></div><!-- /wp:woocommerce/product-filters -->' );

		$this->assertSame( $uri, $_SERVER['REQUEST_URI'] ?? '', 'the request\'s address is put back' );
		$html = html_entity_decode( $html, ENT_QUOTES );
		$this->assertStringContainsString( '"params":{"filter_bild":"utan-bild","query_type_bild":"or"}', $html, 'the block reads the filter' );
		$this->assertStringContainsString( '"value":"utan-bild","selected":true', $html, 'and shows the value chosen' );
		$this->assertStringContainsString( '"value":"med-bild","selected":false,"type":"attribute\/bild","attributeQueryType":"or","count":1', $html, 'and counts each value with the filters on' );
		$this->assertTrue( wp_script_is( ShortAddresses::SCRIPT_HANDLE, 'enqueued' ), 'with the script shortening the address it goes to' );
	}

	public function test_the_page_prints_the_names_for_its_script(): void {
		QueryNames::set( $this->attributes[ self::PICTURE ], 'motiv' );

		$this->assertSame( 'window.pixonautQueryNames = {"bild":"motiv","storlek":"storlek"};', ShortAddresses::names_script() );
	}

	/**
	 * Opens an address as a browser would.
	 *
	 * @param string $url The address.
	 */
	private function open( string $url ): void {
		$this->forget_request();
		// What PHP gives a request, which the test's request does not.
		parse_str( (string) wp_parse_url( $url, PHP_URL_QUERY ), $query );
		$_REQUEST = array_merge( $_REQUEST, $query );
		$this->go_to( $url );
	}

	/**
	 * The products an archive's address lists.
	 *
	 * @param string $url The archive's address.
	 * @return list<int>
	 */
	private function listed( string $url ): array {
		$this->open( $url );
		global $wp_query;
		$ids = array_values( array_unique( array_map( 'intval', wp_list_pluck( $wp_query->posts, 'ID' ) ) ) );
		sort( $ids );
		return $ids;
	}

	private function category_page(): string {
		$page = get_term_link( $this->category, 'product_cat' );
		$this->assertIsString( $page );
		return $page;
	}

	private function forget_request(): void {
		foreach ( self::ARGS as $arg ) {
			unset( $_GET[ $arg ], $_REQUEST[ $arg ] );
		}
		( new \ReflectionProperty( WC_Query::class, 'chosen_attributes' ) )->setValue( null, null );
		wp_dequeue_script( ShortAddresses::SCRIPT_HANDLE );
	}
}
