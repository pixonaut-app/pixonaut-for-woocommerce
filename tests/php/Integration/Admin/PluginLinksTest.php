<?php
/**
 * @package Pixonaut\WooCommerce
 */

declare(strict_types=1);

namespace Pixonaut\WooCommerce\Tests\Integration\Admin;

use Pixonaut\WooCommerce\Admin\ConnectionScreen;
use Pixonaut\WooCommerce\Admin\HelpLinks;
use WP_UnitTestCase;

final class PluginLinksTest extends WP_UnitTestCase {

	private string $basename;

	public function set_up(): void {
		parent::set_up();
		// The checkout's directory, which a worktree names differently.
		$this->basename = plugin_basename( dirname( __DIR__, 4 ) . '/pixonaut-for-woocommerce.php' );
	}

	public function test_the_plugins_row_has_a_settings_action_first(): void {
		$links = apply_filters( 'plugin_action_links_' . $this->basename, array( 'deactivate' => '<a href="#">Deactivate</a>' ) );

		$this->assertSame( array( 'settings', 'deactivate' ), array_keys( $links ) );
		$this->assertStringContainsString( esc_url( ConnectionScreen::settings_url() ), $links['settings'] );
	}

	public function test_the_plugins_row_links_to_the_docs_and_support(): void {
		$meta = apply_filters( 'plugin_row_meta', array( 'Version 0.1.0' ), $this->basename, array(), 'all' );

		$this->assertStringContainsString( HelpLinks::docs(), $meta['docs'] );
		$this->assertStringContainsString( HelpLinks::SUPPORT_URL, $meta['support'] );
	}

	public function test_another_plugins_row_is_left_alone(): void {
		$meta = array( 'Version 1.0' );

		$this->assertSame( $meta, apply_filters( 'plugin_row_meta', $meta, 'hello.php', array(), 'all' ) );
	}
}
