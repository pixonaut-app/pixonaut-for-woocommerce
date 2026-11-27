<?php
/**
 * Products' meta, read for many products at once.
 *
 * @package Pixonaut\WooCommerce
 */

declare(strict_types=1);

namespace Pixonaut\WooCommerce\Catalogue;

use WC_Cache_Helper;
use WC_Data;

/**
 * WooCommerce reads a product's meta (`WC_Data::get_meta()`) with a query of
 * its own the first time the product is asked for any, whatever WordPress's
 * meta cache already holds: reading each of a product's 72 variations is 72
 * queries. `prime()` reads the meta of every product given in one query into
 * the cache WooCommerce reads first, the same rows each of those queries
 * would have read, and into WordPress's meta cache, which WooCommerce reads a
 * product's own fields from.
 */
final class ProductMeta {

	/**
	 * The object cache group WooCommerce keeps a product's meta rows in.
	 */
	private const CACHE_GROUP = 'products';

	/**
	 * WordPress's object cache group of post meta.
	 */
	private const WP_CACHE_GROUP = 'post_meta';

	/**
	 * Reads the meta of the products, or variations, whose meta WooCommerce
	 * or WordPress has not read yet in this request, in one query.
	 *
	 * @param int[] $product_ids Products or variations.
	 * @phpstan-param list<int> $product_ids
	 */
	public static function prime( array $product_ids ): void {
		$product_ids = array_values( array_unique( array_filter( $product_ids, static fn ( int $id ): bool => $id > 0 ) ) );
		$wc_unread   = array_values( array_filter( $product_ids, static fn ( int $id ): bool => ! is_array( wp_cache_get( WC_Data::generate_meta_cache_key( $id, self::CACHE_GROUP ), self::CACHE_GROUP ) ) ) );
		$wp_unread   = array_values( array_filter( $product_ids, static fn ( int $id ): bool => false === wp_cache_get( (string) $id, self::WP_CACHE_GROUP ) ) );
		$unread      = array_values( array_unique( array_merge( $wc_unread, $wp_unread ) ) );
		if ( array() === $unread ) {
			return;
		}
		global $wpdb;
		$placeholders = implode( ', ', array_fill( 0, count( $unread ), '%d' ) );
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- the rows WooCommerce's own read_meta() reads, for many products at once, into its cache; a placeholder per id.
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT post_id, meta_id, meta_key, meta_value FROM {$wpdb->postmeta} WHERE post_id IN ( {$placeholders} ) ORDER BY meta_id",
				...$unread
			)
		);
		// phpcs:enable
		if ( ! is_array( $rows ) ) {
			return;
		}
		// A product with no meta at all is read as such too.
		$wc_rows = array_fill_keys( $wc_unread, array() );
		$wp_meta = array_fill_keys( $wp_unread, array() );
		foreach ( $rows as $row ) {
			$id = (int) $row->post_id;
			if ( isset( $wc_rows[ $id ] ) ) {
				$wc_rows[ $id ][] = (object) array(
					'meta_id'    => $row->meta_id,
					'meta_key'   => $row->meta_key, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- a row read above, not a query.
					'meta_value' => $row->meta_value, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value -- a row read above, not a query.
				);
			}
			if ( isset( $wp_meta[ $id ] ) ) {
				// As update_meta_cache() keeps it: each key's values in the order they were added.
				$wp_meta[ $id ][ (string) $row->meta_key ][] = $row->meta_value;
			}
		}
		WC_Data::prime_raw_meta_data_cache( $wc_rows, self::CACHE_GROUP );
		foreach ( $wp_meta as $id => $meta ) {
			wp_cache_add( (string) $id, $meta, self::WP_CACHE_GROUP );
		}
	}

	/**
	 * Drops what WordPress and WooCommerce keep of a product's or
	 * variation's meta, after a write straight to the database that
	 * bypassed the meta API and its hooks: WordPress's post caches,
	 * WooCommerce's meta rows of the object, kept apart from the post's,
	 * and its product transients.
	 *
	 * @param int $product_id A product or variation.
	 */
	public static function written_directly( int $product_id ): void {
		clean_post_cache( $product_id );
		WC_Cache_Helper::invalidate_cache_group( 'object_' . $product_id );
		wc_delete_product_transients( $product_id );
	}
}
