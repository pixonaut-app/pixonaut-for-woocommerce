<?php
/**
 * @package Pixonaut\WooCommerce
 */

declare(strict_types=1);

namespace Pixonaut\WooCommerce\Tests\Unit\Pricing;

use Pixonaut\WooCommerce\Pricing\Amount;
use Pixonaut\WooCommerce\Pricing\PriceRule;
use Pixonaut\WooCommerce\Pricing\PriceTax;
use Pixonaut\WooCommerce\Tests\Unit\TestCase;

final class PriceRuleTest extends TestCase {

	/**
	 * Swedish VAT, which the store's prices include.
	 */
	private const VAT_PERCENT = 25.0;

	public function test_the_target_margin_counts_on_the_price_excluding_vat(): void {
		$rule = self::rule( array( PriceRule::MARGIN => '50' ) );

		$this->assertSame( self::sek( '334' ), $rule->target( self::sek( '167' ), PriceTax::none() ) );
		$this->assertSame( self::sek( '417.50' ), $rule->target( self::sek( '167' ), PriceTax::rate( self::VAT_PERCENT ) ) );
		$this->assertSame( 50.0, PriceRule::margin_of( self::sek( '417.50' ), self::sek( '167' ), PriceTax::rate( self::VAT_PERCENT ) ) );
		$this->assertSame( 60.0, PriceRule::margin_of( self::sek( '417.50' ), self::sek( '167' ), PriceTax::none() ), 'the same price entered excluding VAT leaves more' );
	}

	public function test_a_price_lands_on_the_step_nearest_the_target(): void {
		$tens_ending_in_nine = self::rule(
			array(
				PriceRule::MARGIN => '50',
				PriceRule::STEP   => '10',
				PriceRule::ENDING => '9',
			)
		);

		// A target of 417.50 lies between 409 and 419.
		$this->assertSame( self::sek( '419' ), $tens_ending_in_nine->price_for( self::sek( '167' ), PriceTax::rate( self::VAT_PERCENT ) ) );
		// A target of 334 lies between 329 and 339, halfway: the higher one keeps the margin.
		$this->assertSame( self::sek( '339' ), $tens_ending_in_nine->price_for( self::sek( '167' ), PriceTax::none() ) );
		$this->assertSame( self::sek( '335' ), self::rule( self::fifty( '5', '0' ) )->price_for( self::sek( '167' ), PriceTax::none() ) );
		$this->assertSame( self::sek( '334' ), self::rule( self::fifty( '1', '0' ) )->price_for( self::sek( '167' ), PriceTax::none() ) );
		$this->assertSame( self::sek( '333.99' ), self::rule( self::fifty( '1', '0.99' ) )->price_for( self::sek( '167' ), PriceTax::none() ) );
	}

	public function test_every_price_the_rule_sets_is_on_a_step(): void {
		$rule = self::rule( self::fifty( '10', '9' ) );

		for ( $cost = 1; $cost <= 600; $cost += 7 ) {
			$price = $rule->price_for( self::sek( (string) $cost ), PriceTax::rate( self::VAT_PERCENT ) );

			$this->assertNotNull( $price );
			$this->assertSame( self::sek( '9' ), $price % self::sek( '10' ), sprintf( 'a cost of %d gives %s', $cost, Amount::decimal( (int) $price ) ) );
		}
	}

	public function test_a_price_stays_within_the_minimum_and_maximum_on_a_step(): void {
		$rule = self::rule(
			self::fifty( '10', '9' ) + array(
				PriceRule::MIN => '300',
				PriceRule::MAX => '500',
			)
		);

		$this->assertSame( self::sek( '309' ), $rule->price_for( self::sek( '100' ), PriceTax::none() ), 'a target of 200 goes up to the first step from the minimum' );
		$this->assertSame( self::sek( '499' ), $rule->price_for( self::sek( '400' ), PriceTax::none() ), 'a target of 800 comes down to the last step under the maximum' );
		$this->assertSame( self::sek( '399' ), $rule->price_for( self::sek( '200' ), PriceTax::none() ) );
	}

