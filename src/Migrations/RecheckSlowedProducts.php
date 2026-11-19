<?php
/**
 * Re-checks every product, so one refused for too many requests sells again.
 *
 * @package Pixonaut\WooCommerce
 */

declare(strict_types=1);

namespace Pixonaut\WooCommerce\Migrations;

use Pixonaut\WooCommerce\Catalogue\ProductRecheck;
use Pixonaut\WooCommerce\Schema;

/**
 * A product or variation whose check Pixonaut refused for too many
 * requests used to be stored unsellable and checked again a minute on,
 * whatever wait Pixonaut asked for, alongside every other one saved in
 * the same run, so the re-checks were refused again together. Asks for
 * the re-check of every product with a configuration, as
 * `RecheckTemplateOnlySizes` does; the re-checks now wait their turn
 * while Pixonaut asks the store to hold its checks.
 */
final class RecheckSlowedProducts implements Migration {

	public function version(): int {
		return 18;
	}

	public function up(): void {
		update_option( Schema::VARIANTS_BACKFILL_OPTION, ProductRecheck::BACKFILL_PENDING, false );
	}
}
