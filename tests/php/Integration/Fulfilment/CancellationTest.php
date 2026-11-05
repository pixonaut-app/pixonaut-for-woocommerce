<?php
/**
 * @package Pixonaut\WooCommerce
 */

declare(strict_types=1);

namespace Pixonaut\WooCommerce\Tests\Integration\Fulfilment;

use Pixonaut\WooCommerce\Api\Client;
use Pixonaut\WooCommerce\Api\Deprecations;
use Pixonaut\WooCommerce\Api\Designs;
use Pixonaut\WooCommerce\Api\Operations;
use Pixonaut\WooCommerce\Api\Orders;
use Pixonaut\WooCommerce\Api\WcLogger;
use Pixonaut\WooCommerce\Api\WpHttpTransport;
use Pixonaut\WooCommerce\ApprovalMode;
use Pixonaut\WooCommerce\Connection\StoredConnection;
use Pixonaut\WooCommerce\Fulfilment\FulfilmentJobs;
use Pixonaut\WooCommerce\Fulfilment\LineCancellation;
use Pixonaut\WooCommerce\Fulfilment\LineState;
use Pixonaut\WooCommerce\Fulfilment\NeedsAttention;
use Pixonaut\WooCommerce\Refs;
use Pixonaut\WooCommerce\Tests\Support\ActionSchedulerRunner;
use Pixonaut\WooCommerce\Tests\Support\FulfilmentAnswers;
use WC_Order;

/**
 * A cancelled or refunded order cancels its line by how far it got: before
 * its printshop order the job stops and a waiting design is rejected; after,
 * Pixonaut asks the printshop, whose answer decides.
 */
final class CancellationTest extends FulfilmentTestCase {

	private const ORDER_ID = 'o_1';

	/**
	 * The product's price, so a refund can name an amount.
	 */
	private const PRICE = 20;

	public function set_up(): void {
		parent::set_up();
		$client = new Client( self::BASE_URL, Operations::plugin(), $this->store, new WpHttpTransport(), new WcLogger(), new Deprecations() );
		$store  = $this->store;
		$cancel = new LineCancellation( new Designs( $client ), new Orders( $client ), static fn (): ?StoredConnection => $store->load(), new WcLogger() );
		// The plugin's own triggers stay; its job runs against the recorder.
		remove_all_actions( LineCancellation::ACTION );
		add_action( LineCancellation::ACTION, array( $cancel, 'run' ) );
		$this->order->calculate_totals();
		$this->order->set_status( 'processing' );
		$this->order->save();
		// Processing scheduled the fulfilment job; each test schedules its own.
		as_unschedule_all_actions( FulfilmentJobs::ACTION );
	}

	public function test_a_line_not_yet_ordered_stops_its_job_rejects_its_design_and_is_cancelled(): void {
		$this->assertTrue( FulfilmentJobs::schedule( $this->item->get_id() ) );
		$this->recorder->json( 200, array( 'ok' => true ) );

		$this->order->update_status( 'cancelled' );
		$this->assertFalse( FulfilmentJobs::is_scheduled( $this->item->get_id() ) );
		ActionSchedulerRunner::run_pending( LineCancellation::ACTION );

		$this->assertSame( 1, $this->count_sent( 'POST', '/v1/designs/' . self::DESIGN . '/reject' ) );
		$line = $this->line();
		$this->assertSame( LineState::CANCELLED, $line->state() );
		$this->assertNull( $line->ref( LineState::REF_CANCEL ) );
		$this->assertStringContainsString( 'cancelled before it was sent to the printshop', $this->notes() );
	}

	public function test_a_design_approved_already_is_not_rejected(): void {
		$line = $this->line();
		$this->assertTrue( $line->transition( LineState::APPROVED ) );

		$this->order->update_status( 'cancelled' );
		ActionSchedulerRunner::run_pending( LineCancellation::ACTION );

		$this->assertSame( array(), $this->recorder->requests );
		$this->assertSame( LineState::CANCELLED, $this->line()->state() );
	}

