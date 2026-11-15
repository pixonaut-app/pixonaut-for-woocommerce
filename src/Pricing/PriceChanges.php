<?php
/**
 * Whether a product's or variation's prices change by themselves.
 *
 * @package Pixonaut\WooCommerce
 */

declare(strict_types=1);

namespace Pixonaut\WooCommerce\Pricing;

use Pixonaut\WooCommerce\Schema;
use WC_Product;

/**
 * The store has one switch for automatic price changes
 * (`Settings::PRICE_AUTOMATIC`). A product can answer it for itself, always
 * or never, and a variation can answer its product: kept as `ALWAYS` or
 * `NEVER` in its `_pixonaut_price_automatic` meta, which one that follows
 * the level above does not have.
 */
final class PriceChanges {

	public const META_KEY = Schema::PRICE_AUTOMATIC_META;

	/**
	 * A product follows the store's switch.
	 */
	public const STORE = 'store';

	/**
	 * A variation follows its product.
	 */
	public const PRODUCT = 'product';

	/**
	 * Its prices change by themselves, whatever the level above says.
	 */
	public const ALWAYS = 'always';

	/**
	 * Its prices never change by themselves.
	 */
	public const NEVER = 'never';

	/**
	 * What a product can be set to.
	 */
	public const OF_PRODUCT = array( self::STORE, self::ALWAYS, self::NEVER );

	/**
	 * What a variation can be set to.
	 */
	public const OF_VARIATION = array( self::PRODUCT, self::ALWAYS, self::NEVER );

	/**
	 * What a product or variation answers for itself: `ALWAYS`, `NEVER`, or
	 * null when it follows the level above.
	 *
	 * @param WC_Product $item The product or variation.
	 */
	public static function own( WC_Product $item ): ?string {
		$stored = $item->get_meta( self::META_KEY, true );
		return self::ALWAYS === $stored || self::NEVER === $stored ? $stored : null;
	}

	/**
	 * What a product or variation is set to, as the panel and the abilities
	 * say it: `ALWAYS`, `NEVER`, or the level it follows, `STORE` for a
	 * product and `PRODUCT` for a variation.
	 *
	 * @param WC_Product $item The product or variation.
	 */
	public static function setting( WC_Product $item ): string {
		return self::own( $item ) ?? self::above( $item );
	}

	/**
	 * Whether a value is one the product or variation can be set to.
	 *
	 * @param WC_Product $item  The product or variation.
	 * @param mixed      $value The value, as written.
	 */
	public static function allows( WC_Product $item, mixed $value ): bool {
		return in_array( $value, array( self::above( $item ), self::ALWAYS, self::NEVER ), true );
	}

	/**
	 * Stores what a product or variation is set to; anything but `ALWAYS`
	 * and `NEVER` has it follow the level above.
	 *
	 * @param WC_Product $item    The product or variation.
	 * @param mixed      $setting The setting, as written.
	 */
	public static function save( WC_Product $item, mixed $setting ): void {
		$own = self::ALWAYS === $setting || self::NEVER === $setting ? $setting : null;
		if ( self::own( $item ) === $own && ( null !== $own || '' === $item->get_meta( self::META_KEY, true ) ) ) {
			return;
		}
		if ( null === $own ) {
			$item->delete_meta_data( self::META_KEY );
		} else {
			$item->update_meta_data( self::META_KEY, $own );
		}
		$item->save_meta_data();
	}

	/**
	 * Whether the prices of a product or variation change by themselves.
	 *
	 * @param WC_Product $item  The product or variation.
	 * @param bool       $above What the level above says: the store for a product, its product for a variation.
	 */
	public static function automatic( WC_Product $item, bool $above ): bool {
		return match ( self::own( $item ) ) {
			self::ALWAYS => true,
			self::NEVER  => false,
			default      => $above,
		};
	}

	/**
	 * The level a product or variation follows when it answers nothing itself.
	 *
	 * @param WC_Product $item The product or variation.
	 */
	private static function above( WC_Product $item ): string {
		return $item->is_type( 'variation' ) ? self::PRODUCT : self::STORE;
	}
}
