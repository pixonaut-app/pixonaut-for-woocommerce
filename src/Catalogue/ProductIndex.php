<?php
/**
 * Which products use which templates and blanks.
 *
 * @package Pixonaut\WooCommerce
 */

declare(strict_types=1);

namespace Pixonaut\WooCommerce\Catalogue;

use Pixonaut\WooCommerce\Schema;
use WC_Product;

/**
 * Meta beside each stored configuration naming its templates and blank, so a
 * change in Pixonaut finds the products it affects without reading every
 * configuration. `ProductConfig` writes it with the configuration; the
 * configuration stays the source, and `write()` rebuilds the index from it.
 */
final class ProductIndex {

	public const TEMPLATE_META = Schema::PRODUCT_TEMPLATE_INDEX_META;

	public const BLANK_META = Schema::PRODUCT_BLANK_INDEX_META;

	/**
	 * Sets a product's or variation's index to what its configuration uses.
	 * The caller saves the product's meta.
	 *
	 * @param WC_Product         $product A product or variation.
	 * @param ProductConfig|null $config  Its stored configuration; null when it has none.
	 */
	public static function write( WC_Product $product, ?ProductConfig $config ): void {
		$product->delete_meta_data( self::TEMPLATE_META );
		$product->delete_meta_data( self::BLANK_META );
		foreach ( self::entries( $config ) as list( $key, $value ) ) {
			$product->add_meta_data( $key, $value );
		}
	}

	/**
	 * Sets the index of a product or variation in the database at once, for
	 * a configuration written without its product object.
	 *
	 * @param int                $product_id A product or variation.
	 * @param ProductConfig|null $config     Its stored configuration; null when it has none.
	 */
	public static function rewrite( int $product_id, ?ProductConfig $config ): void {
		delete_post_meta( $product_id, self::TEMPLATE_META );
		delete_post_meta( $product_id, self::BLANK_META );
		foreach ( self::entries( $config ) as list( $key, $value ) ) {
			add_post_meta( $product_id, $key, wp_slash( $value ) );
		}
	}

	/**
	 * The index rows a configuration has: one per template it uses, and one
	 * for its blank.
	 *
	 * @param ProductConfig|null $config A stored configuration; null for none.
	 * @return list<array{0: string, 1: string}> Meta key and value pairs.
	 */
	public static function entries( ?ProductConfig $config ): array {
		if ( null === $config ) {
			return array();
		}
		$entries = array();
		foreach ( $config->sides as $side ) {
			$entry = array( self::TEMPLATE_META, $side->template_base_id );
			if ( '' !== $side->template_base_id && ! in_array( $entry, $entries, true ) ) {
				$entries[] = $entry;
			}
		}
		if ( null !== $config->blank_id ) {
			$entries[] = array( self::BLANK_META, $config->blank_id );
		}
		return $entries;
	}

	/**
	 * Products and variations using a template, in id order.
	 *
	 * @param string $base_id The template's base id.
	 * @param int    $after   Only ids above this one.
	 * @param int    $limit   At most this many.
	 * @return list<int>
	 */
	public static function using_template( string $base_id, int $after, int $limit ): array {
		return self::ids( self::TEMPLATE_META, $base_id, $after, $limit );
	}

