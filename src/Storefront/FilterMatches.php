<?php
/**
 * The shop's filters, matched against one offer at a time.
 *
 * @package Pixonaut\WooCommerce
 */

declare(strict_types=1);

namespace Pixonaut\WooCommerce\Storefront;

use Pixonaut\WooCommerce\Catalogue\FilterOffers;
use Pixonaut\WooCommerce\Catalogue\NotLiveProducts;
use Pixonaut\WooCommerce\Catalogue\SplitCards;
use Pixonaut\WooCommerce\Settings\Settings;
use WC_Cache_Helper;
use WC_Product;
use WC_Query;
use WP_Query;

/**
 * WooCommerce reads each attribute filter, and the price filter, on its
 * own, against a product as a whole: a poster sold bare, and framed in oak,
 * passes "bare" with "oak", though nothing it sells is both. The shop's
 * listings keep a product, and each of its cards (`SplitCardListings`),
 * only while one of its offers (`FilterOffers`) holds every chosen value and
 * costs within the chosen range; a card's offers are the variations it
 * sells.
 *
 * The Product Filters block's counts follow the same rule: each value's
 * count is what the listing would show with that value chosen on top of
 * the other filters, counting a product once per card it would be listed
 * as. WooCommerce asks for them through `woocommerce_pre_product_filter_data`,
 * which takes the query its listing runs.
 */
final class FilterMatches {

	/**
	 * Before another plugin taking over the counts, such as a search service, whose answer then stands.
	 */
	private const COUNTS_PRIORITY = 5;

	/**
	 * The prefix of a filter's query argument, before the attribute's name.
	 */
	private const FILTER_PREFIX = 'filter_';

	/**
	 * The prefix of the argument saying how a filter's values combine.
	 */
	private const QUERY_TYPE_PREFIX = 'query_type_';

	/**
	 * WooCommerce's clauses for the Product Filters block's filters, which
	 * it keeps internal: used while it has them.
	 */
	private const QUERY_CLAUSES = 'Automattic\\WooCommerce\\Internal\\ProductFilters\\QueryClauses';

	/**
	 * The query type under which every chosen value of an attribute must be held.
	 */
	private const ALL_VALUES = 'and';

	/**
	 * Prefix of the transient each query's counts are kept in.
	 */
	private const COUNTS_TRANSIENT = 'pixonaut_wc_filter_counts_';

	/**
	 * How long counts are kept: they are read again sooner once a product
	 * changes, and a template going live or paused shows within this time.
	 */
	private const COUNTS_LIFETIME = HOUR_IN_SECONDS;

	/**
	 * The price filter's query arguments.
	 */
	private const MIN_PRICE = 'min_price';

	private const MAX_PRICE = 'max_price';

	/**
	 * @param Settings $settings The settings.
	 */
	public function __construct( private readonly Settings $settings ) {}

	public function register(): void {
		add_filter( 'woocommerce_pre_product_filter_data', array( $this, 'counts' ), self::COUNTS_PRIORITY, 4 );
	}

	/**
	 * The values the request's filters chose, by the attribute each is on.
	 *
	 * @return array<string, list<int>> Term ids by taxonomy; none for a filter whose values name no term.
	 */
	public static function chosen(): array {
		$chosen = array();
		foreach ( WC_Query::get_layered_nav_chosen_attributes() as $taxonomy => $filter ) {
			$slugs                        = is_array( $filter['terms'] ?? null ) ? array_values( array_map( 'strval', $filter['terms'] ) ) : array();
			$chosen[ (string) $taxonomy ] = self::term_ids( (string) $taxonomy, $slugs );
		}
		return $chosen;
	}

	/**
	 * The request's price range, as the stored prices compare with it.
	 *
	 * @return array{0: float|null, 1: float|null} The lowest and the highest price; null for none.
	 */
	public static function price_range(): array {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- a filter in a link, which changes nothing; read as WooCommerce reads it.
		return self::range(
			isset( $_GET[ self::MIN_PRICE ] ) ? sanitize_text_field( wp_unslash( $_GET[ self::MIN_PRICE ] ) ) : null,
			isset( $_GET[ self::MAX_PRICE ] ) ? sanitize_text_field( wp_unslash( $_GET[ self::MAX_PRICE ] ) ) : null
		);
		// phpcs:enable
	}

