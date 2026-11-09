<?php
/**
 * What a product picture is drawn on: a view of the blank, one of its
 * photos, or the flat design.
 *
 * @package Pixonaut\WooCommerce
 */

declare(strict_types=1);

namespace Pixonaut\WooCommerce\Catalogue;

use Pixonaut\WooCommerce\Schema;
use WC_Product;

/**
 * A picture is drawn on the blank's photo (`MOCKUP`) or flat (`PREVIEW`).
 * On the photo, the store manager may name the view of the side's placement
 * and the photo of that view; without, it is drawn as the product page shows
 * the product (`PictureSources`). The choice of each showcase image is kept
 * with its set (`ShowcaseImages`); the choice of the picture drawn without a
 * set, and a variation's own, are kept here. A variation without its own
 * choice follows its product's for its image.
 *
 * A choice the blank no longer has is not dropped: the picture is drawn as
 * without one until the store manager chooses again, and the field says so.
 */
final class PictureView {

	public const META_KEY = Schema::PICTURE_VIEW_META;

	/**
	 * Drawn into the print area of the blank's photo.
	 */
	public const MOCKUP = 'mockup';

	/**
	 * Drawn flat, the template alone.
	 */
	public const PREVIEW = 'preview';

	/**
	 * The choice stored on a product or variation; null for none.
	 *
	 * @param WC_Product $product A product or variation.
	 *
	 * @return array{kind: string, view?: string, photo?: string}|null
	 */
	public static function on( WC_Product $product ): ?array {
		$raw = $product->get_meta( self::META_KEY );
		return is_string( $raw ) && '' !== $raw ? self::parse( json_decode( $raw, true ) ) : null;
	}

	/**
	 * The choice as stored, for what a picture depends on; null for none.
	 *
	 * @param WC_Product $product A product or variation.
	 */
	public static function document_on( WC_Product $product ): ?string {
		$raw = $product->get_meta( self::META_KEY );
		return is_string( $raw ) && '' !== $raw ? $raw : null;
	}

	/**
	 * Stores the choice, or forgets it when it chooses nothing: on the photo
	 * without a view, as the product page shows it, is the default.
	 *
	 * @param WC_Product $product A product or variation.
	 * @param mixed      $choice  As `parse()` reads it; null for none.
	 */
	public static function save( WC_Product $product, mixed $choice ): void {
		$choice = self::parse( $choice );
		if ( null === $choice || self::is_default( $choice ) ) {
			$product->delete_meta_data( self::META_KEY );
		} else {
			$product->update_meta_data( self::META_KEY, (string) wp_json_encode( $choice ) );
		}
		$product->save_meta_data();
	}

	/**
	 * Whether a choice draws the picture as without one.
	 *
	 * @param array{kind: string, view?: string, photo?: string} $choice A choice.
	 */
	public static function is_default( array $choice ): bool {
		return self::MOCKUP === $choice['kind'] && ! isset( $choice['view'] );
	}

	/**
	 * The choice in a stored or posted value: `kind`, and on the photo the
	 * `view` and, of that view, the `photo`. A photo without a view is
	 * dropped, as is a view or photo of a flat picture.
	 *
	 * @param mixed $value A decoded `{kind, view?, photo?}`.
	 *
	 * @return array{kind: string, view?: string, photo?: string}|null Null when it is not one.
	 */
	public static function parse( mixed $value ): ?array {
		if ( ! is_array( $value ) ) {
			return null;
		}
		$choice = array( 'kind' => self::PREVIEW === ( $value['kind'] ?? null ) ? self::PREVIEW : self::MOCKUP );
		$view   = $value['view'] ?? null;
		if ( self::MOCKUP === $choice['kind'] && is_string( $view ) && '' !== $view ) {
			$choice['view'] = $view;
			$photo          = $value['photo'] ?? null;
			if ( is_string( $photo ) && '' !== $photo ) {
				$choice['photo'] = $photo;
			}
		}
		return $choice;
	}

	/**
	 * The value a field posts for a choice: `mockup` and `preview` as they
	 * are, a view and photo as JSON.
	 *
	 * @param array{kind: string, view?: string, photo?: string} $choice A choice.
	 */
	public static function field_value( array $choice ): string {
		$choice = self::parse( $choice ) ?? array( 'kind' => self::MOCKUP );
		return isset( $choice['view'] ) ? (string) wp_json_encode( $choice ) : $choice['kind'];
	}

