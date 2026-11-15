<?php
/**
 * Checks every product's picture again, for those drawn for no variation.
 *
 * @package Pixonaut\WooCommerce
 */

declare(strict_types=1);

namespace Pixonaut\WooCommerce\Migrations;

use Pixonaut\WooCommerce\Catalogue\PictureQueue;

/**
 * Asks for every configured product's picture to be checked again, as the
 * setting turned on does. A variable product's picture was drawn for no
 * variant of its blank, so it could show one the product does not sell,
 * and is now the one of a variation it sells; a variation set up on another
 * template than its product's was drawn with the product's showcase set,
 * which that template does not have, so it showed the bare template, and is
 * now drawn with its own template's set. Only those are drawn again; each
 * keeps the image it has until then.
 */
final class RedrawVariationPictures implements Migration {

	public function version(): int {
		return 12;
	}

	public function up(): void {
		update_option( PictureQueue::BACKFILL_OPTION, PictureQueue::BACKFILL_PENDING, false );
	}
}
