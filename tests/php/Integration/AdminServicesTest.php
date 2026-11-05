<?php
/**
 * @package Pixonaut\WooCommerce
 */

declare(strict_types=1);

namespace Pixonaut\WooCommerce\Tests\Integration;

use Pixonaut\WooCommerce\Admin\Notices;
use Pixonaut\WooCommerce\Layout;
use Pixonaut\WooCommerce\Plugin;
use Pixonaut\WooCommerce\Schema;
use Pixonaut\WooCommerce\Settings\Settings;
use Pixonaut\WooCommerce\Settings\SettingsPage;
use Pixonaut\WooCommerce\Templates;
use WC_Admin_Settings;
use WP_UnitTestCase;

final class AdminServicesTest extends WP_UnitTestCase {

	/**
	 * The theme path globals locate_template() reads, as they were before the test.
	 *
	 * @var array<string, mixed>
	 */
	private array $theme_globals = array();

	public function set_up(): void {
		parent::set_up();
		// WooCommerce loads its settings classes on admin screens only.
		require_once WC()->plugin_path() . '/includes/admin/class-wc-admin-settings.php';
		wp_set_template_globals();
		$this->theme_globals = array(
			'wp_stylesheet_path' => $GLOBALS['wp_stylesheet_path'],
			'wp_template_path'   => $GLOBALS['wp_template_path'],
		);
	}

	public function tear_down(): void {
		foreach ( $this->theme_globals as $name => $value ) {
			$GLOBALS[ $name ] = $value;
		}
		parent::tear_down();
	}

	public function test_template_resolves_to_the_plugin_copy(): void {
		$templates = $this->plugin()->templates();

		$this->assertSame( dirname( __DIR__, 3 ) . '/templates/admin/notice.php', $templates->locate( 'admin/notice.php' ) );
	}

	public function test_theme_overrides_a_template_under_woocommerce_pixonaut(): void {
		// The active theme's directory, as locate_template() sees it.
		$GLOBALS['wp_stylesheet_path'] = dirname( __DIR__ ) . '/fixtures/theme';
		wp_cache_flush();

		$html = $this->plugin()->templates()->html( 'admin/notice.php', array( 'message' => 'Hello' ) );

		$this->assertSame( dirname( __DIR__ ) . '/fixtures/theme/' . Templates::THEME_PATH . 'admin/notice.php', $this->plugin()->templates()->locate( 'admin/notice.php' ) );
		$this->assertStringContainsString( '<p class="theme-override">Hello</p>', $html );
	}

	public function test_notices_render_for_store_managers_with_a_dismiss_link(): void {
		$notices = $this->plugin()->notices();
		$notices->add( 'test_notice', 'Reconnect <strong>now</strong>', 'error' );
		$notices->add( 'sticky_notice', 'Cannot be dismissed', 'warning', false );

		wp_set_current_user( $this->user( 'shop_manager' ) );
		ob_start();
		$notices->render();
		$html = (string) ob_get_clean();

		$this->assertStringContainsString( 'notice-error', $html );
		$this->assertStringContainsString( 'Reconnect <strong>now</strong>', $html );
		$this->assertStringContainsString( 'notice=test_notice', $html );
		$this->assertStringNotContainsString( 'notice=sticky_notice', $html );

		wp_set_current_user( $this->user( 'customer' ) );
		ob_start();
		$notices->render();
		$this->assertSame( '', ob_get_clean() );

		$notices->remove( 'test_notice' );
		$notices->remove( 'sticky_notice' );
	}

	public function test_settings_page_is_registered(): void {
		$this->assertTrue( has_action( 'admin_menu' ) );
		$this->assertContains( SettingsPage::HOOK_SUFFIX, apply_filters( 'woocommerce_screen_ids', array() ) );
	}

	public function test_settings_page_saves_into_the_settings_option(): void {
		$page                      = new SettingsPage( $this->plugin()->settings() );
		$_POST[ Settings::OPTION ] = array( Settings::DEFAULT_LAYOUT => Layout::BUTTON ); // phpcs:ignore WordPress.Security.NonceVerification.Missing

		WC_Admin_Settings::save_fields( $page->fields( SettingsPage::STOREFRONT ) );
		unset( $_POST[ Settings::OPTION ] );

		$this->assertSame( Layout::BUTTON, $this->plugin()->settings()->get( Settings::DEFAULT_LAYOUT ) );
		delete_option( Settings::OPTION );
	}

	public function test_settings_page_ticks_and_saves_the_print_area_frame(): void {
		$page = new SettingsPage( $this->plugin()->settings() );
		$this->plugin()->settings()->set( Settings::PRINT_AREA_FRAME, true );
		$field = Settings::OPTION . '[' . Settings::PRINT_AREA_FRAME . ']';

		$this->assertSame( 'yes', WC_Admin_Settings::get_option( $field ) );

		$_POST[ Settings::OPTION ] = array( Settings::DEFAULT_LAYOUT => Layout::INLINE ); // phpcs:ignore WordPress.Security.NonceVerification.Missing
		WC_Admin_Settings::save_fields( $page->fields( SettingsPage::STOREFRONT ) );
		unset( $_POST[ Settings::OPTION ] );

		$this->assertFalse( $this->plugin()->settings()->get( Settings::PRINT_AREA_FRAME ), 'an unticked box saves as off' );
		delete_option( Settings::OPTION );
	}

	public function test_uninstall_removes_settings_notices_and_product_config(): void {
		update_option( Settings::OPTION, array( Settings::DEFAULT_LAYOUT => Layout::BUTTON ) );
		update_option( Notices::OPTION, array( 'k' => array() ) );
		$product_id = self::factory()->post->create( array( 'post_type' => 'product' ) );
		if ( ! is_int( $product_id ) ) {
			$this->fail( 'Could not create a product.' );
		}
		update_post_meta( $product_id, '_pixonaut_config', '{"v":1}' );
		update_post_meta( $product_id, Schema::PRODUCT_TEMPLATE_INDEX_META, 'mini-tee' );
		update_post_meta( $product_id, Schema::PRODUCT_BLANK_INDEX_META, 'blank_tee' );

		if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
			define( 'WP_UNINSTALL_PLUGIN', 'pixonaut-for-woocommerce/pixonaut-for-woocommerce.php' );
		}
		require dirname( __DIR__, 3 ) . '/uninstall.php';

		$this->assertFalse( get_option( Settings::OPTION ) );
		$this->assertFalse( get_option( Notices::OPTION ) );
		$this->assertSame( '', get_post_meta( $product_id, '_pixonaut_config', true ) );
		$this->assertSame( '', get_post_meta( $product_id, Schema::PRODUCT_TEMPLATE_INDEX_META, true ) );
		$this->assertSame( '', get_post_meta( $product_id, Schema::PRODUCT_BLANK_INDEX_META, true ) );
	}

	private function user( string $role ): int {
		$id = self::factory()->user->create( array( 'role' => $role ) );
		if ( ! is_int( $id ) ) {
			$this->fail( 'Could not create a user.' );
		}
		return $id;
	}

	private function plugin(): Plugin {
		return Plugin::boot( dirname( __DIR__, 3 ) . '/pixonaut-for-woocommerce.php' );
	}
}