	/**
	 * What a filtered listing leaves out of a product: null for the whole
	 * product, else the keys of its cards none of whose offers matches;
	 * none when nothing is filtered.
	 *
	 * @param WC_Product               $product A product.
	 * @param array<string, list<int>> $chosen  Chosen term ids by taxonomy.
	 * @param float|null               $min     The lowest price chosen, as stored.
	 * @param float|null               $max     The highest price chosen, as stored.
	 * @return list<string>|null
	 */
	public static function left_out( WC_Product $product, array $chosen, ?float $min, ?float $max ): ?array {
		$matching = array();
		foreach ( FilterOffers::of( $product ) as $offer ) {
			if ( FilterOffers::matches( $offer, $chosen, $min, $max ) ) {
				$matching[] = $offer['id'];
			}
		}
		if ( array() === $matching ) {
			return null;
		}
		$left = array();
		foreach ( SplitCards::sold_by_card( $product ) as $key => $sold ) {
			if ( array() === array_intersect( $sold, $matching ) ) {
				$left[] = $key;
			}
		}
		return $left;
	}

	/**
	 * What a filtered listing leaves out of the variable products its query
	 * finds, by WooCommerce's own reading of the filters: the products none
	 * of whose offers matches, and of the others the cards none of whose
	 * offers does. Any other product is one offer, which WooCommerce's
	 * reading already matches.
	 *
	 * @param string                   $join   The listing's joins.
	 * @param string                   $where  The listing's conditions.
	 * @param array<string, list<int>> $chosen Chosen term ids by taxonomy.
	 * @param float|null               $min    The lowest price chosen, as stored.
	 * @param float|null               $max    The highest price chosen, as stored.
	 * @return array{products: list<int>, cards: array<int, list<string>>} Product ids, and card keys by product id.
	 */
	public static function left_out_of_listing( string $join, string $where, array $chosen, ?float $min, ?float $max ): array {
		global $wpdb;
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared -- the listing's own clauses, which WP_Query prepared; read once a listing.
		$ids = $wpdb->get_col(
			"SELECT DISTINCT {$wpdb->posts}.ID FROM {$wpdb->posts} {$join} WHERE 1=1 {$where} AND {$wpdb->posts}.ID IN ( SELECT type_rel.object_id FROM {$wpdb->term_relationships} type_rel INNER JOIN {$wpdb->term_taxonomy} type_tax ON type_tax.term_taxonomy_id = type_rel.term_taxonomy_id INNER JOIN {$wpdb->terms} type_term ON type_term.term_id = type_tax.term_id WHERE type_tax.taxonomy = 'product_type' AND type_term.slug = 'variable' )"
		);
		// phpcs:enable
		$left = array(
			'products' => array(),
			'cards'    => array(),
		);
		foreach ( is_array( $ids ) ? $ids : array() as $id ) {
			$product = wc_get_product( (int) $id );
			if ( ! $product instanceof WC_Product ) {
				continue;
			}
			$cards = self::left_out( $product, $chosen, $min, $max );
			if ( null === $cards ) {
				$left['products'][] = $product->get_id();
			} elseif ( array() !== $cards ) {
				$left['cards'][ $product->get_id() ] = $cards;
			}
		}
		return $left;
	}

	/**
	 * Each value's count for the Product Filters block, in place of
	 * WooCommerce's own: other filter data is left to WooCommerce.
	 *
	 * @param mixed $results    An answer another callback gave, or null.
	 * @param mixed $type       What is counted: `attribute`, `price`, `stock`, `rating` or `taxonomy`.
	 * @param mixed $query_vars The listing's query, without the counted attribute's own filter unless its values combine with `and`.
	 * @param mixed $extra      The counted attribute's `taxonomy`.
	 * @return mixed Counts by term id.
	 */
	public function counts( mixed $results, mixed $type, mixed $query_vars, mixed $extra ): mixed {
		$taxonomy = is_array( $extra ) && is_string( $extra['taxonomy'] ?? null ) ? $extra['taxonomy'] : '';
		if ( null !== $results || 'attribute' !== $type || ! is_array( $query_vars ) || '' === $taxonomy ) {
			return $results;
		}
		$one     = true === $this->settings->get( Settings::ONE_CARD_PER_PRODUCT );
		$version = class_exists( WC_Cache_Helper::class ) ? (string) WC_Cache_Helper::get_transient_version( 'product' ) : '';
		$normal  = $query_vars;
		ksort( $normal );
		$transient = self::COUNTS_TRANSIENT . md5( (string) wp_json_encode( array( $normal, $taxonomy, $one, $version ) ) );
		$cached    = get_transient( $transient );
		if ( is_array( $cached ) ) {
			return $cached;
		}
		$counts = self::count( $query_vars, $taxonomy, $one );
		set_transient( $transient, $counts, self::COUNTS_LIFETIME );
		return $counts;
	}

