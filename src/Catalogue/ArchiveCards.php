<?php
/**
 * The cards a product adds to an archive's index.
 *
 * @package Pixonaut\WooCommerce
 */

declare(strict_types=1);

namespace Pixonaut\WooCommerce\Catalogue;

use WC_Product;
use WC_Product_Variable;

/**
 * A product is one card of an archive, or, split (`SplitCards`), one per
 * card the shop lists it as, leaving out those whose every variation is on
 * a template that is not live. Each card carries what the shop's filters
 * read of it (`FilterOffers`): the variations it sells that the shop
 * offers, each with the values it is sold in, its displayed price, and the
 * pictures of it a filtered card shows (`CardPages::combination()`, by
 * attachment id until `ArchiveIndexBuild` lists them per archive); and,
 * of a split product, the card's own values, the attributes the product's
 * cards hold, and those of them it lists by only while filtered; and the
 * attributes the product's page lets a shopper choose, which a filtered
 * card's link and picture follow.
 *
 * A card's variations come in the order a filtered listing prefers to open
 * the card on (`FilteredOpening`): those agreeing with most of the
 * product's defaults first, then the cheapest, then the product's order.
 *
 * Each product's cards are worked out once a build, whichever archives
 * list it.
 */
final class ArchiveCards {

	/**
	 * The size a card's picture is listed in, as the shop's cards print it:
	 * WooCommerce's Product Image block, which block themes' loops use,
	 * prints `large` whatever its image sizing (that sets only the aspect
	 * ratio), so the picture is shown whole rather than cropped square.
	 */
	public const IMAGE_SIZE = 'large';

	/**
	 * How many words of the product's short description a card's summary takes.
	 */
	private const SUMMARY_WORDS = 30;

	/**
	 * Between the product's id and its card's values in a card's id.
	 */
	private const ID_SEPARATOR = '-';

	/**
	 * Each product's cards, by product id.
	 *
	 * @var array<int, list<array<string, mixed>>>
	 */
	private array $cards = array();

	/**
	 * The pictures of filtered combinations read so far, by attachment id.
	 *
	 * @var array<int, array{src: string, srcset: string, width: int, height: int}>
	 */
	private array $pictures = array();

	/**
	 * @param ArchiveTerms $terms How attributes and values are named.
	 */
	public function __construct( private readonly ArchiveTerms $terms ) {
	}

	/**
	 * A product's cards, in the order the shop lists them.
	 *
	 * @param WC_Product $product A product an archive lists.
	 * @return list<array<string, mixed>>
	 */
	public function of( WC_Product $product ): array {
		$this->cards[ $product->get_id() ] ??= $this->read( $product );
		return $this->cards[ $product->get_id() ];
	}

	/**
	 * Works out `of()`.
	 *
	 * @param WC_Product $product A product.
	 * @return list<array<string, mixed>>
	 */
	private function read( WC_Product $product ): array {
		// Each variation the shop sells, read once for the prices, pictures and cards below.
		$variations = array();
		foreach ( $product instanceof WC_Product_Variable ? ProductConfig::sold_variations( $product ) : array() as $id => $sold ) {
			$variations[ $id ] = $sold[0];
		}
		$rows      = $this->rows( $product, $variations );
		$sold      = $product instanceof WC_Product_Variable ? SplitCards::sold_by_card( $product ) : array();
		$permalink = (string) get_permalink( $product->get_id() );
		$summary   = $this->summary( $product );
		$choices   = $this->choices( $product );
		if ( array() === $sold ) {
			$card = array(
				'id'           => (string) $product->get_id(),
				'productId'    => $product->get_id(),
				'title'        => $product->get_name(),
				'url'          => $permalink,
				'summary'      => $summary,
				'image'        => self::image( (int) $product->get_image_id() ),
				'holds'        => (object) array(),
				'listedBy'     => array(),
				'onlyFiltered' => array(),
				'choices'      => $choices,
				'variations'   => array_values( $this->ordered( $product, $rows, array_keys( $rows ), array(), null ) ),
			);
			return array( $this->filtered( $card, $product ) );
		}
		$listed_by = array();
		foreach ( array_keys( $sold ) as $key ) {
			$listed_by = array_merge( $listed_by, array_keys( $this->terms->by_key( SplitCards::term_ids( (string) $key ) ) ) );
		}
		$listed_by = array_values( array_unique( $listed_by ) );
		$only      = array_map( array( $this->terms, 'key' ), SplitCards::filtered_taxonomies( $product ) );
		$not_live  = SplitCards::not_live_cards( $product );
		$cards     = array();
		foreach ( $sold as $key => $variation_ids ) {
			$key = (string) $key;
			if ( in_array( $key, $not_live, true ) ) {
				continue;
			}
			$holds   = $this->terms->by_key( SplitCards::card_terms( $product, $key ) );
			$own     = SplitCardLooks::of( $product->get_id(), $key );
			$image   = CardPages::image( $product, $key );
			$ids     = array_values( array_intersect( array_keys( $rows ), $variation_ids ) );
			$cards[] = $this->filtered(
				array(
					'id'           => $product->get_id() . ( array() === $holds ? '' : self::ID_SEPARATOR . str_replace( SplitCards::KEY_SEPARATOR, self::ID_SEPARATOR, $key ) ),
					'productId'    => $product->get_id(),
					'title'        => '' !== $own['title'] ? $own['title'] : $product->get_name(),
					'url'          => CardPages::url( $product, $key, $permalink ),
					'summary'      => $summary,
					'image'        => self::image( $image > 0 ? $image : (int) $product->get_image_id() ),
					'holds'        => (object) $holds,
					'listedBy'     => $listed_by,
					'onlyFiltered' => array_values( array_intersect( array_keys( $holds ), $only ) ),
					'choices'      => $choices,
					'variations'   => array_values( $this->ordered( $product, $rows, $ids, SplitCards::link_values( $product, $key ), $this->fixed( $product, $key, $own['image'], $variations ) ? $image : 0 ) ),
				),
				$product
			);
		}
		return $cards;
	}

