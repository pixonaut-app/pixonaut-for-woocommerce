<?php
/**
 * The shop's listings showing a split product once per value.
 *
 * @package Pixonaut\WooCommerce
 */

declare(strict_types=1);

namespace Pixonaut\WooCommerce\Storefront;

use Pixonaut\WooCommerce\Catalogue\ArchiveCards;
use Pixonaut\WooCommerce\Catalogue\ArchiveTerms;
use Pixonaut\WooCommerce\Catalogue\CardPages;
use Pixonaut\WooCommerce\Catalogue\FilterIndex;
use Pixonaut\WooCommerce\Catalogue\NotLiveProducts;
use Pixonaut\WooCommerce\Catalogue\SplitCardLooks;
use Pixonaut\WooCommerce\Catalogue\SplitCards;
use WC_Product;
use WC_Query;
use WeakMap;
use WP_Post;
use WP_Query;
use WP_Term;

/**
 * The shop, its category, tag and attribute pages and product search list
 * a split product (`SplitCards`) once per card: a value of the attribute it
 * is listed by, or a combination of the values of several. Their main query joins the
 * product's card rows, so it returns the product once per card, and the
 * count, the pages and the sorting take each card as a product: a classic
 * theme's loop and a block theme's Product Collection, which inherits that
 * query, both show them. Any other product query (related products, a
 * Product Collection with a query of its own, the admin's) lists a product
 * once.
 *
 * An attribute the product lists a card per value of only while filtered
 * makes cards only while a filter chooses a value of it: a listing shows
 * the product's cards holding values of exactly the filtered attributes a
 * filter is on (`SplitCards::filtered_taxonomies()`). Every card keeps its
 * page (`CardPages`). While several values of such an attribute are chosen,
 * the product's cards of one design show once (`CardDesigns`), and a theme
 * asks for the others as the card's swatches, on
 * `pixonaut_wc_storefront_card_swatches` (`swatches()`).
 *
 * Each card links to the product with its values chosen, and, while a
 * filter is on, with the variation the filters chose (`FilteredOpening`),
 * a product shown as itself too. A filter by an
 * attribute the product is listed by keeps only the cards holding a chosen
 * value, and a card whose variations are all on a template that is not
 * live is left out. While an attribute or price filter is on, a product
 * none of whose variations matches every filter at once is left out, and so
 * is a card none of whose variations does (`FilterMatches`). A card row that names no card of the product any more
 * (rows written before the product's cards changed, until they are
 * written again) shows the product as itself, and has its rows written.
 */
final class SplitCardListings {

	/**
	 * What a theme asks for the swatches of the card its loop shows.
	 */
	public const SWATCHES_FILTER = 'pixonaut_wc_storefront_card_swatches';

	/**
	 * The joined card rows' alias.
	 */
	private const ALIAS = 'pixonaut_split_card';

	/**
	 * The column holding a row's card value.
	 */
	private const FIELD = 'pixonaut_wc_split_term';

	/**
	 * After WooCommerce's own clauses, such as its price sort, so a card's
	 * order comes last.
	 */
	private const CLAUSES_PRIORITY = 100;

	/**
	 * The key no card has: a product listed once.
	 */
	private const NO_CARD = '';

	/**
	 * In a block's key, between the term ids of a card's key.
	 */
	private const BLOCK_KEY_SEPARATOR = '-';

	/**
	 * Each listed post's card key, by the post object the listing holds.
	 *
	 * @var WeakMap<WP_Post, string>
	 */
	private WeakMap $cards;

	/**
	 * The product and key of the card being shown, while one is.
	 *
	 * @var array{0: int, 1: string}|null
	 */
	private ?array $current = null;

	/**
	 * The posts of the listing's results, by the post object it holds.
	 *
	 * @var WeakMap<WP_Post, true>
	 */
	private WeakMap $listed;

	/**
	 * The product a listing's loop shows now, while it shows one; its
	 * card is `current`, when it shows a card of it.
	 *
	 * @var int|null
	 */
	private ?int $listed_id = null;

	/**
	 * Of each product several of whose cards the listing shows as one
	 * design (`CardDesigns`), the other cards of each design shown, by
	 * product id and the shown card's key.
	 *
	 * @var array<int, array<string, list<string>>>
	 */
	private array $designs = array();

