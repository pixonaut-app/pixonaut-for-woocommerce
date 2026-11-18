<?php
/**
 * Flags prices on the products list.
 *
 * @package Pixonaut\WooCommerce
 */

declare(strict_types=1);

namespace Pixonaut\WooCommerce\Admin;

use Pixonaut\WooCommerce\Pricing\PriceRow;
use Pixonaut\WooCommerce\Pricing\PriceState;
use Pixonaut\WooCommerce\Pricing\ProductPricing;
use WC_Product;

/**
 * On the products list, a product with a price that follows the pricing
 * rule and whose margin left the tolerance is flagged under its name, with
 * the price it has, the margin and contribution it leaves, the price the
 * rule suggests and a link that applies it. **Apply suggested
 * prices** is a bulk action too. A price the rule cannot set (its printshop
 * publishes no cost, say) is flagged with the reason, without a link.
 */
final class PricingList {

	/**
	 * The bulk action, and the link under a flagged product.
	 */
	public const BULK_ACTION = 'pixonaut_apply_suggested_prices';

	/**
	 * The products list's query argument saying how many prices were set.
	 */
	public const DONE_ARG = 'pixonaut_prices_applied';

	/**
	 * The products list's name column: the widest, so a flag reads on one line.
	 */
	private const FLAG_COLUMN = 'name';

	/**
	 * After WooCommerce printed the column.
	 */
	private const COLUMN_PRIORITY = 20;

	/**
	 * The arguments `manage_product_posts_custom_column` passes.
	 */
	private const COLUMN_ARGS = 2;

	/**
	 * The arguments `handle_bulk_actions-edit-product` passes.
	 */
	private const BULK_ARGS = 3;

	/**
	 * The nonce WordPress checks before a products list bulk action.
	 */
	private const BULK_NONCE = 'bulk-posts';

	/**
	 * Flagged prices named under a product's name; the rest are counted.
	 */
	public const MOST_NAMED = 3;

	public function __construct( private readonly ProductPricing $pricing ) {
	}

	public function register(): void {
		add_action( 'manage_product_posts_custom_column', array( $this, 'column' ), self::COLUMN_PRIORITY, self::COLUMN_ARGS );
		add_filter( 'bulk_actions-edit-product', array( $this, 'bulk_actions' ) );
		add_filter( 'handle_bulk_actions-edit-product', array( $this, 'handle_bulk' ), 10, self::BULK_ARGS );
		add_action( 'admin_notices', array( $this, 'bulk_notice' ) );
	}

