<?php
/**
 * Shows the prices below the store's lowest margin in wp-admin.
 *
 * @package Pixonaut\WooCommerce
 */

declare(strict_types=1);

namespace Pixonaut\WooCommerce\Admin;

use Pixonaut\WooCommerce\Pricing\MarginFloor;
use Pixonaut\WooCommerce\Pricing\ProductPricing;
use WC_Product;

/**
 * While prices are below the lowest margin (`MarginFloor`), a notice on the
 * dashboard and the products screens says how many, with a link to the
 * products list filtered to their products: a view of its own, **Below
 * lowest margin**. Under a product's name, the list names each of its
 * prices below the floor with its margin.
 */
final class MarginFloorList {

	/**
	 * The products list's query argument that filters it to the products
	 * with a price below the floor.
	 */
	public const QUERY_ARG = 'pixonaut_margin_low';

	/**
	 * Who sees the notice: whoever may edit the products it is about.
	 */
	private const CAPABILITY = 'edit_products';

	/**
	 * The screens the notice shows on.
	 */
	private const NOTICE_SCREENS = array( 'dashboard', 'edit-product', 'product' );

	/**
	 * After WooCommerce printed the name column, and after the pricing rule's flag.
	 */
	private const COLUMN_PRIORITY = 21;

	/**
	 * The arguments `manage_product_posts_custom_column` passes.
	 */
	private const COLUMN_ARGS = 2;

	/**
	 * The products list's name column.
	 */
	private const COLUMN = 'name';

	/**
	 * Prices named under a product's name; the rest are counted.
	 */
	private const MOST_NAMED = 3;

	public function __construct(
		private readonly MarginFloor $floor,
		private readonly ProductPricing $pricing,
	) {
	}

	public function register(): void {
		add_action( 'admin_notices', array( $this, 'notice' ) );
		add_filter( 'views_edit-product', array( $this, 'views' ) );
		add_filter( 'request', array( $this, 'request' ) );
		add_action( 'manage_product_posts_custom_column', array( $this, 'column' ), self::COLUMN_PRIORITY, self::COLUMN_ARGS );
	}

	/**
	 * The products list filtered to the products with a price below the floor.
	 */
	public static function url(): string {
		return add_query_arg(
			array(
				'post_type'     => 'product',
				self::QUERY_ARG => 1,
			),
			admin_url( 'edit.php' )
		);
	}