	public function test_no_price_is_set_when_no_step_lies_between_the_minimum_and_maximum(): void {
		$rule = self::rule(
			self::fifty( '10', '9' ) + array(
				PriceRule::MIN => '300',
				PriceRule::MAX => '305',
			)
		);

		$this->assertNull( $rule->price_for( self::sek( '150' ), PriceTax::none() ) );
	}

	public function test_a_price_is_held_while_its_margin_is_within_the_tolerance(): void {
		$rule = self::rule( self::fifty( '10', '9' ) + array( PriceRule::TOLERANCE => '10' ) );
		$none = PriceTax::none();

		// 399 over a cost of 200 leaves 49.9 %.
		$this->assertTrue( $rule->holds( self::sek( '399' ), self::sek( '200' ), $none ) );
		// The cost drops a little: 52.4 %, still inside 40 to 60.
		$this->assertTrue( $rule->holds( self::sek( '399' ), self::sek( '190' ), $none ) );
		// It drops to 155: 61.2 %, outside, and the price moves to the step nearest the target of 310.
		$this->assertFalse( $rule->holds( self::sek( '399' ), self::sek( '155' ), $none ) );
		$this->assertSame( self::sek( '309' ), $rule->price_for( self::sek( '155' ), $none ) );
		// It rises to 245: 38.6 %, outside the other way.
		$this->assertFalse( $rule->holds( self::sek( '399' ), self::sek( '245' ), $none ) );
		$this->assertSame( self::sek( '489' ), $rule->price_for( self::sek( '245' ), $none ) );
	}

	public function test_without_a_fixed_amount_prices_are_as_before(): void {
		$tax = PriceTax::rate( self::VAT_PERCENT );
		$was = self::rule( self::fifty( '10', '9' ) );
		foreach ( array( '', '0', ' 0,00 ' ) as $fixed ) {
			$rule = self::rule( self::fifty( '10', '9' ) + array( PriceRule::FIXED => $fixed ) );

			$this->assertSame( 0, $rule->fixed, $fixed );
			for ( $cost = 1; $cost <= 1200; $cost += 37 ) {
				$this->assertSame( $was->price_for( self::sek( (string) $cost ), $tax ), $rule->price_for( self::sek( (string) $cost ), $tax ) );
				$this->assertSame( 50.0, $rule->margin_at( self::sek( (string) $cost ) ) );
			}
		}
	}

	public function test_a_fixed_amount_is_added_to_the_price_excluding_vat(): void {
		$rule = self::rule(
			array(
				PriceRule::MARGIN => '15',
				PriceRule::FIXED  => '300',
				PriceRule::STEP   => '10',
				PriceRule::ENDING => '9',
			)
		);
		$tax  = PriceTax::rate( self::VAT_PERCENT );

		// 143 ÷ 0.85 + 300 = 468.235 excluding VAT, 585.294 with it: 589 is the nearest step.
		$this->assertSame( self::sek( '585.294' ), $rule->target( self::sek( '143' ), $tax ) );
		$this->assertSame( self::sek( '589' ), $rule->price_for( self::sek( '143' ), $tax ) );
		// 518 ÷ 0.85 + 300 = 909.412 excluding VAT, 1136.765 with it: 1139.
		$this->assertSame( self::sek( '1136.765' ), $rule->target( self::sek( '518' ), $tax ) );
		$this->assertSame( self::sek( '1139' ), $rule->price_for( self::sek( '518' ), $tax ) );
	}

