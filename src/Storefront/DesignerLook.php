<?php
/**
 * The mount options the store's settings give every inline designer.
 *
 * @package Pixonaut\WooCommerce
 */

declare(strict_types=1);

namespace Pixonaut\WooCommerce\Storefront;

use Pixonaut\WooCommerce\Connection\WorkspaceSettings;
use Pixonaut\WooCommerce\Settings\Settings;

/**
 * What every inline designer mounts with from the store's settings, on a
 * product page and on a page opened to edit a design alike: how much it
 * loads ahead, whether it frames the print area, and its accent colour:
 * the store's custom one, or with the accent set to none, that of the
 * store's brand in Pixonaut when it has one.
 */
final class DesignerLook {

	/**
	 * @param Settings $settings The store's settings.
	 * @return array<string, mixed> `preload`, `showPrintAreaFrame`, and `accent` with a custom or brand colour.
	 */
	public static function mount_options( Settings $settings ): array {
		$options = array(
			'preload'            => $settings->get( Settings::DESIGNER_PRELOAD ),
			'showPrintAreaFrame' => true === $settings->get( Settings::PRINT_AREA_FRAME ),
		);
		$accent  = Accent::custom( $settings ) ?? ( Accent::NONE === $settings->get( Settings::DESIGNER_ACCENT ) ? WorkspaceSettings::accent() : null );
		if ( null !== $accent ) {
			$options['accent'] = $accent;
		}
		return $options;
	}
}
