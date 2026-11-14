<?php
/**
 * @package Pixonaut\WooCommerce
 */

declare(strict_types=1);

namespace Pixonaut\WooCommerce\Tests\Integration\Pricing;

use Pixonaut\WooCommerce\Catalogue\ProductVariant;
use Pixonaut\WooCommerce\Pricing\Amount;
use Pixonaut\WooCommerce\Pricing\PriceRule;
use Pixonaut\WooCommerce\Pricing\PriceRow;
use Pixonaut\WooCommerce\Pricing\PriceState;
use Pixonaut\WooCommerce\Pricing\PriceTax;
use Pixonaut\WooCommerce\Pricing\ProductPricing;
use Pixonaut\WooCommerce\Settings\Settings;
use Pixonaut\WooCommerce\Vendor\Pixonaut\Sdk\Exception\ApiException;
use WC_Tax;

/**
 * Prices follow the printshop's costs by the pricing rule: applied on
 * request, held within the tolerance, changed or flagged when a cost
 * leaves it, and kept once edited by hand.
 */
final class ProductPricingTest extends PricingTestCase {

	/**
	 * Sizes times frames of the poster the acceptance names.
	 */
	private const POSTER_VARIATIONS = 54;

	public function test_apply_prices_a_poster_with_54_variations_on_the_steps_at_the_target_margin(): void {
		$this->store_rule(
			array(
				PriceRule::MIN => '150',
				PriceRule::MAX => '900',
			)
		);
		$costs = array();
		for ( $index = 0; $index < self::POSTER_VARIATIONS; $index++ ) {
			// From 40.50 up to 517.50 in steps of 9.
			$costs[ 'v_' . $index ] = Amount::decimal( self::amount( '40.50' ) + $index * self::amount( '9' ) );
		}
		$ids = $this->variable_product( array_fill_keys( array_keys( $costs ), '' ) );
		$this->script_costs( $costs, '10' );

		$this->pricing->refresh( $this->parent_of( $ids['v_0'] ) );
		$changed = $this->pricing->apply( $this->parent_of( $ids['v_0'] ) );

		$this->assertSame( self::POSTER_VARIATIONS, $changed );
		$this->assertCount( 1, $this->recorder->requests, 'one read prices every variation' );
		$this->assertStringEndsWith( '/v1/blanks/' . self::BLANK_ID . '/variants/costs?region=' . WC()->countries->get_base_country(), $this->recorder->requests[0]['url'] );
		$rule = $this->pricing->rule_for( $this->parent_of( $ids['v_0'] ) );
		foreach ( $costs as $ref => $product ) {
			$price = self::amount( $this->price( $ids[ $ref ] ) );
			$cost  = self::amount( $product ) + self::amount( '10' );

			$this->assertSame( self::amount( '9' ), $price % self::amount( '10' ), $ref . ' ends in 9' );
			$this->assertGreaterThanOrEqual( self::amount( '150' ), $price, $ref );
			$this->assertLessThanOrEqual( self::amount( '900' ), $price, $ref );
			$target = (int) $rule->target( $cost, PriceTax::none() );
			if ( $target >= self::amount( '159' ) && $target <= self::amount( '899' ) ) {
				$this->assertLessThanOrEqual( self::amount( '5' ), abs( $price - $target ), $ref . ' is the step nearest its target' );
			}
		}
		$this->assertSame( '159', $this->price( $ids['v_0'] ), 'a target of 101 goes up to the first step from the minimum' );
		$this->assertSame( '899', $this->price( $ids[ 'v_' . ( self::POSTER_VARIATIONS - 1 ) ] ), 'a target of 1055 comes down to the last step under the maximum' );
		$this->assertSame( 0, ProductPricing::flagged( $this->parent_of( $ids['v_0'] ) ) );
	}

