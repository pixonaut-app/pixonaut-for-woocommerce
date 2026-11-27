<?php
/**
 * The titles and images a split product's cards show in place of its own.
 *
 * @package Pixonaut\WooCommerce
 */

declare(strict_types=1);

namespace Pixonaut\WooCommerce\Catalogue;

use Pixonaut\WooCommerce\Schema;
use WC_Product;
use WC_Product_Attribute;

/**
 * A product listed by its attributes (`SplitCards`) shows a card per value,
 * or per combination of values. The store manager can give each card a
 * title and an image of its own; a card without them shows the product's
 * title, and its variations' own picture or the product's
 * (`Storefront\SplitCardFaces`). A card an unfiltered listing shows keeps
 * the flat picture of its design over its own image, which it shows only
 * while none is drawn (`CardPages::image()`). They are kept by the card's key on the
 * product (a value's term id, or a combination's joined), so they stay with
 * their card when the product is listed by another attribute and back, until
 * the product loses one of the card's values.
 */
final class SplitCardLooks {

	public const META = Schema::PRODUCT_SPLIT_CARD_LOOKS_META;

	/**
	 * Prefix of the MySQL lock one product's cards' titles, images and
	 * waiting image copies are changed under.
	 */
	private const LOCK_PREFIX = 'pixonaut_split_card_looks_';

	/**
	 * Runs a change to a product's cards' titles, images or waiting image
	 * copies alone: each is kept as one meta value for all the product's
	 * cards, read and written whole, so two changes at once, as two cards'
	 * copy jobs or a job beside a request, would each write over the other's.
	 * The change reads what is stored once it holds the lock, whatever the
	 * request read before. A change still running after the wait is taken
	 * to be stuck, and this one runs beside it.
	 *
	 * @template T
	 * @param int           $product_id A product.
	 * @param callable(): T $change     The change: it reads, then writes.
	 * @return T What the change returned.
	 */
	public static function alone( int $product_id, callable $change ): mixed {
		return PostLock::alone( self::LOCK_PREFIX, $product_id, $change );
	}

	/**
	 * A product's cards' own titles and images, by card key; a card with
	 * neither is left out.
	 *
	 * @param int $product_id A product.
	 * @return array<int|string, array{title: string, image: int}>
	 */
	public static function stored_on( int $product_id ): array {
		$stored = get_post_meta( $product_id, self::META, true );
		return self::normalised( is_array( $stored ) ? $stored : array() );
	}

	/**
	 * One card's own title and image: an empty title and an image of 0 when
	 * it has none.
	 *
	 * @param int        $product_id A product.
	 * @param int|string $key        The card's key.
	 * @return array{title: string, image: int}
	 */
	public static function of( int $product_id, int|string $key ): array {
		return self::stored_on( $product_id )[ $key ] ?? array(
			'title' => '',
			'image' => 0,
		);
	}

	/**
	 * Keeps a product's cards' own titles and images, in place of those it
	 * had; nothing is kept when every card has neither.
	 *
	 * @param int                 $product_id A product.
	 * @param array<mixed, mixed> $looks     Each card's `title` and `image`, by card key.
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
	 * Changes the titles and images of some of a product's cards, keeping
	 * those of every other card: a card listed here with neither loses its
	 * own.
	 *
	 * @param int                 $product_id A product.
	 * @param array<mixed, mixed> $changed   Each changed card's `title` and `image`, by card key.
	 */
	public static function change( int $product_id, array $changed ): void {
		$looks = self::stored_on( $product_id );
		foreach ( $changed as $key => $look ) {
			if ( SplitCards::is_key( $key ) ) {
				$looks[ $key ] = $look;
			}
		}
		self::save( $product_id, $looks );
	}

	/**
	 * Drops the titles and images of the cards holding a value the product
	 * no longer has, as after one of its attributes or values was removed.
	 *
	 * @param WC_Product $product A product.
	 */
	public static function forget_removed_values( WC_Product $product ): void {
		$looks = self::stored_on( $product->get_id() );
		if ( array() === $looks ) {
			return;
		}
		self::save( $product->get_id(), array_filter( $looks, static fn ( int|string $key ): bool => self::has_values_of( $product, $key ), ARRAY_FILTER_USE_KEY ) );
	}

	/**
	 * Whether a product has every value of a card: a card of a value it
	 * lost takes no title or image.
	 *
	 * @param WC_Product $product A product.
	 * @param int|string $key     The card's key.
	 */
	public static function has_values_of( WC_Product $product, int|string $key ): bool {
		$term_ids = array();
		foreach ( $product->get_attributes() as $attribute ) {
			if ( $attribute instanceof WC_Product_Attribute && $attribute->is_taxonomy() ) {
				$term_ids = array_merge( $term_ids, array_map( 'intval', $attribute->get_options() ) );
			}
		}
		return array() === array_diff( SplitCards::term_ids( (string) $key ), $term_ids );
	}

	/**
	 * Titles as plain text and images as attachment ids, by a card's key
	 * each. The card of no value (`SplitCards::NO_VALUE`) shows the product
	 * as itself and takes neither.
	 *
	 * @param array<mixed, mixed> $looks Each card's `title` and `image`, by card key.
	 * @return array<int|string, array{title: string, image: int}>
	 */
	private static function normalised( array $looks ): array {
		$normalised = array();
		foreach ( $looks as $key => $look ) {
			if ( ! SplitCards::is_key( $key ) || SplitCards::NO_VALUE === (string) $key || ! is_array( $look ) ) {
				continue;
			}
			$title = is_string( $look['title'] ?? null ) ? sanitize_text_field( $look['title'] ) : '';
			$image = is_numeric( $look['image'] ?? null ) ? max( 0, (int) $look['image'] ) : 0;
			if ( '' !== $title || $image > 0 ) {
				$normalised[ $key ] = array(
					'title' => $title,
					'image' => $image,
				);
			}
		}
		ksort( $normalised );
		return $normalised;
	}
}
