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
 * go. Such an unfinished picture is deleted with its files: by the request
 * whose job stopped while saving it (`delete_stopped()`), else on its
 * product's next drawing (`delete_of()`), and, for those left before, by a
 * job an upgrade asks for (`ask()`).
 *
 * Only a picture no job can still be saving is deleted: one this request
 * started saving and its job stopped, or one older than a drawing may run
 * (`ProductPictures::LOCK_SECONDS`) whose product no drawing holds the lock
 * of. One that is any post's image, in a product's gallery, or in its
 * product's record stays.
 */
final class UnfinishedPictures {

	/**
	 * The job deleting a batch of unfinished pictures.
	 */
	public const ALL_HOOK = 'pixonaut_wc_unfinished_pictures_all';

	/**
	 * Option holding the job's progress while it has pictures to take up:
	 * `after`, the last picture taken up, and `deferred`, whether one was
	 * passed over as possibly still being saved, so the job looks again
	 * once that can no longer be.
	 */
	public const BACKFILL_OPTION = Schema::UNFINISHED_PICTURES_BACKFILL_OPTION;

	/**
	 * Pictures one job takes up.
	 */
	public const BATCH = 50;

	/**
	 * Attachments this request started saving and has not finished, by id.
	 *
	 * @var array<int, true>
	 */
	private static array $saving = array();

	public static function register(): void {
		add_action( self::ALL_HOOK, array( self::class, 'delete_all' ), 10, 0 );
		add_action( 'action_scheduler_init', array( self::class, 'queue_backfill' ) );
	}

	/**
	 * Notes that this request started saving the picture.
	 *
	 * @param int $attachment The picture's attachment, just inserted.
	 */
	public static function saving( int $attachment ): void {
		self::$saving[ $attachment ] = true;
	}

	/**
	 * Notes that this request finished saving the picture, or deleted it.
	 *
	 * @param int $attachment The picture's attachment.
	 */
	public static function saved( int $attachment ): void {
		unset( self::$saving[ $attachment ] );
	}

	/**
	 * Deletes the pictures this request started saving and never finished:
	 * the job saving them stopped.
	 */
	public static function delete_stopped(): void {
		foreach ( array_keys( self::$saving ) as $attachment ) {
			self::saved( $attachment );
			self::delete( $attachment );
		}
	}

	/**
	 * Asks for every unfinished picture to be deleted, from the first.
	 */
	public static function ask(): void {
		self::progress( 0, false );
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
	 * Deletes the batch of unfinished pictures after the last one taken up,
	 * passing over those a job may still be saving, and queues the next
	 * batch while there may be more. Once all were taken up, looks again
	 * after `LOCK_SECONDS` when one was passed over.
	 */
	public static function delete_all(): void {
		$progress = get_option( self::BACKFILL_OPTION );
		if ( ! is_array( $progress ) || ! is_numeric( $progress['after'] ?? null ) ) {
			return;
		}
		$deferred = ! empty( $progress['deferred'] );
		$ids      = self::find( null, null, (int) $progress['after'], self::BATCH );
		$old      = gmdate( 'Y-m-d H:i:s', time() - ProductPictures::LOCK_SECONDS );
		foreach ( $ids as $id ) {
			$post = get_post( $id );
			if ( null !== $post && ( $post->post_date_gmt >= $old || self::drawn_now( $id ) ) ) {
				$deferred = true;
			} else {
				self::delete( $id );
			}
			self::progress( $id, $deferred );
		}
		if ( count( $ids ) === self::BATCH ) {
			// One not queued is queued by `queue_backfill()` once this job is over.
			as_enqueue_async_action( self::ALL_HOOK, array(), Schema::ACTION_GROUP );
		} elseif ( $deferred ) {
			self::progress( 0, false );
			as_schedule_single_action( time() + ProductPictures::LOCK_SECONDS, self::ALL_HOOK, array(), Schema::ACTION_GROUP );
		} else {
			delete_option( self::BACKFILL_OPTION );
		}
	}

	/**
	 * Deletes the product's unfinished pictures inserted before the time:
	 * the caller holds the product's lock, so no other drawing of it saves
	 * one, and one inserted that long ago is not saved by a drawing that
	 * held the lock before.
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
	 * Records the job's progress.
	 *
	 * @param int  $after    The last picture taken up.
	 * @param bool $deferred Whether one was passed over.
	 */
	private static function progress( int $after, bool $deferred ): void {
		update_option(
			self::BACKFILL_OPTION,
			array(
				'after'    => $after,
				'deferred' => $deferred,
			),
			false
		);
	}

	/**
	 * Whether a drawing of a product the picture names may still run.
	 *
	 * @param int $attachment An unfinished picture.
	 */
	private static function drawn_now( int $attachment ): bool {
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
		if ( 'attachment' !== get_post_type( $attachment ) || metadata_exists( 'post', $attachment, SharedPictures::SOURCE_META ) ) {
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
	 * @param int|null $product_id Only the pictures of this product or variation, inserted with it as their parent; null for all.
	 * @param int|null $before     Unix time; only pictures inserted earlier; null for any.
	 * @param int      $after      Only attachments above this id.
	 * @param int      $limit      How many at most.
	 *
	 * @return list<int>
	 */
	private static function find( ?int $product_id, ?int $before, int $after, int $limit ): array {
		global $wpdb;
		// A product's own pictures are found by their parent, which is indexed, rather than by the meta value naming it.
		$conditions = ( null === $product_id ? '' : $wpdb->prepare( ' AND picture.post_parent = %d AND shown_by.meta_value = %s', $product_id, (string) $product_id ) )
			. ( null === $before ? '' : $wpdb->prepare( ' AND picture.post_date_gmt < %s', gmdate( 'Y-m-d H:i:s', $before ) ) );
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- read once by a job, past every cache; `$conditions` is prepared above.
		$ids = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT DISTINCT picture.ID FROM {$wpdb->posts} AS picture
				INNER JOIN {$wpdb->postmeta} AS shown_by ON shown_by.post_id = picture.ID AND shown_by.meta_key = %s
				LEFT JOIN {$wpdb->postmeta} AS drawn_from ON drawn_from.post_id = picture.ID AND drawn_from.meta_key = %s
				WHERE picture.post_type = 'attachment' AND drawn_from.meta_id IS NULL AND picture.ID > %d{$conditions}
				AND NOT EXISTS ( SELECT 1 FROM {$wpdb->postmeta} AS image WHERE image.meta_key = '_thumbnail_id' AND image.meta_value = CAST( picture.ID AS CHAR ) )
				AND NOT EXISTS ( SELECT 1 FROM {$wpdb->postmeta} AS gallery WHERE gallery.meta_key = '_product_image_gallery' AND FIND_IN_SET( picture.ID, gallery.meta_value ) )
				ORDER BY picture.ID ASC LIMIT %d",
				SharedPictures::PICTURE_OF_META,
				SharedPictures::SOURCE_META,
				$after,
				$limit
			)
		);
		// phpcs:enable
		return array_values( array_map( 'intval', $ids ) );
	}
}
