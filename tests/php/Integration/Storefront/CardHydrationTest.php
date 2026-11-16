<?php
/**
 * @package Pixonaut\WooCommerce
 */

declare(strict_types=1);

namespace Pixonaut\WooCommerce\Tests\Integration\Storefront;

use WC_Product;
use WC_Product_Attribute;
use WC_Product_Simple;
use WC_Product_Variable;
use WC_Product_Variation;
use WP_UnitTestCase;

/**
 * A product card whose markup reads nothing of its product but its id loads
 * the product's identity into the page's product store, not the Store API's
 * whole answer with every variation; a card that reads more, and the
 * product a product page shows, load the whole answer.
 */
final class CardHydrationTest extends WP_UnitTestCase {

	/**
	 * What the Store API answers for a product and a card's light load
	 * leaves out.
	 */
	private const WHOLE_ANSWER_ONLY = array( 'variations', 'prices', 'is_purchasable', 'add_to_cart' );

	/**
	 * Cards showing a title and a price, as the shop's do.
	 */
	private const SHOP_CARD = '<!-- wp:post-title {"isLink":true,"__woocommerceNamespace":"woocommerce/product-collection/product-title"} /--><!-- wp:woocommerce/product-price {"isDescendentOfQueryLoop":true} /-->';

	/**
	 * Cards with an add to cart button, which reads a simple product in the
	 * store; a variable product's is a link to its page.
	 */
	private const BUTTON_CARD = self::SHOP_CARD . '<!-- wp:woocommerce/product-button {"isDescendentOfQueryLoop":true} /-->';

	public function tear_down(): void {
		unset( $GLOBALS['product'] );
		parent::tear_down();
	}

	public function test_a_card_reading_nothing_of_its_product_loads_its_identity(): void {
		$poster = $this->poster();

		$html = $this->cards( $poster, self::SHOP_CARD );

		$loaded = $this->loaded( $poster );
		$this->assertSame( $poster->get_id(), $loaded['id'] ?? null );
		$this->assertSame( 'Poster', $loaded['name'] ?? null );
		$this->assertSame( 'variable', $loaded['type'] ?? null );
		$this->assertSame( $poster->get_permalink(), $loaded['permalink'] ?? null );
		$this->assertSame( array(), array_intersect( self::WHOLE_ANSWER_ONLY, array_keys( $loaded ) ), 'nothing the card does not read' );
		$this->assertStringContainsString( 'Poster', $html, 'the card shows its title' );
		$this->assertStringContainsString( 'woocommerce-Price-amount', $html, 'and its price' );
	}

	public function test_a_card_with_an_add_to_cart_button_loads_the_whole_answer(): void {
		$mug = new WC_Product_Simple();
		$mug->set_name( 'Mug' );
		$mug->set_status( 'publish' );
		$mug->set_regular_price( '90' );
		$mug->save();

		$this->cards( $mug, self::BUTTON_CARD );

		$loaded = $this->loaded( $mug );
		$this->assertArrayHasKey( 'prices', $loaded );
		$this->assertArrayHasKey( 'add_to_cart', $loaded );
	}

	public function test_a_variable_products_card_links_to_its_page_and_loads_its_identity(): void {
		$poster = $this->poster();

		$html = $this->cards( $poster, self::BUTTON_CARD );

		$this->assertStringContainsString( 'product_type_variable', $html, 'its button is a link to the product' );
		$this->assertArrayNotHasKey( 'variations', $this->loaded( $poster ) );
	}

	public function test_the_product_a_product_page_shows_is_loaded_whole_in_a_card(): void {
		$poster = $this->poster();
		$this->go_to( (string) get_permalink( $poster->get_id() ) );

		$this->cards( $poster, self::SHOP_CARD );

		$this->assertCount( 2, $this->loaded( $poster )['variations'] ?? array() );
	}

	public function test_another_card_on_a_product_page_loads_its_identity(): void {
		$page   = $this->poster();
		$poster = $this->poster();
		$this->go_to( (string) get_permalink( $page->get_id() ) );

		$this->cards( $poster, self::SHOP_CARD );

		$this->assertArrayNotHasKey( 'variations', $this->loaded( $poster ) );
	}

	/**
	 * A variable product sold in two sizes.
	 */
	private function poster(): WC_Product_Variable {
		$size = new WC_Product_Attribute();
		$size->set_name( 'Size' );
		$size->set_options( array( 'S', 'M' ) );
		$size->set_variation( true );
		$size->set_visible( true );
		$poster = new WC_Product_Variable();
		$poster->set_name( 'Poster' );
		$poster->set_status( 'publish' );
		$poster->set_attributes( array( $size ) );
		$poster->save();
		foreach ( array(
			'S' => '100',
			'M' => '150',
		) as $value => $price ) {
			$variation = new WC_Product_Variation();
			$variation->set_parent_id( $poster->get_id() );
			$variation->set_attributes( array( 'size' => $value ) );
			$variation->set_regular_price( $price );
			$variation->set_status( 'publish' );
			$variation->save();
		}
		return new WC_Product_Variable( $poster->get_id() );
	}

	/**
	 * A Product Collection showing the product, with cards of the given blocks.
	 *
	 * @param WC_Product $product The product.
	 * @param string     $card    The blocks of each card.
	 */
	private function cards( WC_Product $product, string $card ): string {
		$query      = array(
			'perPage'                       => 4,
			'pages'                         => 1,
			'offset'                        => 0,
			'postType'                      => 'product',
			'order'                         => 'asc',
			'orderBy'                       => 'title',
			'search'                        => '',
			'exclude'                       => array(),
			'inherit'                       => false,
			'taxQuery'                      => array(),
			'isProductCollectionBlock'      => true,
			'woocommerceStockStatus'        => array( 'instock', 'outofstock', 'onbackorder' ),
			'woocommerceAttributes'         => array(),
			'woocommerceHandPickedProducts' => array( (string) $product->get_id() ),
		);
		$attributes = (string) wp_json_encode(
			array(
				'queryId' => 0,
				'query'   => $query,
				'tagName' => 'div',
			)
		);
		return do_blocks( '<!-- wp:woocommerce/product-collection ' . $attributes . ' --><div class="wp-block-woocommerce-product-collection"><!-- wp:woocommerce/product-template -->' . $card . '<!-- /wp:woocommerce/product-template --></div><!-- /wp:woocommerce/product-collection -->' );
	}

	/**
	 * What the page's product store holds of a product.
	 *
	 * @param WC_Product $product The product.
	 * @return array<string, mixed>
	 */
	private function loaded( WC_Product $product ): array {
		$state = wp_interactivity_state( 'woocommerce/products' );
		$this->assertIsArray( $state['products'] ?? null, 'the card loaded its product' );
		$this->assertArrayHasKey( $product->get_id(), $state['products'] );
		return $state['products'][ $product->get_id() ];
	}
}
