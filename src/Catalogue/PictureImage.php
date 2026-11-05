<?php
/**
 * A product's real image, while it is its Pixonaut picture.
 *
 * @package Pixonaut\WooCommerce
 */

declare(strict_types=1);

namespace Pixonaut\WooCommerce\Catalogue;

use Pixonaut\WooCommerce\Schema;
use WC_Product;

/**
 * Sets a product's or variation's own image (`_thumbnail_id`) to its
 * Pixonaut picture, and puts back the image it had before. The image it had
 * when the picture first became its image is remembered; from then on the
 * picture replaces only an empty image or an earlier picture of it, so an
 * image the store manager sets is kept.
 *
 * The image is written as post meta, so no product save hook runs.
 */
final class PictureImage {

	public const PREVIOUS_META = Schema::PRODUCT_PREVIOUS_IMAGE_META;

	public const PICTURE_OF_META = Schema::PICTURE_OF_META;

	/**
	 * The post meta WordPress and WooCommerce keep a post's image in.
	 */
	public const THUMBNAIL_META = '_thumbnail_id';

	/**
	 * The product's or variation's own image; 0 for none.
	 *
	 * @param WC_Product $product A product or variation.
	 */
	public static function current( WC_Product $product ): int {
		return (int) get_post_meta( $product->get_id(), self::THUMBNAIL_META, true );
	}

	/**
	 * Whether the attachment is a Pixonaut picture of the product or
	 * variation and still there.
	 *
	 * @param int        $attachment An attachment id; 0 for none.
	 * @param WC_Product $product    A product or variation.
	 */
	public static function is_picture( int $attachment, WC_Product $product ): bool {
		return SharedPictures::is_picture( $attachment, $product );
	}

	/**
	 * Whether the picture has been its image, so the image it had then is remembered.
	 *
	 * @param WC_Product $product A product or variation.
	 */
	public static function remembers( WC_Product $product ): bool {
		return metadata_exists( 'post', $product->get_id(), self::PREVIOUS_META );
	}

	/**
	 * Whether the product shows an image the store manager set after its
	 * picture had been its image: one that is neither empty nor its picture.
	 *
	 * @param WC_Product $product A product or variation.
	 */
	public static function is_own( WC_Product $product ): bool {
		$current = self::current( $product );
		return self::remembers( $product ) && $current > 0 && ! self::is_picture( $current, $product );
	}

	/**
	 * Makes the picture the product's image: the first time in place of any
	 * image, which is remembered; afterwards only in place of an empty image
	 * or an earlier picture.
	 *
	 * @param WC_Product $product A product or variation.
	 * @param int        $picture Its picture.
	 */
	public static function show( WC_Product $product, int $picture ): void {
		$current = self::stored_image( $product );
		if ( $current === $picture ) {
			return;
		}
		if ( ! self::remembers( $product ) ) {
			update_post_meta( $product->get_id(), self::PREVIOUS_META, self::is_picture( $current, $product ) ? 0 : $current );
		} elseif ( $current > 0 && ! self::is_picture( $current, $product ) ) {
			return;
		}
		self::write( $product, $picture );
	}

	/**
	 * Puts back the image the product had before its picture, unless the
	 * store manager has set another one since, and forgets it.
	 *
	 * @param WC_Product $product A product or variation.
	 */
	public static function restore( WC_Product $product ): void {
		if ( ! self::remembers( $product ) ) {
			return;
		}
		$current  = self::stored_image( $product );
		$previous = (int) get_post_meta( $product->get_id(), self::PREVIOUS_META, true );
		delete_post_meta( $product->get_id(), self::PREVIOUS_META );
		if ( 0 === $current || self::is_picture( $current, $product ) ) {
			// An image deleted from the media library since is not put back.
			self::write( $product, $previous > 0 && null !== get_post( $previous ) ? $previous : 0 );
		}
	}

	/**
	 * Puts the picture back in place of the store manager's own image, as
	 * "Use the Pixonaut picture" on the product screen does: the drawn
	 * picture, or no image while none is drawn, so the picture takes its
	 * place once it is.
	 *
	 * @param WC_Product $product A product or variation.
	 * @param int        $picture Its drawn picture; 0 for none.
	 */
	public static function use_picture( WC_Product $product, int $picture ): void {
		self::write( $product, self::is_picture( $picture, $product ) ? $picture : 0 );
	}

	/**
	 * The image as stored now, not as this request read it earlier: a
	 * drawing takes a while, and the store manager may set one meanwhile.
	 *
	 * @param WC_Product $product A product or variation.
	 */
	private static function stored_image( WC_Product $product ): int {
		wp_cache_delete( (string) $product->get_id(), 'post_meta' );
		return self::current( $product );
	}

	/**
	 * @param WC_Product $product A product or variation.
	 * @param int        $image   An attachment id; 0 for none.
	 */
	private static function write( WC_Product $product, int $image ): void {
		if ( $image > 0 ) {
			update_post_meta( $product->get_id(), self::THUMBNAIL_META, $image );
		} else {
			delete_post_meta( $product->get_id(), self::THUMBNAIL_META );
		}
		wc_delete_product_transients( $product->get_id() );
	}
}
