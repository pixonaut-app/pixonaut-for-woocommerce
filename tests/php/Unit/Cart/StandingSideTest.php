<?php
/**
 * @package Pixonaut\WooCommerce
 */

declare(strict_types=1);

namespace Pixonaut\WooCommerce\Tests\Unit\Cart;

use Pixonaut\WooCommerce\Cart\StandingSide;
use Pixonaut\WooCommerce\Tests\Unit\TestCase;

final class StandingSideTest extends TestCase {

	public function test_each_standing_design_goes_to_its_place_among_the_committed_ones(): void {
		$standing = array( new StandingSide( 'sleeve', 'des_sleeve', 2 ), new StandingSide( 'front', 'des_front', 0 ) );

		$this->assertSame( array( 'des_front', 'd_back', 'des_sleeve' ), StandingSide::in_side_order( array( 'd_back' ), $standing ) );
		$this->assertSame( array( 'des_front', 'des_sleeve' ), StandingSide::in_side_order( array(), $standing ) );
		$this->assertSame( array( 'd_front', 'd_back' ), StandingSide::in_side_order( array( 'd_front', 'd_back' ), array() ) );
	}

	public function test_round_trips_and_refuses_a_side_without_its_design(): void {
		$side = new StandingSide( 'front', 'des_front', 0 );

		$this->assertEquals( $side, StandingSide::from_array( $side->to_array() ) );
		$this->assertNull(
			StandingSide::from_array(
				array(
					'key'      => 'front',
					'position' => 0,
				)
			)
		);
		$this->assertNull(
			StandingSide::from_array(
				array(
					'key'      => 'front',
					'designId' => 'des_front',
					'position' => -1,
				)
			)
		);
	}
}
