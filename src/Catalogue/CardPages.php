<?php
/**
 * The page each card of a split product has.
 *
 * @package Pixonaut\WooCommerce
 */

declare(strict_types=1);

namespace Pixonaut\WooCommerce\Catalogue;

use Pixonaut\WooCommerce\Storefront\QueryNames;
use WC_Product;
use WP_Term;

/**
 * Each card a split product is listed as (`SplitCards`) has a page of its
 * own: the product's page at the address that chooses the card's values,
 * `…/safari/?picture=with-photo`, each attribute by its short name
 * (`QueryNames`). It is the page a search engine
 * lists for that version of the product, with the card's title, description
 * and picture, and the address every other choice on top of the card's (a
 * size, say) names as its canonical one, so the sizes of a card make no
 * pages of their own.
 *
 * The card of the variations no listed attribute applies to
 * (`SplitCards::NO_VALUE`) shows the product as itself and has no page but
 * the product's.
 */
final class CardPages {

	/**
	 * Between a product's title and its card's values, in the title of a
	 * card that has none of its own.
	 */
	private const TITLE_SEPARATOR = ' – ';

	/**
	 * Between a card's values and the text describing its product.
	 */
	private const DESCRIPTION_SEPARATOR = '. ';

	/**
	 * The most words of the product's own text a card's description takes:
	 * about what a search engine shows of one.
	 */
	private const DESCRIPTION_WORDS = 30;

	/**
	 * The address of a card's page: the product's, choosing the card's
	 * values, always in the same order.
	 *
	 * @param WC_Product $product   A product.
	 * @param string     $key       The key of one of its cards.
	 * @param string     $permalink The product's own address.
	 */
	public static function url( WC_Product $product, string $key, string $permalink ): string {
		return QueryNames::shorten( add_query_arg( array_map( 'rawurlencode', SplitCards::link_values( $product, $key ) ), $permalink ) );
	}

	/**
	 * The card whose page an address is, or is another choice on top of:
	 * the card whose every value the address names, the one of most values
	 * when it names those of several, and the first of them in the
	 * product's order on a tie. A value the address names of a listed
	 * attribute that is not in the card is one more choice on top of it, as
	 * a form holds for an attribute that does not apply to the card's
	 * variations. Null when the address names no card's values, as the
	 * product's own address.
	 *
	 * @param WC_Product            $product A product.
	 * @param array<string, string> $values  The values an address names, by attribute as a variation keeps it (`pa_colour`).
	 * @return string|null The card's key.
	 */
	public static function named_by( WC_Product $product, array $values ): ?string {
		$named = array();
		foreach ( SplitListing::attributes_of( $product ) as $attribute ) {
			$value = $values[ sanitize_title( $attribute->get_name() ) ] ?? '';
			$term  = '' === $value ? false : get_term_by( 'slug', sanitize_title( $value ), $attribute->get_name() );
			if ( $term instanceof WP_Term ) {
				$named[] = $term->term_id;
			}
		}
		$card = null;
		$most = 0;
		foreach ( array() === $named ? array() : SplitCards::keys( $product ) as $key ) {
			$held = SplitCards::term_ids( $key );
			if ( count( $held ) > $most && array() === array_diff( $held, $named ) ) {
				$card = $key;
				$most = count( $held );
			}
		}
		return $card;
	}

	/**
	 * The title of a card's page: the card's own, else the product's
	 * followed by the card's values.
	 *
	 * @param WC_Product $product A product.
	 * @param string     $key     The key of one of its cards.
	 */
	public static function title( WC_Product $product, string $key ): string {
		$own = SplitCardLooks::of( $product->get_id(), $key )['title'];
		if ( '' !== $own ) {
			return $own;
		}
		$values = SplitCards::name( SplitCards::card_terms( $product, $key ) );
		return '' === $values ? $product->get_name() : $product->get_name() . self::TITLE_SEPARATOR . $values;
	}

	/**
	 * The description of a card's page: the card's values, then the text
	 * describing its product, so no two cards of a product share one.
	 *
	 * @param WC_Product $product   A product.
	 * @param string     $key       The key of one of its cards.
	 * @param string     $described The text describing the product, when the store wrote one for search engines; else the start of the product's short description, or of its description.
	 */
	public static function description( WC_Product $product, string $key, string $described = '' ): string {
		if ( '' === trim( $described ) ) {
			$text      = '' !== trim( $product->get_short_description() ) ? $product->get_short_description() : $product->get_description();
			$described = wp_trim_words( strip_shortcodes( $text ), self::DESCRIPTION_WORDS );
		}
		$values = SplitCards::name( SplitCards::card_terms( $product, $key ) );
		return implode( self::DESCRIPTION_SEPARATOR, array_filter( array( $values, trim( $described ) ), static fn ( string $part ): bool => '' !== $part ) );
	}

