<?php
/**
 * @package Pixonaut\WooCommerce
 */

declare(strict_types=1);

namespace Pixonaut\WooCommerce\Tests\Integration\Pricing;

use Pixonaut\WooCommerce\Admin\PricingPanel;
use Pixonaut\WooCommerce\Pricing\ExchangeRates;
use Pixonaut\WooCommerce\Pricing\PriceRow;
use Pixonaut\WooCommerce\Pricing\PriceState;
use Pixonaut\WooCommerce\Pricing\ProductPricing;
use Pixonaut\WooCommerce\Settings\Settings;

/**
 * A cost in the printshop's billing currency is converted to the store's
 * with the day's exchange rate and the store's currency buffer, and a rate
 * that moves is a cost that moves.
 */
final class CurrencyConversionTest extends PricingTestCase {

	/**
	 * The store's currency in these tests.
	 */
	private const STORE = 'SEK';

	public function set_up(): void {
		parent::set_up();
		update_option( 'woocommerce_currency', self::STORE );
		// One euro is 11.50 kronor and 1.15 dollars.
		$this->rates = array(
			'SEK' => '11.50',
			'USD' => '1.15',
		);
	}

	public function test_a_cost_in_euro_is_converted_at_the_days_rate_with_the_buffer_added(): void {
		$this->store_rule();
		$id = $this->simple_product( 'v_mug', '' );
		$this->script_costs( array( 'v_mug' => '16.71' ), '3', 'EUR' );

		$this->pricing->refresh( $this->product( $id ) );
		$this->pricing->apply( $this->product( $id ) );

		// 19.71 EUR is 226.665 SEK; with 3 % it costs 233.465. A margin of 50 % targets 466.93.
		$row = $this->row( $id );
		$this->assertSame( self::amount( '233.465' ), $row->cost_total );
		$this->assertSame( '2026-11-13', $row->rate_date );
		$this->assertSame( '3', $row->buffer );
		$this->assertSame( '469', $this->price( $id ) );
		$shown = PricingPanel::row( $row );
		$this->assertStringStartsWith( '≈ ', $shown['cost'] );
		$this->assertStringContainsString( '233', $shown['cost'] );
		$this->assertStringContainsString( '16.71', $shown['cost_parts'] );
		$this->assertStringEndsWith( 'shipping (rate 2026-11-13, +3 %)', $shown['cost_parts'] );
	}

	public function test_the_buffer_is_the_stores_and_a_rate_between_two_other_currencies_goes_through_the_euro(): void {
		$this->store_rule();
		$this->plugin->settings()->set( Settings::PRICE_CURRENCY_BUFFER, '0' );
		$id = $this->simple_product( 'v_mug', '' );
		$this->script_costs( array( 'v_mug' => '23' ), null, 'USD' );

		$this->pricing->refresh( $this->product( $id ) );

		// 23 USD is 20 EUR is 230 SEK.
		$this->assertSame( self::amount( '230' ), $this->row( $id )->cost_total );
		$this->assertSame( '0', $this->row( $id )->buffer );
	}

	public function test_a_cost_in_the_stores_currency_is_not_converted_and_asks_for_no_rate(): void {
		$this->store_rule();
		$id = $this->simple_product( 'v_mug', '' );
		$this->script_costs( array( 'v_mug' => '200' ) );

		$this->pricing->refresh( $this->product( $id ) );

		$this->assertSame( self::amount( '200' ), $this->row( $id )->cost_total );
		$this->assertSame( '', $this->row( $id )->rate_date );
		$this->assertSame( 0, $this->rate_reads );
	}

	public function test_the_rates_are_read_once_a_day(): void {
		$id = $this->simple_product( 'v_mug', '' );
		$this->script_costs( array( 'v_mug' => '16.71' ), null, 'EUR' );
		$this->script_costs( array( 'v_mug' => '16.71' ), null, 'EUR' );

		$this->pricing->refresh( $this->product( $id ) );
		$this->pricing->refresh( $this->product( $id ) );

		$this->assertSame( 1, $this->rate_reads );
	}

