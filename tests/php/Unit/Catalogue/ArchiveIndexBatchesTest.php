<?php
/**
 * @package Pixonaut\WooCommerce
 */

declare(strict_types=1);

namespace Pixonaut\WooCommerce\Tests\Unit\Catalogue;

use Pixonaut\WooCommerce\Catalogue\ArchiveIndexBuild;
use Pixonaut\WooCommerce\Tests\Unit\TestCase;

/**
 * An archive's products are read in batches small enough to fit in memory.
 */
final class ArchiveIndexBatchesTest extends TestCase {

	/**
	 * Products without variations one batch holds at most.
	 */
	private const PRODUCTS_PER_BATCH = 50;

	public function test_products_without_variations_are_read_fifty_at_a_time(): void {
		$ids = range( 1, self::PRODUCTS_PER_BATCH + 1 );

		$batches = ArchiveIndexBuild::batches( $ids, array() );

		$this->assertSame( array( range( 1, self::PRODUCTS_PER_BATCH ), array( self::PRODUCTS_PER_BATCH + 1 ) ), $batches );
	}

	public function test_products_with_many_variations_are_read_a_few_at_a_time_in_their_order(): void {
		$half = intdiv( ArchiveIndexBuild::BATCH_VARIATIONS, 2 );

		$batches = ArchiveIndexBuild::batches(
			array( 7, 3, 9, 4 ),
			array(
				7 => $half,
				3 => $half,
				9 => $half + 1,
				4 => 1,
			)
		);

		$this->assertSame( array( array( 7, 3 ), array( 9, 4 ) ), $batches );
	}

	public function test_a_product_with_more_variations_than_a_batch_is_read_alone(): void {
		$batches = ArchiveIndexBuild::batches(
			array( 1, 2, 3 ),
			array( 2 => ArchiveIndexBuild::BATCH_VARIATIONS * 3 )
		);

		$this->assertSame( array( array( 1 ), array( 2 ), array( 3 ) ), $batches );
	}
}
