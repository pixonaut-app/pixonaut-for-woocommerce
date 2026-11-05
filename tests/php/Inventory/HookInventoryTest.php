<?php
/**
 * @package Pixonaut\WooCommerce
 */

declare(strict_types=1);

namespace Pixonaut\WooCommerce\Tests\Inventory;

use Pixonaut\WooCommerce\Tests\Support\Hooks\Hook;
use Pixonaut\WooCommerce\Tests\Support\Hooks\HookRecorder;
use Pixonaut\WooCommerce\Tests\Support\Hooks\HookScanner;
use WP_UnitTestCase;

/**
 * Every documented hook fires somewhere in the integration suite, which runs
 * before this one, or is listed in NOT_FIRED with the reason. A documented
 * hook nothing fires is either dead or untested.
 */
final class HookInventoryTest extends WP_UnitTestCase {

	/**
	 * Documented hooks the integration suite does not fire, and why.
	 *
	 * @var array<string, string>
	 */
	private const NOT_FIRED = array();

	/**
	 * Options that run part of the suite, leaving hooks unfired.
	 */
	private const PARTIAL_RUN = '/^--(filter|group|exclude-group|testsuite)\b/';

	public function set_up(): void {
		parent::set_up();
		foreach ( $GLOBALS['argv'] ?? array() as $arg ) {
			if ( is_string( $arg ) && 1 === preg_match( self::PARTIAL_RUN, $arg ) ) {
				$this->markTestSkipped( 'The hook inventory needs the whole integration suite.' );
			}
		}
	}

	public function test_every_documented_hook_fires_or_is_listed(): void {
		$fired    = HookRecorder::fired();
		$unfired  = array();
		$listed   = array();
		$hooks    = HookScanner::plugin_hooks( dirname( __DIR__, 3 ) . '/src' );
		$names    = array_map( static fn ( Hook $hook ): string => $hook->name, $hooks );
		$is_fired = static fn ( Hook $hook ): bool => array() !== array_filter( $fired, array( $hook, 'matches' ) );

		$not_fired = self::not_fired();

		foreach ( $hooks as $hook ) {
			if ( isset( $not_fired[ $hook->name ] ) ) {
				if ( $is_fired( $hook ) ) {
					$listed[] = "{$hook->name} fires now: remove it from NOT_FIRED.";
				}
			} elseif ( ! $is_fired( $hook ) ) {
				$unfired[] = "{$hook->name} ({$hook->file}:{$hook->line})";
			}
		}
		foreach ( array_diff( array_keys( $not_fired ), $names ) as $gone ) {
			$listed[] = "{$gone} is not a documented hook: remove it from NOT_FIRED.";
		}

		$this->assertSame( array(), $unfired, 'Fire these in an integration test, or list them in NOT_FIRED with the reason.' );
		$this->assertSame( array(), $listed );
	}

	/**
	 * @return array<string, string>
	 */
	private static function not_fired(): array {
		return self::NOT_FIRED;
	}
}
