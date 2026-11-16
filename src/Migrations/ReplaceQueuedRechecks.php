<?php
/**
 * Replaces the re-checks queued before each product was queued once.
 *
 * @package Pixonaut\WooCommerce
 */

declare(strict_types=1);

namespace Pixonaut\WooCommerce\Migrations;

use Pixonaut\WooCommerce\Catalogue\ProductRecheck;
use Pixonaut\WooCommerce\Schema;

/**
 * Re-checks used to be queued in batches skipped only when one with the
 * same products waited, so every template or blank event queued another
 * set of nearly the same batches, and a store could hold thousands of them.
 * Asks for the re-check of every product with a configuration in place of
 * all re-checks still waiting, which `ProductRecheck::queue_backfill()`
 * cancels before queueing it.
 */
final class ReplaceQueuedRechecks implements Migration {

	public function version(): int {
		return 13;
	}

	public function up(): void {
		update_option( Schema::VARIANTS_BACKFILL_OPTION, ProductRecheck::BACKFILL_REPLACING, false );
	}
}