	public function test_a_small_move_of_the_rate_keeps_the_price_and_one_that_leaves_the_tolerance_changes_it(): void {
		$this->store_rule();
		$this->plugin->settings()->set( Settings::PRICE_AUTOMATIC, true );
		$id = $this->simple_product( 'v_mug', '' );
		$this->applied_at_euro_cost( $id );
		$this->assertSame( '469', $this->price( $id ) );

		// The krona weakens a little: the same 19.71 EUR now costs 239.56 SEK, a margin of 48.9 %.
		$this->next_day( array( 'SEK' => '11.80' ) );
		$this->script_costs( array( 'v_mug' => '16.71' ), '3', 'EUR' );
		$this->assertSame( 0, $this->pricing->refresh( $this->product( $id ) ) );

		$this->assertSame( '469', $this->price( $id ) );
		$this->assertNull( $this->row( $id )->flag );

		// It weakens a lot: 300.47 SEK, a margin of 35.9 %, outside 40 to 60.
		$this->next_day( array( 'SEK' => '14.80' ) );
		$this->script_costs( array( 'v_mug' => '16.71' ), '3', 'EUR' );
		$this->assertSame( 1, $this->pricing->refresh( $this->product( $id ) ) );

		$this->assertSame( '599', $this->price( $id ) );
	}

	public function test_a_rate_that_left_the_tolerance_is_flagged_while_prices_do_not_change_by_themselves(): void {
		$this->store_rule();
		$id = $this->simple_product( 'v_mug', '' );
		$this->applied_at_euro_cost( $id );

		$this->next_day( array( 'SEK' => '14.80' ) );
		$this->script_costs( array( 'v_mug' => '16.71' ), '3', 'EUR' );
		$this->pricing->refresh( $this->product( $id ) );

		$this->assertSame( '469', $this->price( $id ) );
		$this->assertSame( PriceState::FLAG_MARGIN, $this->row( $id )->flag );
		$this->assertSame( self::amount( '599' ), $this->row( $id )->suggested );
	}

	public function test_the_last_rate_is_kept_while_none_can_be_read_and_past_a_week_nothing_is_priced_with_it(): void {
		$this->store_rule();
		$this->plugin->settings()->set( Settings::PRICE_AUTOMATIC, true );
		$id = $this->simple_product( 'v_mug', '' );
		$this->applied_at_euro_cost( $id );

		// Three days without an answer: the rate of the last day read still converts.
		$this->rates = null;
		$this->now  += 3 * DAY_IN_SECONDS;
		$this->script_costs( array( 'v_mug' => '16.71' ), '3', 'EUR' );
		$this->pricing->refresh( $this->product( $id ) );

		$this->assertSame( self::amount( '233.465' ), $this->row( $id )->cost_total );
		$this->assertSame( '2026-11-13', $this->row( $id )->rate_date, 'the panel says how old the rate is' );
		$this->assertNull( $this->row( $id )->flag );

		// Past a week it is flagged instead, whatever the cost does.
		$this->now += 5 * DAY_IN_SECONDS;
		$this->script_costs( array( 'v_mug' => '30' ), '3', 'EUR' );
		$this->pricing->refresh( $this->product( $id ) );

		$this->assertSame( '469', $this->price( $id ) );
		$this->assertNull( $this->row( $id )->cost_total );
		$this->assertSame( PriceState::FLAG_RATE_STALE, $this->row( $id )->flag );
		$this->assertSame( 1, ProductPricing::flagged( $this->product( $id ) ) );

		// Once a rate is read again, the cost prices it again.
		$this->next_day( array( 'SEK' => '11.50' ) );
		$this->script_costs( array( 'v_mug' => '16.71' ), '3', 'EUR' );
		$this->pricing->refresh( $this->product( $id ) );

		$this->assertNull( $this->row( $id )->flag );
		$this->assertSame( 0, ProductPricing::flagged( $this->product( $id ) ) );
	}

	public function test_rates_are_as_old_as_the_day_they_are_of_however_recently_they_were_answered(): void {
		$this->store_rule();
		$id = $this->simple_product( 'v_mug', '300' );
		// The source answers, but with a list of ten days ago.
		$this->rates_date = gmdate( 'Y-m-d', $this->now - 10 * DAY_IN_SECONDS );
		$this->script_costs( array( 'v_mug' => '16.71' ), null, 'EUR' );

		$this->pricing->refresh( $this->product( $id ) );

		$this->assertNull( $this->row( $id )->cost_total );
		$this->assertSame( PriceState::FLAG_RATE_STALE, $this->row( $id )->flag );
	}

