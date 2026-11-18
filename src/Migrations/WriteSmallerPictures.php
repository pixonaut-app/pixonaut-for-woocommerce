<?php
/**
 * Writes every Pixonaut picture kept as a PNG again as WebP.
 *
 * @package Pixonaut\WooCommerce
 */

declare(strict_types=1);

namespace Pixonaut\WooCommerce\Migrations;

use Pixonaut\WooCommerce\Catalogue\SmallerPictures;

/**
 * Asks for every Pixonaut picture kept as a PNG to be written again as
 * WebP, in place, deleting the PNG and its sizes. Migrations run before
 * Action Scheduler can queue, so this leaves an option that
 * `SmallerPictures` turns into the job, on this request or a later one,
 * once it could be queued.
 */
final class WriteSmallerPictures implements Migration {

	public function version(): int {
		return 16;
	}

	public function up(): void {
		update_option( SmallerPictures::BACKFILL_OPTION, SmallerPictures::BACKFILL_PENDING, false );
	}
}
