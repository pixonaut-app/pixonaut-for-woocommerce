<?php
/**
 * A product's attributes used for variations.
 *
 * @package Pixonaut\WooCommerce
 */

declare(strict_types=1);

namespace Pixonaut\WooCommerce\Catalogue;

use WC_Product;
use WC_Product_Attribute;

/**
 * The attributes a variable product's customers choose its variation by, as
 * a side's arrangement places them in the designer's form.
 */
final class VariationAttributes {

	/**
	 * Each attribute's slug, as the add-to-cart form names its field
	 * `attribute_<slug>`, and its name; empty for a product without
	 * variations.
	 *
	 * @param WC_Product $product The product, or one of its variations.
	 * @return list<array{slug: string, label: string}>
	 */
	public static function of( WC_Product $product ): array {
		if ( $product->is_type( 'variation' ) ) {
			$parent  = wc_get_product( $product->get_parent_id() );
			$product = $parent instanceof WC_Product ? $parent : $product;
		}
		$attributes = array();
		foreach ( $product->is_type( 'variable' ) ? $product->get_attributes() : array() as $attribute ) {
			if ( $attribute instanceof WC_Product_Attribute && $attribute->get_variation() ) {
				$attributes[] = array(
					'slug'  => sanitize_title( $attribute->get_name() ),
					'label' => wc_attribute_label( $attribute->get_name(), $product ),
				);
			}
		}
		return $attributes;
	}

	/**
	 * Each attribute as `of()` gives it, with its values on the product:
	 * each as a variation stores it (a term's slug, or a custom attribute's
	 * value as entered) and its name.
	 *
	 * @param WC_Product $product A variable product.
	 * @return list<array{slug: string, label: string, values: list<array{value: string, name: string}>}>
	 */
	public static function with_values( WC_Product $product ): array {
		$attributes = array();
		foreach ( $product->is_type( 'variable' ) ? $product->get_attributes() : array() as $attribute ) {
			if ( ! $attribute instanceof WC_Product_Attribute || ! $attribute->get_variation() ) {
				continue;
			}
			$values = array();
			if ( $attribute->is_taxonomy() ) {
				foreach ( $attribute->get_terms() ?? array() as $term ) {
					$values[] = array(
						'value' => $term->slug,
						'name'  => $term->name,
					);
				}
			} else {
				foreach ( $attribute->get_options() as $option ) {
					$values[] = array(
						'value' => (string) $option,
						'name'  => (string) $option,
					);
				}
			}
			$attributes[] = array(
				'slug'   => sanitize_title( $attribute->get_name() ),
				'label'  => wc_attribute_label( $attribute->get_name(), $product ),
				'values' => $values,
			);
		}
		return $attributes;
	}
}