	/**
	 * Whether a card shows its flat or its own picture whichever variation
	 * it opens on, unless the filters pick a value beyond it, as
	 * `CardPages::image()` decides.
	 *
	 * @param WC_Product             $product    A split product.
	 * @param string                 $key        One of its cards.
	 * @param int                    $own        The card's own image; 0 for none.
	 * @param array<int, WC_Product> $variations The product's variations the shop sells, by id.
	 */
	private function fixed( WC_Product $product, string $key, int $own, array $variations ): bool {
		if ( SplitCards::is_unfiltered( $product, $key ) ) {
			foreach ( SplitCards::card_variations( $product, $key ) as $id ) {
				$variation = $variations[ $id ] ?? null;
				if ( $variation instanceof WC_Product && ProductPictures::flat( $variation ) > 0 ) {
					return true;
				}
			}
		}
		return $own > 0 && wp_attachment_is_image( $own );
	}

	/**
	 * The keys of the global attributes a product's page lets a shopper
	 * choose: its variation attributes; none for any other product.
	 *
	 * @param WC_Product $product A product.
	 * @return list<string>
	 */
	private function choices( WC_Product $product ): array {
		$choices = array();
		foreach ( $product instanceof WC_Product_Variable ? $product->get_attributes() : array() as $attribute ) {
			if ( $attribute instanceof \WC_Product_Attribute && $attribute->is_taxonomy() && $attribute->get_variation() ) {
				$choices[] = $this->terms->key( $attribute->get_name() );
			}
		}
		return $choices;
	}

	/**
	 * A card as a theme takes it.
	 *
	 * @param array<string, mixed> $card    The card.
	 * @param WC_Product           $product Its product.
	 * @return array<string, mixed>
	 */
	private function filtered( array $card, WC_Product $product ): array {
		/**
		 * Filters a card of an archive's index, before the index is kept: a
		 * theme adds the fields its cards show, such as a second picture.
		 * Runs while the index is built in the background, never on a
		 * shopper's request; the fields the plugin writes keep their meaning.
		 *
		 * @since 1.18.0
		 *
		 * @param array<string, mixed> $card    The card: `id`, `productId`, `title`, `url`, `summary`, `image`, `holds`, `listedBy`, `onlyFiltered`, `choices`, `variations` (whose `image` and `openImage` are attachment ids here, listed in the index's `images` after).
		 * @param WC_Product           $product The card's product.
		 *
		 * @example
		 * add_filter( 'pixonaut_wc_archive_index_card', function ( array $card, WC_Product $product ): array {
		 *     $card['badge'] = $product->is_featured() ? 'featured' : '';
		 *     return $card;
		 * }, 10, 2 );
		 */
		return apply_filters( 'pixonaut_wc_archive_index_card', $card, $product );
	}

