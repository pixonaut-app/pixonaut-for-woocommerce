<?php
/**
 * The shop's listings showing a split product once per value.
 *
 * @package Pixonaut\WooCommerce
 */

declare(strict_types=1);

namespace Pixonaut\WooCommerce\Storefront;

use Pixonaut\WooCommerce\Catalogue\CardPages;
use Pixonaut\WooCommerce\Catalogue\NotLiveProducts;
use Pixonaut\WooCommerce\Settings\Settings;
use Pixonaut\WooCommerce\Catalogue\SplitCards;
use WC_Product;
use WC_Query;
use WeakMap;
use WP_Post;
use WP_Query;

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
 * With the store's "one card per product" setting on, a listing shows each
 * product once: as itself, or, while a filter is on an attribute the product
 * is listed by, as the first of its cards the filter keeps. Every card keeps
 * its page (`CardPages`) either way.
 *
 * Each card links to the product with its values chosen. A filter by an
 * attribute the product is listed by keeps only the cards holding a chosen
 * value, and a card whose variations are all on a template that is not
 * live is left out. A card row that names no card of the product any more
 * (rows written before the product's cards changed, until they are
 * written again) shows the product as itself, and has its rows written.
 */
final class SplitCardListings {

	/**
	 * The joined card rows' alias.
	 */
	private const ALIAS = 'pixonaut_split_card';

	/**
	 * The alias of the card rows the first card a listing shows of a
	 * product is looked for among.
	 */
	private const FIRST_ALIAS = 'pixonaut_first_card';

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
	 * The cards a Product Template block has shown so far, in order, `NO_CARD`
	 * for a product listed once.
	 *
	 * @var list<array{0: int, 1: string}>
	 */
	private array $shown = array();

	/**
	 * @param Settings $settings The settings.
	 */
	public function __construct( private readonly Settings $settings ) {
		$this->cards = new WeakMap();
	}

