<?php
/**
 * The shop's listing query, widened for a dependent attribute's filter.
 *
 * @package Pixonaut\WooCommerce
 */

declare(strict_types=1);

namespace Pixonaut\WooCommerce\Storefront;

use WC_Query;
use WP_Query;

/**
 * WooCommerce's query keeps a product for an attribute filter only while
 * the product holds a chosen value of it. A dependent attribute's filter is
 * waived for what it does not apply to (`FilterMatches::exempt()`): with
 * "no frame" and a frame colour chosen, a poster sold bare and framed only
 * in black still has its bare card listed. While a filter is waived, the
 * listing's query keeps a product holding a chosen value of the attribute
 * or one of the values it is waived under, and `FilterMatches` reads each
 * product's variations for the rest.
 *
 * WooCommerce filters by the products' terms, or, with its attribute lookup
 * table on, by that table in the query's clauses, which cannot widen one
 * attribute. While a filter is waived, the listing filters by terms either
 * way.
 */
final class DependentFilterQuery {

	/**
	 * The listing query whose attribute filters are terms rather than
	 * WooCommerce's lookup table, while one is.
	 *
	 * @var WP_Query|null
	 */
	private ?WP_Query $by_terms = null;

	public function register(): void {
		add_action( 'woocommerce_product_query', array( $this, 'widen' ) );
		add_filter( 'woocommerce_enable_post_clause_filtering', array( $this, 'lookup_filtering' ), 10, 2 );
	}

	/**
	 * Widens each waived filter in the shop's listing query.
	 *
	 * @param mixed $query The listing's main query.
	 */
	public function widen( mixed $query ): void {
		if ( ! $query instanceof WP_Query ) {
			return;
		}
		$exempt = FilterMatches::exempt( FilterMatches::chosen() );
		if ( array() === $exempt ) {
			return;
		}
		$tax_query = $query->get( 'tax_query' );
		$tax_query = is_array( $tax_query ) ? $tax_query : array();
		if ( 'yes' === get_option( 'woocommerce_attribute_lookup_enabled' ) ) {
			// The filters WooCommerce leaves to its lookup table, as it reads them without the table.
			foreach ( WC_Query::get_layered_nav_chosen_attributes() as $taxonomy => $filter ) {
				$tax_query[] = array(
					'taxonomy'         => (string) $taxonomy,
					'field'            => 'slug',
					'terms'            => $filter['terms'] ?? array(),
					'operator'         => 'and' === ( $filter['query_type'] ?? '' ) ? 'AND' : 'IN',
					'include_children' => false,
				);
			}
			$this->by_terms = $query;
		}
		foreach ( $tax_query as $index => $clause ) {
			$taxonomy = is_array( $clause ) && is_string( $clause['taxonomy'] ?? null ) ? $clause['taxonomy'] : '';
			if ( isset( $exempt[ $taxonomy ] ) && 'IN' === strtoupper( (string) ( $clause['operator'] ?? 'IN' ) ) ) {
				$tax_query[ $index ] = array_merge( array( 'relation' => 'OR' ), array( $clause ), self::holding( $exempt[ $taxonomy ] ) );
			}
		}
		$query->set( 'tax_query', $tax_query );
	}

	/**
	 * Whether WooCommerce filters a query's attributes by its lookup table:
	 * not the listing whose filters `widen()` made terms.
	 *
	 * @param mixed $enabled Whether it does.
	 * @param mixed $query   The query.
	 */
	public function lookup_filtering( mixed $enabled, mixed $query ): mixed {
		return null !== $this->by_terms && $query === $this->by_terms ? false : $enabled;
	}

	/**
	 * Tax query clauses keeping a product that holds any of some values.
	 *
	 * @param int[] $term_ids Term ids, of whichever attribute.
	 * @phpstan-param list<int> $term_ids
	 * @return list<array{taxonomy: string, field: string, terms: list<int>, include_children: false}>
	 */
	private static function holding( array $term_ids ): array {
		$by_taxonomy = array();
		foreach ( $term_ids as $term_id ) {
			$term = get_term( $term_id );
			if ( $term instanceof \WP_Term ) {
				$by_taxonomy[ $term->taxonomy ][] = $term->term_id;
			}
		}
		$clauses = array();
		foreach ( $by_taxonomy as $taxonomy => $ids ) {
			$clauses[] = array(
				'taxonomy'         => (string) $taxonomy,
				'field'            => 'term_id',
				'terms'            => $ids,
				'include_children' => false,
			);
		}
		return $clauses;
	}
}
