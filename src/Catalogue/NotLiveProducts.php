<?php
/**
 * Keeps products whose Pixonaut template is not live out of the shop's listings.
 *
 * @package Pixonaut\WooCommerce
 */

declare(strict_types=1);

namespace Pixonaut\WooCommerce\Catalogue;

use WC_Product;
use WC_Product_Variation;
use WP_Query;

/**
 * A personalised product whose template is paused, never published or
 * deleted in Pixonaut cannot be sold (see `Purchasability`), and shoppers
 * should not come across it: it is left out of the shop, its categories and
 * tags, search, product blocks and shortcodes, the Store API's lists, related
 * products, upsells and cross-sells. Its page still opens, so links to it
 * keep working. The product's status and catalogue visibility are never
 * changed: once the template is live again and the product re-checked, it is
 * listed again as it was.
 *
 * A variable product is left out only when every variation a customer can
 * choose is on a template that is not live; one such variation alone is
 * unavailable on its own, as WooCommerce shows a variation it cannot sell.
 */
final class NotLiveProducts {

	/**
	 * Why a configuration is unsellable when its template is not live:
	 * paused or deleted, or never published, which Pixonaut answers as no
	 * published template with the id.
	 */
	public const REASONS = array( InvalidReason::TEMPLATE_NOT_LIVE, InvalidReason::UNKNOWN_TEMPLATE );

	/**
	 * Object cache group of the products left out.
	 */
	private const CACHE_GROUP = 'pixonaut_wc';

	/**
	 * Start of the cache key of the products left out; the posts' last
	 * change follows it, so any product or meta write makes a new key.
	 */
	private const CACHE_KEY_PREFIX = 'not_live_products:';

	/**
	 * Post type of WooCommerce products.
	 */
	private const PRODUCT_POST_TYPE = 'product';

	/**
	 * Post type of WooCommerce variations.
	 */
	private const VARIATION_POST_TYPE = 'product_variation';

	/**
	 * The post type WordPress searches every searchable type with.
	 */
	private const ANY_POST_TYPE = 'any';

	/**
	 * A post id no post has, so a `post__in` of it lists nothing.
	 */
	private const NO_POST = 0;

	/**
	 * Arguments WooCommerce passes to `woocommerce_related_products`.
	 */
	private const RELATED_ARGS = 3;

	/**
	 * Products left out in this request, by cache key.
	 *
	 * @var array<string, list<int>>
	 */
	private array $hidden = array();

	/**
	 * Whether the products left out are being found, so a query made while
	 * judging one is never itself narrowed.
	 *
	 * @var bool
	 */
	private bool $finding = false;

	public function register(): void {
		add_action( 'pre_get_posts', array( $this, 'leave_out' ) );
		add_filter( 'woocommerce_related_products', array( $this, 'related' ), 10, self::RELATED_ARGS );
		add_filter( 'woocommerce_product_is_visible', array( $this, 'visible' ), 10, 2 );
	}

	/**
	 * Whether a product is on sale on no template that is live: a simple
	 * product whose configuration is not, or a variable product each of
	 * whose variations WooCommerce offers is on one that is not.
	 *
	 * @param WC_Product $product A product; a variation is never left out on its own.
	 */
	public static function is_not_live( WC_Product $product ): bool {
		if ( $product->is_type( 'variation' ) ) {
			return false;
		}
		if ( ! $product->is_type( 'variable' ) || array() === $product->get_children() ) {
			return self::not_live( ProductConfig::config_for( $product ) );
		}
		$shown = false;
		foreach ( ProductConfig::sold_variations( $product ) as list( $variation, $config ) ) {
			// A variation WooCommerce does not offer, without a price or hidden out of stock, says nothing.
			if ( ! $variation instanceof WC_Product_Variation || ! $variation->variation_is_visible() ) {
				continue;
			}
			if ( ! self::not_live( $config ) ) {
				return false;
			}
			$shown = true;
		}
		return $shown;
	}

	/**
	 * Whether a configuration is unsellable because its template is not live.
	 *
	 * @param ProductConfig $config A resolved configuration.
	 */
	public static function not_live( ProductConfig $config ): bool {
		return $config->personalise && ! $config->valid && in_array( $config->invalid_reason, self::REASONS, true );
	}

	/**
	 * The ids of the products left out of the shop's listings.
	 *
	 * @return list<int>
	 */
	public function hidden_ids(): array {
		$key = self::CACHE_KEY_PREFIX . wp_cache_get_last_changed( 'posts' );
		if ( isset( $this->hidden[ $key ] ) ) {
			return $this->hidden[ $key ];
		}
		$candidates    = self::candidate_ids();
		$hidden        = array();
		$this->finding = true;
		try {
			// Two queries for the products and their meta, rather than two for each.
			_prime_post_caches( $candidates, false, true );
			foreach ( $candidates as $id ) {
				$product = wc_get_product( $id );
				if ( $product instanceof WC_Product && self::is_not_live( $product ) ) {
					$hidden[] = $id;
				}
			}
		} finally {
			$this->finding = false;
		}
		$this->hidden = array( $key => $hidden );
		return $hidden;
	}

