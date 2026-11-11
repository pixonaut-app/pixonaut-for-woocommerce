<?php
/**
 * Writes the split cards of every product set up before they were kept.
 *
 * @package Pixonaut\WooCommerce
 */

declare(strict_types=1);

namespace Pixonaut\WooCommerce\Migrations;

use Pixonaut\WooCommerce\Catalogue\SplitCards;

/**
 * Asks for every variable product's `SplitCards` rows to be written: a
 * product listed by an attribute before they were kept has none until it
 * changes. Migrations run before WooCommerce registers its attributes and
 * before Action Scheduler can queue, so this leaves an option that
 * `SplitCards` turns into the job, on this request or a later one, once it
 * could be queued. The job writes a batch at a time, each its own job, so
 * one that stops is taken up from its batch.
 */
final class WriteSplitCards implements Migration {

	public function version(): int {
		return 10;
	}

	public function up(): void {
		update_option( SplitCards::BACKFILL_OPTION, SplitCards::BACKFILL_PENDING, false );
	}
}
