<?php
/**
 * Whether each variation of a product gets a picture of its own.
 *
 * @package Pixonaut\WooCommerce
 */

declare(strict_types=1);

namespace Pixonaut\WooCommerce\Catalogue;

use Pixonaut\WooCommerce\Schema;
use Pixonaut\WooCommerce\Settings\Settings;
use WC_Product;

/**
 * With showcase sets drawn flat, every variation of a product shares one
 * picture, whichever colour or frame it sells. With "Generate a picture
 * for every variation" on, a variation whose image would be drawn flat is
 * drawn on its blank's photo instead, as the variant it sells
 * (`PictureSources`), so the product page shows the variation the shopper
 * picks. Variations of one variant (sizes of a poster, say) still share
 * that picture (`SharedPictures`).
 *
 * The store-wide setting (`Settings::VARIATION_PICTURES`) is the default;
 * a product may turn it on or off for itself (`META_KEY`). Turning it off
 * draws the variations flat again, which deletes the pictures only they
 * showed. A variation that chose its own picture view keeps it either way.
 */
final class VariationPictures {

	public const META_KEY = Schema::PRODUCT_VARIATION_PICTURES_META;

	/**
	 * The product follows the store-wide setting.
	 */
	public const DEFAULT = 'default';

	public const ON = 'on';

	public const OFF = 'off';

	public const CHOICES = array( self::DEFAULT, self::ON, self::OFF );

	/**
	 * Read once: the settings are read from the option at every `get()`.
	 *
	 * @var Settings|null
	 */
	private static ?Settings $settings = null;

	/**
	 * The product's own choice: DEFAULT, ON or OFF.
	 *
	 * @param WC_Product $product A product; a variation's is its product's.
	 */
	public static function choice( WC_Product $product ): string {
		$owner  = self::owner( $product );
		$choice = null === $owner ? '' : $owner->get_meta( self::META_KEY );
		return in_array( $choice, array( self::ON, self::OFF ), true ) ? $choice : self::DEFAULT;
	}

	/**
	 * Stores the product's own choice; DEFAULT forgets it. The meta write
	 * queues its variations' pictures (`PictureQueue`).
	 *
	 * @param WC_Product $product A simple or variable product.
	 * @param string     $choice  DEFAULT, ON or OFF.
	 */
	public static function choose( WC_Product $product, string $choice ): void {
		if ( self::DEFAULT === $choice || ! in_array( $choice, self::CHOICES, true ) ) {
			$product->delete_meta_data( self::META_KEY );
		} else {
			$product->update_meta_data( self::META_KEY, $choice );
		}
		$product->save_meta_data();
	}

	/**
	 * Whether the store-wide setting is on.
	 *
	 * @param Settings|null $settings The store's settings; read when null.
	 */
	public static function store_wide( ?Settings $settings = null ): bool {
		self::$settings ??= new Settings();
		return true === ( $settings ?? self::$settings )->get( Settings::VARIATION_PICTURES );
	}

	/**
	 * Whether the product's variations each get a picture of their own: its
	 * own choice, else the store-wide setting.
	 *
	 * @param WC_Product    $product  A product; a variation's is its product's.
	 * @param Settings|null $settings The store's settings; read when null.
	 */
	public static function on_for( WC_Product $product, ?Settings $settings = null ): bool {
		return self::resolves( self::choice( $product ), self::store_wide( $settings ) );
	}

	/**
	 * Whether a choice turns it on, under a store-wide setting.
	 *
	 * @param string $choice     DEFAULT, ON or OFF.
	 * @param bool   $store_wide The store-wide setting.
	 */
	public static function resolves( string $choice, bool $store_wide ): bool {
		return self::ON === $choice || ( self::OFF !== $choice && $store_wide );
	}

	/**
	 * Whether the variation is drawn on its blank's photo for it: it is on
	 * for its product, and the variation chose no picture view of its own.
	 *
	 * @param WC_Product    $product  A product or variation.
	 * @param Settings|null $settings The store's settings; read when null.
	 */
	public static function draws( WC_Product $product, ?Settings $settings = null ): bool {
		return $product->is_type( 'variation' ) && null === PictureView::on( $product ) && self::on_for( $product, $settings );
	}

	/**
	 * The product, or a variation's product; null when that is gone.
	 *
	 * @param WC_Product $product A product or variation.
	 */
	private static function owner( WC_Product $product ): ?WC_Product {
		if ( ! $product->is_type( 'variation' ) ) {
			return $product;
		}
		$parent = wc_get_product( $product->get_parent_id() );
		return $parent instanceof WC_Product ? $parent : null;
	}
}
