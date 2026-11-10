<?php
/**
 * @package Pixonaut\WooCommerce
 */

declare(strict_types=1);

namespace Pixonaut\WooCommerce\Tests\Integration\Catalogue;

use Pixonaut\WooCommerce\Catalogue\InvalidReason;
use Pixonaut\WooCommerce\Catalogue\NotLiveProducts;
use Pixonaut\WooCommerce\Catalogue\ProductConfig;
use Pixonaut\WooCommerce\Storefront\UnavailableProduct;
use Pixonaut\WooCommerce\Tests\Integration\Cart\PersonalisedCartTestCase;
use Pixonaut\WooCommerce\Tests\Support\DesignFixture;
use Pixonaut\WooCommerce\Tests\Support\ShopListing;
use WC_Product;
use WC_Product_Attribute;
use WC_Product_Simple;
use WC_Product_Variable;
use WC_Product_Variation;

/**
 * A product whose template is paused or not published is left out of what
 * shoppers browse, while its page still opens and says it is not available.
 */
final class NotLiveProductsTest extends PersonalisedCartTestCase {

	private const AXIS = 'Colour';

	private const NAME = 'Personalised tee';

	private const CATEGORY = 'tees';

	public function set_up(): void {
		parent::set_up();
		$term = wp_insert_term( 'Tees', 'product_cat', array( 'slug' => self::CATEGORY ) );
		$this->assertIsArray( $term );
		wp_set_object_terms( $this->product_id, array( $term['term_id'] ), 'product_cat' );
	}

	public function tear_down(): void {
		wp_set_current_user( 0 );
		parent::tear_down();
	}

	public function test_a_paused_template_takes_the_product_out_of_listings_and_live_again_brings_it_back(): void {
		$other = $this->simple( 'Plain mug' );
		$this->set_config( $this->product_id, self::config()->with_validity( false, InvalidReason::TEMPLATE_NOT_LIVE ) );

		$this->assertNotContains( $this->product_id, ShopListing::shop() );
		$this->assertContains( $other, ShopListing::shop() );
		$this->assertNotContains( $this->product_id, ShopListing::search( self::NAME ) );
		$this->assertNotContains( $this->product_id, ShopListing::ids( array( 'product_cat' => self::CATEGORY ) ), 'a category archive names no post type' );
		$this->assertSame( array( $other ), apply_filters( 'woocommerce_related_products', array( $this->product_id, $other ), $other, array() ) );
		$this->assertFalse( $this->reload( $this->product_id )->is_visible() );
		$this->assertSame( 'publish', $this->reload( $this->product_id )->get_status() );
		$this->assertSame( 'visible', $this->reload( $this->product_id )->get_catalog_visibility() );

		$this->set_config( $this->product_id, self::config() );

		$this->assertContains( $this->product_id, ShopListing::shop() );
		$this->assertContains( $this->product_id, ShopListing::search( self::NAME ) );
		$this->assertContains( $this->product_id, ShopListing::ids( array( 'product_cat' => self::CATEGORY ) ) );
		$this->assertTrue( $this->reload( $this->product_id )->is_visible() );
	}

	public function test_a_listing_of_chosen_products_leaves_it_out_too(): void {
		$other = $this->simple( 'Plain mug' );
		$this->set_config( $this->product_id, self::config()->with_validity( false, InvalidReason::TEMPLATE_NOT_LIVE ) );

		$this->assertSame( array( $other ), ShopListing::ids( self::chosen( array( $this->product_id, $other ) ) ) );
		$this->assertSame( array(), ShopListing::ids( self::chosen( array( $this->product_id ) ) ), 'a choice of only hidden products lists none' );
	}

	public function test_a_product_no_longer_personalised_by_the_filter_is_listed_whatever_was_cached(): void {
		$this->set_config( $this->product_id, self::config()->with_validity( false, InvalidReason::TEMPLATE_NOT_LIVE ) );
		$this->assertContains( $this->product_id, ( new NotLiveProducts() )->hidden_ids() );

		add_filter( 'pixonaut_wc_catalogue_is_personalisable', '__return_false' );
		try {
			$hidden = ( new NotLiveProducts() )->hidden_ids();
		} finally {
			remove_filter( 'pixonaut_wc_catalogue_is_personalisable', '__return_false' );
		}

		$this->assertNotContains( $this->product_id, $hidden );
	}

	public function test_an_unpublished_template_takes_the_product_out_of_listings(): void {
		$this->set_config( $this->product_id, self::config()->with_validity( false, InvalidReason::UNKNOWN_TEMPLATE ) );

		$this->assertNotContains( $this->product_id, ShopListing::shop() );
		$this->assertNotContains( $this->product_id, ShopListing::search( self::NAME ) );
	}

	public function test_another_reason_a_product_cannot_be_sold_keeps_it_listed(): void {
		$this->set_config( $this->product_id, self::config()->with_validity( false, InvalidReason::WRONG_RATIO ) );

		$this->assertContains( $this->product_id, ShopListing::shop() );
	}

	public function test_a_query_of_the_code_itself_still_finds_the_product(): void {
		$this->set_config( $this->product_id, self::config()->with_validity( false, InvalidReason::TEMPLATE_NOT_LIVE ) );

		$found = get_posts(
			array(
				'post_type'   => 'product',
				'fields'      => 'ids',
				'numberposts' => -1,
			)
		);

		$this->assertContains( $this->product_id, array_map( 'intval', $found ) );
		$page = ShopListing::ids(
			array(
				'p'         => $this->product_id,
				'post_type' => 'product',
			)
		);
		$this->assertContains( $this->product_id, $page );
	}

