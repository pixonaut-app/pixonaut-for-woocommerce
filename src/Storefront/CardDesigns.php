<?php
/**
 * A split product's cards that show one design.
 *
 * @package Pixonaut\WooCommerce
 */

declare(strict_types=1);

namespace Pixonaut\WooCommerce\Storefront;

use Pixonaut\WooCommerce\Catalogue\SplitCards;
use WC_Product;
use WP_Term;

/**
 * A product listed by an attribute only while filtered (a framing) has a
 * card per value of it, and a filter choosing several of its values (or a
 * waived filter, keeping a card holding none of them beside one holding a
 * chosen one) would show the same design more than once. Those cards are one design: the
 * cards of a product holding the same values once the values of its
 * attributes listed only while filtered are set aside. A filtered listing
 * shows each design once, as its first card in the product's order of
 * cards (the attribute's order of values), and counts it once; its other
 * cards are its swatches (`SplitCardListings::swatches()`). A card of an
 * attribute the product is always listed by (a personalisation) stays a
 * design of its own.
 */
final class CardDesigns {

	/**
	 * A product's shown cards by design: each design's first card, with
	 * the others showing the same design, in the order given.
	 *
	 * @param WC_Product $product A split product.
	 * @param string[]   $keys    Its shown cards' keys, in its order of cards.
	 * @return array<string, list<string>> The other keys, by the first.
	 */
	public static function of( WC_Product $product, array $keys ): array {
		$filtered = SplitCards::filtered_taxonomies( $product );
		$firsts   = array();
		$designs  = array();
		foreach ( $keys as $key ) {
			$key       = (string) $key;
			$signature = array() === $filtered ? $key : self::signature( $product, $key, $filtered );
			if ( isset( $firsts[ $signature ] ) ) {
				$designs[ $firsts[ $signature ] ][] = $key;
				continue;
			}
			$firsts[ $signature ] = $key;
			$designs[ $key ]      = array();
		}
		return $designs;
	}

	/**
	 * What a card shows besides its values of the attributes listed only
	 * while filtered: its other values.
	 *
	 * @param WC_Product $product  A split product.
	 * @param string     $key      One of its cards.
	 * @param string[]   $filtered Its attributes listed only while filtered.
	 */
	private static function signature( WC_Product $product, string $key, array $filtered ): string {
		$kept = array();
		foreach ( SplitCards::card_terms( $product, $key ) as $term_id ) {
			$term = get_term( $term_id );
			if ( ! $term instanceof WP_Term || ! in_array( $term->taxonomy, $filtered, true ) ) {
				$kept[] = $term_id;
			}
		}
		return SplitCards::key( $kept );
	}
}
