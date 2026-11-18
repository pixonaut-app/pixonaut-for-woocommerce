<?php
/**
 * @package Pixonaut\WooCommerce
 */

declare(strict_types=1);

namespace Pixonaut\WooCommerce\Tests\Unit\Pricing;

use Pixonaut\WooCommerce\Pricing\Amount;
use Pixonaut\WooCommerce\Tests\Unit\TestCase;

final class AmountTest extends TestCase {

	public function test_reads_a_decimal_string_as_thousandths(): void {
		$this->assertSame( 167100, Amount::parse( '167.10' ) );
		$this->assertSame( 167100, Amount::parse( '167,1' ) );
		$this->assertSame( 399000, Amount::parse( ' 399 ' ) );
		$this->assertSame( 1, Amount::parse( '0.0005' ), 'the fourth decimal rounds half up' );
		$this->assertSame( 0, Amount::parse( '0.0004' ) );
		$this->assertSame( 12500, Amount::parse( 12.5 ) );
		$this->assertSame( 12000, Amount::parse( 12 ) );
	}

	public function test_anything_else_is_no_amount(): void {
		foreach ( array( '', '-1', '1.2.3', 'abc', '1e3', null, array(), true ) as $written ) {
			$this->assertNull( Amount::parse( $written ) );
		}
	}

	public function test_writes_thousandths_without_trailing_zeros(): void {
		$this->assertSame( '399', Amount::decimal( 399000 ) );
		$this->assertSame( '417.5', Amount::decimal( 417500 ) );
		$this->assertSame( '0.005', Amount::decimal( 5 ) );
		$this->assertSame( 399000, Amount::parse( Amount::decimal( 399000 ) ) );
	}

	public function test_writes_a_negative_amount_with_a_minus(): void {
		$this->assertSame( '-10.5', Amount::decimal( -10500 ) );
		$this->assertSame( '-0.5', Amount::decimal( -500 ) );
		$this->assertSame( '-10', Amount::decimal( -10000 ) );
	}
}