	/**
	 * Works out `counts()`.
	 *
	 * @param array<array-key, mixed> $query_vars The listing's query.
	 * @param string                  $taxonomy   The counted attribute.
	 * @param bool                    $one        Whether the store lists each product once.
	 * @return array<int, int> Counts by term id.
	 */
	private static function count( array $query_vars, string $taxonomy, bool $one ): array {
		$chosen = self::chosen_in( $query_vars );
		$all    = self::all_values_in( $query_vars );
		$range  = self::range(
			is_scalar( $query_vars[ self::MIN_PRICE ] ?? null ) ? (string) $query_vars[ self::MIN_PRICE ] : null,
			is_scalar( $query_vars[ self::MAX_PRICE ] ?? null ) ? (string) $query_vars[ self::MAX_PRICE ] : null
		);
		$counts = array();
		foreach ( self::listed( $query_vars ) as $product_id ) {
			$product = wc_get_product( $product_id );
			if ( ! $product instanceof WC_Product ) {
				continue;
			}
			foreach ( self::values_held( $product, $taxonomy ) as $term_id ) {
				$with              = $chosen;
				$with[ $taxonomy ] = array_values( array_unique( array_merge( $chosen[ $taxonomy ] ?? array(), array( $term_id ) ) ) );
				$shown             = self::holds_all( $product, $with, $all ) ? self::shown( $product, $with, $range[0], $range[1], $one ) : 0;
				if ( $shown > 0 ) {
					$counts[ $term_id ] = ( $counts[ $term_id ] ?? 0 ) + $shown;
				}
			}
		}
		return $counts;
	}

	/**
	 * Whether a product holds, across its offers, every chosen value of each
	 * attribute whose values combine with `and`, as WooCommerce's own
	 * reading of such a filter keeps it in the listing.
	 *
	 * @param WC_Product               $product A product.
	 * @param array<string, list<int>> $chosen  Chosen term ids by taxonomy.
	 * @param string[]                 $all     The taxonomies whose values combine with `and`.
	 * @phpstan-param list<string> $all
	 */
	private static function holds_all( WC_Product $product, array $chosen, array $all ): bool {
		foreach ( $all as $taxonomy ) {
			if ( array() !== array_diff( $chosen[ $taxonomy ] ?? array(), self::values_held( $product, $taxonomy ) ) ) {
				return false;
			}
		}
		return true;
	}

	/**
	 * How many times a filtered listing shows a product: once per card it
	 * keeps, or once when the product is listed once.
	 *
	 * @param WC_Product               $product A product.
	 * @param array<string, list<int>> $chosen  Chosen term ids by taxonomy.
	 * @param float|null               $min     The lowest price chosen, as stored.
	 * @param float|null               $max     The highest price chosen, as stored.
	 * @param bool                     $one     Whether the store lists each product once.
	 */
	private static function shown( WC_Product $product, array $chosen, ?float $min, ?float $max, bool $one ): int {
		$left_out = self::left_out( $product, $chosen, $min, $max );
		if ( null === $left_out ) {
			return 0;
		}
		$keys = $one ? array() : array_keys( SplitCards::sold_by_card( $product ) );
		if ( array() === $keys ) {
			return 1;
		}
		$not_live = in_array( $product->get_id(), NotLiveProducts::candidate_ids(), true ) ? SplitCards::not_live_cards( $product ) : array();
		$listed   = self::listed_taxonomies( $keys );
		$kept     = 0;
		foreach ( array_diff( array_map( 'strval', $keys ), $left_out, $not_live ) as $key ) {
			$held = SplitCards::term_ids( $key );
			foreach ( $chosen as $taxonomy => $ids ) {
				// A filter on an attribute the product is listed by keeps the cards holding a chosen value.
				if ( isset( $listed[ $taxonomy ] ) && array() === array_intersect( $held, $ids ) ) {
					continue 2;
				}
			}
			++$kept;
		}
		return $kept;
	}

	/**
	 * The attributes a product's cards hold values of.
	 *
	 * @param array<int|string> $keys The product's card keys.
	 * @return array<string, true> By taxonomy.
	 */
	private static function listed_taxonomies( array $keys ): array {
		$listed = array();
		foreach ( $keys as $key ) {
			foreach ( SplitCards::term_ids( (string) $key ) as $term_id ) {
				$term = get_term( $term_id );
				if ( $term instanceof \WP_Term ) {
					$listed[ $term->taxonomy ] = true;
				}
			}
		}
		return $listed;
	}

	/**
	 * The values of an attribute any of a product's offers holds.
	 *
	 * @param WC_Product $product  A product.
	 * @param string     $taxonomy The attribute's taxonomy.
	 * @return list<int> Term ids.
	 */
	private static function values_held( WC_Product $product, string $taxonomy ): array {
		$held = array();
		foreach ( FilterOffers::of( $product ) as $offer ) {
			$held = array_merge( $held, $offer['values'][ $taxonomy ] ?? array() );
		}
		return array_values( array_unique( $held ) );
	}

