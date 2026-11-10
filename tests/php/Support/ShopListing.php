<?php
/**
 * The products a shopper's listings show.
 *
 * @package Pixonaut\WooCommerce
 */

declare(strict_types=1);

namespace Pixonaut\WooCommerce\Tests\Support;

use WP_Post;
use WP_Query;

/**
 * Runs the queries the shop's archive and the site's search make, as a
 * visitor's request runs them.
 */
final class ShopListing {

	/**
	 * The products the shop lists.
	 *
	 * @return list<int>
	 */
	public static function shop(): array {
		return self::ids(
			array(
				'post_type'      => 'product',
				'posts_per_page' => -1,
			)
		);
	}

	/**
	 * What a site search finds.
	 *
	 * @param string $terms What is searched for.
	 * @return list<int>
	 */
	public static function search( string $terms ): array {
		return self::ids(
			array(
				's'              => $terms,
				'posts_per_page' => -1,
			)
		);
	}

	/**
	 * The ids a query finds.
	 *
	 * @param array<string, mixed> $args The query's arguments.
	 * @return list<int>
	 */
	public static function ids( array $args ): array {
		$query = new WP_Query( array( 'fields' => 'ids' ) + $args );
		$ids   = array();
		foreach ( (array) $query->posts as $post ) {
			$ids[] = $post instanceof WP_Post ? $post->ID : (int) $post;
		}
		return $ids;
	}
}