	/**
	 * The choice a field posted, as `field_value()` writes it.
	 *
	 * @param string $value The posted value, unslashed.
	 *
	 * @return array{kind: string, view?: string, photo?: string}
	 */
	public static function from_field( string $value ): array {
		if ( self::PREVIEW === $value || self::MOCKUP === $value ) {
			return array( 'kind' => $value );
		}
		$decoded = json_decode( $value, true );
		$posted  = array( 'kind' => self::MOCKUP );
		foreach ( array( 'view', 'photo' ) as $part ) {
			if ( is_array( $decoded ) && is_string( $decoded[ $part ] ?? null ) ) {
				$posted[ $part ] = sanitize_text_field( $decoded[ $part ] );
			}
		}
		return self::parse( $posted ) ?? array( 'kind' => self::MOCKUP );
	}

	/**
	 * A placement's views with the photos a picture can be drawn on, as
	 * Pixonaut lists them (`GET /v1/blanks/{id}/mockups`), in its order:
	 * each view's id and label, and its photos' ids, thumbnails and the
	 * variants they show. A photo still uploading, or of a view whose
	 * print area is not marked, is left out; so is a view without photos.
	 *
	 * @param array<mixed> $placements   The blank's placements, as Pixonaut lists them.
	 * @param string       $placement_id One of them.
	 *
	 * @return list<array{id: string, label: string, photos: list<array{id: string, thumbnail: string, variants: list<string>}>}>
	 */
	public static function views_of( array $placements, string $placement_id ): array {
		$views = array();
		foreach ( $placements as $placement ) {
			if ( ! is_array( $placement ) || ( $placement['id'] ?? null ) !== $placement_id ) {
				continue;
			}
			foreach ( is_array( $placement['views'] ?? null ) ? $placement['views'] : array() as $view ) {
				if ( ! is_array( $view ) || ! is_string( $view['id'] ?? null ) || '' === $view['id'] ) {
					continue;
				}
				$photos = array();
				foreach ( is_array( $view['photos'] ?? null ) ? $view['photos'] : array() as $photo ) {
					// A photo Pixonaut cannot draw on yet has no mount.
					if ( ! is_array( $photo ) || ! is_string( $photo['id'] ?? null ) || '' === $photo['id'] || ( array_key_exists( 'mount', $photo ) && null === $photo['mount'] ) ) {
						continue;
					}
					$photos[] = array(
						'id'        => $photo['id'],
						'thumbnail' => is_string( $photo['thumbnailUrl'] ?? null ) ? $photo['thumbnailUrl'] : '',
						'variants'  => array_values( array_filter( is_array( $photo['variantRefs'] ?? null ) ? $photo['variantRefs'] : array(), 'is_string' ) ),
					);
				}
				if ( array() !== $photos ) {
					$views[] = array(
						'id'     => $view['id'],
						'label'  => is_string( $view['label'] ?? null ) && '' !== $view['label'] ? $view['label'] : $view['id'],
						'photos' => $photos,
					);
				}
			}
		}
		return $views;
	}

	/**
	 * Where a choice draws the picture among the placement's views: its view
	 * and, when it names one, its photo. A photo that does not show the
	 * variant is drawn only when it was chosen for this picture (`$own`);
	 * followed from another's choice, the view's photo of the variant is
	 * drawn instead. Null when the blank no longer has the view or photo.
	 *
	 * @param list<array{id: string, label: string, photos: list<array{id: string, thumbnail: string, variants: list<string>}>}> $views   The placement's views, as `views_of()` lists them.
	 * @param array{kind: string, view?: string, photo?: string}                                                                  $choice  A choice naming a view.
	 * @param string|null                                                                                                         $variant The variant the picture shows; null for any.
	 * @param bool                                                                                                                $own     Whether the choice is the picture's own.
	 *
	 * @return array{view: string, photo: string|null}|null
	 */
	public static function resolve( array $views, array $choice, ?string $variant, bool $own ): ?array {
		foreach ( $views as $view ) {
			if ( $view['id'] !== ( $choice['view'] ?? null ) ) {
				continue;
			}
			if ( ! isset( $choice['photo'] ) ) {
				return array(
					'view'  => $view['id'],
					'photo' => null,
				);
			}
			foreach ( $view['photos'] as $photo ) {
				if ( $photo['id'] !== $choice['photo'] ) {
					continue;
				}
				$shows = null === $variant || array() === $photo['variants'] || in_array( $variant, $photo['variants'], true );
				return array(
					'view'  => $view['id'],
					'photo' => $own || $shows ? $photo['id'] : null,
				);
			}
			return null;
		}
		return null;
	}
}
