<?php
/**
 * Which of a variable product's attributes apply to a choice.
 *
 * @package Pixonaut\WooCommerce
 */

declare(strict_types=1);

namespace Pixonaut\WooCommerce\Catalogue;

use Pixonaut\WooCommerce\Storefront\ProductPageCache;
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
	 * Whether an attribute applies to a variation: the variation sets it,
	 * or leaves it open without the attribute being hidden under the
	 * variation's own values.
	 *
	 * @param string[]                    $fields     The product's `attribute_*` fields, in its order of attributes.
	 * @param list<array<string, string>> $variations Each variation's values by field; an unset one empty or missing.
	 * @param array<string, string>       $variation  One of them.
	 * @param string                      $field      The attribute's field.
	 * @phpstan-param list<string> $fields
	 */
	public static function applies( array $fields, array $variations, array $variation, string $field ): bool {
		return '' !== ( $variation[ $field ] ?? '' ) || ! in_array( $field, self::hidden( $fields, $variations, $variation ), true );
	}

	/**
	 * The attribute each hidden-able attribute depends on, and the values of
	 * it the attribute applies under: an attribute every variation sets, and
	 * whose value alone decides whether the dependent one applies. A frame
	 * colour that applies to the framed and the hanger variations depends on
	 * the framing, under "framed" and "hanger". When several decide it, as a
	 * size does when only the large size is sold framed, the nearest before
	 * the dependent attribute in the product's order is taken, else the
	 * nearest after it. An attribute no such attribute decides has no entry.
	 *
	 * @param string[]                    $fields     The product's `attribute_*` fields, in its order of attributes.
	 * @param list<array<string, string>> $variations Each variation's values by field; an unset one empty or missing.
	 * @phpstan-param list<string> $fields
	 * @return array<string, array{field: string, values: list<string>}> By the dependent attribute's field; values in the order the variations first hold them.
	 */
	public static function parents( array $fields, array $variations ): array {
		$dependent = self::dependent( $fields, $variations );
		$parents   = array();
		foreach ( $dependent as $field ) {
			$applies = array_map( static fn ( array $variation ): bool => self::applies( $fields, $variations, $variation, $field ), $variations );
			$place   = (int) array_search( $field, $fields, true );
			$nearer  = array_merge( array_reverse( array_slice( $fields, 0, $place ) ), array_slice( $fields, $place + 1 ) );
			foreach ( array_diff( $nearer, $dependent ) as $parent ) {
				$under = self::values_deciding( $variations, $applies, $parent );
				if ( null !== $under ) {
					$parents[ $field ] = array(
						'field'  => $parent,
						'values' => $under,
					);
					break;
				}
			}
		}
		return $parents;
	}

	/**
	 * The values of an attribute under which another applies, when the
	 * attribute's value alone decides that: every variation sets it, all the
	 * variations holding one value agree, and some value has the other not
	 * apply. Null otherwise.
	 *
	 * @param list<array<string, string>> $variations Each variation's values by field.
	 * @param bool[]                      $applies    Whether the other attribute applies, by variation.
	 * @param string                      $deciding   The attribute's field.
	 * @phpstan-param list<bool> $applies
	 * @return list<string>|null
	 */
	private static function values_deciding( array $variations, array $applies, string $deciding ): ?array {
		$by_value = array();
		foreach ( $variations as $index => $variation ) {
			$value = $variation[ $deciding ] ?? '';
			if ( '' === $value || ( isset( $by_value[ $value ] ) && $by_value[ $value ] !== $applies[ $index ] ) ) {
				return null;
			}
			$by_value[ $value ] = $applies[ $index ];
		}
		$under = array_keys( array_filter( $by_value ) );
		return count( $under ) === count( $by_value ) ? null : array_map( 'strval', $under );
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
	 * The values of each enabled variation of the product, by field. One
	 * out of stock counts too, though the form leaves it out while the
	 * store hides those: which attributes a product's variations set does
	 * not change with its stock.
	 *
	 * @param WC_Product $product A product.
	 * @return list<array<string, string>>
	 */
	public static function variations( WC_Product $product ): array {
		if ( ! $product instanceof WC_Product_Variable ) {
			return array();
		}
		$ids = array_values( array_map( 'intval', $product->get_children() ) );
		// Every variation's status and attributes in one read each.
		_prime_post_caches( $ids, false, true );
		$enabled = array_values( array_filter( $ids, static fn ( int $id ): bool => 'publish' === get_post_status( $id ) ) );
		return array_map( static fn ( int $id ): array => array_map( 'strval', wc_get_product_variation_attributes( $id ) ), $enabled );
	}

	/**
	 * `variations()`, kept between requests with what else a product page
	 * keeps of the product (`ProductPageCache`).
	 *
	 * @param WC_Product $product A product.
	 * @return list<array<string, string>>
	 */
	public static function kept_variations( WC_Product $product ): array {
		return ProductPageCache::remember( $product, 'applicable_variations', '', static fn (): array => self::variations( $product ) );
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
