<?php
/**
 * @package Pixonaut\WooCommerce
 */

declare(strict_types=1);

namespace Pixonaut\WooCommerce\Tests\Integration\Webhooks;

use Pixonaut\WooCommerce\Admin\Notices;
use Pixonaut\WooCommerce\Api\Hosts;
use Pixonaut\WooCommerce\Api\WcLogger;
use Pixonaut\WooCommerce\Fulfilment\AttentionAlerts;
use Pixonaut\WooCommerce\Fulfilment\LineState;
use Pixonaut\WooCommerce\Fulfilment\OrderEventLines;
use Pixonaut\WooCommerce\Fulfilment\OrderFailed;
use Pixonaut\WooCommerce\Plugin;
use Pixonaut\WooCommerce\Tests\Support\FulfilmentAnswers;
use Pixonaut\WooCommerce\Tests\Support\HttpRecorder;
use Pixonaut\WooCommerce\Webhooks\Event;
use WC_Order_Item_Product;

/**
 * `order.submitted` for a retry of a failed order hands the line to the order
 * that replaced it, then reads that order from Pixonaut answered from a script.
 */
final class OrderSubmittedTest extends OrderEventsTestCase {

	private const RETRY_ID = 'a7b8c9d0-e1f2-4a3b-8c4d-5e6f7a8b9c0d';

	private const SECOND_RETRY_ID = 'b8c9d0e1-f2a3-4b4c-9d5e-6f7a8b9c0d1e';

	/**
	 * Answers the plugin's own client, where the handler reads the new order.
	 *
	 * @var HttpRecorder
	 */
	private HttpRecorder $recorder;

	public function set_up(): void {
		parent::set_up();
		$this->recorder = ( new HttpRecorder( Hosts::server_api() ) )->start();
	}

	public function tear_down(): void {
		$this->recorder->stop();
		parent::tear_down();
	}

	public function test_a_retried_order_hands_the_line_to_its_replacement(): void {
		$item = $this->failed_line();
		$this->reads( self::RETRY_ID, array( 'submitted' ) );

		$this->deliver( $this->submitted_event( self::RETRY_ID, self::ORDER_ID ) );
		$this->run_jobs();

		$line = LineState::of( $item );
		$this->assertSame( LineState::ORDERED, $line->state() );
		$this->assertSame( self::RETRY_ID, $line->ref( LineState::REF_ORDER_ID ) );
		$this->assertSame( array( self::ORDER_ID ), $line->replaced_order_ids() );
		$this->assertSame( 'submitted', $line->ref( LineState::REF_ORDER_STATUS ) );
		$this->assertStringContainsString( 'again, as order ' . self::RETRY_ID, $this->notes( $item ) );
		$this->assertArrayNotHasKey( AttentionAlerts::key( $item->get_order_id() ), (array) get_option( Notices::OPTION ), 'the notice goes once nothing needs attention' );

		$this->deliver( $this->status_event( 'submitted', 'queued', null, array( 'orderId' => self::RETRY_ID ) ) );
		$this->deliver( $this->status_event( 'queued', 'printed', null, array( 'orderId' => self::RETRY_ID ) ) );
		$this->run_jobs();

		$this->assertSame( 'printed', LineState::of( $item )->ref( LineState::REF_ORDER_STATUS ), 'the replacement\'s events find the line' );
	}

	public function test_the_replacement_failing_too_flags_the_line_again(): void {
		$item = $this->failed_line();
		$this->reads( self::RETRY_ID, array( 'submitted' ) );
		$this->deliver( $this->submitted_event( self::RETRY_ID, self::ORDER_ID ) );
		$this->run_jobs();

		$this->deliver( $this->failed_event( 'the printshop is closed', self::RETRY_ID ) );
		$this->run_jobs();

		$line = LineState::of( $item );
		$this->assertSame( LineState::NEEDS_ATTENTION, $line->state() );
		$this->assertSame( 'failed', $line->ref( LineState::REF_ORDER_STATUS ) );
	}

