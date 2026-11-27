<?php
/**
 * @package Pixonaut\WooCommerce
 */

declare(strict_types=1);

namespace Pixonaut\WooCommerce\Tests\Integration\Catalogue;

use Pixonaut\WooCommerce\Catalogue\PrintSize;
use Pixonaut\WooCommerce\Catalogue\ProductConfig;
use Pixonaut\WooCommerce\Catalogue\ProductPictures;
use Pixonaut\WooCommerce\Catalogue\Side;
use Pixonaut\WooCommerce\Catalogue\SideValue;
use Pixonaut\WooCommerce\Catalogue\SideValues;
use Pixonaut\WooCommerce\Catalogue\TemplateRenames;
use WC_Product;
use WC_Product_Simple;
use WC_Product_Variable;
use WC_Product_Variation;
use WP_UnitTestCase;

/**
 * A side's own values are kept on the product or variation that holds its
 * setup, apart from the side, and hold only while the side is on the
 * template they were made for.
 */
final class SideValuesTest extends WP_UnitTestCase {

	private const TEMPLATE = 'mini-mug';

	private const OTHER_TEMPLATE = 'mini-tee';

	private const RENAMED = 'mini-mug-farmor';

	private const SET = 'vs_3f2e9a1b7c4d5e6f8a9b0c1d';

	private const OTHER_SET = 'vs_0a1b2c3d4e5f6a7b8c9d0e1f';

	private const VALUES = array( 'title' => array( 'content' => 'farmor' ) );

	/**
	 * When the template's id changed, in milliseconds since the Unix epoch.
	 */
	private const RENAMED_AT = 1767225600000;

	public function tear_down(): void {
		delete_option( TemplateRenames::OPTION );
		parent::tear_down();
	}

	public function test_values_are_stored_and_read_back_by_side(): void {
		$product = $this->product( self::TEMPLATE );

		$this->assertTrue( SideValues::replace_on( $product, array( 'front' => new SideValue( self::TEMPLATE, self::SET, self::VALUES ) ), null ) );

		$read = SideValues::of( $this->reload( $product ), self::side( self::TEMPLATE ) );
		$this->assertNotNull( $read );
		$this->assertSame( self::SET, $read->value_set_id );
		$this->assertSame( self::VALUES, $read->values );
		$this->assertNull( SideValues::of( $this->reload( $product ), self::side( self::TEMPLATE, 'back' ) ) );
	}

	public function test_values_for_another_template_do_not_apply_and_a_renamed_one_still_does(): void {
		$product = $this->product( self::TEMPLATE );
		SideValues::replace_on( $product, array( 'front' => new SideValue( self::TEMPLATE, self::SET, self::VALUES ) ), null );

		$this->assertNull( SideValues::set_of( $this->reload( $product ), self::side( self::OTHER_TEMPLATE ) ) );

		TemplateRenames::record( self::TEMPLATE, self::RENAMED, self::RENAMED_AT );
		$this->assertSame( self::SET, SideValues::set_of( $this->reload( $product ), self::side( self::RENAMED ) ) );
	}

	public function test_a_variation_following_its_product_is_sold_with_the_products_values_and_one_with_its_own_setup_with_its_own(): void {
		list( $parent, $following, $own ) = $this->variable();
		SideValues::replace_on( $parent, array( 'front' => new SideValue( self::TEMPLATE, self::SET, self::VALUES ) ), null );
		SideValues::replace_on( $own, array( 'front' => new SideValue( self::TEMPLATE, self::OTHER_SET, self::VALUES ) ), null );

		$this->assertSame( self::SET, SideValues::set_of( $this->reload( $following ), self::side( self::TEMPLATE ) ) );
		$this->assertSame( self::OTHER_SET, SideValues::set_of( $this->reload( $own ), self::side( self::TEMPLATE ) ) );
	}