	public function test_a_small_cost_move_keeps_the_price_and_leaving_the_tolerance_suggests_the_next_step(): void {
		$this->store_rule();
		$ids     = $this->variable_product( array( 'v_a3' => '' ) );
		$product = fn () => $this->parent_of( $ids['v_a3'] );
		$this->script_costs( array( 'v_a3' => '200' ) );
		$this->pricing->refresh( $product() );
		$this->pricing->apply( $product() );
		$this->assertSame( '399', $this->price( $ids['v_a3'] ) );

		// 52.4 %: inside 40 to 60.
		$this->script_costs( array( 'v_a3' => '190' ) );
		$this->pricing->refresh( $product() );

		$this->assertSame( '399', $this->price( $ids['v_a3'] ) );
		$this->assertNull( $this->row( $ids['v_a3'] )->flag );
		$this->assertNull( $this->row( $ids['v_a3'] )->suggested, 'a price held is suggested no other' );
		$this->assertSame( 0, ProductPricing::flagged( $product() ) );

		// 61.2 %: outside. Prices do not change by themselves, so it is flagged.
		$this->script_costs( array( 'v_a3' => '155' ) );
		$this->pricing->refresh( $product() );

		$this->assertSame( '399', $this->price( $ids['v_a3'] ) );
		$this->assertSame( PriceState::FLAG_MARGIN, $this->row( $ids['v_a3'] )->flag );
		$this->assertSame( self::amount( '309' ), $this->row( $ids['v_a3'] )->suggested );
		$this->assertSame( 1, ProductPricing::flagged( $product() ) );

		$this->assertSame( 1, $this->pricing->apply( $product(), array( $ids['v_a3'] ) ) );

		$this->assertSame( '309', $this->price( $ids['v_a3'] ) );
		$this->assertSame( 0, ProductPricing::flagged( $product() ) );
	}

	public function test_prices_change_by_themselves_only_when_the_setting_is_on(): void {
		$this->store_rule();
		$ids     = $this->variable_product(
			array(
				'v_a3' => '',
				'v_a4' => '',
			)
		);
		$product = fn () => $this->parent_of( $ids['v_a3'] );
		$this->script_costs(
			array(
				'v_a3' => '200',
				'v_a4' => '100',
			)
		);
		$this->pricing->refresh( $product() );
		$this->pricing->apply( $product() );
		$this->logger->entries = array();

		$this->plugin->settings()->set( Settings::PRICE_AUTOMATIC, true );
		// A3 leaves the tolerance; A4 moves within it.
		$this->script_costs(
			array(
				'v_a3' => '155',
				'v_a4' => '105',
			)
		);
		$this->pricing->refresh( $product() );

		$this->assertSame( '309', $this->price( $ids['v_a3'] ) );
		$this->assertSame( '199', $this->price( $ids['v_a4'] ), 'held within the tolerance, automatic or not' );
		$this->assertSame( PriceState::FOLLOWS, $this->row( $ids['v_a3'] )->standing );
		$this->assertSame( self::NOW, $this->row( $ids['v_a3'] )->rule_at );
		$this->assertSame( 0, ProductPricing::flagged( $product() ) );
		$this->assertCount( 1, $this->logger->entries, 'each change is logged' );
		$this->assertStringContainsString( 'to 309, from 399: cost 155, margin 49.8 %', $this->logger->entries[0]['message'] );
		$this->assertSame( $ids['v_a3'], $this->logger->entries[0]['context']['product_id'] );
	}

	public function test_a_price_the_rule_never_set_is_not_changed_by_itself(): void {
		$this->store_rule();
		$this->plugin->settings()->set( Settings::PRICE_AUTOMATIC, true );
		$ids = $this->variable_product( array( 'v_a3' => '250' ) );
		$this->script_costs( array( 'v_a3' => '200' ) );

		$this->pricing->refresh( $this->parent_of( $ids['v_a3'] ) );

		$this->assertSame( '250', $this->price( $ids['v_a3'] ) );
		$row = $this->row( $ids['v_a3'] );
		$this->assertSame( PriceState::OWN, $row->standing );
		$this->assertSame( self::amount( '399' ), $row->suggested, 'the panel says what the rule would set' );
		$this->assertSame( 20.0, $row->margin );
		$this->assertSame( 0, ProductPricing::flagged( $this->parent_of( $ids['v_a3'] ) ) );
		$this->assertSame( array(), ProductPricing::watched( 0, 10 ) );
	}

