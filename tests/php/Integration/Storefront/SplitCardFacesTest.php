<?php
/**
 * @package Pixonaut\WooCommerce
 */

declare(strict_types=1);

namespace Pixonaut\WooCommerce\Tests\Integration\Storefront;

use Pixonaut\WooCommerce\Catalogue\SplitCardLooks;
use Pixonaut\WooCommerce\Catalogue\SplitListing;
use Pixonaut\WooCommerce\Tests\Support\SplitPosters;
use WC_Product;
use WC_Product_Variable;
use WP_UnitTestCase;

final class SplitCardFacesTest extends WP_UnitTestCase {

	use SplitPosters;

	/**
	 * The attribute the poster is listed by.
	 */
	private const PICTURE = 'Bild';

	private const SIZE = 'Storlek';

	public function set_up(): void {
		parent::set_up();
		$this->set_up_posters();
		SplitListing::set_attribute_default( $this->attributes[ self::PICTURE ], true );
	}

	public function tear_down(): void {
		$this->tear_down_posters();
		parent::tear_down();
	}

	public function test_a_card_shows_its_own_title_else_the_products(): void {
		$poster = $this->poster();
		SplitCardLooks::save(
			$poster->get_id(),
			array(
				$this->term( 'med-bild' ) => array(
					'title' => 'Safari & photo',
					'image' => 0,
				),
			)
		);

		$cards = $this->shop();

		$this->assertSame( array( 'Safari &amp; photo', 'Safari' ), array_column( $cards, 'title' ) );
		$this->assertSame( 'Safari', get_the_title( $poster->get_id() ), 'out of the loop the product is itself' );
	}

	public function test_a_card_shows_its_own_image_else_its_variations_else_the_products(): void {
		$poster  = $this->poster();
		$product = $this->image();
		$own     = $this->image();
		$picture = $this->image();
		$poster->set_image_id( $product );
		$poster->save();
		$without = $this->variation_of( $poster, 'utan-bild' );
		$without->set_image_id( $picture );
		$without->save();

		$this->assertSame( array( $product, $picture ), array_column( $this->shop(), 'image' ), 'a value with no own image shows its variation\'s' );

		SplitCardLooks::save(
			$poster->get_id(),
			array(
				$this->term( 'med-bild' )  => array(
					'title' => '',
					'image' => $own,
				),
				$this->term( 'utan-bild' ) => array(
					'title' => '',
					'image' => $own,
				),
			)
		);
		$this->assertSame( array( $own, $own ), array_column( $this->shop(), 'image' ) );

		wp_delete_attachment( $own, true );
		$this->assertSame( array( $product, $picture ), array_column( $this->shop(), 'image' ), 'a deleted own image is shown no more' );
		$fresh = wc_get_product( $poster->get_id() );
		$this->assertInstanceOf( WC_Product::class, $fresh );
		$this->assertSame( $product, (int) $fresh->get_image_id(), 'out of the loop the product is itself' );
	}

	public function test_a_card_shows_from_its_variations_lowest_price(): void {
		$poster = new WC_Product_Variable();
		$poster->set_name( 'Safari' );
		$poster->set_status( 'publish' );
		$poster->set_category_ids( array( $this->category ) );
		$poster->set_attributes( array( $this->product_attribute( self::PICTURE ), $this->product_attribute( self::SIZE ) ) );
		$poster->save();
		$this->variation( $poster, 'med-bild', '30x40', '29' );
		$this->variation( $poster, 'med-bild', '50x70', '19' );
		$on_sale = $this->variation( $poster, 'utan-bild', '30x40', '15' );
		$on_sale->set_sale_price( '12' );
		$on_sale->save();
		WC_Product_Variable::sync( $poster->get_id() );

		$prices = array_column( $this->shop(), 'price' );

		$this->assertSame( wc_get_price_html_from_text() . wc_price( 19 ), $prices[0] );
		$this->assertSame( wc_format_sale_price( wc_price( 15 ), wc_price( 12 ) ), $prices[1], 'one price, as WooCommerce prints it' );
		$fresh = wc_get_product( $poster->get_id() );
		$this->assertInstanceOf( WC_Product::class, $fresh );
		$this->assertStringContainsString( '29.00', $fresh->get_price_html(), 'out of the loop the product shows its range' );
		$this->assertStringNotContainsString( wc_get_price_html_from_text(), $fresh->get_price_html() );
	}

	/**
	 * The category page's cards, each with the title, image and price a theme prints.
	 *
	 * @return list<array{title: string, image: int, price: string}>
	 */
	private function shop(): array {
		$page = get_term_link( $this->category, 'product_cat' );
		$this->assertIsString( $page );
		$this->go_to( $page );
		global $wp_query;
		$cards = array();
		while ( $wp_query->have_posts() ) {
			$wp_query->the_post();
			$product = wc_get_product( (int) get_the_ID() );
			$this->assertInstanceOf( WC_Product::class, $product );
			$cards[] = array(
				'title' => get_the_title(),
				'image' => (int) $product->get_image_id(),
				'price' => $product->get_price_html(),
			);
		}
		wp_reset_postdata();
		return $cards;
	}

	/**
	 * The poster's variation with a picture.
	 *
	 * @param WC_Product $poster  The poster.
	 * @param string     $picture The picture's slug.
	 */
	private function variation_of( WC_Product $poster, string $picture ): WC_Product {
		foreach ( $poster->get_children() as $id ) {
			$variation = wc_get_product( $id );
			if ( $variation instanceof WC_Product && $picture === $variation->get_attributes()['pa_bild'] ) {
				return $variation;
			}
		}
		$this->fail( "No variation {$picture}" );
	}

	/**
	 * An image in the media library.
	 */
	private function image(): int {
		$id = self::factory()->attachment->create_object(
			array(
				'file'           => 'card.png',
				'post_mime_type' => 'image/png',
			)
		);
		$this->assertIsInt( $id );
		return $id;
	}
}
