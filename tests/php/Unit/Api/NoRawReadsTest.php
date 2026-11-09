<?php
/**
 * @package Pixonaut\WooCommerce
 */

declare(strict_types=1);

namespace Pixonaut\WooCommerce\Tests\Unit\Api;

use Pixonaut\WooCommerce\Tests\Unit\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

/**
 * The store reads Pixonaut's answers through the answer models in
 * `Api\Answers`, never as the SDK model's `raw()` array, whose fields
 * PHPStan cannot check.
 */
final class NoRawReadsTest extends TestCase {

	public function test_no_code_reads_an_sdk_models_raw_array(): void {
		$found = array();
		$files = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( dirname( __DIR__, 4 ) . '/src' ) );
		foreach ( $files as $file ) {
			if ( ! $file instanceof SplFileInfo || 'php' !== $file->getExtension() ) {
				continue;
			}
			foreach ( (array) file( $file->getPathname() ) as $index => $line ) {
				if ( is_string( $line ) && str_contains( $line, '->raw()' ) ) {
					$found[] = $file->getFilename() . ':' . ( $index + 1 );
				}
			}
		}

		$this->assertSame( array(), $found, 'Read the answer through an answer model in Api\\Answers instead.' );
	}
}