	public function test_a_price_edited_by_hand_is_kept_until_it_is_given_back_to_the_rule(): void {
		$this->store_rule();
		$this->plugin->settings()->set( Settings::PRICE_AUTOMATIC, true );
		$ids     = $this->variable_product(
			array(
				'v_a3' => '',
				'v_a4' => '',
			)
		);
		$product = fn () => $this->parent_of( $ids['v_a3'] );
		$costs   = array(
			'v_a3' => '200',
			'v_a4' => '100',
		);
		$this->script_costs( $costs );
		$this->pricing->refresh( $product() );
		$this->pricing->apply( $product() );

		$this->edit_price( $ids['v_a3'], '450' );
		$this->script_costs( array( 'v_a3' => '120' ) + $costs );
		$this->pricing->refresh( $product() );
		$this->pricing->apply( $product() );

		$this->assertSame( '450', $this->price( $ids['v_a3'] ), 'neither a cost change nor Apply moves it' );
		$this->assertSame( PriceState::BY_HAND, $this->row( $ids['v_a3'] )->standing );
		$this->assertNull( $this->row( $ids['v_a3'] )->flag );
		$this->assertSame( 0, ProductPricing::flagged( $product() ) );

		$this->assertSame( 1, $this->pricing->apply( $product(), array( $ids['v_a3'] ), true ) );

		$this->assertSame( '239', $this->price( $ids['v_a3'] ) );
		$this->assertSame( PriceState::FOLLOWS, $this->row( $ids['v_a3'] )->standing );
	}

	public function test_the_margin_counts_on_the_price_excluding_vat_when_prices_include_it(): void {
		update_option( 'woocommerce_calc_taxes', 'yes' );
		update_option( 'woocommerce_prices_include_tax', 'yes' );
		WC_Tax::_insert_tax_rate(
			array(
				'tax_rate_country'  => '',
				'tax_rate'          => '25.0000',
				'tax_rate_name'     => 'VAT',
				'tax_rate_priority' => 1,
				'tax_rate_shipping' => 1,
				'tax_rate_order'    => 1,
				'tax_rate_class'    => '',
			)
		);
		$this->store_rule();
		$id = $this->simple_product( 'v_mug', '' );
		$this->script_costs( array( 'v_mug' => '167' ) );

		$this->pricing->refresh( $this->product( $id ) );
		$this->pricing->apply( $this->product( $id ) );

		// 334 excluding VAT is 417.50 including it, between 409 and 419.
		$this->assertSame( '419', $this->price( $id ) );
		$this->assertEqualsWithDelta( 50.2, (float) $this->row( $id )->margin, 0.05 );
	}

	public function test_a_variant_without_a_published_price_is_left_unpriced_and_flagged(): void {
		$this->store_rule();
		$ids     = $this->variable_product(
			array(
				'v_a3' => '',
				'v_a4' => '',
			)
		);
		$product = fn () => $this->parent_of( $ids['v_a3'] );
		$this->script_costs(
			array(
				'v_a3' => null,
				'v_a4' => '100',
			)
		);

		$this->pricing->refresh( $product() );
		$this->assertSame( 1, $this->pricing->apply( $product() ) );

		$this->assertSame( '', $this->price( $ids['v_a3'] ) );
		$this->assertSame( PriceState::FLAG_NO_COST, $this->row( $ids['v_a3'] )->flag );
		$this->assertSame( '199', $this->price( $ids['v_a4'] ) );
	}