	/**
	 * The cards a Product Template block has shown so far, in order, `NO_CARD`
	 * for a product listed once.
	 *
	 * @var list<array{0: int, 1: string}>
	 */
	private array $shown = array();

	public function __construct() {
		$this->cards  = new WeakMap();
		$this->listed = new WeakMap();
	}

	public function register(): void {
		add_filter( 'posts_clauses', array( $this, 'clauses' ), self::CLAUSES_PRIORITY, 2 );
		add_filter( 'posts_results', array( $this, 'results' ), 10, 2 );
		add_action( 'the_post', array( $this, 'enter' ), 10, 2 );
		add_action( 'loop_end', array( $this, 'leave' ) );
		add_filter( 'post_type_link', array( $this, 'link' ), 10, 2 );
		add_filter( 'pre_render_block', array( $this, 'start_template' ), 10, 2 );
		add_filter( 'render_block_woocommerce/product-template', array( $this, 'keys' ) );
		add_filter( self::SWATCHES_FILTER, array( $this, 'swatches' ), 10, 2 );
	}

	/**
	 * Whether a query is a listing of the shop's.
	 *
	 * @param WP_Query $query A query.
	 */
	private static function lists_cards( WP_Query $query ): bool {
		return ! is_admin() && $query->is_main_query() && 'product_query' === $query->get( 'wc_query' );
	}

