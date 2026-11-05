<?php
/**
 * @package Pixonaut\WooCommerce
 */

declare(strict_types=1);

namespace Pixonaut\WooCommerce\Tests\Unit\Docs;

use Pixonaut\WooCommerce\Tests\Support\Hooks\Hook;
use Pixonaut\WooCommerce\Tests\Support\Hooks\HookScanner;
use Pixonaut\WooCommerce\Tests\Unit\TestCase;

final class HookScannerTest extends TestCase {

	private const SOURCE = <<<'PHP'
		<?php
		namespace Acme\Shop;

		use Acme\Shop\Orders\Line;
		use Pixonaut\WooCommerce\DeprecatedHooks;
		use WC_Order_Item_Product;

		final class Example {
			public function run( $value, $to ) {
				/**
				 * Filters a value.
				 *
				 * Second paragraph, wrapped
				 * over two lines.
				 *
				 * @since 1.2.0
				 *
				 * @param array<string, mixed>  $value The value,
				 *                                     wrapped.
				 * @param Line|null             $line  The line.
				 * @param WC_Order_Item_Product $item  The item.
				 * @param \Other\Thing          $thing A thing.
				 * @param Orders\Note           $note  A note.
				 *
				 * @example
				 * add_filter( 'acme_value', function ( $value ) {
				 *     return $value;
				 * } );
				 */
				$value = apply_filters( 'acme_value', $value, null, null, null, null );

				/**
				 * Fires per state.
				 *
				 * @since 1.0.0
				 */
				do_action( "acme_state_{$to}", $value );

				/**
				 * Not this call's docblock: a line sits between.
				 */
				$x = 1;
				do_action( 'acme_undocumented' );

				/**
				 * Gone.
				 *
				 * @since 1.0.0
				 * @deprecated 2.0.0 Use acme_value.
				 */
				$value = apply_filters_deprecated( 'acme_old', array( $value, $to ), '2.0.0', 'acme_value' );

				do_action_ref_array( 'acme_ref', $value );

				/**
				 * Retired.
				 *
				 * @since 1.0.0
				 * @deprecated 2.1.0 No replacement.
				 */
				DeprecatedHooks::action( 'acme_retired', array( $value ), '2.1.0' );

				\Pixonaut\WooCommerce\DeprecatedHooks::filter( 'acme_retired_filter', array( $value, $to, $x ), '2.1.0' );
				Other::filter( 'not_a_hook', array() );
				return strtolower( 'not a hook' );
			}
		}
		PHP;

	/**
	 * @return array<string, Hook>
	 */
	private function hooks(): array {
		$hooks = array();
		foreach ( HookScanner::scan_source( self::SOURCE, 'src/Example.php' ) as $hook ) {
			$hooks[ $hook->name ] = $hook;
		}
		return $hooks;
	}

	public function test_finds_every_hook_call_and_nothing_else(): void {
		$this->assertSame(
			array( 'acme_value', 'acme_state_{$to}', 'acme_undocumented', 'acme_old', 'acme_ref', 'acme_retired', 'acme_retired_filter' ),
			array_keys( $this->hooks() )
		);
	}

	public function test_reads_the_docblock_above_the_call(): void {
		$hook = $this->hooks()['acme_value'];

		$this->assertTrue( $hook->documented );
		$this->assertSame( Hook::FILTER, $hook->type );
		$this->assertSame( 'src/Example.php', $hook->file );
		$this->assertSame( 30, $hook->line );
		$this->assertSame( 5, $hook->args );
		$this->assertSame( 'Filters a value.', $hook->summary );
		$this->assertSame( 'Second paragraph, wrapped over two lines.', $hook->description );
		$this->assertSame( '1.2.0', $hook->since );
		$this->assertSame( "add_filter( 'acme_value', function ( \$value ) {\n    return \$value;\n} );", $hook->example );
	}

	public function test_resolves_param_types_against_namespace_and_imports(): void {
		$this->assertSame(
			array(
				array(
					'type'        => 'array<string, mixed>',
					'name'        => '$value',
					'description' => 'The value, wrapped.',
				),
				array(
					'type'        => 'Acme\Shop\Orders\Line|null',
					'name'        => '$line',
					'description' => 'The line.',
				),
				array(
					'type'        => 'WC_Order_Item_Product',
					'name'        => '$item',
					'description' => 'The item.',
				),
				array(
					'type'        => 'Other\Thing',
					'name'        => '$thing',
					'description' => 'A thing.',
				),
				array(
					'type'        => 'Acme\Shop\Orders\Note',
					'name'        => '$note',
					'description' => 'A note.',
				),
			),
			$this->hooks()['acme_value']->params
		);
	}

	public function test_keeps_the_variable_part_of_an_interpolated_name(): void {
		$hook = $this->hooks()['acme_state_{$to}'];

		$this->assertSame( Hook::ACTION, $hook->type );
		$this->assertTrue( $hook->is_dynamic() );
		$this->assertTrue( $hook->matches( 'acme_state_shipped' ) );
		$this->assertFalse( $hook->matches( 'acme_state_' ) );
		$this->assertFalse( $hook->matches( 'other_acme_state_shipped' ) );
	}

	public function test_a_docblock_not_right_above_the_call_does_not_count(): void {
		$this->assertFalse( $this->hooks()['acme_undocumented']->documented );
	}

	public function test_deprecated_and_ref_array_calls(): void {
		$old = $this->hooks()['acme_old'];
		$this->assertTrue( $old->deprecated );
		$this->assertSame( 2, $old->args );
		$this->assertSame( '2.0.0 Use acme_value.', $old->deprecation );

		$ref = $this->hooks()['acme_ref'];
		$this->assertSame( Hook::ACTION, $ref->type );
		$this->assertNull( $ref->args );
	}

	public function test_a_hook_retired_through_deprecated_hooks_stays_documented(): void {
		$action = $this->hooks()['acme_retired'];
		$this->assertSame( Hook::ACTION, $action->type );
		$this->assertTrue( $action->deprecated );
		$this->assertTrue( $action->documented );
		$this->assertSame( 1, $action->args );
		$this->assertSame( '2.1.0 No replacement.', $action->deprecation );

		$filter = $this->hooks()['acme_retired_filter'];
		$this->assertSame( Hook::FILTER, $filter->type );
		$this->assertTrue( $filter->deprecated );
		$this->assertSame( 3, $filter->args );
	}

	public function test_plugin_hooks_lists_only_the_prefix_sorted(): void {
		$names = array_map( static fn ( Hook $hook ): string => $hook->name, HookScanner::plugin_hooks( dirname( __DIR__, 4 ) . '/src' ) );

		$this->assertContains( 'pixonaut_wc_storefront_label', $names );
		foreach ( $names as $name ) {
			$this->assertStringStartsWith( HookScanner::PREFIX, $name );
		}
		$sorted = $names;
		sort( $sorted );
		$this->assertSame( $sorted, $names );
	}
}
