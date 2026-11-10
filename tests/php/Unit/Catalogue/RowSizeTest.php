<?php
/**
 * @package Pixonaut\WooCommerce
 */

declare(strict_types=1);

namespace Pixonaut\WooCommerce\Tests\Unit\Catalogue;

use Brain\Monkey\Functions;
use Pixonaut\WooCommerce\Catalogue\InvalidReason;
use Pixonaut\WooCommerce\Catalogue\PrintSize;
use Pixonaut\WooCommerce\Catalogue\RowSize;
use Pixonaut\WooCommerce\Tests\Unit\TestCase;

final class RowSizeTest extends TestCase {

	/**
	 * A 30 × 40 poster's print area, its usual box a little inside it.
	 */
	private const AREA = array(
		'widthMm'       => 300,
		'heightMm'      => 400,
		'usualWidthMm'  => 280,
		'usualHeightMm' => 380,
	);

	protected function setUp(): void {
		parent::setUp();
		Functions\when( '__' )->returnArg();
		Functions\when( 'wp_json_encode' )->alias( 'json_encode' );
	}

	public function test_a_print_covering_the_area_whole_is_the_area_whatever_the_templates_shape(): void {
		$size = RowSize::fitted( self::AREA, true, 1, 1 );

		$this->assertSame( array( 300.0, 400.0, true ), array( $size?->width_mm, $size?->height_mm, $size?->placement_default ) );
	}

	public function test_a_print_within_the_area_is_the_template_fitted_into_the_usual_box(): void {
		$square = RowSize::fitted( self::AREA, false, 1, 1 );
		$wide   = RowSize::fitted( self::AREA, false, 2, 1 );

		$this->assertSame( array( 280.0, 280.0 ), array( $square?->width_mm, $square?->height_mm ) );
		$this->assertSame( array( 280.0, 140.0 ), array( $wide?->width_mm, $wide?->height_mm ) );
	}

	public function test_an_area_without_a_size_gives_none(): void {
		$this->assertNull( RowSize::fitted( array( 'widthMm' => 0 ) + self::AREA, false, 1, 1 ) );
	}

	public function test_a_size_is_refused_larger_than_the_area_or_other_than_an_area_printed_whole(): void {
		$within = new RowSize( array( 'Size' => '30x40' ), array( 'v_small' ), 300, 400, false, null );
		$full   = new RowSize( array( 'Size' => '30x40' ), array( 'v_small' ), 300, 400, true, null );

		$this->assertSame( InvalidReason::TOO_LARGE, $within->refuses( new PrintSize( 301, 200, '' ) ) );
		$this->assertNull( $within->refuses( new PrintSize( 210, 297, '' ) ) );
		$this->assertSame( InvalidReason::PRINT_SIZE_NOT_AREA, $full->refuses( new PrintSize( 210, 297, '' ) ) );
		$this->assertNull( $full->refuses( new PrintSize( 300, 400, '' ) ) );
	}

	public function test_a_row_survives_being_stored(): void {
		$row = new RowSize( array( 'Size' => '30x40' ), array( 'v_small', 'v_small_white' ), 300, 406.4, true, new PrintSize( 300, 406.4, '300 × 406.4 mm', true ) );

		$this->assertEquals( $row, RowSize::from_array( json_decode( (string) wp_json_encode( $row->to_array() ), true ) ) );
		$this->assertTrue( $row->holds( 'v_small_white' ) );
		$this->assertFalse( $row->holds( null ) );
	}
}
