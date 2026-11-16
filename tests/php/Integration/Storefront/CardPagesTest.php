<?php
/**
 * @package Pixonaut\WooCommerce
 */

declare(strict_types=1);

namespace Pixonaut\WooCommerce\Tests\Integration\Storefront;

use Pixonaut\WooCommerce\Catalogue\CardPages;
use Pixonaut\WooCommerce\Catalogue\SplitCardLooks;
use Pixonaut\WooCommerce\Catalogue\SplitListing;
use Pixonaut\WooCommerce\Storefront\CardPageHead;
use Pixonaut\WooCommerce\Storefront\CardSitemapProvider;
use Pixonaut\WooCommerce\Storefront\CardSitemaps;
use Pixonaut\WooCommerce\Tests\Support\SplitPosters;
use WC_Product;
use WP_UnitTestCase;

final class CardPagesTest extends WP_UnitTestCase {

	use SplitPosters;

	private const PICTURE = 'Bild';

	private const SIZE = 'Storlek';

	private const FIELDS = array( 'attribute_pa_bild', 'attribute_pa_storlek' );

	public function set_up(): void {
		parent::set_up();
		$this->set_up_posters();
		SplitListing::set_attribute_default( $this->attributes[ self::PICTURE ], true );
	}

	public function tear_down(): void {
		$this->tear_down_posters();
		foreach ( self::FIELDS as $field ) {
			unset( $_REQUEST[ $field ] );
		}
		parent::tear_down();
	}

	public function test_an_address_naming_a_cards_value_is_that_cards_page(): void {
		$poster = $this->poster();

		$this->assertSame( (string) $this->term( 'med-bild' ), CardPages::named_by( $poster, array( 'pa_bild' => 'med-bild' ) ) );
		$this->assertSame(
			(string) $this->term( 'med-bild' ),
			CardPages::named_by(
				$poster,
				array(
					'pa_bild'    => 'Med Bild',
					'pa_storlek' => '50x70',
				)
			),
			'however the address spells the value, and whatever else it chooses'
		);
		$this->assertNull( CardPages::named_by( $poster, array() ), 'the product\'s own address' );
		$this->assertNull( CardPages::named_by( $poster, array( 'pa_storlek' => '50x70' ) ), 'a size alone names no card' );
		$this->assertNull( CardPages::named_by( $poster, array( 'pa_bild' => 'ram' ) ), 'a value no variation sells has no card' );
		$this->assertNull( CardPages::named_by( $poster, array( 'pa_bild' => 'okand' ) ) );
	}

	public function test_a_card_page_is_canonical_to_itself_and_a_size_on_top_of_it_to_the_card(): void {
		$poster = $this->poster();
		$card   = add_query_arg( 'attribute_pa_bild', 'med-bild', (string) get_permalink( $poster->get_id() ) );

		$this->assertSame( $card, $this->open( $poster, array( 'attribute_pa_bild' => 'med-bild' ) )->canonical( 'x', get_post( $poster->get_id() ) ) );
		$sized = $this->open(
			$poster,
			array(
				'attribute_pa_bild'    => 'med-bild',
				'attribute_pa_storlek' => '50x70',
			)
		);
		$this->assertSame( $card, $sized->canonical( 'x', get_post( $poster->get_id() ) ) );
		$this->assertSame( $card, wp_get_canonical_url( $poster->get_id() ), 'what WordPress prints' );
		$this->assertSame( 'x', $sized->canonical( 'x', self::factory()->post->create_and_get() ), 'another post keeps its own' );

		$own = $this->open( $poster, array() );
		$this->assertSame( 'x', $own->canonical( 'x', get_post( $poster->get_id() ) ), 'the product\'s own address is canonical as it is' );
		$this->assertSame( 'x', $this->open( $poster, array( 'attribute_pa_storlek' => '50x70' ) )->canonical( 'x', get_post( $poster->get_id() ) ) );
	}

	public function test_a_card_page_has_the_cards_title_description_and_picture(): void {
		$poster = $this->poster();
		$poster->set_short_description( '<p>A poster of the savannah.</p>' );
		$product_image = $this->image();
		$own_image     = $this->image();
		$poster->set_image_id( $product_image );
		$poster->save();

		$head = $this->open( $poster, array( 'attribute_pa_bild' => 'med-bild' ) );
		$this->assertSame( array( 'title' => 'Safari – Med bild' ), $head->title_parts( array( 'title' => 'Safari' ) ) );
		$tags = $this->printed( $head );
		$this->assertStringContainsString( '<meta name="description" content="Med bild. A poster of the savannah." />', $tags );
		$this->assertStringContainsString( '<meta property="og:title" content="Safari – Med bild" />', $tags );
		$this->assertStringContainsString( '<meta property="og:type" content="product" />', $tags );
		$this->assertStringContainsString( 'property="og:url" content="' . esc_attr( add_query_arg( 'attribute_pa_bild', 'med-bild', (string) get_permalink( $poster->get_id() ) ) ) . '"', $tags );
		$this->assertStringContainsString( 'property="og:image" content="' . esc_attr( (string) wp_get_attachment_url( $product_image ) ) . '"', $tags, 'the product\'s picture until the card has one' );

		SplitCardLooks::save(
			$poster->get_id(),
			array(
				$this->term( 'med-bild' ) => array(
					'title' => 'Safari with your photo',
					'image' => $own_image,
				),
			)
		);
		$head = $this->open( $poster, array( 'attribute_pa_bild' => 'med-bild' ) );
		$this->assertSame( array( 'title' => 'Safari with your photo' ), $head->title_parts( array( 'title' => 'Safari' ) ) );
		$this->assertStringContainsString( 'property="og:image" content="' . esc_attr( (string) wp_get_attachment_url( $own_image ) ) . '"', $this->printed( $head ) );

		$this->assertSame( '', $this->printed( $this->open( $poster, array() ) ), 'the product\'s own page gets no tags' );
		$this->assertSame( array( 'title' => 'Safari' ), $this->open( $poster, array() )->title_parts( array( 'title' => 'Safari' ) ) );
	}

