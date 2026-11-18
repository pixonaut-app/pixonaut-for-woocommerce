<?php
/**
 * Deletes the pictures a stopped job left in the media library.
 *
 * @package Pixonaut\WooCommerce
 */

declare(strict_types=1);

namespace Pixonaut\WooCommerce\Catalogue;

use Pixonaut\WooCommerce\Schema;

/**
 * `SharedPictures::attach()` inserts a picture's attachment, has WordPress
 * make its image sizes, and records what it was drawn from last. A job
 * stopped in between (PHP's time limit, a killed worker) leaves an
 * attachment naming its product (`PICTURE_OF_META`) but no source: no
 * product shows it, `find()` never gives it, and `release()` never lets it
 * go. Such an unfinished picture is deleted with its files once its job is
 * over (`StoppedPictureJobs`), on the product's next drawing, and, for those
 * left before, by a job an upgrade asks for (`ask()`).
 *
 * A picture is only deleted once no job can still be saving it: the job
 * that stopped is known to be over, or the picture is older than a drawing
 * may run (`ProductPictures::LOCK_SECONDS`) and no drawing of its product
 * holds the product's lock. One that is any post's image, in a product's
 * gallery, or in its product's record stays.
 */
final class UnfinishedPictures {

	/**
	 * The job deleting a batch of unfinished pictures.
	 */
	public const ALL_HOOK = 'pixonaut_wc_unfinished_pictures_all';

	/**
	 * Option holding the last picture the job took up, while it has more
	 * to take up.
	 */
	public const BACKFILL_OPTION = Schema::UNFINISHED_PICTURES_BACKFILL_OPTION;

	/**
	 * Pictures one job takes up.
	 */
	public const BATCH = 50;

	public static function register(): void {
		add_action( self::ALL_HOOK, array( self::class, 'delete_all' ), 10, 0 );
		add_action( 'action_scheduler_init', array( self::class, 'queue_backfill' ) );
	}

	/**
	 * Asks for every unfinished picture to be deleted, from the first.
	 */
	public static function ask(): void {
		update_option( self::BACKFILL_OPTION, '0', false );
	}

	/**
	 * Queues the job while pictures are left to take up and no job waits
	 * or runs for them.
	 */
	public static function queue_backfill(): void {
		if ( false === get_option( self::BACKFILL_OPTION ) || ! function_exists( 'as_enqueue_async_action' ) || as_has_scheduled_action( self::ALL_HOOK ) ) {
			return;
		}
		as_enqueue_async_action( self::ALL_HOOK, array(), Schema::ACTION_GROUP );
	}

	/**
	 * Deletes the batch of unfinished pictures after the last one taken up
	 * that no job can still be saving, and queues the next batch while
	 * there may be more.
	 */
	public static function delete_all(): void {
		$after = get_option( self::BACKFILL_OPTION );
		if ( ! is_numeric( $after ) ) {
			return;
		}
		$ids = self::find( null, time() - ProductPictures::LOCK_SECONDS, (int) $after, self::BATCH );
		foreach ( $ids as $id ) {
			update_option( self::BACKFILL_OPTION, (string) $id, false );
			if ( ! self::saving( $id ) ) {
				self::delete( $id );
			}
		}
		if ( count( $ids ) < self::BATCH ) {
			delete_option( self::BACKFILL_OPTION );
		} else {
			// One not queued is queued by `queue_backfill()` once this job is over.
			as_enqueue_async_action( self::ALL_HOOK, array(), Schema::ACTION_GROUP );
		}
	}

	/**
	 * Deletes the product's unfinished pictures inserted before the time:
	 * the caller knows no job of the product saved any of them since.
	 *
	 * @param int $product_id A product or variation.
	 * @param int $before     Unix time; only pictures inserted earlier.
	 */
	public static function delete_of( int $product_id, int $before ): void {
		foreach ( self::find( $product_id, $before, 0, self::BATCH ) as $id ) {
			self::delete( $id );
		}
	}

	/**
	 * Whether a drawing of a product the picture names may still run.
	 *
	 * @param int $attachment An unfinished picture.
	 */
	private static function saving( int $attachment ): bool {
		foreach ( SharedPictures::owners( $attachment ) as $product_id ) {
			if ( ProductPictures::drawing( $product_id, time() ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Deletes the picture and its files, unless its save finished meanwhile
	 * or its product's record names it.
	 *
	 * @param int $attachment An unfinished picture.
	 */
	private static function delete( int $attachment ): void {
		if ( metadata_exists( 'post', $attachment, SharedPictures::SOURCE_META ) ) {
			return;
		}
		foreach ( SharedPictures::owners( $attachment ) as $product_id ) {
			$product = wc_get_product( $product_id );
			if ( $product instanceof \WC_Product && in_array( $attachment, ProductPictures::attachments( ProductPictures::stored( $product ) ), true ) ) {
				return;
			}
		}
		wp_delete_attachment( $attachment, true );
	}

	/**
	 * Unfinished pictures no post shows, by id: attachments naming a
	 * product with no source recorded, not a post's image nor in a
	 * product's gallery.
	 *
	 * @param int|null $product_id Only the pictures of this product or variation; null for all.
	 * @param int      $before     Unix time; only pictures inserted earlier.
	 * @param int      $after      Only attachments above this id.
	 * @param int      $limit      How many at most.
	 *
	 * @return list<int>
	 */
	private static function find( ?int $product_id, int $before, int $after, int $limit ): array {
		global $wpdb;
		$owner = null === $product_id ? '' : $wpdb->prepare( ' AND shown_by.meta_value = %s', (string) $product_id );
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- read once by a job, past every cache; `$owner` is prepared above.
		$ids = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT DISTINCT picture.ID FROM {$wpdb->posts} AS picture
				INNER JOIN {$wpdb->postmeta} AS shown_by ON shown_by.post_id = picture.ID AND shown_by.meta_key = %s{$owner}
				LEFT JOIN {$wpdb->postmeta} AS drawn_from ON drawn_from.post_id = picture.ID AND drawn_from.meta_key = %s
				WHERE picture.post_type = 'attachment' AND drawn_from.meta_id IS NULL
				AND picture.post_date_gmt < %s AND picture.ID > %d
				AND NOT EXISTS ( SELECT 1 FROM {$wpdb->postmeta} AS image WHERE image.meta_key = '_thumbnail_id' AND image.meta_value = CAST( picture.ID AS CHAR ) )
				AND NOT EXISTS ( SELECT 1 FROM {$wpdb->postmeta} AS gallery WHERE gallery.meta_key = '_product_image_gallery' AND FIND_IN_SET( picture.ID, gallery.meta_value ) )
				ORDER BY picture.ID ASC LIMIT %d",
				SharedPictures::PICTURE_OF_META,
				SharedPictures::SOURCE_META,
				gmdate( 'Y-m-d H:i:s', $before ),
				$after,
				$limit
			)
		);
		// phpcs:enable
		return array_values( array_map( 'intval', $ids ) );
	}
}
