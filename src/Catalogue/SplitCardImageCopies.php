<?php
/**
 * The images a split product's cards are waiting for, copied from their URLs.
 *
 * @package Pixonaut\WooCommerce
 */

declare(strict_types=1);

namespace Pixonaut\WooCommerce\Catalogue;

use Pixonaut\WooCommerce\Schema;
use WP_Error;

/**
 * A card's image given by its URL is copied into the media library in a job
 * of its own, one per card, so a call naming many of them answers at once
 * and no copy runs past a request's time. Until its job has run a card keeps
 * the image it had, and the product says which URL it waits for; a copy that
 * failed leaves nothing in the media library and says why, until the card's
 * image is set again. A card's copy is replaced by a later one of another
 * URL, and dropped when the card is given an image of the media library or
 * none: the job of a copy that is no longer the card's does nothing.
 */
final class SplitCardImageCopies {

	public const META = Schema::PRODUCT_SPLIT_CARD_IMAGE_COPIES_META;

	/**
	 * Attachment meta holding the URL a card's image was copied from, so the
	 * same URL given again is not copied again.
	 */
	public const SOURCE_META = Schema::ATTACHMENT_CARD_IMAGE_SOURCE_META;

	/**
	 * The job that copies one card's image: a product's id, the card's key
	 * and the copy's token.
	 */
	public const HOOK = 'pixonaut_wc_split_card_image_copy';

	/** A copy whose job has not run yet. */
	public const WAITING = 'waiting';

	/** A copy that could not be made. */
	public const FAILED = 'failed';

	/**
	 * How long the store waits for a card's image to download, in seconds.
	 */
	private const DOWNLOAD_TIMEOUT_SECONDS = 30;

	/**
	 * The job's arguments: the product, the card's key and the copy's token.
	 */
	private const HOOK_ARGUMENTS = 3;

	public static function register(): void {
		add_action( self::HOOK, array( self::class, 'copy' ), 10, self::HOOK_ARGUMENTS );
	}

	/**
	 * The copies a product's cards wait for or could not get, by card key.
	 *
	 * @param int $product_id A product.
	 * @return array<int|string, array{src: string, token: string, state: string, reason: string}>
	 */
	public static function stored_on( int $product_id ): array {
		$stored = get_post_meta( $product_id, self::META, true );
		$copies = array();
		foreach ( is_array( $stored ) ? $stored : array() as $key => $copy ) {
			if ( ! SplitCards::is_key( $key ) || ! is_array( $copy ) || ! is_string( $copy['src'] ?? null ) || ! is_string( $copy['token'] ?? null ) ) {
				continue;
			}
			$copies[ $key ] = array(
				'src'    => $copy['src'],
				'token'  => $copy['token'],
				'state'  => self::FAILED === ( $copy['state'] ?? null ) ? self::FAILED : self::WAITING,
				'reason' => is_string( $copy['reason'] ?? null ) ? $copy['reason'] : '',
			);
		}
		return $copies;
	}

	/**
	 * Whether a card's image is the one copied from a URL already, so
	 * giving it again changes nothing.
	 *
	 * @param int    $attachment_id The card's image; 0 for none.
	 * @param string $url           The URL given.
	 */
	public static function copied_from( int $attachment_id, string $url ): bool {
		return $attachment_id > 0 && get_post_meta( $attachment_id, self::SOURCE_META, true ) === $url && wp_attachment_is_image( $attachment_id );
	}

	/**
	 * Changes which copies a product's cards wait for: a card given a URL
	 * waits for its copy, in a job of its own, unless it waits for that URL
	 * already; a card given null waits for none.
	 *
	 * @param int                            $product_id A product.
	 * @param array<int|string, string|null> $changed    Each changed card's URL, or null for no copy, by card key.
	 */
	public static function change( int $product_id, array $changed ): void {
		if ( array() === $changed ) {
			return;
		}
		$copies = self::fresh( $product_id );
		$queued = array();
		foreach ( $changed as $key => $url ) {
			if ( null === $url ) {
				unset( $copies[ $key ] );
				continue;
			}
			$waiting = $copies[ $key ] ?? null;
			if ( null !== $waiting && self::WAITING === $waiting['state'] && $waiting['src'] === $url ) {
				continue;
			}
			$token          = wp_generate_uuid4();
			$copies[ $key ] = array(
				'src'    => $url,
				'token'  => $token,
				'state'  => self::WAITING,
				'reason' => '',
			);
			$queued[ $key ] = $token;
		}
		self::save( $product_id, $copies );
		foreach ( $queued as $key => $token ) {
			as_enqueue_async_action( self::HOOK, array( $product_id, (string) $key, $token ), Schema::ACTION_GROUP );
		}
	}