	/**
	 * A listing's query, joined to its products' card rows.
	 *
	 * @param mixed $clauses The query's clauses.
	 * @param mixed $query   The query.
	 * @return mixed
	 */
	public function clauses( mixed $clauses, mixed $query ): mixed {
		$alias = self::ALIAS;
		if ( ! is_array( $clauses ) || ! $query instanceof WP_Query || ! self::lists_cards( $query ) || str_contains( (string) ( $clauses['join'] ?? '' ), " AS {$alias} " ) ) {
			return $clauses;
		}
		$chosen        = FilterMatches::chosen();
		$left          = self::left_out( $clauses, $chosen );
		$this->designs = $left['designs'];
		global $wpdb;
		if ( array() !== $left['products'] ) {
			$clauses['where'] = ( $clauses['where'] ?? '' ) . " AND {$wpdb->posts}.ID NOT IN ( " . implode( ', ', array_map( 'intval', $left['products'] ) ) . ' )'; // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- a table name and integers.
		}
		$clauses['join']    = ( $clauses['join'] ?? '' ) . $wpdb->prepare( " LEFT JOIN {$wpdb->postmeta} AS {$alias} ON ( {$alias}.post_id = {$wpdb->posts}.ID AND {$alias}.meta_key = %s )", SplitCards::META ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table names and a constant alias.
		$clauses['fields']  = ( $clauses['fields'] ?? '' ) . ", {$alias}.meta_value AS " . self::FIELD;
		$clauses['groupby'] = '' === trim( (string) ( $clauses['groupby'] ?? '' ) ) ? '' : $clauses['groupby'] . ", {$alias}.meta_id";
		$clauses['orderby'] = '' === trim( (string) ( $clauses['orderby'] ?? '' ) ) ? "{$alias}.meta_id ASC" : $clauses['orderby'] . ", {$alias}.meta_id ASC";
		// A card is left out while it is not live, or while no variation it sells matches the filters.
		$hidden = self::not_live_cards();
		foreach ( $left['cards'] as $product_id => $keys ) {
			$hidden[ $product_id ] = array_values( array_unique( array_merge( $hidden[ $product_id ] ?? array(), $keys ) ) );
		}
		$clauses['where'] .= self::filtered_cards( $chosen, FilterMatches::exempt( $chosen ), $alias ) . self::unfiltered_levels( $chosen, $alias ) . self::shown_cards( $hidden, $alias );
		return $clauses;
	}

	/**
	 * What the listing's attribute and price filters leave out, reading
	 * each variation on its own (`FilterMatches`); nothing while none is on.
	 *
	 * @param array<array-key, mixed>  $clauses The listing's clauses.
	 * @param array<string, list<int>> $chosen  The filters' values, as `FilterMatches::chosen()` reads them.
	 * @return array{products: list<int>, cards: array<int, list<string>>, designs: array<int, array<string, list<string>>>}
	 */
	private static function left_out( array $clauses, array $chosen ): array {
		list( $min, $max ) = FilterMatches::price_range();
		if ( array() === $chosen && null === $min && null === $max ) {
			return array(
				'products' => array(),
				'cards'    => array(),
				'designs'  => array(),
			);
		}
		return FilterMatches::left_out_of_listing( (string) ( $clauses['join'] ?? '' ), (string) ( $clauses['where'] ?? '' ), $chosen, $min, $max );
	}

	/**
	 * The condition a filtered listing keeps a card row by, for each
	 * attribute a filter is on: the card holds a value the filter chose, or
	 * none of the product's cards holds a value of that attribute, so the
	 * product is not listed by it. While the filter is waived under some
	 * values (`FilterMatches::exempt()`), a card holding no value of the
	 * attribute is kept too: the attribute does not apply to what it sells,
	 * and its variations decide (`FilterMatches::left_out_of_listing()`). A
	 * product listed once has no card and is kept. Empty without a filter.
	 *
	 * @param array<string, list<int>> $chosen The filters' values, as `FilterMatches::chosen()` reads them.
	 * @param array<string, list<int>> $exempt The values each filter is waived under (`FilterMatches::exempt()`).
	 * @param string                   $alias  The card rows' alias.
	 */
	private static function filtered_cards( array $chosen, array $exempt, string $alias ): string {
		global $wpdb;
		$where = '';
		foreach ( $chosen as $taxonomy => $ids ) {
			// A key is the set of its term ids. No chosen id names a term: no card holds one.
			$holds_chosen = array() === $ids ? '1 = 0' : implode( ' OR ', array_fill( 0, count( $ids ), "FIND_IN_SET( %d, {$alias}.meta_value )" ) );
			if ( array() !== ( $exempt[ $taxonomy ] ?? array() ) ) {
				$holds_chosen .= $wpdb->prepare( " OR NOT EXISTS ( SELECT 1 FROM {$wpdb->term_taxonomy} AS own WHERE FIND_IN_SET( own.term_id, {$alias}.meta_value ) AND own.taxonomy = %s )", $taxonomy ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table names and a constant alias.
			}
			// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- table names, a constant alias and a placeholder per value.
			$kept = $wpdb->prepare(
				" AND ( {$alias}.meta_value IS NULL OR {$holds_chosen} OR NOT EXISTS ( SELECT 1 FROM {$wpdb->postmeta} AS listed INNER JOIN {$wpdb->term_taxonomy} AS held ON FIND_IN_SET( held.term_id, listed.meta_value ) WHERE listed.post_id = {$wpdb->posts}.ID AND listed.meta_key = %s AND held.taxonomy = %s ) )",
				...$ids,
				...array( SplitCards::META, $taxonomy )
			);
			// phpcs:enable
			$where .= is_string( $kept ) ? $kept : '';
		}
		return $where;
	}

	/**
	 * The condition leaving out the card rows holding a value of an
	 * attribute the product lists a card per value of only while filtered,
	 * while no filter is on it. With `filtered_cards()`, which keeps only
	 * the rows holding a chosen value of each attribute a filter is on, a
	 * product shows the cards of exactly the filtered attributes a filter
	 * is on.
	 *
	 * @param array<string, list<int>> $chosen The filters' values, as `FilterMatches::chosen()` reads them.
	 * @param string                   $alias  The card rows' alias.
	 */
	private static function unfiltered_levels( array $chosen, string $alias ): string {
		global $wpdb;
		$taxonomies = array_keys( $chosen );
		// A row is `<taxonomy>:<term id>`, one per value the product's cards hold.
		$not_chosen = array() === $taxonomies ? '' : ' AND SUBSTRING_INDEX( filtered.meta_value, %s, 1 ) NOT IN ( ' . implode( ', ', array_fill( 0, count( $taxonomies ), '%s' ) ) . ' )';
		$args       = array() === $taxonomies ? array() : array( SplitCards::FILTERED_SEPARATOR, ...$taxonomies );
		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- table names, a constant alias and a placeholder per taxonomy.
		$left = $wpdb->prepare(
			" AND ( {$alias}.meta_value IS NULL OR NOT EXISTS ( SELECT 1 FROM {$wpdb->postmeta} AS filtered WHERE filtered.post_id = {$wpdb->posts}.ID AND filtered.meta_key = %s AND FIND_IN_SET( SUBSTRING_INDEX( filtered.meta_value, %s, -1 ), {$alias}.meta_value ){$not_chosen} ) )",
			SplitCards::FILTERED_META,
			SplitCards::FILTERED_SEPARATOR,
			...$args
		);
		// phpcs:enable
		return is_string( $left ) ? $left : '';
	}

	/**
	 * The condition leaving out the card rows of cards that are not shown.
	 *
	 * @param array<int, list<string>> $hidden Card keys by product id.
	 * @param string                   $alias  The card rows' alias.
	 */
	private static function shown_cards( array $hidden, string $alias ): string {
		global $wpdb;
		$where = '';
		foreach ( $hidden as $product_id => $keys ) {
			$placeholders = implode( ', ', array_fill( 0, count( $keys ), '%s' ) );
			// By the product's own id: a product without card rows has none to compare.
			// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- a table name, a constant alias and a placeholder per key.
			$left = $wpdb->prepare( " AND NOT ( {$wpdb->posts}.ID = %d AND {$alias}.meta_value IN ( {$placeholders} ) )", $product_id, ...$keys );
			// phpcs:enable
			$where .= is_string( $left ) ? $left : '';
		}
		return $where;
	}

	/**
	 * The cards left out because each variation offered in them is on a
	 * template that is not live, by product. Only the products
	 * `NotLiveProducts` names as candidates are read.
	 *
	 * @return array<int, list<string>> Card keys by product id.
	 */
	private static function not_live_cards(): array {
		$candidates = NotLiveProducts::candidate_ids();
		if ( array() === $candidates ) {
			return array();
		}
		update_meta_cache( 'post', $candidates );
		$cards = array();
		foreach ( $candidates as $product_id ) {
			if ( array() === get_post_meta( $product_id, SplitCards::META, false ) ) {
				continue;
			}
			$product = wc_get_product( $product_id );
			$keys    = $product instanceof WC_Product ? SplitCards::not_live_cards( $product ) : array();
			if ( array() !== $keys ) {
				$cards[ $product_id ] = $keys;
			}
		}
		return $cards;
	}

	/**
	 * Keeps each listed post's card key beside it, off the post: WordPress
	 * caches the post object, which other queries share.
	 *
	 * @param mixed $posts The posts found.
	 * @param mixed $query The query.
	 * @return mixed
	 */
	public function results( mixed $posts, mixed $query ): mixed {
		if ( ! is_array( $posts ) || ! $query instanceof WP_Query || ! self::lists_cards( $query ) ) {
			return $posts;
		}
		$carded = array();
		foreach ( $posts as $post ) {
			if ( $post instanceof WP_Post ) {
				$this->listed[ $post ] = true;
			}
			if ( ! $post instanceof WP_Post || ! property_exists( $post, self::FIELD ) ) {
				continue;
			}
			$key = $post->{self::FIELD};
			unset( $post->{self::FIELD} );
			if ( SplitCards::is_key( $key ) ) {
				$this->cards[ $post ] = (string) $key;
				$carded[]             = $post->ID;
			}
		}
		// Each card's link reads its product's variations.
		FilterIndex::look_up( $carded );
		return $posts;
	}

	/**
	 * Notes the card a loop moves to. A query sets its post up again out of
	 * its loop too, as `wp_reset_postdata()` does after another query: no
	 * card is shown then.
	 *
	 * @param mixed $post  The post.
	 * @param mixed $query The query whose post it is.
	 */
	public function enter( mixed $post, mixed $query = null ): void {
		if ( $query instanceof WP_Query && ! $query->in_the_loop ) {
			$this->current   = null;
			$this->listed_id = null;
			return;
		}
		if ( ! $post instanceof WP_Post ) {
			return;
		}
		$key             = $this->current_key( $post );
		$this->current   = self::NO_CARD === $key ? null : array( $post->ID, $key );
		$this->listed_id = isset( $this->listed[ $post ] ) || null !== $this->current ? $post->ID : null;
		$this->shown[]   = array( $post->ID, $key );
	}

	/**
	 * The key of a listed post's card that is one of its product's cards
	 * now; `NO_CARD` for a product listed once. A row naming a card the
	 * product no longer has is from before its cards changed: the product's
	 * rows are written again, and it is shown as itself this once.
	 *
	 * @param WP_Post $post A listed post.
	 */
	private function current_key( WP_Post $post ): string {
		$key = $this->cards[ $post ] ?? self::NO_CARD;
		if ( self::NO_CARD === $key ) {
			return $key;
		}
		$product = wc_get_product( $post->ID );
		if ( $product instanceof WC_Product && SplitCards::has_card( $product, $key ) ) {
			return $key;
		}
		SplitCards::write( $post->ID );
		return self::NO_CARD;
	}

	/**
	 * The product and key of the card a loop shows now; null while it
	 * shows none.
	 *
	 * @return array{0: int, 1: string}|null
	 */
	public function current(): ?array {
		return $this->current;
	}

	/**
	 * Forgets the card once its loop ends.
	 */
	public function leave(): void {
		$this->current   = null;
		$this->listed_id = null;
	}

	/**
	 * A card's links to its product, with its values chosen, and with the
	 * variation the listing's filters chose while one is on.
	 *
	 * @param mixed $url  The product's address.
	 * @param mixed $post The product.
	 * @return mixed
	 */
	public function link( mixed $url, mixed $post ): mixed {
		if ( ! is_string( $url ) || ! $post instanceof WP_Post || $post->ID !== $this->listed_id ) {
			return $url;
		}
		$product = wc_get_product( $post->ID );
		if ( ! $product instanceof WC_Product ) {
			return $url;
		}
		return self::card_link( $product, null !== $this->current && $this->current[0] === $post->ID ? $this->current[1] : self::NO_CARD, $url );
	}

	/**
	 * A card's link: the product's address with the card's values, and the
	 * values of the variation the filters chose while one is on.
	 *
	 * @param WC_Product $product A product the listing shows.
	 * @param string     $key     Its card's key; `NO_CARD` for the product shown as itself.
	 * @param string     $url     The product's address.
	 */
	private static function card_link( WC_Product $product, string $key, string $url ): string {
		$link    = self::NO_CARD === $key ? $url : CardPages::url( $product, $key, $url );
		$opening = FilteredOpening::values( $product, $key );
		// Without a filter on, the card's link is its own; its values are read only to leave them out of the filters' values.
		if ( array() === $opening ) {
			return $link;
		}
		$more = array_diff_key( $opening, SplitCards::link_values( $product, $key ) );
		return array() === $more ? $link : QueryNames::shorten( add_query_arg( array_map( 'rawurlencode', $more ), $link ) );
	}

	/**
	 * The swatches of the card the loop shows: the card itself, marked
	 * current, then the product's other cards the listing shows as the same
	 * design (`CardDesigns`); none for a design of one card. Each with its
	 * values of the attributes the design's cards differ by (each
	 * attribute's key, the value's slug and its name; a card holding none
	 * of those, all its values; one holding none at all, the chosen values
	 * it opens on), and its title, link and picture, as the card would show
	 * them.
	 *
	 * @param mixed $swatches What another callback answered.
	 * @param mixed $product  The product the theme draws a card of.
	 * @return mixed `list<array{values: list<array{key: string, id: string, label: string}>, title: string, url: string, image: array{src: string, srcset: string, width: int, height: int, alt: string}, current: bool}>`
	 */
	public function swatches( mixed $swatches, mixed $product ): mixed {
		if ( ! $product instanceof WC_Product || null === $this->current || $this->current[0] !== $product->get_id() ) {
			return $swatches;
		}
		$others = $this->designs[ $product->get_id() ][ $this->current[1] ] ?? array();
		if ( array() === $others ) {
			return $swatches;
		}
		$terms = new ArchiveTerms();
		$size  = ArchiveCards::image_size();
		// The product's own address, not the shown card's.
		$listed          = $this->listed_id;
		$this->listed_id = null;
		$url             = (string) get_permalink( $product->get_id() );
		$this->listed_id = $listed;
		$answer          = is_array( $swatches ) ? $swatches : array();
		$keys            = array_merge( array( $this->current[1] ), $others );
		$held            = array();
		foreach ( $keys as $key ) {
			$held[ $key ] = array();
			foreach ( SplitCards::card_terms( $product, $key ) as $term_id ) {
				$term = get_term( $term_id );
				if ( $term instanceof WP_Term ) {
					$held[ $key ][ $term->taxonomy ][] = $term;
				}
			}
		}
		// The attributes the design's cards differ by: a swatch names its card by these.
		$taxonomies = array_unique( array_merge( ...array_map( 'array_keys', array_values( $held ) ) ) );
		$differing  = array_values(
			array_filter(
				$taxonomies,
				static fn ( string $taxonomy ): bool => 1 < count( array_unique( array_map( static fn ( array $terms ): string => implode( SplitCards::KEY_SEPARATOR, wp_list_pluck( $terms[ $taxonomy ] ?? array(), 'term_id' ) ), $held ) ) )
			)
		);
		foreach ( $keys as $key ) {
			$values = array();
			// A card holding none of them, as one a waived filter keeps beside its colours, is named by the values it holds.
			$naming = array() === array_intersect( $differing, array_keys( $held[ $key ] ) ) ? array_keys( $held[ $key ] ) : $differing;
			foreach ( $naming as $taxonomy ) {
				foreach ( $held[ $key ][ $taxonomy ] ?? array() as $term ) {
					$values[] = array(
						'key'   => $terms->key( $taxonomy ),
						'id'    => $term->slug,
						'label' => html_entity_decode( $term->name, ENT_QUOTES, 'UTF-8' ),
					);
				}
			}
			if ( array() === $values ) {
				$values = self::opening_values( $product, $key, $terms );
			}
			$own      = SplitCardLooks::of( $product->get_id(), $key )['title'];
			$image    = CardPages::image( $product, $key, FilteredOpening::values( $product, $key ), null, SplitCardFaces::picks_beyond( $product, $key ) );
			$answer[] = array(
				'values'  => $values,
				'title'   => '' !== $own ? $own : $product->get_name(),
				'url'     => self::card_link( $product, $key, $url ),
				'image'   => ArchiveCards::image( $image > 0 ? $image : (int) $product->get_image_id( 'edit' ), $size ),
				'current' => $key === $this->current[1],
			);
		}
		return $answer;
	}

	/**
	 * The values of the chosen attributes a card holding none opens on:
	 * the product shown as itself, kept by a waived filter beside its
	 * cards of the chosen values, is named by the variation the filters
	 * open it on (a bare print, say).
	 *
	 * @param WC_Product   $product A split product.
	 * @param string       $key     One of its cards.
	 * @param ArchiveTerms $terms   How attributes are named.
	 * @return list<array{key: string, id: string, label: string}>
	 */
	private static function opening_values( WC_Product $product, string $key, ArchiveTerms $terms ): array {
		$chosen = FilterMatches::chosen();
		$values = array();
		foreach ( FilteredOpening::values( $product, $key ) as $field => $slug ) {
			$taxonomy = substr( $field, strlen( QueryNames::FIELD_PREFIX ) );
			$term     = isset( $chosen[ $taxonomy ] ) ? get_term_by( 'slug', $slug, $taxonomy ) : false;
			if ( $term instanceof WP_Term ) {
				$values[] = array(
					'key'   => $terms->key( $taxonomy ),
					'id'    => $term->slug,
					'label' => html_entity_decode( $term->name, ENT_QUOTES, 'UTF-8' ),
				);
			}
		}
		return $values;
	}

	/**
	 * Starts counting the cards a Product Template block shows.
	 *
	 * @param mixed $pre    Markup another filter rendered the block as, or null.
	 * @param mixed $parsed The block.
	 * @return mixed
	 */
	public function start_template( mixed $pre, mixed $parsed ): mixed {
		if ( is_array( $parsed ) && 'woocommerce/product-template' === ( $parsed['blockName'] ?? null ) ) {
			$this->shown = array();
		}
		return $pre;
	}

	/**
	 * Tells a Product Template block's cards of one product apart for the
	 * Interactivity API, which keys each card by its product.
	 *
	 * @param mixed $content The block's markup.
	 * @return mixed
	 */
	public function keys( mixed $content ): mixed {
		$shown       = $this->shown;
		$this->shown = array();
		if ( ! is_string( $content ) ) {
			return $content;
		}
		return preg_replace_callback(
			'/data-wp-key="product-item-(\d+)"/',
			static function ( array $found ) use ( &$shown ): string {
				foreach ( $shown as $index => $card ) {
					if ( $card[0] === (int) $found[1] ) {
						unset( $shown[ $index ] );
						return self::NO_CARD === $card[1] ? $found[0] : 'data-wp-key="product-item-' . $card[0] . '-' . str_replace( SplitCards::KEY_SEPARATOR, self::BLOCK_KEY_SEPARATOR, $card[1] ) . '"';
					}
				}
				return $found[0];
			},
			$content
		) ?? $content;
	}
}
