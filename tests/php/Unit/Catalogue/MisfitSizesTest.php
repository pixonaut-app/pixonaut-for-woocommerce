<?php
/**
 * @package Pixonaut\WooCommerce
 */

declare(strict_types=1);

namespace Pixonaut\WooCommerce\Tests\Unit\Catalogue;

use Pixonaut\WooCommerce\Api\ApiError;
use Pixonaut\WooCommerce\Api\ErrorCodes;
use Pixonaut\WooCommerce\Api\Operations;
use Pixonaut\WooCommerce\Catalogue\MisfitSizes;
use Pixonaut\WooCommerce\Tests\Unit\TestCase;

final class MisfitSizesTest extends TestCase {

	private const UNPROCESSABLE = 422;

	public function test_misfits_are_read_from_pixonauts_sizes_and_none_without_them(): void {
		$this->assertSame(
			array( 'XL', 'XL / Long' ),
			MisfitSizes::of(
				array(
					'fits'    => array( 'S' ),
					'misfits' => array( 'XL', 'XL / Long' ),
				)
			)
		);
		$this->assertSame( array(), MisfitSizes::of( null ) );
		$this->assertSame( array(), MisfitSizes::of( array( 'misfits' => 'XL' ) ) );
	}

	public function test_a_row_is_named_as_pixonaut_names_it(): void {
		$this->assertSame( 'XL', MisfitSizes::value( array( 'Size' => 'XL' ) ) );
		$this->assertSame(
			'XL / Long',
			MisfitSizes::value(
				array(
					'Size'   => 'XL',
					'Length' => 'Long',
				)
			)
		);
	}

	public function test_only_a_violation_of_the_print_size_is_a_misfit(): void {
		$this->assertTrue( MisfitSizes::refused( self::violation( 'wrong_ratio' ) ) );
		$this->assertTrue( MisfitSizes::refused( self::violation( 'too_large' ) ) );
		$this->assertFalse( MisfitSizes::refused( self::violation( 'required' ) ) );
		$this->assertFalse( MisfitSizes::refused( new ApiError( Operations::FIND_OR_CREATE_STANDING_DESIGN, self::UNPROCESSABLE, ErrorCodes::CONSTRAINT_VIOLATION, false ) ) );
		$this->assertFalse( MisfitSizes::refused( new ApiError( Operations::FIND_OR_CREATE_STANDING_DESIGN, self::UNPROCESSABLE, ErrorCodes::VARIANT_REQUIRED, false, null, array( 'violations' => array( array( 'rule' => 'wrong_ratio' ) ) ) ) ) );
	}

	public function test_no_misfit_needs_no_words(): void {
		$this->assertSame( '', MisfitSizes::note( array() ) );
		$this->assertSame( '', MisfitSizes::warning( array() ) );
	}

	private static function violation( string $rule ): ApiError {
		return new ApiError(
			Operations::FIND_OR_CREATE_STANDING_DESIGN,
			self::UNPROCESSABLE,
			ErrorCodes::CONSTRAINT_VIOLATION,
			false,
			null,
			array(
				'violations' => array(
					array(
						'layerId' => '',
						'rule'    => $rule,
					),
				),
			)
		);
	}
}