	public function test_a_design_approved_in_the_meantime_still_cancels_the_line(): void {
		$this->recorder->json( 409, array( 'error' => 'not_committed' ) );

		$this->order->update_status( 'cancelled' );
		ActionSchedulerRunner::run_pending( LineCancellation::ACTION );

		$this->assertSame( LineState::CANCELLED, $this->line()->state() );
		$this->assertStringNotContainsString( 'could not be rejected', $this->notes() );
	}

	public function test_a_design_that_cannot_be_rejected_still_cancels_the_line_with_a_note(): void {
		$this->recorder->json( 403, array( 'error' => 'forbidden' ) );

		$this->order->update_status( 'cancelled' );
		ActionSchedulerRunner::run_pending( LineCancellation::ACTION );

		$this->assertSame( LineState::CANCELLED, $this->line()->state() );
		$this->assertStringContainsString( 'could not be rejected in Pixonaut, which answered "forbidden"', $this->notes() );
	}

	public function test_a_refusal_for_one_side_still_rejects_the_other(): void {
		$this->order->remove_item( $this->item->get_id() );
		$this->order->save();
		$this->order = $this->paid_order( array( 'front', 'back' ) );
		$item        = current( $this->order->get_items() );
		$this->assertInstanceOf( \WC_Order_Item_Product::class, $item );
		$this->item = $item;
		$this->order->set_status( 'processing' );
		$this->order->save();
		$this->recorder
			->json( 404, array( 'error' => 'not_found' ) )
			->json( 200, array( 'ok' => true ) );

		$this->order->update_status( 'cancelled' );
		ActionSchedulerRunner::run_pending( LineCancellation::ACTION );

		$this->assertSame( 1, $this->count_sent( 'POST', '/v1/designs/' . self::DESIGN_PREFIX . 'back/reject' ) );
		$this->assertSame( LineState::CANCELLED, $this->line()->state() );
		$this->assertStringContainsString( 'answered "not_found"', $this->notes() );
	}

	public function test_a_cancellation_the_store_could_not_schedule_is_scheduled_when_the_order_is_saved(): void {
		$refuse = static fn ( $pre, string $hook ) => LineCancellation::ACTION === $hook ? 0 : $pre;
		add_filter( 'pre_as_enqueue_async_action', $refuse, 10, 2 );
		$this->order->update_status( 'cancelled' );
		remove_filter( 'pre_as_enqueue_async_action', $refuse, 10 );
		$this->assertSame( array(), ActionSchedulerRunner::pending( LineCancellation::ACTION ) );
		$this->assertStringContainsString( 'is not cancelled yet', $this->notes() );
		$this->recorder->json( 200, array( 'ok' => true ) );

		do_action( 'woocommerce_process_shop_order_meta', $this->order->get_id(), get_post( $this->order->get_id() ) );
		ActionSchedulerRunner::run_pending( LineCancellation::ACTION );

		$this->assertSame( LineState::CANCELLED, $this->line()->state() );
	}

	public function test_an_order_whose_answer_was_lost_is_found_and_cancelled(): void {
		$this->printed_with_order_request();
		$this->recorder
			->json( 200, FulfilmentAnswers::orders( array( $this->answer( 'submitted' ) ) ) )
			->json( 200, $this->answer( 'cancelled' ) );

		$this->order->update_status( 'cancelled' );
		ActionSchedulerRunner::run_pending( LineCancellation::ACTION );

		$this->assertSame( 1, $this->count_sent( 'POST', '/v1/orders/' . self::ORDER_ID . '/cancel' ) );
		$line = $this->line();
		$this->assertSame( LineState::CANCELLED, $line->state() );
		$this->assertSame( self::ORDER_ID, $line->ref( LineState::REF_ORDER_ID ) );
		$this->assertNull( $line->ref( LineState::REF_ORDER_REQUEST ) );
	}

