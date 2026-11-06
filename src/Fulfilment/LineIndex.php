<?php
/**
 * Finds the orders holding Pixonaut lines in a state.
 *
 * @package Pixonaut\WooCommerce
 */

declare(strict_types=1);

namespace Pixonaut\WooCommerce\Fulfilment;

/**
 * Orders by the state their lines read as (`LineState::state()`), from the
 * stored line documents, so a page of them is full and a count is exact
 * without loading each line.
 *
 * A document is written as JSON with `v` as its first key and `state` as
 * its second (`LineState::start()` has written them so since the first
 * version, and later writes keep the key order), so a line's state is
 * matched by the document's prefix, `{"v":<v>,"state":"<state>",`, never
 * by a `"state"` key nested deeper (a ref may hold one). One reads as its
 * stored state when its version is one this plugin knows, that state is a
 * known one and the document ends in `}`, so a cut-off one does not; any
 * other document (a newer version's, one that is not JSON or cut off, one
 * with an unknown state) reads as `needs_attention`.
 */
final class LineIndex {

	/**
	 * Ids of the orders holding a tracked line in $state, newest first.
	 *
	 * @param string|null $state  One of LineState::STATES; null for any.
	 * @param int         $before Only orders with a lower id; 0 for the newest.
	 * @param int         $limit  Orders at most.
	 * @return list<int>
	 */
	public static function order_ids( ?string $state, int $before, int $limit ): array {
		global $wpdb;
		list( $where, $args ) = self::where( $state );
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber, PluginCheck.Security.DirectDB.UnescapedDBParameter -- a lookup no API offers; $where holds placeholders only, filled by prepare().
		$ids = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT DISTINCT items.order_id FROM {$wpdb->prefix}woocommerce_order_items items INNER JOIN {$wpdb->prefix}woocommerce_order_itemmeta meta ON meta.order_item_id = items.order_item_id WHERE {$where} AND ( %d = 0 OR items.order_id < %d ) ORDER BY items.order_id DESC LIMIT %d",
				array_merge( $args, array( $before, $before, $limit ) )
			)
		);
		// phpcs:enable
		return array_values( array_map( 'intval', $ids ) );
	}

	/**
	 * How many orders hold a tracked line in $state.
	 *
	 * @param string|null $state One of LineState::STATES; null for any.
	 */
	public static function count( ?string $state ): int {
		global $wpdb;
		list( $where, $args ) = self::where( $state );
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, PluginCheck.Security.DirectDB.UnescapedDBParameter -- as in order_ids().
		$count = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT( DISTINCT items.order_id ) FROM {$wpdb->prefix}woocommerce_order_items items INNER JOIN {$wpdb->prefix}woocommerce_order_itemmeta meta ON meta.order_item_id = items.order_item_id WHERE {$where}",
				$args
			)
		);
		// phpcs:enable
		return (int) $count;
	}

	/**
	 * The condition on a line's meta row that it reads as $state.
	 *
	 * @param string|null $state One of LineState::STATES; null for any.
	 * @return array{0: string, 1: list<string>} The condition with placeholders, and their values.
	 */
	private static function where( ?string $state ): array {
		global $wpdb;
		$where = 'meta.meta_key = %s';
		$args  = array( LineState::META_KEY );
		if ( null === $state ) {
			return array( $where, $args );
		}
		$reads    = static function ( string $stored ) use ( $wpdb ): array {
			$prefixes = array();
			for ( $version = 1; $version <= LineState::SCHEMA_VERSION; $version++ ) {
				$prefixes[] = $wpdb->esc_like( '{"v":' . $version . ',"state":"' . $stored . '",' ) . '%';
			}
			return $prefixes;
		};
		$states   = LineState::NEEDS_ATTENTION === $state
			? array_values( array_diff( LineState::STATES, array( LineState::NEEDS_ATTENTION ) ) )
			: array( $state );
		$prefixes = array_merge( ...array_map( $reads, $states ) );
		$whole    = '%' . $wpdb->esc_like( '}' );
		$matched  = '( meta.meta_value LIKE %s AND ( ' . implode( ' OR ', array_fill( 0, count( $prefixes ), 'meta.meta_value LIKE %s' ) ) . ' ) )';
		$matched  = LineState::NEEDS_ATTENTION === $state ? "NOT {$matched}" : $matched;
		return array( "{$where} AND {$matched}", array_merge( $args, array( $whole ), $prefixes ) );
	}
}
