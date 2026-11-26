<?php
/**
 * @package Pixonaut\WooCommerce
 */

declare(strict_types=1);

namespace Pixonaut\WooCommerce\Tests\Integration\Catalogue;

use Pixonaut\WooCommerce\Catalogue\AttributeOrderCache;
use WC_Cache_Helper;
use WC_Product_Attribute;
use WC_Product_Simple;
use WP_UnitTestCase;

/**
 * A product lists an attribute's values in their new order once their places
 * change, though WooCommerce kept the product's values in the old one.
 */
final class AttributeOrderCacheTest extends WP_UnitTestCase {

	private const ATTRIBUTE = 'Baby size';
	private const SLUG      = 'order-cache-size';
	private const TAXONOMY  = 'pa_order-cache-size';

	/**
	 * The values by name, in the order they are made: their names'.
	 */
	private const SIZES = array( '12 months', '6 months', 'Newborn' );

	/**
	 * The values by age, the order they are put in.
	 */
	private const BY_AGE = array( 'Newborn', '6 months', '12 months' );

	/**
	 * Term ids by name.
	 *
	 * @var array<string, int>
	 */
	private array $terms = array();

	private int $product = 0;

	public function set_up(): void {
		parent::set_up();
		$attribute = wc_create_attribute(
			array(
				'name'     => self::ATTRIBUTE,
				'slug'     => self::SLUG,
				'order_by' => 'menu_order',
			)
		);
		$this->assertIsInt( $attribute );
		register_taxonomy( self::TAXONOMY, array( 'product', 'product_variation' ) );
		// What WooCommerce holds for an attribute from `init` on.
		$GLOBALS['wc_product_attributes'][ self::TAXONOMY ] = current( wp_list_filter( wc_get_attribute_taxonomies(), array( 'attribute_id' => $attribute ) ) );
		foreach ( self::SIZES as $name ) {
			$term = wp_insert_term( $name, self::TAXONOMY );
			$this->assertIsArray( $term );
			$this->terms[ $name ] = (int) $term['term_id'];
		}
		$product_attribute = new WC_Product_Attribute();
		$product_attribute->set_id( $attribute );
		$product_attribute->set_name( self::TAXONOMY );
		$product_attribute->set_options( array_values( $this->terms ) );
		$product = new WC_Product_Simple();
		$product->set_name( 'Body' );
		$product->set_attributes( array( $product_attribute ) );
		$this->product = $product->save();
		wp_set_object_terms( $this->product, array_values( $this->terms ), self::TAXONOMY );
		AttributeOrderCache::register();
	}

	public function tear_down(): void {
		unregister_taxonomy( self::TAXONOMY );
		unset( $GLOBALS['wc_product_attributes'][ self::TAXONOMY ] );
		delete_transient( 'wc_attribute_taxonomies' );
		parent::tear_down();
	}

	/**
	 * The product's values, as its page lists them.
	 *
	 * @return list<string>
	 */
	private function listed(): array {
		return array_values( wp_list_pluck( wc_get_product_terms( $this->product, self::TAXONOMY, array( 'fields' => 'all' ) ), 'name' ) );
	}

	public function test_a_product_lists_the_values_in_their_new_places_after_its_values_were_kept(): void {
		// Never given places, the values list by name, and WooCommerce keeps that.
		$this->assertSame( self::SIZES, $this->listed() );

		foreach ( self::BY_AGE as $position => $name ) {
			update_term_meta( $this->terms[ $name ], 'order', $position );
		}

		$this->assertSame( self::BY_AGE, $this->listed() );
	}

	public function test_other_term_meta_leaves_the_kept_values(): void {
		$this->assertSame( self::SIZES, $this->listed() );
		$prefix = WC_Cache_Helper::get_cache_prefix( 'product_terms_' . self::TAXONOMY );

		update_term_meta( $this->terms['Newborn'], 'pixonaut_some_meta', 'value' );

		$this->assertSame( $prefix, WC_Cache_Helper::get_cache_prefix( 'product_terms_' . self::TAXONOMY ) );
	}
}