	/**
	 * The picture of a card. When the filters pick values the card does not
	 * hold (`$picked`), the picture of the whole combination: the variation
	 * the card opens on on its blank's photo (`combination()`). Else, and
	 * while that is not drawn, for a card an unfiltered listing shows, the
	 * flat picture of its design, drawn for the first of its variations
	 * that has one (`ProductPictures::flat()`), the template alone as the
	 * product's other lists show it; else its own image; else, as for a
	 * card holding a value of an attribute listed only while filtered (a
	 * framing), the image of one of its variations, a picture of the
	 * product in its setting: of the variation a filtered listing opens the
	 * card on when it has one, else of the one agreeing with most of the
	 * values it opens on, the first of them on a tie. 0 when it has none of
	 * these, and shows the product's.
	 *
	 * @param WC_Product            $product A product.
	 * @param string                $key     The key of one of its cards.
	 * @param array<string, string> $opens   The values a filtered listing opens the card on, by `attribute_<name>` field (`FilteredOpening`); none out of one.
	 * @param \Closure|null         $undrawn `fn(WC_Product $variation, bool $on_photo): void`, told of the variation the card opens on when it is to show a picture of it not drawn yet: on its blank's photo, or its image.
	 * @param bool                  $picked  Whether the filters pick a value of an attribute the card holds none of, as a frame's colour on a card of a framing.
	 * @return int An attachment id.
	 */
	public static function image( WC_Product $product, string $key, array $opens = array(), ?\Closure $undrawn = null, bool $picked = false ): int {
		$opened = $picked ? self::opened( SplitCards::card_variations( $product, $key ), SplitCards::variation_values( $product->get_id() ), $opens ) : null;
		if ( null !== $opened ) {
			$combination = self::combination( $opened );
			if ( $combination > 0 ) {
				return $combination;
			}
			if ( null !== $undrawn ) {
				$undrawn( $opened, true );
			}
		}
		$ids = SplitCards::card_variations( $product, $key );
		if ( SplitCards::is_unfiltered( $product, $key ) ) {
			// The design alone, as the product shows in every other list, even over the card's own image.
			foreach ( self::loaded( $ids ) as $variation ) {
				$flat = ProductPictures::flat( $variation );
				if ( $flat > 0 ) {
					return $flat;
				}
			}
		}
		$own = SplitCardLooks::of( $product->get_id(), $key )['image'];
		if ( $own > 0 && wp_attachment_is_image( $own ) ) {
			return $own;
		}
		$held     = SplitCards::variation_values( $product->get_id() );
		$opened ??= self::opened( $ids, $held, $opens );
		if ( null !== $opened ) {
			$image = (int) $opened->get_image_id( 'edit' );
			if ( $image > 0 ) {
				return $image;
			}
			if ( null !== $undrawn ) {
				$undrawn( $opened, false );
			}
		}
		// Most agreeing first; `usort()` keeps the product's order on a tie.
		$agreeing = array();
		foreach ( $ids as $id ) {
			$agreeing[ $id ] = count( array_intersect_assoc( $held[ $id ] ?? array(), $opens ) );
		}
		usort( $ids, static fn ( int $first, int $second ): int => $agreeing[ $second ] <=> $agreeing[ $first ] );
		foreach ( self::loaded( $ids ) as $variation ) {
			$image = (int) $variation->get_image_id( 'edit' );
			if ( $image > 0 ) {
				return $image;
			}
		}
		return 0;
	}

	/**
	 * The picture of a variation in its setting, its blank's photo: the
	 * Pixonaut picture of it there (`ProductPictures::on_photo()`), else an
	 * image the store gave it, which shows it as the store chose; 0 while
	 * neither is there, as when its Pixonaut picture is drawn flat alone.
	 *
	 * @param WC_Product $variation A variation.
	 */
	public static function combination( WC_Product $variation ): int {
		$photo = ProductPictures::on_photo( $variation );
		if ( $photo > 0 ) {
			return $photo;
		}
		$image = (int) $variation->get_image_id( 'edit' );
		return $image > 0 && ! PictureImage::is_picture( $image, $variation ) ? $image : 0;
	}