	/**
	 * Published products and enabled variations using a template, in id
	 * order: what the store sells it on now, leaving out the trashed,
	 * drafts and disabled variations a configuration outlives.
	 *
	 * @param string $base_id The template's base id.
	 * @param int    $limit   At most this many.
	 * @param int    $offset  Skipping this many.
	 * @return list<int>
	 */
	public static function published_using_template( string $base_id, int $limit, int $offset = 0 ): array {
		global $wpdb;
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- a meta value joined to its post's status, which no meta query limits by.
		$ids = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT DISTINCT meta.post_id FROM {$wpdb->postmeta} meta INNER JOIN {$wpdb->posts} post ON post.ID = meta.post_id WHERE meta.meta_key = %s AND meta.meta_value = %s AND post.post_status = %s ORDER BY meta.post_id LIMIT %d OFFSET %d",
				self::TEMPLATE_META,
				$base_id,
				ProductConfig::PUBLIC_STATUS,
				$limit,
				$offset
			)
		);
		// phpcs:enable
		return array_values( array_map( 'intval', $ids ) );
	}

	/**
	 * A page of the products of some types and statuses linked to a
	 * template, on their own configuration or a variation's, in id order:
	 * a variation counts as its product.
	 *
	 * @param string|null $base_id    The template's base id; null for any template.
	 * @param int         $product_id Only this product; 0 for any.
	 * @param string[]    $types      Product types, e.g. `simple`.
	 * @param string[]    $statuses   Post statuses, e.g. `publish`.
	 * @param int         $limit      At most this many.
	 * @param int         $offset     Skipping this many.
	 * @phpstan-param list<string> $types
	 * @phpstan-param list<string> $statuses
	 * @return array{ids: list<int>, total: int} The page, and how many products there are in all.
	 */
	public static function linked_products( ?string $base_id, int $product_id, array $types, array $statuses, int $limit, int $offset ): array {
		global $wpdb;
		$types_in    = implode( ', ', array_fill( 0, count( $types ), '%s' ) );
		$statuses_in = implode( ', ', array_fill( 0, count( $statuses ), '%s' ) );
		// Joined on the product a row's post is, or belongs to as a variation.
		$from = "FROM {$wpdb->postmeta} meta
			INNER JOIN {$wpdb->posts} post ON post.ID = meta.post_id
			INNER JOIN {$wpdb->posts} product ON product.ID = IF( post.post_type = 'product_variation', post.post_parent, post.ID )
			INNER JOIN {$wpdb->term_relationships} rel ON rel.object_id = product.ID
			INNER JOIN {$wpdb->term_taxonomy} tax ON tax.term_taxonomy_id = rel.term_taxonomy_id AND tax.taxonomy = 'product_type'
			INNER JOIN {$wpdb->terms} term ON term.term_id = tax.term_id
			WHERE meta.meta_key = %s AND ( %s = '' OR meta.meta_value = %s ) AND ( %d = 0 OR product.ID = %d )
			AND post.post_status <> 'trash' AND product.post_type = 'product'
			AND term.slug IN ( {$types_in} ) AND product.post_status IN ( {$statuses_in} )";
		$args = array_merge( array( self::TEMPLATE_META, (string) $base_id, (string) $base_id, $product_id, $product_id ), $types, $statuses );
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- a variation's meta read as its product's, which no meta query does; $from holds only placeholders and table names.
		$total = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT( DISTINCT product.ID ) {$from}", $args ) );
		$ids   = $wpdb->get_col( $wpdb->prepare( "SELECT DISTINCT product.ID {$from} ORDER BY product.ID LIMIT %d OFFSET %d", array_merge( $args, array( $limit, $offset ) ) ) );
		// phpcs:enable
		return array(
			'ids'   => array_values( array_map( 'intval', $ids ) ),
			'total' => $total,
		);
	}

	/**
	 * Products and variations using a blank, in id order.
	 *
	 * @param string $blank_id The blank.
	 * @param int    $after    Only ids above this one.
	 * @param int    $limit    At most this many.
	 * @return list<int>
	 */
	public static function using_blank( string $blank_id, int $after, int $limit ): array {
		return self::ids( self::BLANK_META, $blank_id, $after, $limit );
	}

	/**
	 * Products and variations with a configuration stored, in id order.
	 *
	 * @param int $after Only ids above this one.
	 * @param int $limit At most this many.
	 * @return list<int>
	 */
	public static function configured( int $after, int $limit ): array {
		return self::ids( ProductConfig::META_KEY, null, $after, $limit );
	}

	/**
	 * @param string      $meta_key The meta key.
	 * @param string|null $value    Its value; null for any.
	 * @param int         $after    Only ids above this one.
	 * @param int         $limit    At most this many.
	 * @return list<int>
	 */
	private static function ids( string $meta_key, ?string $value, int $after, int $limit ): array {
		global $wpdb;
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- paged by id, which no meta query can do; read by background jobs only.
		$ids = null === $value
			? $wpdb->get_col( $wpdb->prepare( "SELECT DISTINCT post_id FROM {$wpdb->postmeta} WHERE meta_key = %s AND post_id > %d ORDER BY post_id LIMIT %d", $meta_key, $after, $limit ) )
			: $wpdb->get_col( $wpdb->prepare( "SELECT DISTINCT post_id FROM {$wpdb->postmeta} WHERE meta_key = %s AND meta_value = %s AND post_id > %d ORDER BY post_id LIMIT %d", $meta_key, $value, $after, $limit ) );
		// phpcs:enable
		return array_values( array_map( 'intval', $ids ) );
	}
}
