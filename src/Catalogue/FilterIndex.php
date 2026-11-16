<?php
/**
 * What the shop's filters and card links read of a product's variations, kept between requests.
 *
 * @package Pixonaut\WooCommerce
 */

declare(strict_types=1);

namespace Pixonaut\WooCommerce\Catalogue;

use Pixonaut\WooCommerce\Plugin;
use Pixonaut\WooCommerce\Schema;
use WP_Term;

/**
 * A filtered listing works out, for every variable product it may list,
 * which of its variations each filter matches (`FilterOffers`) and which
 * of its cards sell them (`SplitCards`), and a card's link reads every
 * variation of its product; each reads all of the product's variations.
 * Those reads are kept here, one transient per product, and taken as the
 * next request's while nothing they come from has changed.
 *
 * A kept read carries a stamp of what it was read from: the product's and
 * its variations' last change (WooCommerce saves each change to a product
 * or variation, its prices and stock too, with the time), how many
 * variations it has, its stored configuration, the plugin's version, the
 * attributes listed as cards by default, whether the store hides what is
 * out of stock, and a generation raised whenever an attribute, one of its
 * values or their order changes. A read whose stamp no longer matches is
 * read again. One read within moments of the product's last change is not
 * kept, so two changes within the same second cannot leave it stale.
 *
 * Whether a variation is on a live template, and whether WooCommerce
 * offers it while something filters its price, stock or visibility, are
 * decided per request: kept offers are taken only for a product no stored
 * configuration names a template that is not live of
 * (`NotLiveProducts::candidate_ids()`), and only while nothing filters
 * those.
 */
final class FilterIndex {

	/**
	 * How long a kept read is kept at most: a day, in seconds. A change
	 * the stamp sees drops it sooner.
	 */
	private const LIFETIME = 86400;

	/**
	 * How many seconds after its product's last change a read is kept: a
	 * stamp is to the second, so a change in the same second would not
	 * change it.
	 */
	private const SETTLE_SECONDS = 2;

	/**
	 * The filters through which WooCommerce decides whether it offers a
	 * variation, as `SplitCards` reads it.
	 */
	private const OFFER_FILTERS = array(
		'woocommerce_variation_is_visible',
		'woocommerce_product_variation_get_price',
		'woocommerce_product_variation_get_stock_status',
		'woocommerce_product_is_in_stock',
	);

	/**
	 * The term meta writes after which the attribute's values may list in another order.
	 */
	private const TERM_META_WRITES = array( 'added_term_meta', 'updated_term_meta', 'deleted_term_meta' );

	/**
	 * The arguments of a term meta write action that are read: the meta row and the term.
	 */
	private const TERM_META_ACTION_ARGS = 2;

	/**
	 * Arguments of `edited_term` and `delete_term`.
	 */
	private const TERM_ACTION_ARGS = 3;

	/**
	 * The products whose kept reads were looked up this request, by site
	 * and product id: the stamp read before anything else of them, whether
	 * it has settled, and what was kept.
	 *
	 * @var array<string, array{id: int, stamp: string, settled: bool, kept: array<string, mixed>}>
	 */
	private static array $looked_up = array();

	public static function register(): void {
		add_action( 'edited_term', array( self::class, 'term_changed' ), 10, self::TERM_ACTION_ARGS );
		add_action( 'delete_term', array( self::class, 'term_changed' ), 10, self::TERM_ACTION_ARGS );
		foreach ( self::TERM_META_WRITES as $action ) {
			add_action( $action, array( self::class, 'term_meta_written' ), 10, self::TERM_META_ACTION_ARGS );
		}
		add_action( 'woocommerce_attribute_updated', array( self::class, 'drop_all' ) );
		add_action( 'woocommerce_attribute_deleted', array( self::class, 'drop_all' ) );
		add_action( 'shutdown', array( self::class, 'keep' ) );
	}

