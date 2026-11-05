<?php
/**
 * Where Pixonaut is.
 *
 * @package Pixonaut\WooCommerce
 */

declare(strict_types=1);

namespace Pixonaut\WooCommerce\Api;

use Closure;
use Pixonaut\WooCommerce\Settings\Settings;

/**
 * Pixonaut's addresses. Each is overridable in the developer tools (a local
 * or staging Pixonaut), and a constant in `wp-config.php` wins over that
 * (the fake the tests run against, or a host fixed for the site).
 */
final class Hosts {

	public const API = 'https://api.pixonaut.app';

	public const STUDIO = 'https://studio.pixonaut.app';

	/**
	 * Where the pinned SDK release is served from.
	 */
	public const CDN = 'https://cdn.pixonaut.app';

	public const API_CONSTANT = 'PIXONAUT_WC_API_BASE';

	public const SERVER_API_CONSTANT = 'PIXONAUT_WC_SERVER_API_BASE';

	public const STUDIO_CONSTANT = 'PIXONAUT_WC_STUDIO_BASE';

	public const CDN_CONSTANT = 'PIXONAUT_WC_CDN_BASE';

	/**
	 * Each overridable address's constant, by its setting.
	 */
	public const CONSTANTS = array(
		Settings::API_BASE        => self::API_CONSTANT,
		Settings::SERVER_API_BASE => self::SERVER_API_CONSTANT,
		Settings::STUDIO_BASE     => self::STUDIO_CONSTANT,
		Settings::CDN_BASE        => self::CDN_CONSTANT,
	);

	/**
	 * `fn( string $name ): string`, a constant's value, empty when it is not
	 * set; null reads the real constants.
	 *
	 * @var Closure|null
	 */
	private static ?Closure $constants = null;

	/**
	 * The API as the browser reaches it: the SDK's `apiBase`.
	 */
	public static function api(): string {
		return self::resolve( Settings::API_BASE, self::API );
	}

	/**
	 * The API as WordPress itself reaches it, when that differs from the
	 * browser's address (WordPress inside Docker, say).
	 */
	public static function server_api(): string {
		return self::resolve( Settings::SERVER_API_BASE, self::api() );
	}

	/**
	 * The studio, where a member approves a connect.
	 */
	public static function studio(): string {
		return self::resolve( Settings::STUDIO_BASE, self::STUDIO );
	}

	/**
	 * The CDN the pinned SDK release loads from. Its copy of the release is
	 * the same file, so the release's integrity holds there too.
	 */
	public static function cdn(): string {
		return self::resolve( Settings::CDN_BASE, self::CDN );
	}

	/**
	 * The constant that fixes an address, or null when none is set and the
	 * developer tools may override it.
	 *
	 * @param string $setting A key of CONSTANTS.
	 */
	public static function locked_by( string $setting ): ?string {
		$name = self::CONSTANTS[ $setting ] ?? null;
		return null !== $name && '' !== self::constant( $name ) ? $name : null;
	}

	/**
	 * The addresses that are not Pixonaut's own, from a constant or the
	 * developer tools, that the store's key is sent to. The CDN is not
	 * among them: it is sent no key.
	 *
	 * @return list<string>
	 */
	public static function custom(): array {
		$hosts = array();
		foreach ( array( self::server_api(), self::api() ) as $api ) {
			if ( self::API !== $api ) {
				$hosts[] = $api;
			}
		}
		if ( self::STUDIO !== self::studio() ) {
			$hosts[] = self::studio();
		}
		return array_values( array_unique( $hosts ) );
	}

	/**
	 * Reads the constants through `$constants` instead, for tests whose
	 * site defines the real ones; null reads the real ones again.
	 *
	 * @internal
	 *
	 * @param Closure|null $constants `fn( string $name ): string`, empty for a constant not set.
	 */
	public static function read_constants_with( ?Closure $constants ): void {
		self::$constants = $constants;
	}

	/**
	 * @param string $setting  A key of CONSTANTS.
	 * @param string $fallback Used when neither the constant nor the setting is set.
	 */
	private static function resolve( string $setting, string $fallback ): string {
		$constant = self::constant( self::CONSTANTS[ $setting ] );
		if ( '' !== $constant ) {
			return untrailingslashit( $constant );
		}
		$override = ( new Settings() )->get( $setting );
		return is_string( $override ) && '' !== $override ? $override : $fallback;
	}

	/**
	 * @param string $name Constant name.
	 * @return string Its value; empty when it is not set.
	 */
	private static function constant( string $name ): string {
		if ( null !== self::$constants ) {
			return (string) ( self::$constants )( $name );
		}
		return defined( $name ) ? (string) constant( $name ) : '';
	}
}
