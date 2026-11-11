<?php
/**
 * A poster listed once per picture, for the split listing's tests.
 *
 * @package Pixonaut\WooCommerce
 */

declare(strict_types=1);

namespace Pixonaut\WooCommerce\Tests\Support;

use Pixonaut\WooCommerce\Catalogue\SplitListing;
use WC_Product;
use WC_Product_Attribute;
use WC_Product_Variable;
use WC_Product_Variation;

/**
 * Global attributes `Bild` (Med bild, Utan bild, and Ram, which no variation
 * sells) and `Storlek` (30x40, 50x70), a category, and a poster in it sold
 * with a photo in both sizes and without one in 30x40. The test class names
 * the attributes in its `PICTURE` and `SIZE` constants.
 */
trait SplitPosters {

	/**
	 * Attribute ids by name.
	 *
	 * @var array<string, int>
	 */
	private array $attributes = array();

	/**
	 * The category every product of a test is in, whose page lists them.
	 *
	 * @var int
	 */
	private int $category;

	private function set_up_posters(): void {
		$this->attribute( self::PICTURE, array( 'Med bild', 'Utan bild', 'Ram' ) );
		$this->attribute( self::SIZE, array( '30x40', '50x70' ) );
		$category = wp_insert_term( 'Posters', 'product_cat' );
		$this->assertIsArray( $category );
		$this->category = (int) $category['term_id'];
	}

	private function tear_down_posters(): void {
		foreach ( array_keys( $this->attributes ) as $name ) {
			$taxonomy = wc_attribute_taxonomy_name( $name );
			unregister_taxonomy( $taxonomy );
			unset( $GLOBALS['wc_product_attributes'][ $taxonomy ] );
		}
		delete_transient( 'wc_attribute_taxonomies' );
		delete_option( SplitListing::OPTION );
	}

	private function term( string $slug ): int {
		foreach ( array_keys( $this->attributes ) as $name ) {
			$term = get_term_by( 'slug', $slug, wc_attribute_taxonomy_name( $name ) );
			if ( $term instanceof \WP_Term ) {
				return $term->term_id;
			}
		}
		$this->fail( "No term {$slug}" );
	}

	/**
	 * A poster sold with a photo in both sizes, and without one in 30x40.
	 */
	private function poster(): WC_Product {
		$product = new WC_Product_Variable();
		$product->set_name( 'Safari' );
		$product->set_status( 'publish' );
		$product->set_category_ids( array( $this->category ) );
		$product->set_attributes( array( $this->product_attribute( self::PICTURE ), $this->product_attribute( self::SIZE ) ) );
		$product->save();
		$this->variation( $product, 'med-bild', '30x40' );
		$this->variation( $product, 'med-bild', '50x70' );
		$this->variation( $product, 'utan-bild', '30x40' );
		WC_Product_Variable::sync( $product->get_id() );
		$fresh = wc_get_product( $product->get_id() );
		$this->assertInstanceOf( WC_Product::class, $fresh );
		return $fresh;
	}

	/**
	 * @param WC_Product $product A poster.
	 * @param string     $picture The picture's slug.
	 * @param string     $size    The size's slug.
	 * @param string     $price   Its regular price.
	 */
	private function variation( WC_Product $product, string $picture, string $size, string $price = '10' ): WC_Product_Variation {
		$variation = new WC_Product_Variation();
		$variation->set_parent_id( $product->get_id() );
		$variation->set_attributes(
			array(
				'pa_bild'    => $picture,
				'pa_storlek' => $size,
			)
		);
		$variation->set_regular_price( $price );
		$variation->save();
		return $variation;
	}

	private function product_attribute( string $name ): WC_Product_Attribute {
		$taxonomy  = wc_attribute_taxonomy_name( $name );
		$attribute = new WC_Product_Attribute();
		$attribute->set_id( $this->attributes[ $name ] );
		$attribute->set_name( $taxonomy );
		$terms = get_terms(
			array(
				'taxonomy'   => $taxonomy,
				'hide_empty' => false,
				'fields'     => 'ids',
				'orderby'    => 'term_id',
			)
		);
		$attribute->set_options( is_array( $terms ) ? $terms : array() );
		$attribute->set_variation( true );
		return $attribute;
	}

	/**
	 * @param string   $name   The attribute's name.
	 * @param string[] $values Its values.
	 * @phpstan-param list<string> $values
	 */
	private function attribute( string $name, array $values ): void {
		$id = wc_create_attribute( array( 'name' => $name ) );
		$this->assertIsInt( $id );
		$taxonomy = wc_attribute_taxonomy_name( $name );
		register_taxonomy( $taxonomy, array( 'product', 'product_variation' ) );
		// What WooCommerce holds for an attribute from `init` on.
		$GLOBALS['wc_product_attributes'][ $taxonomy ] = current( wp_list_filter( wc_get_attribute_taxonomies(), array( 'attribute_id' => $id ) ) );
		foreach ( $values as $value ) {
			wp_insert_term( $value, $taxonomy );
		}
		$this->attributes[ $name ] = $id;
	}
}
