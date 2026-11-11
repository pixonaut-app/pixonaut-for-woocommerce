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
use WP_Post;

/**
 * A product listed by one of its attributes (`SplitListing`) is shown once
 * per value of it that a variation sells. The values are kept as meta rows
 * on the product, one per value in the product's order, so the shop's
 * listings can join them and count, page and sort the cards in SQL. They
 * are written again whenever what they come from changes: the product or a
 * variation saved, trashed, restored or deleted, the product's choice or
 * the attribute's default changed.
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
	 * Action Scheduler hook writing the next batch of every variable product.
	 */
	public const ALL_HOOK = 'pixonaut_wc_split_cards_all';

	/**
	 * Option holding whether every variable product's cards wait to be written.
	 */
	public const BACKFILL_OPTION = Schema::SPLIT_CARDS_BACKFILL_OPTION;

	/**
	 * BACKFILL_OPTION's value while the job writing every product's cards waits to be queued.
	 */
	public const BACKFILL_PENDING = '1';

	/**
	 * Prefix of the MySQL lock one product's cards are written under.
	 */
	private const LOCK_PREFIX = 'pixonaut_split_card_';

	/**
	 * How long a write waits for another one of the same product's cards.
	 */
	private const LOCK_WAIT_SECONDS = 5;

	/**
	 * What MySQL's `GET_LOCK()` answers once the lock is taken.
	 */
	private const LOCK_TAKEN = '1';

	/**
	 * Hook priority after WooCommerce's own handler of a restored product,
	 * which restores its variations.
	 */
	private const AFTER_WOOCOMMERCE_RESTORE = 20;

	/**
	 * Variations' attributes by product id, read once a request.
	 *
	 * @var array<int, list<array<string, string>>>
	 */
	private static array $variations = array();

	/**
	 * Products' offers, by product id, read once a request.
	 *
	 * @var array<int, list<array{id: int, attributes: array<string, string>, offered: bool, live: bool}>>
	 */
	private static array $offers = array();

	/**
	 * Cards' link values, by product id and term id, read once a request.
	 *
	 * @var array<int, array<int, array<string, string>>>
	 */
	private static array $links = array();

	public static function register(): void {
		add_action( 'woocommerce_after_product_object_save', array( self::class, 'product_saved' ) );
		add_action( 'trashed_post', array( self::class, 'post_changed' ) );
		add_action( 'untrashed_post', array( self::class, 'post_changed' ), self::AFTER_WOOCOMMERCE_RESTORE );
		add_action( 'after_delete_post', array( self::class, 'post_deleted' ), 10, 2 );
		add_action( 'added_post_meta', array( self::class, 'config_written' ), 10, 3 );
		add_action( 'updated_post_meta', array( self::class, 'config_written' ), 10, 3 );
		add_action( 'deleted_post_meta', array( self::class, 'config_written' ), 10, 3 );
		add_action( 'delete_term', array( self::class, 'term_deleted' ) );
		add_action( self::ATTRIBUTE_HOOK, array( self::class, 'write_attribute' ), 10, 2 );
		add_action( self::ALL_HOOK, array( self::class, 'write_all' ) );
		add_action( 'action_scheduler_init', array( self::class, 'queue_backfill' ) );
	}

	/**
	 * Queues the job writing every variable product's cards that an upgrade
	 * asked for, and only then forgets the ask: one that could not be
	 * queued is tried again on the next request.
	 */
	public static function queue_backfill(): void {
		if ( self::BACKFILL_PENDING !== get_option( self::BACKFILL_OPTION ) || ! function_exists( 'as_enqueue_async_action' ) ) {
			return;
		}
		if ( 0 !== as_enqueue_async_action( self::ALL_HOOK, array( 0 ), Schema::ACTION_GROUP ) ) {
			delete_option( self::BACKFILL_OPTION );
		}
	}

	/**
	 * Writes the cards of a batch of variable products, and queues the
	 * next batch while there may be more.
	 *
	 * @param mixed $after Only products above this id.
	 */
	public static function write_all( mixed $after = 0 ): void {
		$ids = self::variable_products( (int) $after );
		foreach ( $ids as $id ) {
			self::write( $id );
		}
		if ( count( $ids ) === self::BATCH && function_exists( 'as_enqueue_async_action' ) ) {
			as_enqueue_async_action( self::ALL_HOOK, array( end( $ids ) ), Schema::ACTION_GROUP );
		}
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
	 * Writes the cards of a trashed or restored product, or of a trashed or
	 * restored variation's product. WordPress fires these however the post
	 * was trashed or restored, WooCommerce's own hooks only for some ways.
	 *
	 * @param mixed $post_id The post.
	 */
	public static function post_changed( mixed $post_id ): void {
		$post = get_post( (int) $post_id );
		if ( $post instanceof WP_Post ) {
			self::post_deleted( $post->ID, $post );
		}
	}

	/**
	 * Writes the cards of a product, or of a variation's product, once it
	 * was deleted: the post as it was still names the variation's parent.
	 *
	 * @param mixed $post_id The post; unused.
	 * @param mixed $post    The post as it was.
	 */
	public static function post_deleted( mixed $post_id, mixed $post ): void {
		unset( $post_id );
		if ( ! $post instanceof WP_Post ) {
			return;
		}
		if ( 'product' === $post->post_type ) {
			self::write( $post->ID );
		} elseif ( 'product_variation' === $post->post_type && $post->post_parent > 0 ) {
			self::write( $post->post_parent );
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
		if ( ProductConfig::META_KEY !== $meta_key ) {
			return;
		}
		$post_type = get_post_type( (int) $post_id );
		if ( 'product' === $post_type ) {
			self::write( (int) $post_id );
		} elseif ( 'product_variation' === $post_type ) {
			// Its template decides whether its cards are shown, read once a request.
			self::forget( (int) wp_get_post_parent_id( (int) $post_id ) );
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
		global $wpdb;
		// Two requests rebuilding the rows at once would each add them, so a
		// write waits for one already running, and reads the product only
		// once it holds the lock. One still running after the wait is taken
		// to be stuck: this write is skipped, and the product's next change
		// writes its cards.
		$lock = self::LOCK_PREFIX . md5( $wpdb->prefix . $product_id );
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- a lock, never cached.
		if ( self::LOCK_TAKEN !== $wpdb->get_var( $wpdb->prepare( 'SELECT GET_LOCK( %s, %d )', $lock, self::LOCK_WAIT_SECONDS ) ) ) {
			return;
		}
		try {
			self::forget( $product_id );
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
		} finally {
			$wpdb->query( $wpdb->prepare( 'SELECT RELEASE_LOCK( %s )', $lock ) );
		}
		// phpcs:enable
	}

	/**
	 * Forgets what was read of a product's variations this request.
	 *
	 * @param int $product_id A product.
	 */
	private static function forget( int $product_id ): void {
		unset( self::$variations[ $product_id ], self::$offers[ $product_id ], self::$links[ $product_id ] );
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
		self::$links[ $product->get_id() ][ $term_id ] ??= self::read_link_values( $product, $term_id );
		return self::$links[ $product->get_id() ][ $term_id ];
	}

	/**
	 * Works out `link_values()`.
	 *
	 * @param WC_Product $product A product.
	 * @param int        $term_id The card's value.
	 * @return array<string, string> Values by `attribute_<name>` field.
	 */
	private static function read_link_values( WC_Product $product, int $term_id ): array {
		$attribute = SplitListing::attribute_of( $product );
		$term      = null === $attribute ? null : get_term( $term_id, $attribute->get_name() );
		if ( null === $attribute || ! $term instanceof \WP_Term ) {
			return array();
		}
		$matching = array_map( static fn ( array $offer ): array => $offer['attributes'], self::card_offers( $product, $term_id ) );
		$chosen   = array( self::field( $attribute ) => $term->slug );
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
	 * The variations a card sells: those WooCommerce offers with its value
	 * on a live template, in the product's order of variations.
	 *
	 * @param WC_Product $product A product listed by an attribute.
	 * @param int        $term_id The card's value.
	 * @return list<int> Variation ids.
	 */
	public static function card_variations( WC_Product $product, int $term_id ): array {
		return array_map( static fn ( array $offer ): int => $offer['id'], self::card_offers( $product, $term_id ) );
	}

	/**
	 * The offers of `card_variations()`.
	 *
	 * @param WC_Product $product A product listed by an attribute.
	 * @param int        $term_id The card's value.
	 * @return list<array{id: int, attributes: array<string, string>, offered: bool, live: bool}>
	 */
	private static function card_offers( WC_Product $product, int $term_id ): array {
		$attribute = SplitListing::attribute_of( $product );
		$term      = null === $attribute ? null : get_term( $term_id, $attribute->get_name() );
		if ( null === $attribute || ! $term instanceof \WP_Term ) {
			return array();
		}
		$key = self::field( $attribute );
		return array_values(
			array_filter(
				self::offers( $product ),
				static fn ( array $offer ): bool => $offer['offered'] && $offer['live'] && in_array( $offer['attributes'][ $key ] ?? '', array( '', $term->slug ), true )
			)
		);
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
	 * A product's enabled variations, each with its id, its attribute values,
	 * whether WooCommerce offers it (it has a price, and is not hidden out
	 * of stock) and whether it is on a live template.
	 *
	 * @param WC_Product $product A variable product.
	 * @return list<array{id: int, attributes: array<string, string>, offered: bool, live: bool}>
	 */
	private static function offers( WC_Product $product ): array {
		if ( isset( self::$offers[ $product->get_id() ] ) ) {
			return self::$offers[ $product->get_id() ];
		}
		$offers = array();
		foreach ( ProductConfig::sold_variations( $product ) as list( $variation, $config ) ) {
			if ( ! $variation instanceof WC_Product_Variation ) {
				continue;
			}
			$offers[] = array(
				'id'         => $variation->get_id(),
				'attributes' => array_map( 'strval', $variation->get_variation_attributes() ),
				'offered'    => $variation->variation_is_visible(),
				'live'       => ! NotLiveProducts::not_live( $config ),
			);
		}
		self::$offers[ $product->get_id() ] = $offers;
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
	 * Variable products, in id order.
	 *
	 * @param int $after Only products above this id.
	 * @return list<int>
	 */
	private static function variable_products( int $after ): array {
		global $wpdb;
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- paged by id, which no product query can do; read once after an upgrade.
		$ids = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT product.ID FROM {$wpdb->posts} product INNER JOIN {$wpdb->term_relationships} rel ON rel.object_id = product.ID INNER JOIN {$wpdb->term_taxonomy} tax ON tax.term_taxonomy_id = rel.term_taxonomy_id INNER JOIN {$wpdb->terms} type ON type.term_id = tax.term_id WHERE tax.taxonomy = 'product_type' AND type.slug = 'variable' AND product.post_type = 'product' AND product.ID > %d ORDER BY product.ID LIMIT %d",
				$after,
				self::BATCH
			)
		);
		// phpcs:enable
		return array_values( array_map( 'intval', $ids ) );
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
