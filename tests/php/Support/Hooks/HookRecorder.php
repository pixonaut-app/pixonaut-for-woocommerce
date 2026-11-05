<?php
/**
 * Records which of the plugin's hooks fire.
 *
 * @package Pixonaut\WooCommerce
 */

declare(strict_types=1);

namespace Pixonaut\WooCommerce\Tests\Support\Hooks;

/**
 * Attached to WordPress's `all` hook by the integration bootstrap, so it sees
 * every hook fired during the suite. The hook inventory test reads it last.
 */
final class HookRecorder {

	/**
	 * @var array<string, true> Fired hook names.
	 */
	private static array $fired = array();

	/**
	 * @param string $hook The hook being fired.
	 */
	public static function record( string $hook ): void {
		if ( str_starts_with( $hook, HookScanner::PREFIX ) ) {
			self::$fired[ $hook ] = true;
		}
	}

	/**
	 * @return list<string> The plugin's hooks fired so far.
	 */
	public static function fired(): array {
		return array_keys( self::$fired );
	}
}
