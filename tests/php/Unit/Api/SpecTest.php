<?php
/**
 * @package Pixonaut\WooCommerce
 */

declare(strict_types=1);

namespace Pixonaut\WooCommerce\Tests\Unit\Api;

use Pixonaut\WooCommerce\Api\Spec;
use Pixonaut\WooCommerce\Tests\Unit\TestCase;

final class SpecTest extends TestCase {

	public function test_the_version_is_the_pinned_one(): void {
		$lock = json_decode( (string) file_get_contents( dirname( __DIR__, 4 ) . '/contracts/pixonaut/spec.lock.json' ), true ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		$this->assertIsArray( $lock );

		$this->assertSame( $lock['version'], Spec::VERSION );
	}
}
