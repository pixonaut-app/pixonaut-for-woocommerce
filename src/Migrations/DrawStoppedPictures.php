<?php
/**
 * Checks every product's picture again, for those whose job stopped.
 *
 * @package Pixonaut\WooCommerce
 */

declare(strict_types=1);

namespace Pixonaut\WooCommerce\Migrations;

use Pixonaut\WooCommerce\Catalogue\PictureQueue;

/**
 * Asks for every configured product's picture to be checked again, as
 * `DrawFlatCardPictures` did. A drawing's job started from WP-Cron could
 * be stopped by a web request's time limit while WordPress made the
 * picture's image sizes; Action Scheduler marked it failed and nothing
 * queued it again, so the product or variation kept its own image, or
 * none. Only those are drawn now.
 */
final class DrawStoppedPictures implements Migration {

	public function version(): int {
		return 15;
	}

	public function up(): void {
		update_option( PictureQueue::BACKFILL_OPTION, PictureQueue::BACKFILL_PENDING, false );
	}
}
