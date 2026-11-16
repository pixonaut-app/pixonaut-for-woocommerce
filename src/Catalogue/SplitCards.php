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
use WP_Term;

/**
 * A product listed by one of its attributes (`SplitListing`) is shown once
 * per value of it that a variation sells; one listed by several, once per
 * combination of their values that a variation sells. An attribute a
 * variation leaves unset counts for each of its values, unless it does not
 * apply to that variation (`ApplicableAttributes`, the variation's own
 * values as the choice): a poster sold bare, or with a hanger in a colour,
 * listed by both has a card for the bare one and one per hanger colour.
 * An attribute listed only while filtered adds its values to the cards a
 * listing shows only while a filter chooses one of them (`cards()`).
 *
 * A card is named by its key: its values' term ids, lowest first, joined by
 * commas, so a card of one value is that term's id, and a combination keeps
 * its key when the product's attributes change places; `NO_VALUE` for the
 * card of the variations none of the attributes applies to, which shows the
 * product as itself. A product whose combinations would come to more than
 * `MAX_CARDS` cards is listed once. The keys are kept as meta
 * rows on the product, one per card in the product's order of values, so
 * the shop's listings can join them and count, page and sort the cards in
 * SQL. They are written again whenever what they come from changes: the
 * product or a variation saved, trashed, restored or deleted, the
 * product's choice or the attribute's default changed.
 *
 * A card opens the product with its values chosen, and with each other
 * attribute's value that the card's variations all share.
 */
final class SplitCards {

	public const META = Schema::PRODUCT_SPLIT_CARD_META;

	/**
	 * The product's rows naming the values its cards hold of the
	 * attributes it lists a card per value of only while filtered
	 * (`filtered_rows()`).
	 */
	public const FILTERED_META = Schema::PRODUCT_SPLIT_FILTERED_META;

	/**
	 * Between the taxonomy and the term id of a `FILTERED_META` row.
	 */
	public const FILTERED_SEPARATOR = ':';

	/**
	 * Between the term ids of a card's key. MySQL's `FIND_IN_SET()` reads
	 * a key as the set of its ids.
	 */
	public const KEY_SEPARATOR = ',';

	/**
	 * The key of the card of the variations no listed attribute applies to.
	 */
	public const NO_VALUE = '0';

	/**
	 * The most cards a product is listed as. Each value of an attribute a
	 * variation leaves unset multiplies its combinations, so a product with
	 * a few such attributes would otherwise fill the shop, and the memory
	 * its cards are worked out in.
	 */
	public const MAX_CARDS = 250;

	/**
	 * The most attributes a product lists only while filtered: the cards
	 * of each set of them are written, so each one doubles the sets. A
	 * product with more is listed once.
	 */
	public const MAX_FILTERED_ATTRIBUTES = 4;

	/**
	 * In place of a value's place among its attribute's values, for an
	 * attribute that does not apply: before the first value.
	 */
	private const NOT_APPLYING = -1;

	/**
	 * What a card's key looks like.
	 */
	private const KEY_PATTERN = '/^(0|[1-9]\d*(,[1-9]\d*)*)$/';

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
	 * Variations' attributes by product id and variation id, read once a request.
	 *
	 * @var array<int, array<int, array<string, string>>>
	 */
	private static array $variations = array();

	/**
	 * Products' offers, by product id, read once a request.
	 *
	 * @var array<int, list<array{id: int, attributes: array<string, string>, offered: bool, live: bool}>>
	 */
	private static array $offers = array();

	/**
	 * Products' cards, by product id: each card's variations by its key, in
	 * the product's order of values; worked out once a request.
	 *
	 * @var array<int, array<string, list<int>>>
	 */
	private static array $cards = array();

	/**
	 * Products' cards' values, by product id and card key: term ids in the
	 * product's order of attributes; worked out with the cards.
	 *
	 * @var array<int, array<string, list<int>>>
	 */
	private static array $card_terms = array();