	public function test_an_seo_plugins_tags_name_the_card(): void {
		$poster = $this->poster();
		$image  = $this->image();
		SplitCardLooks::save(
			$poster->get_id(),
			array(
				$this->term( 'med-bild' ) => array(
					'title' => '',
					'image' => $image,
				),
			)
		);
		$head = $this->open( $poster, array( 'attribute_pa_bild' => 'med-bild' ) );
		$card = add_query_arg( 'attribute_pa_bild', 'med-bild', (string) get_permalink( $poster->get_id() ) );

		$this->assertSame( $card, $head->address( (string) get_permalink( $poster->get_id() ) ) );
		$this->assertSame( 'Safari – Med bild | Shop', $head->title( 'Safari | Shop' ), 'the card where the title names the product' );
		$this->assertSame( 'Safari – Med bild', $head->title( 'Posters for the wall' ), 'and alone where it does not' );
		$this->assertSame( 'Med bild. Written for search.', $head->description( 'Written for search.' ) );
		$this->assertSame( 'Med bild', $head->description( '' ), 'the product has no text of its own' );
		$this->assertSame( wp_get_attachment_url( $image ), $head->image( 'https://example.org/product.jpg' ) );
		$presentation = $head->yoast_images( new \stdClass() );
		$this->assertIsObject( $presentation );
		$this->assertSame( wp_get_attachment_url( $image ), $presentation->twitter_image ?? null );
		$this->assertSame( wp_get_attachment_url( $image ), $presentation->open_graph_images[0]['url'] ?? null );

		$own = $this->open( $poster, array() );
		$this->assertSame( 'Safari | Shop', $own->title( 'Safari | Shop' ) );
		$this->assertSame( 'Written for search.', $own->description( 'Written for search.' ) );
		$this->assertSame( 'https://example.org/product.jpg', $own->image( 'https://example.org/product.jpg' ) );
		$this->assertEquals( new \stdClass(), $own->yoast_images( new \stdClass() ) );
	}

	public function test_the_sitemap_lists_each_cards_page_and_no_size(): void {
		$poster = $this->poster();
		$this->poster_named( 'Draft', 'draft' );
		$address  = (string) get_permalink( $poster->get_id() );
		$expected = array(
			add_query_arg( 'attribute_pa_bild', 'med-bild', $address ),
			add_query_arg(
				array(
					'attribute_pa_bild'    => 'utan-bild',
					'attribute_pa_storlek' => '30x40',
				),
				$address
			),
		);

		$provider = new CardSitemapProvider();
		$entries  = $provider->get_url_list( 1 );
		$this->assertSame( $expected, array_column( $entries, 'loc' ), 'a card per value, the one size a card is sold in chosen with it; a draft has none' );
		$this->assertMatchesRegularExpression( '/^\d{4}-\d{2}-\d{2}T/', $entries[0]['lastmod'] ?? '' );
		$this->assertSame( 1, $provider->get_max_num_pages() );
		$this->assertSame( array(), $provider->get_url_list( 2 ) );

		( new CardSitemaps() )->provide();
		$this->assertInstanceOf( CardSitemapProvider::class, wp_get_sitemap_providers()[ CardSitemapProvider::NAME ] ?? null );

		$content = ( new CardSitemaps() )->content( '<url><loc>https://example.org/</loc></url>' );
		$this->assertIsString( $content );
		$this->assertStringStartsWith( '<url><loc>https://example.org/</loc></url>', $content );
		$parsed = simplexml_load_string( '<urlset>' . $content . '</urlset>' );
		$this->assertNotFalse( $parsed, 'well-formed XML' );
		$locations = array();
		foreach ( $parsed->url as $url ) {
			$locations[] = (string) $url->loc;
		}
		$this->assertSame( array_merge( array( 'https://example.org/' ), $expected ), $locations );

		SplitListing::set_attribute_default( $this->attributes[ self::PICTURE ], false );
		$this->assertSame( array(), $provider->get_url_list( 1 ), 'a product listed once has its own page alone' );
		$this->assertSame( 0, $provider->get_max_num_pages() );
	}

	/**
	 * Opens a product's page at an address choosing `$chosen`.
	 *
	 * @param WC_Product            $product A product.
	 * @param array<string, string> $chosen  Values by `attribute_*` field.
	 */
	private function open( WC_Product $product, array $chosen ): CardPageHead {
		foreach ( self::FIELDS as $field ) {
			unset( $_REQUEST[ $field ] );
		}
		foreach ( $chosen as $field => $value ) {
			$_REQUEST[ $field ] = $value;
		}
		$this->go_to( add_query_arg( $chosen, (string) get_permalink( $product->get_id() ) ) );
		return new CardPageHead();
	}

	private function printed( CardPageHead $head ): string {
		ob_start();
		$head->print_tags();
		return (string) ob_get_clean();
	}

	private function poster_named( string $name, string $status ): WC_Product {
		$poster = $this->poster();
		$poster->set_name( $name );
		$poster->set_status( $status );
		$poster->save();
		return $poster;
	}

	private function image(): int {
		$id = $this->factory()->attachment->create_upload_object( DIR_TESTDATA . '/images/canola.jpg' );
		$this->assertIsInt( $id );
		return $id;
	}
}
