<?php
/**
 * @package Pixonaut\WooCommerce
 */

declare(strict_types=1);

namespace Pixonaut\WooCommerce\Tests\Unit\Api;

use Pixonaut\WooCommerce\Tests\Unit\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

/**
 * Only `Api` talks to Pixonaut, and inside it only the transport makes HTTP
 * requests.
 */
final class HttpBoundaryTest extends TestCase {

	private const PLUGIN_ROOT = __DIR__ . '/../../../..';

	private const TRANSPORT = 'src/Api/WpHttpTransport.php';

	/**
	 * Calls that make an HTTP request from PHP. `WP_Http` counts only when
	 * constructed: its status code constants are not requests.
	 */
	private const HTTP_CALLS = '/\b(wp_(safe_)?remote_(request|get|post|head)|_wp_http_get_object|curl_init|fsockopen|stream_socket_client)\b|\bnew\s+\\\\?WP_Http\b/';

	public function test_only_the_transport_makes_http_requests(): void {
		$offenders = array();
		foreach ( $this->php_files() as $file ) {
			if ( self::TRANSPORT === $file ) {
				continue;
			}
			if ( 1 === preg_match( self::HTTP_CALLS, (string) file_get_contents( self::PLUGIN_ROOT . '/' . $file ) ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- local file.
				$offenders[] = $file;
			}
		}

		$this->assertSame( array(), $offenders, 'Call Pixonaut through the SDK, not directly.' );
	}

	/**
	 * @return array<string, array{string, bool}>
	 */
	public static function snippets(): array {
		return array(
			'constructed'              => array( '$http = new WP_Http();', true ),
			'constructed, fully named' => array( '$http = new \\WP_Http();', true ),
			'a remote request'         => array( 'wp_remote_get( $url );', true ),
			'a status code constant'   => array( "array( 'status' => WP_Http::CONFLICT )", false ),
			'imported'                 => array( 'use WP_Http;', false ),
		);
	}

	/**
	 * @dataProvider snippets
	 *
	 * @param string $code    PHP source.
	 * @param bool   $request Whether it makes an HTTP request.
	 */
	public function test_the_check_tells_requests_from_status_constants( string $code, bool $request ): void {
		$matches = 1 === preg_match( self::HTTP_CALLS, $code );

		$this->assertSame( $request, $matches );
	}

	public function test_the_transport_is_where_the_check_looks(): void {
		$this->assertMatchesRegularExpression( self::HTTP_CALLS, (string) file_get_contents( self::PLUGIN_ROOT . '/' . self::TRANSPORT ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- local file.
	}

	/**
	 * Plugin PHP outside the tests and vendor.
	 *
	 * @return list<string> Paths relative to the plugin root.
	 */
	private function php_files(): array {
		$files = array( 'pixonaut-for-woocommerce.php', 'uninstall.php' );
		$src   = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( self::PLUGIN_ROOT . '/src', RecursiveDirectoryIterator::SKIP_DOTS ) );
		foreach ( $src as $file ) {
			if ( 'php' === $file->getExtension() ) {
				$files[] = 'src/' . substr( $file->getPathname(), strlen( self::PLUGIN_ROOT . '/src/' ) );
			}
		}
		return $files;
	}
}
