<?php
/**
 * The showcase sets a product's images are drawn with.
 *
 * @package Pixonaut\WooCommerce
 */

declare(strict_types=1);

namespace Pixonaut\WooCommerce\Catalogue;

use Pixonaut\WooCommerce\Schema;
use WC_Product;

/**
 * A template can carry showcase sets: named sets of example values, such as
 * a real name and photo, that its pictures are drawn with instead of its
 * defaults. The store manager chooses, for a product, which of its first
 * side's template's sets are product images, and whether each is drawn on
 * the blank's photo (`MOCKUP`), on a view and photo of it the store
 * manager names (`PictureView`), or flat (`PREVIEW`). The first is the
 * product's image, the others are its gallery; a variation's image is drawn
 * with its product's first. The designer never sees them: a customer always
 * starts from the template's own defaults.
 *
 * Each choice keeps the set's name, so one Pixonaut no longer has can still
 * be named.
 */
final class ShowcaseImages {

	public const META_KEY = Schema::PRODUCT_SHOWCASE_IMAGES_META;

	/**
	 * Drawn into the print area of the blank's photo, as the product's picture is.
	 */
	public const MOCKUP = PictureView::MOCKUP;

	/**
	 * Drawn flat, the template alone.
	 */
	public const PREVIEW = PictureView::PREVIEW;

	/**
	 * Most sets a product shows: a gallery's worth.
	 */
	public const MAX_IMAGES = 10;

	/**
	 * The sets the product's images are drawn with, the first its image; a
	 * variation's are its product's. Empty for the template's defaults.
	 *
	 * @param WC_Product $product A product or variation.
	 *
	 * @return list<array{id: string, name: string, kind: string, view?: string, photo?: string}>
	 */
	public static function on( WC_Product $product ): array {
		$owner = self::owner( $product );
		return null === $owner ? array() : self::parse( $owner->get_meta( self::META_KEY ) );
	}

	/**
	 * The choices as stored, for what a picture depends on; null for none.
	 *
	 * @param WC_Product $product A product or variation.
	 */
	public static function document_on( WC_Product $product ): ?string {
		$owner = self::owner( $product );
		$raw   = null === $owner ? '' : $owner->get_meta( self::META_KEY );
		return is_string( $raw ) && '' !== $raw ? $raw : null;
	}

	/**
	 * Stores the product's choices, or forgets them when there are none.
	 *
	 * @param WC_Product $product A simple or variable product.
	 * @param mixed      $images  A list of `{id, name, kind, view?, photo?}`; what is not one is dropped.
	 */
	public static function save( WC_Product $product, mixed $images ): void {
		$images = self::parse( $images );
		if ( array() === $images ) {
			$product->delete_meta_data( self::META_KEY );
		} else {
			$product->update_meta_data( self::META_KEY, (string) wp_json_encode( $images ) );
		}
		$product->save_meta_data();
	}

	/**
	 * The choices in a stored or posted value, each set once, at most `MAX_IMAGES`.
	 *
	 * @param mixed $value JSON text, or a list.
	 *
	 * @return list<array{id: string, name: string, kind: string, view?: string, photo?: string}>
	 */
	public static function parse( mixed $value ): array {
		return array_slice( self::each_once( $value ), 0, self::MAX_IMAGES );
	}

	/**
	 * A template's sets as Pixonaut lists them, each once, however many.
	 *
	 * @param mixed $value A list of `{id, name}`.
	 *
	 * @return list<array{id: string, name: string, kind: string, view?: string, photo?: string}> Each drawn on the photo until chosen otherwise.
	 */
	public static function sets( mixed $value ): array {
		return self::each_once( $value );
	}

	/**
	 * @param mixed $value JSON text, or a list.
	 *
	 * @return list<array{id: string, name: string, kind: string, view?: string, photo?: string}>
	 */
	private static function each_once( mixed $value ): array {
		$list   = is_string( $value ) ? json_decode( $value, true ) : $value;
		$images = array();
		foreach ( is_array( $list ) ? $list : array() as $image ) {
			$id = is_array( $image ) ? ( $image['id'] ?? null ) : null;
			if ( ! is_string( $id ) || '' === $id || isset( $images[ $id ] ) ) {
				continue;
			}
			$name          = $image['name'] ?? null;
			$images[ $id ] = array(
				'id'   => $id,
				'name' => is_string( $name ) && '' !== $name ? $name : $id,
			) + ( PictureView::parse( $image ) ?? array( 'kind' => self::MOCKUP ) );
		}
		return array_values( $images );
	}

	/**
	 * The product the choices are stored on: the product, or a variation's product.
	 *
	 * @param WC_Product $product A product or variation.
	 */
	private static function owner( WC_Product $product ): ?WC_Product {
		if ( ! $product->is_type( 'variation' ) ) {
			return $product;
		}
		$parent = wc_get_product( $product->get_parent_id() );
		return $parent instanceof WC_Product ? $parent : null;
	}
}
