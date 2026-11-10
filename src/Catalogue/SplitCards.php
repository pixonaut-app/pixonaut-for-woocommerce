<?php
/**
 * The cards a split product is listed as.
 *
 * @package Pixonaut\WooCommerce
 */

declare(strict_types=1);

namespace Pixonaut\WooCommerce\Catalogue;

use Pixonaut\WooCommerce\Schema;
use WC_Product;
use WC_Product_Attribute;
use WC_Product_Variation;

/**
 * A product listed by one of its attributes (`SplitListing`) is shown once
 * per value of it that a variation sells. The values are kept as meta rows
 * on the product, one per value in the product's order, so the shop's
 * listings can join them and count, page and sort the cards in SQL. They
 * are written again whenever what they come from changes: the product or a
 * variation saved, a variation deleted, the product's choice or the
 * attribute's default changed.
 *
 * A card opens the product with its value chosen, and with each other
 * attribute's value that the card's variations all share.
 */
final class SplitCards {

	public const META = Schema::PRODUCT_SPLIT_CARD_META;

	/**
	 * Products whose cards are written now when an attribute's default
	 * changes; the rest are written by a job, this many at a time.
	 */
	public const BATCH = 100;

	/**
	 * Action Scheduler hook writing the next batch of an attribute's products.
	 */
	public const ATTRIBUTE_HOOK = 'pixonaut_wc_split_cards_attribute';

	/**
	 * Variations' attributes by product id, read once a request.
	 *
	 * @var array<int, list<array<string, string>>>
	 */
	private static array $variations = array();

	/**
	 * Parents of the variations being deleted, by variation id: a deleted
	 * variation no longer names its product.
	 *
	 * @var array<int, int>
	 */
	private static array $deleting = array();

	public static function register(): void {
		add_action( 'woocommerce_after_product_object_save', array( self::class, 'product_saved' ) );
		add_action( 'woocommerce_trash_product_variation', array( self::class, 'variation_gone' ) );
		add_action( 'woocommerce_before_delete_product_variation', array( self::class, 'variation_deleting' ) );
		add_action( 'woocommerce_delete_product_variation', array( self::class, 'variation_gone' ) );
		add_action( 'added_post_meta', array( self::class, 'config_written' ), 10, 3 );
		add_action( 'updated_post_meta', array( self::class, 'config_written' ), 10, 3 );
		add_action( 'deleted_post_meta', array( self::class, 'config_written' ), 10, 3 );
		add_action( 'delete_term', array( self::class, 'term_deleted' ) );
		add_action( self::ATTRIBUTE_HOOK, array( self::class, 'write_attribute' ), 10, 2 );
	}

	/**
	 * Writes a saved product's cards, or its product's for a variation.
	 *
	 * @param mixed $product The saved product or variation.
	 */
	public static function product_saved( mixed $product ): void {
		if ( ! $product instanceof WC_Product ) {
			return;
		}
		self::write( $product->is_type( 'variation' ) ? $product->get_parent_id() : $product->get_id() );
	}

	/**
	 * Notes a variation's product before the variation is deleted.
	 *
	 * @param mixed $variation_id The variation.
	 */
	public static function variation_deleting( mixed $variation_id ): void {
		$variation_id = (int) $variation_id;
		$parent       = wp_get_post_parent_id( $variation_id );
		if ( is_int( $parent ) && $parent > 0 ) {
			self::$deleting[ $variation_id ] = $parent;
		}
	}

	/**
	 * Writes the cards of a trashed or deleted variation's product.
	 *
	 * @param mixed $variation_id The variation.
	 */
	public static function variation_gone( mixed $variation_id ): void {
		$variation_id = (int) $variation_id;
		$parent       = self::$deleting[ $variation_id ] ?? wp_get_post_parent_id( $variation_id );
		unset( self::$deleting[ $variation_id ] );
		if ( is_int( $parent ) && $parent > 0 ) {
			self::write( $parent );
		}
	}

	/**
	 * Writes a product's cards after its configuration, which holds its
	 * choice of attribute, was written without saving the product.
	 *
	 * @param mixed $meta_id  The meta row; unused.
	 * @param mixed $post_id  The post.
	 * @param mixed $meta_key The meta key.
	 */
	public static function config_written( mixed $meta_id, mixed $post_id, mixed $meta_key ): void {
		if ( ProductConfig::META_KEY === $meta_key && 'product' === get_post_type( (int) $post_id ) ) {
			self::write( (int) $post_id );
		}
	}

