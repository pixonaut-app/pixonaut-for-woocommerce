<?php
/**
 * Which of a variable product's attributes apply to a choice.
 *
 * @package Pixonaut\WooCommerce
 */

declare(strict_types=1);

namespace Pixonaut\WooCommerce\Catalogue;

use WC_Product;
use WC_Product_Attribute;
use WC_Product_Variable;

/**
 * A product whose options depend on each other (a poster sold bare, with a
 * hanger or framed, with a hanger colour and a frame colour) leaves an
 * attribute unset on the variations it does not apply to. Such an attribute
 * is hidden from the shopper, and left off the cart and order line, while
 * none of the variations still matching the shopper's choices sets it.
 *
 * Only an attribute some variations set and others leave unset can be
 * hidden. One that no variation sets is a free choice ("any colour"), and
 * one that every variation sets always applies.
 *
 * The choices that count are those on the attributes that cannot be hidden,
 * then each shown attribute's, in the product's order of attributes. A
 * hidden attribute's value never counts, so the answer is the same whatever
 * fills its field. The storefront's `applicable-attributes.ts` applies the
 * same rule in the browser; `tests/fixtures/applicable-attributes.json`
 * holds the cases both must answer alike.
 */
final class ApplicableAttributes {

	/**
	 * The attributes that can be hidden: those some variation sets and
	 * another leaves unset.
	 *
	 * @param string[]                    $fields     The product's `attribute_*` fields, in its order of attributes.
	 * @param list<array<string, string>> $variations Each variation's values by field; an unset one empty or missing.
	 * @phpstan-param list<string> $fields
	 * @return list<string> Fields.
	 */
	public static function dependent( array $fields, array $variations ): array {
		$dependent = array();
		foreach ( $fields as $field ) {
			$set = count( array_filter( $variations, static fn ( array $variation ): bool => '' !== ( $variation[ $field ] ?? '' ) ) );
			if ( $set > 0 && $set < count( $variations ) ) {
				$dependent[] = $field;
			}
		}
		return $dependent;
	}

	/**
	 * The attributes hidden under a choice.
	 *
	 * @param string[]                    $fields     The product's `attribute_*` fields, in its order of attributes.
	 * @param list<array<string, string>> $variations Each variation's values by field; an unset one empty or missing.
	 * @param array<string, string>       $chosen     The chosen values by field; empty or missing for none.
	 * @phpstan-param list<string> $fields
	 * @return list<string> Fields.
	 */
	public static function hidden( array $fields, array $variations, array $chosen ): array {
		$dependent = self::dependent( $fields, $variations );
		$counted   = array();
		foreach ( array_diff( $fields, $dependent ) as $field ) {
			$counted[ $field ] = $chosen[ $field ] ?? '';
		}
		$hidden = array();
		foreach ( $dependent as $field ) {
			$matching = array_filter( $variations, static fn ( array $variation ): bool => self::matches( $variation, $counted ) );
			$setting  = array_filter( $matching, static fn ( array $variation ): bool => '' !== ( $variation[ $field ] ?? '' ) );
			// A choice no variation matches hides nothing: the form refuses it by itself.
			if ( array() !== $matching && array() === $setting ) {
				$hidden[] = $field;
			} else {
				$counted[ $field ] = $chosen[ $field ] ?? '';
			}
		}
		return $hidden;
	}

	/**
	 * Whether a variation holds every chosen value, one it leaves unset
	 * holding any.
	 *
	 * @param array<string, string> $variation Its values by field.
	 * @param array<string, string> $chosen    The chosen values by field; an empty one chooses nothing.
	 */
	public static function matches( array $variation, array $chosen ): bool {
		foreach ( $chosen as $field => $value ) {
			$held = $variation[ $field ] ?? '';
			if ( '' !== $value && '' !== $held && $held !== $value ) {
				return false;
			}
		}
		return true;
	}

	/**
	 * A variable product's `attribute_*` fields, in its order of attributes;
	 * empty for any other product.
	 *
	 * @param WC_Product $product A product.
	 * @return list<string>
	 */
	public static function fields( WC_Product $product ): array {
		$fields = array();
		foreach ( $product instanceof WC_Product_Variable ? $product->get_attributes() : array() as $attribute ) {
			if ( $attribute instanceof WC_Product_Attribute && $attribute->get_variation() ) {
				$fields[] = 'attribute_' . sanitize_title( $attribute->get_name() );
			}
		}
		return $fields;
	}

	/**
	 * The values of each variation the product's form offers, by field.
	 *
	 * @param WC_Product $product A product.
	 * @return list<array<string, string>>
	 */
	public static function variations( WC_Product $product ): array {
		if ( ! $product instanceof WC_Product_Variable ) {
			return array();
		}
		$ids = array_map( 'intval', $product->get_visible_children() );
		// Every variation's attributes in one read.
		update_meta_cache( 'post', $ids );
		return array_map( static fn ( int $id ): array => array_map( 'strval', wc_get_product_variation_attributes( $id ) ), array_values( $ids ) );
	}

	/**
	 * The attributes of a product that a choice hides.
	 *
	 * @param WC_Product            $product A product.
	 * @param array<string, string> $chosen  The chosen values by `attribute_*` field.
	 * @return list<string> Fields.
	 */
	public static function hidden_on( WC_Product $product, array $chosen ): array {
		$fields = self::fields( $product );
		return array() === $fields ? array() : self::hidden( $fields, self::variations( $product ), $chosen );
	}
}
