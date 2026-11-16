<?php
/**
 * @package Pixonaut\WooCommerce
 */

declare(strict_types=1);

namespace Pixonaut\WooCommerce\Tests\Integration\Pricing;

use Pixonaut\WooCommerce\Pricing\CostRefresh;
use Pixonaut\WooCommerce\Settings\Settings;
use WC_Product_Variable;

/**
 * While the store shows delivery times, a product or variation printed on a
 * blank says how long its printshop says it takes, after its availability:
 * only what the printshop tells, read with the cost.
 */
final class DeliveryTimeTest extends PricingTestCase {

	private const WHOLE_WAY = array(
		'production' => null,
		'shipping'   => null,
		'delivery'   => array(
			'minDays' => 4,
			'maxDays' => 7,
		),
	);

	public function set_up(): void {
		parent::set_up();
		$this->plugin->settings()->set( Settings::DELIVERY_TIME_SHOW, true );
		// Switching the times on starts a read, which the tests of that start for themselves.
		as_unschedule_all_actions( CostRefresh::PAGE_HOOK );
	}

	public function tear_down(): void {
		$this->plugin->settings()->set( Settings::DELIVERY_TIME_SHOW, false );
		$this->plugin->settings()->set( Settings::DELIVERY_TIME_HANDLING_DAYS, 0 );
		as_unschedule_all_actions( CostRefresh::HOOK );
		as_unschedule_all_actions( CostRefresh::PAGE_HOOK );
		parent::tear_down();
	}

	public function test_a_product_says_the_days_its_printshop_tells_from_order_to_door(): void {
		$id = $this->read_product( self::WHOLE_WAY );

		$this->assertSame( '<p class="pixonaut-delivery-time">Delivered in 4–7 days</p>', wc_get_stock_html( $this->product( $id ) ) );
	}

	public function test_each_variation_says_its_own_time_with_its_availability(): void {
		$ids = $this->variable_product(
			array(
				'v_a3' => '200',
				'v_a4' => '100',
			)
		);
		$this->script_costs(
			array(
				'v_a3' => '120',
				'v_a4' => '60',
			),
			'49',
			null,
			self::WHOLE_WAY
		);
		$parent = $this->parent_of( $ids['v_a3'] );
		$this->pricing->read_costs( $parent );
		$parent = $this->parent_of( $ids['v_a3'] );
		$this->assertInstanceOf( WC_Product_Variable::class, $parent );

		$shown = $parent->get_available_variation( $this->product( $ids['v_a4'] ) );

		$this->assertIsArray( $shown );
		$this->assertStringContainsString( 'Delivered in 4–7 days', (string) $shown['availability_html'] );
	}

	public function test_the_stores_handling_days_are_added_to_the_days_that_start_at_the_order(): void {
		$id = $this->read_product( self::WHOLE_WAY );
		$this->plugin->settings()->set( Settings::DELIVERY_TIME_HANDLING_DAYS, 2 );

		$this->assertStringContainsString( 'Delivered in 6–9 days', wc_get_stock_html( $this->product( $id ) ) );
	}

	public function test_a_making_and_a_post_told_apart_are_the_whole_way_together(): void {
		$id = $this->read_product(
			array(
				'production' => self::span( 2, 3 ),
				'shipping'   => self::span( 3, 6 ),
				'delivery'   => null,
			)
		);

		$this->assertStringContainsString( 'Delivered in 5–9 days', wc_get_stock_html( $this->product( $id ) ) );
	}

	public function test_a_printshop_that_tells_the_making_alone_gets_that_said_and_no_delivery(): void {
		$id = $this->read_product(
			array(
				'production' => self::span( 3, 3 ),
				'shipping'   => null,
				'delivery'   => null,
			)
		);
		$this->plugin->settings()->set( Settings::DELIVERY_TIME_HANDLING_DAYS, 1 );

		$html = wc_get_stock_html( $this->product( $id ) );

		$this->assertStringContainsString( 'Made in 4 days', $html );
		$this->assertStringNotContainsString( 'Delivered', $html );
	}

	public function test_a_printshop_that_tells_the_post_alone_gets_that_said_without_the_handling_days(): void {
		$id = $this->read_product(
			array(
				'production' => null,
				'shipping'   => self::span( 5, 10 ),
				'delivery'   => null,
			)
		);
		$this->plugin->settings()->set( Settings::DELIVERY_TIME_HANDLING_DAYS, 2 );

		$this->assertStringContainsString( 'Shipping takes 5–10 days', wc_get_stock_html( $this->product( $id ) ) );
	}

	public function test_nothing_is_said_where_the_printshop_tells_no_time_or_none_was_read(): void {
		$told_none = $this->read_product(
			array(
				'production' => null,
				'shipping'   => null,
				'delivery'   => null,
			)
		);
		$unread    = $this->simple_product( 'v_other', '300' );

		$this->assertSame( '', wc_get_stock_html( $this->product( $told_none ) ) );
		$this->assertSame( '', wc_get_stock_html( $this->product( $unread ) ) );
	}