	public function test_a_cost_kept_from_an_earlier_read_is_converted_at_the_new_days_rate(): void {
		$this->store_rule();
		$id = $this->simple_product( 'v_mug', '' );
		$this->applied_at_euro_cost( $id );

		// Pixonaut leaves the variant out; its cost is kept, and the rate still moves.
		$this->next_day( array( 'SEK' => '14.80' ) );
		$this->script_costs( array() );
		$this->pricing->refresh( $this->product( $id ) );

		$this->assertSame( 2, $this->rate_reads );
		$this->assertSame( PriceState::FLAG_MARGIN, $this->row( $id )->flag );
	}

	public function test_a_rate_file_cut_short_or_with_a_rate_of_nothing_replaces_no_rate(): void {
		$rates = new ExchangeRates( fn (): int => $this->now );
		$rates->refresh();

		$this->next_day( array( 'SEK' => '9.00' ) );
		$this->rates_cut_short = true;
		$rates->refresh();
		$this->assertSame( 11.5, $rates->rate( 'EUR', self::STORE ) );

		$this->next_day(
			array(
				'SEK' => '0',
				'USD' => '1.15',
			)
		);
		$this->rates_cut_short = false;
		$rates->refresh();
		$this->assertSame( 11.5, $rates->rate( 'EUR', self::STORE ) );
		$this->assertSame( '2026-11-13', $rates->date() );
	}

	public function test_a_currency_without_a_rate_prices_nothing(): void {
		$this->store_rule();
		$id = $this->simple_product( 'v_mug', '300' );
		$this->script_costs( array( 'v_mug' => '16.70' ), null, 'XTS' );

		$this->pricing->refresh( $this->product( $id ) );
		$this->assertSame( 0, $this->pricing->apply( $this->product( $id ) ) );

		$this->assertSame( '300', $this->price( $id ) );
		$this->assertSame( PriceState::FLAG_CURRENCY, $this->row( $id )->flag );
		$this->assertNull( $this->row( $id )->margin );
		$this->assertStringContainsString( '16.70', PricingPanel::row( $this->row( $id ) )['cost'], 'the cost is shown as the printshop charges it' );
	}

	public function test_a_source_that_answers_something_else_keeps_the_rates_and_is_not_asked_again_within_the_hour(): void {
		$rates = new ExchangeRates( fn (): int => $this->now );
		$rates->refresh();
		$this->assertSame( 11.5, $rates->rate( 'EUR', self::STORE ) );

		$this->now  += 2 * DAY_IN_SECONDS;
		$this->rates = array();
		$rates->refresh();
		$rates->refresh();

		$this->assertSame( 2, $this->rate_reads );
		$this->assertSame( 11.5, $rates->rate( 'EUR', self::STORE ) );
		$this->assertSame( '2026-11-13', $rates->date() );
		$this->assertNull( $rates->rate( 'XTS', self::STORE ) );
	}

	/**
	 * Applies the rule to a product whose variant costs 16.71 EUR and 3 EUR
	 * shipping: 233.465 SEK at the first day's rate, priced at 469.
	 *
	 * @param int $id The product.
	 */
	private function applied_at_euro_cost( int $id ): void {
		$this->script_costs( array( 'v_mug' => '16.71' ), '3', 'EUR' );
		$this->pricing->refresh( $this->product( $id ) );
		$this->pricing->apply( $this->product( $id ) );
	}

	/**
	 * A day later, with other rates.
	 *
	 * @param array<string, string> $rates Each currency's rate against the euro.
	 */
	private function next_day( array $rates ): void {
		$this->now       += DAY_IN_SECONDS + 1;
		$this->rates      = $rates;
		$this->rates_date = gmdate( 'Y-m-d', $this->now - DAY_IN_SECONDS );
	}

	/**
	 * A product's row.
	 *
	 * @param int $id The product.
	 */
	private function row( int $id ): PriceRow {
		return $this->pricing->rows( $this->product( $id ) )[0];
	}
}
