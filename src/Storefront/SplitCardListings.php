<?php
/**
 * The shop's listings showing a split product once per value.
 *
 * @package Pixonaut\WooCommerce
 */

declare(strict_types=1);

namespace Pixonaut\WooCommerce\Storefront;

use Pixonaut\WooCommerce\Catalogue\SplitCards;
use WC_Product;
use WC_Query;
use WeakMap;
use WP_Post;
use WP_Query;

/**
 * The shop, its category, tag and attribute pages and product search list
 * a split product (`SplitCards`) once per value. Their main query joins the
 * product's card rows, so it returns the product once per card, and the
 * count, the pages and the sorting take each card as a product: a classic
 * theme's loop and a block theme's Product Collection, which inherits that
 * query, both show them. Any other product query (related products, a
 * Product Collection with a query of its own, the admin's) lists a product
 * once.
 *
 * Each card links to the product with its value chosen. A filter by the
 * split attribute keeps only the cards of the chosen values.
 */
final class SplitCardListings {

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
	 * Each listed post's card value, by the post object the listing holds.
	 *
	 * @var WeakMap<WP_Post, int>
	 */
	private WeakMap $cards;

	/**
	 * The product and value of the card being shown, while one is.
	 *
	 * @var array{0: int, 1: int}|null
	 */
	private ?array $current = null;

	/**
	 * The cards a Product Template block has shown so far, in order, a value
	 * of 0 for a product listed once.
	 *
	 * @var list<array{0: int, 1: int}>
	 */
	private array $shown = array();

	public function __construct() {
		$this->cards = new WeakMap();
	}

	public function register(): void {
		add_filter( 'posts_clauses', array( $this, 'clauses' ), self::CLAUSES_PRIORITY, 2 );
		add_filter( 'posts_results', array( $this, 'results' ), 10, 2 );
		add_action( 'the_post', array( $this, 'enter' ) );
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
		global $wpdb;
		$clauses['join']    = ( $clauses['join'] ?? '' ) . $wpdb->prepare( " LEFT JOIN {$wpdb->postmeta} AS {$alias} ON ( {$alias}.post_id = {$wpdb->posts}.ID AND {$alias}.meta_key = %s )", SplitCards::META ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table names and a constant alias.
		$clauses['fields']  = ( $clauses['fields'] ?? '' ) . ", {$alias}.meta_value AS " . self::FIELD;
		$clauses['groupby'] = '' === trim( (string) ( $clauses['groupby'] ?? '' ) ) ? '' : $clauses['groupby'] . ", {$alias}.meta_id";
		$clauses['orderby'] = '' === trim( (string) ( $clauses['orderby'] ?? '' ) ) ? "{$alias}.meta_id ASC" : $clauses['orderby'] . ", {$alias}.meta_id ASC";
		$filtered_out       = self::filtered_out();
		if ( array() !== $filtered_out ) {
			$clauses['where'] = ( $clauses['where'] ?? '' ) . " AND ( {$alias}.meta_value IS NULL OR {$alias}.meta_value NOT IN ( '" . implode( "', '", $filtered_out ) . "' ) )";
		}
		return $clauses;
	}

	/**
	 * The values of the filtered attributes that no filter chose, whose
	 * cards a filtered listing leaves out.
	 *
	 * @return list<int>
	 */
	private static function filtered_out(): array {
		$out = array();
		foreach ( WC_Query::get_layered_nav_chosen_attributes() as $taxonomy => $filter ) {
			$chosen = is_array( $filter['terms'] ?? null ) ? $filter['terms'] : array();
			$terms  = get_terms(
				array(
					'taxonomy'   => (string) $taxonomy,
					'hide_empty' => false,
				)
			);
			foreach ( is_array( $terms ) ? $terms : array() as $term ) {
				if ( ! in_array( $term->slug, $chosen, true ) ) {
					$out[] = $term->term_id;
				}
			}
		}
		return $out;
	}

	/**
	 * Keeps each listed post's card value beside it, off the post: WordPress
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
		foreach ( $posts as $post ) {
			if ( ! $post instanceof WP_Post || ! property_exists( $post, self::FIELD ) ) {
				continue;
			}
			$term_id = (int) $post->{self::FIELD};
			unset( $post->{self::FIELD} );
			if ( $term_id > 0 ) {
				$this->cards[ $post ] = $term_id;
			}
		}
		return $posts;
	}

	/**
	 * Notes the card a loop moves to.
	 *
	 * @param mixed $post The post.
	 */
	public function enter( mixed $post ): void {
		if ( ! $post instanceof WP_Post ) {
			return;
		}
		$term_id       = $this->cards[ $post ] ?? 0;
		$this->current = $term_id > 0 ? array( $post->ID, $term_id ) : null;
		$this->shown[] = array( $post->ID, $term_id );
	}

	/**
	 * Forgets the card once its loop ends.
	 */
	public function leave(): void {
		$this->current = null;
	}

	/**
	 * A card's links to its product, with its value chosen.
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
		return add_query_arg( array_map( 'rawurlencode', SplitCards::link_values( $product, $this->current[1] ) ), $url );
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
						return 0 === $card[1] ? $found[0] : 'data-wp-key="product-item-' . $card[0] . '-' . $card[1] . '"';
					}
				}
				return $found[0];
			},
			$content
		) ?? $content;
	}
}
