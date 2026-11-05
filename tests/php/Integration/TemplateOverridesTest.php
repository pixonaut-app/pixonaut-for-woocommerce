<?php
/**
 * @package Pixonaut\WooCommerce
 */

declare(strict_types=1);

namespace Pixonaut\WooCommerce\Tests\Integration;

use Pixonaut\WooCommerce\Admin\StatusReport;
use Pixonaut\WooCommerce\Plugin;
use Pixonaut\WooCommerce\TemplateOverride;
use Pixonaut\WooCommerce\Templates;
use WP_UnitTestCase;

final class TemplateOverridesTest extends WP_UnitTestCase {

	/**
	 * A theme overriding `admin/notice.php` with an `@version` older than any
	 * the plugin ships, and `storefront/preview.php` without one.
	 */
	private const OUTDATED_THEME = 'outdated-theme';

	/**
	 * The theme path globals locate_template() reads, as they were before the test.
	 *
	 * @var array<string, mixed>
	 */
	private array $theme_globals = array();

	public function set_up(): void {
		parent::set_up();
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
		wp_cache_flush();
		parent::tear_down();
	}

	public function test_a_theme_without_overrides_has_none(): void {
		$this->assertSame( array(), $this->templates()->overrides() );
		$this->assertStringContainsString( '<td>&ndash;</td>', $this->status_report() );
	}

	public function test_the_override_is_rendered_and_listed(): void {
		$this->use_theme( self::OUTDATED_THEME );

		$html      = $this->templates()->html( 'admin/notice.php', array( 'message' => 'Hello' ) );
		$overrides = $this->templates()->overrides();

		$this->assertStringContainsString( '<p class="outdated-override">Hello</p>', $html );
		$this->assertSame( array( 'admin/notice.php', 'storefront/preview.php' ), array_map( static fn ( TemplateOverride $override ): string => $override->name, $overrides ) );
		$this->assertSame( $this->theme_dir( self::OUTDATED_THEME ) . '/' . Templates::THEME_PATH . 'admin/notice.php', $overrides[0]->file );
	}

	public function test_an_override_with_an_old_version_is_reported_outdated(): void {
		$this->use_theme( self::OUTDATED_THEME );
		$plugin_version = Templates::version( dirname( __DIR__, 3 ) . '/templates/admin/notice.php' );

		$notice = $this->templates()->overrides()[0];
		$html   = $this->status_report();

		$this->assertSame( '0.0.1', $notice->version );
		$this->assertSame( $plugin_version, $notice->plugin_version );
		$this->assertTrue( $notice->outdated() );
		$this->assertStringContainsString( 'woocommerce/pixonaut/admin/notice.php</code>', $html );
		$this->assertStringContainsString( 'Version <strong>0.0.1</strong> is out of date. The plugin ships version <strong>' . $plugin_version . '</strong>.', $html );
		$this->assertStringContainsString( 'Version header is missing.', $html );
		$this->assertStringContainsString( 'data-export-label="Outdated templates"', $html );
	}

	public function test_a_current_override_is_listed_without_a_warning(): void {
		$this->use_theme( 'theme' );
		$current = $this->templates()->overrides();

		$html = $this->status_report();

		$this->assertCount( 1, $current );
		$this->assertFalse( $current[0]->outdated(), 'Raise the @version of the fixture theme\'s admin/notice.php to the plugin\'s.' );
		$this->assertStringContainsString( 'woocommerce/pixonaut/admin/notice.php</code>', $html );
		$this->assertStringNotContainsString( 'out of date', $html );
		$this->assertStringNotContainsString( 'Outdated templates', $html );
	}

	public function test_the_section_prints_on_woocommerce_status(): void {
		( new StatusReport( $this->templates() ) )->register();
		$this->use_theme( self::OUTDATED_THEME );

		ob_start();
		do_action( 'woocommerce_system_status_report', array() ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- WooCommerce's hook.
		$html = (string) ob_get_clean();

		$this->assertStringContainsString( 'Pixonaut for WooCommerce templates', $html );
		$this->assertStringContainsString( 'is out of date', $html );
	}

	/**
	 * Makes the fixture theme the active theme's directory, as locate_template()
	 * sees it.
	 *
	 * @param string $theme Directory below `tests/php/fixtures/`.
	 */
	private function use_theme( string $theme ): void {
		$GLOBALS['wp_stylesheet_path'] = $this->theme_dir( $theme );
		wp_cache_flush();
	}

	private function theme_dir( string $theme ): string {
		return dirname( __DIR__ ) . '/fixtures/' . $theme;
	}

	private function status_report(): string {
		ob_start();
		( new StatusReport( $this->templates() ) )->render();
		return (string) ob_get_clean();
	}

	private function templates(): Templates {
		return Plugin::boot( dirname( __DIR__, 3 ) . '/pixonaut-for-woocommerce.php' )->templates();
	}
}