	/**
	 * Each offer of a product as a card's variation row, by offer id.
	 *
	 * @param WC_Product             $product    A product.
	 * @param array<int, WC_Product> $variations Its variations the shop sells, by id.
	 * @return array<int, array<string, mixed>>
	 */
	private function rows( WC_Product $product, array $variations ): array {
		$offers  = FilterOffers::of( $product );
		$regular = $product instanceof WC_Product_Variable ? self::regular_prices( array_column( $offers, 'id' ) ) : array();
		$rows    = array();
		$filters = has_filter( 'pixonaut_wc_archive_index_variation' );
		foreach ( $offers as $offer ) {
			$values = array();
			foreach ( $offer['values'] as $taxonomy => $term_ids ) {
				$slugs = array();
				foreach ( $term_ids as $term_id ) {
					$term = $this->terms->term( $term_id );
					if ( null !== $term ) {
						$slugs[] = $term[1];
					}
				}
				$values[ $this->terms->key( $taxonomy ) ] = $slugs;
			}
			$variation   = $variations[ $offer['id'] ] ?? null;
			$own_regular = $product instanceof WC_Product_Variable ? ( $regular[ $offer['id'] ] ?? null ) : self::number( $product->get_regular_price( 'edit' ) );
			// A variation's own tax class decides its displayed price.
			$taxed = $variation ?? $product;
			$row   = array(
				'values'       => (object) $values,
				'price'        => self::displayed( $taxed, $offer['min'] ),
				'regularPrice' => self::displayed( $taxed, max( $offer['min'], $own_regular ?? $offer['min'] ) ),
			);
			if ( $offer['max'] > $offer['min'] ) {
				$row['priceMax'] = self::displayed( $taxed, $offer['max'] );
			}
			$pictures = array( 0, 0 );
			if ( $variation instanceof WC_Product ) {
				$pictures = array( CardPages::combination( $variation ), (int) $variation->get_image_id( 'edit' ) );
				if ( $filters ) {
					/**
					 * Filters a variation's row on a card of an archive's index,
					 * before the index is kept: a theme adds the fields it shows
					 * with a filtered card, such as its lowest price of the last
					 * 30 days. Runs while the index is built in the background.
					 *
					 * @since 1.18.0
					 *
					 * @param array<string, mixed> $row       The row: `values`, `price`, `regularPrice` and `priceMax` when it has one; the pictures are added per card after.
					 * @param WC_Product           $variation The variation.
					 * @param WC_Product           $product   Its product.
					 *
					 * @example
					 * add_filter( 'pixonaut_wc_archive_index_variation', function ( array $row, WC_Product $variation, WC_Product $product ): array {
					 *     $row['sku'] = $variation->get_sku();
					 *     return $row;
					 * }, 10, 3 );
					 */
					$row = apply_filters( 'pixonaut_wc_archive_index_variation', $row, $variation, $product );
				}
			}
			$rows[ $offer['id'] ] = $row + array(
				'min'      => $offer['min'],
				'pictures' => $pictures,
			);
		}
		return $rows;
	}

	/**
	 * Some of a product's rows in the order a filtered listing prefers to
	 * open a card on them, without what only the ordering reads.
	 *
	 * @param WC_Product                       $product A product.
	 * @param array<int, array<string, mixed>> $rows    Its rows by offer id.
	 * @param int[]                            $ids     The card's offers, in the product's order of variations.
	 * @param array<string, string>            $card    The card's own values by `attribute_<name>` field.
	 * @param int|null                         $fixed   For a card of a split product, the picture it shows unless the filters pick a value beyond it (`CardPages::image()`) when it is its flat or own picture, else 0; null for a product listed once, whose picture filters never change.
	 * @phpstan-param list<int> $ids
	 * @return array<int, array<string, mixed>>
	 */
	private function ordered( WC_Product $product, array $rows, array $ids, array $card, ?int $fixed ): array {
		$ranks = array();
		if ( $product instanceof WC_Product_Variable ) {
			$defaults = array_filter( array_map( 'strval', $product->get_default_attributes( 'edit' ) ), static fn ( string $value ): bool => '' !== $value );
			$held     = SplitCards::variation_values( $product->get_id() );
			$fields   = ApplicableAttributes::fields( $product );
			$sold     = array_values( $held );
			foreach ( $ids as $place => $id ) {
				$own    = $held[ $id ] ?? array();
				$hidden = ApplicableAttributes::hidden( $fields, $sold, $own );
				$agree  = 0;
				foreach ( $defaults as $attribute => $value ) {
					$field = 'attribute_' . $attribute;
					$named = $own[ $field ] ?? '';
					if ( '' === $named && ! in_array( $field, $hidden, true ) ) {
						$named = $card[ $field ] ?? $value;
					}
					$agree += $named === $value ? 1 : 0;
				}
				$ranks[ $id ] = array( -$agree, (float) $rows[ $id ]['min'], $place );
			}
			uasort( $ranks, static fn ( array $first, array $second ): int => $first <=> $second );
			$ids = array_keys( $ranks );
		}
		$ordered = array();
		foreach ( $ids as $id ) {
			$row                       = $rows[ $id ];
			list( $combination, $own ) = $row['pictures'];
			unset( $row['min'], $row['pictures'] );
			if ( null !== $fixed && $combination > 0 ) {
				$row['image'] = $combination;
			}
			// A card showing neither its flat picture nor its own opens on the variation's own image.
			if ( 0 === $fixed && $own > 0 ) {
				$row['openImage'] = $own;
			}
			$ordered[ $id ] = $row;
		}
		return $ordered;
	}