	public function test_a_write_over_values_saved_meanwhile_is_refused_and_none_takes_them_off(): void {
		$product = $this->product( self::TEMPLATE );
		SideValues::replace_on( $product, array( 'front' => new SideValue( self::TEMPLATE, self::SET, self::VALUES ) ), null );
		$read = SideValues::document_on( $this->reload( $product ) );
		$this->assertTrue( SideValues::replace_on( $product, array( 'front' => new SideValue( self::TEMPLATE, self::OTHER_SET, self::VALUES ) ), $read ));

		$this->assertFalse( SideValues::replace_on( $product, array(), $read ) );
		$this->assertFalse( SideValues::replace_on( $product, array( 'front' => new SideValue( self::TEMPLATE, self::SET, self::VALUES ) ), null ) );
		$this->assertSame( self::OTHER_SET, SideValues::set_of( $this->reload( $product ), self::side( self::TEMPLATE ) ) );

		$this->assertTrue( SideValues::replace_on( $product, array(), SideValues::document_on( $this->reload( $product ) ) ) );
		$this->assertNull( SideValues::document_on( $this->reload( $product ) ) );
	}

	public function test_a_document_from_a_newer_plugin_is_neither_read_nor_overwritten(): void {
		$product = $this->product( self::TEMPLATE );
		$newer   = (string) wp_json_encode(
			array(
				'v'     => SideValues::VERSION + 1,
				'sides' => array( 'front' => ( new SideValue( self::TEMPLATE, self::SET, self::VALUES ) )->to_array() ),
			)
		);
		update_post_meta( $product->get_id(), SideValues::META_KEY, wp_slash( $newer ) );

		$this->assertSame( array(), SideValues::stored_on( $this->reload( $product ) ) );
		$this->assertFalse( SideValues::replace_on( $product, array(), $newer ) );
	}

	public function test_changing_a_sides_values_changes_what_its_pictures_are_drawn_from(): void {
		$product = $this->product( self::TEMPLATE );
		$before  = ProductPictures::basis( $this->reload( $product ) );

		SideValues::replace_on( $product, array( 'front' => new SideValue( self::TEMPLATE, self::SET, self::VALUES ) ), null );
		$with_set = ProductPictures::basis( $this->reload( $product ) );
		SideValues::replace_on( $product, array( 'front' => new SideValue( self::TEMPLATE, self::OTHER_SET, self::VALUES ) ), SideValues::document_on( $this->reload( $product ) ) );

		$this->assertNotSame( $before, $with_set );
		$this->assertNotSame( $with_set, ProductPictures::basis( $this->reload( $product ) ) );
	}

	private static function side( string $template, string $key = 'front' ): Side {
		return new Side( $key, 'Front', null, null, $template, 2, null, 'Mini mug', array( new PrintSize( 200, 90, 'Mug' ) ) );
	}

	private static function config( string $template, bool $override = false ): ProductConfig {
		return new ProductConfig( true, $override, null, null, array( self::side( $template ) ) );
	}

	private function product( string $template ): WC_Product {
		$product = new WC_Product_Simple();
		$product->set_name( 'Mug' );
		$product->save();
		self::config( $template )->save_to( $product );
		return $this->reload( $product );
	}

	/**
	 * A variable product with a variation following it and one with a setup of its own.
	 *
	 * @return array{0: WC_Product, 1: WC_Product, 2: WC_Product}
	 */
	private function variable(): array {
		$parent = new WC_Product_Variable();
		$parent->set_name( 'Mug' );
		$parent->save();
		self::config( self::TEMPLATE )->save_to( $parent );
		$variations = array();
		foreach ( array( false, true ) as $override ) {
			$variation = new WC_Product_Variation();
			$variation->set_parent_id( $parent->get_id() );
			$variation->set_regular_price( '10' );
			$variation->save();
			if ( $override ) {
				self::config( self::TEMPLATE, true )->save_to( $variation );
			}
			$variations[] = $this->reload( $variation );
		}
		return array( $this->reload( $parent ), $variations[0], $variations[1] );
	}

	private function reload( WC_Product $product ): WC_Product {
		$fresh = wc_get_product( $product->get_id() );
		$this->assertInstanceOf( WC_Product::class, $fresh );
		return $fresh;
	}
}
