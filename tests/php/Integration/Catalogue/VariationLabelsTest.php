<?php
/**
 * @package Pixonaut\WooCommerce
 */

declare(strict_types=1);

namespace Pixonaut\WooCommerce\Tests\Integration\Catalogue;

use Pixonaut\WooCommerce\Catalogue\ProductVariants;
use Pixonaut\WooCommerce\Catalogue\RequestCache;
use WC_Product_Attribute;
use WC_Product_Variable;
use WC_Product_Variation;
use WP_Term;
use WP_UnitTestCase;

/**
 * A variation's attributes read as labels: a global attribute's value by its
 * term's name, as it is named now.
 */
final class VariationLabelsTest extends WP_UnitTestCase {

	private const TAXONOMY = 'pa_colour';

	public function set_up(): void {
		parent::set_up();
		$id = wc_create_attribute( array( 'name' => 'Colour' ) );
		$this->assertIsInt( $id );
		register_taxonomy( self::TAXONOMY, array( 'product', 'product_variation' ) );
		// What WooCommerce holds for an attribute from `init` on.
		$GLOBALS['wc_product_attributes'][ self::TAXONOMY ] = current( wp_list_filter( wc_get_attribute_taxonomies(), array( 'attribute_id' => $id ) ) );
		RequestCache::forget();
	}

	public function tear_down(): void {
		unset( $GLOBALS['wc_product_attributes'][ self::TAXONOMY ] );
		unregister_taxonomy( self::TAXONOMY );
		delete_transient( 'wc_attribute_taxonomies' );
		RequestCache::forget();
		parent::tear_down();
	}

	public function test_a_variation_reads_its_value_by_the_name_of_its_products_term(): void {
		$variations = self::product( array( 'Red', 'Navy Blue' ) );

		$this->assertSame( array( 'Colour' => 'Navy Blue' ), ProductVariants::attributes( $variations['navy-blue'] ) );
		$this->assertSame( array( 'Colour' => 'Red' ), ProductVariants::attributes( $variations['red'] ) );
	}

	public function test_a_renamed_value_reads_by_its_new_name(): void {
		$variations = self::product( array( 'Red', 'Navy Blue' ) );
		ProductVariants::attributes( $variations['navy-blue'] );

		$term = get_term_by( 'slug', 'navy-blue', self::TAXONOMY );
		$this->assertInstanceOf( WP_Term::class, $term );
		wp_update_term( $term->term_id, self::TAXONOMY, array( 'name' => 'Midnight' ) );

		$this->assertSame( array( 'Colour' => 'Midnight' ), ProductVariants::attributes( self::reload( $variations['navy-blue'] ) ) );
	}

	public function test_a_value_its_product_no_longer_lists_still_reads_by_its_terms_name(): void {
		$variations = self::product( array( 'Red', 'Navy Blue' ) );
		wp_insert_term( 'Forest Green', self::TAXONOMY );
		$variations['navy-blue']->set_attributes( array( self::TAXONOMY => 'forest-green' ) );
		$variations['navy-blue']->save();

		$this->assertSame( array( 'Colour' => 'Forest Green' ), ProductVariants::attributes( self::reload( $variations['navy-blue'] ) ) );
	}

	/**
	 * A variable product with a variation per colour, by the colour's slug.
	 *
	 * @param string[] $colours The colours.
	 * @phpstan-param list<string> $colours
	 * @return array<string, WC_Product_Variation>
	 */
	private static function product( array $colours ): array {
		$ids = array();
		foreach ( $colours as $colour ) {
			$term = wp_insert_term( $colour, self::TAXONOMY );
			self::assertIsArray( $term );
			$ids[] = (int) $term['term_id'];
		}
		$attribute = new WC_Product_Attribute();
		$attribute->set_id( (int) wc_attribute_taxonomy_id_by_name( self::TAXONOMY ) );
		$attribute->set_name( self::TAXONOMY );
		$attribute->set_options( $ids );
		$attribute->set_variation( true );
		$product = new WC_Product_Variable();
		$product->set_name( 'Mug' );
		$product->set_status( 'publish' );
		$product->set_attributes( array( $attribute ) );
		$product->save();
		wp_set_object_terms( $product->get_id(), $ids, self::TAXONOMY );
		$variations = array();
		foreach ( $ids as $id ) {
			$term = get_term( $id, self::TAXONOMY );
			self::assertInstanceOf( WP_Term::class, $term );
			$slug      = $term->slug;
			$variation = new WC_Product_Variation();
			$variation->set_parent_id( $product->get_id() );
			$variation->set_attributes( array( self::TAXONOMY => $slug ) );
			$variation->set_regular_price( '10' );
			$variation->save();
			$variations[ $slug ] = self::reload( $variation );
		}
		return $variations;
	}

	private static function reload( WC_Product_Variation $variation ): WC_Product_Variation {
		$reloaded = wc_get_product( $variation->get_id() );
		self::assertInstanceOf( WC_Product_Variation::class, $reloaded );
		return $reloaded;
	}
}
