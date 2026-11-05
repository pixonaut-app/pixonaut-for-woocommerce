<?php
/**
 * @package Pixonaut\WooCommerce
 */

declare(strict_types=1);

namespace Pixonaut\WooCommerce\Tests\Unit\Storefront;

use Brain\Monkey\Functions;
use Pixonaut\WooCommerce\Storefront\ThemeLook;
use Pixonaut\WooCommerce\Tests\Unit\TestCase;

final class ThemeLookTest extends TestCase {

	public function test_lists_the_presets_the_theme_defines_in_its_global_settings(): void {
		$this->settings(
			array(
				'color.palette'           => array(
					'default' => array( array( 'slug' => 'black' ) ),
					'theme'   => array( array( 'slug' => 'accent' ), array( 'slug' => 'border-strong' ) ),
				),
				'typography.fontFamilies' => array( 'theme' => array( array( 'slug' => 'heading' ) ) ),
				'custom.radius.small'     => '6px',
			)
		);

		$this->assertSame( array( 'accent', 'border', 'headingFont', 'radius' ), ThemeLook::presets() );
	}

	public function test_a_theme_without_them_lists_none(): void {
		$this->settings(
			array(
				'color.palette' => array( 'theme' => array( array( 'slug' => 'accent-1' ) ) ),
			)
		);

		$this->assertSame( array(), ThemeLook::presets() );
	}

	/**
	 * Answers `wp_get_global_settings()` from `$settings`, by dotted path.
	 *
	 * @param array<string, mixed> $settings The settings.
	 */
	private function settings( array $settings ): void {
		Functions\when( 'wp_get_global_settings' )->alias(
			static fn ( array $path ) => $settings[ implode( '.', $path ) ] ?? null
		);
	}
}
