<?php
/**
 * @package Pixonaut\WooCommerce
 */

declare(strict_types=1);

namespace Pixonaut\WooCommerce\Tests\Integration\Admin;

use Pixonaut\WooCommerce\Admin\ConnectionScreen;
use Pixonaut\WooCommerce\Admin\ConnectNotice;
use Pixonaut\WooCommerce\Admin\HelpLinks;
use Pixonaut\WooCommerce\Connection\Cipher;
use Pixonaut\WooCommerce\Connection\Connection;
use Pixonaut\WooCommerce\Connection\ConnectionStore;
use Pixonaut\WooCommerce\Plugin;
use Pixonaut\WooCommerce\Tests\Integration\Storefront\StorefrontTest;
use RuntimeException;
use WP_UnitTestCase;

final class ConnectNoticeTest extends WP_UnitTestCase {

	/**
	 * Screens the notice shows on: the Plugins screen and WooCommerce's.
	 */
	private const SHOWN_ON = array( 'plugins', 'edit-product', 'woocommerce_page_wc-orders' );

	private Plugin $plugin;

	private ConnectNotice $notice;

	private int $admin;

	public function set_up(): void {
		parent::set_up();
		$this->plugin = Plugin::boot( dirname( __DIR__, 4 ) . '/pixonaut-for-woocommerce.php' );
		$this->plugin->connection_store()->clear();
		$this->notice = new ConnectNotice( $this->plugin->connection(), $this->plugin->templates(), ConnectionScreen::settings_url() );
		$admin        = self::factory()->user->create( array( 'role' => 'administrator' ) );
		$this->admin  = is_int( $admin ) ? $admin : 0;
		wp_set_current_user( $this->admin );
	}

	public function tear_down(): void {
		$this->plugin->connection_store()->clear();
		remove_all_filters( 'wp_redirect' );
		unset( $_GET['_wpnonce'], $_REQUEST['_wpnonce'] );
		parent::tear_down();
	}

	public function test_a_disconnected_store_shows_the_notice_on_the_plugins_and_woocommerce_screens(): void {
		foreach ( self::SHOWN_ON as $screen ) {
			$this->assertTrue( $this->notice->shows( $this->admin, $screen ), $screen );
		}
		$this->assertFalse( $this->notice->shows( $this->admin, 'dashboard' ) );
		$this->assertFalse( $this->notice->shows( $this->admin, 'toplevel_page_pixonaut' ), 'The settings page shows the connection card instead.' );
	}

	public function test_the_notice_links_to_the_connection_settings_and_the_guide(): void {
		set_current_screen( 'plugins' );

		ob_start();
		$this->notice->render();
		$html = (string) ob_get_clean();

		// wp_kses_post() writes the & as &amp;, or on WordPress 6.9 leaves esc_url()'s &#038;.
		$this->assertStringContainsString( ConnectionScreen::settings_url(), html_entity_decode( $html ) );
		$this->assertStringContainsString( HelpLinks::docs( HelpLinks::CONNECT ), $html );
		$this->assertStringContainsString( ConnectNotice::DISMISS_ACTION, $html );
	}

	public function test_a_shopper_without_the_capability_never_sees_it(): void {
		$customer = self::factory()->user->create( array( 'role' => 'customer' ) );

		$this->assertFalse( $this->notice->shows( is_int( $customer ) ? $customer : 0, 'plugins' ) );
	}

	public function test_dismissing_hides_it_for_that_user_only(): void {
		$other                = self::factory()->user->create( array( 'role' => 'administrator' ) );
		$nonce                = wp_create_nonce( ConnectNotice::DISMISS_ACTION );
		$_GET['_wpnonce']     = $nonce;
		$_REQUEST['_wpnonce'] = $nonce;
		// Turns the handler's redirect, which exits, into an exception.
		add_filter(
			'wp_redirect',
			static function ( string $location ): string {
				throw new RuntimeException( $location ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- a test's redirect.
			}
		);

		try {
			$this->notice->handle_dismiss();
			$this->fail( 'The handler redirects.' );
		} catch ( RuntimeException $redirect ) {
			$this->assertNotSame( '', $redirect->getMessage() );
		}

		$this->assertFalse( $this->notice->shows( $this->admin, 'plugins' ) );
		$this->assertTrue( $this->notice->shows( is_int( $other ) ? $other : 0, 'plugins' ) );
	}

	public function test_connecting_removes_it(): void {
		StorefrontTest::connect( $this->plugin->connection_store() );

		$this->assertFalse( $this->notice->shows( $this->admin, 'plugins' ) );
	}

	public function test_a_paused_connection_shows_its_own_notice_instead(): void {
		StorefrontTest::connect( $this->plugin->connection_store() );
		// A store of its own: the plugin's would stay paused in memory for the tests after this one.
		( new ConnectionStore( Cipher::for_site() ) )->pause( Connection::PAUSED_UNAUTHORIZED );

		$this->assertFalse( $this->notice->shows( $this->admin, 'plugins' ) );
	}
}
