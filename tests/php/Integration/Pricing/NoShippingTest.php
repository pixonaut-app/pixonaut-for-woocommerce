<?php
/**
 * @package Pixonaut\WooCommerce
 */

declare(strict_types=1);

namespace Pixonaut\WooCommerce\Tests\Integration\Pricing;

use Pixonaut\WooCommerce\Admin\PricingPanel;
use Pixonaut\WooCommerce\Pricing\PriceRow;
use Pixonaut\WooCommerce\Pricing\PriceState;

/**
 * A cost with a product price and no shipping to the store's country is not
 * whole: priced on the product alone, the variation would be sold as if
 * shipping were free, and for less than a smaller size that has shipping.
 * The rule keeps its price and flags it, as it does a missing product price.
 */
final class NoShippingTest extends PricingTestCase {

	public function set_up(): void {
		parent::set_up();
		update_option( 'woocommerce_currency', 'SEK' );
		// One euro is 11.50 kronor and 1.15 dollars.
		$this->rates = array(
			'SEK' => '11.50',
			'USD' => '1.15',
		);
	}

	public function test_a_cost_with_no_shipping_keeps_the_price_and_flags_the_missing_shipping(): void {
		$this->store_rule();
		$id = $this->simple_product( 'v_tee', '449' );
		$this->script_costs( array( 'v_tee' => '12.97' ), null, 'USD' );

		$this->pricing->refresh( $this->product( $id ) );
		$this->pricing->apply( $this->product( $id ) );

		$this->assertSame( '449', $this->price( $id ) );
		$row = $this->row( $id );
		$this->assertSame( PriceState::FLAG_NO_SHIPPING, $row->flag );
		$this->assertNull( $row->cost_total );
		$this->assertStringContainsString( 'no shipping', PricingPanel::flag_texts()[ PriceState::FLAG_NO_SHIPPING ] );
	}

	private function row( int $id ): PriceRow {
		foreach ( $this->pricing->rows( $this->product( $id ) ) as $row ) {
			if ( $row->id === $id ) {
				return $row;
			}
		}
		$this->fail( 'No row for ' . $id );
	}
}
