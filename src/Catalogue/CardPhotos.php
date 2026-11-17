<?php
/**
 * The variations a filtered shop card shows on their blank's photo.
 *
 * @package Pixonaut\WooCommerce
 */

declare(strict_types=1);

namespace Pixonaut\WooCommerce\Catalogue;

use Pixonaut\WooCommerce\Schema;
use WC_Product;

/**
 * A card a filtered listing shows (`CardPages::image()`) shows the whole
 * combination the filters pick: the variation it opens on, on its blank's
 * photo, a frame in the chosen colour, say. When the variation's image is
 * drawn flat, as for a store whose showcase sets are flat, every variation
 * shares one picture and none shows its frame. Such a variation is marked
 * the first time a card wants it (`want()`), and from then on is drawn on
 * its blank's photo too, beside its image (`PictureSources`,
 * `ProductPictures::on_photo()`). Variations are marked only once a card
 * asks, so a product of many sizes and colours draws the photos its
 * shoppers filter for, not one per variation.
 */
final class CardPhotos {

	public const META_KEY = Schema::VARIATION_CARD_PHOTO_META;

	private const MARKED = '1';

	/**
	 * Whether a card wants the variation on its blank's photo.
	 *
	 * @param WC_Product $product A product or variation.
	 */
	public static function wanted( WC_Product $product ): bool {
		return $product->is_type( 'variation' ) && self::MARKED === get_post_meta( $product->get_id(), self::META_KEY, true );
	}

	/**
	 * Marks the variation as one a card wants on its blank's photo: the
	 * meta's write queues its drawing (`PictureQueue`).
	 *
	 * @param WC_Product $variation A variation.
	 * @return bool Whether it was marked now, rather than before.
	 */
	public static function want( WC_Product $variation ): bool {
		if ( ! $variation->is_type( 'variation' ) || self::wanted( $variation ) ) {
			return false;
		}
		return false !== update_post_meta( $variation->get_id(), self::META_KEY, self::MARKED );
	}
}
