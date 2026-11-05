<?php
/**
 * @package Pixonaut\WooCommerce
 */

declare(strict_types=1);

namespace Pixonaut\WooCommerce\Tests\Unit\Storefront;

use Pixonaut\WooCommerce\Api\Hosts;
use Pixonaut\WooCommerce\Storefront\Sdk;
use Pixonaut\WooCommerce\Tests\Unit\TestCase;

final class SdkTest extends TestCase {

	public function test_the_constants_are_the_pinned_release(): void {
		$lock = json_decode( (string) file_get_contents( dirname( __DIR__, 4 ) . '/contracts/pixonaut/sdk.lock.json' ), true ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		$this->assertIsArray( $lock );

		$this->assertSame( $lock['version'], Sdk::VERSION );
		$this->assertSame( $lock['baseUrl'] . $lock['script'], Sdk::SCRIPT_URL );
		$this->assertSame( Hosts::CDN . Sdk::SCRIPT_PATH, Sdk::SCRIPT_URL );
		$this->assertSame( $lock['integrity'], Sdk::INTEGRITY );
	}

	public function test_the_script_url_carries_no_query(): void {
		$this->assertStringNotContainsString( '?', Sdk::SCRIPT_URL );
	}
}
