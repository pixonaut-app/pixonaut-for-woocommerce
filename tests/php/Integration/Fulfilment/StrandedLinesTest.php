<?php
/**
 * @package Pixonaut\WooCommerce
 */

declare(strict_types=1);

namespace Pixonaut\WooCommerce\Tests\Integration\Fulfilment;

use ActionScheduler;
use Pixonaut\WooCommerce\Api\WcLogger;
use Pixonaut\WooCommerce\Connection\StoredConnection;
use Pixonaut\WooCommerce\Fulfilment\FulfilmentJobs;
use Pixonaut\WooCommerce\Fulfilment\FulfilmentSweep;
use Pixonaut\WooCommerce\Fulfilment\LineActions;
use Pixonaut\WooCommerce\Fulfilment\LineState;
use Pixonaut\WooCommerce\Plugin;
use Pixonaut\WooCommerce\Refs;
use Pixonaut\WooCommerce\Tests\Support\ActionSchedulerRunner;
use Pixonaut\WooCommerce\Tests\Support\FulfilmentAnswers;
use RuntimeException;

/**
 * Orders paid without passing Processing, and lines whose job was lost:
 * each still reaches Pixonaut, through any paid status, the daily sweep or
 * Retry on the order screen, with one job per line at a time.
 */
final class StrandedLinesTest extends FulfilmentTestCase {

	private const ORDER_ID = 'o_1';

	/**
	 * Days back of an order the sweep no longer looks at.
	 */
	private const PAST_WINDOW_DAYS = 31;

	public function test_an_order_on_hold_marked_completed_is_sent(): void {
		$this->order->update_status( 'on-hold' );
		$this->assertSame( array(), ActionSchedulerRunner::pending( FulfilmentJobs::ACTION ) );

		$this->order->update_status( 'completed' );

		$this->assert_sent();
	}

	public function test_an_order_created_as_completed_is_sent(): void {
		// A phone order the admin adds goes from its first status straight to Completed.
		$this->assertSame( 'pending', $this->order->get_status() );
		$this->assertSame( array(), ActionSchedulerRunner::pending( FulfilmentJobs::ACTION ) );

		$this->order->update_status( 'completed' );

		$this->assert_sent();
	}

	public function test_no_trigger_nor_the_sweep_schedules_a_second_job(): void {
		$this->order->update_status( 'processing' );
		do_action( 'woocommerce_payment_complete', $this->order->get_id() );
		$this->order->update_status( 'completed' );
		$this->sweep();

		$this->assertCount( 1, ActionSchedulerRunner::pending( FulfilmentJobs::ACTION ) );
		$this->assertNotContains( LineActions::RETRY, $this->plugin_actions()->available( $this->line(), null ) );
	}

	public function test_the_sweep_schedules_an_approved_line_whose_job_failed(): void {
		$this->order->update_status( 'processing' );
		$this->assertTrue( $this->line()->transition( LineState::APPROVED ) );
		// The job dies before it does anything, as on a PHP fatal or a timeout.
		$fatal = static function (): void {
			throw new RuntimeException( 'Maximum execution time exceeded' );
		};
		add_action( FulfilmentJobs::ACTION, $fatal, 1 );
		$this->assertSame( 1, ActionSchedulerRunner::run_once( FulfilmentJobs::ACTION ) );
		remove_action( FulfilmentJobs::ACTION, $fatal, 1 );
		$this->assertCount( 1, ActionSchedulerRunner::failed( FulfilmentJobs::ACTION ) );
		$this->assertSame( array(), ActionSchedulerRunner::pending( FulfilmentJobs::ACTION ) );

		$this->sweep();
		$this->sweep();

		$this->assertCount( 1, ActionSchedulerRunner::pending( FulfilmentJobs::ACTION ) );
		$this->recorder
			->json( 200, FulfilmentAnswers::orders( array() ) )
			->json( 200, FulfilmentAnswers::design_approved( self::DESIGN, self::REVISION ) )
			->json( 201, FulfilmentAnswers::order( self::ORDER_ID, array( 'p_front' ), $this->external_ref() ) );
		$this->assertSame( 1, ActionSchedulerRunner::run_pending( FulfilmentJobs::ACTION ) );
		$this->assertSame( LineState::ORDERED, $this->line()->state() );
	}