	/**
	 * The variation a filtered listing opens a card on: the first of its
	 * variations that sets each of the values the listing names, or leaves
	 * it open, as the product's form chooses. Null out of a filtered
	 * listing, or when none does.
	 *
	 * @param int[]                             $ids   The card's variations, in the product's order of variations.
	 * @param array<int, array<string, string>> $held  Each variation's values by `attribute_<name>` field.
	 * @param array<string, string>             $opens The values the listing names, by field; none out of one.
	 * @phpstan-param list<int> $ids
	 */
	private static function opened( array $ids, array $held, array $opens ): ?WC_Product {
		if ( array() === $opens ) {
			return null;
		}
		foreach ( $ids as $id ) {
			$own = $held[ $id ] ?? array();
			foreach ( $opens as $field => $value ) {
				$set = $own[ $field ] ?? '';
				if ( '' !== $set && 0 !== strcasecmp( $set, $value ) ) {
					continue 2;
				}
			}
			$variation = wc_get_product( $id );
			return $variation instanceof WC_Product ? $variation : null;
		}
		return null;
	}

	/**
	 * Variations, read one at a time: the first that serves ends the search.
	 *
	 * @param int[] $ids Variation ids.
	 * @phpstan-param list<int> $ids
	 * @return \Generator<int, WC_Product>
	 */
	private static function loaded( array $ids ): \Generator {
		foreach ( $ids as $id ) {
			$variation = wc_get_product( $id );
			if ( $variation instanceof WC_Product ) {
				yield $variation;
			}
		}
	}

	/**
	 * How many card rows the shop's published products have: at least as
	 * many as their card pages.
	 */
	public static function count(): int {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- counted for a sitemap, which WordPress or the SEO plugin caches.
		return (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->postmeta} card INNER JOIN {$wpdb->posts} product ON product.ID = card.post_id WHERE card.meta_key = %s AND card.meta_value <> %s AND product.post_type = 'product' AND product.post_status = 'publish' AND product.post_password = ''", SplitCards::META, SplitCards::NO_VALUE ) );
	}

	/**
	 * The card pages of the shop's published products, in the order of
	 * their card rows: each card the shop lists that has a page of its own.
	 * A page of rows may hold fewer pages than rows: a row of a card the
	 * product no longer has, or of one the shop leaves out, has none.
	 *
	 * @param int $limit  The most card rows read.
	 * @param int $offset The rows before them.
	 * @return list<array{loc: string, lastmod: string}> Each page's address, and when its product last changed (W3C), empty when unknown.
	 */
	public static function listed( int $limit, int $offset = 0 ): array {
		global $wpdb;
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- paged by card row for a sitemap, which WordPress or the SEO plugin caches.
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT card.post_id AS product_id, card.meta_value AS card_key FROM {$wpdb->postmeta} card INNER JOIN {$wpdb->posts} product ON product.ID = card.post_id WHERE card.meta_key = %s AND card.meta_value <> %s AND product.post_type = 'product' AND product.post_status = 'publish' AND product.post_password = '' ORDER BY card.meta_id LIMIT %d OFFSET %d",
				SplitCards::META,
				SplitCards::NO_VALUE,
				max( 0, $limit ),
				max( 0, $offset )
			)
		);
		// phpcs:enable
		$cards = array();
		foreach ( is_array( $rows ) ? $rows : array() as $row ) {
			$cards[] = array( (int) $row->product_id, (string) $row->card_key );
		}
		$pages    = array();
		$not_live = array();
		// Every product of the page in one read, not one each.
		_prime_post_caches( array_values( array_unique( array_column( $cards, 0 ) ) ) );
		foreach ( $cards as list( $product_id, $key ) ) {
			$product = wc_get_product( $product_id );
			if ( ! $product instanceof WC_Product || ! SplitCards::has_card( $product, $key ) ) {
				continue;
			}
			$not_live[ $product_id ] ??= SplitCards::not_live_cards( $product );
			if ( in_array( $key, $not_live[ $product_id ], true ) ) {
				continue;
			}
			$modified = $product->get_date_modified();
			$pages[]  = array(
				'loc'     => self::url( $product, $key, $product->get_permalink() ),
				'lastmod' => null === $modified ? '' : gmdate( DATE_W3C, $modified->getTimestamp() ),
			);
		}
		return $pages;
	}
}