	public function test_a_variant_pixonaut_has_not_read_yet_is_not_priced_and_keeps_the_cost_it_had(): void {
		$this->store_rule();
		$ids     = $this->variable_product(
			array(
				'v_a3' => '',
				'v_a4' => '',
			)
		);
		$product = fn () => $this->parent_of( $ids['v_a3'] );
		// The first read of a country answers only what Pixonaut has read so far.
		$this->script_costs( array( 'v_a4' => '100' ) );

		$this->pricing->refresh( $product() );
		$this->assertSame( 1, $this->pricing->apply( $product() ) );

		$this->assertSame( '', $this->price( $ids['v_a3'] ) );
		$this->assertSame( PriceState::FLAG_UNREAD, $this->row( $ids['v_a3'] )->flag );
		$this->assertSame( PriceState::OWN, $this->row( $ids['v_a3'] )->standing );

		$this->script_costs(
			array(
				'v_a3' => '200',
				'v_a4' => '100',
			)
		);
		$this->pricing->refresh( $product() );
		$this->pricing->apply( $product() );
		$this->assertSame( '399', $this->price( $ids['v_a3'] ) );

		// A later answer that leaves a variant out changes nothing for it.
		$this->script_costs( array( 'v_a4' => '100' ) );
		$this->pricing->refresh( $product() );

		$this->assertSame( self::amount( '200' ), $this->row( $ids['v_a3'] )->cost_total );
		$this->assertNull( $this->row( $ids['v_a3'] )->flag );
		$this->assertSame( 0, ProductPricing::flagged( $product() ) );
	}

	public function test_a_product_changes_parts_of_the_stores_rule(): void {
		$this->store_rule();
		$id = $this->simple_product( 'v_mug', '' );
		$this->pricing->save_own_parts(
			$this->product( $id ),
			array(
				PriceRule::MARGIN => '60',
				PriceRule::STEP   => '5',
				PriceRule::ENDING => '0',
				PriceRule::MAX    => 'plenty',
			)
		);
		$this->script_costs( array( 'v_mug' => '100' ) );

		$this->pricing->refresh( $this->product( $id ) );
		$this->pricing->apply( $this->product( $id ) );

		$this->assertSame( '250', $this->price( $id ) );
		$this->assertSame(
			array(
				PriceRule::MARGIN    => '60',
				PriceRule::TOLERANCE => '',
				PriceRule::STEP      => '5',
				PriceRule::ENDING    => '0',
				PriceRule::MIN       => '',
				PriceRule::MAX       => '',
			),
			ProductPricing::own_parts( $this->product( $id ) )
		);
		$this->assertSame( 10.0, $this->pricing->rule_for( $this->product( $id ) )->tolerance, 'the tolerance is the store’s' );
	}

	public function test_without_a_target_margin_nothing_is_priced(): void {
		$id = $this->simple_product( 'v_mug', '300' );
		$this->script_costs( array( 'v_mug' => '100' ) );

		$this->pricing->refresh( $this->product( $id ) );
		$this->assertSame( 0, $this->pricing->apply( $this->product( $id ) ) );

		$this->assertSame( '300', $this->price( $id ) );
		$this->assertSame( PriceState::OWN, $this->row( $id )->standing );
		$this->assertNull( $this->row( $id )->suggested );
	}

	public function test_a_price_not_above_the_sale_price_is_flagged_rather_than_set(): void {
		$this->store_rule();
		$id      = $this->simple_product( 'v_mug', '500' );
		$product = $this->product( $id );
		$product->set_sale_price( '450' );
		$product->save();
		$this->script_costs( array( 'v_mug' => '200' ) );

		$this->pricing->refresh( $this->product( $id ) );
		$this->assertSame( 0, $this->pricing->apply( $this->product( $id ) ) );

		$this->assertSame( '500', $this->price( $id ) );
		$this->assertSame( PriceState::FLAG_SALE, $this->row( $id )->flag );
	}

