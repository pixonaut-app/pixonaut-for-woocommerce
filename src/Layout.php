<?php
/**
 * Product page layouts.
 *
 * @package Pixonaut\WooCommerce
 */

declare(strict_types=1);

namespace Pixonaut\WooCommerce;

use Pixonaut\WooCommerce\Catalogue\ProductConfig;
use Pixonaut\WooCommerce\Settings\Settings;

/**
 * How a personalised product page shows the designer.
 */
final class Layout {

	/**
	 * Live preview in place of the gallery, form in the summary.
	 */
	public const INLINE = 'inline';

	/**
	 * A button that opens Pixonaut's hosted designer.
	 */
	public const BUTTON = 'button';

	/**
	 * The feature that gates each layout.
	 */
	public const FEATURES = array(
		self::INLINE => Features::LAYOUT_INLINE,
		self::BUTTON => Features::LAYOUT_BUTTON,
	);

	/**
	 * @return list<string> The layouts whose feature is enabled.
	 */
	public static function available(): array {
		return array_keys( array_filter( self::FEATURES, array( Features::class, 'enabled' ) ) );
	}

	/**
	 * Each layout's name, for the store manager.
	 *
	 * @return array<string, string>
	 */
	public static function names(): array {
		return array(
			/* translators: Option in the layout setting, shown to the merchant on the settings page and the product page. */
			self::INLINE => __( 'Inline: live preview in place of the product image', 'pixonaut-for-woocommerce' ),
			/* translators: Option in the layout setting, shown to the merchant; Personalise is the name of the button on the product page. */
			self::BUTTON => __( 'Personalise button: opens the Pixonaut designer', 'pixonaut-for-woocommerce' ),
		);
	}

	/**
	 * A personalised product's layout: its own when it names an available
	 * one, else the store's default; empty when that layout's feature is off.
	 *
	 * @param ProductConfig $config   The product's configuration.
	 * @param Settings      $settings The store's settings.
	 */
	public static function resolve( ProductConfig $config, Settings $settings ): string {
		$layout = in_array( $config->layout, self::available(), true ) ? $config->layout : $settings->get( Settings::DEFAULT_LAYOUT );
		return Features::enabled( self::FEATURES[ $layout ] ?? '' ) ? (string) $layout : '';
	}

	/**
	 * Whether a configuration personalises on the Personalise button.
	 *
	 * @param ProductConfig $config   A product's or variation's configuration.
	 * @param Settings      $settings The store's settings.
	 */
	public static function personalises_on_button( ProductConfig $config, Settings $settings ): bool {
		return $config->personalise && self::BUTTON === self::resolve( $config, $settings );
	}
}
