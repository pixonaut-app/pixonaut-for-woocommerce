<?php
/**
 * Writes again the split cards of the products listed by several attributes.
 *
 * @package Pixonaut\WooCommerce
 */

declare(strict_types=1);

namespace Pixonaut\WooCommerce\Migrations;

use Pixonaut\WooCommerce\Catalogue\SplitCards;

/**
 * Asks for every variable product's `SplitCards` rows to be written again,
 * as `WriteSplitCards` did: a product with more than one attribute listing
 * its values as their own products was listed by the first alone, and is now
 * listed once per combination of their values, and a variation leaving a
 * listed attribute unset because it does not apply to it has a card of its
 * own. Until its rows are written, the shop shows such a product as itself.
 */
final class WriteCombinationCards implements Migration {

	public function version(): int {
		return 11;
	}

	public function up(): void {
		update_option( SplitCards::BACKFILL_OPTION, SplitCards::BACKFILL_PENDING, false );
	}
}
