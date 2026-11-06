<?php
/**
 * @package Pixonaut\WooCommerce
 */

declare(strict_types=1);

namespace Pixonaut\WooCommerce\Tests\Unit\Api;

use Pixonaut\WooCommerce\Tests\Unit\TestCase;
use Pixonaut\WooCommerce\Vendor\Pixonaut\Sdk\Generated\ApiVersion;

/**
 * The SDK the plugin ships is built for the API release the plugin pins, so
 * the plugin never runs an SDK built for another release.
 */
final class ApiVersionTest extends TestCase {

	public function test_the_sdk_targets_the_pinned_api_release(): void {
		$lock = self::lock();

		$this->assertSame( $lock['version'], ApiVersion::TARGET );
		$this->assertSame( $lock['sha256'], ApiVersion::SPEC_SHA256 );
	}

	/**
	 * @return array{version: string, sha256: string}
	 */
	private static function lock(): array {
		$lock = json_decode( (string) file_get_contents( dirname( __DIR__, 4 ) . '/contracts/pixonaut/spec.lock.json' ), true ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- local file.
		self::assertIsArray( $lock );
		self::assertIsString( $lock['version'] ?? null );
		self::assertIsString( $lock['sha256'] ?? null );
		return $lock;
	}
}