	/**
	 * Says how many prices are below the floor, to whoever may edit products.
	 */
	public function notice(): void {
		if ( ! in_array( get_current_screen()?->id, self::NOTICE_SCREENS, true ) || ! current_user_can( self::CAPABILITY ) ) {
			return;
		}
		echo $this->notice_html(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped where it is built.
	}

	/**
	 * The notice; empty while no price is below the floor, or the store sets none.
	 */
	public function notice_html(): string {
		$floor = $this->floor->floor();
		$count = count( $this->floor->low() );
		if ( null === $floor || 0 === $count ) {
			return '';
		}
		return sprintf(
			'<div class="notice notice-warning pixonaut-margin-floor-notice"><p>%1$s <a href="%2$s">%3$s</a></p></div>',
			esc_html(
				sprintf(
					/* translators: 1: number of prices, 2: the lowest margin the store accepts, such as 25.0 %. Admin notice. */
					_n( '%1$d price has a margin below your lowest margin of %2$s.', '%1$d prices have a margin below your lowest margin of %2$s.', $count, 'pixonaut-for-woocommerce' ),
					$count,
					PricingPanel::percent( $floor )
				)
			),
			esc_url( self::url() ),
			/* translators: Link in an admin notice to the products list, filtered to the products with a price below the lowest margin. */
			esc_html__( 'Show the products', 'pixonaut-for-woocommerce' )
		);
	}

	/**
	 * Adds the view of the products with a price below the floor, while there are any.
	 *
	 * @param mixed $views The products list's views.
	 * @return mixed
	 */
	public function views( mixed $views ): mixed {
		$count = count( $this->floor->products() );
		if ( ! is_array( $views ) || null === $this->floor->floor() || 0 === $count ) {
			return $views;
		}
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- a filter of the list, as WordPress's own views are.
		$current                  = isset( $_GET[ self::QUERY_ARG ] );
		$views[ self::QUERY_ARG ] = sprintf(
			'<a href="%1$s"%2$s>%3$s <span class="count">(%4$s)</span></a>',
			esc_url( self::url() ),
			$current ? ' class="current" aria-current="page"' : '',
			/* translators: A view of the admin products list: the products with a price whose margin is below the lowest margin the store accepts. */
			esc_html__( 'Below lowest margin', 'pixonaut-for-woocommerce' ),
			esc_html( number_format_i18n( $count ) )
		);
		return $views;
	}

	/**
	 * Filters the products list to the products with a price below the floor.
	 *
	 * @param mixed $vars The list's query.
	 * @return mixed
	 */
	public function request( mixed $vars ): mixed {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- as above.
		if ( ! is_admin() || ! is_array( $vars ) || ! isset( $_GET[ self::QUERY_ARG ] ) || 'product' !== ( $vars['post_type'] ?? null ) ) {
			return $vars;
		}
		$products = $this->floor->products();
		// No product matches an id of 0, so the view is empty rather than every product.
		$vars['post__in'] = array() === $products ? array( 0 ) : $products;
		return $vars;
	}

	/**
	 * Names a product's prices below the floor under its name.
	 *
	 * @param mixed $column  The column printed.
	 * @param mixed $post_id The row's product.
	 */
	public function column( mixed $column, mixed $post_id ): void {
		if ( self::COLUMN === $column ) {
			echo $this->column_html( (int) $post_id ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped where it is built.
		}
	}

	/**
	 * A product's prices below the floor, each with its margin; empty for
	 * a product with none.
	 *
	 * @param int $product_id The product.
	 */
	public function column_html( int $product_id ): string {
		$floor = $this->floor->floor();
		$ids   = $this->floor->low_of_product( $product_id );
		if ( null === $floor || array() === $ids ) {
			return '';
		}
		$lines = array();
		foreach ( array_slice( $ids, 0, self::MOST_NAMED ) as $id ) {
			$item   = wc_get_product( $id );
			$margin = $item instanceof WC_Product ? $this->pricing->margin_now( $item ) : null;
			if ( ! $item instanceof WC_Product || null === $margin ) {
				continue;
			}
			$lines[] = sprintf(
				/* translators: 1: a variation's attribute values or a product's name, 2: the price, 3: the margin it leaves, such as 22.4 %. Under a product's name on the admin products list. */
				__( '%1$s: %2$s (margin %3$s)', 'pixonaut-for-woocommerce' ),
				ProductPricing::label( $item ),
				PricingPanel::money( $margin['price'] ),
				PricingPanel::percent( $margin['margin'] )
			);
		}
		$more = count( $ids ) - self::MOST_NAMED;
		if ( $more > 0 ) {
			/* translators: %d: how many more prices. Under a product's name on the admin products list. */
			$lines[] = sprintf( _n( 'and %d more', 'and %d more', $more, 'pixonaut-for-woocommerce' ), $more );
		}
		return sprintf(
			'<span class="pixonaut-margin-floor" style="display:block;margin-top:4px;color:#b32d2e;"><strong>%1$s</strong><span style="display:block;">%2$s</span></span>',
			esc_html(
				sprintf(
					/* translators: %s: the lowest margin the store accepts, such as 25.0 %. Under a product's name on the admin products list. */
					__( 'Below lowest margin of %s', 'pixonaut-for-woocommerce' ),
					PricingPanel::percent( $floor )
				)
			),
			implode( '<br />', array_map( 'esc_html', $lines ) )
		);
	}
}
