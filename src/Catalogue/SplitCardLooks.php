<?php
/**
 * The titles and images a split product's cards show in place of its own.
 *
 * @package Pixonaut\WooCommerce
 */

declare(strict_types=1);

namespace Pixonaut\WooCommerce\Catalogue;

use Pixonaut\WooCommerce\Schema;

/**
 * A product listed by one of its attributes (`SplitCards`) shows a card per
 * value. The store manager can give each value's card a title and an image
 * of its own; a card without them shows the product's title, and its value's
 * own picture or the product's (`Storefront\SplitCardFaces`). They are kept
 * by term id on the product, so they stay with their value when the product
 * is listed by another attribute and back.
 */
final class SplitCardLooks {

	public const META = Schema::PRODUCT_SPLIT_CARD_LOOKS_META;

	/**
	 * A product's cards' own titles and images, by term id; a value with
	 * neither is left out.
	 *
	 * @param int $product_id A product.
	 * @return array<int, array{title: string, image: int}>
	 */
	public static function stored_on( int $product_id ): array {
		$stored = get_post_meta( $product_id, self::META, true );
		return self::normalised( is_array( $stored ) ? $stored : array() );
	}

	/**
	 * One card's own title and image: an empty title and an image of 0 when
	 * it has none.
	 *
	 * @param int $product_id A product.
	 * @param int $term_id    The card's value.
	 * @return array{title: string, image: int}
	 */
	public static function of( int $product_id, int $term_id ): array {
		return self::stored_on( $product_id )[ $term_id ] ?? array(
			'title' => '',
			'image' => 0,
		);
	}

	/**
	 * Keeps a product's cards' own titles and images, in place of those it
	 * had; nothing is kept when every card has neither.
	 *
	 * @param int                 $product_id A product.
	 * @param array<mixed, mixed> $looks     Each value's `title` and `image`, by term id.
	 */
	public static function save( int $product_id, array $looks ): void {
		$looks = self::normalised( $looks );
		if ( self::stored_on( $product_id ) === $looks ) {
			return;
		}
		if ( array() === $looks ) {
			delete_post_meta( $product_id, self::META );
			return;
		}
		// update_post_meta() unslashes what it stores.
		update_post_meta( $product_id, self::META, wp_slash( $looks ) );
	}

	/**
	 * Titles as plain text and images as attachment ids, by a term id each.
	 *
	 * @param array<mixed, mixed> $looks Each value's `title` and `image`, by term id.
	 * @return array<int, array{title: string, image: int}>
	 */
	private static function normalised( array $looks ): array {
		$normalised = array();
		foreach ( $looks as $term_id => $look ) {
			if ( ! is_numeric( $term_id ) || (int) $term_id < 1 || ! is_array( $look ) ) {
				continue;
			}
			$title = is_string( $look['title'] ?? null ) ? sanitize_text_field( $look['title'] ) : '';
			$image = is_numeric( $look['image'] ?? null ) ? max( 0, (int) $look['image'] ) : 0;
			if ( '' !== $title || $image > 0 ) {
				$normalised[ (int) $term_id ] = array(
					'title' => $title,
					'image' => $image,
				);
			}
		}
		ksort( $normalised );
		return $normalised;
	}
}