	public function test_a_variation_paired_with_another_variant_is_not_priced_from_the_cost_of_the_one_before(): void {
		$this->store_rule();
		$ids     = $this->variable_product( array( 'v_a3' => '' ) );
		$product = fn () => $this->parent_of( $ids['v_a3'] );
		$this->script_costs( array( 'v_a3' => '200' ) );
		$this->pricing->refresh( $product() );
		$this->pricing->apply( $product() );

		// It now sells a variant Pixonaut has read no cost for yet.
		( new ProductVariant( self::BLANK_ID, 'v_a2', 'v_a2', 'Size: A2' ) )->save_to( $this->product( $ids['v_a3'] ) );
		$this->assertNull( $this->row( $ids['v_a3'] )->cost_total, 'before any read' );
		$this->script_costs( array() );
		$this->pricing->refresh( $product() );
		$this->pricing->apply( $product(), array( $ids['v_a3'] ) );

		$this->assertSame( '399', $this->price( $ids['v_a3'] ) );
		$this->assertNull( $this->row( $ids['v_a3'] )->cost_total );
		$this->assertSame( PriceState::FLAG_UNREAD, $this->row( $ids['v_a3'] )->flag );
	}

	public function test_a_suggestion_not_above_the_sale_price_says_so_and_offers_nothing_to_apply(): void {
		$this->store_rule();
		$id = $this->simple_product( 'v_mug', '' );
		$this->script_costs( array( 'v_mug' => '200' ) );
		$this->pricing->refresh( $this->product( $id ) );
		$this->pricing->apply( $this->product( $id ) );
		$product = $this->product( $id );
		$product->set_sale_price( '350' );
		$product->save();

		// The rule would now set 309, under the sale price.
		$this->script_costs( array( 'v_mug' => '155' ) );
		$this->pricing->refresh( $this->product( $id ) );

		$this->assertSame( PriceState::FLAG_SALE, $this->row( $id )->flag );
		$this->assertSame( '399', $this->price( $id ) );
	}

	public function test_a_price_edited_while_the_costs_are_read_is_kept(): void {
		$this->store_rule();
		$this->plugin->settings()->set( Settings::PRICE_AUTOMATIC, true );
		$id = $this->simple_product( 'v_mug', '' );
		$this->script_costs( array( 'v_mug' => '200' ) );
		$this->pricing->refresh( $this->product( $id ) );
		$this->pricing->apply( $this->product( $id ) );
		$this->script_costs( array( 'v_mug' => '155' ) );
		// The store manager saves another price while Pixonaut is asked.
		$meanwhile = function ( $preempt ) use ( $id, &$meanwhile ) {
			remove_filter( 'pre_http_request', $meanwhile, 5 );
			$this->edit_price( $id, '450' );
			return $preempt;
		};
		add_filter( 'pre_http_request', $meanwhile, 5 );

		$changed = $this->pricing->refresh( $this->product( $id ) );

		$this->assertSame( 0, $changed );
		$this->assertSame( '450', $this->price( $id ) );
		$this->assertSame( PriceState::BY_HAND, $this->row( $id )->standing );
	}

	public function test_costs_that_cannot_be_read_keep_what_is_stored(): void {
		$this->store_rule();
		$id = $this->simple_product( 'v_mug', '' );
		$this->script_costs( array( 'v_mug' => '200' ) );
		$this->pricing->refresh( $this->product( $id ) );
		$this->pricing->apply( $this->product( $id ) );
		$this->recorder->fail();
		$this->recorder->fail();
		$this->recorder->fail();

		try {
			$this->pricing->refresh( $this->product( $id ) );
			$this->fail( 'The read failed.' );
		} catch ( ApiException $e ) {
			$this->assertSame( '399', $this->price( $id ) );
			$this->assertSame( self::amount( '200' ), $this->row( $id )->cost_total );
		}
	}

	/**
	 * A product's or variation's row.
	 *
	 * @param int $id The product or variation.
	 */
	private function row( int $id ): PriceRow {
		$item  = $this->product( $id );
		$owner = $item->is_type( 'variation' ) ? $this->product( $item->get_parent_id() ) : $item;
		foreach ( $this->pricing->rows( $owner ) as $row ) {
			if ( $row->id === $id ) {
				return $row;
			}
		}
		$this->fail( 'No row for ' . $id );
	}
}
