<?php
/**
 * Whether a blank's photo can be drawn on.
 *
 * @package Pixonaut\WooCommerce
 */

declare(strict_types=1);

namespace Pixonaut\WooCommerce\Catalogue;

/**
 * A photo in a blank's mockups answer is drawn on through its `mount`, or,
 * on a view showing a setting, through its own `photoScene`, which such a
 * photo carries in place of a mount. One with neither (still uploading, or
 * with no print area marked) is one nothing can draw on yet.
 */
final class BlankPhoto {

	/**
	 * @param array<mixed> $photo A photo of a view, as Pixonaut lists it.
	 */
	public static function drawable( array $photo ): bool {
		return is_array( $photo['mount'] ?? null ) || is_array( $photo['photoScene'] ?? null );
	}
}
