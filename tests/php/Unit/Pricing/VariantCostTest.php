<?php
/**
 * @package Pixonaut\WooCommerce
 */

declare(strict_types=1);

namespace Pixonaut\WooCommerce\Tests\Unit\Pricing;

use Pixonaut\WooCommerce\Pricing\VariantCost;
use Pixonaut\WooCommerce\Tests\Unit\TestCase;

final class VariantCostTest extends TestCase {

	public function test_a_cost_is_the_product_and_its_shipping(): void {
		$costs = VariantCost::list_from( array( self::row( 'v_a3', '167.10', '65.50' ) ) );

		$this->assertSame( array( 'v_a3' ), array_keys( $costs ) );
		$this->assertSame( 232600, $costs['v_a3']->total( 'SEK' ) );
		$this->assertNull( $costs['v_a3']->problem( 'SEK' ) );
		$this->assertSame( 'SE', $costs['v_a3']->region );
	}

	public function test_a_variant_without_shipping_costs_its_product(): void {
		$cost = VariantCost::list_from( array( self::row( 'v_a3', '167.10', null ) ) )['v_a3'];

		$this->assertSame( 167100, $cost->total( 'SEK' ) );
	}

	public function test_a_variant_the_printshop_publishes_no_price_for_has_no_cost(): void {
		$cost = VariantCost::list_from( array( self::row( 'v_a3', null, '65.50' ) ) )['v_a3'];

		$this->assertSame( VariantCost::NO_PRICE, $cost->problem( 'SEK' ) );
		$this->assertNull( $cost->total( 'SEK' ) );
	}

	public function test_a_cost_in_another_currency_is_never_converted(): void {
		$cost = VariantCost::list_from( array( self::row( 'v_a3', '16.71', '6.55', 'EUR' ) ) )['v_a3'];

		$this->assertSame( VariantCost::OTHER_CURRENCY, $cost->problem( 'SEK' ) );
		$this->assertNull( $cost->total( 'SEK' ) );
		$this->assertSame( 23260, $cost->total( 'EUR' ) );
	}

	public function test_entries_without_a_variant_are_left_out_and_a_price_that_is_no_amount_is_none(): void {
		$costs = VariantCost::list_from(
			array(
				'not a row',
				array(
					'product' => array(
						'amount'   => '1',
						'currency' => 'SEK',
					),
				),
				array(
					'variant' => 'v_odd',
					'product' => array(
						'amount'   => 'free',
						'currency' => 'SEK',
					),
				),
			)
		);

		$this->assertSame( array( 'v_odd' ), array_keys( $costs ) );
		$this->assertSame( VariantCost::NO_PRICE, $costs['v_odd']->problem( 'SEK' ) );
	}

	public function test_a_cost_is_stored_and_read_back(): void {
		$cost = VariantCost::list_from( array( self::row( 'v_a3', '167.10', '65.50' ) ) )['v_a3'];
		$read = VariantCost::from_array( $cost->to_array() );

		$this->assertTrue( $cost->charges_as( $read ) );
		$this->assertSame( $cost->to_array(), $read?->to_array() );
		$this->assertFalse( $cost->charges_as( VariantCost::list_from( array( self::row( 'v_a3', '170', '65.50' ) ) )['v_a3'] ) );
		$this->assertFalse( $cost->charges_as( null ) );
	}

	/**
	 * A row of the answer's `costs`.
	 *
	 * @param string      $variant  The variant's ref.
	 * @param string|null $product  Its product price; null for none.
	 * @param string|null $shipping Its shipping; null for none.
	 * @param string      $currency The currency of both.
	 * @return array<string, mixed>
	 */
	private static function row( string $variant, ?string $product, ?string $shipping, string $currency = 'SEK' ): array {
		$money = static fn ( ?string $amount ): ?array => null === $amount ? null : array(
			'amount'   => $amount,
			'currency' => $currency,
		);
		return array(
			'variant'   => $variant,
			'product'   => $money( $product ),
			'shipping'  => $money( $shipping ),
			'region'    => 'SE',
			'readAt'    => '2026-11-14T06:00:00.000Z',
			'changedAt' => null,
		);
	}
}
