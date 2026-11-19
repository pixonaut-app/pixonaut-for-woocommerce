<?php
/**
 * @package Pixonaut\WooCommerce
 */

declare(strict_types=1);

namespace Pixonaut\WooCommerce\Tests\Integration\Catalogue;

use Pixonaut\WooCommerce\Catalogue\BlankPictureGenerations;
use Pixonaut\WooCommerce\Catalogue\ProductIndex;
use WC_Product;
use WC_Product_Variable;
use WC_Product_Variation;
use WP_UnitTestCase;

/**
 * A blank's number moves the pictures that can be drawn on that blank, and no others.
 */
final class BlankPictureGenerationsTest extends WP_UnitTestCase {

	private const BLANK = 'blank_moved';

	private const OTHER_BLANK = 'blank_kept';

	public function test_each_move_counts_and_blanks_keep_their_own_numbers(): void {
		BlankPictureGenerations::move( self::BLANK );
		BlankPictureGenerations::move( self::OTHER_BLANK );
		BlankPictureGenerations::move( self::BLANK );

		$this->assertSame( 2, BlankPictureGenerations::of( self::BLANK ) );
		$this->assertSame( 1, BlankPictureGenerations::of( self::OTHER_BLANK ) );
		$this->assertSame( 0, BlankPictureGenerations::of( 'blank_never_moved' ) );
	}

	public function test_a_variation_depends_on_its_own_blank_and_its_product_on_its_variations(): void {
		$product = new WC_Product_Variable();
		$product->save();
		$moved = $this->variation( $product, self::BLANK );
		$kept  = $this->variation( $product, self::OTHER_BLANK );
		$this->assertSame( array(), BlankPictureGenerations::of_product( $moved ) );

		BlankPictureGenerations::move( self::BLANK );

		$this->assertSame( array( self::BLANK => 1 ), BlankPictureGenerations::of_product( $moved ) );
		$this->assertSame( array(), BlankPictureGenerations::of_product( $kept ) );
		$parent = wc_get_product( $product->get_id() );
		$this->assertInstanceOf( WC_Product::class, $parent );
		$this->assertSame( array( self::BLANK => 1 ), BlankPictureGenerations::of_product( $parent ) );
	}

	private function variation( WC_Product_Variable $product, string $blank_id ): WC_Product {
		$variation = new WC_Product_Variation();
		$variation->set_parent_id( $product->get_id() );
		$variation->save();
		add_post_meta( $variation->get_id(), ProductIndex::BLANK_META, $blank_id );
		$read = wc_get_product( $variation->get_id() );
		$this->assertInstanceOf( WC_Product::class, $read );
		return $read;
	}
}
