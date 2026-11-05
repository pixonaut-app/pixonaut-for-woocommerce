<?php
/**
 * Turns the products that kept their own image into products whose image is kept.
 *
 * @package Pixonaut\WooCommerce
 */

declare(strict_types=1);

namespace Pixonaut\WooCommerce\Migrations;

use Pixonaut\WooCommerce\Catalogue\PictureImage;
use Pixonaut\WooCommerce\Catalogue\PictureQueue;
use Pixonaut\WooCommerce\Schema;

/**
 * A product's Pixonaut picture is now its real image, where the picture
 * used to be shown in place of the image unless the product's "Show the
 * Pixonaut picture" box was unticked. A product with the box unticked has
 * the image it shows remembered, as if the picture had been its image
 * before, so its image is kept as one the store manager set. Then every
 * product is checked, which makes the picture the image of the others.
 * Through the post meta API: migrations run before WooCommerce's product
 * classes may be loaded.
 */
final class ReplaceKeptImages implements Migration {

	/**
	 * Products read per query.
	 */
	private const BATCH = 200;

	/**
	 * The meta's value on a product that kept its own image.
	 */
	private const KEPT = 'yes';

	public function version(): int {
		return 5;
	}

	public function up(): void {
		global $wpdb;
		do {
			// By the meta rows, whatever the product's status: one in the trash may be restored later.
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- once, at upgrade; each row read is deleted below.
			$ids = $wpdb->get_col( $wpdb->prepare( "SELECT DISTINCT post_id FROM {$wpdb->postmeta} WHERE meta_key = %s ORDER BY post_id LIMIT %d", Schema::PRODUCT_KEEPS_IMAGE_META, self::BATCH ) );
			foreach ( $ids as $product_id ) {
				$product_id = (int) $product_id;
				if ( self::KEPT === get_post_meta( $product_id, Schema::PRODUCT_KEEPS_IMAGE_META, true ) && ! metadata_exists( 'post', $product_id, PictureImage::PREVIOUS_META ) ) {
					add_post_meta( $product_id, PictureImage::PREVIOUS_META, (int) get_post_meta( $product_id, PictureImage::THUMBNAIL_META, true ), true );
				}
				delete_post_meta( $product_id, Schema::PRODUCT_KEEPS_IMAGE_META );
			}
			$full = self::BATCH === count( $ids );
		} while ( $full );
		update_option( PictureQueue::BACKFILL_OPTION, PictureQueue::BACKFILL_PENDING, false );
	}
}
