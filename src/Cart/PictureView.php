<?php
/**
 * The view a personalised line's picture shows its design in.
 *
 * @package Pixonaut\WooCommerce
 */

declare(strict_types=1);

namespace Pixonaut\WooCommerce\Cart;

/**
 * Which of its blank's views a personalised cart or order line shows the
 * customer's design in: the one they previewed it in, or the first of its
 * placement's views the product is shown in.
 */
final class PictureView {

	/**
	 * The view the customer previewed the design in; the placement's first
	 * when they previewed it in none.
	 */
	public const PREVIEWED = 'previewed';

	/**
	 * The first of the placement's views the product is shown in and its
	 * variant has a photo in, whichever the customer previewed.
	 */
	public const FIRST = 'first';

	/**
	 * @return list<string> Every value, in the order the setting lists them.
	 */
	public static function available(): array {
		return array( self::PREVIEWED, self::FIRST );
	}

	/**
	 * @return array<string, string> Each value's label.
	 */
	public static function names(): array {
		return array(
			/* translators: Option of the setting for which view of the product a personalised item's picture shows in the cart and orders. The merchant reads it. */
			self::PREVIEWED => __( 'The view the customer previewed', 'pixonaut-for-woocommerce' ),
			/* translators: Option of the setting for which view of the product a personalised item's picture shows in the cart and orders. The merchant reads it. */
			self::FIRST     => __( 'The placement’s first view', 'pixonaut-for-woocommerce' ),
		);
	}
}