	public function test_a_retry_of_a_retry_hands_the_line_on_again(): void {
		$item = $this->failed_line();
		$this->reads( self::RETRY_ID, array( 'submitted' ) );
		$this->reads( self::SECOND_RETRY_ID, array( 'submitted' ) );
		$this->deliver( $this->submitted_event( self::RETRY_ID, self::ORDER_ID ) );
		$this->deliver( $this->failed_event( 'refused', self::RETRY_ID ) );
		$this->deliver( $this->submitted_event( self::SECOND_RETRY_ID, self::RETRY_ID ) );
		$this->run_jobs();

		$line = LineState::of( $item );
		$this->assertSame( LineState::ORDERED, $line->state() );
		$this->assertSame( self::SECOND_RETRY_ID, $line->ref( LineState::REF_ORDER_ID ) );
		$this->assertSame( array( self::ORDER_ID, self::RETRY_ID ), $line->replaced_order_ids() );
	}

	public function test_a_late_event_of_the_replaced_order_leaves_the_line_alone(): void {
		$item = $this->failed_line();
		$this->reads( self::RETRY_ID, array( 'submitted' ) );
		$this->deliver( $this->submitted_event( self::RETRY_ID, self::ORDER_ID ) );
		$this->run_jobs();

		$this->deliver( $this->failed_event( 'late', self::ORDER_ID ) );
		$this->run_jobs();

		$this->assertSame( LineState::ORDERED, LineState::of( $item )->state() );
	}

	public function test_a_replacement_of_an_order_the_store_holds_no_line_for_changes_nothing(): void {
		$item   = $this->failed_line();
		$before = LineState::of( $item );

		$this->deliver( $this->submitted_event( self::SECOND_RETRY_ID, self::RETRY_ID ) );
		$this->run_jobs();

		$line = LineState::of( $item );
		$this->assertSame( LineState::NEEDS_ATTENTION, $line->state() );
		$this->assertSame( self::ORDER_ID, $line->ref( LineState::REF_ORDER_ID ) );
		$this->assertSame( $before->changed_at(), $line->changed_at() );
		$this->assertSame( array(), LineState::for_order_id( self::SECOND_RETRY_ID ) );
	}

	public function test_a_replacement_whose_external_ref_differs_is_refused(): void {
		$item = $this->failed_line();

		$this->deliver( $this->submitted_event( self::RETRY_ID, self::ORDER_ID, 'wc_site_1042_99' ) );
		$this->run_jobs();

		$line = LineState::of( $item );
		$this->assertSame( LineState::NEEDS_ATTENTION, $line->state() );
		$this->assertSame( self::ORDER_ID, $line->ref( LineState::REF_ORDER_ID ) );
	}

	public function test_a_first_order_changes_nothing(): void {
		$item = $this->pixonaut_line();

		$this->deliver( $this->submitted_event( self::ORDER_ID, null ) );
		$this->run_jobs();

		$this->assertSame( LineState::APPROVED, LineState::of( $item )->state() );
	}

	public function test_a_waiting_order_handed_to_its_printshop_moves_the_line_on(): void {
		$item = $this->waiting_line();
		$this->reads( self::ORDER_ID, array( 'awaiting_prints', 'submitted' ) );

		$this->deliver( $this->submitted_event( self::ORDER_ID, null ) );
		$this->run_jobs();

		$line = LineState::of( $item );
		$this->assertSame( LineState::ORDERED, $line->state() );
		$this->assertSame( 'submitted', $line->ref( LineState::REF_ORDER_STATUS ) );

		$this->deliver( $this->status_event( 'submitted', 'queued' ) );
		$this->run_jobs();

		$this->assertSame( 'queued', LineState::of( $item )->ref( LineState::REF_ORDER_STATUS ) );
	}

	public function test_an_unknown_outcome_of_the_hand_on_handled_first_is_applied_with_the_submission(): void {
		$item = $this->waiting_line();
		$this->deliver( $this->status_event( 'submitted', 'outcome_unknown' ) );
		$this->run_jobs();
		$this->assertSame( 'awaiting_prints', LineState::of( $item )->ref( LineState::REF_ORDER_STATUS ) );

		$this->reads( self::ORDER_ID, array( 'awaiting_prints', 'submitted', 'outcome_unknown' ) );
		$this->deliver( $this->submitted_event( self::ORDER_ID, null ) );
		$this->run_jobs();

		$line = LineState::of( $item );
		$this->assertSame( LineState::NEEDS_ATTENTION, $line->state() );
		$this->assertSame( 'outcome_unknown', $line->ref( LineState::REF_ORDER_STATUS ) );
	}

