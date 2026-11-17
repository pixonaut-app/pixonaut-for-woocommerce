<?php
/**
 * Works out one archive's index.
 *
 * @package Pixonaut\WooCommerce
 */

declare(strict_types=1);

namespace Pixonaut\WooCommerce\Catalogue;

use Pixonaut\WooCommerce\Schema;
use Pixonaut\WooCommerce\Storefront\AttributeDependencies;
use WC_Product;
use WP_Query;
use WP_Term;

/**
 * An archive's index lists every card the archive shows, as its listing
 * does (`SplitCardListings`): the products its query finds before any
 * filter, in the store's default order, each as its cards
 * (`ArchiveCards`). Beside the cards: each card's place under every sort
 * WooCommerce offers, from WooCommerce's own ordering of the same query;
 * the filters, one per attribute the cards' variations hold with the
 * values they hold, in the store's order, a dependent attribute naming
 * the values of the attribute it applies under (`AttributeDependencies`),
 * then the price; the shop's sort menu, as WooCommerce last showed it;
 * and the store's currency.
 */
final class ArchiveIndexBuild {

	/**
	 * The sorts WooCommerce's sort menu can offer, as `orderby` names them.
	 */
	public const SORTS = array( 'menu_order', 'popularity', 'rating', 'date', 'price', 'price-desc' );

	/**
	 * The store's default sort when it names none.
	 */
	private const DEFAULT_SORT = 'menu_order';

	/**
	 * How many products are read at a time.
	 */
	private const BATCH = 50;

	/**
	 * The price filter's key.
	 */
	public const PRICE_KEY = 'price';

	/**
	 * @param ArchiveCards $cards Each product's cards, shared by the archives of one build.
	 * @param ArchiveTerms $terms How attributes and values are named.
	 */
	public function __construct(
		private readonly ArchiveCards $cards,
		private readonly ArchiveTerms $terms,
	) {
	}

	/**
	 * An archive's index, without its version; null for an archive that is gone.
	 *
	 * @param string $archive An archive's name (`ArchiveIndexes::name()`).
	 * @param int[]  $hidden  The products the shop leaves out of its listings (`NotLiveProducts`).
	 * @return array{index: array<string, mixed>, count: int}|null
	 */
	public function build( string $archive, array $hidden ): ?array {
		$tax_query = ArchiveIndexes::tax_query( $archive );
		if ( null === $tax_query ) {
			return null;
		}
		$args    = array(
			'post_type'        => 'product',
			'post_status'      => 'publish',
			'posts_per_page'   => -1,
			'nopaging'         => true,
			'no_found_rows'    => true,
			'fields'           => 'ids',
			// The index is public: a product behind a password keeps it to those who have it.
			'has_password'     => false,
			'suppress_filters' => false,
			'tax_query'        => WC()->query->get_tax_query( $tax_query ), // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_tax_query -- the archive's own query, built in the background.
			'meta_query'       => WC()->query->get_meta_query(), // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- the archive's own query, built in the background.
			'post__not_in'     => array_map( 'intval', $hidden ), // phpcs:ignore WordPressVIPMinimum.Performance.WPQueryParams.PostNotIn_post__not_in -- the products the shop leaves out of its listings.
		);
		$default = (string) get_option( 'woocommerce_default_catalog_orderby', self::DEFAULT_SORT );
		$default = in_array( $default, self::SORTS, true ) ? $default : self::DEFAULT_SORT;
		$places  = array();
		$ordered = array();
		foreach ( self::SORTS as $sort ) {
			$ids              = self::sorted( $args, $sort );
			$places[ $sort ]  = array_flip( $ids );
			$ordered[ $sort ] = $ids;
		}
		$cards = array();
		foreach ( array_chunk( $ordered[ $default ], self::BATCH ) as $batch ) {
			// What was kept of the products' variations since they last changed; the rest read in a few queries a batch.
			FilterIndex::look_up( $batch );
			_prime_post_caches( $batch, true, true );
			foreach ( $batch as $product_id ) {
				$product = wc_get_product( $product_id );
				if ( ! $product instanceof WC_Product ) {
					continue;
				}
				$sort = array();
				foreach ( self::SORTS as $key ) {
					$sort[ $key ] = $places[ $key ][ $product_id ] ?? count( $places[ $key ] );
				}
				foreach ( $this->cards->of( $product ) as $card ) {
					$cards[] = array_slice( $card, 0, 6, true ) + array( 'sort' => $sort ) + $card;
				}
			}
			// What a batch read is kept for the next build, and let go of: a large archive would hold it all.
			FilterIndex::keep();
			SplitCards::release( $batch );
			wp_cache_flush_runtime();
		}
		$count                  = count( array_filter( $cards, static fn ( array $card ): bool => array() === ( $card['onlyFiltered'] ?? array() ) ) );
		list( $cards, $images ) = $this->pictures( $cards );
		return array(
			'index' => array(
				'currency' => self::currency(),
				'filters'  => $this->filters( $cards ),
				'sorts'    => self::sort_menu(),
				'cards'    => $cards,
				'images'   => $images,
			),
			'count' => $count,
		);
	}