	public function register(): void {
		add_filter( 'posts_clauses', array( $this, 'clauses' ), self::CLAUSES_PRIORITY, 2 );
		add_filter( 'posts_results', array( $this, 'results' ), 10, 2 );
		add_action( 'the_post', array( $this, 'enter' ), 10, 2 );
		add_action( 'loop_end', array( $this, 'leave' ) );
		add_filter( 'post_type_link', array( $this, 'link' ), 10, 2 );
		add_filter( 'pre_render_block', array( $this, 'start_template' ), 10, 2 );
		add_filter( 'render_block_woocommerce/product-template', array( $this, 'keys' ) );
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
		$one    = true === $this->settings->get( Settings::ONE_CARD_PER_PRODUCT );
		$chosen = self::chosen_values();
		if ( $one && array() === $chosen ) {
			// Each product once, as itself.
			return $clauses;
		}
		global $wpdb;
		$clauses['join']    = ( $clauses['join'] ?? '' ) . $wpdb->prepare( " LEFT JOIN {$wpdb->postmeta} AS {$alias} ON ( {$alias}.post_id = {$wpdb->posts}.ID AND {$alias}.meta_key = %s )", SplitCards::META ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table names and a constant alias.
		$clauses['fields']  = ( $clauses['fields'] ?? '' ) . ", {$alias}.meta_value AS " . self::FIELD;
		$clauses['groupby'] = '' === trim( (string) ( $clauses['groupby'] ?? '' ) ) ? '' : $clauses['groupby'] . ", {$alias}.meta_id";
		$clauses['orderby'] = '' === trim( (string) ( $clauses['orderby'] ?? '' ) ) ? "{$alias}.meta_id ASC" : $clauses['orderby'] . ", {$alias}.meta_id ASC";
		$not_live           = self::not_live_cards();
		$clauses['where']   = ( $clauses['where'] ?? '' ) . self::filtered_cards( $chosen, $alias ) . self::live_cards( $not_live, $alias );
		if ( $one ) {
			$first = self::FIRST_ALIAS;
			// The first of the product's cards the filters keep; a product listed once has no card row.
			$clauses['where'] .= " AND ( {$alias}.meta_id IS NULL OR {$alias}.meta_id = ( SELECT MIN( {$first}.meta_id ) FROM {$wpdb->postmeta} AS {$first} WHERE {$first}.post_id = {$wpdb->posts}.ID AND "
				. $wpdb->prepare( "{$first}.meta_key = %s", SplitCards::META ) // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- a constant alias.
				. self::filtered_cards( $chosen, $first ) . self::live_cards( $not_live, $first ) . ' ) )';
		}
		return $clauses;
	}

	/**
	 * The values the listing's filters chose, by the attribute each is on.
	 *
	 * @return array<string, list<int>> Term ids by taxonomy; none for a filter whose values name no term.
	 */
	private static function chosen_values(): array {
		$chosen = array();
		foreach ( WC_Query::get_layered_nav_chosen_attributes() as $taxonomy => $filter ) {
			$slugs                        = is_array( $filter['terms'] ?? null ) ? array_values( array_map( 'strval', $filter['terms'] ) ) : array();
			$ids                          = array() === $slugs ? array() : get_terms(
				array(
					'taxonomy'   => (string) $taxonomy,
					'slug'       => $slugs,
					'hide_empty' => false,
					'fields'     => 'ids',
				)
			);
			$chosen[ (string) $taxonomy ] = is_array( $ids ) ? array_values( array_unique( array_map( 'intval', $ids ) ) ) : array();
		}
		return $chosen;
	}

	/**
	 * The condition a filtered listing keeps a card row by, for each
	 * attribute a filter is on: the card holds a value the filter chose, or
	 * none of the product's cards holds a value of that attribute, so the
	 * product is not listed by it. A product listed once has no card and is
	 * kept. Empty without a filter.
	 *
	 * @param array<string, list<int>> $chosen The filters' values, as `chosen_values()` reads them.
	 * @param string                   $alias  The card rows' alias.
	 */
	private static function filtered_cards( array $chosen, string $alias ): string {
		global $wpdb;
		$where = '';
		foreach ( $chosen as $taxonomy => $ids ) {
			// A key is the set of its term ids. No chosen id names a term: no card holds one.
			$holds_chosen = array() === $ids ? '1 = 0' : implode( ' OR ', array_fill( 0, count( $ids ), "FIND_IN_SET( %d, {$alias}.meta_value )" ) );
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
	 * The condition leaving out the card rows of cards that are not live.
	 *
	 * @param array<int, list<string>> $not_live Card keys by product id, as `not_live_cards()` reads them.
	 * @param string                   $alias    The card rows' alias.
	 */
	private static function live_cards( array $not_live, string $alias ): string {
		global $wpdb;
		$where = '';
		foreach ( $not_live as $product_id => $keys ) {
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
		// With one card per product, a product shows a card only while a filter chose one of the card's values.
		$filtered = true === $this->settings->get( Settings::ONE_CARD_PER_PRODUCT ) ? array_merge( array(), ...array_values( self::chosen_values() ) ) : null;
		foreach ( $posts as $post ) {
			if ( ! $post instanceof WP_Post || ! property_exists( $post, self::FIELD ) ) {
				continue;
			}
			$key = $post->{self::FIELD};
			unset( $post->{self::FIELD} );
			if ( SplitCards::is_key( $key ) && ( null === $filtered || array() !== array_intersect( SplitCards::term_ids( (string) $key ), $filtered ) ) ) {
				$this->cards[ $post ] = (string) $key;
			}
		}
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
			$this->current = null;
			return;
		}
		if ( ! $post instanceof WP_Post ) {
			return;
		}
		$key           = $this->current_key( $post );
		$this->current = self::NO_CARD === $key ? null : array( $post->ID, $key );
		$this->shown[] = array( $post->ID, $key );
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
		$this->current = null;
	}

	/**
	 * A card's links to its product, with its values chosen.
	 *
	 * @param mixed $url  The product's address.
	 * @param mixed $post The product.
	 * @return mixed
	 */
	public function link( mixed $url, mixed $post ): mixed {
		if ( null === $this->current || ! is_string( $url ) || ! $post instanceof WP_Post || $post->ID !== $this->current[0] ) {
			return $url;
		}
		$product = wc_get_product( $post->ID );
		if ( ! $product instanceof WC_Product ) {
			return $url;
		}
		return CardPages::url( $product, $this->current[1], $url );
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