	public function test_an_order_request_that_placed_nothing_is_looked_up_once_more_then_cancelled(): void {
		$this->printed_with_order_request();
		$this->recorder
			->json( 200, FulfilmentAnswers::orders( array() ) )
			->json( 200, FulfilmentAnswers::orders( array() ) );

		$this->order->update_status( 'cancelled' );
		ActionSchedulerRunner::run_once( LineCancellation::ACTION );

		$this->assertSame( LineState::PRINTED, $this->line()->state() );

		ActionSchedulerRunner::run_once( LineCancellation::ACTION );

		$this->assertSame( 2, count( $this->recorder->requests ) );
		$line = $this->line();
		$this->assertSame( LineState::CANCELLED, $line->state() );
		$this->assertNull( $line->ref( LineState::REF_ORDER_REQUEST ) );
	}

	public function test_a_line_being_cancelled_is_not_fulfilled(): void {
		$this->recorder->json( 200, array( 'ok' => true ) );
		$this->order->update_status( 'cancelled' );

		do_action( FulfilmentJobs::ACTION, $this->item->get_id() );

		$this->assertSame( 0, $this->count_sent( 'POST', '/v1/designs/' . self::DESIGN . '/approve' ) );
		$this->assertSame( LineState::COMMITTED, $this->line()->state() );
	}

	public function test_an_ordered_line_asks_the_printshop_and_is_cancelled(): void {
		$this->ordered();
		$this->recorder->json( 200, $this->answer( 'cancelled' ) );

		$this->order->update_status( 'cancelled' );
		ActionSchedulerRunner::run_pending( LineCancellation::ACTION );

		$this->assertSame( 1, $this->count_sent( 'POST', '/v1/orders/' . self::ORDER_ID . '/cancel' ) );
		$line = $this->line();
		$this->assertSame( LineState::CANCELLED, $line->state() );
		$this->assertSame( 'cancelled', $line->ref( LineState::REF_ORDER_STATUS ) );
		$this->assertStringContainsString( 'the printshop order for "Personalised tee" was cancelled', $this->notes() );
	}

	public function test_an_order_at_the_printshop_leaves_the_line_with_a_note_saying_why(): void {
		$this->ordered();
		$this->recorder->json(
			409,
			array(
				'error' => 'order_at_printshop',
				'cause' => 'begun',
			)
		);

		$this->order->update_status( 'cancelled' );
		ActionSchedulerRunner::run_pending( LineCancellation::ACTION );

		$line = $this->line();
		$this->assertSame( LineState::ORDERED, $line->state() );
		$this->assertNull( $line->ref( LineState::REF_CANCEL ) );
		$this->assertStringContainsString( 'the printshop has begun it and did not cancel it. Ask the printshop.', $this->notes() );
	}

	public function test_an_order_still_in_flight_is_asked_again_later(): void {
		$this->ordered();
		$this->recorder
			->json(
				409,
				array(
					'error'  => 'order_in_flight',
					'detail' => 'The submission has not been answered yet.',
				)
			)
			->json( 200, $this->answer( 'cancelled' ) );

		$this->order->update_status( 'cancelled' );
		ActionSchedulerRunner::run_once( LineCancellation::ACTION );

		$this->assertSame( LineState::ORDERED, $this->line()->state() );
		$this->assertSame( 1, $this->line()->ref( LineState::REF_CANCEL )['failures'] );
		$this->assertCount( 1, ActionSchedulerRunner::due_at( LineCancellation::ACTION ) );

		ActionSchedulerRunner::run_once( LineCancellation::ACTION );

		$this->assertSame( 2, $this->count_sent( 'POST', '/v1/orders/' . self::ORDER_ID . '/cancel' ) );
		$this->assertSame( LineState::CANCELLED, $this->line()->state() );
	}

	public function test_a_printshop_that_could_not_be_reached_is_asked_again_while_pixonaut_says_it_may_work(): void {
		$this->ordered();
		$this->recorder->json(
			503,
			array(
				'error'     => 'cancel_unavailable',
				'reason'    => 'The printshop did not answer.',
				'retryable' => true,
			)
		);

		$this->order->update_status( 'cancelled' );
		ActionSchedulerRunner::run_once( LineCancellation::ACTION );

		$this->assertSame( LineState::ORDERED, $this->line()->state() );
		$this->assertCount( 1, ActionSchedulerRunner::due_at( LineCancellation::ACTION ) );
	}