	public function test_the_sweep_schedules_an_approved_line_with_no_job_at_all(): void {
		$this->assertTrue( $this->line()->transition( LineState::APPROVED ) );
		// Paid without any trigger seeing it, as before the store ran this version.
		remove_all_actions( 'woocommerce_order_status_changed' );
		$this->order->update_status( 'completed' );
		$this->assertSame( array(), ActionSchedulerRunner::pending( FulfilmentJobs::ACTION ) );

		$this->sweep();

		$this->assertCount( 1, ActionSchedulerRunner::pending( FulfilmentJobs::ACTION ) );
	}

	public function test_the_sweep_leaves_a_line_held_back_on_purpose(): void {
		add_filter( 'pixonaut_wc_fulfilment_can_fulfil', '__return_false' );
		$this->order->update_status( 'processing' );
		$this->assertSame( 1, ActionSchedulerRunner::run_pending( FulfilmentJobs::ACTION ) );

		$this->sweep();

		$this->assertSame( array(), ActionSchedulerRunner::pending( FulfilmentJobs::ACTION ) );
	}

	public function test_the_sweep_leaves_unpaid_old_and_unconnected_orders(): void {
		$unpaid = $this->order;
		$unpaid->update_status( 'on-hold' );
		$old = $this->paid_order();
		$old->set_date_created( time() - self::PAST_WINDOW_DAYS * DAY_IN_SECONDS );
		$old->save();
		remove_all_actions( 'woocommerce_order_status_changed' );
		$old->update_status( 'completed' );

		$this->sweep();
		$this->assertSame( array(), ActionSchedulerRunner::pending( FulfilmentJobs::ACTION ) );

		$unpaid->update_status( 'completed' );
		$this->store->clear();
		$this->sweep();
		$this->assertCount( 0, ActionSchedulerRunner::pending( FulfilmentJobs::ACTION ), 'nothing is swept while the store is not connected' );
	}

	public function test_an_old_order_with_a_higher_id_does_not_stop_the_sweep(): void {
		remove_all_actions( 'woocommerce_order_status_changed' );
		$this->order->update_status( 'completed' );
		// Imported after it, dated before the window.
		$old = $this->paid_order();
		$old->set_date_created( time() - self::PAST_WINDOW_DAYS * DAY_IN_SECONDS );
		$old->save();
		$old->update_status( 'completed' );

		$this->sweep();

		$this->assertSame( array( array( 'item_id' => $this->item->get_id() ) ), $this->pending_args() );
	}

	public function test_the_sweep_reads_every_page_one_job_each(): void {
		remove_all_actions( 'woocommerce_order_status_changed' );
		$this->order->update_status( 'completed' );
		$newer = $this->paid_order();
		$newer->update_status( 'completed' );
		$newest = $this->paid_order();
		$newest->update_status( 'completed' );
		$store = $this->store;
		$sweep = new FulfilmentSweep( FulfilmentJobs::schedule( ... ), static fn (): ?StoredConnection => $store->load(), new WcLogger(), null, 1 );
		remove_all_actions( FulfilmentSweep::PAGE_HOOK );
		$sweep->register();

		$sweep->sweep();
		$pages = 0;
		while ( 1 === ActionSchedulerRunner::run_once( FulfilmentSweep::PAGE_HOOK ) ) {
			++$pages;
		}

		$this->assertCount( 3, ActionSchedulerRunner::pending( FulfilmentJobs::ACTION ) );
		// Three pages of one order, an empty fourth that ends the state, and an empty page for each other state.
		$this->assertSame( 3 + 1 + count( LineState::FULFILLABLE ) - 1, $pages );
	}

	public function test_a_job_run_while_the_store_is_not_connected_is_swept_once_it_is(): void {
		$this->order->update_status( 'processing' );
		$this->store->clear();

		$this->assertSame( 1, ActionSchedulerRunner::run_once( FulfilmentJobs::ACTION ) );
		$this->assertCount( 1, ActionSchedulerRunner::failed( FulfilmentJobs::ACTION ) );
		$this->assertSame( LineState::COMMITTED, $this->line()->state() );

		$this->store->save( self::connection() );
		$this->sweep();

		$this->assertCount( 1, ActionSchedulerRunner::pending( FulfilmentJobs::ACTION ) );
	}

