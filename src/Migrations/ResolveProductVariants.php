<?php
/**
 * Resolves the blank variant of every product stored before variants.
 *
 * @package Pixonaut\WooCommerce
 */

declare(strict_types=1);

namespace Pixonaut\WooCommerce\Migrations;

use Pixonaut\WooCommerce\Catalogue\ProductRecheck;
use Pixonaut\WooCommerce\Schema;

/**
 * Asks for the re-check of every product with a configuration, which
 * resolves the blank variant of each product and variation: until it has
 * one, one personalised on a blank cannot be bought. Migrations run before
 * Action Scheduler can queue, so this leaves an option that
 * `ProductRecheck` turns into the job, on this request or a later one, once
 * it could be queued.
 */
final class ResolveProductVariants implements Migration {

	public function version(): int {
		return 3;
	}

	public function up(): void {
		update_option( Schema::VARIANTS_BACKFILL_OPTION, ProductRecheck::BACKFILL_PENDING, false );
	}
}
