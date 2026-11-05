<?php
/**
 * Pixonaut pictures as media-library attachments, shared by the products
 * and variations drawn from the same source.
 *
 * @package Pixonaut\WooCommerce
 */

declare(strict_types=1);

namespace Pixonaut\WooCommerce\Catalogue;

use Pixonaut\WooCommerce\Schema;
use WC_Product;

/**
 * A picture is an attachment that names each product or variation showing
 * it (`PICTURE_OF_META`, one row each) and the key of what it was drawn
 * from (`SOURCE_META`). The variations of a sign come in several sizes of
 * the same template, so their pictures are identical: a variation drawn
 * from a source another of the product's family already has a picture of
 * takes that picture rather than drawing its own (`find()`, `share()`). A
 * picture is deleted once the last product showing it lets it go
 * (`release()`).
 *
 * While one job draws a source, another job of the family wanting the same
 * waits for it (`claim()`), so variations drawn side by side still draw it
 * once.
 */
final class SharedPictures {

	public const PICTURE_OF_META = Schema::PICTURE_OF_META;

	public const SOURCE_META = Schema::PICTURE_SOURCE_META;

	/**
	 * Prefix of the option a job holds while it draws a source, followed by
	 * the family's product id and the source's key (`lock_option()`); under
	 * the pictures' lock prefix, so uninstall removes it with them.
	 */
	public const SOURCE_LOCK_PREFIX = Schema::PICTURE_LOCK_OPTION_PREFIX . 'source-';

	/**
	 * How long a job waits for another drawing the same source before it
	 * draws the source itself.
	 */
	public const WAIT_SECONDS = 30;

	/**
	 * How long a job sleeps between looks at a source another job draws.
	 */
	private const POLL_SECONDS = 1;

	/**
	 * Whether the attachment is a Pixonaut picture the product or variation
	 * shows, and still there.
	 *
	 * @param int        $attachment An attachment id; 0 for none.
	 * @param WC_Product $product    A product or variation.
	 */
	public static function is_picture( int $attachment, WC_Product $product ): bool {
		// A picture deleted while another product took it keeps that product's row: it is missing, and drawn again.
		return $attachment > 0 && 'attachment' === get_post_type( $attachment ) && in_array( $product->get_id(), self::owners( $attachment ), true );
	}

	/**
	 * The products and variations showing the picture.
	 *
	 * @param int $attachment An attachment id.
	 *
	 * @return list<int>
	 */
	public static function owners( int $attachment ): array {
		$rows = get_post_meta( $attachment, self::PICTURE_OF_META, false );
		return array_values( array_unique( array_filter( array_map( 'intval', is_array( $rows ) ? $rows : array() ) ) ) );
	}

