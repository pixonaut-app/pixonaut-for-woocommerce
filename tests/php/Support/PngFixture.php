<?php
/**
 * @package Pixonaut\WooCommerce
 */

declare(strict_types=1);

namespace Pixonaut\WooCommerce\Tests\Support;

/**
 * A PNG for Pixonaut to answer a picture with.
 */
final class PngFixture {

	/**
	 * An 8×8 white PNG.
	 */
	public static function white(): string {
		return base64_decode( 'iVBORw0KGgoAAAANSUhEUgAAAAgAAAAICAIAAABLbSncAAAAD0lEQVR4nGP4jwMwDC0JALoev0Ewkwr8AAAAAElFTkSuQmCC' ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- a fixture image.
	}
}