	/**
	 * Copies one card's image into the media library and gives it to the
	 * card, keeping the card's title. A copy that is no longer the card's
	 * does nothing, and one replaced or dropped while it downloaded leaves
	 * no image behind.
	 *
	 * @param int    $product_id The product.
	 * @param string $key        The card's key.
	 * @param string $token      The copy's token, as it was queued.
	 */
	public static function copy( int $product_id, string $key, string $token ): void {
		$copy = self::fresh( $product_id )[ $key ] ?? null;
		if ( null === $copy || $copy['token'] !== $token || self::WAITING !== $copy['state'] ) {
			return;
		}
		$image  = self::downloaded( $copy['src'], $product_id );
		$copies = self::fresh( $product_id );
		if ( ( $copies[ $key ]['token'] ?? null ) !== $token ) {
			if ( ! $image instanceof WP_Error ) {
				wp_delete_attachment( $image, true );
			}
			return;
		}
		if ( $image instanceof WP_Error ) {
			$copies[ $key ]['state']  = self::FAILED;
			$copies[ $key ]['reason'] = $image->get_error_message();
			self::save( $product_id, $copies );
			return;
		}
		update_post_meta( $image, self::SOURCE_META, wp_slash( $copy['src'] ) );
		SplitCardLooks::change(
			$product_id,
			array(
				$key => array(
					'title' => SplitCardLooks::of( $product_id, $key )['title'],
					'image' => $image,
				),
			)
		);
		unset( $copies[ $key ] );
		self::save( $product_id, $copies );
	}

	/**
	 * A product's copies as stored now, whatever this request read before:
	 * a job runs beside other requests that change them.
	 *
	 * @param int $product_id A product.
	 * @return array<int|string, array{src: string, token: string, state: string, reason: string}>
	 */
	private static function fresh( int $product_id ): array {
		wp_cache_delete( (string) $product_id, 'post_meta' );
		return self::stored_on( $product_id );
	}

	/**
	 * @param int                                                                                 $product_id A product.
	 * @param array<int|string, array{src: string, token: string, state: string, reason: string}> $copies     Its copies, by card key.
	 */
	private static function save( int $product_id, array $copies ): void {
		if ( array() === $copies ) {
			delete_post_meta( $product_id, self::META );
			return;
		}
		// update_post_meta() unslashes what it stores.
		update_post_meta( $product_id, self::META, wp_slash( $copies ) );
	}

	/**
	 * Copies an image into the media library, attached to the product.
	 * WordPress's `download_url()` downloads it, which refuses a URL of the
	 * store's own network and names the file for its type when its URL
	 * names none.
	 *
	 * @param string $url        The image's public URL.
	 * @param int    $product_id The product whose card shows it.
	 * @return int|WP_Error The attachment's id.
	 */
	private static function downloaded( string $url, int $product_id ): int|WP_Error {
		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/media.php';
		require_once ABSPATH . 'wp-admin/includes/image.php';
		$file = download_url( $url, self::DOWNLOAD_TIMEOUT_SECONDS );
		if ( is_wp_error( $file ) ) {
			return self::not_copied( $file->get_error_message() );
		}
		if ( (int) filesize( $file ) > wp_max_upload_size() ) {
			wp_delete_file( $file );
			/* translators: %s: the largest file the store takes, e.g. "64 MB". */
			return self::not_copied( sprintf( __( 'The file is larger than the store takes, at most %s.', 'pixonaut-for-woocommerce' ), size_format( wp_max_upload_size() ) ) );
		}
		$name = sanitize_file_name( wp_basename( (string) wp_parse_url( $url, PHP_URL_PATH ) ) );
		if ( '' === pathinfo( $name, PATHINFO_EXTENSION ) ) {
			// `download_url()` gave the file the extension of the type it was answered with.
			$name = ( '' === $name ? 'card' : $name ) . '.' . pathinfo( $file, PATHINFO_EXTENSION );
		}
		$attachment_id = media_handle_sideload(
			array(
				'name'     => $name,
				'tmp_name' => $file,
			),
			$product_id
		);
		if ( is_wp_error( $attachment_id ) || $attachment_id < 1 ) {
			wp_delete_file( $file );
			return self::not_copied( is_wp_error( $attachment_id ) ? $attachment_id->get_error_message() : '' );
		}
		if ( ! wp_attachment_is_image( $attachment_id ) ) {
			wp_delete_attachment( $attachment_id, true );
			return self::not_copied( '' );
		}
		return $attachment_id;
	}

	/**
	 * Why a card's image could not be copied from its URL.
	 *
	 * @param string $reason WordPress's reason; empty when it gave none.
	 */
	private static function not_copied( string $reason ): WP_Error {
		$refusal = __( 'The card\'s image could not be copied from its URL.', 'pixonaut-for-woocommerce' );
		return new WP_Error( 'pixonaut_wc_card_image_download', '' === $reason ? $refusal : $refusal . ' ' . $reason );
	}
}
