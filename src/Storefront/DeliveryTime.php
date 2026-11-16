<?php
/**
 * How long delivery takes, on the product page.
 *
 * @package Pixonaut\WooCommerce
 */

declare(strict_types=1);

namespace Pixonaut\WooCommerce\Storefront;

use Closure;
use Pixonaut\WooCommerce\Pricing\DeliveryTimes;
use Pixonaut\WooCommerce\Pricing\ProductPricing;
use Pixonaut\WooCommerce\Settings\Settings;
use WC_Product;
use WC_Product_Variable;

/**
 * While the store shows delivery times (`delivery_time_show`), a product or
 * variation printed on a blank says how long its printshop says it takes,
 * as Pixonaut read it with the cost: a line under Add to cart, after the
 * price row a theme closes there. On a variable product's page the line is
 * the chosen variation's, in a place that keeps its height while the
 * shopper switches (`VariationSlots`), never in the variation's
 * availability, which WooCommerce redraws between the description and the
 * price row with each choice.
 *
 * Only what the printshop tells is said. With the days from the order to
 * the door (told as such, or as its making and its post together) the line
 * is "Delivered in 4–7 days"; with the making alone "Made in 2–3 days";
 * with the post alone "Shipping takes 5–10 days". A printshop that tells no
 * time gets no line. The store's handling days are added to the days that
 * start at the order, never to the post alone.
 */
final class DeliveryTime {

	public const CSS_CLASS = 'pixonaut-delivery-time';

	/**
	 * After the price row a theme closes after the button (at the default
	 * priority).
	 */
	private const PRIORITY = 20;

	/**
	 * @param Settings       $settings Whether the time is shown, and the store's handling days.
	 * @param VariationSlots $slots    The place that keeps its height.
	 */
	public function __construct(
		private readonly Settings $settings,
		private readonly VariationSlots $slots,
	) {
	}

	public function register(): void {
		add_action( 'woocommerce_after_add_to_cart_button', array( $this, 'print_line' ), self::PRIORITY );
	}

	/**
	 * Prints the delivery time of the product the add-to-cart form is for:
	 * each variation's, for a variable product, of which the chosen one's
	 * shows.
	 */
	public function print_line(): void {
		$product = $GLOBALS['product'] ?? null;
		if ( ! $product instanceof WC_Product || true !== $this->settings->get( Settings::DELIVERY_TIME_SHOW ) ) {
			return;
		}
		if ( $product instanceof WC_Product_Variable ) {
			$lines = array();
			foreach ( VariationSlots::variations( $product ) as $variation ) {
				$lines[ $variation->get_id() ] = esc_html( $this->line_of( $variation ) );
			}
			// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Each line is escaped above; the markup is escaped where it is built.
			echo $this->slots->varying( self::CSS_CLASS, 'p', $lines );
			return;
		}
		// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- The line is escaped here; the markup is escaped where it is built.
		echo $this->slots->fixed( self::CSS_CLASS, 'p', esc_html( $this->line_of( $product ) ) );
	}

	/**
	 * What is said of a product's or a variation's delivery time; empty for
	 * one out of stock or whose printshop tells none.
	 *
	 * @param WC_Product $product The product or variation.
	 */
	public function line_of( WC_Product $product ): string {
		return $product->is_in_stock() ? $this->line( ProductPricing::times_of( $product ) ) : '';
	}

	/**
	 * What is said of the times a printshop tells; empty when it tells none.
	 *
	 * @param DeliveryTimes $times The times.
	 */
	public function line( DeliveryTimes $times ): string {
		$handling = $this->settings->get( Settings::DELIVERY_TIME_HANDLING_DAYS );
		$handling = is_int( $handling ) ? $handling : 0;
		$to_door  = $times->to_door();
		if ( null !== $to_door ) {
			return self::said(
				$to_door[0] + $handling,
				$to_door[1] + $handling,
				/* translators: %1$d and %2$d: the shortest and the longest number of days, e.g. 4 and 7. Shown on the product page: how long until the customer has the order. */
				__( 'Delivered in %1$d–%2$d days', 'pixonaut-for-woocommerce' ),
				/* translators: %d: a number of days. Shown on the product page: how long until the customer has the order. */
				static fn ( int $days ): string => _n( 'Delivered in %d day', 'Delivered in %d days', $days, 'pixonaut-for-woocommerce' )
			);
		}
		if ( null !== $times->production ) {
			return self::said(
				$times->production[0] + $handling,
				$times->production[1] + $handling,
				/* translators: %1$d and %2$d: the shortest and the longest number of days, e.g. 2 and 3. Shown on the product page: how long the product takes to make before it is sent. */
				__( 'Made in %1$d–%2$d days', 'pixonaut-for-woocommerce' ),
				/* translators: %d: a number of days. Shown on the product page: how long the product takes to make before it is sent. */
				static fn ( int $days ): string => _n( 'Made in %d day', 'Made in %d days', $days, 'pixonaut-for-woocommerce' )
			);
		}
		if ( null !== $times->shipping ) {
			return self::said(
				$times->shipping[0],
				$times->shipping[1],
				/* translators: %1$d and %2$d: the shortest and the longest number of days, e.g. 5 and 10. Shown on the product page: how long the parcel is on its way once it is sent. */
				__( 'Shipping takes %1$d–%2$d days', 'pixonaut-for-woocommerce' ),
				/* translators: %d: a number of days. Shown on the product page: how long the parcel is on its way once it is sent. */
				static fn ( int $days ): string => _n( 'Shipping takes %d day', 'Shipping takes %d days', $days, 'pixonaut-for-woocommerce' )
			);
		}
		return '';
	}

	/**
	 * A span of days in words: both ends, or the one count when they are
	 * the same.
	 *
	 * @param int     $min   The shortest.
	 * @param int     $max   The longest.
	 * @param string  $range The sentence for two counts.
	 * @param Closure $one   The sentence for one count: `fn( int ): string`, the count in.
	 * @phpstan-param Closure(int): string $one
	 */
	private static function said( int $min, int $max, string $range, Closure $one ): string {
		return $min === $max
			? sprintf( $one( $max ), $max )
			: sprintf( $range, $min, $max );
	}
}