	public function test_a_hand_on_whose_order_could_not_be_read_is_tried_again(): void {
		$item = $this->waiting_line();
		$this->recorder->fail();

		$this->deliver( $this->submitted_event( self::ORDER_ID, null ) );
		$this->run_jobs();

		$this->assertSame( 'awaiting_prints', LineState::of( $item )->ref( LineState::REF_ORDER_STATUS ) );
		$this->assertCount( 1, $this->pending_jobs(), 'the event is tried again' );

		$this->reads( self::ORDER_ID, array( 'awaiting_prints', 'submitted' ) );
		$this->run_jobs();

		$this->assertSame( 'submitted', LineState::of( $item )->ref( LineState::REF_ORDER_STATUS ) );
	}

	public function test_the_submission_of_an_order_that_moved_on_leaves_the_line_alone(): void {
		$item = $this->pixonaut_line();
		$this->deliver( $this->status_event( 'submitted', 'queued' ) );
		$this->run_jobs();

		$this->deliver( $this->submitted_event( self::ORDER_ID, null ) );
		$this->run_jobs();

		$this->assertSame( 'queued', LineState::of( $item )->ref( LineState::REF_ORDER_STATUS ) );
	}

	public function test_a_replacement_reported_twice_is_noted_once(): void {
		$item  = $this->failed_line();
		$event = $this->submitted_event( self::RETRY_ID, self::ORDER_ID );
		$this->reads( self::RETRY_ID, array( 'submitted' ) );
		$this->reads( self::RETRY_ID, array( 'submitted' ) );

		$this->deliver( $event );
		$this->run_jobs();
		$this->deliver( array( 'id' => $event['id'] . '-again' ) + $event );
		$this->run_jobs();

		$this->assertSame( 1, substr_count( $this->notes( $item ), self::RETRY_ID ) );
		$this->assertSame( array( self::ORDER_ID ), LineState::of( $item )->replaced_order_ids() );
	}

	public function test_a_line_that_took_the_replacement_on_before_its_status_was_applied_catches_up(): void {
		$item = $this->failed_line();
		$line = LineState::of( $item );
		// A run that stopped after the line was saved.
		$line->replace_order( self::RETRY_ID );
		$line->save();
		$this->reads( self::RETRY_ID, array( 'submitted' ) );

		$this->deliver( $this->submitted_event( self::RETRY_ID, self::ORDER_ID ) );
		$this->run_jobs();

		$this->assertSame( LineState::ORDERED, LineState::of( $item )->state() );
	}

	public function test_a_line_whose_order_id_index_a_stopped_run_left_behind_is_found_and_repaired(): void {
		$item = $this->failed_line();
		$line = LineState::of( $item );
		$line->replace_order( self::RETRY_ID );
		$line->save();
		// The document was written, the index not.
		wc_update_order_item_meta( $item->get_id(), LineState::ORDER_ID_META_KEY, self::ORDER_ID );
		$this->assertSame( array(), LineState::for_order_id( self::RETRY_ID ) );
		$this->reads( self::RETRY_ID, array( 'submitted' ) );

		$this->deliver( $this->submitted_event( self::RETRY_ID, self::ORDER_ID ) );
		$this->run_jobs();

		$this->assertSame( LineState::ORDERED, LineState::of( $item )->state() );
		$this->assertCount( 1, LineState::for_order_id( self::RETRY_ID ), 'the index holds the new id again' );
	}

	public function test_a_status_of_the_replacement_handled_before_its_submission_is_caught_up(): void {
		$item = $this->failed_line();
		$this->deliver( $this->status_event( 'submitted', 'queued', null, array( 'orderId' => self::RETRY_ID ) ) );
		$this->run_jobs();
		$this->reads( self::RETRY_ID, array( 'submitted', 'queued' ) );

		$this->deliver( $this->submitted_event( self::RETRY_ID, self::ORDER_ID ) );
		$this->run_jobs();

		$line = LineState::of( $item );
		$this->assertSame( LineState::ORDERED, $line->state() );
		$this->assertSame( 'queued', $line->ref( LineState::REF_ORDER_STATUS ) );
	}

