<?php
/**
 * The rules every hook the plugin fires follows; the generated hook reference
 * is built from these docblocks.
 *
 * @package Pixonaut\WooCommerce
 */

declare(strict_types=1);

namespace Pixonaut\WooCommerce\Tests\Unit\Docs;

use Pixonaut\WooCommerce\Tests\Support\Hooks\Hook;
use Pixonaut\WooCommerce\Tests\Support\Hooks\HookScanner;
use Pixonaut\WooCommerce\Tests\Unit\TestCase;

final class HookDocblocksTest extends TestCase {

	/**
	 * `pixonaut_wc_<area>_<name>`, where a name may hold `{$variable}` parts.
	 */
	private const HOOK_NAME = '/^pixonaut_wc_[a-z0-9]+_([a-z0-9_]|\{\$\w+\})+$/';

	/**
	 * A plugin version, e.g. `0.1.0`.
	 */
	private const VERSION = '/^\d+\.\d+\.\d+$/';

	/**
	 * The transport, which fires `HTTP_API_HOOKS`.
	 */
	private const TRANSPORT_FILE = 'src/Api/WpHttpTransport.php';

	/**
	 * WordPress's HTTP API hooks, which the transport fires for the requests
	 * it sends together as `wp_remote_request()` fires them for one.
	 */
	private const HTTP_API_HOOKS = array( 'http_request_args', 'http_headers_useragent', 'pre_http_request', 'http_request_reject_unsafe_urls', 'https_ssl_verify', 'http_api_debug', 'http_response' );

	/**
	 * @return array<string, array{Hook}>
	 */
	public static function plugin_hooks(): array {
		$hooks = array();
		foreach ( HookScanner::plugin_hooks( self::src() ) as $hook ) {
			$hooks[ "{$hook->name} ({$hook->file}:{$hook->line})" ] = array( $hook );
		}
		return $hooks;
	}

	/**
	 * @dataProvider plugin_hooks
	 *
	 * @param Hook $hook A hook the plugin fires.
	 */
	public function test_hook_is_named_and_documented( Hook $hook ): void {
		$this->assertMatchesRegularExpression( self::HOOK_NAME, $hook->name, 'Name hooks pixonaut_wc_<area>_<name>.' );
		$this->assertTrue( $hook->documented, 'Put a docblock right above the call.' );
		$this->assertNotSame( '', $hook->summary, 'Start the docblock with a description.' );
		$this->assertMatchesRegularExpression( self::VERSION, $hook->since, 'Add @since with the version that adds the hook.' );
		$this->assertNotSame( '', $hook->example, 'Add an @example.' );
		if ( $hook->deprecated ) {
			$this->assertNotSame( '', $hook->deprecation, 'Add @deprecated with the version and what to use instead.' );
		}
		if ( null !== $hook->args ) {
			$this->assertCount( $hook->args, $hook->params, 'Add a @param for each argument the hook passes.' );
		}
	}

	public function test_every_hook_name_in_src_is_the_plugins_own(): void {
		foreach ( HookScanner::scan_directory( self::src() ) as $hook ) {
			// A variable name is a hook passed through, e.g. by DeprecatedHooks.
			if ( self::TRANSPORT_FILE === $hook->file && in_array( $hook->name, self::HTTP_API_HOOKS, true ) ) {
				continue;
			}
			if ( ! str_starts_with( $hook->name, '$' ) ) {
				$this->assertStringStartsWith( HookScanner::PREFIX, $hook->name, "{$hook->file}:{$hook->line}" );
			}
		}
	}

	private static function src(): string {
		return dirname( __DIR__, 4 ) . '/src';
	}
}
