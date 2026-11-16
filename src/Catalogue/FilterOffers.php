<?php
/**
 * What each of a product's offers holds, as the shop's filters read it.
 *
 * @package Pixonaut\WooCommerce
 */

declare(strict_types=1);

namespace Pixonaut\WooCommerce\Catalogue;

use WC_Product;
use WC_Product_Attribute;
use WC_Product_Variable;
use WC_Tax;
use WP_Term;

/**
 * The shop's attribute and price filters keep a product only when one
 * thing a shopper can buy of it holds every chosen value and costs within
 * the chosen range: a variable product's purchasable variations, each one
 * offer, or any other product as one offer.
 *
 * An offer holds, for each of the product's global attributes, the values
 * it can be bought in: the one a variation sets; each the product lists
 * for an attribute the variation leaves open; none for one that does not
 * apply to it (`ApplicableAttributes`), so a filter on that attribute
 * never matches it; each the product lists for an attribute that is not a
 * variation's. A filter's values on one attribute match an offer holding
 * any of them.
 *
 * A variation is purchasable while it is enabled, has a price, and is in
 * stock or the store shows those out of stock, as WooCommerce decides
 * which variations it offers.
 */
final class FilterOffers {

	/**
	 * Products' offers, by product id, read once a request.
	 *
	 * @var array<int, list<array{id: int, values: array<string, list<int>>, min: float, max: float}>>
	 */
	private static array $offers = array();

	/**
	 * Whether an offer holds a chosen value of each filtered attribute and
	 * costs within the chosen range. A filter whose values name no term
	 * matches nothing.
	 *
	 * @param array{values: array<string, list<int>>, min: float, max: float} $offer  An offer.
	 * @param array<string, list<int>>                                        $chosen Chosen term ids by taxonomy.
	 * @param float|null                                                      $min    The lowest price chosen; null for none.
	 * @param float|null                                                      $max    The highest price chosen; null for none.
	 */
	public static function matches( array $offer, array $chosen, ?float $min, ?float $max ): bool {
		foreach ( $chosen as $taxonomy => $ids ) {
			if ( array() === array_intersect( $offer['values'][ $taxonomy ] ?? array(), $ids ) ) {
				return false;
			}
		}
		return ( null === $min || $offer['max'] >= $min ) && ( null === $max || $offer['min'] <= $max );
	}

	/**
	 * A price filter's bounds as the stored prices are compared with them:
	 * without tax, when the shop shows prices with tax that are stored
	 * without, as WooCommerce's own price filter does.
	 *
	 * @param float|null $price A bound; null for none.
	 */
	public static function stored_price( ?float $price ): ?float {
		if ( null === $price || ! wc_tax_enabled() || 'incl' !== get_option( 'woocommerce_tax_display_shop' ) || wc_prices_include_tax() ) {
			return $price;
		}
		// The standard rates, as WooCommerce's own price filter takes off.
		$rates = WC_Tax::get_rates();
		return array() === $rates ? $price : $price - (float) WC_Tax::get_tax_total( WC_Tax::calc_inclusive_tax( $price, $rates ) );
	}

	/**
	 * A product's offers, each with its id: a variation's, or the product's own.
	 *
	 * @param WC_Product $product A product.
	 * @return list<array{id: int, values: array<string, list<int>>, min: float, max: float}>
	 */
	public static function of( WC_Product $product ): array {
		self::$offers[ $product->get_id() ] ??= $product instanceof WC_Product_Variable ? self::variations( $product ) : array( self::itself( $product ) );
		return self::$offers[ $product->get_id() ];
	}

	/**
	 * Forgets what was read of a product's offers this request.
	 *
	 * @param int $product_id A product.
	 */
	public static function forget( int $product_id ): void {
		unset( self::$offers[ $product_id ] );
	}

	/**
	 * A product's offers when they were worked out this request, for
	 * `FilterIndex` to keep; null when they were not.
	 *
	 * @param int $product_id A product.
	 * @return list<array{id: int, values: array<string, list<int>>, min: float, max: float}>|null
	 */
	public static function worked_out( int $product_id ): ?array {
		return self::$offers[ $product_id ] ?? null;
	}

	/**
	 * Takes a product's offers, as `worked_out()` answered them in an
	 * earlier request, as this request's when they are not worked out yet.
	 *
	 * @param int   $product_id A product.
	 * @param mixed $offers     Its offers, as `FilterIndex` kept them.
	 */
	public static function take( int $product_id, mixed $offers ): void {
		if ( is_array( $offers ) ) {
			// @phpstan-ignore assign.propertyType (kept as `worked_out()` answered it, for this product's unchanged variations)
			self::$offers[ $product_id ] ??= $offers;
		}
	}

	/**
	 * A product other than a variable one as its only offer.
	 *
	 * @param WC_Product $product A product.
	 * @return array{id: int, values: array<string, list<int>>, min: float, max: float}
	 */
	private static function itself( WC_Product $product ): array {
		$values = array();
		foreach ( self::attributes( $product ) as $taxonomy => $attribute ) {
			$values[ $taxonomy ] = array_values( array_map( 'intval', $attribute->get_options() ) );
		}
		list( $min, $max ) = self::price_range( $product );
		return array(
			'id'     => $product->get_id(),
			'values' => $values,
			'min'    => $min,
			'max'    => $max,
		);
	}

