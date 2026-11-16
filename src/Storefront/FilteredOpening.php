<?php
/**
 * The variation a filtered listing's card opens its product on.
 *
 * @package Pixonaut\WooCommerce
 */

declare(strict_types=1);

namespace Pixonaut\WooCommerce\Storefront;

use Pixonaut\WooCommerce\Catalogue\FilterOffers;
use Pixonaut\WooCommerce\Catalogue\SplitCards;
use WC_Product;
use WC_Product_Attribute;
use WC_Product_Variable;
use WP_Term;

/**
 * While the shop's attribute or price filters are on, a card of a variable
 * product links to it with the variation the filters chose: one of the
 * card's offers (`FilterOffers`) holding a chosen value of every filtered
 * attribute and costing within the chosen range. Of several, it is the one
 * the product's default attributes agree with most, then the cheapest, then
 * the first in the product's order. The link names every value that
 * variation sets, so the product's page opens on it and shows its price; an
 * attribute it leaves open is named only when a filter is on it, by the
 * chosen value the defaults agree with, else the first of the product's.
 * Without a filter, or when no offer matches, a card links as it always has.
 */
final class FilteredOpening {

	/**
	 * The values each card's link names, by product id, card key and filters.
	 *
	 * @var array<string, array<string, string>>
	 */
	private static array $values = array();

	/**
	 * The values a filtered listing's card links to its product with, on
	 * top of the card's own; none without a filter.
	 *
	 * @param WC_Product $product A product the listing shows.
	 * @param string     $key     The key of the card it is shown as; `''` for the product shown as itself.
	 * @return array<string, string> Values by `attribute_<name>` field, in the product's order of attributes.
	 */
	public static function values( WC_Product $product, string $key ): array {
		if ( ! $product instanceof WC_Product_Variable ) {
			return array();
		}
		$chosen            = FilterMatches::chosen();
		list( $min, $max ) = FilterMatches::price_range();
		if ( array() === $chosen && null === $min && null === $max ) {
			return array();
		}
		$defaults                     = array_filter( array_map( 'strval', $product->get_default_attributes( 'edit' ) ), static fn ( string $value ): bool => '' !== $value );
		$cache_key                    = (string) wp_json_encode( array( $product->get_id(), $key, $chosen, $min, $max, $defaults ) );
		self::$values[ $cache_key ] ??= self::read( $product, $key, $chosen, $min, $max, $defaults );
		return self::$values[ $cache_key ];
	}

	/**
	 * Works out `values()`.
	 *
	 * @param WC_Product_Variable      $product  A variable product.
	 * @param string                   $key      A card's key, or `''`.
	 * @param array<string, list<int>> $chosen   Chosen term ids by taxonomy.
	 * @param float|null               $min      The lowest price chosen, as stored.
	 * @param float|null               $max      The highest price chosen, as stored.
	 * @param array<string, string>    $defaults The product's stored defaults, by attribute.
	 * @return array<string, string>
	 */
	private static function read( WC_Product_Variable $product, string $key, array $chosen, ?float $min, ?float $max, array $defaults ): array {
		$in_card = '' === $key ? null : SplitCards::card_variations( $product, $key );
		$held    = SplitCards::variation_values( $product->get_id() );
		$best    = null;
		foreach ( FilterOffers::of( $product ) as $offer ) {
			if ( ( null !== $in_card && ! in_array( $offer['id'], $in_card, true ) ) || ! FilterOffers::matches( $offer, $chosen, $min, $max ) ) {
				continue;
			}
			$rank = array( self::agreeing( $offer['values'], $held[ $offer['id'] ] ?? array(), $defaults ), -$offer['min'] );
			if ( null === $best || $rank > $best[0] ) {
				$best = array( $rank, $offer['id'] );
			}
		}
		if ( null === $best ) {
			return array();
		}
		$values = array();
		foreach ( $product->get_attributes() as $attribute ) {
			if ( ! $attribute instanceof WC_Product_Attribute || ! $attribute->get_variation() ) {
				continue;
			}
			$name  = sanitize_title( $attribute->get_name() );
			$field = 'attribute_' . $name;
			$value = $held[ $best[1] ][ $field ] ?? '';
			if ( '' === $value && isset( $chosen[ $attribute->get_name() ] ) ) {
				$value = self::chosen_slug( $attribute, $chosen[ $attribute->get_name() ], $defaults[ $name ] ?? '' );
			}
			if ( '' !== $value ) {
				$values[ $field ] = $value;
			}
		}
		return $values;
	}

	/**
	 * How many of the product's default attributes a variation agrees
	 * with: a global attribute's when the variation can be bought in the
	 * default value, setting it or leaving it open, and another's when
	 * the variation sets that value or leaves it open.
	 *
	 * @param array<string, list<int>> $offered  The term ids the variation can be bought in, by taxonomy (`FilterOffers`).
	 * @param array<string, string>    $held     The variation's values by `attribute_<name>` field.
	 * @param array<string, string>    $defaults The product's defaults by attribute.
	 */
	private static function agreeing( array $offered, array $held, array $defaults ): int {
		$agreeing = 0;
		foreach ( $defaults as $attribute => $value ) {
			if ( isset( $offered[ $attribute ] ) ) {
				$term   = get_term_by( 'slug', $value, $attribute );
				$agrees = $term instanceof WP_Term && in_array( $term->term_id, $offered[ $attribute ], true );
			} else {
				$own    = $held[ 'attribute_' . $attribute ] ?? '';
				$agrees = '' === $own || $own === $value;
			}
			$agreeing += $agrees ? 1 : 0;
		}
		return $agreeing;
	}

	/**
	 * The slug a filtered attribute a variation leaves open is named by:
	 * the default when a filter chose it, else the first chosen value in
	 * the product's order of the attribute's values.
	 *
	 * @param WC_Product_Attribute $attribute A global attribute of the product.
	 * @param int[]                $ids       The term ids the filter chose.
	 * @param string               $preferred The product's default value of it, as a slug.
	 * @phpstan-param list<int> $ids
	 */
	private static function chosen_slug( WC_Product_Attribute $attribute, array $ids, string $preferred ): string {
		$slugs = array();
		foreach ( array_intersect( array_map( 'intval', $attribute->get_options() ), $ids ) as $id ) {
			$term = get_term( $id, $attribute->get_name() );
			if ( $term instanceof WP_Term ) {
				$slugs[] = $term->slug;
			}
		}
		if ( '' !== $preferred && in_array( $preferred, $slugs, true ) ) {
			return $preferred;
		}
		return $slugs[0] ?? '';
	}
}
