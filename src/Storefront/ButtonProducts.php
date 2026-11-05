<?php
/**
 * Which products show the Personalise button.
 *
 * @package Pixonaut\WooCommerce
 */

declare(strict_types=1);

namespace Pixonaut\WooCommerce\Storefront;

use Pixonaut\WooCommerce\Catalogue\ProductConfig;
use Pixonaut\WooCommerce\Layout;
use Pixonaut\WooCommerce\Settings\Settings;
use WC_Product;

/**
 * Resolves a product to the configuration its page starts the Personalise
 * button on, or to none when its page shows no button.
 */
final class ButtonProducts {

	/**
	 * @param Settings $settings The default layout.
	 */
	public function __construct( private readonly Settings $settings ) {
	}

	/**
	 * Whether a configuration personalises on the button layout.
	 *
	 * @param ProductConfig $config A product's or variation's configuration.
	 */
	public function shows_button( ProductConfig $config ): bool {
		return Layout::personalises_on_button( $config, $this->settings );
	}

	/**
	 * Each variation's configuration as the designer sees it, by variation
	 * id; empty unless the product is variable.
	 *
	 * @param WC_Product $product The product the page shows.
	 * @return array<int, ProductConfig>
	 */
	public function variants( WC_Product $product ): array {
		return $product->is_type( 'variable' ) ? array_map( static fn ( ProductConfig $config ): ProductConfig => $config->for_designer(), ProductConfig::variation_configs( $product ) ) : array();
	}

	/**
	 * The configuration a product page starts the button on, else null when
	 * the page shows no button. A variable product shows it while it is
	 * personalised on the button layout or, when it is not personalised,
	 * while one of its variations is and none personalises inline, since the
	 * inline designer takes that page.
	 *
	 * @param WC_Product                $product  The product the page shows.
	 * @param array<int, ProductConfig> $variants On a variable product, each variation's configuration by variation id.
	 */
	public function page_config( WC_Product $product, array $variants ): ?ProductConfig {
		$config = ProductConfig::config_for( $product )->for_designer();
		if ( $config->personalise || array() === $variants ) {
			return $this->shows_button( $config ) ? $config : null;
		}
		$layouts = array();
		foreach ( $variants as $variant ) {
			if ( $variant->personalise ) {
				$layouts[] = Layout::resolve( $variant, $this->settings );
			}
		}
		return in_array( Layout::BUTTON, $layouts, true ) && ! in_array( Layout::INLINE, $layouts, true ) ? $config : null;
	}
}
