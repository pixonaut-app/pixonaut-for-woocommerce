<?php
/**
 * The mount options that put a side's designer on its product.
 *
 * @package Pixonaut\WooCommerce
 */

declare(strict_types=1);

namespace Pixonaut\WooCommerce\Storefront;

use Pixonaut\WooCommerce\Catalogue\PrintSize;
use Pixonaut\WooCommerce\Catalogue\ProductConfig;
use Pixonaut\WooCommerce\Catalogue\Side;

/**
 * What a product page and a page editing a design made on it both mount a
 * side with, so the two look alike: the blank's mockup, views and variants,
 * the print size menu, and the canvas the customer draws on.
 */
final class ProductMount {

	/**
	 * `blankId`, `placementId`, `placements`, `views`, `initialView` and
	 * `variants` with a blank, `printSizes` with a size menu, and
	 * `drawAtTemplateRatio` when the side or its configuration chooses the
	 * canvas: left out, the template's own setting decides.
	 *
	 * @param ProductConfig $config   The configuration the side is sold under.
	 * @param Side          $side     The side.
	 * @param string[]      $variants The refs of the blank's variants the product sells; empty for none known.
	 * @phpstan-param list<string> $variants
	 *
	 * @return array<string, mixed>
	 */
	public static function options( ProductConfig $config, Side $side, array $variants = array() ): array {
		$options = array();
		if ( null !== $config->blank_id && null !== $side->placement_id ) {
			$options['blankId']     = $config->blank_id;
			$options['placementId'] = $side->placement_id;
			// Each side is its own design, so its designer sells its own placement alone, printed with the mount's template.
			$options['placements'] = array( array( 'placementId' => $side->placement_id ) );
			$options['views']      = $config->views;
			if ( null !== $config->initial_view ) {
				// The SDK opens a side on it only when it is that side's placement's.
				$options['initialView'] = $config->initial_view;
			}
			if ( array() !== $variants ) {
				$options['variants'] = $variants;
			}
		}
		if ( array() !== $side->print_sizes ) {
			$options['printSizes'] = array_map( static fn ( PrintSize $size ): array => $size->to_array(), $side->print_sizes );
		}
		$draw = $config->draw_at_template_ratio_for( $side );
		if ( null !== $draw ) {
			$options['drawAtTemplateRatio'] = $draw;
		}
		return $options;
	}
}
