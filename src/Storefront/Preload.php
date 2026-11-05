<?php
/**
 * How the inline designer loads ahead.
 *
 * @package Pixonaut\WooCommerce
 */

declare(strict_types=1);

namespace Pixonaut\WooCommerce\Storefront;

/**
 * What the inline designer loads before the customer asks for it: the
 * product's other colours, views and sides, so switching to one shows it at
 * once. Each value is the SDK's `preload` mount option.
 */
final class Preload {

	/**
	 * The SDK picks from the device: nothing on save-data or a slow
	 * connection, more on a strong device.
	 */
	public const AUTO = 'auto';

	/**
	 * One at a time, while the page is idle.
	 */
	public const BACKGROUND = 'background';

	/**
	 * Nothing; each colour, view or side loads when it is shown.
	 */
	public const OFF = 'off';

	/**
	 * @return list<string> Every value, in the order the setting lists them.
	 */
	public static function available(): array {
		return array( self::AUTO, self::BACKGROUND, self::OFF );
	}

	/**
	 * @return array<string, string> Each value's label.
	 */
	public static function names(): array {
		return array(
			/* translators: Option in the settings for preloading product sides in the designer. The merchant reads it. */
			self::AUTO       => __( 'Auto: as much as the customer\'s device and connection allow', 'pixonaut-for-woocommerce' ),
			/* translators: Option in the settings for preloading product sides in the designer. The merchant reads it. */
			self::BACKGROUND => __( 'In the background, one at a time', 'pixonaut-for-woocommerce' ),
			/* translators: Option in the settings for preloading product sides in the designer. The merchant reads it. */
			self::OFF        => __( 'Off: load each one when it is shown', 'pixonaut-for-woocommerce' ),
		);
	}
}