	/**
	 * The products that may be left out, whole or in part: those whose
	 * stored configuration, or one of whose variations' own, names a
	 * template that is not live.
	 *
	 * @return list<int>
	 */
	public static function candidate_ids(): array {
		$key = self::CACHE_KEY_PREFIX . wp_cache_get_last_changed( 'posts' );
		// Only what is stored is cached: whether a product is personalised is filtered, per request.
		$candidates = wp_cache_get( $key, self::CACHE_GROUP );
		if ( ! is_array( $candidates ) ) {
			$candidates = self::candidates();
			wp_cache_set( $key, $candidates, self::CACHE_GROUP );
		}
		return array_values( array_map( 'intval', $candidates ) );
	}

	/**
	 * Leaves the products whose template is not live out of a query that
	 * lists products to shoppers.
	 *
	 * @param WP_Query $query The query about to run.
	 */
	public function leave_out( WP_Query $query ): void {
		if ( $this->finding || ! self::lists_to_shoppers( $query ) ) {
			return;
		}
		$hidden = $this->hidden_ids();
		if ( array() === $hidden ) {
			return;
		}
		$selected = $query->get( 'post__in' );
		// WordPress ignores `post__not_in` once `post__in` names products, as the Store API's `include` and on-sale lists do.
		if ( is_array( $selected ) && array() !== $selected ) {
			$kept = array_values( array_diff( array_map( 'intval', $selected ), $hidden ) );
			// An empty `post__in` would list every product; one naming no post lists none.
			$query->set( 'post__in', array() === $kept ? array( self::NO_POST ) : $kept );
		}
		$excluded = $query->get( 'post__not_in' );
		$query->set( 'post__not_in', array_values( array_unique( array_merge( is_array( $excluded ) ? array_map( 'intval', $excluded ) : array(), $hidden ) ) ) );
	}

	/**
	 * Takes the products whose template is not live out of a product's related products.
	 *
	 * @param mixed $related    The related products' ids.
	 * @param mixed $product_id The product they are related to.
	 * @param mixed $args       How they were chosen.
	 * @return mixed
	 */
	public function related( $related, $product_id = 0, $args = array() ) {
		unset( $product_id, $args );
		if ( ! is_array( $related ) || array() === $related ) {
			return $related;
		}
		$hidden = $this->hidden_ids();
		return array() === $hidden ? $related : array_values( array_filter( $related, static fn ( $id ): bool => ! in_array( (int) $id, $hidden, true ) ) );
	}

	/**
	 * Hides a product whose template is not live wherever WooCommerce asks
	 * whether to show it in a list: upsells, cross-sells, widgets.
	 *
	 * @param mixed $visible    What WooCommerce decided.
	 * @param mixed $product_id The product.
	 * @return mixed
	 */
	public function visible( $visible, $product_id = 0 ) {
		if ( true !== $visible || ! is_numeric( $product_id ) ) {
			return $visible;
		}
		return ! in_array( (int) $product_id, $this->hidden_ids(), true );
	}

	/**
	 * Whether a query lists products to shoppers: on the storefront or in
	 * the Store API, never in the admin, a scheduled job, WP-CLI or another
	 * REST route, and never one product's own page. A query that suppresses
	 * filters, as `get_posts()` does, is the code's own and left as it is.
	 *
	 * @param WP_Query $query The query about to run.
	 */
	private static function lists_to_shoppers( WP_Query $query ): bool {
		if ( $query->is_singular() || true === $query->get( 'suppress_filters' ) ) {
			return false;
		}
		if ( is_admin() || wp_doing_cron() || ( defined( 'WP_CLI' ) && WP_CLI ) ) {
			return false;
		}
		if ( wp_is_serving_rest_request() && ! WC()->is_store_api_request() ) {
			return false;
		}
		$types = $query->get( 'post_type' );
		if ( in_array( $types, array( '', self::ANY_POST_TYPE ), true ) ) {
			// A product category, tag or attribute archive names no post type until the query runs.
			return $query->is_search() || $query->is_tax( get_object_taxonomies( self::PRODUCT_POST_TYPE ) );
		}
		return in_array( self::PRODUCT_POST_TYPE, (array) $types, true );
	}

	/**
	 * The products that may be left out: those whose stored configuration,
	 * or one of whose variations' own, names a template that is not live.
	 * Each is judged in the request, as filters resolve its configuration.
	 *
	 * @return list<int>
	 */
	private static function candidates(): array {
		global $wpdb;
		$ids = array();
		foreach ( self::REASONS as $reason ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- cached by the caller, under the posts' last change.
			$found = $wpdb->get_col(
				$wpdb->prepare(
					"SELECT IF( p.post_type = %s, p.post_parent, p.ID ) FROM {$wpdb->postmeta} m INNER JOIN {$wpdb->posts} p ON p.ID = m.post_id WHERE m.meta_key = %s AND m.meta_value LIKE %s",
					self::VARIATION_POST_TYPE,
					ProductConfig::META_KEY,
					'%' . $wpdb->esc_like( $reason ) . '%'
				)
			);
			$ids   = array_merge( $ids, array_map( 'intval', $found ) );
		}
		return array_values( array_unique( $ids ) );
	}
}
