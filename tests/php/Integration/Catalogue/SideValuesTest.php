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
 * template they were made for; a variation of its own without values for a
 * side on its product's template is sold with its product's.
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

	public function test_a_variation_with_its_own_setup_on_its_products_template_and_no_values_of_its_own_is_sold_with_its_products(): void {
		list( $parent, $following, $own ) = $this->variable();
		SideValues::replace_on( $parent, array( 'front' => new SideValue( self::TEMPLATE, self::SET, self::VALUES ) ), null );

		$sold = SideValues::sold_with( $this->reload( $own ), self::side( self::TEMPLATE ) );
		$this->assertSame( self::SET, $sold['value']?->value_set_id );
		$this->assertSame( SideValues::FROM_PRODUCT, $sold['from'] );
		$this->assertSame( SideValues::FROM_PRODUCT, SideValues::sold_with( $this->reload( $following ), self::side( self::TEMPLATE ) )['from'] );
		$this->assertSame( SideValues::FROM_OWN, SideValues::sold_with( $this->reload( $parent ), self::side( self::TEMPLATE ) )['from'] );
		$this->assertNull( SideValues::sold_with( $this->reload( $own ), self::side( self::TEMPLATE, 'back' ) )['from'], 'a side the product has no values for has none' );
	}

	public function test_a_variation_with_its_own_values_is_sold_with_them(): void {
		list( $parent, , $own ) = $this->variable();
		SideValues::replace_on( $parent, array( 'front' => new SideValue( self::TEMPLATE, self::SET, self::VALUES ) ), null );
		SideValues::replace_on( $own, array( 'front' => new SideValue( self::TEMPLATE, self::OTHER_SET, self::VALUES ) ), null );

		$sold = SideValues::sold_with( $this->reload( $own ), self::side( self::TEMPLATE ) );
		$this->assertSame( self::OTHER_SET, $sold['value']?->value_set_id );
		$this->assertSame( SideValues::FROM_OWN, $sold['from'] );
	}

	public function test_a_variation_with_its_own_setup_on_another_template_takes_none_of_its_products_values(): void {
		list( $parent, , $own ) = $this->variable( self::OTHER_TEMPLATE );
		SideValues::replace_on( $parent, array( 'front' => new SideValue( self::TEMPLATE, self::SET, self::VALUES ) ), null );

		$this->assertNull( SideValues::set_of( $this->reload( $own ), self::side( self::OTHER_TEMPLATE ) ) );
	}

	public function test_values_left_behind_on_a_product_moved_to_another_template_reach_none_of_its_variations(): void {
		list( $parent, , $own ) = $this->variable();
		SideValues::replace_on( $parent, array( 'front' => new SideValue( self::TEMPLATE, self::SET, self::VALUES ) ), null );
		self::config( self::OTHER_TEMPLATE )->save_to( $parent );

		$this->assertNull( SideValues::set_of( $this->reload( $own ), self::side( self::TEMPLATE ) ) );
	}

	public function test_changing_a_products_values_changes_what_a_variation_of_its_own_is_drawn_from(): void {
		list( $parent, , $own ) = $this->variable();
		$before = ProductPictures::basis( $this->reload( $own ) );

		SideValues::replace_on( $parent, array( 'front' => new SideValue( self::TEMPLATE, self::SET, self::VALUES ) ), null );

		$this->assertNotSame( $before, ProductPictures::basis( $this->reload( $own ) ) );
	}

	public function test_a_write_over_values_saved_meanwhile_is_refused_and_none_takes_them_off(): void {
		$product = $this->product( self::TEMPLATE );
		SideValues::replace_on( $product, array( 'front' => new SideValue( self::TEMPLATE, self::SET, self::VALUES ) ), null );
		$read = SideValues::document_on( $this->reload( $product ) );
		$this->assertTrue( SideValues::replace_on( $product, array( 'front' => new SideValue( self::TEMPLATE, self::OTHER_SET, self::VALUES ) ), $read ) );

		$this->assertFalse( SideValues::replace_on( $product, array(), $read ) );
		$this->assertFalse( SideValues::replace_on( $product, array( 'front' => new SideValue( self::TEMPLATE, self::SET, self::VALUES ) ), null ) );
		$this->assertSame( self::OTHER_SET, SideValues::set_of( $this->reload( $product ), self::side( self::TEMPLATE ) ) );

		$this->assertTrue( SideValues::replace_on( $product, array(), SideValues::document_on( $this->reload( $product ) ) ) );
		$this->assertNull( SideValues::document_on( $this->reload( $product ) ) );
	}

	public function test_a_first_write_after_another_writer_added_a_row_is_refused(): void {
		$product = $this->product( self::TEMPLATE );
		$read    = SideValues::document_on( $this->reload( $product ) );
		// Another writer stores its values between this one's read and its write.
		add_post_meta( $product->get_id(), SideValues::META_KEY, wp_slash( (string) SideValues::encoded( array( 'front' => new SideValue( self::TEMPLATE, self::OTHER_SET, self::VALUES ) ) ) ) );

		$this->assertNull( $read );
		$this->assertFalse( SideValues::replace_on( $product, array( 'front' => new SideValue( self::TEMPLATE, self::SET, self::VALUES ) ), $read ) );
		$this->assertCount( 1, get_post_meta( $product->get_id(), SideValues::META_KEY, false ) );
		$this->assertSame( self::OTHER_SET, SideValues::set_of( $this->reload( $product ), self::side( self::TEMPLATE ) ) );
	}

	public function test_writing_back_the_document_read_is_refused_once_another_was_stored_meanwhile(): void {
		$product = $this->product( self::TEMPLATE );
		$sides   = array( 'front' => new SideValue( self::TEMPLATE, self::SET, self::VALUES ) );
		SideValues::replace_on( $product, $sides, null );
		$read = SideValues::document_on( $this->reload( $product ) );
		SideValues::replace_on( $product, array( 'front' => new SideValue( self::TEMPLATE, self::OTHER_SET, self::VALUES ) ), $read );

		$this->assertFalse( SideValues::replace_on( $product, $sides, $read ) );
		$this->assertSame( self::OTHER_SET, SideValues::set_of( $this->reload( $product ), self::side( self::TEMPLATE ) ) );
	}

	public function test_of_two_rows_a_write_left_the_newest_is_read_and_the_next_write_keeps_one(): void {
		$product = $this->product( self::TEMPLATE );
		$older   = (string) SideValues::encoded( array( 'front' => new SideValue( self::TEMPLATE, self::SET, self::VALUES ) ) );
		$newer   = (string) SideValues::encoded( array( 'front' => new SideValue( self::TEMPLATE, self::OTHER_SET, self::VALUES ) ) );
		add_post_meta( $product->get_id(), SideValues::META_KEY, wp_slash( $older ) );
		add_post_meta( $product->get_id(), SideValues::META_KEY, wp_slash( $newer ) );

		$this->assertSame( $newer, SideValues::document_on( $this->reload( $product ) ) );
		$this->assertTrue( SideValues::replace_on( $product, array(), $newer ) );
		$this->assertSame( array(), get_post_meta( $product->get_id(), SideValues::META_KEY, false ) );
	}

	public function test_a_product_read_before_a_write_is_read_again_with_the_new_values(): void {
		$product = $this->product( self::TEMPLATE );
		SideValues::replace_on( $product, array( 'front' => new SideValue( self::TEMPLATE, self::SET, self::VALUES ) ), null );
		// WooCommerce keeps the meta it read, apart from WordPress's cache.
		$this->assertSame( self::SET, SideValues::set_of( $this->reload( $product ), self::side( self::TEMPLATE ) ) );

		SideValues::replace_on( $product, array( 'front' => new SideValue( self::TEMPLATE, self::OTHER_SET, self::VALUES ) ), SideValues::document_on( $this->reload( $product ) ) );

		$this->assertSame( self::OTHER_SET, SideValues::set_of( $this->reload( $product ), self::side( self::TEMPLATE ) ) );
	}

	public function test_a_write_whose_setup_no_longer_stands_is_refused(): void {
		$product = $this->product( self::TEMPLATE );

		$this->assertFalse( SideValues::replace_on( $product, array( 'front' => new SideValue( self::TEMPLATE, self::SET, self::VALUES ) ), null, static fn (): bool => false ) );
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
	 * @param string $own_template The template the variation of its own is on.
	 * @return array{0: WC_Product, 1: WC_Product, 2: WC_Product}
	 */
	private function variable( string $own_template = self::TEMPLATE ): array {
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
				self::config( $own_template, true )->save_to( $variation );
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
