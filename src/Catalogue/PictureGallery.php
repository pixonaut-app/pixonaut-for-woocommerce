<?php
/**
 * A product's gallery, while it holds Pixonaut pictures.
 *
 * @package Pixonaut\WooCommerce
 */

declare(strict_types=1);

namespace Pixonaut\WooCommerce\Catalogue;

use WC_Product;

/**
 * Puts a product's Pixonaut pictures beyond its image, one per further
 * showcase set, first in its gallery (`_product_image_gallery`), followed by
 * the gallery's other images, which stay as the store manager set them.
 *
 * The gallery is written as post meta, so no product save hook runs.
 */
final class PictureGallery {

	/**
	 * The post meta WooCommerce keeps a product's gallery in: attachment ids, comma separated.
	 */
	public const GALLERY_META = '_product_image_gallery';

	/**
	 * Makes the pictures the first of the product's gallery images, in
	 * place of its earlier ones; none takes them out.
	 *
	 * @param WC_Product $product  A product.
	 * @param int[]      $pictures Its pictures beyond its image, in order.
	 * @phpstan-param list<int> $pictures
	 */
	public static function show( WC_Product $product, array $pictures ): void {
		wp_cache_delete( (string) $product->get_id(), 'post_meta' );
		$current = array_values( array_filter( array_map( 'intval', explode( ',', (string) get_post_meta( $product->get_id(), self::GALLERY_META, true ) ) ) ) );
		$others  = array_values( array_filter( $current, static fn ( int $image ): bool => ! PictureImage::is_picture( $image, $product ) ) );
		$gallery = array_merge( $pictures, $others );
		if ( $gallery === $current ) {
			return;
		}
		update_post_meta( $product->get_id(), self::GALLERY_META, implode( ',', $gallery ) );
		wc_delete_product_transients( $product->get_id() );
	}
}
