<?php
/**
 * An in-memory options table for unit tests.
 *
 * @package Pixonaut\WooCommerce
 */

declare(strict_types=1);

namespace Pixonaut\WooCommerce\Tests\Unit;

use Brain\Monkey\Functions;

trait FakeOptions {

	/**
	 * @var array<string, mixed>
	 */
	private array $options = array();

	/**
	 * Option names whose writes fail, as a failed database write would.
	 *
	 * @var list<string>
	 */
	private array $failing_options = array();

	private function fake_options(): void {
		Functions\when( 'get_option' )->alias(
			fn ( string $name, mixed $fallback = false ): mixed => array_key_exists( $name, $this->options ) ? $this->options[ $name ] : $fallback
		);
		Functions\when( 'update_option' )->alias(
			function ( string $name, mixed $value ): bool {
				if ( in_array( $name, $this->failing_options, true ) ) {
					return false;
				}
				$this->options[ $name ] = $value;
				return true;
			}
		);
		Functions\when( 'add_option' )->alias(
			function ( string $name, mixed $value ): bool {
				if ( array_key_exists( $name, $this->options ) ) {
					return false;
				}
				$this->options[ $name ] = $value;
				return true;
			}
		);
		Functions\when( 'delete_option' )->alias(
			function ( string $name ): bool {
				if ( in_array( $name, $this->failing_options, true ) ) {
					return false;
				}
				$existed = array_key_exists( $name, $this->options );
				unset( $this->options[ $name ] );
				return $existed;
			}
		);
		Functions\when( 'wp_cache_delete' )->justReturn( true );
	}
}
