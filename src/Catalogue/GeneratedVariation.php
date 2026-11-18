<?php
/**
 * What a product's plan did to one of its variations.
 *
 * @package Pixonaut\WooCommerce
 */

declare(strict_types=1);

namespace Pixonaut\WooCommerce\Catalogue;

use Pixonaut\WooCommerce\Schema;
use WC_Product;

/**
 * A variation the plan made or disabled says so in its
 * `_pixonaut_generated` meta, so generating again tells a variation the
 * plan disabled, which it publishes again once offered, from one the store
 * manager disabled, which it leaves.
 */
final class GeneratedVariation {

	public const META_KEY = Schema::VARIATION_GENERATED_META;

	/**
	 * The plan made the variation, or published it again.
	 */
	public const MADE = 'made';

	/**
	 * The plan disabled the variation.
	 */
	public const DISABLED = 'disabled';

	/**
	 * Whether the plan disabled the variation.
	 *
	 * @param WC_Product $variation A variation.
	 */
	public static function disabled( WC_Product $variation ): bool {
		return self::DISABLED === $variation->get_meta( self::META_KEY, true, 'edit' );
	}

	/**
	 * Notes what the plan did; the variation is saved by the caller.
	 *
	 * @param WC_Product $variation A variation.
	 * @param string     $what      `MADE` or `DISABLED`.
	 */
	public static function mark( WC_Product $variation, string $what ): void {
		$variation->update_meta_data( self::META_KEY, $what );
	}
}
