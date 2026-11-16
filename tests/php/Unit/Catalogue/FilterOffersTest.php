<?php
/**
 * Tests for the rule matching the shop's filters against one offer.
 *
 * @package Pixonaut\WooCommerce
 */

declare(strict_types=1);

namespace Pixonaut\WooCommerce\Tests\Unit\Catalogue;

use Pixonaut\WooCommerce\Catalogue\FilterOffers;
use Pixonaut\WooCommerce\Tests\Unit\TestCase;

final class FilterOffersTest extends TestCase {

	private const INGEN = 1;

	private const RAM = 2;

	private const EK = 10;

	private const VIT = 11;

	private const SMALL = 20;

	private const LARGE = 21;

	public function test_an_offer_matches_when_it_holds_a_chosen_value_of_every_filtered_attribute(): void {
		$framed = self::offer( array( self::RAM ), array( self::EK, self::VIT ), 300.0 );

		$this->assertTrue( FilterOffers::matches( $framed, array(), null, null ) );
		$this->assertTrue( FilterOffers::matches( $framed, array( 'pa_ramfarg' => array( self::EK ) ), null, null ), 'a colour left open holds each' );
		$this->assertTrue( FilterOffers::matches( $framed, array( 'pa_inramning' => array( self::INGEN, self::RAM ) ), null, null ), 'any value within one attribute' );
		$this->assertFalse(
			FilterOffers::matches(
				$framed,
				array(
					'pa_inramning' => array( self::INGEN ),
					'pa_ramfarg'   => array( self::EK ),
				),
				null,
				null
			)
		);
		$this->assertFalse( FilterOffers::matches( $framed, array( 'pa_ramfarg' => array() ), null, null ), 'a filter naming no term' );
		$this->assertFalse( FilterOffers::matches( $framed, array( 'pa_storlek' => array( self::SMALL ) ), null, null ), 'an attribute the product does not have' );
	}

	public function test_an_attribute_that_does_not_apply_matches_no_filter_on_it(): void {
		$bare = self::offer( array( self::INGEN ), array(), 200.0 );

		$this->assertTrue( FilterOffers::matches( $bare, array( 'pa_inramning' => array( self::INGEN ) ), null, null ) );
		$this->assertFalse( FilterOffers::matches( $bare, array( 'pa_ramfarg' => array( self::EK ) ), null, null ) );
	}

	public function test_an_offer_matches_a_price_range_its_price_falls_in(): void {
		$small = self::offer( array( self::RAM ), array( self::EK ), 299.0 );

		$this->assertTrue( FilterOffers::matches( $small, array(), 299.0, 391.0 ), 'the bounds count' );
		$this->assertTrue( FilterOffers::matches( $small, array(), null, 299.0 ) );
		$this->assertFalse( FilterOffers::matches( $small, array(), 300.0, null ) );
		$this->assertFalse( FilterOffers::matches( $small, array(), null, 250.0 ) );
		$this->assertFalse( FilterOffers::matches( $small, array( 'pa_ramfarg' => array( self::VIT ) ), 299.0, 391.0 ), 'price and values at once' );
	}

	/**
	 * @param int[] $framing Framing values it holds.
	 * @param int[] $colours Frame colours it holds.
	 * @param float $price   Its price.
	 * @phpstan-param list<int> $framing
	 * @phpstan-param list<int> $colours
	 * @return array{id: int, values: array<string, list<int>>, min: float, max: float}
	 */
	private static function offer( array $framing, array $colours, float $price ): array {
		return array(
			'id'     => 1,
			'values' => array(
				'pa_inramning' => $framing,
				'pa_ramfarg'   => $colours,
				'pa_storlek'   => array( self::LARGE ),
			),
			'min'    => $price,
			'max'    => $price,
		);
	}
}
