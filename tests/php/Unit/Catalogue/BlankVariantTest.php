<?php
/**
 * @package Pixonaut\WooCommerce
 */

declare(strict_types=1);

namespace Pixonaut\WooCommerce\Tests\Unit\Catalogue;

use Pixonaut\WooCommerce\Catalogue\BlankVariant;
use Pixonaut\WooCommerce\Tests\Unit\TestCase;

final class BlankVariantTest extends TestCase {

	public function test_options_name_a_variant_by_every_axis_case_and_spacing_aside(): void {
		$variant = new BlankVariant(
			'v_red_m',
			array(
				'Färg'    => 'Röd',
				'Storlek' => 'M',
			),
			written: array(
				'Colour' => 'Red',
				'Size'   => 'M',
			)
		);

		$this->assertTrue(
			$variant->is_named_by(
				array(
					'storlek ' => 'm',
					'Färg'     => ' RÖD',
				)
			)
		);
		$this->assertTrue(
			$variant->is_named_by(
				array(
					'Colour' => 'red',
					'Size'   => 'M',
				)
			),
			'as written in Pixonaut'
		);
		$this->assertFalse(
			$variant->is_named_by(
				array(
					'Colour'  => 'Red',
					'Storlek' => 'M',
				)
			),
			'not half in each language'
		);
		$this->assertFalse( $variant->is_named_by( array( 'Färg' => 'Röd' ) ), 'an axis left out' );
		$this->assertFalse(
			$variant->is_named_by(
				array(
					'Färg'    => 'Röd',
					'Storlek' => 'M',
					'Ask'     => 'Ja',
				)
			),
			'an axis too many'
		);
	}

	public function test_no_options_name_a_variant_without_any(): void {
		$this->assertTrue( ( new BlankVariant( 'v_one' ) )->is_named_by( array() ) );
		$this->assertFalse( ( new BlankVariant( 'v_one' ) )->is_named_by( array( 'Size' => 'M' ) ) );
	}
}