	public function test_with_a_fixed_amount_the_tolerance_is_around_the_rules_margin_at_that_cost(): void {
		$rule = self::rule(
			array(
				PriceRule::MARGIN    => '15',
				PriceRule::FIXED     => '300',
				PriceRule::TOLERANCE => '5',
			)
		);
		$none = PriceTax::none();

		// At a cost of 143 the rule's price is 468.24, which leaves 69.5 %.
		$this->assertEqualsWithDelta( 69.46, $rule->margin_at( self::sek( '143' ) ), 0.01 );
		// 471 leaves 69.6 %: held, though far above the target margin of 15 %.
		$this->assertTrue( $rule->holds( self::sek( '471' ), self::sek( '143' ), $none ) );
		// 389 leaves 63.2 %, more than 5 points under.
		$this->assertFalse( $rule->holds( self::sek( '389' ), self::sek( '143' ), $none ) );
		// At a cost of 518 the rule's price is 909.41, which leaves 43.0 %: 899 leaves 42.4 %.
		$this->assertTrue( $rule->holds( self::sek( '899' ), self::sek( '518' ), $none ) );
		$this->assertFalse( $rule->holds( self::sek( '589' ), self::sek( '518' ), $none ) );
	}

	public function test_contribution_is_what_a_price_leaves_over_a_cost_excluding_vat(): void {
		$this->assertSame( self::sek( '328.20' ), PriceRule::contribution_of( self::sek( '589' ), self::sek( '143' ), PriceTax::rate( self::VAT_PERCENT ) ) );
		$this->assertSame( -self::sek( '10' ), PriceRule::contribution_of( self::sek( '90' ), self::sek( '100' ), PriceTax::none() ) );
	}

	public function test_a_price_outside_the_minimum_and_maximum_is_not_held(): void {
		$rule = self::rule(
			self::fifty( '10', '9' ) + array(
				PriceRule::TOLERANCE => '10',
				PriceRule::MIN       => '409',
			)
		);

		$this->assertFalse( $rule->holds( self::sek( '399' ), self::sek( '200' ), PriceTax::none() ) );
	}

	public function test_without_a_target_margin_there_is_no_rule(): void {
		foreach ( array( '', '0', '100', '140', 'half' ) as $margin ) {
			$rule = self::rule( array( PriceRule::MARGIN => $margin ) );

			$this->assertFalse( $rule->is_set(), $margin );
			$this->assertNull( $rule->price_for( self::sek( '167' ), PriceTax::none() ) );
			$this->assertFalse( $rule->holds( self::sek( '399' ), self::sek( '200' ), PriceTax::none() ) );
		}
	}

	public function test_parts_are_read_with_a_point_or_a_comma(): void {
		$rule = self::rule(
			array(
				PriceRule::MARGIN => '49,5',
				PriceRule::STEP   => '0,50',
				PriceRule::ENDING => ' 0.25 ',
			)
		);

		$this->assertSame( 49.5, $rule->margin );
		$this->assertSame( 500, $rule->step );
		$this->assertSame( 250, $rule->ending );
		$this->assertTrue( PriceRule::is_part( '49,5' ) );
		$this->assertTrue( PriceRule::is_part( '' ) );
		$this->assertFalse( PriceRule::is_part( '-5' ) );
		$this->assertFalse( PriceRule::is_part( 'ten' ) );
		$this->assertFalse( PriceRule::is_part( 10 ) );
		$this->assertSame( '49.5', PriceRule::part( ' 49,5 ' ) );
	}

	public function test_a_step_of_nothing_is_one_unit_and_an_ending_past_the_step_is_none(): void {
		$rule = self::rule(
			array(
				PriceRule::MARGIN => '50',
				PriceRule::STEP   => '0',
				PriceRule::ENDING => '12',
			)
		);

		$this->assertSame( Amount::PER_UNIT, $rule->step );
		$this->assertSame( 0, $rule->ending );
	}

	/**
	 * @param array<string, string> $parts The rule's parts as written.
	 */
	private static function rule( array $parts ): PriceRule {
		return PriceRule::from_parts( $parts );
	}

	/**
	 * A target margin of 50 % on a step with an ending.
	 *
	 * @param string $step   The step.
	 * @param string $ending What prices end in.
	 * @return array<string, string>
	 */
	private static function fifty( string $step, string $ending ): array {
		return array(
			PriceRule::MARGIN => '50',
			PriceRule::STEP   => $step,
			PriceRule::ENDING => $ending,
		);
	}

	/**
	 * @param string $amount A decimal amount.
	 */
	private static function sek( string $amount ): int {
		return (int) Amount::parse( $amount );
	}
}
