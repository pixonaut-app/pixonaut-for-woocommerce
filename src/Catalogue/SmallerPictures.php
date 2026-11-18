<?php
/**
 * Product pictures kept as WebP rather than as Pixonaut's PNG.
 *
 * @package Pixonaut\WooCommerce
 */

declare(strict_types=1);

namespace Pixonaut\WooCommerce\Catalogue;

use Pixonaut\WooCommerce\Api\WcLogger;
use Pixonaut\WooCommerce\Schema;
use WP_Error;

/**
 * A picture Pixonaut draws is a PNG several times larger than the picture
 * needs; the media library keeps a WebP of it instead (`file()`), and
 * WordPress makes every size of it in WebP too. Pictures kept as PNGs
 * before are written again as WebP in place (`convert()`): the attachment
 * keeps its id, so every product showing it shows the WebP, and the PNG
 * with each of its sizes is deleted. Where the image editor writes no
 * WebP, pictures stay PNGs.
 *
 * An upgrade asks for every PNG picture to be converted by leaving
 * `BACKFILL_OPTION`, which holds the last picture taken up. A job converts
 * a batch at a time and queues the next; while the option is there and no
 * job waits or runs, one is queued, so a job that stopped, or a next batch
 * that could not be queued, is taken up from the picture after the last
 * one taken up. The option goes once no PNG picture is left after it.
 */
final class SmallerPictures {

	/**
	 * The job converting a batch of PNG pictures.
	 */
	public const ALL_HOOK = 'pixonaut_wc_smaller_pictures_all';

