<?php
/**
 * Resolves the blank variant of every product again, with its views' ids.
 *
 * @package Pixonaut\WooCommerce
 */

declare(strict_types=1);

namespace Pixonaut\WooCommerce\Migrations;

use Pixonaut\WooCommerce\Catalogue\ProductRecheck;
use Pixonaut\WooCommerce\Schema;

/**
 * A stored variant now names the views it has a photo in by id and
 * placement, which the product's chosen views are checked against; one
 * stored before keeps its pick and variant but reads as having a photo in
 * no view. Asks for the re-check of every product with a configuration, as
 * `ResolveProductVariants` does, which stores each variant again with its
 * views.
 */
final class ResolveVariantViews implements Migration {

	public function version(): int {
		return 4;
	}

	public function up(): void {
		update_option( Schema::VARIANTS_BACKFILL_OPTION, ProductRecheck::BACKFILL_PENDING, false );
	}
}