	public function test_nothing_is_said_while_the_setting_is_off_or_the_product_is_out_of_stock(): void {
		$id = $this->read_product( self::WHOLE_WAY );

		$this->plugin->settings()->set( Settings::DELIVERY_TIME_SHOW, false );
		$this->assertSame( '', wc_get_stock_html( $this->product( $id ) ) );

		$this->plugin->settings()->set( Settings::DELIVERY_TIME_SHOW, true );
		$product = $this->product( $id );
		$product->set_stock_status( 'outofstock' );
		$product->save();
		$this->assertStringNotContainsString( 'pixonaut-delivery-time', wc_get_stock_html( $this->product( $id ) ) );
	}

	public function test_the_daily_job_reads_every_product_on_a_blank_while_times_are_shown(): void {
		// No rule was applied to it: only its delivery time needs its cost.
		$id = $this->simple_product( 'v_mug', '300' );
		$this->script_costs( array( 'v_mug' => '155' ), '49', null, self::WHOLE_WAY );
		$refresh = new CostRefresh( $this->pricing, static fn (): bool => true, $this->logger, static fn (): int => self::NOW, static fn (): bool => true );

		$refresh->page( 0 );

		$this->assertCount( 1, $this->recorder->requests );
		$this->assertStringContainsString( 'Delivered in 4–7 days', wc_get_stock_html( $this->product( $id ) ) );
		$this->assertSame( '300', $this->price( $id ), 'a price that follows no rule is left alone' );
	}

	public function test_switching_the_times_on_starts_a_read_of_the_costs(): void {
		$this->plugin->settings()->set( Settings::DELIVERY_TIME_SHOW, false );
		$refresh = new CostRefresh( $this->pricing, static fn (): bool => true, $this->logger, static fn (): int => self::NOW, static fn (): bool => true );
		$refresh->register();

		$this->plugin->settings()->set( Settings::DELIVERY_TIME_HANDLING_DAYS, 3 );
		$this->assertFalse( as_has_scheduled_action( CostRefresh::PAGE_HOOK ), 'another setting starts nothing' );

		$this->plugin->settings()->set( Settings::DELIVERY_TIME_SHOW, true );
		$this->assertTrue( as_has_scheduled_action( CostRefresh::PAGE_HOOK ) );

		remove_action( 'update_option_' . Settings::OPTION, array( $refresh, 'settings_changed' ), 10 );
		remove_action( 'add_option_' . Settings::OPTION, array( $refresh, 'settings_added' ), 10 );
	}

	public function test_switching_the_times_on_as_the_stores_first_saved_setting_starts_a_read(): void {
		delete_option( Settings::OPTION );
		$refresh = new CostRefresh( $this->pricing, static fn (): bool => true, $this->logger, static fn (): int => self::NOW, static fn (): bool => true );
		$refresh->register();

		$this->plugin->settings()->set( Settings::DELIVERY_TIME_SHOW, true );

		$this->assertTrue( as_has_scheduled_action( CostRefresh::PAGE_HOOK ) );

		remove_action( 'update_option_' . Settings::OPTION, array( $refresh, 'settings_changed' ), 10 );
		remove_action( 'add_option_' . Settings::OPTION, array( $refresh, 'settings_added' ), 10 );
	}

	public function test_switching_the_times_on_while_a_read_is_under_way_starts_again_at_the_first_page(): void {
		$this->plugin->settings()->set( Settings::DELIVERY_TIME_SHOW, false );
		$refresh = new CostRefresh( $this->pricing, static fn (): bool => true, $this->logger, static fn (): int => self::NOW, static fn (): bool => true );
		$refresh->register();
		// The day's read of the products that follow the rule has passed product 1000.
		as_enqueue_async_action( CostRefresh::PAGE_HOOK, array( 1000 ), CostRefresh::GROUP );

		$this->plugin->settings()->set( Settings::DELIVERY_TIME_SHOW, true );

		$this->assertFalse( as_has_scheduled_action( CostRefresh::PAGE_HOOK, array( 1000 ), CostRefresh::GROUP ) );
		$this->assertTrue( as_has_scheduled_action( CostRefresh::PAGE_HOOK, array( 0 ), CostRefresh::GROUP ) );

		remove_action( 'update_option_' . Settings::OPTION, array( $refresh, 'settings_changed' ), 10 );
		remove_action( 'add_option_' . Settings::OPTION, array( $refresh, 'settings_added' ), 10 );
	}

	/**
	 * A simple product whose cost was read with `$times`.
	 *
	 * @param array<string, mixed> $times The cost's `times`, as the answer writes them.
	 */
	private function read_product( array $times ): int {
		$id = $this->simple_product( 'v_mug', '300' );
		$this->script_costs( array( 'v_mug' => '155' ), '49', null, $times );
		$this->pricing->read_costs( $this->product( $id ) );
		return $id;
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