	public function test_a_printshop_that_cannot_be_asked_again_leaves_the_line_with_a_note(): void {
		$this->ordered();
		$this->recorder->json(
			503,
			array(
				'error'     => 'cancel_unavailable',
				'reason'    => 'The printshop refused the request.',
				'retryable' => false,
			)
		);

		$this->order->update_status( 'cancelled' );
		ActionSchedulerRunner::run_pending( LineCancellation::ACTION );

		$this->assertSame( LineState::ORDERED, $this->line()->state() );
		$this->assertStringContainsString( 'Pixonaut answered "cancel_unavailable". The printshop refused the request. Ask the printshop to stop it.', $this->notes() );
	}

	public function test_retries_that_run_out_leave_the_line_with_a_note(): void {
		add_filter( 'pixonaut_wc_fulfilment_retry_schedule', static fn (): array => array() );
		$this->ordered();
		$this->recorder->json(
			409,
			array(
				'error'  => 'order_in_flight',
				'detail' => 'The submission has not been answered yet.',
			)
		);

		$this->order->update_status( 'cancelled' );
		ActionSchedulerRunner::run_pending( LineCancellation::ACTION );

		$this->assertSame( LineState::ORDERED, $this->line()->state() );
		$this->assertStringContainsString( 'could not be cancelled: Pixonaut answered "order_in_flight"', $this->notes() );
	}

	public function test_a_failed_order_cancels_the_line_in_the_store_only(): void {
		$line = $this->ordered( 'failed' );
		$this->assertTrue( NeedsAttention::flag( $line, $this->order, NeedsAttention::ORDER_FAILED, 'Flagged.' ) );
		$this->recorder->json(
			409,
			array(
				'error' => 'order_status_not_allowed',
				'from'  => 'failed',
				'to'    => 'cancelled',
			)
		);

		$this->order->update_status( 'cancelled' );
		ActionSchedulerRunner::run_pending( LineCancellation::ACTION );

		$this->assertSame( LineState::CANCELLED, $this->line()->state() );
		$this->assertStringContainsString( 'Its printshop order had failed', $this->notes() );
	}

	public function test_refunding_part_of_a_line_changes_nothing_in_pixonaut_and_adds_a_note(): void {
		$this->ordered();

		$this->refund( 1 );

		$this->assertSame( 0, ActionSchedulerRunner::run_pending( LineCancellation::ACTION ) );
		$this->assertSame( array(), $this->recorder->requests );
		$this->assertSame( LineState::ORDERED, $this->line()->state() );
		$this->assertStringContainsString( '1 of 2 of "Personalised tee" refunded. Nothing was changed in Pixonaut', $this->notes() );
	}

	public function test_refunding_the_rest_of_a_line_cancels_it(): void {
		$this->ordered();
		$this->recorder->json( 200, $this->answer( 'cancelled' ) );
		$order = new WC_Order( $this->order->get_id() );
		// Another item keeps the order from being refunded in full.
		$order->add_product( $this->product(), 1 );
		$order->calculate_totals();

		$this->refund( 1 );
		$this->refund( 1 );
		ActionSchedulerRunner::run_pending( LineCancellation::ACTION );

		$this->assertSame( 1, $this->count_sent( 'POST', '/v1/orders/' . self::ORDER_ID . '/cancel' ) );
		$this->assertSame( LineState::CANCELLED, $this->line()->state() );
		$this->assertNotSame( 'refunded', $this->order_status() );
	}

	public function test_refunding_the_whole_order_cancels_its_line_once(): void {
		$this->ordered();
		$this->recorder->json( 200, $this->answer( 'cancelled' ) );

		$this->refund( self::QUANTITY );
		$this->assertSame( 'refunded', $this->order_status() );
		ActionSchedulerRunner::run_pending( LineCancellation::ACTION );

		$this->assertSame( 1, $this->count_sent( 'POST', '/v1/orders/' . self::ORDER_ID . '/cancel' ) );
		$this->assertSame( LineState::CANCELLED, $this->line()->state() );
	}

