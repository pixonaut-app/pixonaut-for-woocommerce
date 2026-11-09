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

	/**
	 * References of the pinned release's version that an SDK release was
	 * built from although no API release publishes them, by sha256, with why.
	 * The plugin pins published releases only, so its pin stays behind until
	 * one does.
	 */
	private const UNPUBLISHED_SDK_SPECS = array(
		// pixonaut-php 2.0.0: the reference the API answers since blanks lost `photoSource`, still declaring 1.0.0.
		'f583c41e39449564e63d2fd3f727609734f0ba2de4cb5e5e1d7fb090b17ad033' => '1.0.0',
	);

	public function test_the_sdk_targets_the_pinned_api_release(): void {
		$lock = self::lock();

		$this->assertSame( $lock['version'], ApiVersion::TARGET );
		if ( ApiVersion::SPEC_SHA256 !== $lock['sha256'] ) {
			$this->assertSame(
				$lock['version'],
				self::unpublished_version( ApiVersion::SPEC_SHA256 ),
				'The SDK is built from another spec than the pinned one, and not from a listed unpublished reference of its version.'
			);
		}
	}

	/**
	 * The version a listed unpublished reference declares, or null when it is not listed.
	 *
	 * @param string $sha256 The reference's sha256.
	 */
	private static function unpublished_version( string $sha256 ): ?string {
		return self::UNPUBLISHED_SDK_SPECS[ $sha256 ] ?? null;
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
