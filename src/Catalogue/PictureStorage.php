<?php
/**
 * How many Pixonaut pictures the shop keeps, and how much space they take.
 *
 * @package Pixonaut\WooCommerce
 */

declare(strict_types=1);

namespace Pixonaut\WooCommerce\Catalogue;

use Pixonaut\WooCommerce\Schema;

/**
 * Every Pixonaut picture is an attachment naming the products and
 * variations showing it (`SharedPictures`). Its space is its file plus each
 * image size WordPress made from it. A product's pictures are those it or
 * one of its variations shows; a variation picture is one only its
 * variations show, not the product's own image or gallery.
 */
final class PictureStorage {

	/**
	 * Attachments whose metadata is read per query.
	 */
	private const BATCH = 200;

	/**
	 * Transient keeping the average picture's bytes (0 for none) between
	 * estimates, which read every picture to make it.
	 */
	public const AVERAGE_TRANSIENT = Schema::PICTURE_AVERAGE_TRANSIENT;

	/**
	 * How long the average is kept: an hour, which drawings move little.
	 */
	private const AVERAGE_SECONDS = 60 * 60;

	/**
	 * The pictures of each product that shows any, with the bytes of each,
	 * by product id.
	 *
	 * @return array<int, array<int, array{bytes: int, variation: bool}>> Attachments by id, each with its bytes and whether only variations show it.
	 */
	public function families(): array {
		global $wpdb;
		// One read of every picture's owners: a large catalogue has one per product and variation.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- as above; counted on demand, never on a shopper's page.
		$rows     = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT shown_by.post_id AS picture, owner.ID AS owner, owner.post_type AS type, owner.post_parent AS parent
				FROM {$wpdb->postmeta} AS shown_by
				INNER JOIN {$wpdb->posts} AS picture ON picture.ID = shown_by.post_id AND picture.post_type = 'attachment'
				INNER JOIN {$wpdb->posts} AS owner ON owner.ID = shown_by.meta_value
				WHERE shown_by.meta_key = %s AND owner.post_type IN ('product', 'product_variation')",
				SharedPictures::PICTURE_OF_META
			),
			ARRAY_A
		);
		$families = array();
		foreach ( is_array( $rows ) ? $rows : array() as $row ) {
			$variation                        = 'product_variation' === $row['type'];
			$product                          = $variation ? (int) $row['parent'] : (int) $row['owner'];
			$picture                          = (int) $row['picture'];
			$families[ $product ][ $picture ] = ( $families[ $product ][ $picture ] ?? true ) && $variation;
		}
		$bytes = $this->bytes( array_values( array_unique( array_merge( array(), ...array_map( 'array_keys', array_values( $families ) ) ) ) ) );
		$sized = array();
		foreach ( $families as $product => $pictures ) {
			foreach ( $pictures as $picture => $variation ) {
				$sized[ $product ][ $picture ] = array(
					'bytes'     => $bytes[ $picture ] ?? 0,
					'variation' => $variation,
				);
			}
		}
		return $sized;
	}

	/**
	 * Totals over every picture, a picture shared by several products
	 * counted once, and per product.
	 *
	 * @return array{pictures: int, bytes: int, variation_pictures: int, variation_bytes: int, products: array<int, array{pictures: int, bytes: int, variation_pictures: int, variation_bytes: int}>}
	 */
	public function totals(): array {
		$totals = array(
			'pictures'           => 0,
			'bytes'              => 0,
			'variation_pictures' => 0,
			'variation_bytes'    => 0,
			'products'           => array(),
		);
		$seen   = array();
		foreach ( $this->families() as $product => $pictures ) {
			$own                            = self::sum( $pictures );
			$totals['products'][ $product ] = $own;
			foreach ( $pictures as $picture => $sized ) {
				if ( isset( $seen[ $picture ] ) ) {
					continue;
				}
				$seen[ $picture ] = true;
				++$totals['pictures'];
				$totals['bytes'] += $sized['bytes'];
				if ( $sized['variation'] ) {
					++$totals['variation_pictures'];
					$totals['variation_bytes'] += $sized['bytes'];
				}
			}
		}
		return $totals;
	}

	/**
	 * The average space of a picture the shop keeps; null when it keeps none.
	 */
	public function average_bytes(): ?int {
		$kept = get_transient( self::AVERAGE_TRANSIENT );
		if ( is_int( $kept ) ) {
			return $kept > 0 ? $kept : null;
		}
		$totals  = $this->totals();
		$average = 0 === $totals['pictures'] ? 0 : intdiv( $totals['bytes'], $totals['pictures'] );
		set_transient( self::AVERAGE_TRANSIENT, $average, self::AVERAGE_SECONDS );
		return $average > 0 ? $average : null;
	}

	/**
	 * Forgets the average kept, so the next estimate reads the pictures again.
	 */
	public static function forget_average(): void {
		delete_transient( self::AVERAGE_TRANSIENT );
	}

	/**
	 * The bytes of each attachment: its file and its image sizes.
	 *
	 * @param int[] $attachments Attachment ids.
	 * @phpstan-param list<int> $attachments
	 *
	 * @return array<int, int> By attachment id.
	 */
	public function bytes( array $attachments ): array {
		$bytes = array();
		foreach ( array_chunk( $attachments, self::BATCH ) as $batch ) {
			update_meta_cache( 'post', $batch );
			foreach ( $batch as $attachment ) {
				$bytes[ $attachment ] = self::bytes_of( $attachment );
				// Read once: a large catalogue's metadata is not kept for the rest of the request.
				wp_cache_delete( (string) $attachment, 'post_meta' );
			}
		}
		return $bytes;
	}

	/**
	 * The attachment's file and image sizes, by the sizes WordPress recorded
	 * when it made them, else by the files on disk.
	 *
	 * @param int $attachment An attachment id.
	 */
	public static function bytes_of( int $attachment ): int {
		$file     = get_attached_file( $attachment );
		$metadata = wp_get_attachment_metadata( $attachment );
		$metadata = is_array( $metadata ) ? $metadata : array();
		$total    = self::file_bytes( $metadata['filesize'] ?? null, is_string( $file ) ? $file : null );
		$folder   = is_string( $file ) ? dirname( $file ) : null;
		foreach ( is_array( $metadata['sizes'] ?? null ) ? $metadata['sizes'] : array() as $size ) {
			if ( ! is_array( $size ) ) {
				continue;
			}
			$path   = null !== $folder && is_string( $size['file'] ?? null ) ? $folder . '/' . $size['file'] : null;
			$total += self::file_bytes( $size['filesize'] ?? null, $path );
		}
		return $total;
	}

	/**
	 * A file's size as recorded, else as on disk; 0 when it is neither.
	 *
	 * @param mixed       $recorded The size WordPress recorded.
	 * @param string|null $path     The file.
	 */
	private static function file_bytes( mixed $recorded, ?string $path ): int {
		if ( is_int( $recorded ) && $recorded >= 0 ) {
			return $recorded;
		}
		$size = null !== $path && is_file( $path ) ? filesize( $path ) : false;
		return false === $size ? 0 : $size;
	}

	/**
	 * Counts and bytes of a product's pictures.
	 *
	 * @param array<int, array{bytes: int, variation: bool}> $pictures By attachment id.
	 *
	 * @return array{pictures: int, bytes: int, variation_pictures: int, variation_bytes: int}
	 */
	private static function sum( array $pictures ): array {
		$variation = array_filter( $pictures, static fn ( array $sized ): bool => $sized['variation'] );
		return array(
			'pictures'           => count( $pictures ),
			'bytes'              => array_sum( array_column( $pictures, 'bytes' ) ),
			'variation_pictures' => count( $variation ),
			'variation_bytes'    => array_sum( array_column( $variation, 'bytes' ) ),
		);
	}
}
