<?php
/**
 * How the designer's form shows a variation attribute's options.
 *
 * @package Pixonaut\WooCommerce
 */

declare(strict_types=1);

namespace Pixonaut\WooCommerce\Storefront;

use Pixonaut\WooCommerce\Settings\Settings;

/**
 * How a variation attribute the designer draws in its form offers its
 * options: in the theme's own field (the classic form's select, the block
 * form's chips or dropdown), or as swatches drawn like the designer's own
 * choices, which pick the option in the theme's field.
 */
final class AttributeOptions {

	/**
	 * The theme's own field, as it renders it.
	 */
	public const THEME = 'theme';

	/**
	 * Swatches in the designer's look.
	 */
	public const SWATCHES = 'swatches';

	/**
	 * @return list<string> Every value, in the order the setting lists them.
	 */
	public static function available(): array {
		return array( self::THEME, self::SWATCHES );
	}

	/**
	 * @return array<string, string> Each value's label.
	 */
	public static function names(): array {
		return array(
			/* translators: Option in the settings for how a product option such as a size shows in the designer's form. The merchant reads it. */
			self::THEME    => __( 'As the theme shows them', 'pixonaut-for-woocommerce' ),
			/* translators: Option in the settings for how a product option such as a size shows in the designer's form: as a row of buttons, one per option, drawn like the designer's own. The merchant reads it. */
			self::SWATCHES => __( 'As swatches, like the designer\'s own choices', 'pixonaut-for-woocommerce' ),
		);
	}

	/**
	 * Whether the store draws the options as swatches.
	 *
	 * @param Settings $settings The store's settings.
	 */
	public static function swatches( Settings $settings ): bool {
		return self::SWATCHES === $settings->get( Settings::ATTRIBUTE_OPTIONS );
	}
}
