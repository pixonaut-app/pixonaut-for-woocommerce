<?php
/**
 * Pixonaut's times.
 *
 * @package Pixonaut\WooCommerce
 */

declare(strict_types=1);

namespace Pixonaut\WooCommerce;

/**
 * Pixonaut writes times as milliseconds since the Unix epoch.
 */
final class Milliseconds {

	public const PER_SECOND = 1000;

	/**
	 * @param int $unix_time Seconds since the Unix epoch.
	 */
	public static function of( int $unix_time ): int {
		return $unix_time * self::PER_SECOND;
	}
}