	public function test_the_page_says_the_product_is_not_available_and_refuses_add_to_cart(): void {
		$this->set_config( $this->product_id, self::config()->with_validity( false, InvalidReason::TEMPLATE_NOT_LIVE ) );
		$product = $this->reload( $this->product_id );

		$html = $this->page()->html( $product );

		$this->assertStringContainsString( 'This product is not available right now.', $html );
		$this->assertStringNotContainsString( 'pixonaut-unavailable__note', $html );
		$this->assertFalse( $product->is_purchasable() );
		$this->assertFalse( WC()->cart->add_to_cart( $this->product_id ) );
	}

	public function test_a_store_manager_previews_the_page_with_why_shoppers_do_not_see_it(): void {
		$this->set_config( $this->product_id, self::config()->with_validity( false, InvalidReason::TEMPLATE_NOT_LIVE ) );
		$manager = self::factory()->user->create( array( 'role' => 'shop_manager' ) );
		$this->assertIsInt( $manager );
		wp_set_current_user( $manager );

		$html = $this->page()->html( $this->reload( $this->product_id ) );

		$this->assertStringContainsString( 'This product is not available right now.', $html );
		$this->assertStringContainsString( 'Shoppers do not see this product', $html );
	}

	public function test_a_sellable_product_page_says_nothing(): void {
		$this->assertSame( '', $this->page()->html( $this->reload( $this->product_id ) ) );
	}

	public function test_a_variation_on_a_paused_template_alone_is_unavailable(): void {
		list( $parent, $red, $white ) = $this->variable();
		$this->set_config( $red, self::config()->with_override( true )->with_validity( false, InvalidReason::TEMPLATE_NOT_LIVE ) );

		$this->assertFalse( $this->reload( $red )->is_purchasable() );
		$this->assertTrue( $this->reload( $white )->is_purchasable() );
		$this->assertContains( $parent, ShopListing::shop() );
		$this->assertSame( '', $this->page()->html( $this->reload( $parent ) ) );
	}

	public function test_a_variable_product_whose_every_variation_is_on_a_paused_template_is_left_out(): void {
		list( $parent ) = $this->variable();
		$this->set_config( $parent, self::config()->with_validity( false, InvalidReason::TEMPLATE_NOT_LIVE ) );

		$this->assertNotContains( $parent, ShopListing::shop() );
		$this->assertStringContainsString( 'This product is not available right now.', $this->page()->html( $this->reload( $parent ) ) );
	}

	public function test_a_variation_woocommerce_does_not_offer_keeps_nothing_listed(): void {
		list( $parent, $red, $white ) = $this->variable();
		$this->set_config( $parent, self::config()->with_validity( false, InvalidReason::TEMPLATE_NOT_LIVE ) );
		// White sells on a live template of its own, but has no price, so WooCommerce does not offer it.
		$this->set_config( $white, self::config()->with_override( true ) );
		$unpriced = $this->reload( $white );
		$unpriced->set_regular_price( '' );
		$unpriced->save();

		$this->assertFalse( $this->reload( $red )->is_purchasable() );
		$this->assertNotContains( $parent, ShopListing::shop() );
	}

	/**
	 * The arguments of a listing of chosen products, as the Store API's `include` makes it.
	 *
	 * @param int[] $ids The products chosen.
	 * @phpstan-param list<int> $ids
	 * @return array<string, mixed>
	 */
	private static function chosen( array $ids ): array {
		return array(
			'post_type' => 'product',
			'post__in'  => $ids,
			'orderby'   => 'post__in',
		);
	}

	private function page(): UnavailableProduct {
		return new UnavailableProduct( $this->plugin->labels() );
	}

	private function set_config( int $product_id, ProductConfig $config ): void {
		$config->save_to( $this->reload( $product_id ) );
	}

	private function simple( string $name ): int {
		$product = new WC_Product_Simple();
		$product->set_name( $name );
		$product->set_regular_price( '10' );
		$product->save();
		return $product->get_id();
	}

	/**
	 * A personalised variable product in two colours.
	 *
	 * @return array{0: int, 1: int, 2: int} The product, its red variation and its white one.
	 */
	private function variable(): array {
		$attribute = new WC_Product_Attribute();
		$attribute->set_name( self::AXIS );
		$attribute->set_options( array( 'Red', 'White' ) );
		$attribute->set_visible( true );
		$attribute->set_variation( true );
		$parent = new WC_Product_Variable();
		$parent->set_name( 'Personalised tee in colours' );
		$parent->set_status( 'publish' );
		$parent->set_attributes( array( $attribute ) );
		$parent->save();
		self::config()->save_to( $parent );

		$ids = array();
		foreach ( array( 'Red', 'White' ) as $colour ) {
			$variation = new WC_Product_Variation();
			$variation->set_parent_id( $parent->get_id() );
			$variation->set_attributes( array( strtolower( self::AXIS ) => $colour ) );
			$variation->set_regular_price( '30' );
			$variation->save();
			$ids[] = $variation->get_id();
		}
		WC_Product_Variable::sync( $parent->get_id() );
		DesignFixture::sell_variant( $this->reload( $parent->get_id() ) );
		return array( $parent->get_id(), $ids[0], $ids[1] );
	}

	private function reload( int $product_id ): WC_Product {
		$product = wc_get_product( $product_id );
		$this->assertInstanceOf( WC_Product::class, $product );
		return $product;
	}
}