	public function test_a_retry_of_a_retry_handled_first_is_followed_once_the_first_retry_is(): void {
		$item = $this->failed_line();
		$this->deliver( $this->submitted_event( self::SECOND_RETRY_ID, self::RETRY_ID ) );
		$this->run_jobs();
		$this->assertSame( self::ORDER_ID, LineState::of( $item )->ref( LineState::REF_ORDER_ID ), 'nothing holds the first retry yet' );
		$this->recorder->json(
			200,
			array( 'replacedByOrderId' => self::SECOND_RETRY_ID ) + FulfilmentAnswers::order_read( self::RETRY_ID, self::PRINT_ID, self::EXTERNAL_REF, array( 'submitted', 'failed' ) )
		);
		// Read once following the first retry, and once more as the line's own order.
		$this->reads( self::SECOND_RETRY_ID, array( 'submitted', 'queued' ) );
		$this->reads( self::SECOND_RETRY_ID, array( 'submitted', 'queued' ) );

		$this->deliver( $this->submitted_event( self::RETRY_ID, self::ORDER_ID ) );
		$this->run_jobs();

		$line = LineState::of( $item );
		$this->assertSame( LineState::ORDERED, $line->state() );
		$this->assertSame( self::SECOND_RETRY_ID, $line->ref( LineState::REF_ORDER_ID ) );
		$this->assertSame( array( self::ORDER_ID, self::RETRY_ID ), $line->replaced_order_ids() );
		$this->assertSame( 'queued', $line->ref( LineState::REF_ORDER_STATUS ) );
	}

	public function test_a_retry_whose_event_was_lost_is_followed_on_the_next_read(): void {
		$item = $this->failed_line();
		$this->recorder->json(
			200,
			array( 'replacedByOrderId' => self::RETRY_ID ) + FulfilmentAnswers::order_read( self::ORDER_ID, self::PRINT_ID, self::EXTERNAL_REF, array( 'submitted', 'failed' ) )
		);
		$this->reads( self::RETRY_ID, array( 'submitted', 'queued' ) );
		$this->reads( self::RETRY_ID, array( 'submitted', 'queued' ) );

		list( $line, $order ) = Plugin::boot( dirname( __DIR__, 4 ) . '/pixonaut-for-woocommerce.php' )->line_actions()->current( LineState::of( $item ) );

		$this->assertSame( LineState::ORDERED, $line->state() );
		$this->assertSame( self::RETRY_ID, $line->ref( LineState::REF_ORDER_ID ) );
		$this->assertSame( array( self::ORDER_ID ), $line->replaced_order_ids() );
		$this->assertSame( 'queued', $line->ref( LineState::REF_ORDER_STATUS ) );
		$this->assertSame( self::RETRY_ID, $order['id'] ?? null );
		$this->assertStringContainsString( 'again, as order ' . self::RETRY_ID, $this->notes( $item ) );

		$this->deliver( $this->status_event( 'queued', 'printed', null, array( 'orderId' => self::RETRY_ID ) ) );
		$this->run_jobs();

		$this->assertSame( 'printed', LineState::of( $item )->ref( LineState::REF_ORDER_STATUS ), 'the replacement\'s events find the line' );
	}

	public function test_a_replacement_that_could_not_be_read_is_followed_when_the_event_is_tried_again(): void {
		$item          = $this->failed_line();
		$replaced_read = array( 'replacedByOrderId' => self::SECOND_RETRY_ID ) + FulfilmentAnswers::order_read( self::RETRY_ID, self::PRINT_ID, self::EXTERNAL_REF, array( 'submitted', 'failed' ) );
		$this->recorder->json( 200, $replaced_read )->fail();

		$this->deliver( $this->submitted_event( self::RETRY_ID, self::ORDER_ID ) );
		$this->run_jobs();

		$this->assertSame( self::RETRY_ID, LineState::of( $item )->ref( LineState::REF_ORDER_ID ), 'the line waits on the first retry' );
		$this->assertCount( 1, $this->pending_jobs(), 'the event is tried again' );

		$this->recorder->json( 200, $replaced_read );
		$this->reads( self::SECOND_RETRY_ID, array( 'submitted', 'queued' ) );
		$this->reads( self::SECOND_RETRY_ID, array( 'submitted', 'queued' ) );
		$this->run_jobs();

		$line = LineState::of( $item );
		$this->assertSame( LineState::ORDERED, $line->state() );
		$this->assertSame( self::SECOND_RETRY_ID, $line->ref( LineState::REF_ORDER_ID ) );
		$this->assertSame( 'queued', $line->ref( LineState::REF_ORDER_STATUS ) );
	}

