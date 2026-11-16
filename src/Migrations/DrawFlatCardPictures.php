<?php
/**
 * Checks every product's picture again, for the flat pictures cards show.
 *
 * @package Pixonaut\WooCommerce
 */

declare(strict_types=1);

namespace Pixonaut\WooCommerce\Migrations;

use Pixonaut\WooCommerce\Catalogue\PictureQueue;

/**
 * Asks for every configured product's picture to be checked again, as
 * `RedrawVariationPictures` did. A variation of a product listed as cards,
 * drawn on the blank's photo, now also has a flat picture of its design,
 * which a card in the shop shows while no filter on a framing is on; each
 * such variation is drawn again for it, keeping the pictures it has, which
 * are not drawn again.
 */
final class DrawFlatCardPictures implements Migration {

	public function version(): int {
		return 14;
	}

	public function up(): void {
		update_option( PictureQueue::BACKFILL_OPTION, PictureQueue::BACKFILL_PENDING, false );
	}
}
