<?php
/**
 * @package Pixonaut\WooCommerce
 */

declare(strict_types=1);

namespace Pixonaut\WooCommerce\Tests\Integration\Storefront;

use Pixonaut\WooCommerce\Catalogue\BlankOptionLinks;
use Pixonaut\WooCommerce\Connection\ConnectionStore;
use Pixonaut\WooCommerce\Plugin;
use Pixonaut\WooCommerce\Storefront\Storefront;
use Pixonaut\WooCommerce\Tests\Support\DesignFixture;
use WC_Product;
use WC_Product_Attribute;
use WC_Product_Variable;
use WC_Product_Variation;
use WP_UnitTestCase;

/**
 * The product page tells the storefront script the pages a book is sold at,
 * for the designer to show.
 */
final class BookPageCountTest extends WP_UnitTestCase {

	private const TAXONOMY = 'pa_blad';

	/**
	 * Each value's slug, the pages it names, null for none, and its name.
	 */
	private const VALUES = array(
		'44-blad' => array( 44, '44 blad' ),
		'60-blad' => array( 60, '60 blad' ),
		'okant'   => array( null, 'Okänt' ),
	);

	private ConnectionStore $store;

	private int $attribute = 0;

	/**
	 * Term ids by slug.
	 *
	 * @var array<string, int>
	 */
	private array $terms = array();

	public function set_up(): void {
		parent::set_up();
		$plugin      = Plugin::boot( dirname( __DIR__, 4 ) . '/pixonaut-for-woocommerce.php' );
		$this->store = $plugin->connection_store();
		$plugin->storefront()->forget_page();
		StorefrontTest::connect( $this->store );
		wp_dequeue_script( Storefront::SCRIPT_HANDLE );
		wp_deregister_script( Storefront::SCRIPT_HANDLE );
		$attribute = wc_create_attribute(
			array(
				'name' => 'Blad',
				'slug' => 'blad',
			)
		);
		$this->assertIsInt( $attribute );
		$this->attribute = $attribute;
		register_taxonomy( self::TAXONOMY, array( 'product', 'product_variation' ) );
		// What WooCommerce holds for an attribute from `init` on.
		$GLOBALS['wc_product_attributes'][ self::TAXONOMY ] = current( wp_list_filter( wc_get_attribute_taxonomies(), array( 'attribute_id' => $attribute ) ) );
		foreach ( self::VALUES as $slug => list( , $name ) ) {
			$term = wp_insert_term( $name, self::TAXONOMY, array( 'slug' => $slug ) );
			$this->assertIsArray( $term );
			$this->terms[ $slug ] = (int) $term['term_id'];
		}
		BlankOptionLinks::set( $attribute, BlankOptionLinks::PAGE_COUNT, array() );
	}

	public function tear_down(): void {
		$this->store->clear();
		unset( $GLOBALS['product'], $GLOBALS['wc_product_attributes'][ self::TAXONOMY ] );
		unregister_taxonomy( self::TAXONOMY );
		delete_transient( 'wc_attribute_taxonomies' );
		delete_option( BlankOptionLinks::OPTION );
		parent::tear_down();
	}

	public function test_each_variation_names_the_pages_it_is_sold_at(): void {
		list( $product, $ids ) = $this->book();

		$html = $this->form_html( $product );

		self::assertSame( 1, preg_match( '/data-pixonaut-variations="([^"]*)"/', $html, $match ) );
		$variations = json_decode( html_entity_decode( $match[1] ?? '', ENT_QUOTES ), true );
		self::assertIsArray( $variations );
		foreach ( self::VALUES as $slug => list( $pages ) ) {
			$this->assertSame( 'personalised', $variations[ $ids[ $slug ] ]['state'] );
			$this->assertSame( $pages, $variations[ $ids[ $slug ] ]['pageCount'] ?? null, $slug );
		}
		$this->assertStringNotContainsString( 'data-pixonaut-page-count', $html );
	}

	public function test_a_variation_of_an_attribute_that_stands_for_nothing_names_none(): void {
		list( $product ) = $this->book();
		BlankOptionLinks::set( $this->attribute, '', array() );

		$this->assertStringNotContainsString( 'pageCount', $this->form_html( $product ) );
	}

	public function test_a_product_without_variations_names_its_own_pages(): void {
		$product = StorefrontTest::product( StorefrontTest::personalised() );
		$this->assertStringNotContainsString( 'data-pixonaut-page-count', $this->form_html( $product ) );
		$product->set_attributes( array( $this->attribute_of( array( '60-blad' ), false ) ) );
		$product->save();
		Plugin::boot( dirname( __DIR__, 4 ) . '/pixonaut-for-woocommerce.php' )->storefront()->forget_page();

		$this->assertStringContainsString( 'data-pixonaut-page-count="60"', $this->form_html( $product ) );
	}

	/**
	 * A variable product with a variation per value.
	 *
	 * @return array{0: WC_Product, 1: array<string, int>} The product, and its variations' ids by value.
	 */
	private function book(): array {
		$product = new WC_Product_Variable();
		$product->set_name( 'Notebook' );
		$product->set_status( 'publish' );
		$product->set_attributes( array( $this->attribute_of( array_keys( self::VALUES ), true ) ) );
		$product->save();
		VariationSwitchesTest::front()->save_to( $product );
		DesignFixture::sell_variant( $product );
		$ids = array();
		foreach ( array_keys( self::VALUES ) as $slug ) {
			$variation = new WC_Product_Variation();
			$variation->set_parent_id( $product->get_id() );
			$variation->set_attributes( array( self::TAXONOMY => $slug ) );
			$variation->set_regular_price( '30' );
			$variation->save();
			DesignFixture::sell_variant( $variation );
			$ids[ $slug ] = $variation->get_id();
		}
		WC_Product_Variable::sync( $product->get_id() );
		$reloaded = wc_get_product( $product->get_id() );
		self::assertInstanceOf( WC_Product::class, $reloaded );
		return array( $reloaded, $ids );
	}

	/**
	 * The page count attribute of a product.
	 *
	 * @param string[] $slugs  The values it has.
	 * @param bool     $varies Whether the product varies by it.
	 * @phpstan-param list<string> $slugs
	 */
	private function attribute_of( array $slugs, bool $varies ): WC_Product_Attribute {
		$attribute = new WC_Product_Attribute();
		$attribute->set_id( $this->attribute );
		$attribute->set_name( self::TAXONOMY );
		$attribute->set_options( array_map( fn ( string $slug ): int => $this->terms[ $slug ], $slugs ) );
		$attribute->set_variation( $varies );
		return $attribute;
	}

	private function form_html( WC_Product $product ): string {
		$GLOBALS['product'] = $product;
		ob_start();
		do_action( 'woocommerce_before_add_to_cart_button' );
		return (string) ob_get_clean();
	}
}
