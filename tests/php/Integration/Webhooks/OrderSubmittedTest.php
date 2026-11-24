<?php
/**
 * @package Pixonaut\WooCommerce
 */

declare(strict_types=1);

namespace Pixonaut\WooCommerce\Tests\Integration\Webhooks;

use Pixonaut\WooCommerce\Admin\Notices;
use Pixonaut\WooCommerce\Api\Hosts;
use Pixonaut\WooCommerce\Fulfilment\AttentionAlerts;
use Pixonaut\WooCommerce\Fulfilment\LineState;
use Pixonaut\WooCommerce\Tests\Support\FulfilmentAnswers;
use Pixonaut\WooCommerce\Tests\Support\HttpRecorder;
use WC_Order_Item_Product;

/**
 * `item.submitted`, sent when an item is handed to its printshop, once its
 * prints are done or again after a retry, brings the line of the item in
 * step with its order, read from Pixonaut answered from a script. A retry
 * keeps the order and the item, so the line keeps both.
 */
final class OrderSubmittedTest extends OrderEventsTestCase {

	/**
	 * A second item of ORDER_ID, another line's.
	 */
	private const OTHER_ITEM_ID = 'oi_second';

	private const OTHER_EXTERNAL_REF = 'wc_site_1042_8';

	/**
	 * Answers the plugin's own client, where the handler reads the order.
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

	public function test_a_retried_item_brings_its_line_out_of_needs_attention(): void {
		$item = $this->failed_line();
		$this->reads( array( 'submitted', 'failed', 'submitted' ) );

		$this->deliver( $this->submitted_event() );
		$this->run_jobs();

		$line = LineState::of( $item );
		$this->assertSame( LineState::ORDERED, $line->state() );
		$this->assertSame( self::ORDER_ID, $line->ref( LineState::REF_ORDER_ID ), 'a retry keeps the order' );
		$this->assertSame( self::ITEM_ID, $line->ref( LineState::REF_ORDER_ITEM_ID ) );
		$this->assertSame( array(), $line->replaced_order_ids() );
		$this->assertSame( 'submitted', $line->ref( LineState::REF_ORDER_STATUS ) );
		$this->assertStringEndsWith( '/v1/orders/' . self::ORDER_ID, $this->recorder->requests[0]['url'] );
		$this->assertArrayNotHasKey( AttentionAlerts::key( $item->get_order_id() ), (array) get_option( Notices::OPTION ), 'the notice goes once nothing needs attention' );

		$this->deliver( $this->status_event( 'submitted', 'queued' ) );
		$this->deliver( $this->status_event( 'queued', 'printed' ) );
		$this->run_jobs();

		$this->assertSame( 'printed', LineState::of( $item )->ref( LineState::REF_ORDER_STATUS ), 'the item\'s later events find the line' );
	}

	public function test_the_retried_item_failing_again_flags_the_line_again(): void {
		$item = $this->failed_line();
		$this->reads( array( 'submitted', 'failed', 'submitted' ) );
		$this->deliver( $this->submitted_event() );
		$this->run_jobs();

		$this->deliver( $this->failed_event( 'the printshop is closed' ) );
		$this->run_jobs();

		$line = LineState::of( $item );
		$this->assertSame( LineState::NEEDS_ATTENTION, $line->state() );
		$this->assertSame( 'failed', $line->ref( LineState::REF_ORDER_STATUS ) );
	}

	public function test_a_retry_reported_twice_is_noted_once(): void {
		$item  = $this->failed_line();
		$event = $this->submitted_event();
		$this->reads( array( 'submitted', 'failed', 'submitted' ) );
		$this->reads( array( 'submitted', 'failed', 'submitted' ) );

		$this->deliver( $event );
		$this->run_jobs();
		$this->deliver( array( 'id' => $event['id'] . '-again' ) + $event );
		$this->run_jobs();

		$this->assertSame( 1, substr_count( $this->notes( $item ), 'is now "submitted"' ) );
		$this->assertSame( LineState::ORDERED, LineState::of( $item )->state() );
	}

	public function test_a_status_of_the_retry_handled_before_its_submission_is_caught_up(): void {
		$item = $this->failed_line();
		$this->deliver( $this->status_event( 'submitted', 'queued' ) );
		$this->run_jobs();
		$this->assertSame( LineState::NEEDS_ATTENTION, LineState::of( $item )->state() );
		$this->reads( array( 'submitted', 'failed', 'submitted', 'queued' ) );

		$this->deliver( $this->submitted_event() );
		$this->run_jobs();

		$line = LineState::of( $item );
		$this->assertSame( LineState::ORDERED, $line->state() );
		$this->assertSame( 'queued', $line->ref( LineState::REF_ORDER_STATUS ) );
	}

	public function test_a_waiting_item_handed_to_its_printshop_moves_the_line_on(): void {
		$item = $this->waiting_line();
		$this->reads( array( 'awaiting_prints', 'submitted' ) );

		$this->deliver( $this->submitted_event() );
		$this->run_jobs();

		$line = LineState::of( $item );
		$this->assertSame( LineState::ORDERED, $line->state() );
		$this->assertSame( 'submitted', $line->ref( LineState::REF_ORDER_STATUS ) );

		$this->deliver( $this->status_event( 'submitted', 'queued' ) );
		$this->run_jobs();

		$this->assertSame( 'queued', LineState::of( $item )->ref( LineState::REF_ORDER_STATUS ) );
	}

	public function test_the_submission_of_another_lines_item_leaves_the_line_alone(): void {
		$item  = $this->waiting_line();
		$other = $this->pixonaut_line(
			array(
				'order'        => $this->order_of( $item ),
				'item_id'      => self::OTHER_ITEM_ID,
				'external_ref' => self::OTHER_EXTERNAL_REF,
			)
		);
		$this->recorder->json(
			200,
			FulfilmentAnswers::order_of_items(
				self::ORDER_ID,
				self::ORDER_EXTERNAL_REF,
				array(
					FulfilmentAnswers::item( self::ITEM_ID, array( self::PRINT_ID ), self::EXTERNAL_REF, array( 'status' => 'awaiting_prints' ) ),
					FulfilmentAnswers::item( self::OTHER_ITEM_ID, array( self::PRINT_ID ), self::OTHER_EXTERNAL_REF ),
				)
			)
		);

		$this->deliver(
			$this->submitted_event(
				array(
					'itemId'          => self::OTHER_ITEM_ID,
					'itemExternalRef' => self::OTHER_EXTERNAL_REF,
				)
			)
		);
		$this->run_jobs();

		$this->assertCount( 1, $this->recorder->requests, 'only the other line is read' );
		$this->assertSame( 'awaiting_prints', LineState::of( $item )->ref( LineState::REF_ORDER_STATUS ) );
		$this->assertSame( 'submitted', LineState::of( $other )->ref( LineState::REF_ORDER_STATUS ) );
		$this->assertSame( LineState::ORDERED, LineState::of( $other )->state() );
	}

	public function test_a_line_placed_before_orders_held_items_is_brought_in_step(): void {
		$item = $this->pixonaut_line( array( 'item_id' => null ) );
		$line = LineState::of( $item );
		$line->set_ref( LineState::REF_ORDER_STATUS, 'awaiting_prints' );
		$this->assertTrue( $line->transition( LineState::ORDERED ) );
		// Its order was placed for it alone, under its own ref.
		$this->recorder->json( 200, FulfilmentAnswers::order_read( self::ORDER_ID, self::PRINT_ID, self::EXTERNAL_REF, array( 'awaiting_prints', 'submitted' ) ) );

		$this->deliver( $this->submitted_event( array( 'externalRef' => self::EXTERNAL_REF ) ) );
		$this->run_jobs();

		$line = LineState::of( $item );
		$this->assertSame( LineState::ORDERED, $line->state() );
		$this->assertSame( 'submitted', $line->ref( LineState::REF_ORDER_STATUS ) );
	}

	public function test_an_unknown_outcome_of_the_hand_on_handled_first_is_applied_with_the_submission(): void {
		$item = $this->waiting_line();
		$this->deliver( $this->status_event( 'submitted', 'outcome_unknown' ) );
		$this->run_jobs();
		$this->assertSame( 'awaiting_prints', LineState::of( $item )->ref( LineState::REF_ORDER_STATUS ) );

		$this->reads( array( 'awaiting_prints', 'submitted', 'outcome_unknown' ) );
		$this->deliver( $this->submitted_event() );
		$this->run_jobs();

		$line = LineState::of( $item );
		$this->assertSame( LineState::NEEDS_ATTENTION, $line->state() );
		$this->assertSame( 'outcome_unknown', $line->ref( LineState::REF_ORDER_STATUS ) );
	}

	public function test_a_hand_on_whose_order_could_not_be_read_is_tried_again(): void {
		$item = $this->waiting_line();
		$this->recorder->fail();

		$this->deliver( $this->submitted_event() );
		$this->run_jobs();

		$this->assertSame( 'awaiting_prints', LineState::of( $item )->ref( LineState::REF_ORDER_STATUS ) );
		$this->assertCount( 1, $this->pending_jobs(), 'the event is tried again' );

		$this->reads( array( 'awaiting_prints', 'submitted' ) );
		$this->run_jobs();

		$this->assertSame( 'submitted', LineState::of( $item )->ref( LineState::REF_ORDER_STATUS ) );
	}

	public function test_the_submission_of_an_item_that_moved_on_leaves_the_line_where_it_is(): void {
		$item = $this->pixonaut_line();
		$this->deliver( $this->status_event( 'submitted', 'queued' ) );
		$this->run_jobs();
		$this->reads( array( 'submitted', 'queued' ) );

		$this->deliver( $this->submitted_event() );
		$this->run_jobs();

		$this->assertSame( 'queued', LineState::of( $item )->ref( LineState::REF_ORDER_STATUS ) );
	}

	public function test_a_submission_whose_external_ref_differs_is_refused(): void {
		$item   = $this->failed_line();
		$before = LineState::of( $item );

		$this->deliver( $this->submitted_event( array( 'externalRef' => 'wc_site_1042_99' ) ) );
		$this->run_jobs();

		$line = LineState::of( $item );
		$this->assertSame( LineState::NEEDS_ATTENTION, $line->state() );
		$this->assertSame( $before->changed_at(), $line->changed_at() );
		$this->assertSame( array(), $this->recorder->requests );
	}

	public function test_a_cancelled_line_is_left_as_it_is(): void {
		$item = $this->failed_line();
		LineState::of( $item )->transition( LineState::CANCELLED );

		$this->deliver( $this->submitted_event() );
		$this->run_jobs();

		$this->assertSame( LineState::CANCELLED, LineState::of( $item )->state() );
		$this->assertSame( array(), $this->recorder->requests, 'a final line is not read' );
	}

	/**
	 * Scripts Pixonaut's answer to the next read of ORDER_ID.
	 *
	 * @param string[] $statuses Its item's statuses so far, oldest first.
	 * @phpstan-param non-empty-list<string> $statuses
	 */
	private function reads( array $statuses ): void {
		$this->recorder->json( 200, self::order_read( $statuses ) );
	}

	/**
	 * A line ordered at ORDER_ID, whose item waits for its prints.
	 */
	private function waiting_line(): WC_Order_Item_Product {
		$item = $this->pixonaut_line();
		$line = LineState::of( $item );
		$line->set_ref( LineState::REF_ORDER_STATUS, 'awaiting_prints' );
		$this->assertTrue( $line->transition( LineState::ORDERED ) );
		return $item;
	}

	/**
	 * A line whose item of ORDER_ID failed at its printshop.
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