	/**
	 * A product's lowest and highest price, as WooCommerce's price filter
	 * reads them: a grouped product's span its children's.
	 *
	 * @param WC_Product $product A product other than a variable one.
	 * @return array{0: float, 1: float}
	 */
	private static function price_range( WC_Product $product ): array {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- WooCommerce's own lookup row, which its price filter reads.
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT min_price, max_price FROM {$wpdb->wc_product_meta_lookup} WHERE product_id = %d", $product->get_id() ) );
		if ( is_object( $row ) && is_numeric( $row->min_price ?? null ) && is_numeric( $row->max_price ?? null ) ) {
			return array( (float) $row->min_price, (float) $row->max_price );
		}
		$price = (float) $product->get_price();
		return array( $price, $price );
	}

	/**
	 * A variable product's purchasable variations as offers.
	 *
	 * @param WC_Product_Variable $product A variable product.
	 * @return list<array{id: int, values: array<string, list<int>>, min: float, max: float}>
	 */
	private static function variations( WC_Product_Variable $product ): array {
		$attributes = self::attributes( $product );
		$fields     = ApplicableAttributes::fields( $product );
		$variations = SplitCards::variation_values( $product->get_id() );
		$sold       = array_values( $variations );
		$prices     = self::prices( array_keys( $variations ) );
		$slugs      = array_map( array( self::class, 'term_ids_by_slug' ), $attributes );
		$offers     = array();
		foreach ( $variations as $id => $held ) {
			if ( ! isset( $prices[ $id ] ) ) {
				continue;
			}
			$hidden = ApplicableAttributes::hidden( $fields, $sold, $held );
			$values = array();
			foreach ( $attributes as $taxonomy => $attribute ) {
				$field = 'attribute_' . sanitize_title( $attribute->get_name() );
				$value = $held[ $field ] ?? '';
				if ( ! $attribute->get_variation() || ( '' === $value && ! in_array( $field, $hidden, true ) ) ) {
					$values[ $taxonomy ] = array_values( $slugs[ $taxonomy ] );
				} elseif ( '' !== $value && isset( $slugs[ $taxonomy ][ $value ] ) ) {
					$values[ $taxonomy ] = array( $slugs[ $taxonomy ][ $value ] );
				} else {
					$values[ $taxonomy ] = array();
				}
			}
			$offers[] = array(
				'id'     => $id,
				'values' => $values,
				'min'    => $prices[ $id ],
				'max'    => $prices[ $id ],
			);
		}
		return $offers;
	}

	/**
	 * A product's global attributes, by taxonomy.
	 *
	 * @param WC_Product $product A product.
	 * @return array<string, WC_Product_Attribute>
	 */
	private static function attributes( WC_Product $product ): array {
		$attributes = array();
		foreach ( $product->get_attributes() as $attribute ) {
			if ( $attribute instanceof WC_Product_Attribute && $attribute->is_taxonomy() ) {
				$attributes[ $attribute->get_name() ] = $attribute;
			}
		}
		return $attributes;
	}

	/**
	 * The values a product lists for an attribute, term ids by slug.
	 *
	 * @param WC_Product_Attribute $attribute A global attribute of a product.
	 * @return array<string, int>
	 */
	private static function term_ids_by_slug( WC_Product_Attribute $attribute ): array {
		$ids = array();
		foreach ( $attribute->get_options() as $term_id ) {
			$term = get_term( (int) $term_id, $attribute->get_name() );
			if ( $term instanceof WP_Term ) {
				$ids[ $term->slug ] = $term->term_id;
			}
		}
		return $ids;
	}

	/**
	 * The price of each purchasable variation, read in one query.
	 *
	 * @param int[] $variation_ids A product's enabled variations.
	 * @phpstan-param list<int> $variation_ids
	 * @return array<int, float> By variation id.
	 */
	private static function prices( array $variation_ids ): array {
		if ( array() === $variation_ids ) {
			return array();
		}
		global $wpdb;
		$placeholders = implode( ', ', array_fill( 0, count( $variation_ids ), '%d' ) );
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- every variation's price and stock in one read, kept for the request; a placeholder per id.
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT post_id AS id, meta_key AS field, meta_value AS value FROM {$wpdb->postmeta} WHERE meta_key IN ( '_price', '_stock_status' ) AND post_id IN ( {$placeholders} )",
				...$variation_ids
			)
		);
		// phpcs:enable
		$meta = array();
		foreach ( is_array( $rows ) ? $rows : array() as $row ) {
			$meta[ (int) $row->id ][ (string) $row->field ] = (string) $row->value;
		}
		$hide_out_of_stock = 'yes' === get_option( 'woocommerce_hide_out_of_stock_items' );
		$prices            = array();
		foreach ( $variation_ids as $id ) {
			$price = $meta[ $id ]['_price'] ?? '';
			if ( '' === $price || ( $hide_out_of_stock && 'outofstock' === ( $meta[ $id ]['_stock_status'] ?? '' ) ) ) {
				continue;
			}
			$prices[ $id ] = (float) $price;
		}
		return $prices;
	}
}