	public function test_a_filter_can_keep_a_refunded_line_going(): void {
		$this->ordered();
		$triggers = array();
		add_filter(
			'pixonaut_wc_fulfilment_cancel_line',
			static function ( bool $cancel, $item, string $trigger ) use ( &$triggers ): bool {
				$triggers[] = $trigger;
				return LineCancellation::ORDER_CANCELLED === $trigger;
			},
			10,
			3
		);

		$this->refund( self::QUANTITY );

		$this->assertSame( array( LineCancellation::ORDER_REFUNDED, LineCancellation::LINE_REFUNDED ), $triggers );
		$this->assertSame( 0, ActionSchedulerRunner::run_pending( LineCancellation::ACTION ) );
		$this->assertSame( LineState::ORDERED, $this->line()->state() );
	}

	public function test_a_shipped_line_is_left_alone(): void {
		$line = $this->ordered();
		$this->assertTrue( $line->transition( LineState::SHIPPED ) );

		$this->order->update_status( 'cancelled' );

		$this->assertSame( 0, ActionSchedulerRunner::run_pending( LineCancellation::ACTION ) );
		$this->assertSame( LineState::SHIPPED, $this->line()->state() );
	}

	public function test_a_proof_by_email_line_waiting_for_the_customer_rejects_its_design(): void {
		$line = $this->line();
		$line->set_ref( LineState::REF_APPROVAL_MODE, ApprovalMode::PROOF_EMAIL );
		$this->assertTrue( $line->transition( LineState::AWAITING_CUSTOMER ) );
		$this->recorder->json( 200, array( 'ok' => true ) );

		$this->order->update_status( 'cancelled' );
		ActionSchedulerRunner::run_pending( LineCancellation::ACTION );

		$this->assertSame( 1, $this->count_sent( 'POST', '/v1/designs/' . self::DESIGN . '/reject' ) );
		$this->assertSame( LineState::CANCELLED, $this->line()->state() );
	}

	/**
	 * Refunds $quantity of the line at its price.
	 *
	 * @param int $quantity How many.
	 */
	private function refund( int $quantity ): void {
		$refund = wc_create_refund(
			array(
				'order_id'   => $this->order->get_id(),
				'amount'     => $quantity * self::PRICE,
				'line_items' => array(
					$this->item->get_id() => array(
						'qty'          => $quantity,
						'refund_total' => $quantity * self::PRICE,
					),
				),
			)
		);
		$this->assertNotWPError( $refund );
	}

	/**
	 * A printed line that sent its order request without learning the order's id.
	 */
	private function printed_with_order_request(): void {
		$line = $this->line();
		$line->set_ref( LineState::REF_ORDER_REQUEST, array( 'stored' => true ) );
		$this->assertTrue( $line->transition( LineState::PRINTED ) );
	}

	/**
	 * The line holding its printshop order.
	 *
	 * @param string $status Pixonaut's order status.
	 */
	private function ordered( string $status = 'submitted' ): LineState {
		$line = $this->line();
		$line->set_ref( LineState::REF_ORDER_ID, self::ORDER_ID );
		$line->set_ref( LineState::REF_ORDER_STATUS, $status );
		$this->assertTrue( $line->transition( LineState::ORDERED ) );
		return $this->line();
	}

	/**
	 * Pixonaut's answer to the cancel: the order as it is now.
	 *
	 * @param string $status The order's status.
	 *
	 * @return array<string, mixed>
	 */
	private function answer( string $status ): array {
		return FulfilmentAnswers::order( self::ORDER_ID, array( 'p_front' ), Refs::order_item_ref( self::SITE, $this->order->get_id(), $this->item->get_id() ), array( 'status' => $status ) );
	}

	/**
	 * The order's status as stored now.
	 */
	private function order_status(): string {
		$order = wc_get_order( $this->order->get_id() );
		$this->assertInstanceOf( WC_Order::class, $order );
		return $order->get_status();
	}

	/**
	 * The order's notes as one string.
	 */
	private function notes(): string {
		$notes = wc_get_order_notes( array( 'order_id' => $this->order->get_id() ) );
		return implode( "\n", array_map( static fn ( $note ): string => (string) $note->content, $notes ) );
	}
}