	/**
	 * Lists the pictures an archive's cards' variations point to, each
	 * once in the order first met, and points to them by their place.
	 *
	 * @param list<array<string, mixed>> $cards The archive's cards, their variations' pictures as attachment ids.
	 * @return array{0: list<array<string, mixed>>, 1: list<array{src: string, srcset: string, width: int, height: int}>}
	 */
	private function pictures( array $cards ): array {
		$places = array();
		$images = array();
		foreach ( $cards as $index => $card ) {
			foreach ( is_array( $card['variations'] ?? null ) ? $card['variations'] : array() as $row_index => $row ) {
				foreach ( array( 'image', 'openImage' ) as $field ) {
					if ( ! is_array( $row ) || ! is_int( $row[ $field ] ?? null ) ) {
						continue;
					}
					$attachment = $row[ $field ];
					if ( ! isset( $places[ $attachment ] ) ) {
						$places[ $attachment ] = count( $images );
						$images[]              = $this->cards->picture( $attachment );
					}
					$cards[ $index ]['variations'][ $row_index ][ $field ] = $places[ $attachment ];
				}
			}
		}
		return array( $cards, $images );
	}

	/**
	 * The products a query finds under one of WooCommerce's sorts, as
	 * WooCommerce's own listing orders them.
	 *
	 * @param array<string, mixed> $args The archive's query.
	 * @param string               $sort A sort's `orderby` name.
	 * @return list<int>
	 */
	private static function sorted( array $args, string $sort ): array {
		// WooCommerce splits `price-desc` into its sort and its order only when it reads the request.
		list( $orderby, $order ) = array_pad( explode( '-', $sort, 2 ), 2, '' );
		$ordering                = WC()->query->get_catalog_ordering_args( $orderby, $order );
		try {
			// Not from the query cache: WooCommerce's price filter runs an archive's query with no results (`posts_pre_query`), and WordPress keeps that empty answer for the query.
			$ids = ( new WP_Query() )->query( array_merge( $args, $ordering, array( 'cache_results' => false ) ) );
		} finally {
			WC()->query->remove_ordering_args();
		}
		return array_values( array_map( static fn ( mixed $id ): int => is_numeric( $id ) ? (int) $id : 0, $ids ) );
	}

	/**
	 * The filters of an archive's cards: each attribute their variations
	 * hold, in the store's order of attributes, with the values they hold,
	 * in the attribute's order of values; then the price.
	 *
	 * @param list<array<string, mixed>> $cards The archive's cards.
	 * @return list<array<string, mixed>>
	 */
	private function filters( array $cards ): array {
		$held = array();
		foreach ( $cards as $card ) {
			foreach ( is_array( $card['variations'] ?? null ) ? $card['variations'] : array() as $row ) {
				foreach ( (array) ( $row['values'] ?? array() ) as $key => $slugs ) {
					foreach ( is_array( $slugs ) ? $slugs : array() as $slug ) {
						$held[ (string) $key ][ (string) $slug ] = true;
					}
				}
			}
		}
		$dependencies = AttributeDependencies::store();
		$filters      = array();
		foreach ( wc_get_attribute_taxonomies() as $attribute ) {
			$taxonomy = wc_attribute_taxonomy_name( (string) $attribute->attribute_name );
			$key      = $this->terms->key( $taxonomy );
			if ( ! isset( $held[ $key ] ) ) {
				continue;
			}
			$terms  = get_terms(
				array(
					'taxonomy'   => $taxonomy,
					'hide_empty' => false,
				)
			);
			$values = array();
			foreach ( is_array( $terms ) ? $terms : array() as $term ) {
				if ( isset( $held[ $key ][ $term->slug ] ) ) {
					$values[] = array(
						'id'    => $term->slug,
						'label' => html_entity_decode( $term->name, ENT_QUOTES, 'UTF-8' ),
						'order' => count( $values ),
					);
				}
			}
			$filter = array(
				'key'    => $key,
				'label'  => html_entity_decode( wc_attribute_label( $taxonomy ), ENT_QUOTES, 'UTF-8' ),
				'type'   => 'attribute',
				'values' => $values,
			);
			foreach ( $dependencies[ $taxonomy ] ?? array() as $parent => $under ) {
				$filter['parent'] = array(
					'key'    => $this->terms->key( (string) $parent ),
					'values' => $under,
				);
				// A filter's drawer nests it under one attribute: the first it depends on.
				break;
			}
			$filters[] = $filter;
		}
		$filters[] = array(
			'key'   => self::PRICE_KEY,
			/* translators: The label of the price filter of the shop's product archives. */
			'label' => __( 'Price', 'pixonaut-for-woocommerce' ),
			'type'  => 'price',
		);
		return $filters;
	}

	/**
	 * The shop's sort menu, as WooCommerce last showed it on an archive
	 * (`ArchiveIndexDiscovery::sorts()`); empty before it did.
	 *
	 * @return list<array{key: string, label: string}>
	 */
	private static function sort_menu(): array {
		$stored = get_option( Schema::ARCHIVE_SORTS_OPTION, array() );
		$menu   = array();
		foreach ( is_array( $stored ) ? $stored : array() as $key => $label ) {
			if ( in_array( (string) $key, self::SORTS, true ) && is_string( $label ) ) {
				$menu[] = array(
					'key'   => (string) $key,
					'label' => $label,
				);
			}
		}
		return $menu;
	}

	/**
	 * How the store writes its prices.
	 *
	 * @return array{code: string, symbol: string, position: string, decimals: int, decimalSeparator: string, thousandSeparator: string}
	 */
	private static function currency(): array {
		return array(
			'code'              => get_woocommerce_currency(),
			'symbol'            => html_entity_decode( get_woocommerce_currency_symbol(), ENT_QUOTES, 'UTF-8' ),
			'position'          => (string) get_option( 'woocommerce_currency_pos', 'left' ),
			'decimals'          => wc_get_price_decimals(),
			'decimalSeparator'  => wc_get_price_decimal_separator(),
			'thousandSeparator' => wc_get_price_thousand_separator(),
		);
	}
}
