<?php
/**
 * Indexes the templates and blanks of configurations stored before the index.
 *
 * @package Pixonaut\WooCommerce
 */

declare(strict_types=1);

namespace Pixonaut\WooCommerce\Migrations;

use Pixonaut\WooCommerce\Catalogue\ProductConfig;
use Pixonaut\WooCommerce\Catalogue\ProductIndex;

/**
 * Writes `ProductIndex`'s meta for every product and variation that has a
 * configuration, through the post meta API: migrations run before
 * WooCommerce's product classes may be loaded.
 */
final class IndexProductConfigs implements Migration {

	/**
	 * Products read per query.
	 */
	private const BATCH = 200;

	public function version(): int {
		return 2;
	}

	public function up(): void {
		$after = 0;
		do {
			$ids  = ProductIndex::configured( $after, self::BATCH );
			$full = self::BATCH === count( $ids );
			foreach ( $ids as $product_id ) {
				$raw = get_post_meta( $product_id, ProductConfig::META_KEY, true );
				delete_post_meta( $product_id, ProductIndex::TEMPLATE_META );
				delete_post_meta( $product_id, ProductIndex::BLANK_META );
				foreach ( ProductIndex::entries( is_string( $raw ) && '' !== $raw ? ProductConfig::from_json( $raw ) : null ) as list( $key, $value ) ) {
					add_post_meta( $product_id, $key, $value );
				}
				$after = $product_id;
			}
		} while ( $full );
	}
}