	/**
	 * A price as the shop shows it, with or without tax as it is set to.
	 *
	 * @param WC_Product $product The product, whose tax class applies.
	 * @param float      $price   A price as stored.
	 */
	private static function displayed( WC_Product $product, float $price ): float {
		return round( (float) wc_get_price_to_display( $product, array( 'price' => $price ) ), wc_get_price_decimals() );
	}

	/**
	 * Each variation's regular price, read in one query.
	 *
	 * @param int[] $variation_ids Variations.
	 * @return array<int, float> By variation id; none for one without.
	 */
	private static function regular_prices( array $variation_ids ): array {
		if ( array() === $variation_ids ) {
			return array();
		}
		global $wpdb;
		$placeholders = implode( ', ', array_fill( 0, count( $variation_ids ), '%d' ) );
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- every variation's regular price in one read, while an index is built; a placeholder per id.
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT post_id AS id, meta_value AS price FROM {$wpdb->postmeta} WHERE meta_key = '_regular_price' AND post_id IN ( {$placeholders} )", ...array_map( 'intval', $variation_ids ) ) );
		// phpcs:enable
		$prices = array();
		foreach ( is_array( $rows ) ? $rows : array() as $row ) {
			$price = self::number( $row->price );
			if ( null !== $price ) {
				$prices[ (int) $row->id ] = $price;
			}
		}
		return $prices;
	}

	/**
	 * A stored price as a number; null for none.
	 *
	 * @param mixed $price A stored price.
	 */
	private static function number( mixed $price ): ?float {
		return is_numeric( $price ) ? (float) $price : null;
	}

	/**
	 * The start of a product's short description, as plain text.
	 *
	 * @param WC_Product $product A product.
	 */
	private function summary( WC_Product $product ): string {
		return html_entity_decode( wp_trim_words( wp_strip_all_tags( strip_shortcodes( $product->get_short_description( 'edit' ) ) ), self::SUMMARY_WORDS ), ENT_QUOTES, 'UTF-8' );
	}

	/**
	 * A picture of a filtered combination, as the index lists it; read once a build.
	 *
	 * @param int $attachment An image.
	 * @return array{src: string, srcset: string, width: int, height: int}
	 */
	public function picture( int $attachment ): array {
		if ( ! isset( $this->pictures[ $attachment ] ) ) {
			$image                         = self::image( $attachment );
			$this->pictures[ $attachment ] = array(
				'src'    => $image['src'],
				'srcset' => $image['srcset'],
				'width'  => $image['width'],
				'height' => $image['height'],
			);
		}
		return $this->pictures[ $attachment ];
	}

	/**
	 * A card's picture as the shop's cards print it; the store's placeholder
	 * for none, at the dimensions WooCommerce prints it with.
	 *
	 * @param int $attachment An image; 0 for none.
	 * @return array{src: string, srcset: string, width: int, height: int, alt: string}
	 */
	public static function image( int $attachment ): array {
		$source = $attachment > 0 ? wp_get_attachment_image_src( $attachment, self::IMAGE_SIZE ) : false;
		if ( is_array( $source ) ) {
			$srcset = wp_get_attachment_image_srcset( $attachment, self::IMAGE_SIZE );
			return array(
				'src'    => (string) $source[0],
				'srcset' => is_string( $srcset ) ? $srcset : '',
				'width'  => (int) $source[1],
				'height' => (int) $source[2],
				'alt'    => trim( wp_strip_all_tags( (string) get_post_meta( $attachment, '_wp_attachment_image_alt', true ) ) ),
			);
		}
		$size = wc_get_image_size( self::IMAGE_SIZE );
		return array(
			'src'    => (string) wc_placeholder_img_src( self::IMAGE_SIZE ),
			'srcset' => '',
			'width'  => (int) ( $size['width'] ?? 0 ),
			'height' => (int) ( $size['height'] ?? 0 ),
			'alt'    => '',
		);
	}
}