	/**
	 * Takes the kept reads of some products as this request's, and notes
	 * what to keep of the others once the request is done.
	 *
	 * @param int[] $product_ids Products a page may read.
	 */
	public static function look_up( array $product_ids ): void {
		$ids = array();
		foreach ( $product_ids as $id ) {
			if ( $id > 0 && ! isset( self::$looked_up[ self::slot( $id ) ] ) ) {
				$ids[ $id ] = $id;
			}
		}
		if ( array() === $ids ) {
			return;
		}
		$stamps = self::stamps( array_values( $ids ) );
		$names  = array();
		foreach ( array_keys( $stamps ) as $id ) {
			$names[] = '_transient_' . self::transient( $id );
			$names[] = '_transient_timeout_' . self::transient( $id );
		}
		// Two options a product, read in one query rather than two each.
		wp_prime_option_caches( $names );
		$offers_taken = self::offers_taken();
		foreach ( $stamps as $id => $stamp ) {
			$parts = self::kept( $id, $stamp['stamp'] );
			$taken = $parts;
			unset( $taken['offers'] );
			if ( is_array( $parts['offers'] ?? null ) && $offers_taken && ! in_array( $id, NotLiveProducts::candidate_ids(), true ) ) {
				// No stored configuration of the product names a template that is not live.
				$taken['offers'] = array_map( static fn ( mixed $offer ): array => array( 'live' => true ) + ( is_array( $offer ) ? $offer : array() ), $parts['offers'] );
			}
			SplitCards::take( $id, $taken );
			FilterOffers::take( $id, $parts['filter_offers'] ?? null );
			self::$looked_up[ self::slot( $id ) ] = array(
				'id'      => $id,
				'stamp'   => $stamp['stamp'],
				'settled' => $stamp['settled'],
				'kept'    => $parts,
			);
		}
	}

	/**
	 * What is kept of a product while its stamp matches; nothing otherwise.
	 *
	 * @param int    $product_id A product.
	 * @param string $stamp      Its stamp now.
	 * @return array<string, mixed>
	 */
	private static function kept( int $product_id, string $stamp ): array {
		$kept = get_transient( self::transient( $product_id ) );
		if ( ! is_array( $kept ) || ( $kept['stamp'] ?? null ) !== $stamp || ! is_array( $kept['parts'] ?? null ) ) {
			return array();
		}
		/** @var array<string, mixed> */
		return $kept['parts'];
	}

	/**
	 * Keeps what this request worked out of the products it looked up and
	 * whose kept reads were missing or partial.
	 */
	public static function keep(): void {
		$offers_kept = self::offers_taken();
		foreach ( self::$looked_up as $slot => $looked_up ) {
			if ( ! $looked_up['settled'] || ! str_starts_with( $slot, get_current_blog_id() . ':' ) ) {
				continue;
			}
			$id    = $looked_up['id'];
			$parts = SplitCards::worked_out( $id );
			unset( $parts['offers'] );
			$offers = SplitCards::worked_out( $id )['offers'] ?? null;
			if ( null !== $offers && $offers_kept && array() === array_filter( $offers, static fn ( array $offer ): bool => ! $offer['live'] ) ) {
				// Each one live: whether it is live is read per request again.
				$parts['offers'] = array_map(
					static function ( array $offer ): array {
						unset( $offer['live'] );
						return $offer;
					},
					$offers
				);
			} elseif ( isset( $looked_up['kept']['offers'] ) ) {
				$parts['offers'] = $looked_up['kept']['offers'];
			}
			$filter_offers = FilterOffers::worked_out( $id );
			if ( null !== $filter_offers ) {
				$parts['filter_offers'] = $filter_offers;
			}
			$parts += $looked_up['kept'];
			if ( $parts != $looked_up['kept'] ) { // phpcs:ignore Universal.Operators.StrictComparisons.LooseNotEqual -- the same parts, whatever order they were added in.
				set_transient(
					self::transient( $id ),
					array(
						'stamp' => $looked_up['stamp'],
						'parts' => $parts,
					),
					self::LIFETIME
				);
			}
		}
		self::$looked_up = array();
	}

	/**
	 * Forgets a product's kept reads once it, a variation of it or its
	 * configuration is written: what this request read of it before is
	 * not kept either.
	 *
	 * @param int $product_id A product.
	 */
	public static function forget( int $product_id ): void {
		unset( self::$looked_up[ self::slot( $product_id ) ] );
		delete_transient( self::transient( $product_id ) );
	}

	/**
	 * Drops every kept read once a value of an attribute is renamed or
	 * deleted.
	 *
	 * @param mixed $term_id  The term; unused.
	 * @param mixed $tt_id    Its term taxonomy id; unused.
	 * @param mixed $taxonomy Its taxonomy.
	 */
	public static function term_changed( mixed $term_id, mixed $tt_id, mixed $taxonomy ): void {
		unset( $term_id, $tt_id );
		if ( is_string( $taxonomy ) && taxonomy_is_product_attribute( $taxonomy ) ) {
			self::drop_all();
		}
	}