	/**
	 * Forgets a deleted value's cards.
	 *
	 * @param mixed $term_id The deleted term.
	 */
	public static function term_deleted( mixed $term_id ): void {
		delete_metadata( 'post', 0, self::META, (string) (int) $term_id, true );
	}

	/**
	 * Writes the cards of the variable products using an attribute, after
	 * its default changed: a batch now, the rest as jobs.
	 *
	 * @param int $attribute_id A global attribute's id.
	 * @param int $after        Only products above this id.
	 */
	public static function write_attribute( int $attribute_id, int $after = 0 ): void {
		$taxonomy = wc_attribute_taxonomy_name_by_id( $attribute_id );
		if ( '' === $taxonomy ) {
			return;
		}
		$ids = self::products_using( $taxonomy, $after );
		foreach ( $ids as $id ) {
			self::write( $id );
		}
		if ( count( $ids ) === self::BATCH && function_exists( 'as_enqueue_async_action' ) ) {
			as_enqueue_async_action( self::ATTRIBUTE_HOOK, array( $attribute_id, end( $ids ) ), Schema::ACTION_GROUP );
		}
	}

	/**
	 * Sets a product's card rows to the values it is listed by now: none
	 * when it is listed once. Nothing is written when they are unchanged.
	 *
	 * @param int $product_id A product.
	 */
	public static function write( int $product_id ): void {
		unset( self::$variations[ $product_id ] );
		$product = $product_id > 0 ? wc_get_product( $product_id ) : null;
		$values  = $product instanceof WC_Product ? self::values( $product ) : array();
		$stored  = array_map( 'intval', get_post_meta( $product_id, self::META, false ) );
		if ( $stored === $values ) {
			return;
		}
		delete_post_meta( $product_id, self::META );
		foreach ( $values as $term_id ) {
			add_post_meta( $product_id, self::META, (string) $term_id );
		}
	}

	/**
	 * The term ids a product is listed by, one card each, in its order of
	 * values: those of its split attribute that an enabled variation sells.
	 * Empty when it is listed once.
	 *
	 * @param WC_Product $product A product.
	 * @return list<int>
	 */
	public static function values( WC_Product $product ): array {
		$attribute = SplitListing::attribute_of( $product );
		if ( null === $attribute ) {
			return array();
		}
		$key  = self::field( $attribute );
		$sold = array();
		foreach ( self::variations( $product->get_id() ) as $variation ) {
			$sold[] = $variation[ $key ] ?? '';
		}
		$values = array();
		foreach ( $attribute->get_options() as $term_id ) {
			$term = get_term( (int) $term_id, $attribute->get_name() );
			if ( $term instanceof \WP_Term && ( in_array( $term->slug, $sold, true ) || in_array( '', $sold, true ) ) ) {
				$values[] = $term->term_id;
			}
		}
		return $values;
	}

	/**
	 * The query parameters a card's link chooses its value with: the split
	 * attribute's, and each other variation attribute's that every variation
	 * offered with the value on a live template shares. Empty when the product is not listed by an
	 * attribute holding the term.
	 *
	 * @param WC_Product $product A product.
	 * @param int        $term_id The card's value.
	 * @return array<string, string> Values by `attribute_<name>` field.
	 */
	public static function link_values( WC_Product $product, int $term_id ): array {
		$attribute = SplitListing::attribute_of( $product );
		$term      = null === $attribute ? null : get_term( $term_id, $attribute->get_name() );
		if ( null === $attribute || ! $term instanceof \WP_Term ) {
			return array();
		}
		$key      = self::field( $attribute );
		$matching = array_filter(
			array_map( static fn ( array $offer ): array => $offer['attributes'], array_filter( self::offers( $product ), static fn ( array $offer ): bool => $offer['offered'] && $offer['live'] ) ),
			static fn ( array $variation ): bool => in_array( $variation[ $key ] ?? '', array( '', $term->slug ), true )
		);
		$chosen   = array( $key => $term->slug );
		foreach ( $product->get_attributes() as $other ) {
			if ( ! $other instanceof WC_Product_Attribute || ! $other->get_variation() || $other->get_name() === $attribute->get_name() ) {
				continue;
			}
			$field  = self::field( $other );
			$shared = array_unique( array_map( static fn ( array $variation ): string => $variation[ $field ] ?? '', $matching ) );
			if ( 1 === count( $shared ) && '' !== current( $shared ) ) {
				$chosen[ $field ] = current( $shared );
			}
		}
		return $chosen;
	}