	public function test_a_replacement_under_another_external_ref_is_not_followed(): void {
		$item = $this->failed_line();
		$this->recorder
			->json(
				200,
				array( 'replacedByOrderId' => self::RETRY_ID ) + FulfilmentAnswers::order_read( self::ORDER_ID, self::PRINT_ID, self::EXTERNAL_REF, array( 'submitted', 'failed' ) )
			)
			->json( 200, FulfilmentAnswers::order_read( self::RETRY_ID, self::PRINT_ID, 'another-store-line', array( 'submitted' ) ) );

		list( $line ) = Plugin::boot( dirname( __DIR__, 4 ) . '/pixonaut-for-woocommerce.php' )->line_actions()->current( LineState::of( $item ) );

		$this->assertSame( LineState::NEEDS_ATTENTION, $line->state() );
		$this->assertSame( self::ORDER_ID, $line->ref( LineState::REF_ORDER_ID ) );
	}

	public function test_an_event_of_the_old_order_found_before_the_handoff_leaves_the_line_alone(): void {
		$item  = $this->failed_line();
		$stale = LineState::of( $item );
		$this->reads( self::RETRY_ID, array( 'submitted' ) );
		$this->deliver( $this->submitted_event( self::RETRY_ID, self::ORDER_ID ) );
		$this->run_jobs();

		$plugin = Plugin::boot( dirname( __DIR__, 4 ) . '/pixonaut-for-woocommerce.php' );
		( new OrderFailed( new OrderEventLines( $plugin->connection(), new WcLogger() ) ) )->apply_to( $stale, 'late' );
		$plugin->order_status_changed()->apply_to( $item, Event::from_array( $this->status_event( 'printed', 'shipped', self::TRACKING ) ) );

		$line = LineState::of( $item );
		$this->assertSame( LineState::ORDERED, $line->state() );
		$this->assertSame( 'submitted', $line->ref( LineState::REF_ORDER_STATUS ) );
	}

	public function test_a_cancelled_line_keeps_its_order(): void {
		$item = $this->failed_line();
		LineState::of( $item )->transition( LineState::CANCELLED );

		$this->deliver( $this->submitted_event( self::RETRY_ID, self::ORDER_ID ) );
		$this->run_jobs();

		$this->assertSame( self::ORDER_ID, LineState::of( $item )->ref( LineState::REF_ORDER_ID ) );
	}

	/**
	 * Scripts Pixonaut's answer to the next read of an order.
	 *
	 * @param string   $order_id The order.
	 * @param string[] $statuses Its statuses so far, oldest first.
	 * @phpstan-param non-empty-list<string> $statuses
	 */
	private function reads( string $order_id, array $statuses ): void {
		$this->recorder->json( 200, FulfilmentAnswers::order_read( $order_id, self::PRINT_ID, self::EXTERNAL_REF, $statuses ) );
	}

	/**
	 * A line ordered at ORDER_ID, which waits for its prints.
	 */
	private function waiting_line(): WC_Order_Item_Product {
		$item = $this->pixonaut_line();
		$line = LineState::of( $item );
		$line->set_ref( LineState::REF_ORDER_STATUS, 'awaiting_prints' );
		$this->assertTrue( $line->transition( LineState::ORDERED ) );
		return $item;
	}

	/**
	 * A line whose printshop order ORDER_ID failed.
	 */
	private function failed_line(): WC_Order_Item_Product {
		$item = $this->pixonaut_line();
		$this->deliver( $this->status_event( '', 'submitted' ) );
		$this->deliver( $this->failed_event( 'the printshop adapter threw instead of answering' ) );
		$this->run_jobs();
		$this->assertSame( LineState::NEEDS_ATTENTION, LineState::of( $item )->state() );
		return $item;
	}
}
