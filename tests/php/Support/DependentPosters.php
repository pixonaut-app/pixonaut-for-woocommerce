<?php
/**
 * A poster whose options depend on each other, for the tests.
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
 * Global attributes `Inramning` (Ingen, Hängare, Ram, Canvas), `Hängarfärg`
 * (Ek, Svart), `Ramfärg` (Svart, Vit) and `Canvasdjup` (2 cm, 4 cm), and a
 * poster sold bare, with a hanger in each colour, framed in each colour and
 * on canvas in each depth: a variation sets only the option of its own
 * extra.
 */
trait DependentPosters {

	/**
	 * Each attribute's values, by its name, in the product's order of attributes.
	 *
	 * @var array<string, list<string>>
	 */
	private static array $poster_values = array(
		'Inramning'  => array( 'Ingen', 'Hängare', 'Ram', 'Canvas' ),
		'Hangarfarg' => array( 'Ek', 'Svart' ),
		'Ramfarg'    => array( 'Svart', 'Vit' ),
		'Canvasdjup' => array( '2 cm', '4 cm' ),
	);

	/**
	 * The values each variation sets, by attribute name.
	 *
	 * @var array<string, array<string, string>>
	 */
	private static array $poster_sold = array(
		'ingen'         => array( 'Inramning' => 'ingen' ),
		'hangare-ek'    => array(
			'Inramning'  => 'hangare',
			'Hangarfarg' => 'ek',
		),
		'hangare-svart' => array(
			'Inramning'  => 'hangare',
			'Hangarfarg' => 'svart',
		),
		'ram-svart'     => array(
			'Inramning' => 'ram',
			'Ramfarg'   => 'svart',
		),
		'ram-vit'       => array(
			'Inramning' => 'ram',
			'Ramfarg'   => 'vit',
		),
		'canvas-2-cm'   => array(
			'Inramning'  => 'canvas',
			'Canvasdjup' => '2-cm',
		),
		'canvas-4-cm'   => array(
			'Inramning'  => 'canvas',
			'Canvasdjup' => '4-cm',
		),
	);

	/**
	 * Attribute ids by name.
	 *
	 * @var array<string, int>
	 */
	private array $poster_attributes = array();

	/**
	 * The poster's variations, by the keys of `$poster_sold`.
	 *
	 * @var array<string, WC_Product_Variation>
	 */
	private array $poster_variations = array();

	private function set_up_dependent_posters(): void {
		foreach ( self::$poster_values as $name => $values ) {
			$id = wc_create_attribute( array( 'name' => $name ) );
			$this->assertIsInt( $id );
			$taxonomy = wc_attribute_taxonomy_name( $name );
			register_taxonomy( $taxonomy, array( 'product', 'product_variation' ) );
			// What WooCommerce holds for an attribute from `init` on.
			$GLOBALS['wc_product_attributes'][ $taxonomy ] = current( wp_list_filter( wc_get_attribute_taxonomies(), array( 'attribute_id' => $id ) ) );
			foreach ( $values as $value ) {
				wp_insert_term( $value, $taxonomy );
			}
			$this->poster_attributes[ $name ] = $id;
		}
	}

	private function tear_down_dependent_posters(): void {
		foreach ( array_keys( $this->poster_attributes ) as $name ) {
			$taxonomy = wc_attribute_taxonomy_name( $name );
			unregister_taxonomy( $taxonomy );
			unset( $GLOBALS['wc_product_attributes'][ $taxonomy ] );
		}
		delete_transient( 'wc_attribute_taxonomies' );
		delete_option( SplitListing::OPTION );
	}

	/**
	 * The `attribute_*` field of one of the poster's attributes.
	 *
	 * @param string $name The attribute's name.
	 */
	private static function poster_field( string $name ): string {
		return 'attribute_' . wc_attribute_taxonomy_name( $name );
	}

	/**
	 * The id of one of the poster's values.
	 *
	 * @param string $name The attribute's name.
	 * @param string $slug The value's slug.
	 */
	private function poster_term( string $name, string $slug ): int {
		$term = get_term_by( 'slug', $slug, wc_attribute_taxonomy_name( $name ) );
		$this->assertInstanceOf( \WP_Term::class, $term );
		return $term->term_id;
	}

	/**
	 * The poster, with its variations in `$this->poster_variations`.
	 *
	 * @param int[] $category_ids The categories it is in.
	 */
	private function dependent_poster( array $category_ids = array() ): WC_Product_Variable {
		$product = new WC_Product_Variable();
		$product->set_name( 'Poster' );
		$product->set_status( 'publish' );
		$product->set_category_ids( $category_ids );
		$attributes = array();
		foreach ( $this->poster_attributes as $name => $id ) {
			$taxonomy  = wc_attribute_taxonomy_name( $name );
			$attribute = new WC_Product_Attribute();
			$attribute->set_id( $id );
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
			$attributes[] = $attribute;
		}
		$product->set_attributes( $attributes );
		$product->save();
		foreach ( self::$poster_sold as $key => $values ) {
			$variation = new WC_Product_Variation();
			$variation->set_parent_id( $product->get_id() );
			$set = array();
			foreach ( $values as $name => $slug ) {
				$set[ wc_attribute_taxonomy_name( $name ) ] = $slug;
			}
			$variation->set_attributes( $set );
			$variation->set_regular_price( '10' );
			$variation->save();
			$this->poster_variations[ $key ] = $variation;
		}
		WC_Product_Variable::sync( $product->get_id() );
		$fresh = wc_get_product( $product->get_id() );
		$this->assertInstanceOf( WC_Product_Variable::class, $fresh );
		return $fresh;
	}
}