	public const BACKFILL_OPTION = Schema::SMALLER_PICTURES_BACKFILL_OPTION;

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
		add_action( self::ALL_HOOK, array( self::class, 'convert_all' ), 10, 0 );
		add_action( 'action_scheduler_init', array( self::class, 'queue_backfill' ) );
	}

	/**
	 * Asks for every PNG picture to be converted, from the first.
	 */
	public static function ask(): void {
		update_option( self::BACKFILL_OPTION, '0', true );
	}

	/**
	 * Queues the job converting the PNG pictures while some are left to
	 * take up and no job waits or runs for them.
	 */
	public static function queue_backfill(): void {
		if ( false === get_option( self::BACKFILL_OPTION ) || ! function_exists( 'as_enqueue_async_action' ) || as_has_scheduled_action( self::ALL_HOOK ) ) {
			return;
		}
		as_enqueue_async_action( self::ALL_HOOK, array(), Schema::ACTION_GROUP );
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
	 * Converts the batch of PNG pictures after the last one taken up, and
	 * queues the next batch while there may be more. Each picture is taken
	 * up before it is converted, so one whose conversion stops the job is
	 * not tried again. The archives' indexes, which name the pictures'
	 * files, are built again, and a picture's average weight read again.
	 */
	public static function convert_all(): void {
		$after = get_option( self::BACKFILL_OPTION );
		if ( ! is_numeric( $after ) ) {
			return;
		}
		$ids = self::png_pictures( (int) $after );
		wc_set_time_limit( self::TIME_LIMIT_SECONDS );
		foreach ( $ids as $id ) {
			update_option( self::BACKFILL_OPTION, (string) $id, true );
			self::convert( $id );
		}
		if ( array() !== $ids ) {
			ArchiveIndexes::changed();
			// The variation pictures' cost estimate reads what a picture weighs.
			PictureStorage::forget_average();
		}
		if ( count( $ids ) < self::BATCH ) {
			delete_option( self::BACKFILL_OPTION );
		} elseif ( function_exists( 'as_enqueue_async_action' ) ) {
			// One not queued is queued by `queue_backfill()` once this job is over.
			as_enqueue_async_action( self::ALL_HOOK, array(), Schema::ACTION_GROUP );
		}
	}

	/**
	 * Writes a PNG picture again as WebP, with each size WordPress makes,
	 * and deletes the PNG and its sizes. The WebP and its sizes are all
	 * written before the attachment is changed, so a picture whose
	 * conversion fails, or stops, stays the PNG it was.
	 *
	 * @param int $attachment A Pixonaut picture.
	 * @return bool Whether it was converted.
	 */
	public static function convert( int $attachment ): bool {
		$png    = get_attached_file( $attachment );
		$before = wp_get_attachment_metadata( $attachment );
		if ( self::PNG !== get_post_mime_type( $attachment ) || ! is_string( $png ) || ! is_file( $png ) || ! is_array( $before ) ) {
			return false;
		}
		$webp = self::file( $png );
		if ( null === $webp ) {
			return false;
		}
		$after = self::metadata( $webp, $before );
		if ( null === $after ) {
			self::delete_files( $webp, array() );
			self::failed( $attachment, 'its WebP could not be made in every size its PNG has' );
			return false;
		}
		// A picture its last product let go meanwhile is deleted with the PNG's files: the WebP's go too.
		clean_post_cache( $attachment );
		if ( 'attachment' !== get_post_type( $attachment ) || get_attached_file( $attachment, true ) !== $png ) {
			self::delete_files( $webp, $after['sizes'] );
			return false;
		}
		if ( ! self::commit( $attachment, $webp, $after ) ) {
			self::commit( $attachment, $png, $before, self::PNG );
			self::delete_files( $webp, $after['sizes'] );
			self::failed( $attachment, 'the attachment could not be changed to its WebP' );
			return false;
		}
		$backup_sizes = get_post_meta( $attachment, '_wp_attachment_backup_sizes', true );
		wp_delete_attachment_files( $attachment, $before, is_array( $backup_sizes ) ? $backup_sizes : array(), $png );
		return true;
	}

	/**
	 * The WebP's attachment metadata: the PNG's, with the WebP's file,
	 * dimensions and sizes. The sizes are made without changing the
	 * attachment, as `wp_generate_attachment_metadata()` would as it goes.
	 *
	 * @param string               $webp   The WebP.
	 * @param array<string, mixed> $before The PNG's metadata.
	 * @return array<string, mixed>|null Null when the WebP could not be read, or a size the PNG has could not be made.
	 */
	private static function metadata( string $webp, array $before ): ?array {
		$dimensions = wp_getimagesize( $webp );
		$editor     = wp_get_image_editor( $webp );
		if ( ! is_array( $dimensions ) || $dimensions[0] <= 0 || $editor instanceof WP_Error ) {
			return null;
		}
		$registered = wp_get_registered_image_subsizes();
		$sizes      = $editor->multi_resize( $registered );
		// A size no longer registered is not made again, as WordPress would not.
		$had = is_array( $before['sizes'] ?? null ) ? array_intersect_key( $before['sizes'], $registered ) : array();
		if ( array() !== array_diff_key( $had, $sizes ) ) {
			self::delete_files( $webp, $sizes );
			return null;
		}
		$after = array_merge(
			$before,
			array(
				'file'     => _wp_relative_upload_path( $webp ),
				'width'    => $dimensions[0],
				'height'   => $dimensions[1],
				'filesize' => wp_filesize( $webp ),
				'sizes'    => $sizes,
			)
		);
		// The PNG had no larger original; a WebP never names one.
		unset( $after['original_image'] );
		return $after;
	}

	/**
	 * Points the attachment at a file, with its metadata and media type.
	 *
	 * @param int                  $attachment The picture.
	 * @param string               $file       Its file.
	 * @param array<string, mixed> $metadata   The file's metadata.
	 * @param string|null          $mime       Its media type; null for WebP.
	 * @return bool Whether every write took.
	 */
	private static function commit( int $attachment, string $file, array $metadata, ?string $mime = null ): bool {
		$mime = $mime ?? current( SmallerImage::WEBP );
		update_attached_file( $attachment, $file );
		wp_update_attachment_metadata( $attachment, $metadata );
		wp_update_post(
			array(
				'ID'             => $attachment,
				'post_mime_type' => $mime,
			)
		);
		// Each write answers false when the value was already there: read them back.
		clean_post_cache( $attachment );
		return get_attached_file( $attachment, true ) === $file && get_post_mime_type( $attachment ) === $mime && wp_get_attachment_metadata( $attachment, true ) === $metadata;
	}

	/**
	 * Deletes a file and the files of its sizes beside it.
	 *
	 * @param string                              $file  The file.
	 * @param array<string, array<string, mixed>> $sizes Its sizes, as attachment metadata names them.
	 */
	private static function delete_files( string $file, array $sizes ): void {
		foreach ( $sizes as $size ) {
			if ( is_string( $size['file'] ?? null ) ) {
				wp_delete_file( dirname( $file ) . '/' . wp_basename( $size['file'] ) );
			}
		}
		wp_delete_file( $file );
	}

	/**
	 * Logs why a picture stays a PNG.
	 *
	 * @param int    $attachment The picture.
	 * @param string $reason     Why.
	 */
	private static function failed( int $attachment, string $reason ): void {
		( new WcLogger() )->warning( 'A product picture stays a PNG: ' . $reason . '.', array( 'attachment' => $attachment ) );
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
