<?php
/**
 * @package Pixonaut\WooCommerce
 */

declare(strict_types=1);

namespace Pixonaut\WooCommerce\Tests\Integration\Storefront;

use ReflectionProperty;
use WC_Product;
use WC_Product_Simple;
use WC_Structured_Data;
use WP_UnitTestCase;

/**
 * A product page's structured data describes the product it shows, not the
 * products of the cards on it, whose Product Summary blocks run the same
 * summary hooks.
 */
final class CardStructuredDataTest extends WP_UnitTestCase {

	public function set_up(): void {
		parent::set_up();
		self::forget_described();
	}

	public function tear_down(): void {
		unset( $GLOBALS['product'], $GLOBALS['post'] );
		self::forget_described();
		parent::tear_down();
	}

	/**
	 * Empties what WooCommerce keeps to describe, which lives as long as the test run.
	 */
	private static function forget_described(): void {
		( new ReflectionProperty( WC_Structured_Data::class, '_data' ) )->setValue( WC()->structured_data, array() );
	}

	public function test_a_cards_summary_on_a_product_page_adds_no_product(): void {
		$page    = $this->product( 'Page product' );
		$related = $this->product( 'Related product' );
		$this->go_to( (string) get_permalink( $page->get_id() ) );

		$this->summary_of( $page );
		$this->summary_of( $related );

		$this->assertSame( array( 'Page product' ), $this->described() );
	}

	public function test_the_summary_after_a_card_still_describes_its_product(): void {
		$page    = $this->product( 'Page product' );
		$related = $this->product( 'Related product' );
		$this->go_to( (string) get_permalink( $page->get_id() ) );

		$this->summary_of( $related );
		$this->summary_of( $page );

		$this->assertSame( array( 'Page product' ), $this->described() );
	}

	public function test_a_summary_off_a_product_page_describes_its_product(): void {
		$product = $this->product( 'Listed product' );
		$this->go_to( home_url( '/' ) );

		$this->summary_of( $product );

		$this->assertSame( array( 'Listed product' ), $this->described() );
	}

	private function product( string $name ): WC_Product_Simple {
		$product = new WC_Product_Simple();
		$product->set_name( $name );
		$product->set_status( 'publish' );
		$product->set_regular_price( '10' );
		$product->save();
		return $product;
	}

	/**
	 * Runs the summary hooks with a product as the global one, and its post
	 * as the loop's, as a Product Summary block in a product list does.
	 * WooCommerce before 11.0 reads the brand from the global post.
	 *
	 * @param WC_Product $product The product.
	 */
	private function summary_of( WC_Product $product ): void {
		$GLOBALS['product'] = $product;
		$GLOBALS['post']    = get_post( $product->get_id() ); // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- the post the list's loop sets.
		ob_start();
		do_action( 'woocommerce_single_product_summary' );
		ob_end_clean();
	}

	/**
	 * The names of the products the page's structured data describes.
	 *
	 * @return list<string>
	 */
	private function described(): array {
		$names = array();
		foreach ( WC()->structured_data->get_data() as $data ) {
			if ( 'Product' === ( $data['@type'] ?? null ) ) {
				$names[] = (string) ( $data['name'] ?? '' );
			}
		}
		return $names;
	}
}
