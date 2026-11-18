<?php
/**
 * Deletes the pictures stopped jobs left unfinished before the plugin did so itself.
 *
 * @package Pixonaut\WooCommerce
 */

declare(strict_types=1);

namespace Pixonaut\WooCommerce\Migrations;

use Pixonaut\WooCommerce\Catalogue\UnfinishedPictures;

/**
 * A picture's job stopped while WordPress made the image sizes left the
 * attachment it had inserted, shown by no product. Migrations run before
 * Action Scheduler can queue, so this leaves an option that
 * `UnfinishedPictures` turns into the job deleting them.
 */
final class DeleteUnfinishedPictures implements Migration {

	public function version(): int {
		return 17;
	}

	public function up(): void {
		UnfinishedPictures::ask();
	}
}
