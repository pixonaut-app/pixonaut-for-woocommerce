<?php
/**
 * The shop's filters, matched against one offer at a time.
 *
 * @package Pixonaut\WooCommerce
 */

declare(strict_types=1);

namespace Pixonaut\WooCommerce\Storefront;

use Pixonaut\WooCommerce\Catalogue\FilterIndex;
use Pixonaut\WooCommerce\Catalogue\FilterOffers;
use Pixonaut\WooCommerce\Catalogue\NotLiveProducts;
use Pixonaut\WooCommerce\Catalogue\SplitCards;
use WC_Cache_Helper;
use WC_Product;
use WC_Query;
use WP_Query;
use WP_Term;

/**
 * WooCommerce reads each attribute filter, and the price filter, on its
 * own, against a product as a whole: a poster sold bare, and framed in oak,
 * passes "bare" with "oak", though nothing it sells is both. The shop's
 * listings keep a product, and each of its cards (`SplitCardListings`),
 * only while one of its offers (`FilterOffers`) holds every chosen value and
 * costs within the chosen range; a card's offers are the variations it
 * sells.
 *
 * Values chosen of one attribute combine with "or", attributes with
 * "and". A dependent attribute, which applies only under some values of
 * another (`AttributeDependencies`), filters only what it applies to: with
 * "hanger" and "no frame" chosen, a frame colour keeps the bare prints and
 * the hangers in that colour (`exempt()`).
 *
 * The Product Filters block's counts follow the same rule: each value's
 * count is what the listing would show with that value chosen on top of
 * the current filters, its own attribute's chosen values included,
 * counting a product once per card it would be listed as. WooCommerce asks for them through `woocommerce_pre_product_filter_data`,
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
	 * The query type under which any chosen value of an attribute is enough.
	 */
	private const ANY_VALUE = 'or';

	/**
	 * Prefix of the transient each query's counts are kept in.
	 */
	private const COUNTS_TRANSIENT = 'pixonaut_wc_filter_counts_';

	/**
	 * How long counts are kept: they are read again sooner once a product
	 * changes, and a template going live or paused shows within this time:
	 * an hour, in seconds.
	 */
	private const COUNTS_LIFETIME = 3600;

	/**
	 * The price filter's query arguments.
	 */
	private const MIN_PRICE = 'min_price';

	private const MAX_PRICE = 'max_price';

	public function register(): void {
		add_filter( 'woocommerce_pre_product_filter_data', array( $this, 'counts' ), self::COUNTS_PRIORITY, 4 );
		// Values chosen of one attribute combine with "or" unless the address asks for "and", as the Product Filters block's do.
		add_filter( 'woocommerce_layered_nav_default_query_type', static fn (): string => self::ANY_VALUE );
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
	 * @param array<string, list<int>> $exempt  The values each filter is waived under (`exempt()`); worked out from the chosen ones when null.
	 * @return list<string>|null
	 */
	public static function left_out( WC_Product $product, array $chosen, ?float $min, ?float $max, ?array $exempt = null ): ?array {
		return self::left_out_of( FilterOffers::of( $product ), SplitCards::sold_by_card( $product ), $chosen, $min, $max, $exempt ?? self::exempt( $chosen ) );
	}

	/**
	 * The values under which each chosen attribute's filter passes what it
	 * does not apply to: for a dependent attribute, the chosen values of the
	 * attribute it depends on that it does not apply under, as the store's
	 * variations say (`AttributeDependencies::store()`), never by name. A
	 * frame colour chosen beside "no frame" keeps what is sold bare; with no
	 * framing chosen it keeps only what comes in that colour.
	 *
	 * @param array<string, list<int>> $chosen Chosen term ids by taxonomy.
	 * @return array<string, list<int>> Term ids by the filtered attribute's taxonomy.
	 */
	public static function exempt( array $chosen ): array {
		// A dependent attribute is waived only through a chosen value of another.
		if ( count( $chosen ) < 2 ) {
			return array();
		}
		$dependencies = AttributeDependencies::store();
		$exempt       = array();
		foreach ( array_keys( $chosen ) as $taxonomy ) {
			foreach ( $dependencies[ $taxonomy ] ?? array() as $parent => $under ) {
				foreach ( $chosen[ $parent ] ?? array() as $term_id ) {
					$term = get_term( $term_id, $parent );
					if ( $term instanceof WP_Term && ! in_array( $term->slug, $under, true ) ) {
						$exempt[ $taxonomy ][] = $term->term_id;
					}
				}
			}
		}
		return $exempt;
	}

	/**
	 * Works out `left_out()` from a product's offers and its cards.
	 *
	 * @param list<array{id: int, values: array<string, list<int>>, min: float, max: float}> $offers The product's offers.
	 * @param array<int|string, list<int>>                                                   $cards  The variations each of its cards sells, by the card's key.
	 * @param array<string, list<int>>                                                       $chosen Chosen term ids by taxonomy.
	 * @param float|null                                                                     $min    The lowest price chosen, as stored.
	 * @param float|null                                                                     $max    The highest price chosen, as stored.
	 * @param array<string, list<int>>                                                       $exempt The values each filter is waived under (`exempt()`).
	 * @return list<string>|null
	 */
	private static function left_out_of( array $offers, array $cards, array $chosen, ?float $min, ?float $max, array $exempt ): ?array {
		$matching = array();
		foreach ( $offers as $offer ) {
			if ( FilterOffers::matches( $offer, $chosen, $min, $max, $exempt ) ) {
				$matching[] = $offer['id'];
			}
		}
		if ( array() === $matching ) {
			return null;
		}
		$left = array();
		foreach ( $cards as $key => $sold ) {
			if ( array() === array_intersect( $sold, $matching ) ) {
				$left[] = (string) $key;
			}
		}
		return $left;
	}

	/**
	 * What a filtered listing leaves out of the variable products its query
	 * finds, by WooCommerce's own reading of the filters: the products none
	 * of whose offers matches, and of the others the cards none of whose
	 * offers does. Any other product is one offer, which WooCommerce's
	 * reading already matches, unless a filter is waived under some values
	 * (`exempt()`): WooCommerce's query then admits what holds them
	 * (`DependentFilterQuery`), and every product is read.
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
		$exempt   = self::exempt( $chosen );
		$variable = array() === $exempt ? " AND {$wpdb->posts}.ID IN ( SELECT type_rel.object_id FROM {$wpdb->term_relationships} type_rel INNER JOIN {$wpdb->term_taxonomy} type_tax ON type_tax.term_taxonomy_id = type_rel.term_taxonomy_id INNER JOIN {$wpdb->terms} type_term ON type_term.term_id = type_tax.term_id WHERE type_tax.taxonomy = 'product_type' AND type_term.slug = 'variable' )" : '';
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- the listing's own clauses, which WP_Query prepared; read once a listing.
		$ids = $wpdb->get_col( "SELECT DISTINCT {$wpdb->posts}.ID FROM {$wpdb->posts} {$join} WHERE 1=1 {$where}{$variable}" );
		// phpcs:enable
		$ids  = array_values( array_map( 'intval', is_array( $ids ) ? $ids : array() ) );
		$left = array(
			'products' => array(),
			'cards'    => array(),
		);
		// What was kept of the products' variations since they last changed; the others, their meta and terms in three queries.
		FilterIndex::look_up( $ids );
		$unread = array_values( array_filter( $ids, static fn ( int $id ): bool => null === FilterOffers::worked_out( $id ) || ! isset( SplitCards::worked_out( $id )['cards'] ) ) );
		_prime_post_caches( $unread, true, true );
		foreach ( $ids as $id ) {
			if ( in_array( $id, $unread, true ) ) {
				$product = wc_get_product( $id );
				$cards   = $product instanceof WC_Product ? self::left_out( $product, $chosen, $min, $max, $exempt ) : array();
			} else {
				$cards = self::left_out_of( FilterOffers::worked_out( $id ) ?? array(), SplitCards::worked_out( $id )['cards'] ?? array(), $chosen, $min, $max, $exempt );
			}
			if ( null === $cards ) {
				$left['products'][] = $id;
			} elseif ( array() !== $cards ) {
				$left['cards'][ $id ] = $cards;
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
		$version = class_exists( WC_Cache_Helper::class ) ? (string) WC_Cache_Helper::get_transient_version( 'product' ) : '';
		$normal  = $query_vars;
		ksort( $normal );
		// WooCommerce leaves out the counted attribute's own filter when its values combine with "or": the request still has it.
		$own       = isset( self::chosen_in( $query_vars )[ $taxonomy ] ) ? array() : ( self::chosen()[ $taxonomy ] ?? array() );
		$transient = self::COUNTS_TRANSIENT . md5( (string) wp_json_encode( array( $normal, $taxonomy, $own, $version ) ) );
		$cached    = get_transient( $transient );
		if ( is_array( $cached ) ) {
			return $cached;
		}
		$counts = self::count( $query_vars, $taxonomy, $own );
		set_transient( $transient, $counts, self::COUNTS_LIFETIME );
		return $counts;
	}

	/**
	 * Works out `counts()`: for each value any listed product holds, the
	 * listing's cards with that value chosen on top of the filters.
	 *
	 * @param array<array-key, mixed> $query_vars The listing's query.
	 * @param string                  $taxonomy   The counted attribute.
	 * @param int[]                   $own        The counted attribute's chosen values the query leaves out.
	 * @phpstan-param list<int> $own
	 * @return array<int, int> Counts by term id.
	 */
	private static function count( array $query_vars, string $taxonomy, array $own ): array {
		$chosen = self::chosen_in( $query_vars );
		if ( ! isset( $chosen[ $taxonomy ] ) && array() !== $own ) {
			$chosen[ $taxonomy ] = $own;
		}
		$all      = self::all_values_in( $query_vars );
		$range    = self::range(
			is_scalar( $query_vars[ self::MIN_PRICE ] ?? null ) ? (string) $query_vars[ self::MIN_PRICE ] : null,
			is_scalar( $query_vars[ self::MAX_PRICE ] ?? null ) ? (string) $query_vars[ self::MAX_PRICE ] : null
		);
		$products = array_values( array_filter( array_map( 'wc_get_product', self::listed( $query_vars ) ), static fn ( mixed $product ): bool => $product instanceof WC_Product ) );
		$values   = array();
		foreach ( $products as $product ) {
			$values = array_merge( $values, self::values_held( $product, $taxonomy ) );
		}
		$with   = array();
		$exempt = array();
		foreach ( array_values( array_unique( $values ) ) as $term_id ) {
			$with[ $term_id ]              = $chosen;
			$with[ $term_id ][ $taxonomy ] = array_values( array_unique( array_merge( $chosen[ $taxonomy ] ?? array(), array( $term_id ) ) ) );
			$exempt[ $term_id ]            = self::exempt( $with[ $term_id ] );
		}
		// Adding a value to an attribute already chosen with "or" leaves a product not holding it as the filters list it.
		$widens = isset( $chosen[ $taxonomy ] ) && ! in_array( $taxonomy, $all, true );
		$counts = array();
		foreach ( $products as $product ) {
			$held = self::values_held( $product, $taxonomy );
			$base = null;
			foreach ( $with as $term_id => $filters ) {
				if ( in_array( $term_id, $held, true ) || isset( $exempt[ $term_id ][ $taxonomy ] ) ) {
					$shown = self::holds_all( $product, $filters, $all ) ? self::shown( $product, $filters, $range[0], $range[1], $exempt[ $term_id ] ) : 0;
				} elseif ( $widens ) {
					$base ??= self::holds_all( $product, $chosen, $all ) ? self::shown( $product, $chosen, $range[0], $range[1], self::exempt( $chosen ) ) : 0;
					$shown = $base;
				} else {
					continue;
				}
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
	 * @param array<string, list<int>> $exempt  The values each filter is waived under (`exempt()`).
	 */
	private static function shown( WC_Product $product, array $chosen, ?float $min, ?float $max, array $exempt ): int {
		$left_out = self::left_out( $product, $chosen, $min, $max, $exempt );
		if ( null === $left_out ) {
			return 0;
		}
		$keys = array_keys( SplitCards::sold_by_card( $product ) );
		if ( array() === $keys ) {
			return 1;
		}
		$not_live = in_array( $product->get_id(), NotLiveProducts::candidate_ids(), true ) ? SplitCards::not_live_cards( $product ) : array();
		$listed   = self::listed_taxonomies( $keys );
		$unchosen = array_diff( SplitCards::filtered_taxonomies( $product ), array_keys( $chosen ) );
		$kept     = 0;
		foreach ( array_diff( array_map( 'strval', $keys ), $left_out, $not_live ) as $key ) {
			$held = SplitCards::term_ids( $key );
			// A card holding a value of an attribute listed only while filtered shows only while a filter is on it.
			if ( array() !== array_intersect( array_keys( self::listed_taxonomies( array( $key ) ) ), $unchosen ) ) {
				continue;
			}
			foreach ( $chosen as $taxonomy => $ids ) {
				// A filter on an attribute the product is listed by keeps the cards holding a chosen value; while it is waived, those holding none of its values too.
				if ( isset( $listed[ $taxonomy ] ) && array() === array_intersect( $held, $ids ) && ( array() === ( $exempt[ $taxonomy ] ?? array() ) || isset( self::listed_taxonomies( array( $key ) )[ $taxonomy ] ) ) ) {
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