	/**
	 * Prints the flag under a product's name.
	 *
	 * @param mixed $column  The column printed.
	 * @param mixed $post_id The row's product.
	 */
	public function column( mixed $column, mixed $post_id ): void {
		if ( self::FLAG_COLUMN !== $column ) {
			return;
		}
		$product = wc_get_product( (int) $post_id );
		if ( ! $product instanceof WC_Product ) {
			return;
		}
		echo $this->flag_html( $product ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped where it is built.
	}

	/**
	 * A product's flag: each flagged price with what the rule suggests, up
	 * to `MOST_NAMED`, and the link that applies the suggestions. Empty for
	 * a product with nothing flagged.
	 *
	 * @param WC_Product $product The product.
	 */
	public function flag_html( WC_Product $product ): string {
		// The count was noted when the prices were last settled; the rows say what is true now.
		$flagged = 0 === ProductPricing::flagged( $product ) ? array() : $this->flagged( $product );
		if ( array() === $flagged ) {
			return '';
		}
		$texts = PricingPanel::flag_texts();
		$lines = '';
		foreach ( array_slice( $flagged, 0, self::MOST_NAMED ) as $row ) {
			$shown = PricingPanel::row( $row );
			$what  = null === $row->suggested || ! in_array( $row->flag, array( PriceState::FLAG_MARGIN, PriceState::FLAG_RULE ), true )
				? ( $texts[ (string) $row->flag ] ?? '' )
				/* translators: 1: the price now, 2: the price the pricing rule suggests. Under a product's name on the admin products list. */
				: sprintf( __( '%1$s → %2$s suggested', 'pixonaut-for-woocommerce' ), $shown['price'], $shown['suggested'] );
			if ( '' !== $shown['margin'] && '' !== $shown['contribution'] && '' !== $shown['source'] ) {
				$what = sprintf(
					/* translators: 1: why a price is flagged, or the price now and the one suggested; 2: the margin the price now leaves, such as 32.5 %; 3: the amount of money it leaves over the cost excluding VAT; 4: where its pricing rule comes from, such as "Ram (Frame), Store". Under a product's name on the admin products list. */
					__( '%1$s (margin %2$s, contribution %3$s, rule from %4$s)', 'pixonaut-for-woocommerce' ),
					$what,
					$shown['margin'],
					$shown['contribution'],
					$shown['source']
				);
			} elseif ( '' !== $shown['margin'] && '' !== $shown['contribution'] ) {
				$what = sprintf(
					/* translators: 1: why a price is flagged, or the price now and the one suggested; 2: the margin the price now leaves, such as 32.5 %; 3: the amount of money it leaves over the cost excluding VAT. Under a product's name on the admin products list. */
					__( '%1$s (margin %2$s, contribution %3$s)', 'pixonaut-for-woocommerce' ),
					$what,
					$shown['margin'],
					$shown['contribution']
				);
			}
			$lines .= sprintf(
				'<span class="pixonaut-price-flag__line">%s</span>',
				esc_html( $product->is_type( 'variable' ) ? $row->label . ': ' . $what : $what )
			);
		}
		$more = count( $flagged ) - self::MOST_NAMED;
		if ( $more > 0 ) {
			/* translators: %d: how many more prices. Under a product's name on the admin products list. */
			$lines .= sprintf( '<span class="pixonaut-price-flag__line">%s</span>', esc_html( sprintf( _n( 'and %d more', 'and %d more', $more, 'pixonaut-for-woocommerce' ), $more ) ) );
		}
		$link = '';
		if ( array() !== self::suggested( $flagged ) && current_user_can( 'edit_post', $product->get_id() ) ) {
			$url  = wp_nonce_url(
				add_query_arg(
					array(
						'post_type' => 'product',
						'action'    => self::BULK_ACTION,
						'post[]'    => $product->get_id(),
					),
					admin_url( 'edit.php' )
				),
				self::BULK_NONCE
			);
			$link = sprintf( '<a href="%1$s" class="pixonaut-price-flag__apply">%2$s</a>', esc_url( $url ), esc_html( self::label() ) );
		}
		return sprintf(
			'<span class="pixonaut-price-flag" style="display:block;margin-top:4px;color:#996800;"><strong>%1$s</strong><span style="display:block;">%2$s</span>%3$s</span>',
			esc_html(
				array() !== self::suggested( $flagged )
					? ( array() === array_filter( $flagged, static fn ( PriceRow $row ): bool => PriceState::FLAG_MARGIN === $row->flag )
						/* translators: Flag under a product's name on the admin products list: a level of the pricing rule changed, and the rule has other prices for the product. */
						? __( 'Pricing rule changed', 'pixonaut-for-woocommerce' )
						/* translators: Flag under a product's name on the admin products list: the margin of a price that follows the pricing rule left the tolerance. */
						: __( 'Margin off target', 'pixonaut-for-woocommerce' ) )
					/* translators: Flag under a product's name on the admin products list: the pricing rule cannot set one of its prices. */
					: __( 'Price not set by the pricing rule', 'pixonaut-for-woocommerce' )
			),
			str_replace( '</span><span', '</span><br /><span', $lines ),
			$link
		);
	}

	/**
	 * @param mixed $actions The products list's bulk actions.
	 * @return mixed
	 */
	public function bulk_actions( mixed $actions ): mixed {
		if ( is_array( $actions ) ) {
			$actions[ self::BULK_ACTION ] = self::label();
		}
		return $actions;
	}

	/**
	 * Sets the suggested prices of the selected products; WordPress checked
	 * the list's nonce. A product the user cannot edit is passed over.
	 *
	 * @param mixed $redirect_to Where the list goes back to.
	 * @param mixed $action      The bulk action.
	 * @param mixed $post_ids    The selected products.
	 */
	public function handle_bulk( mixed $redirect_to, mixed $action, mixed $post_ids ): mixed {
		if ( self::BULK_ACTION !== $action || ! is_string( $redirect_to ) ) {
			return $redirect_to;
		}
		$changed = 0;
		foreach ( is_array( $post_ids ) ? $post_ids : array() as $post_id ) {
			$product = current_user_can( 'edit_post', (int) $post_id ) ? wc_get_product( (int) $post_id ) : null;
			if ( ! $product instanceof WC_Product || ! ProductPricing::prices( $product ) ) {
				continue;
			}
			$ids = self::suggested( $this->flagged( $product ) );
			if ( array() !== $ids ) {
				$changed += $this->pricing->apply( $product, $ids );
			}
		}
		return add_query_arg( self::DONE_ARG, $changed, remove_query_arg( self::DONE_ARG, $redirect_to ) );
	}

	/**
	 * Says on the products list how many prices the action set.
	 */
	public function bulk_notice(): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- a count to show, set by handle_bulk().
		if ( ! isset( $_GET[ self::DONE_ARG ] ) || 'edit-product' !== get_current_screen()?->id ) {
			return;
		}
		$changed = absint( wp_unslash( $_GET[ self::DONE_ARG ] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- as above.
		$text    = 0 === $changed
			? __( 'None of the selected products has a suggested price, so no price was changed.', 'pixonaut-for-woocommerce' )
			/* translators: %d: number of prices. Admin products list, after the Apply suggested prices action. */
			: sprintf( _n( '%d price was set to the price the pricing rule suggests.', '%d prices were set to the prices the pricing rule suggests.', $changed, 'pixonaut-for-woocommerce' ), $changed );
		printf( '<div class="notice notice-%1$s is-dismissible"><p>%2$s</p></div>', esc_attr( 0 === $changed ? 'warning' : 'success' ), esc_html( $text ) );
	}

	/**
	 * The prices of a product that follow the rule and are flagged.
	 *
	 * @param WC_Product $product The product.
	 * @return list<PriceRow>
	 */
	private function flagged( WC_Product $product ): array {
		return array_values( array_filter( $this->pricing->rows( $product ), static fn ( PriceRow $row ): bool => PriceState::FOLLOWS === $row->standing && null !== $row->flag ) );
	}

	/**
	 * The products or variations, among flagged rows, the rule suggests a
	 * price for that it can set.
	 *
	 * @param PriceRow[] $rows Flagged rows.
	 * @phpstan-param list<PriceRow> $rows
	 * @return list<int>
	 */
	private static function suggested( array $rows ): array {
		return array_values( array_map( static fn ( PriceRow $row ): int => $row->id, array_filter( $rows, static fn ( PriceRow $row ): bool => in_array( $row->flag, array( PriceState::FLAG_MARGIN, PriceState::FLAG_RULE ), true ) && null !== $row->suggested ) ) );
	}

	private static function label(): string {
		/* translators: Bulk action on the admin products list, and link under a flagged product: sets the prices the pricing rule suggests. */
		return __( 'Apply suggested prices', 'pixonaut-for-woocommerce' );
	}
}
