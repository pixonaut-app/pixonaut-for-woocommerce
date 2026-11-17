<?php
/**
 * The variation a filtered listing's card opens its product on.
 *
 * @package Pixonaut\WooCommerce
 */

declare(strict_types=1);

namespace Pixonaut\WooCommerce\Storefront;

use Pixonaut\WooCommerce\Catalogue\ApplicableAttributes;
use Pixonaut\WooCommerce\Catalogue\FilterIndex;
use Pixonaut\WooCommerce\Catalogue\FilterOffers;
use Pixonaut\WooCommerce\Catalogue\SplitCards;
use WC_Product;
use WC_Product_Attribute;
use WC_Product_Variable;
use WP_Term;

/**
 * While the shop's attribute or price filters are on, a card of a variable
 * product links to it with a variation the filters chose: one of the
 * card's variations, or of the product's on a live template for a product
 * shown as itself, that holds a chosen value of every filtered attribute
 * and costs within the chosen range (`FilterOffers`).
 *
 * The link names a value of every attribute that applies to the variation
 * (`ApplicableAttributes`): the one it sets, and for one it leaves open
 * the card's value, else a chosen value of a
 * filter on it, else the product's default, else the attribute's first.
 * Of several matching variations, the one whose named values agree with
 * most of the product's defaults wins, then the cheapest, then the first in
 * the product's order. The product's page opens on the first variation its
 * form offers that agrees with the named values, which may be an earlier
 * one left open on some of them: a variation is chosen only when that one
 * matches the filters too. Without a filter, or when no variation matches,
 * a card links as it always has.
 */
final class FilteredOpening {

	/**
	 * The values each card's link names, by product id, card key, filters
	 * and defaults.
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
		FilterIndex::look_up( array( $product->get_id() ) );
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
		$eligible = '' === $key ? SplitCards::live_variations( $product ) : SplitCards::card_variations( $product, $key );
		$card     = '' === $key ? array() : SplitCards::link_values( $product, $key );
		$held     = SplitCards::variation_values( $product->get_id() );
		$offers   = FilterOffers::of( $product );
		$fields   = ApplicableAttributes::fields( $product );
		$exempt   = FilterMatches::exempt( $chosen );
		$best     = null;
		foreach ( $offers as $offer ) {
			if ( ! in_array( $offer['id'], $eligible, true ) || ! FilterOffers::matches( $offer, $chosen, $min, $max, $exempt ) ) {
				continue;
			}
			$own    = $held[ $offer['id'] ] ?? array();
			$hidden = ApplicableAttributes::hidden( $fields, array_values( $held ), $own );
			$values = self::named( $product, $own, $hidden, $card, $chosen, $defaults );
			if ( ! self::opens_matching( $offers, $held, $values, $chosen, $min, $max, $exempt ) ) {
				continue;
			}
			$rank = array( self::agreeing( $values, $defaults ), -$offer['min'] );
			if ( null === $best || $rank > $best[0] ) {
				$best = array( $rank, $values );
			}
		}
		return null === $best ? array() : $best[1];
	}

	/**
	 * The values a link names for a variation: a value of each of the
	 * product's variation attributes that applies to it.
	 *
	 * @param WC_Product_Variable      $product  The product.
	 * @param array<string, string>    $held     The variation's values by `attribute_<name>` field, `''` for one it leaves open.
	 * @param string[]                 $hidden   The fields that do not apply to the variation.
	 * @param array<string, string>    $card     The card's own values by field.
	 * @param array<string, list<int>> $chosen   Chosen term ids by taxonomy.
	 * @param array<string, string>    $defaults The product's stored defaults, by attribute.
	 * @return array<string, string> Values by field, in the product's order of attributes.
	 */
	private static function named( WC_Product_Variable $product, array $held, array $hidden, array $card, array $chosen, array $defaults ): array {
		$values = array();
		foreach ( $product->get_attributes() as $attribute ) {
			if ( ! $attribute instanceof WC_Product_Attribute || ! $attribute->get_variation() ) {
				continue;
			}
			$name  = sanitize_title( $attribute->get_name() );
			$field = 'attribute_' . $name;
			$value = $held[ $field ] ?? '';
			if ( '' === $value && ! in_array( $field, $hidden, true ) ) {
				$value = $card[ $field ] ?? self::open_value( $attribute, $chosen[ $attribute->get_name() ] ?? array(), $defaults[ $name ] ?? '' );
			}
			if ( '' !== $value ) {
				$values[ $field ] = $value;
			}
		}
		return $values;
	}

	/**
	 * Whether the first variation the product's form offers that agrees
	 * with the named values, as the page opens on, matches the filters.
	 *
	 * @param list<array{id: int, values: array<string, list<int>>, min: float, max: float}> $offers The product's offers, in its order of variations.
	 * @param array<int, array<string, string>>                                              $held   Each variation's values by field.
	 * @param array<string, string>                                                          $values The named values by field.
	 * @param array<string, list<int>>                                                       $chosen Chosen term ids by taxonomy.
	 * @param float|null                                                                     $min    The lowest price chosen, as stored.
	 * @param float|null                                                                     $max    The highest price chosen, as stored.
	 * @param array<string, list<int>>                                                       $exempt The values each filter is waived under (`FilterMatches::exempt()`).
	 */
	private static function opens_matching( array $offers, array $held, array $values, array $chosen, ?float $min, ?float $max, array $exempt ): bool {
		foreach ( $offers as $offer ) {
			$own = $held[ $offer['id'] ] ?? array();
			foreach ( $values as $field => $value ) {
				$set = $own[ $field ] ?? '';
				if ( '' !== $set && 0 !== strcasecmp( $set, $value ) ) {
					continue 2;
				}
			}
			return FilterOffers::matches( $offer, $chosen, $min, $max, $exempt );
		}
		return false;
	}

	/**
	 * How many of the product's defaults the named values agree with.
	 *
	 * @param array<string, string> $values   The named values by `attribute_<name>` field.
	 * @param array<string, string> $defaults The product's defaults by attribute.
	 */
	private static function agreeing( array $values, array $defaults ): int {
		$agreeing = 0;
		foreach ( $defaults as $attribute => $value ) {
			$agreeing += ( $values[ 'attribute_' . $attribute ] ?? '' ) === $value ? 1 : 0;
		}
		return $agreeing;
	}

	/**
	 * The value named for an attribute a variation leaves open: the default
	 * when a filter on it chose that or there is no filter on it, else the
	 * first chosen value, else the attribute's first value; each in the
	 * product's order of the attribute's values.
	 *
	 * @param WC_Product_Attribute $attribute A variation attribute of the product.
	 * @param int[]                $ids       The term ids a filter on it chose; none without one.
	 * @param string               $preferred The product's default value of it.
	 * @phpstan-param list<int> $ids
	 */
	private static function open_value( WC_Product_Attribute $attribute, array $ids, string $preferred ): string {
		$options = array();
		if ( $attribute->is_taxonomy() ) {
			foreach ( $attribute->get_options() as $term_id ) {
				$term = get_term( (int) $term_id, $attribute->get_name() );
				if ( $term instanceof WP_Term && ( array() === $ids || in_array( $term->term_id, $ids, true ) ) ) {
					$options[] = $term->slug;
				}
			}
		} else {
			$options = array_map( 'strval', $attribute->get_options() );
		}
		if ( '' !== $preferred && in_array( $preferred, $options, true ) ) {
			return $preferred;
		}
		return $options[0] ?? '';
	}
}
