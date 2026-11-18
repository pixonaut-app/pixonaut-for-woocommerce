<?php
/**
 * Settles the prices a changed level of the pricing rule reaches.
 *
 * @package Pixonaut\WooCommerce
 */

declare(strict_types=1);

namespace Pixonaut\WooCommerce\Pricing;

use Pixonaut\WooCommerce\Catalogue\WaitingJob;
use Pixonaut\WooCommerce\Schema;
use Pixonaut\WooCommerce\Settings\Settings;
use WC_Product;
use WP_Term;

/**
 * When the store's rule or an attribute value's parts change, every price
 * that follows the rule and that the change reaches is set to the rule's
 * price, or flagged with it, as its automatic price changes say
 * (`ProductPricing::reprice()`). That may be every product, so it runs a
 * page of products per Action Scheduler job. A product's or variation's own
 * parts are settled where they are saved.
 */
final class RuleChange {

	/**
	 * Settles one page of products; takes the product id the page starts
	 * after, and the attribute value that changed, by taxonomy and slug
	 * (empty for the store's rule).
	 */
	public const HOOK = 'pixonaut_wc_pricing_rule_changed';

	public const GROUP = Schema::ACTION_GROUP;

	/**
	 * Products per job: none is asked of Pixonaut.
	 */
	public const PAGE = 50;

	/**
	 * Arguments of `added_term_meta`, `updated_term_meta` and `deleted_term_meta`.
	 */
	private const TERM_META_ARGS = 3;

	/**
	 * Arguments of the job: the page's start, a taxonomy and a slug.
	 */
	private const PAGE_ARGS = 3;

	/**
	 * @param ProductPricing $pricing Settles a product's prices.
	 */
	public function __construct( private readonly ProductPricing $pricing ) {
	}

	public function register(): void {
		add_action( self::HOOK, array( $this, 'page' ), 10, self::PAGE_ARGS );
		add_action( 'update_option_' . Settings::OPTION, array( $this, 'settings_changed' ), 10, 2 );
		add_action( 'add_option_' . Settings::OPTION, array( $this, 'settings_added' ), 10, 2 );
		foreach ( array( 'added_term_meta', 'updated_term_meta', 'deleted_term_meta' ) as $action ) {
			add_action( $action, array( $this, 'term_changed' ), 10, self::TERM_META_ARGS );
		}
	}

	/**
	 * The store's first saved settings: before them the rule had its defaults.
	 *
	 * @param mixed $option The option's name.
	 * @param mixed $after  The settings now.
	 */
	public function settings_added( mixed $option, mixed $after ): void {
		$this->settings_changed( array(), $after );
	}

	/**
	 * Queues the prices for settling when a part of the store's rule changed.
	 *
	 * @param mixed $before The settings before.
	 * @param mixed $after  The settings now.
	 */
	public function settings_changed( mixed $before, mixed $after ): void {
		$before = is_array( $before ) ? $before : array();
		$after  = is_array( $after ) ? $after : array();
		foreach ( Settings::PRICE_RULE as $setting ) {
			if ( (string) ( $before[ $setting ] ?? '' ) !== (string) ( $after[ $setting ] ?? '' ) ) {
				self::queue( '', '' );
				return;
			}
		}
	}

	/**
	 * Queues the prices of the variations with an attribute value for
	 * settling when the value's parts changed.
	 *
	 * @param mixed $meta_id  Unused.
	 * @param mixed $term_id  The term.
	 * @param mixed $meta_key The meta changed.
	 */
	public function term_changed( mixed $meta_id, mixed $term_id, mixed $meta_key ): void {
		unset( $meta_id );
		if ( AttributeRules::META_KEY !== $meta_key ) {
			return;
		}
		$term = get_term( (int) $term_id );
		if ( $term instanceof WP_Term && AttributeRules::is_attribute( $term->taxonomy ) ) {
			self::queue( $term->taxonomy, $term->slug );
		}
	}

	/**
	 * Starts settling at the first page, unless that waits to run already.
	 * A first page that runs now may have passed products the change
	 * reaches, so another is queued beside it.
	 *
	 * @param string $taxonomy The attribute value's taxonomy; empty for the store's rule.
	 * @param string $slug     The attribute value's slug; empty for the store's rule.
	 */
	public static function queue( string $taxonomy, string $slug ): void {
		if ( ! function_exists( 'as_enqueue_async_action' ) ) {
			return;
		}
		$args = array( 0, $taxonomy, $slug );
		if ( ! WaitingJob::exists( self::HOOK, $args, self::GROUP ) ) {
			as_enqueue_async_action( self::HOOK, $args, self::GROUP );
		}
	}

	/**
	 * The job: settles a page of products with a price that follows the
	 * rule, then queues the page after it when this one was full.
	 *
	 * @param int|string $after    The product id the page starts after; 0 for the first.
	 * @param string     $taxonomy The attribute value's taxonomy; empty for the store's rule.
	 * @param string     $slug     The attribute value's slug; empty for the store's rule.
	 */
	public function page( int|string $after = 0, string $taxonomy = '', string $slug = '' ): void {
		$ids = ProductPricing::watched( (int) $after, self::PAGE );
		foreach ( $ids as $id ) {
			$product = wc_get_product( $id );
			if ( ! $product instanceof WC_Product || ! ProductPricing::prices( $product ) ) {
				continue;
			}
			$reached = '' === $taxonomy ? null : self::with_value( $product, $taxonomy, $slug );
			if ( null === $reached || array() !== $reached ) {
				$this->pricing->reprice( $product, $reached );
			}
		}
		if ( self::PAGE === count( $ids ) ) {
			as_enqueue_async_action( self::HOOK, array( $ids[ self::PAGE - 1 ], $taxonomy, $slug ), self::GROUP );
		}
	}

	/**
	 * A product's variations with an attribute value.
	 *
	 * @param WC_Product $product  A simple or variable product.
	 * @param string     $taxonomy The attribute's taxonomy.
	 * @param string     $slug     The value's slug.
	 * @return list<int>
	 */
	private static function with_value( WC_Product $product, string $taxonomy, string $slug ): array {
		$ids = array();
		foreach ( ProductPricing::items( $product ) as $item ) {
			if ( $item->is_type( 'variation' ) && ( $item->get_attributes()[ $taxonomy ] ?? '' ) === $slug ) {
				$ids[] = $item->get_id();
			}
		}
		return $ids;
	}
}