	/**
	 * Drops every kept read once the meta of an attribute's value, which
	 * holds its place among the attribute's values, is written.
	 *
	 * @param mixed $meta_id The meta row; unused.
	 * @param mixed $term_id The term.
	 */
	public static function term_meta_written( mixed $meta_id, mixed $term_id ): void {
		unset( $meta_id );
		$term = is_numeric( $term_id ) ? get_term( (int) $term_id ) : null;
		if ( $term instanceof WP_Term && taxonomy_is_product_attribute( $term->taxonomy ) ) {
			self::drop_all();
		}
	}

	/**
	 * Drops every kept read, by raising the generation each one carries.
	 */
	public static function drop_all(): void {
		update_option( Schema::FILTER_INDEX_GENERATION_OPTION, (int) get_option( Schema::FILTER_INDEX_GENERATION_OPTION, 0 ) + 1, true );
	}

	/**
	 * Whether kept offers are taken and kept now: nothing filters whether
	 * WooCommerce offers a variation.
	 */
	private static function offers_taken(): bool {
		return '' === RequestCache::filters_key( self::OFFER_FILTERS );
	}

	/**
	 * Each product's stamp, and whether it has settled, read in one query.
	 * None for a product that does not exist.
	 *
	 * @param int[] $product_ids Products.
	 * @phpstan-param list<int> $product_ids
	 * @return array<int, array{stamp: string, settled: bool}> By product id.
	 */
	private static function stamps( array $product_ids ): array {
		global $wpdb;
		$placeholders = implode( ', ', array_fill( 0, count( $product_ids ), '%d' ) );
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- every product's last changes in one read, before reading anything else of them; a placeholder per id.
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT product.ID AS id, product.post_modified_gmt AS modified, MAX( variation.post_modified_gmt ) AS variation_modified, COUNT( variation.ID ) AS variations, SUM( variation.ID ) AS variation_ids, ( SELECT MD5( config.meta_value ) FROM {$wpdb->postmeta} config WHERE config.post_id = product.ID AND config.meta_key = %s ORDER BY config.meta_id LIMIT 1 ) AS config FROM {$wpdb->posts} product LEFT JOIN {$wpdb->posts} variation ON variation.post_parent = product.ID AND variation.post_type = 'product_variation' WHERE product.ID IN ( {$placeholders} ) GROUP BY product.ID",
				ProductConfig::META_KEY,
				...$product_ids
			)
		);
		// phpcs:enable
		$shared  = self::shared_stamp();
		$settled = time() - self::SETTLE_SECONDS;
		$stamps  = array();
		foreach ( is_array( $rows ) ? $rows : array() as $row ) {
			$last                     = max( (string) $row->modified, (string) $row->variation_modified );
			$changed                  = strtotime( $last . ' UTC' );
			$stamps[ (int) $row->id ] = array(
				'stamp'   => md5( $shared . '|' . $row->modified . '|' . $row->variation_modified . '|' . $row->variations . '|' . $row->variation_ids . '|' . $row->config ),
				'settled' => false === $changed || $changed < $settled,
			);
		}
		return $stamps;
	}

	/**
	 * The part of every product's stamp that is the store's.
	 */
	private static function shared_stamp(): string {
		return (string) wp_json_encode(
			array(
				Plugin::VERSION,
				get_option( Schema::FILTER_INDEX_GENERATION_OPTION, 0 ),
				get_option( Schema::SPLIT_ATTRIBUTES_OPTION ),
				get_option( Schema::SPLIT_FILTERED_ATTRIBUTES_OPTION ),
				get_option( 'woocommerce_hide_out_of_stock_items' ),
			)
		);
	}

	/**
	 * A product's place in `$looked_up`: another site of a network has its
	 * own products under the same ids.
	 *
	 * @param int $product_id A product.
	 */
	private static function slot( int $product_id ): string {
		return get_current_blog_id() . ':' . $product_id;
	}

	/**
	 * The transient a product's reads are kept in.
	 *
	 * @param int $product_id A product.
	 */
	private static function transient( int $product_id ): string {
		return Schema::FILTER_INDEX_PREFIX . $product_id;
	}
}
