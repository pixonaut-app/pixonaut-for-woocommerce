<?php
/**
 * Products list an attribute's values in their new order once it changes.
 *
 * @package Pixonaut\WooCommerce
 */

declare(strict_types=1);

namespace Pixonaut\WooCommerce\Catalogue;

use WC_Cache_Helper;
use WP_Term;

/**
 * WooCommerce keeps each product's values of an attribute, in the
 * attribute's order, in the object cache (`wc_get_product_terms`), which is
 * persistent on a site with Redis or Memcached. A value's place in its
 * attribute's custom order is term meta (`order`), and writing it leaves
 * that cache as it was: a product page viewed before went on listing the
 * values in their old order, however they were reordered (the attribute's
 * screen, the REST API, Pixonaut's shop tools).
 *
 * When a value's place is written, every product's cached values of its
 * attribute are let go: on WooCommerce 11.2 and newer all at once, through
 * the prefix its own cache keys carry (`product_terms_<taxonomy>`, the one
 * it lets go itself when a term is saved); before that, product by
 * product, for the products that have the value.
 */
final class AttributeOrderCache {

	/**
	 * The term meta that holds a value's place, and the older key per
	 * taxonomy (`order_<taxonomy>`) WooCommerce still reads.
	 */
	private const ORDER_META_KEY = 'order';

	/**
	 * The term meta writes that count, and the arguments read of them: the
	 * meta row, the term and the key.
	 */
	private const META_WRITES      = array( 'added_term_meta', 'updated_term_meta', 'deleted_term_meta' );
	private const META_ACTION_ARGS = 3;

	/**
	 * The first WooCommerce whose cached product values of a taxonomy share
	 * a prefix that can be let go at once.
	 */
	private const SHARED_PREFIX_SINCE = '11.2.0';

	/**
	 * Before 11.2: the terms whose products were let go this request, and
	 * those products, each let go once as it is found and once more as the
	 * request ends, so a bulk reorder costs a product two writes at most
	 * and a read made between two of its writes is not kept.
	 *
	 * @var array<int, true>
	 */
	private static array $terms_seen = array();

	/**
	 * @var array<int, true>
	 */
	private static array $products_seen = array();

	public static function register(): void {
		foreach ( self::META_WRITES as $action ) {
			add_action( $action, array( self::class, 'meta_written' ), 10, self::META_ACTION_ARGS );
		}
		add_action( 'shutdown', array( self::class, 'forget_again' ) );
	}

	/**
	 * Lets go, once more, of the products let go one by one this request.
	 */
	public static function forget_again(): void {
		foreach ( array_keys( self::$products_seen ) as $product_id ) {
			WC_Cache_Helper::invalidate_cache_group( 'product_' . $product_id );
		}
		self::$terms_seen    = array();
		self::$products_seen = array();
	}

	/**
	 * Lets go of the cached values of the attribute whose value's place was
	 * written.
	 *
	 * @param mixed $meta_id  The meta row.
	 * @param mixed $term_id  The term.
	 * @param mixed $meta_key The key written.
	 */
	public static function meta_written( mixed $meta_id, mixed $term_id, mixed $meta_key ): void {
		unset( $meta_id );
		if ( ! is_string( $meta_key ) || ! is_numeric( $term_id ) ) {
			return;
		}
		if ( self::ORDER_META_KEY !== $meta_key && ! str_starts_with( $meta_key, self::ORDER_META_KEY . '_' ) ) {
			return;
		}
		$term = get_term( (int) $term_id );
		if ( ! $term instanceof WP_Term || ! taxonomy_is_product_attribute( $term->taxonomy ) ) {
			return;
		}
		self::forget( $term );
	}

	/**
	 * Lets go of every product's cached values of the term's taxonomy.
	 *
	 * @param WP_Term $term The value whose place changed.
	 */
	private static function forget( WP_Term $term ): void {
		WC_Cache_Helper::invalidate_cache_group( 'product_terms_' . $term->taxonomy );
		if ( version_compare( (string) WC()->version, self::SHARED_PREFIX_SINCE, '>=' ) ) {
			return;
		}
		if ( isset( self::$terms_seen[ $term->term_id ] ) ) {
			return;
		}
		self::$terms_seen[ $term->term_id ] = true;

		$products = get_objects_in_term( $term->term_id, $term->taxonomy );
		if ( ! is_array( $products ) ) {
			return;
		}
		foreach ( $products as $product_id ) {
			$product_id = (int) $product_id;
			if ( ! isset( self::$products_seen[ $product_id ] ) ) {
				self::$products_seen[ $product_id ] = true;
				WC_Cache_Helper::invalidate_cache_group( 'product_' . $product_id );
			}
		}
	}
}