	/**
	 * A picture the product's family (the product, or a variation's product,
	 * and its variations) already has of the source; 0 for none.
	 *
	 * @param WC_Product $product A product or variation.
	 * @param string     $key     What the picture is drawn from.
	 */
	public static function find( WC_Product $product, string $key ): int {
		global $wpdb;
		$family = array_map( 'strval', self::relatives( $product ) );
		// Read from the database, past every cache: a job waiting for another's picture must see it once saved.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- as above.
		$found = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT picture.ID FROM {$wpdb->posts} AS picture
				INNER JOIN {$wpdb->postmeta} AS drawn_from ON drawn_from.post_id = picture.ID AND drawn_from.meta_key = %s AND drawn_from.meta_value = %s
				INNER JOIN {$wpdb->postmeta} AS shown_by ON shown_by.post_id = picture.ID AND shown_by.meta_key = %s
				WHERE picture.post_type = 'attachment' AND picture.post_status = 'inherit'
				AND shown_by.meta_value IN (" . implode( ', ', array_fill( 0, count( $family ), '%s' ) ) . ')
				ORDER BY picture.ID ASC LIMIT 1',
				array_merge( array( self::SOURCE_META, $key, self::PICTURE_OF_META ), $family )
			)
		);
		return null === $found ? 0 : (int) $found;
	}

	/**
	 * Makes the picture one the product or variation shows too, unless it
	 * is gone: its last product may have let it go after `find()` gave it.
	 *
	 * @param int        $attachment A Pixonaut picture.
	 * @param WC_Product $product    A product or variation.
	 *
	 * @return bool Whether the product shows it now.
	 */
	public static function share( int $attachment, WC_Product $product ): bool {
		$added = ! self::is_picture( $attachment, $product ) && false !== add_post_meta( $attachment, self::PICTURE_OF_META, $product->get_id() );
		// Read again past the cache once the row is there, so a picture deleted meanwhile is not taken.
		wp_cache_delete( (string) $attachment, 'posts' );
		if ( 'attachment' === get_post_type( $attachment ) ) {
			return true;
		}
		if ( $added ) {
			delete_post_meta( $attachment, self::PICTURE_OF_META, (string) $product->get_id() );
		}
		return false;
	}

	/**
	 * Saves the PNG to the media library as the product's picture.
	 *
	 * @param WC_Product $product A product or variation.
	 * @param string     $png     The picture's bytes.
	 * @param string     $key     What it was drawn from.
	 *
	 * @return int The attachment; 0 when it could not be saved.
	 */
	public static function attach( WC_Product $product, string $png, string $key ): int {
		$upload = wp_upload_bits( sprintf( 'pixonaut-%d-%s.png', $product->get_id(), substr( $key, 0, 8 ) ), null, $png );
		if ( false !== $upload['error'] ) {
			return 0;
		}
		$attachment = wp_insert_attachment(
			array(
				'post_mime_type' => 'image/png',
				'post_title'     => $product->get_name(),
				'post_status'    => 'inherit',
			),
			$upload['file'],
			$product->get_id()
		);
		if ( 0 === $attachment ) {
			wp_delete_file( $upload['file'] );
			return 0;
		}
		add_post_meta( $attachment, self::PICTURE_OF_META, $product->get_id() );
		update_post_meta( $attachment, '_wp_attachment_image_alt', $product->get_name() );
		require_once ABSPATH . 'wp-admin/includes/image.php';
		$metadata = wp_generate_attachment_metadata( $attachment, $upload['file'] );
		// Bytes WordPress cannot read as an image must not replace the last good picture.
		if ( ! is_int( $metadata['width'] ?? null ) || $metadata['width'] <= 0 ) {
			wp_delete_attachment( $attachment, true );
			return 0;
		}
		wp_update_attachment_metadata( $attachment, $metadata );
		// Last, so `find()` never gives another product a picture that is not yet a valid image.
		update_post_meta( $attachment, self::SOURCE_META, $key );
		return $attachment;
	}

	/**
	 * Lets the product's picture go, and deletes it once no product or
	 * variation shows it; an attachment that is not its picture stays.
	 *
	 * @param int        $attachment An attachment id; 0 for none.
	 * @param WC_Product $product    A product or variation.
	 */
	public static function release( int $attachment, WC_Product $product ): void {
		if ( ! self::is_picture( $attachment, $product ) ) {
			return;
		}
		delete_post_meta( $attachment, self::PICTURE_OF_META, (string) $product->get_id() );
		if ( array() === self::owners( $attachment ) ) {
			wp_delete_attachment( $attachment, true );
		}
	}

	/**
	 * Takes the right to draw the source for the product's family. While
	 * another job holds it, waits until that job saved its picture, which
	 * `find()` then gives, or let it go; after `WAIT_SECONDS`, or when the
	 * lock is stale, takes it over.
	 *
	 * @param WC_Product $product A product or variation.
	 * @param string     $key     What the picture is drawn from.
	 * @param \Closure   $clock   `fn(): int` Unix time.
	 * @param \Closure   $sleep   `fn(int $seconds): void`.
	 * @param int        $stale   How long a lock is held before it may be taken over, in seconds.
	 *
	 * @return array{0: int, 1: string|null} A picture the family has of the source meanwhile, or 0; and the lock's value for `unclaim()`, or null when a picture was found.
	 */
	public static function claim( WC_Product $product, string $key, \Closure $clock, \Closure $sleep, int $stale ): array {
		$option  = self::lock_option( $product, $key );
		$started = (int) $clock();
		while ( true ) {
			$now = (string) $clock();
			if ( add_option( $option, $now, '', false ) ) {
				// Another job may have saved its picture just before it let go.
				$found = self::find( $product, $key );
				if ( 0 !== $found ) {
					self::unclaim( $product, $key, $now );
					return array( $found, null );
				}
				return array( 0, $now );
			}
			wp_cache_delete( $option, 'options' );
			$held_since = get_option( $option );
			$waited     = (int) $now - $started;
			if ( ! is_numeric( $held_since ) || (int) $now - (int) $held_since >= $stale || $waited >= self::WAIT_SECONDS ) {
				// Stale, or held too long: drawn here too, rather than not at all.
				return array( 0, null );
			}
			$sleep( self::POLL_SECONDS );
			$found = self::find( $product, $key );
			if ( 0 !== $found ) {
				return array( $found, null );
			}
		}
	}

	/**
	 * Gives up the source's lock, unless another job took it over meanwhile.
	 *
	 * @param WC_Product $product A product or variation, as it claimed the source.
	 * @param string     $key     What the picture is drawn from.
	 * @param string     $held    The lock's value when it was taken.
	 */
	public static function unclaim( WC_Product $product, string $key, string $held ): void {
		$option = self::lock_option( $product, $key );
		wp_cache_delete( $option, 'options' );
		if ( get_option( $option ) === $held ) {
			delete_option( $option );
		}
	}

	/**
	 * The option a job of the product's family holds while it draws the
	 * source. Pictures are shared within a family only, so another family
	 * drawing the same source never waits for it.
	 *
	 * @param WC_Product $product A product or variation.
	 * @param string     $key     What the picture is drawn from.
	 */
	public static function lock_option( WC_Product $product, string $key ): string {
		return self::SOURCE_LOCK_PREFIX . self::root( $product ) . '-' . $key;
	}

	/**
	 * The product, or a variation's product.
	 *
	 * @param WC_Product $product A product or variation.
	 */
	private static function root( WC_Product $product ): int {
		return $product->is_type( 'variation' ) ? $product->get_parent_id() : $product->get_id();
	}

	/**
	 * The product, or a variation's product, and its variations.
	 *
	 * @param WC_Product $product A product or variation.
	 *
	 * @return list<int>
	 */
	private static function relatives( WC_Product $product ): array {
		$parent_id = self::root( $product );
		$parent    = wc_get_product( $parent_id );
		$children  = $parent instanceof WC_Product ? array_map( 'intval', $parent->get_children() ) : array();
		return array_values( array_unique( array_merge( array( $product->get_id(), $parent_id ), $children ) ) );
	}
}
