<?php
/**
 * @package Pixonaut\WooCommerce
 */

declare(strict_types=1);

namespace Pixonaut\WooCommerce\Tests\Integration;

use Pixonaut\WooCommerce\DeprecatedHooks;
use WP_UnitTestCase;

final class DeprecatedHooksTest extends WP_UnitTestCase {

	private const OLD = 'pixonaut_wc_test_old';

	private const NEW = 'pixonaut_wc_test_new';

	private const VERSION = '1.2.0';

	public function test_a_renamed_filter_still_runs_callbacks_on_the_old_name_first(): void {
		( new DeprecatedHooks( array( self::OLD => array( self::NEW, self::VERSION ) ), array() ) )->register();
		add_filter( self::OLD, static fn ( string $value, string $suffix ): string => $value . ' old' . $suffix, 10, 2 );
		add_filter( self::NEW, static fn ( string $value ): string => $value . ' new' );
		$this->setExpectedDeprecated( self::OLD );

		$this->assertSame( 'x old! new', apply_filters( self::NEW, 'x', '!' ) );
	}

	public function test_a_renamed_action_still_runs_callbacks_on_the_old_name_first(): void {
		( new DeprecatedHooks( array(), array( self::OLD => array( self::NEW, self::VERSION ) ) ) )->register();
		$calls = array();
		add_action(
			self::OLD,
			static function ( int $id ) use ( &$calls ): void {
				$calls[] = "old {$id}";
			}
		);
		add_action(
			self::NEW,
			static function ( int $id ) use ( &$calls ): void {
				$calls[] = "new {$id}";
			}
		);
		$this->setExpectedDeprecated( self::OLD );

		do_action( self::NEW, 7 );

		$this->assertSame( array( 'old 7', 'new 7' ), $calls );
	}

	public function test_an_unused_old_name_reports_nothing(): void {
		( new DeprecatedHooks( array( self::OLD => array( self::NEW, self::VERSION ) ), array() ) )->register();

		$this->assertSame( 'x', apply_filters( self::NEW, 'x' ) );
	}

	public function test_filter_names_the_plugin_version_in_the_notice(): void {
		$notices = array();
		add_action(
			'deprecated_hook_run',
			static function ( string $hook, string $replacement, string $version, string $message ) use ( &$notices ): void {
				$notices[] = array( $hook, $replacement, $version, $message );
			},
			10,
			4
		);
		add_filter( self::OLD, static fn (): string => 'filtered' );
		$this->setExpectedDeprecated( self::OLD );

		$this->assertSame( 'filtered', DeprecatedHooks::filter( self::OLD, array( 'x' ), self::VERSION, self::NEW ) );
		$this->assertSame( array( array( self::OLD, self::NEW, self::VERSION, 'Deprecated in Pixonaut for WooCommerce 1.2.0.' ) ), $notices );
	}
}
