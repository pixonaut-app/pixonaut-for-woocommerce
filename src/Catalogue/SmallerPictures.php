<?php
/**
 * Product pictures kept as WebP rather than as Pixonaut's PNG.
 *
 * @package Pixonaut\WooCommerce
 */

declare(strict_types=1);

namespace Pixonaut\WooCommerce\Catalogue;

use Pixonaut\WooCommerce\Schema;

/**
 * A picture Pixonaut draws is a PNG several times larger than the picture
 * needs; the media library keeps a WebP of it instead (`file()`), and
 * WordPress makes every size of it in WebP too. Pictures kept as PNGs
 * before are written again as WebP in place (`convert()`): the attachment
 * keeps its id, so every product showing it shows the WebP, and the PNG
 * with each of its sizes is deleted. An upgrade asks for that, which a job
 * does a batch at a time, each its own job, so one that stops is taken up
 * from its batch. Where the image editor writes no WebP, pictures stay PNGs.
 */
final class SmallerPictures {

	/**
	 * The job converting a batch of PNG pictures: the last attachment id of
	 * the batch before, 0 for the first.
	 */
	public const ALL_HOOK = 'pixonaut_wc_smaller_pictures_all';

	public const BACKFILL_OPTION = Schema::SMALLER_PICTURES_BACKFILL_OPTION;

	/**
	 * BACKFILL_OPTION's value while the job converting every PNG picture waits to be queued.
	 */
	public const BACKFILL_PENDING = '1';

	/**
	 * Pictures converted by one job: each is decoded, encoded and resized
	 * to every size the site makes, about a second each.
	 */
	public const BATCH = 20;

	/**
	 * How long one batch may run: a picture takes about a second, on a
	 * busy server several.
	 */
	private const TIME_LIMIT_SECONDS = 300;

	private const PNG = 'image/png';

	public static function register(): void {
		add_action( self::ALL_HOOK, array( self::class, 'convert_all' ) );
		add_action( 'action_scheduler_init', array( self::class, 'queue_backfill' ) );
	}

	/**
	 * Queues the job converting every PNG picture that an upgrade asked
	 * for, and only then forgets the ask: one that could not be queued is
	 * tried again on the next request.
	 */
	public static function queue_backfill(): void {
		if ( self::BACKFILL_PENDING !== get_option( self::BACKFILL_OPTION ) || ! function_exists( 'as_enqueue_async_action' ) ) {
			return;
		}
		if ( 0 !== as_enqueue_async_action( self::ALL_HOOK, array( 0 ), Schema::ACTION_GROUP ) ) {
			delete_option( self::BACKFILL_OPTION );
		}
	}

	/**
	 * Writes a WebP of a PNG Pixonaut drew beside it, under a name no file
	 * of its directory has; the PNG stays.
	 *
	 * @param string $png The PNG's path.
	 * @return string|null The WebP's path; null when the image editor writes no WebP.
	 */
	public static function file( string $png ): ?string {
		$directory = dirname( $png );
		$unique    = wp_unique_filename( $directory, pathinfo( $png, PATHINFO_FILENAME ) . '.' . array_key_first( SmallerImage::WEBP ) );
		return SmallerImage::write( $png, $directory . '/' . pathinfo( $unique, PATHINFO_FILENAME ), SmallerImage::WEBP );
	}

	/**
	 * Converts a batch of PNG pictures, and queues the next batch while
	 * there may be more. The archives' indexes, which name the pictures'
	 * files, are built again, and a picture's average weight read again.
	 *
	 * @param mixed $after Only attachments above this id.
	 */
	public static function convert_all( mixed $after = 0 ): void {
		$ids = self::png_pictures( (int) $after );
		wc_set_time_limit( self::TIME_LIMIT_SECONDS );
		foreach ( $ids as $id ) {
			self::convert( $id );
		}
		if ( array() !== $ids ) {
			ArchiveIndexes::changed();
			// The variation pictures' cost estimate reads what a picture weighs.
			PictureStorage::forget_average();
		}
		if ( count( $ids ) === self::BATCH && function_exists( 'as_enqueue_async_action' ) ) {
			as_enqueue_async_action( self::ALL_HOOK, array( end( $ids ) ), Schema::ACTION_GROUP );
		}
	}

	/**
	 * Writes a PNG picture again as WebP, with every size WordPress makes,
	 * and deletes the PNG and its sizes. A picture that cannot be converted
	 * stays as it is.
	 *
	 * @param int $attachment A Pixonaut picture.
	 * @return bool Whether it was converted.
	 */
	public static function convert( int $attachment ): bool {
		$png = get_attached_file( $attachment );
		if ( self::PNG !== get_post_mime_type( $attachment ) || ! is_string( $png ) || ! is_file( $png ) ) {
			return false;
		}
		$webp = self::file( $png );
		if ( null === $webp ) {
			return false;
		}
		$before = wp_get_attachment_metadata( $attachment );
		require_once ABSPATH . 'wp-admin/includes/image.php';
		$after = wp_generate_attachment_metadata( $attachment, $webp );
		if ( ! is_int( $after['width'] ?? null ) || $after['width'] <= 0 ) {
			// WordPress saves the sizes it made as it goes: the PNG's are put back.
			wp_delete_attachment_files( $attachment, $after, array(), $webp );
			wp_update_attachment_metadata( $attachment, is_array( $before ) ? $before : array() );
			return false;
		}
		update_attached_file( $attachment, $webp );
		wp_update_attachment_metadata( $attachment, $after );
		wp_update_post(
			array(
				'ID'             => $attachment,
				'post_mime_type' => current( SmallerImage::WEBP ),
			)
		);
		$backup_sizes = get_post_meta( $attachment, '_wp_attachment_backup_sizes', true );
		wp_delete_attachment_files( $attachment, is_array( $before ) ? $before : array(), is_array( $backup_sizes ) ? $backup_sizes : array(), $png );
		return true;
	}

	/**
	 * Pixonaut pictures kept as PNGs, a batch of them, by id.
	 *
	 * @param int $after Only attachments above this id.
	 * @return list<int>
	 */
	private static function png_pictures( int $after ): array {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- a batch of a one-off job, read once.
		$ids = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT DISTINCT picture.ID FROM {$wpdb->posts} AS picture
				INNER JOIN {$wpdb->postmeta} AS drawn_from ON drawn_from.post_id = picture.ID AND drawn_from.meta_key = %s
				WHERE picture.post_type = 'attachment' AND picture.post_mime_type = %s AND picture.ID > %d
				ORDER BY picture.ID ASC LIMIT %d",
				SharedPictures::SOURCE_META,
				self::PNG,
				$after,
				self::BATCH
			)
		);
		return array_values( array_map( 'intval', $ids ) );
	}
}
