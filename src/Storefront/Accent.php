<?php
/**
 * The designer's accent colour.
 *
 * @package Pixonaut\WooCommerce
 */

declare(strict_types=1);

namespace Pixonaut\WooCommerce\Storefront;

use Pixonaut\WooCommerce\Settings\Settings;

/**
 * Which colour the designer rings its chosen view, swatch and tile in, fills
 * its sliders with and draws its Next button in: the SDK's `accent` mount
 * option. Without one the designer draws them in its own ink.
 */
final class Accent {

	/**
	 * The accent colour of the store's brand in Pixonaut, else the designer's own ink.
	 */
	public const NONE = 'none';

	/**
	 * The colour of the theme's buttons, which the storefront script reads
	 * from the page.
	 */
	public const THEME = 'theme';

	/**
	 * The colour in the `designer_accent_colour` setting.
	 */
	public const CUSTOM = 'custom';

	/**
	 * @return list<string> Every value, in the order the setting lists them.
	 */
	public static function available(): array {
		return array( self::NONE, self::THEME, self::CUSTOM );
	}

	/**
	 * @return array<string, string> Each value's label.
	 */
	public static function names(): array {
		return array(
			/* translators: Option in the settings for the designer accent colour. The merchant reads it. */
			self::NONE   => __( 'None: the accent of the store\'s brand in Pixonaut, else the designer\'s own colours', 'pixonaut-for-woocommerce' ),
			/* translators: Option in the settings for the designer accent colour. The merchant reads it. */
			self::THEME  => __( 'The theme\'s primary colour, as its buttons show it', 'pixonaut-for-woocommerce' ),
			/* translators: Option in the settings for the designer accent colour; a colour picker follows. The merchant reads it. */
			self::CUSTOM => __( 'A custom colour', 'pixonaut-for-woocommerce' ),
		);
	}

	/**
	 * The custom colour the store chose, or null when it chose none or the
	 * theme's, or left the colour empty.
	 *
	 * @param Settings $settings The store's settings.
	 */
	public static function custom( Settings $settings ): ?string {
		$colour = $settings->get( Settings::DESIGNER_ACCENT_COLOUR );
		return self::CUSTOM === $settings->get( Settings::DESIGNER_ACCENT ) && is_string( $colour ) && '' !== $colour ? $colour : null;
	}

	/**
	 * Whether the storefront script takes the accent from the theme.
	 *
	 * @param Settings $settings The store's settings.
	 */
	public static function follows_theme( Settings $settings ): bool {
		return self::THEME === $settings->get( Settings::DESIGNER_ACCENT );
	}
}
