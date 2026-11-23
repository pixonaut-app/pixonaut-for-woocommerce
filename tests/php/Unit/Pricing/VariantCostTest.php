<?php
/**
 * @package Pixonaut\WooCommerce
 */

declare(strict_types=1);

namespace Pixonaut\WooCommerce\Tests\Unit\Pricing;

use Pixonaut\WooCommerce\Pricing\VariantCost;
use Pixonaut\WooCommerce\Tests\Unit\TestCase;

final class VariantCostTest extends TestCase {

	public function test_a_cost_is_a_product_price_and_its_shipping_each_in_its_currency(): void {
		$costs = VariantCost::list_from( array( self::row( 'v_a3', '167.10', '65.50' ) ) );

		$this->assertSame( array( 'v_a3' ), array_keys( $costs ) );
		$this->assertSame( array( 167100, 'SEK', 65500, 'SEK' ), array( $costs['v_a3']->product, $costs['v_a3']->currency, $costs['v_a3']->shipping, $costs['v_a3']->shipping_currency ) );
		$this->assertSame( 'SE', $costs['v_a3']->region );
	}

	public function test_a_variant_without_shipping_or_without_a_published_price_has_none(): void {
		$unshipped   = VariantCost::list_from( array( self::row( 'v_a3', '167.10', null ) ) )['v_a3'];
		$unpublished = VariantCost::list_from( array( self::row( 'v_a3', null, '65.50' ) ) )['v_a3'];

		$this->assertNull( $unshipped->shipping );
		$this->assertSame( '', $unshipped->shipping_currency );
		$this->assertNull( $unpublished->product );
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
		$this->assertNull( $costs['v_odd']->product );
	}

	public function test_a_book_is_read_with_its_cost_at_each_page_count(): void {
		$row  = self::row( 'v_a5', '9.53', '7.45' ) + array(
			'pages' => array(
				array(
					'pages'    => 60,
					'product'  => array(
						'amount'   => '15.10',
						'currency' => 'SEK',
					),
					'shipping' => array(
						'amount'   => '8.10',
						'currency' => 'SEK',
					),
				),
				array(
					'pages'    => 20,
					'product'  => null,
					'shipping' => null,
				),
				array( 'pages' => 'many' ),
			),
		);
		$cost = VariantCost::list_from( array( $row ) )['v_a5'];

		$this->assertSame( array( 20, 60 ), array_keys( $cost->by_pages ), 'fewest first, a count that is not a number left out' );
		$this->assertNull( $cost->pages );
		$at = $cost->at_pages( 60 );
		$this->assertSame( array( 60, 15100, 8100, 'SE' ), array( $at->pages, $at->product, $at->shipping, $at->region ) );
		$this->assertNull( $cost->at_pages( 20 )->product, 'no price at a count the printshop prices none at' );
		$this->assertSame( array( 44, null ), array( $cost->at_pages( 44 )->pages, $cost->at_pages( 44 )->product ), 'no price at a count the placement does not offer' );
		$this->assertSame( $cost, $cost->at_pages( null ) );

		$stored = VariantCost::from_array( $at->to_array() );
		$this->assertNotNull( $stored );
		$this->assertSame( array( 60, 15100 ), array( $stored->pages, $stored->product ) );
	}

	public function test_a_variant_printed_as_no_pages_is_its_own_cost_at_any_count(): void {
		$cost = VariantCost::list_from( array( self::row( 'v_a3', '167.10', '65.50' ) + array( 'pages' => array() ) ) )['v_a3'];

		$this->assertSame( $cost, $cost->at_pages( 44 ) );
		$this->assertArrayNotHasKey( 'pages', $cost->to_array() );
	}

	public function test_a_cost_is_stored_and_read_back(): void {
		$cost = VariantCost::list_from( array( self::row( 'v_a3', '16.71', '6.55', 'eur' ) ) )['v_a3'];
		$read = VariantCost::from_array( $cost->to_array() );

		$this->assertSame( 'EUR', $cost->currency );
		$this->assertEquals( $cost, $read );
		$this->assertNull( VariantCost::from_array( 'nothing' ) );
	}

	public function test_a_cost_carries_the_times_its_printshop_tells_and_keeps_them_when_stored(): void {
		$row  = self::row( 'v_a3', '167.10', '65.50' ) + array(
			'times' => array(
				'production' => null,
				'shipping'   => array(
					'minDays' => 3,
					'maxDays' => 6,
				),
				'delivery'   => array(
					'minDays' => 4,
					'maxDays' => 7,
				),
			),
		);
		$cost = VariantCost::list_from( array( $row ) )['v_a3'];

		$this->assertNull( $cost->times->production );
		$this->assertSame( array( 3, 6 ), $cost->times->shipping );
		$this->assertSame( array( 4, 7 ), $cost->times->delivery );
		$this->assertEquals( $cost, VariantCost::from_array( $cost->to_array() ) );
	}

	public function test_a_cost_answered_or_stored_without_times_has_none(): void {
		$cost   = VariantCost::list_from( array( self::row( 'v_a3', '167.10', '65.50' ) ) )['v_a3'];
		$stored = $cost->to_array();
		unset( $stored['times'] );

		$this->assertTrue( $cost->times->none() );
		$this->assertTrue( VariantCost::from_array( $stored )?->times->none() );
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
