<?php
/**
 * @package Pixonaut\WooCommerce
 */

declare(strict_types=1);

namespace Pixonaut\WooCommerce\Tests\Unit\Pricing;

use Pixonaut\WooCommerce\Pricing\DeliveryTimes;
use Pixonaut\WooCommerce\Tests\Unit\TestCase;

final class DeliveryTimesTest extends TestCase {

	public function test_each_span_the_printshop_answers_is_read_and_one_it_does_not_is_none(): void {
		$times = DeliveryTimes::from_array(
			array(
				'production' => self::span( 2, 3 ),
				'shipping'   => null,
				'delivery'   => self::span( 4, 7 ),
			)
		);

		$this->assertSame( array( 2, 3 ), $times->production );
		$this->assertNull( $times->shipping );
		$this->assertSame( array( 4, 7 ), $times->delivery );
		$this->assertFalse( $times->none() );
	}

	public function test_a_span_that_is_no_two_whole_day_counts_shortest_first_is_none(): void {
		$times = DeliveryTimes::from_array(
			array(
				'production' => self::span( 3, 2 ),
				'shipping'   => self::span( -1, 4 ),
				'delivery'   => array(
					'minDays' => '4',
					'maxDays' => 7,
				),
			)
		);

		$this->assertTrue( $times->none() );
		$this->assertTrue( DeliveryTimes::from_array( 'soon' )->none() );
	}

	public function test_a_span_longer_than_the_most_days_held_is_none(): void {
		$times = DeliveryTimes::from_array(
			array(
				'production' => self::span( 1, DeliveryTimes::MAX_DAYS ),
				'shipping'   => self::span( 1, DeliveryTimes::MAX_DAYS + 1 ),
				'delivery'   => self::span( PHP_INT_MAX, PHP_INT_MAX ),
			)
		);

		$this->assertSame( array( 1, DeliveryTimes::MAX_DAYS ), $times->production );
		$this->assertNull( $times->shipping );
		$this->assertNull( $times->delivery );
	}

	public function test_the_whole_way_is_the_span_told_for_it_before_any_sum(): void {
		$times = new DeliveryTimes( array( 2, 3 ), array( 3, 6 ), array( 4, 7 ) );

		$this->assertSame( array( 4, 7 ), $times->to_door() );
	}

	public function test_the_whole_way_is_the_making_and_the_post_together_when_both_are_told(): void {
		$this->assertSame( array( 5, 9 ), ( new DeliveryTimes( array( 2, 3 ), array( 3, 6 ) ) )->to_door() );
	}

	public function test_a_part_alone_is_not_the_whole_way(): void {
		$this->assertNull( ( new DeliveryTimes( array( 2, 3 ) ) )->to_door() );
		$this->assertNull( ( new DeliveryTimes( null, array( 3, 6 ) ) )->to_door() );
		$this->assertNull( ( new DeliveryTimes() )->to_door() );
	}

	public function test_the_times_are_written_as_the_answer_writes_them(): void {
		$times = new DeliveryTimes( null, array( 3, 6 ), array( 4, 7 ) );

		$this->assertSame(
			array(
				'production' => null,
				'shipping'   => self::span( 3, 6 ),
				'delivery'   => self::span( 4, 7 ),
			),
			$times->to_array()
		);
		$this->assertEquals( $times, DeliveryTimes::from_array( $times->to_array() ) );
	}

	/**
	 * A span as the answer writes it.
	 *
	 * @param int $min The shortest.
	 * @param int $max The longest.
	 * @return array{minDays: int, maxDays: int}
	 */
	private static function span( int $min, int $max ): array {
		return array(
			'minDays' => $min,
			'maxDays' => $max,
		);
	}
}