	/**
	 * Cards' link values, by product id and card key, read once a request.
	 *
	 * @var array<int, array<string, array<string, string>>>
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
	 * Forgets a deleted value's card, and writes again the cards of the
	 * products that list a combination holding it.
	 *
	 * @param mixed $term_id The deleted term.
	 */
	public static function term_deleted( mixed $term_id ): void {
		global $wpdb;
		$term_id = (int) $term_id;
		delete_metadata( 'post', 0, self::META, (string) $term_id, true );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- the products whose card keys hold the id, which no meta query finds; read when a term is deleted.
		$ids = $wpdb->get_col( $wpdb->prepare( "SELECT DISTINCT post_id FROM {$wpdb->postmeta} WHERE meta_key = %s AND FIND_IN_SET( %d, meta_value )", self::META, $term_id ) );
		foreach ( $ids as $product_id ) {
			self::write( (int) $product_id );
		}
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
	 * Sets a product's card rows to the cards it is listed by now: none
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
			$product  = $product_id > 0 ? wc_get_product( $product_id ) : null;
			$keys     = $product instanceof WC_Product ? self::keys( $product ) : array();
			$filtered = $product instanceof WC_Product ? self::filtered_rows( $product ) : array();
			if ( array_map( 'strval', get_post_meta( $product_id, self::FILTERED_META, false ) ) !== $filtered ) {
				delete_post_meta( $product_id, self::FILTERED_META );
				foreach ( $filtered as $taxonomy ) {
					add_post_meta( $product_id, self::FILTERED_META, $taxonomy );
				}
			}
			if ( array_map( 'strval', get_post_meta( $product_id, self::META, false ) ) === $keys ) {
				return;
			}
			delete_post_meta( $product_id, self::META );
			foreach ( $keys as $key ) {
				add_post_meta( $product_id, self::META, $key );
			}
		} finally {
			$wpdb->query( $wpdb->prepare( 'SELECT RELEASE_LOCK( %s )', $lock ) );
		}
		// phpcs:enable
	}

	/**
	 * Whether a product has card rows: it is listed as cards now, as its
	 * rows were last written.
	 *
	 * @param int $product_id A product.
	 */
	public static function has_rows( int $product_id ): bool {
		return array() !== get_post_meta( $product_id, self::META, false );
	}

	/**
	 * Forgets what was read of a product's variations this request.
	 *
	 * @param int $product_id A product.
	 */
	private static function forget( int $product_id ): void {
		unset( self::$variations[ $product_id ], self::$offers[ $product_id ], self::$cards[ $product_id ], self::$card_terms[ $product_id ], self::$links[ $product_id ] );
		FilterOffers::forget( $product_id );
		FilterIndex::forget( $product_id );
	}

	/**
	 * What was worked out of a product's variations and cards this
	 * request, for `FilterIndex` to keep: its variations' values, its cards
	 * with their values, and its offers.
	 *
	 * @param int $product_id A product.
	 * @return array{values?: array<int, array<string, string>>, cards?: array<string, list<int>>, card_terms?: array<string, list<int>>, offers?: list<array{id: int, attributes: array<string, string>, offered: bool, live: bool}>}
	 */
	public static function worked_out( int $product_id ): array {
		$parts = array();
		if ( isset( self::$variations[ $product_id ] ) ) {
			$parts['values'] = self::$variations[ $product_id ];
		}
		if ( isset( self::$cards[ $product_id ], self::$card_terms[ $product_id ] ) ) {
			$parts['cards']      = self::$cards[ $product_id ];
			$parts['card_terms'] = self::$card_terms[ $product_id ];
		}
		if ( isset( self::$offers[ $product_id ] ) ) {
			$parts['offers'] = self::$offers[ $product_id ];
		}
		return $parts;
	}

	/**
	 * Takes what `worked_out()` answered in an earlier request as this
	 * request's, for the parts not worked out yet.
	 *
	 * @param int                  $product_id A product.
	 * @param array<string, mixed> $parts      Parts `worked_out()` answered, as `FilterIndex` kept them.
	 */
	public static function take( int $product_id, array $parts ): void {
		if ( is_array( $parts['values'] ?? null ) ) {
			self::$variations[ $product_id ] ??= $parts['values'];
		}
		if ( is_array( $parts['cards'] ?? null ) && is_array( $parts['card_terms'] ?? null ) && ! isset( self::$cards[ $product_id ] ) ) {
			self::$cards[ $product_id ]      = $parts['cards'];
			self::$card_terms[ $product_id ] = $parts['card_terms'];
		}
		if ( is_array( $parts['offers'] ?? null ) ) {
			// @phpstan-ignore assign.propertyType (kept as `worked_out()` answered it, for this product's unchanged variations)
			self::$offers[ $product_id ] ??= $parts['offers'];
		}
	}

	/**
	 * A card's key: its term ids, lowest first, joined; `NO_VALUE` for none.
	 *
	 * @param int[] $term_ids Its values, in any order.
	 */
	public static function key( array $term_ids ): string {
		$term_ids = array_map( 'intval', $term_ids );
		sort( $term_ids );
		return array() === $term_ids ? self::NO_VALUE : implode( self::KEY_SEPARATOR, $term_ids );
	}

	/**
	 * The values of a card, from its key.
	 *
	 * @param string $key A card's key.
	 * @return list<int> Term ids, lowest first; empty for `NO_VALUE`.
	 */
	public static function term_ids( string $key ): array {
		return self::NO_VALUE === $key || ! self::is_key( $key ) ? array() : array_map( 'intval', explode( self::KEY_SEPARATOR, $key ) );
	}

	/**
	 * What a card is called beside its fields: its values' names, in the
	 * order given; a value whose term is gone is left out.
	 *
	 * @param int[] $term_ids The card's values.
	 * @phpstan-param list<int> $term_ids
	 */
	public static function name( array $term_ids ): string {
		$names = array();
		foreach ( $term_ids as $term_id ) {
			$term = get_term( $term_id );
			if ( $term instanceof WP_Term ) {
				$names[] = $term->name;
			}
		}
		return implode( ', ', $names );
	}

	/**
	 * Whether a stored or posted value is a card's key.
	 *
	 * @param mixed $key A value.
	 */
	public static function is_key( mixed $key ): bool {
		return ( is_string( $key ) || is_int( $key ) ) && 1 === preg_match( self::KEY_PATTERN, (string) $key );
	}

	/**
	 * The keys of the cards a product is listed by, in its order of
	 * values: one per combination of its listed attributes' values that an
	 * enabled variation sells. Empty when it is listed once.
	 *
	 * @param WC_Product $product A product.
	 * @return list<string>
	 */
	public static function keys( WC_Product $product ): array {
		return array_map( 'strval', array_keys( self::cards( $product ) ) );
	}

	/**
	 * Whether a key names one of the product's cards now. A stored row may
	 * not, until the product's cards are written again.
	 *
	 * @param WC_Product $product A product.
	 * @param string     $key     A card's key.
	 */
	public static function has_card( WC_Product $product, string $key ): bool {
		return isset( self::cards( $product )[ $key ] );
	}

	/**
	 * The values of one of a product's cards, in the product's order of
	 * attributes; empty for a card it does not have, and for `NO_VALUE`.
	 *
	 * @param WC_Product $product A product.
	 * @param string     $key     The card's key.
	 * @return list<int> Term ids.
	 */
	public static function card_terms( WC_Product $product, string $key ): array {
		self::cards( $product );
		return self::$card_terms[ $product->get_id() ][ $key ] ?? array();
	}

	/**
	 * The enabled variations each of a product's cards sells, by the card's
	 * key, in the product's order of values; empty when it is listed once.
	 *
	 * @param WC_Product $product A product.
	 * @return array<string, list<int>> Variation ids.
	 */
	public static function sold_by_card( WC_Product $product ): array {
		$sold = array();
		foreach ( self::cards( $product ) as $key => $variation_ids ) {
			$sold[ (string) $key ] = $variation_ids;
		}
		return $sold;
	}

	/**
	 * A product's cards: the enabled variations each sells, by its key.
	 * First the cards of the attributes it always lists a card per value
	 * of, then, for each set of those it lists one per value of only while
	 * filtered, fewest first, the cards with their values too: a listing
	 * shows those holding values of exactly the filtered attributes a
	 * filter is on (`filtered_taxonomies()`). Without an attribute always
	 * listed, the first is `NO_VALUE`, the product as itself. A card two
	 * sets share is kept once, selling the variations of both. None for a
	 * product whose cards, of all sets together, would be more than
	 * `MAX_CARDS`, or with more than `MAX_FILTERED_ATTRIBUTES` listed only
	 * while filtered.
	 *
	 * @param WC_Product $product A product.
	 * @return array<string, list<int>> Variation ids by card key.
	 */
	private static function cards( WC_Product $product ): array {
		$product_id = $product->get_id();
		if ( isset( self::$cards[ $product_id ] ) ) {
			return self::$cards[ $product_id ];
		}
		$listed   = SplitListing::modes_of( $product );
		$always   = array();
		$sets     = array( array() );
		$filtered = 0;
		foreach ( $listed as list( $attribute, $mode ) ) {
			if ( SplitListing::FILTERED !== $mode ) {
				$always[] = $attribute;
				continue;
			}
			if ( ++$filtered > self::MAX_FILTERED_ATTRIBUTES ) {
				// Too many sets of them to list: the product is listed once.
				$listed = array();
				break;
			}
			foreach ( $sets as $set ) {
				$sets[] = array_merge( $set, array( $attribute ) );
			}
		}
		// Fewest filtered attributes first, so a product's cards start with those an unfiltered listing shows.
		usort( $sets, static fn ( array $first, array $second ): int => count( $first ) <=> count( $second ) );
		$cards = array();
		$held  = array();
		foreach ( array() === $listed ? array() : $sets as $set ) {
			$level = self::level( $product, self::in_product_order( $listed, array_merge( $always, $set ) ) );
			if ( null === $level ) {
				$cards = array();
				$held  = array();
				break;
			}
			foreach ( $level['cards'] as $key => $variation_ids ) {
				$key            = (string) $key;
				$cards[ $key ]  = array_values( array_unique( array_merge( $cards[ $key ] ?? array(), $variation_ids ) ) );
				$held[ $key ] ??= $level['terms'][ $key ];
			}
			if ( count( $cards ) > self::MAX_CARDS ) {
				$cards = array();
				$held  = array();
				break;
			}
		}
		self::$card_terms[ $product_id ] = $held;
		// PHP turns a key of digits into an integer.
		self::$cards[ $product_id ] = $cards;
		return $cards;
	}

	/**
	 * The listed attributes of a set, in the product's order of attributes.
	 *
	 * @param array<int, array{0: WC_Product_Attribute, 1: string}> $listed The product's listed attributes (`SplitListing::modes_of()`).
	 * @param WC_Product_Attribute[]                                $set    Some of them.
	 * @phpstan-param list<WC_Product_Attribute> $set
	 * @return list<WC_Product_Attribute>
	 */
	private static function in_product_order( array $listed, array $set ): array {
		return array_values( array_filter( array_column( $listed, 0 ), static fn ( WC_Product_Attribute $attribute ): bool => in_array( $attribute, $set, true ) ) );
	}

	/**
	 * The cards of one set of listed attributes: one per combination of
	 * their values that an enabled variation sells, in the product's order
	 * of values, each with its values in the product's order of
	 * attributes. With no attribute, `NO_VALUE` selling every variation.
	 * Null when they would be more than `MAX_CARDS`.
	 *
	 * @param WC_Product             $product    A product.
	 * @param WC_Product_Attribute[] $attributes The set, in the product's order of attributes.
	 * @phpstan-param list<WC_Product_Attribute> $attributes
	 * @return array{cards: array<int|string, list<int>>, terms: array<int|string, list<int>>}|null Keys of digits are integers.
	 */
	private static function level( WC_Product $product, array $attributes ): ?array {
		$variations = self::variation_values( $product->get_id() );
		if ( array() === $attributes ) {
			return array(
				'cards' => array( self::NO_VALUE => array_keys( $variations ) ),
				'terms' => array( self::NO_VALUE => array() ),
			);
		}
		$terms = array();
		foreach ( $attributes as $attribute ) {
			$terms[ self::field( $attribute ) ] = self::terms( $attribute );
		}
		$cards  = array();
		$order  = array();
		$held   = array();
		$fields = ApplicableAttributes::fields( $product );
		$sold   = array_values( $variations );
		foreach ( $variations as $variation_id => $values ) {
			$hidden       = ApplicableAttributes::hidden( $fields, $sold, $values );
			$combinations = self::combinations( $terms, $values, $hidden );
			if ( null === $combinations ) {
				return null;
			}
			foreach ( $combinations as $positions ) {
				$term_ids = array();
				foreach ( $positions as $field => $position ) {
					$term_id = array_values( $terms[ $field ] )[ $position ] ?? null;
					if ( null !== $term_id ) {
						$term_ids[] = $term_id;
					}
				}
				$key             = self::key( $term_ids );
				$cards[ $key ][] = $variation_id;
				$order[ $key ]   = array_values( $positions );
				$held[ $key ]    = $term_ids;
			}
			if ( count( $cards ) > self::MAX_CARDS ) {
				return null;
			}
		}
		// In the order of each attribute's values, a card without a value of one first.
		uksort( $cards, static fn ( int|string $first, int|string $second ): int => $order[ $first ] <=> $order[ $second ] );
		return array(
			'cards' => $cards,
			'terms' => array_intersect_key( $held, $cards ),
		);
	}

	/**
	 * The attributes a product lists a card per value of only while a
	 * filter chooses one of their values; none when it is listed once.
	 *
	 * @param WC_Product $product A product.
	 * @return list<string> Taxonomies, in the product's order of attributes.
	 */
	public static function filtered_taxonomies( WC_Product $product ): array {
		if ( array() === self::cards( $product ) ) {
			return array();
		}
		$taxonomies = array();
		foreach ( SplitListing::modes_of( $product ) as list( $attribute, $mode ) ) {
			if ( SplitListing::FILTERED === $mode ) {
				$taxonomies[] = $attribute->get_name();
			}
		}
		return $taxonomies;
	}

	/**
	 * The `FILTERED_META` rows of a product: each value of an attribute it
	 * lists only while filtered that one of its cards holds, as
	 * `<taxonomy>:<term id>`, in the order of its cards.
	 *
	 * @param WC_Product $product A product.
	 * @return list<string>
	 */
	private static function filtered_rows( WC_Product $product ): array {
		$filtered = self::filtered_taxonomies( $product );
		$rows     = array();
		foreach ( array() === $filtered ? array() : self::keys( $product ) as $key ) {
			foreach ( self::card_terms( $product, $key ) as $term_id ) {
				$term = get_term( $term_id );
				if ( $term instanceof WP_Term && in_array( $term->taxonomy, $filtered, true ) ) {
					$rows[] = $term->taxonomy . self::FILTERED_SEPARATOR . $term_id;
				}
			}
		}
		return array_values( array_unique( $rows ) );
	}

	/**
	 * Whether a card is one a listing shows while no filter chooses a value
	 * of the attributes the product lists by only while filtered: it holds
	 * a value of none of them.
	 *
	 * @param WC_Product $product A product.
	 * @param string     $key     One of its cards' keys.
	 */
	public static function is_unfiltered( WC_Product $product, string $key ): bool {
		$filtered = self::filtered_taxonomies( $product );
		if ( array() === $filtered ) {
			return true;
		}
		foreach ( self::card_terms( $product, $key ) as $term_id ) {
			$term = get_term( $term_id );
			if ( $term instanceof WP_Term && in_array( $term->taxonomy, $filtered, true ) ) {
				return false;
			}
		}
		return true;
	}

	/**
	 * The combinations of the listed attributes' values a variation sells,
	 * each as the place of its value among every attribute's values:
	 * `NOT_APPLYING` for an attribute that does not apply to the variation,
	 * and one combination per value of an attribute it sells any value of.
	 * None when it holds a value its attribute does not list; null when
	 * they would be more than `MAX_CARDS`.
	 *
	 * @param array<string, array<string, int>> $terms  Each listed attribute's term ids by slug, in its order of values, by field.
	 * @param array<string, string>             $values The variation's values by field.
	 * @param string[]                          $hidden The fields that do not apply to the variation.
	 * @return list<array<string, int>>|null Places by field.
	 */
	private static function combinations( array $terms, array $values, array $hidden ): ?array {
		$combinations = array( array() );
		foreach ( $terms as $field => $ids ) {
			$value = $values[ $field ] ?? '';
			$slugs = array_map( 'strval', array_keys( $ids ) );
			if ( '' !== $value ) {
				$place  = array_search( $value, $slugs, true );
				$places = false === $place ? array() : array( $place );
			} elseif ( in_array( $field, $hidden, true ) ) {
				$places = array( self::NOT_APPLYING );
			} else {
				$places = array_keys( $slugs );
			}
			if ( count( $combinations ) * count( $places ) > self::MAX_CARDS ) {
				return null;
			}
			$next = array();
			foreach ( $combinations as $combination ) {
				foreach ( $places as $place ) {
					$next[] = $combination + array( $field => $place );
				}
			}
			$combinations = $next;
		}
		return $combinations;
	}

	/**
	 * A listed attribute's term ids by slug, in its order of values on the product.
	 *
	 * @param WC_Product_Attribute $attribute A global attribute of a product.
	 * @return array<string, int>
	 */
	private static function terms( WC_Product_Attribute $attribute ): array {
		$terms = array();
		foreach ( $attribute->get_options() as $term_id ) {
			$term = get_term( (int) $term_id, $attribute->get_name() );
			if ( $term instanceof \WP_Term ) {
				$terms[ $term->slug ] = $term->term_id;
			}
		}
		return $terms;
	}

	/**
	 * The query parameters a card's link chooses its values with: each
	 * listed attribute's, and each other variation attribute's that every
	 * variation offered in the card on a live template shares. Empty when
	 * the product has no such card.
	 *
	 * @param WC_Product $product A product.
	 * @param string     $key     The card's key.
	 * @return array<string, string> Values by `attribute_<name>` field.
	 */
	public static function link_values( WC_Product $product, string $key ): array {
		self::$links[ $product->get_id() ][ $key ] ??= self::read_link_values( $product, $key );
		return self::$links[ $product->get_id() ][ $key ];
	}

	/**
	 * Works out `link_values()`.
	 *
	 * @param WC_Product $product A product.
	 * @param string     $key     The card's key.
	 * @return array<string, string> Values by `attribute_<name>` field.
	 */
	private static function read_link_values( WC_Product $product, string $key ): array {
		if ( ! self::has_card( $product, $key ) ) {
			return array();
		}
		$chosen = array();
		$held   = self::term_ids( $key );
		foreach ( SplitListing::attributes_of( $product ) as $attribute ) {
			$slug = array_search( current( array_intersect( $held, self::terms( $attribute ) ) ), self::terms( $attribute ), true );
			if ( false !== $slug ) {
				$chosen[ self::field( $attribute ) ] = (string) $slug;
			}
		}
		$matching = array_map( static fn ( array $offer ): array => $offer['attributes'], self::card_offers( $product, $key ) );
		foreach ( $product->get_attributes() as $other ) {
			if ( ! $other instanceof WC_Product_Attribute || ! $other->get_variation() || isset( $chosen[ self::field( $other ) ] ) ) {
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
	 * The variations a card sells: those WooCommerce offers in it on a
	 * live template, in the product's order of variations.
	 *
	 * @param WC_Product $product A product listed by an attribute.
	 * @param string     $key     The card's key.
	 * @return list<int> Variation ids.
	 */
	public static function card_variations( WC_Product $product, string $key ): array {
		return array_map( static fn ( array $offer ): int => $offer['id'], self::card_offers( $product, $key ) );
	}

	/**
	 * The variations a product sells: those WooCommerce offers on a live
	 * template, in the product's order of variations.
	 *
	 * @param WC_Product $product A variable product.
	 * @return list<int> Variation ids.
	 */
	public static function live_variations( WC_Product $product ): array {
		return array_values(
			array_map(
				static fn ( array $offer ): int => $offer['id'],
				array_filter( self::offers( $product ), static fn ( array $offer ): bool => $offer['offered'] && $offer['live'] )
			)
		);
	}

	/**
	 * The offers of `card_variations()`.
	 *
	 * @param WC_Product $product A product listed by an attribute.
	 * @param string     $key     The card's key.
	 * @return list<array{id: int, attributes: array<string, string>, offered: bool, live: bool}>
	 */
	private static function card_offers( WC_Product $product, string $key ): array {
		$sold = self::cards( $product )[ $key ] ?? array();
		return array_values(
			array_filter(
				self::offers( $product ),
				static fn ( array $offer ): bool => $offer['offered'] && $offer['live'] && in_array( $offer['id'], $sold, true )
			)
		);
	}

	/**
	 * The cards of a product that the shop leaves out: those whose every
	 * variation WooCommerce offers is on a template that is not live, as
	 * `NotLiveProducts` leaves out a whole product. A card with no
	 * variation offered at all is kept, as a product would be.
	 *
	 * @param WC_Product $product A product listed by an attribute.
	 * @return list<string> Card keys.
	 */
	public static function not_live_cards( WC_Product $product ): array {
		$offers = self::offers( $product );
		$hidden = array();
		foreach ( self::cards( $product ) as $key => $sold ) {
			$shown = array_filter( $offers, static fn ( array $offer ): bool => $offer['offered'] && in_array( $offer['id'], $sold, true ) );
			if ( array() !== $shown && array() === array_filter( $shown, static fn ( array $offer ): bool => $offer['live'] ) ) {
				$hidden[] = (string) $key;
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
				// As `get_available_variations()` decides: one out of stock is left out while the store hides those.
				'offered'    => $variation->variation_is_visible() && ( $variation->is_in_stock() || 'yes' !== get_option( 'woocommerce_hide_out_of_stock_items' ) ),
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
	 * it leaves unset; read once a request.
	 *
	 * @param int $product_id A variable product.
	 * @return array<int, array<string, string>> Values by `attribute_<name>` field, by variation id, in the product's order of variations.
	 */
	public static function variation_values( int $product_id ): array {
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
		self::$variations[ $product_id ] = $by_variation;
		return $by_variation;
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
