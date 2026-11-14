<?php
/**
 * @package Pixonaut\WooCommerce
 */

declare(strict_types=1);

namespace Pixonaut\WooCommerce\Tests\Integration\Pricing;

use Pixonaut\WooCommerce\Pricing\CostRefresh;
use Pixonaut\WooCommerce\Pricing\PriceState;
use Pixonaut\WooCommerce\Pricing\ProductPricing;
use Pixonaut\WooCommerce\Settings\Settings;

/**
 * The costs of products with a price that follows the rule are read again
 * daily, and when the product is checked again with Pixonaut.
 */
final class CostRefreshTest extends PricingTestCase {

	private bool $connected = true;

	private CostRefresh $refresh;

	public function set_up(): void {
		parent::set_up();
		$this->refresh = new CostRefresh( $this->pricing, fn (): bool => $this->connected, $this->logger, static fn (): int => self::NOW );
	}

	public function tear_down(): void {
		as_unschedule_all_actions( CostRefresh::HOOK );
		as_unschedule_all_actions( CostRefresh::PAGE_HOOK );
		parent::tear_down();
	}

	public function test_the_daily_job_reads_the_costs_of_the_products_that_follow_the_rule(): void {
		$this->store_rule();
		$this->plugin->settings()->set( Settings::PRICE_AUTOMATIC, true );
		$followed = $this->applied_product( '200' );
		// Never applied: its costs are not read by themselves.
		$this->simple_product( 'v_mug', '300' );
		$this->script_costs( array( 'v_mug' => '155' ) );

		$this->refresh->page( 0 );

		$this->assertCount( 2, $this->recorder->requests, 'one read when it was applied, one now' );
		$this->assertSame( '309', $this->price( $followed ) );
		$this->assertFalse( as_has_scheduled_action( CostRefresh::PAGE_HOOK ), 'a page that was not full is the last' );
	}

	public function test_a_product_whose_costs_cannot_be_read_keeps_its_prices_and_is_logged(): void {
		$this->store_rule();
		$followed = $this->applied_product( '200' );
		$this->recorder->fail();
		$this->recorder->fail();
		$this->recorder->fail();

		$this->refresh->page( 0 );

		$this->assertSame( '399', $this->price( $followed ) );
		$warnings = array_values( array_filter( $this->logger->entries, static fn ( array $entry ): bool => 'warning' === $entry['level'] ) );
		$this->assertCount( 1, $warnings );
		$this->assertSame( $followed, $warnings[0]['context']['product_id'] );
	}

	public function test_nothing_is_read_while_the_store_is_not_connected(): void {
		$this->store_rule();
		$this->applied_product( '200' );
		$this->connected = false;

		$this->refresh->page( 0 );

		$this->assertCount( 1, $this->recorder->requests );
	}

	public function test_a_product_checked_again_with_pixonaut_has_its_costs_read_once(): void {
		$this->store_rule();
		$ids = $this->variable_product(
			array(
				'v_a3' => '',
				'v_a4' => '',
			)
		);
		$this->script_costs(
			array(
				'v_a3' => '200',
				'v_a4' => '100',
			)
		);
		$this->pricing->refresh( $this->parent_of( $ids['v_a3'] ) );
		$this->pricing->apply( $this->parent_of( $ids['v_a3'] ) );
		$this->script_costs(
			array(
				'v_a3' => '155',
				'v_a4' => '100',
			)
		);

		// The re-check tells of the product and of each variation with a setup of its own.
		$this->refresh->rechecked( $this->parent_of( $ids['v_a3'] ) );
		$this->refresh->rechecked( $this->product( $ids['v_a3'] ) );

		$this->assertCount( 2, $this->recorder->requests );
		$this->assertSame( PriceState::FLAG_MARGIN, PriceState::stored_on( $this->product( $ids['v_a3'] ) )->flag );
		$this->assertSame( 1, ProductPricing::flagged( $this->parent_of( $ids['v_a3'] ) ) );

		// A product nobody applied the rule to is left alone.
		$own = $this->simple_product( 'v_mug', '300' );
		$this->refresh->rechecked( $this->product( $own ) );
		$this->assertCount( 2, $this->recorder->requests );
	}

	public function test_the_job_is_scheduled_daily_and_starts_at_the_first_page(): void {
		// The plugin scheduled its own when it booted.
		as_unschedule_all_actions( CostRefresh::HOOK );

		$this->refresh->schedule();
		$this->refresh->schedule();

		$this->assertSame( self::NOW + DAY_IN_SECONDS, as_next_scheduled_action( CostRefresh::HOOK, array(), CostRefresh::GROUP ) );
		$this->assertCount(
			1,
			as_get_scheduled_actions(
				array(
					'hook'   => CostRefresh::HOOK,
					'status' => \ActionScheduler_Store::STATUS_PENDING,
				)
			)
		);

		$this->refresh->start();
		$this->refresh->start();

		$this->assertCount(
			1,
			as_get_scheduled_actions(
				array(
					'hook'   => CostRefresh::PAGE_HOOK,
					'status' => \ActionScheduler_Store::STATUS_PENDING,
				)
			)
		);
	}

	/**
	 * A simple product the rule was applied to at a cost.
	 *
	 * @param string $cost Its cost.
	 */
	private function applied_product( string $cost ): int {
		$id = $this->simple_product( 'v_mug', '' );
		$this->script_costs( array( 'v_mug' => $cost ) );
		$this->pricing->refresh( $this->product( $id ) );
		$this->pricing->apply( $this->product( $id ) );
		return $id;
	}
}