	/**
	 * The products a listing's query finds before its attribute and price
	 * filters, which the counts apply themselves.
	 *
	 * @param array<array-key, mixed> $query_vars The listing's query.
	 * @return list<int>
	 */
	private static function listed( array $query_vars ): array {
		foreach ( array_keys( $query_vars ) as $name ) {
			if ( null !== self::attribute_of( $name, self::FILTER_PREFIX ) || null !== self::attribute_of( $name, self::QUERY_TYPE_PREFIX ) || self::MIN_PRICE === $name || self::MAX_PRICE === $name ) {
				unset( $query_vars[ $name ] );
			}
		}
		$query_vars['fields']         = 'ids';
		$query_vars['posts_per_page'] = -1;
		$query_vars['nopaging']       = true;
		$query_vars['no_found_rows']  = true;
		// WooCommerce's own clauses for the block's other filters, such as stock and taxonomies; attributes and price are gone from the query.
		$clauses = null;
		if ( class_exists( self::QUERY_CLAUSES ) && function_exists( 'wc_get_container' ) ) {
			/** @var class-string<object> $class */
			$class   = self::QUERY_CLAUSES;
			$clauses = array( wc_get_container()->get( $class ), 'add_query_clauses' );
		}
		if ( is_callable( $clauses ) ) {
			add_filter( 'posts_clauses', $clauses, 10, 2 );
		}
		$ids = ( new WP_Query() )->query( $query_vars );
		if ( is_callable( $clauses ) ) {
			remove_filter( 'posts_clauses', $clauses, 10 );
		}
		return array_values( array_map( static fn ( mixed $id ): int => is_numeric( $id ) ? (int) $id : 0, $ids ) );
	}

	/**
	 * The values a query's filters chose, by the attribute each is on.
	 *
	 * @param array<array-key, mixed> $query_vars A query.
	 * @return array<string, list<int>> Term ids by taxonomy.
	 */
	private static function chosen_in( array $query_vars ): array {
		$chosen = array();
		foreach ( $query_vars as $name => $value ) {
			$taxonomy = self::attribute_of( $name, self::FILTER_PREFIX );
			if ( null !== $taxonomy && is_string( $value ) && '' !== $value ) {
				$chosen[ $taxonomy ] = self::term_ids( $taxonomy, array_map( 'sanitize_title', explode( ',', $value ) ) );
			}
		}
		return $chosen;
	}

	/**
	 * The attributes whose chosen values a query combines with `and`.
	 *
	 * @param array<array-key, mixed> $query_vars A query.
	 * @return list<string> Taxonomies.
	 */
	private static function all_values_in( array $query_vars ): array {
		$all = array();
		foreach ( $query_vars as $name => $value ) {
			$taxonomy = self::attribute_of( $name, self::QUERY_TYPE_PREFIX );
			if ( null !== $taxonomy && is_string( $value ) && self::ALL_VALUES === strtolower( $value ) ) {
				$all[] = $taxonomy;
			}
		}
		return $all;
	}

	/**
	 * The global attribute a query argument names after a prefix; null for
	 * any other argument, such as the stock filter's or a taxonomy's.
	 *
	 * @param int|string $name   A query argument's name.
	 * @param string     $prefix `filter_` or `query_type_`.
	 */
	private static function attribute_of( int|string $name, string $prefix ): ?string {
		if ( ! is_string( $name ) || ! str_starts_with( $name, $prefix ) ) {
			return null;
		}
		$attribute = wc_sanitize_taxonomy_name( substr( $name, strlen( $prefix ) ) );
		$taxonomy  = wc_attribute_taxonomy_name( $attribute );
		return taxonomy_exists( $taxonomy ) && 0 !== wc_attribute_taxonomy_id_by_name( $attribute ) ? $taxonomy : null;
	}

	/**
	 * Terms of an attribute by their slugs.
	 *
	 * @param string   $taxonomy The attribute's taxonomy.
	 * @param string[] $slugs    Slugs.
	 * @phpstan-param list<string> $slugs
	 * @return list<int>
	 */
	private static function term_ids( string $taxonomy, array $slugs ): array {
		$ids = array() === $slugs ? array() : get_terms(
			array(
				'taxonomy'   => $taxonomy,
				'slug'       => $slugs,
				'hide_empty' => false,
				'fields'     => 'ids',
			)
		);
		return is_array( $ids ) ? array_values( array_unique( array_map( 'intval', $ids ) ) ) : array();
	}

	/**
	 * A price range from its query arguments, as the stored prices compare with it.
	 *
	 * @param string|null $min The lowest price's argument.
	 * @param string|null $max The highest price's argument.
	 * @return array{0: float|null, 1: float|null}
	 */
	private static function range( ?string $min, ?string $max ): array {
		$bound = static fn ( ?string $value ): ?float => null === $value || '' === $value ? null : FilterOffers::stored_price( (float) $value );
		return array( $bound( $min ), $bound( $max ) );
	}
}
