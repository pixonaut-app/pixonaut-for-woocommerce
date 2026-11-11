<?php
/**
 * @package Pixonaut\WooCommerce
 */

declare(strict_types=1);

namespace Pixonaut\WooCommerce\Tests\Unit\Catalogue;

use Pixonaut\WooCommerce\Catalogue\Measurements;
use Pixonaut\WooCommerce\Tests\Unit\TestCase;

final class MeasurementsTest extends TestCase {

	public function test_a_label_names_each_size_it_writes_in_mm(): void {
		$this->assertEqualsWithDelta( array( array( 711.2, 1016.0 ), array( 700.0, 1000.0 ) ), Measurements::of( '28x40" / 70x100cm' ), 0.01 );
		$this->assertSame( array( array( 210.0, 297.0 ) ), Measurements::of( 'Poster A4' ) );
		$this->assertEqualsWithDelta( array( array( 215.0, 279.4 ) ), Measurements::of( '21,5 × 27,94 cm' ), 0.01 );
		$this->assertEqualsWithDelta( array( array( 500.0, 700.0 ) ), Measurements::of( '0.5 m × 70 cm' ), 0.01, 'each number in its own unit' );
		$this->assertSame( array(), Measurements::of( 'Heather Forest' ) );
		$this->assertSame( array(), Measurements::of( '70x100' ) );
		$this->assertSame( array(), Measurements::of( '70x100 matte' ) );
	}

	public function test_labels_name_the_same_size_within_a_millimetre_either_way_round(): void {
		$this->assertTrue( Measurements::same( '70×100 cm', '28x40" / 70x100cm' ) );
		$this->assertTrue( Measurements::same( '100 x 70 cm', '700x1000mm' ) );
		$this->assertTrue( Measurements::same( '297 x 420 mm', 'A3' ) );
		$this->assertFalse( Measurements::same( '70×100 cm', '50x70cm' ) );
		$this->assertFalse( Measurements::same( 'M', 'M' ), 'Labels naming no size name none in common.' );
	}
}
