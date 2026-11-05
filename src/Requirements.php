<?php
/**
 * Runtime requirements the plugin checks before it boots.
 *
 * @package Pixonaut\WooCommerce
 */

namespace Pixonaut\WooCommerce;

/**
 * Kept to PHP 7.2 syntax, the oldest PHP WordPress 6.9 runs on: it runs
 * before the PHP version is known to be supported. Bump MIN_PHP together with
 * `Requires PHP`, and MIN_WOOCOMMERCE with `WC requires at least`, in the
 * plugin header (`pnpm check:headers` compares them).
 */
final class Requirements {

	/**
	 * Lowest PHP version the plugin runs on.
	 */
	const MIN_PHP = '8.1';

	/**
	 * Lowest WooCommerce version the plugin runs on.
	 */
	const MIN_WOOCOMMERCE = '10.6';

	/**
	 * Whether the plugin can run on this PHP version.
	 *
	 * @param string $version A PHP version string, e.g. PHP_VERSION.
	 */
	public static function php_supported( string $version ): bool {
		return version_compare( $version, self::MIN_PHP, '>=' );
	}

	/**
	 * Whether WooCommerce has loaded. Call on `plugins_loaded` or later.
	 */
	public static function woocommerce_active(): bool {
		return did_action( 'woocommerce_loaded' ) > 0;
	}

	/**
	 * Whether the plugin can run on this WooCommerce version.
	 *
	 * @param string $version A WooCommerce version string, e.g. WC_VERSION.
	 */
	public static function woocommerce_supported( string $version ): bool {
		return version_compare( $version, self::MIN_WOOCOMMERCE, '>=' );
	}

	/**
	 * The version of the WooCommerce that loaded; empty before it loaded.
	 */
	public static function woocommerce_version(): string {
		return defined( 'WC_VERSION' ) ? (string) constant( 'WC_VERSION' ) : '';
	}

	/**
	 * Admin notice shown when PHP is too old.
	 */
	public static function php_notice(): void {
		self::print_notice(
			sprintf(
				/* translators: 1: required PHP version, 2: current PHP version. */
				__( 'Pixonaut for WooCommerce needs PHP %1$s or newer. This site runs PHP %2$s, so the plugin is not running.', 'pixonaut-for-woocommerce' ),
				self::MIN_PHP,
				PHP_VERSION
			)
		);
	}

	/**
	 * Admin notice shown when WooCommerce is not active.
	 */
	public static function woocommerce_notice(): void {
		self::print_notice( __( 'Pixonaut for WooCommerce needs WooCommerce. Activate WooCommerce to use it.', 'pixonaut-for-woocommerce' ) );
	}

	/**
	 * Admin notice shown when WooCommerce is older than the plugin needs.
	 */
	public static function woocommerce_version_notice(): void {
		self::print_notice(
			sprintf(
				/* translators: 1: required WooCommerce version, 2: installed WooCommerce version. */
				__( 'Pixonaut for WooCommerce needs WooCommerce %1$s or newer. This site runs WooCommerce %2$s, so the plugin is not running. Update WooCommerce to use it.', 'pixonaut-for-woocommerce' ),
				self::MIN_WOOCOMMERCE,
				self::woocommerce_version()
			)
		);
	}

	/**
	 * Prints an error notice.
	 *
	 * @param string $message Notice text.
	 */
	private static function print_notice( string $message ): void {
		printf( '<div class="notice notice-error"><p>%s</p></div>', esc_html( $message ) );
	}
}