	public function test_the_newest_job_decides_whatever_its_scheduled_date(): void {
		remove_all_actions( 'woocommerce_order_status_changed' );
		$this->order->update_status( 'completed' );
		$args   = array( 'item_id' => $this->item->get_id() );
		$failed = as_enqueue_async_action( FulfilmentJobs::ACTION, $args, FulfilmentJobs::GROUP );
		ActionScheduler::store()->mark_failure( $failed );
		// Due before the failed one, yet scheduled after it, and completed.
		$completed = as_schedule_single_action( time() - HOUR_IN_SECONDS, FulfilmentJobs::ACTION, $args, FulfilmentJobs::GROUP );
		ActionScheduler::store()->mark_complete( $completed );

		$this->sweep();
		$this->assertSame( array(), ActionSchedulerRunner::pending( FulfilmentJobs::ACTION ), 'the newest job completed' );

		$newest = as_schedule_single_action( time() - DAY_IN_SECONDS, FulfilmentJobs::ACTION, $args, FulfilmentJobs::GROUP );
		ActionScheduler::store()->mark_failure( $newest );
		$this->sweep();
		$this->assertCount( 1, ActionSchedulerRunner::pending( FulfilmentJobs::ACTION ), 'the newest job failed' );
	}

	public function test_the_sweep_runs_daily(): void {
		as_unschedule_all_actions( FulfilmentSweep::HOOK, array(), FulfilmentJobs::GROUP );

		do_action( 'action_scheduler_init' );
		do_action( 'action_scheduler_init' );

		$this->assertCount( 1, ActionSchedulerRunner::pending( FulfilmentSweep::HOOK ) );
	}

	public function test_a_paid_line_whose_job_was_lost_offers_retry_and_retry_schedules_it(): void {
		$actions = $this->plugin_actions();
		$this->assertTrue( $this->line()->transition( LineState::APPROVED ) );
		$this->assertSame( array(), $actions->available( $this->line(), null ), 'an unpaid order is not sent' );
		$this->order->update_status( 'processing' );
		$this->assertSame( array(), $actions->available( $this->line(), null ), 'a line with a pending job waits for it' );
		// The plugin was deactivated while the job was pending.
		as_unschedule_all_actions( FulfilmentJobs::ACTION, array( 'item_id' => $this->item->get_id() ), FulfilmentJobs::GROUP );

		$this->assertSame( array( LineActions::RETRY ), $actions->available( $this->line(), null ) );
		$this->assertSame( LineActions::DONE, $actions->run( $this->line(), LineActions::RETRY )->code );

		$this->assertCount( 1, ActionSchedulerRunner::pending( FulfilmentJobs::ACTION ) );
		$this->assertSame( LineState::APPROVED, $this->line()->state() );
		$this->assertSame( array(), $actions->available( $this->line(), null ) );
		$this->assertSame( array(), $this->recorder->requests );
	}

	/**
	 * The line's one job ran, and placed its printshop order.
	 */
	private function assert_sent(): void {
		$this->recorder
			->json( 200, FulfilmentAnswers::approved() )
			->json( 200, FulfilmentAnswers::orders( array() ) )
			->json( 200, FulfilmentAnswers::design_approved( self::DESIGN, self::REVISION ) )
			->json( 201, FulfilmentAnswers::order( self::ORDER_ID, array( 'p_front' ), $this->external_ref() ) );

		$this->assertSame( 1, ActionSchedulerRunner::run_pending( FulfilmentJobs::ACTION ) );

		$this->assertSame( array(), ActionSchedulerRunner::failed( FulfilmentJobs::ACTION ) );
		$this->assertSame( 1, $this->count_sent( 'POST', '/v1/orders' ) );
		$this->assertSame( LineState::ORDERED, $this->line()->state() );
	}

	/**
	 * Runs the daily sweep and every page it queues.
	 */
	private function sweep(): void {
		do_action( FulfilmentSweep::HOOK );
		ActionSchedulerRunner::run_pending( FulfilmentSweep::PAGE_HOOK );
	}

	/**
	 * @return list<mixed> Arguments of the pending fulfilment jobs.
	 */
	private function pending_args(): array {
		return array_map( static fn ( int $id ): mixed => ActionScheduler::store()->fetch_action( (string) $id )->get_args(), ActionSchedulerRunner::pending( FulfilmentJobs::ACTION ) );
	}

	private function external_ref(): string {
		return Refs::order_item_ref( self::SITE, $this->order->get_id(), $this->item->get_id() );
	}

	private function plugin_actions(): LineActions {
		return Plugin::boot( dirname( __DIR__, 4 ) . '/pixonaut-for-woocommerce.php' )->line_actions();
	}
}
