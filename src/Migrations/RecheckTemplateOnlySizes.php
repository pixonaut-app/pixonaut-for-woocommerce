<?php
/**
 * Re-checks every product, so a template-only one without a print size stops selling.
 *
 * @package Pixonaut\WooCommerce
 */

declare(strict_types=1);

namespace Pixonaut\WooCommerce\Migrations;

use Pixonaut\WooCommerce\Catalogue\ProductRecheck;
use Pixonaut\WooCommerce\Schema;

/**
 * A side without a blank is now sold in the print sizes the store manager
 * enters. One saved before has none, and no design made on it is accepted
 * by the cart, yet it still reads as sellable. Asks for the re-check of
 * every product with a configuration, as `ResolveProductVariants` does,
 * which marks such a product unsellable and raises its notice.
 */
final class RecheckTemplateOnlySizes implements Migration {

	public function version(): int {
		return 6;
	}

	public function up(): void {
		update_option( Schema::VARIANTS_BACKFILL_OPTION, ProductRecheck::BACKFILL_PENDING, false );
	}
}