	/**
	 * The values among a product's cards that the shop leaves out: those
	 * whose every variation WooCommerce offers is on a template that is not
	 * live, as `NotLiveProducts` leaves out a whole product. A value with no
	 * variation offered at all keeps its card, as a product would.
	 *
	 * @param WC_Product $product A product listed by an attribute.
	 * @return list<int> Term ids.
	 */
	public static function not_live_values( WC_Product $product ): array {
		$attribute = SplitListing::attribute_of( $product );
		if ( null === $attribute ) {
			return array();
		}
		$key    = self::field( $attribute );
		$offers = self::offers( $product );
		$hidden = array();
		foreach ( self::values( $product ) as $term_id ) {
			$term = get_term( $term_id, $attribute->get_name() );
			if ( ! $term instanceof \WP_Term ) {
				continue;
			}
			$shown = array_filter( $offers, static fn ( array $offer ): bool => $offer['offered'] && in_array( $offer['attributes'][ $key ] ?? '', array( '', $term->slug ), true ) );
			if ( array() !== $shown && array() === array_filter( $shown, static fn ( array $offer ): bool => $offer['live'] ) ) {
				$hidden[] = $term_id;
			}
		}
		return $hidden;
	}

	/**
	 * A product's enabled variations, each with its attribute values,
	 * whether WooCommerce offers it (it has a price, and is not hidden out
	 * of stock) and whether it is on a live template.
	 *
	 * @param WC_Product $product A variable product.
	 * @return list<array{attributes: array<string, string>, offered: bool, live: bool}>
	 */
	private static function offers( WC_Product $product ): array {
		$offers = array();
		foreach ( ProductConfig::sold_variations( $product ) as list( $variation, $config ) ) {
			if ( ! $variation instanceof WC_Product_Variation ) {
				continue;
			}
			$offers[] = array(
				'attributes' => array_map( 'strval', $variation->get_variation_attributes() ),
				'offered'    => $variation->variation_is_visible(),
				'live'       => ! NotLiveProducts::not_live( $config ),
			);
		}
		return $offers;
	}

	/**
	 * The add-to-cart form's field for an attribute, which variations store
	 * their value under.
	 *
	 * @param WC_Product_Attribute $attribute A product's attribute.
	 */
	private static function field( WC_Product_Attribute $attribute ): string {
		return 'attribute_' . sanitize_title( $attribute->get_name() );
	}

	/**
	 * The attribute values of a product's enabled variations, `''` for one
	 * selling any value.
	 *
	 * @param int $product_id A variable product.
	 * @return list<array<string, string>> Values by `attribute_<name>` field, one entry per variation.
	 */
	private static function variations( int $product_id ): array {
		if ( isset( self::$variations[ $product_id ] ) ) {
			return self::$variations[ $product_id ];
		}
		global $wpdb;
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- every variation's attributes in one read, kept for the request.
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT variation.ID AS id, meta.meta_key AS field, meta.meta_value AS value FROM {$wpdb->posts} variation LEFT JOIN {$wpdb->postmeta} meta ON meta.post_id = variation.ID AND meta.meta_key LIKE %s WHERE variation.post_parent = %d AND variation.post_type = 'product_variation' AND variation.post_status = 'publish' ORDER BY variation.menu_order, variation.ID",
				$wpdb->esc_like( 'attribute_' ) . '%',
				$product_id
			)
		);
		// phpcs:enable
		$by_variation = array();
		foreach ( is_array( $rows ) ? $rows : array() as $row ) {
			$id                  = (int) $row->id;
			$by_variation[ $id ] = $by_variation[ $id ] ?? array();
			if ( is_string( $row->field ) ) {
				$by_variation[ $id ][ $row->field ] = (string) $row->value;
			}
		}
		self::$variations[ $product_id ] = array_values( $by_variation );
		return self::$variations[ $product_id ];
	}

	/**
	 * Variable products using a global attribute, in id order.
	 *
	 * @param string $taxonomy The attribute's taxonomy.
	 * @param int    $after    Only products above this id.
	 * @return list<int>
	 */
	private static function products_using( string $taxonomy, int $after ): array {
		global $wpdb;
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- paged by id, which no product query can do; read when an attribute's default changes.
		$ids = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT DISTINCT rel.object_id FROM {$wpdb->term_relationships} rel INNER JOIN {$wpdb->term_taxonomy} tax ON tax.term_taxonomy_id = rel.term_taxonomy_id INNER JOIN {$wpdb->posts} product ON product.ID = rel.object_id WHERE tax.taxonomy = %s AND product.post_type = 'product' AND rel.object_id > %d ORDER BY rel.object_id LIMIT %d",
				$taxonomy,
				$after,
				self::BATCH
			)
		);
		// phpcs:enable
		return array_values( array_map( 'intval', $ids ) );
	}
}
