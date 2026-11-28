<?php
/**
 * The product's other sides its gallery shows.
 *
 * @package Pixonaut\WooCommerce
 */

declare(strict_types=1);

namespace Pixonaut\WooCommerce\Catalogue;

use Pixonaut\WooCommerce\Schema;
use WC_Product;

/**
 * A product's pictures show its first side (`PictureSources`). The store
 * manager may add pictures of its other sides to its gallery, after the
 * pictures of its first side: a calendar's month page beside its cover, a
 * shirt's back beside its front. Each is drawn with its side's template, on
 * the first view of the side's placement the product is shown in, or flat,
 * with the values that side is sold with (`SideValues`), so a page whose
 * motif the product sets shows that motif rather than the template's
 * default. The sides are those of the variation a variable product is
 * pictured as (`PicturedVariation`), whose setup may hold more sides than
 * its product's; a side it no longer has draws no picture. A variation has
 * none of its own: its image is its first side.
 */
final class SidePictures {

	public const META_KEY = Schema::PRODUCT_SIDE_PICTURES_META;

	/**
	 * Most side pictures a product shows: its gallery's worth beside the
	 * picture of its first side.
	 */
	public const MAX_PICTURES = ShowcaseImages::MAX_IMAGES - 1;

	/**
	 * The sides the product's gallery shows after its first side's pictures,
	 * in order, each with how it is drawn; empty on a variation.
	 *
	 * @param WC_Product $product A product or variation.
	 *
	 * @return list<array{side: string, kind: string}>
	 */
	public static function on( WC_Product $product ): array {
		return $product->is_type( 'variation' ) ? array() : self::parse( $product->get_meta( self::META_KEY ) );
	}

	/**
	 * The choices as stored, for what a picture depends on; null for none.
	 *
	 * @param WC_Product $product A product or variation.
	 */
	public static function document_on( WC_Product $product ): ?string {
		$raw = $product->is_type( 'variation' ) ? '' : $product->get_meta( self::META_KEY );
		return is_string( $raw ) && '' !== $raw ? $raw : null;
	}

	/**
	 * Stores the product's side pictures, or forgets them when there are none.
	 *
	 * @param WC_Product $product A simple or variable product.
	 * @param mixed      $sides   A list of `{side, kind}`; what is not one is dropped.
	 */
	public static function save( WC_Product $product, mixed $sides ): void {
		$sides = self::parse( $sides );
		if ( array() === $sides ) {
			$product->delete_meta_data( self::META_KEY );
		} else {
			$product->update_meta_data( self::META_KEY, (string) wp_json_encode( $sides ) );
		}
		$product->save_meta_data();
	}

	/**
	 * The choices in a stored or posted value: each side and kind once, at
	 * most `MAX_PICTURES`; a kind that is not `PREVIEW` is drawn on the photo.
	 *
	 * @param mixed $value JSON text, or a list.
	 *
	 * @return list<array{side: string, kind: string}>
	 */
	public static function parse( mixed $value ): array {
		$list   = is_string( $value ) ? json_decode( $value, true ) : $value;
		$chosen = array();
		foreach ( is_array( $list ) ? $list : array() as $row ) {
			$side = is_array( $row ) ? ( $row['side'] ?? null ) : null;
			if ( ! is_string( $side ) || '' === $side ) {
				continue;
			}
			$kind   = PictureView::PREVIEW === ( $row['kind'] ?? null ) ? PictureView::PREVIEW : PictureView::MOCKUP;
			$choice = array(
				'side' => $side,
				'kind' => $kind,
			);
			$key    = $side . "\0" . $kind;
			if ( ! isset( $chosen[ $key ] ) ) {
				$chosen[ $key ] = $choice;
			}
		}
		return array_slice( array_values( $chosen ), 0, self::MAX_PICTURES );
	}

	/**
	 * The sides a product's gallery can show: those of the variation a
	 * variable product is pictured as, else its own.
	 *
	 * @param WC_Product $product A simple or variable product.
	 *
	 * @return list<Side>
	 */
	public static function sides_of( WC_Product $product ): array {
		$pictured = PicturedVariation::of( $product ) ?? $product;
		return ProductConfig::config_for( $pictured )->sides;
	}
}
