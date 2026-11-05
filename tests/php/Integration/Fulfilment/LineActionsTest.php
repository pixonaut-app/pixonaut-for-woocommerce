<?php
/**
 * @package Pixonaut\WooCommerce
 */

declare(strict_types=1);

namespace Pixonaut\WooCommerce\Tests\Integration\Fulfilment;

use Pixonaut\WooCommerce\Admin\ConnectionScreen;
use Pixonaut\WooCommerce\Admin\Notices;
use Pixonaut\WooCommerce\Admin\OrderBox;
use Pixonaut\WooCommerce\Api\Client;
use Pixonaut\WooCommerce\Api\Connect;
use Pixonaut\WooCommerce\Api\Deprecations;
use Pixonaut\WooCommerce\Api\ErrorCodes;
use Pixonaut\WooCommerce\Cart\StandingSide;
use Pixonaut\WooCommerce\Api\Operations;
use Pixonaut\WooCommerce\Api\Orders;
use Pixonaut\WooCommerce\Api\WcLogger;
use Pixonaut\WooCommerce\Api\WpHttpTransport;
use Pixonaut\WooCommerce\Connection\ConnectAttempts;
use Pixonaut\WooCommerce\Connection\Connection;
use Pixonaut\WooCommerce\Connection\StoredConnection;
use Pixonaut\WooCommerce\Fulfilment\AdoptedOrder;
use Pixonaut\WooCommerce\Fulfilment\AttentionAlerts;
use Pixonaut\WooCommerce\Fulfilment\DeliveryChange;
use Pixonaut\WooCommerce\Fulfilment\FulfilmentJobs;
use Pixonaut\WooCommerce\Fulfilment\LineActions;
use Pixonaut\WooCommerce\Fulfilment\LineState;
use Pixonaut\WooCommerce\Fulfilment\NeedsAttention;
use Pixonaut\WooCommerce\Fulfilment\OrderAddress;
use Pixonaut\WooCommerce\Fulfilment\OrderEventLines;
use Pixonaut\WooCommerce\Fulfilment\OrderFailed;
use Pixonaut\WooCommerce\Fulfilment\OrderHold;
use Pixonaut\WooCommerce\Fulfilment\OrderStatus;
use Pixonaut\WooCommerce\Fulfilment\OrderStatusChanged;
use Pixonaut\WooCommerce\Fulfilment\Shipments;
use Pixonaut\WooCommerce\Proofs\ProofUrls;
use Pixonaut\WooCommerce\Templates;
use Pixonaut\WooCommerce\Tests\Support\ConnectionAnswers;
use Pixonaut\WooCommerce\Tests\Support\FulfilmentAnswers;
use Pixonaut\WooCommerce\Tests\Support\RecordingLogger;
use WC_Order;
use WC_Order_Item_Product;

/**
 * Retry, settling an unknown outcome, cancel and sandbox rehearsals, each
 * offered only when Pixonaut's order allows it, and the order screen's box
 * that offers them.
 */
final class LineActionsTest extends FulfilmentTestCase {

	private const ORDER_ID = 'o_1';

	private const NEWER_ORDER_ID = 'o_2';

	private const NEWEST_ORDER_ID = 'o_3';

	private const PERIOD_END = '2026-11-01T00:00:00.000Z';

	/**
	 * An order's `hold`: a free workspace past its monthly orders.
	 */
	private const HOLD = array(
		'reason'    => 'monthly_order_limit',
		'limit'     => 25,
		'periodEnd' => self::PERIOD_END,
	);

	private LineActions $actions;

	/**
	 * Lines the store scheduled.
	 *
	 * @var list<int>
	 */
	private array $scheduled = array();

	/**
	 * What scheduling a line answers: false as when Action Scheduler refuses the job.
	 *
	 * @var bool
	 */
	private bool $schedules = true;

	private RecordingLogger $logger;

	public function set_up(): void {
		parent::set_up();
		$this->logger  = new RecordingLogger();
		$this->actions = $this->line_actions();
	}

	public function tear_down(): void {
		unset( $_POST[ OrderBox::ACTION_FIELD ], $_POST[ OrderBox::NONCE_FIELD ], $_POST[ OrderBox::INPUT_FIELD ] );
		parent::tear_down();
	}

	public function test_a_failed_order_offers_retry_only_while_pixonaut_allows_it(): void {
		$line = $this->flagged( 'failed', NeedsAttention::ORDER_FAILED );

		$this->assertSame( array( LineActions::RETRY ), $this->actions->available( $line, $this->read( 'failed', array( 'canRetry' => true ) ) ) );
		$this->assertSame( array(), $this->actions->available( $line, $this->read( 'failed' ) ) );
	}

	public function test_retry_places_the_order_again_and_the_line_goes_on(): void {
		$line = $this->flagged( 'failed', NeedsAttention::ORDER_FAILED );
		$this->recorder
			->json( 200, $this->read( 'failed', array( 'canRetry' => true ) ) )
			->json( 201, $this->placed( self::NEWER_ORDER_ID, array( 'replacesOrderId' => self::ORDER_ID ) ) );

		$result = $this->actions->run( $line, LineActions::RETRY );

		$this->assertSame( LineActions::DONE, $result->code );
		$this->assertSame( 1, $this->count_sent( 'POST', '/v1/orders/' . self::ORDER_ID . '/retry' ) );
		$retry = array_slice( $this->recorder->requests, -1 )[0] ?? null;
		$this->assertSame( '{}', $retry['body'] ?? null, 'A retry goes back to the printshop the order went to: it names none.' );
		$line = $this->line();
		$this->assertSame( LineState::ORDERED, $line->state() );
		$this->assertSame( self::NEWER_ORDER_ID, $line->ref( LineState::REF_ORDER_ID ) );
		$this->assertSame( 'submitted', $line->ref( LineState::REF_ORDER_STATUS ) );
		$this->assertSame( array( $line->item()->get_id() ), array_map( static fn ( LineState $found ): int => $found->item()->get_id(), LineState::for_order_id( self::NEWER_ORDER_ID ) ) );
		$this->assertStringContainsString( self::NEWER_ORDER_ID, $this->notes() );
		$this->assertFalse( $this->has_notice() );
	}

	public function test_a_failed_print_is_offered_retry_only_while_pixonaut_allows_it(): void {
		$line   = $this->flagged( 'failed', NeedsAttention::ORDER_FAILED );
		$failed = array( 'failureKind' => ErrorCodes::PRINT_FAILED );

		$this->assertSame( array( LineActions::RETRY ), $this->actions->available( $line, $this->read( 'failed', $failed + array( 'canRetry' => true ) ) ) );
		$this->assertSame( array(), $this->actions->available( $line, $this->read( 'failed', $failed + array( 'canRetry' => false ) ) ) );
	}

	public function test_a_retry_of_a_failed_print_takes_on_the_order_waiting_for_it(): void {
		$this->sign_in( 'shop_manager' );
		$line = $this->flagged( 'failed', NeedsAttention::ORDER_FAILED );
		$this->recorder
			->json(
				200,
				$this->read(
					'failed',
					array(
						'canRetry'    => true,
						'failureKind' => ErrorCodes::PRINT_FAILED,
					)
				)
			)
			->json(
				201,
				$this->placed(
					self::NEWER_ORDER_ID,
					array(
						'status'          => OrderStatus::AWAITING_PRINTS,
						'replacesOrderId' => self::ORDER_ID,
					)
				)
			);

		$this->assertSame( LineActions::DONE, $this->actions->run( $line, LineActions::RETRY )->code );

		$line = $this->line();
		$this->assertSame( LineState::ORDERED, $line->state() );
		$this->assertSame( self::NEWER_ORDER_ID, $line->ref( LineState::REF_ORDER_ID ) );
		$this->assertSame( OrderStatus::AWAITING_PRINTS, $line->ref( LineState::REF_ORDER_STATUS ) );
		$this->assertStringContainsString( 'Waiting for prints', $this->render_box() );
	}

	public function test_a_retry_refused_while_the_print_is_made_again_says_so(): void {
		$this->sign_in( 'shop_manager' );
		$this->flagged( 'failed', NeedsAttention::ORDER_FAILED );
		$this->recorder
			->json( 200, $this->read( 'failed', array( 'canRetry' => true ) ) )
			->json(
				409,
				array(
					'error'  => ErrorCodes::PRINT_NOT_COMPLETED,
					'status' => 'created',
				)
			)
			->json( 200, $this->read( 'failed', array( 'canRetry' => false ) ) );
		$this->press( LineActions::RETRY );

		$this->box()->handle( $this->order->get_id() );

		$words = 'The print is still being made. Try again once it is finished.';
		$this->assertStringContainsString( $words, $this->render_box() );
		$this->assertStringContainsString( $words, $this->notes() );
		$line = $this->line();
		$this->assertSame( LineState::NEEDS_ATTENTION, $line->state() );
		$this->assertSame( self::ORDER_ID, $line->ref( LineState::REF_ORDER_ID ) );
	}

	public function test_a_retry_refused_with_a_code_this_version_does_not_know_names_it(): void {
		$line = $this->flagged( 'failed', NeedsAttention::ORDER_FAILED );
		$this->recorder
			->json( 200, $this->read( 'failed', array( 'canRetry' => true ) ) )
			->json( 409, array( 'error' => 'teleporter_jammed' ) );

		$result = $this->actions->run( $line, LineActions::RETRY );

		$this->assertSame( LineActions::REFUSED, $result->code );
		$this->assertSame( 'teleporter_jammed', $result->detail );
		$this->assertStringContainsString( '"teleporter_jammed"', $result->message );
		$this->assertStringContainsString( '"teleporter_jammed"', $this->notes() );
	}

	public function test_a_retry_refused_as_no_longer_failed_says_why(): void {
		$line = $this->flagged( 'failed', NeedsAttention::ORDER_FAILED );
		$this->recorder
			->json( 200, $this->read( 'failed', array( 'canRetry' => true ) ) )
			->json(
				409,
				array(
					'error'  => ErrorCodes::ORDER_NOT_RETRYABLE,
					'status' => OrderStatus::SUBMITTED,
				)
			);

		$result = $this->actions->run( $line, LineActions::RETRY );

		$this->assertSame( LineActions::STATUS_NOT_ALLOWED, $result->code );
		$this->assertSame( 'The printshop order is no longer failed in Pixonaut, so it was not placed again.', $result->message );
	}

	public function test_a_retry_placed_already_is_adopted(): void {
		$line = $this->flagged( 'failed', NeedsAttention::ORDER_FAILED );
		$this->recorder
			->json( 200, $this->read( 'failed', array( 'canRetry' => true ) ) )
			->json(
				409,
				array(
					'error'        => 'order_superseded',
					'newerOrderId' => self::NEWER_ORDER_ID,
				)
			)
			->json( 200, FulfilmentAnswers::read_order( self::NEWER_ORDER_ID, $this->external_ref(), array( 'status' => 'queued' ) ) );

		$this->assertSame( LineActions::DONE, $this->actions->run( $line, LineActions::RETRY )->code );

		$line = $this->line();
		$this->assertSame( LineState::ORDERED, $line->state() );
		$this->assertSame( self::NEWER_ORDER_ID, $line->ref( LineState::REF_ORDER_ID ) );
		$this->assertSame( 1, $this->count_sent( 'GET', '/v1/orders/' . self::NEWER_ORDER_ID ) );
	}

	public function test_a_retry_refused_for_an_automations_order_takes_that_order_on(): void {
		$line = $this->flagged( 'failed', NeedsAttention::ORDER_FAILED );
		$this->recorder
			->json( 200, $this->read( 'failed', array( 'canRetry' => true ) ) )
			->json( 409, $this->already_ordered() )
			->json( 200, $this->newer_order( 'automation', self::QUANTITY ) );

		$this->assertSame( LineActions::DONE, $this->actions->run( $line, LineActions::RETRY )->code );

		$line = $this->line();
		$this->assertSame( LineState::ORDERED, $line->state() );
		$this->assertSame( self::NEWER_ORDER_ID, $line->ref( LineState::REF_ORDER_ID ) );
		$this->assertSame( 'Order approved designs', $line->ref( LineState::REF_ORDER_AUTOMATION ) );
	}

	public function test_a_retry_that_takes_on_an_automations_order_to_another_address_sends_the_customers(): void {
		$line = $this->flagged( 'failed', NeedsAttention::ORDER_FAILED );
		$line->set_ref( LineState::REF_DELIVERY, array( DeliveryChange::FIELD_ADDRESS => OrderAddress::fingerprint( OrderAddress::of( $this->order, $this->item ) ) ) );
		$line->save();
		$automation           = $this->newer_order( 'automation', self::QUANTITY );
		$automation['shipTo'] = array(
			'savedAddressId' => 'addr_warehouse',
			'name'           => 'Warehouse',
			'address'        => array(
				'line1'   => 'Lagervägen 1',
				'city'    => 'Malmö',
				'country' => 'SE',
			),
		);
		$this->recorder
			->json( 200, $this->read( 'failed', array( 'canRetry' => true ) ) )
			->json( 409, $this->already_ordered() )
			->json( 200, $automation );

		$this->assertSame( LineActions::DONE, $this->actions->run( $this->line(), LineActions::RETRY )->code );

		$this->assertSame( AdoptedOrder::address( $automation ), DeliveryChange::of( $this->line() )[ DeliveryChange::FIELD_ADDRESS ] );
		$this->assertTrue( as_has_scheduled_action( DeliveryChange::ACTION, array( 'item_id' => $this->item->get_id() ) ) );
	}

	/**
	 * @return iterable<string, array{string, int}>
	 */
	public static function orders_a_retry_does_not_take_on(): iterable {
		yield 'placed by a member' => array( 'member', self::QUANTITY );
		yield 'of fewer copies' => array( 'automation', 1 );
	}

	/**
	 * @dataProvider orders_a_retry_does_not_take_on
	 *
	 * @param string $via      Who placed the order.
	 * @param int    $quantity Copies it holds.
	 */
	public function test_a_retry_refused_for_another_order_is_refused( string $via, int $quantity ): void {
		$line = $this->flagged( 'failed', NeedsAttention::ORDER_FAILED );
		$this->recorder
			->json( 200, $this->read( 'failed', array( 'canRetry' => true ) ) )
			->json( 409, $this->already_ordered() )
			->json( 200, $this->newer_order( $via, $quantity ) );

		$result = $this->actions->run( $line, LineActions::RETRY );

		$this->assertSame( LineActions::REFUSED, $result->code );
		$this->assertSame( 'design_already_ordered', $result->detail );
		$this->assertStringContainsString( 'Check that order in Pixonaut', $result->message );
		$this->assertStringNotContainsString( 'Order again anyway', $result->message, 'A line with an order is not offered Order again anyway.' );
		$line = $this->line();
		$this->assertSame( LineState::NEEDS_ATTENTION, $line->state() );
		$this->assertSame( self::ORDER_ID, $line->ref( LineState::REF_ORDER_ID ) );
	}

	public function test_a_retry_that_fails_again_keeps_the_line_waiting_with_the_new_order(): void {
		$line = $this->flagged( 'failed', NeedsAttention::ORDER_FAILED );
		$this->recorder
			->json( 200, $this->read( 'failed', array( 'canRetry' => true ) ) )
			->json(
				201,
				$this->placed(
					self::NEWER_ORDER_ID,
					array(
						'status'    => 'failed',
						'lastError' => 'Refused again.',
					)
				)
			);

		$result = $this->actions->run( $line, LineActions::RETRY );

		$this->assertSame( LineActions::RETRY_STILL_PENDING, $result->code );
		$this->assertSame( 'failed', $result->detail );
		$line = $this->line();
		$this->assertSame( LineState::NEEDS_ATTENTION, $line->state() );
		$this->assertSame( self::NEWER_ORDER_ID, $line->ref( LineState::REF_ORDER_ID ) );
	}

	public function test_a_retry_pixonaut_holds_shows_the_line_as_held_with_one_note(): void {
		$line = $this->flagged( 'failed', NeedsAttention::ORDER_FAILED );
		$this->recorder
			->json( 200, $this->read( 'failed', array( 'canRetry' => true ) ) )
			->json(
				201,
				$this->placed(
					self::NEWER_ORDER_ID,
					array(
						'status' => OrderStatus::HELD,
						'hold'   => self::HOLD,
					)
				)
			);

		$this->assertSame( LineActions::DONE, $this->actions->run( $line, LineActions::RETRY )->code );

		$line = $this->line();
		$this->assertSame( LineState::ORDERED, $line->state() );
		$this->assertSame( self::NEWER_ORDER_ID, $line->ref( LineState::REF_ORDER_ID ) );
		$this->assertSame( OrderStatus::HELD, $line->ref( LineState::REF_ORDER_STATUS ) );
		$this->assertSame( strtotime( self::PERIOD_END ), OrderHold::of( $line->ref( LineState::REF_HOLD ) )['periodEnd'] );
		$this->assertSame( 1, substr_count( $this->notes(), 'is held' ) );
	}

	public function test_a_read_of_a_held_order_shows_the_line_as_held_with_one_note(): void {
		$line = $this->line();
		$line->set_ref( LineState::REF_ORDER_ID, self::ORDER_ID );
		$this->assertTrue( $line->save() );
		$held = $this->read( OrderStatus::HELD, array( 'hold' => self::HOLD ) );

		$this->status_changed()->apply_read( $this->line(), $held );
		$this->status_changed()->apply_read( $this->line(), $held );

		$line = $this->line();
		$this->assertSame( LineState::ORDERED, $line->state() );
		$this->assertSame( OrderStatus::HELD, $line->ref( LineState::REF_ORDER_STATUS ) );
		$this->assertSame( strtotime( self::PERIOD_END ), OrderHold::of( $line->ref( LineState::REF_HOLD ) )['periodEnd'] );
		$this->assertSame( 1, substr_count( $this->notes(), 'is held' ) );
	}

	public function test_a_read_of_a_line_shown_as_held_without_its_hold_keeps_the_hold_with_one_note(): void {
		$line = $this->ordered( OrderStatus::HELD );
		$held = $this->read( OrderStatus::HELD, array( 'hold' => self::HOLD ) );

		$this->actions->reconcile( $line, $held );
		$this->actions->reconcile( $this->line(), $held );

		$line = $this->line();
		$this->assertSame( LineState::ORDERED, $line->state() );
		$this->assertSame( strtotime( self::PERIOD_END ), OrderHold::of( $line->ref( LineState::REF_HOLD ) )['periodEnd'] );
		$this->assertSame( 1, substr_count( $this->notes(), 'is held' ) );
	}

	public function test_a_read_of_a_released_order_still_held_adds_no_hold(): void {
		$line = $this->ordered( OrderStatus::HELD );
		$line->set_ref( LineState::REF_RELEASED_BY, OrderHold::RELEASED_BY_UPGRADE );
		$this->assertTrue( $line->save() );

		$this->actions->reconcile( $this->line(), $this->read( OrderStatus::HELD, array( 'hold' => self::HOLD ) ) );

		$this->assertNull( $this->line()->ref( LineState::REF_HOLD ) );
		$this->assertStringNotContainsString( 'is held', $this->notes() );
	}

	public function test_an_unknown_outcome_offers_the_moves_pixonaut_allows_and_cancel(): void {
		$line = $this->flagged( 'outcome_unknown', NeedsAttention::OUTCOME_UNKNOWN );

		$this->assertSame(
			array( LineActions::SETTLE_SUBMITTED, LineActions::SETTLE_FAILED, LineActions::CANCEL ),
			$this->actions->available( $line, $this->read( 'outcome_unknown', array( 'statusMoves' => array( 'submitted', 'failed' ) ) ) )
		);
		$this->assertSame( array( LineActions::CANCEL ), $this->actions->available( $line, $this->read( 'outcome_unknown' ) ) );
	}

	public function test_settling_needs_a_reason_and_sends_nothing_without_one(): void {
		$line = $this->flagged( 'outcome_unknown', NeedsAttention::OUTCOME_UNKNOWN );
		$this->recorder->json( 200, $this->read( 'outcome_unknown', array( 'statusMoves' => array( 'submitted', 'failed' ) ) ) );

		$this->assertSame( LineActions::REASON_MISSING, $this->actions->run( $line, LineActions::SETTLE_SUBMITTED, '  ' )->code );

		$this->assertSame( 0, $this->count_sent( 'PATCH', '/v1/orders/' . self::ORDER_ID ) );
		$this->assertSame( LineState::NEEDS_ATTENTION, $this->line()->state() );
	}

	public function test_the_printshop_having_the_order_settles_it_and_the_line_goes_on(): void {
		$line = $this->flagged( 'outcome_unknown', NeedsAttention::OUTCOME_UNKNOWN );
		$this->recorder
			->json( 200, $this->read( 'outcome_unknown', array( 'statusMoves' => array( 'submitted', 'failed' ) ) ) )
			->json( 200, $this->placed( self::ORDER_ID, array( 'externalOrderRef' => 'PF-889123' ) ) );

		$result = $this->actions->run( $line, LineActions::SETTLE_SUBMITTED, 'Printful has it.', 'PF-889123' );

		$this->assertSame( LineActions::DONE, $result->code );
		$this->assertSame(
			array(
				'status'           => 'submitted',
				'reason'           => 'Printful has it.',
				'externalOrderRef' => 'PF-889123',
			),
			$this->sent_body( 'PATCH', '/v1/orders/' . self::ORDER_ID )
		);
		$line = $this->line();
		$this->assertSame( LineState::ORDERED, $line->state() );
		$this->assertSame( 'submitted', $line->ref( LineState::REF_ORDER_STATUS ) );
		$this->assertStringContainsString( 'Printful has it.', $this->notes() );
	}

	public function test_the_printshop_not_having_the_order_fails_it_and_sends_it_again(): void {
		$line = $this->flagged( 'outcome_unknown', NeedsAttention::OUTCOME_UNKNOWN );
		$this->recorder
			->json( 200, $this->read( 'outcome_unknown', array( 'statusMoves' => array( 'submitted', 'failed' ) ) ) )
			->json( 200, $this->placed( self::ORDER_ID, array( 'status' => 'failed' ) ) )
			->json( 201, $this->placed( self::NEWER_ORDER_ID ) );

		$result = $this->actions->run( $line, LineActions::SETTLE_FAILED, 'No order under that number.' );

		$this->assertSame( LineActions::DONE, $result->code );
		$this->assertSame(
			array(
				'status' => 'failed',
				'reason' => 'No order under that number.',
			),
			$this->sent_body( 'PATCH', '/v1/orders/' . self::ORDER_ID )
		);
		$this->assertSame( 1, $this->count_sent( 'POST', '/v1/orders/' . self::ORDER_ID . '/retry' ) );
		$line = $this->line();
		$this->assertSame( LineState::ORDERED, $line->state() );
		$this->assertSame( self::NEWER_ORDER_ID, $line->ref( LineState::REF_ORDER_ID ) );
	}

	public function test_a_move_pixonaut_no_longer_allows_changes_nothing(): void {
		$line = $this->flagged( 'outcome_unknown', NeedsAttention::OUTCOME_UNKNOWN );
		$this->recorder
			->json( 200, $this->read( 'outcome_unknown', array( 'statusMoves' => array( 'submitted', 'failed' ) ) ) )
			->json(
				409,
				array(
					'error' => 'order_status_not_allowed',
					'from'  => 'submitted',
					'to'    => 'submitted',
				)
			);

		$this->assertSame( LineActions::STATUS_NOT_ALLOWED, $this->actions->run( $line, LineActions::SETTLE_SUBMITTED, 'It is there.' )->code );

		$this->assertSame( LineState::NEEDS_ATTENTION, $this->line()->state() );
		$this->assertSame( 'outcome_unknown', $this->line()->ref( LineState::REF_ORDER_STATUS ) );
	}

	public function test_a_reference_another_order_holds_changes_nothing(): void {
		$line = $this->flagged( 'outcome_unknown', NeedsAttention::OUTCOME_UNKNOWN );
		$this->recorder
			->json( 200, $this->read( 'outcome_unknown', array( 'statusMoves' => array( 'submitted', 'failed' ) ) ) )
			->json( 409, array( 'error' => 'order_reference_taken' ) );

		$this->assertSame( LineActions::REFERENCE_TAKEN, $this->actions->run( $line, LineActions::SETTLE_SUBMITTED, 'It is there.', 'PF-1' )->code );

		$this->assertSame( LineState::NEEDS_ATTENTION, $this->line()->state() );
	}

	public function test_cancel_asks_the_printshop_and_cancels_the_line(): void {
		$line = $this->flagged( 'outcome_unknown', NeedsAttention::OUTCOME_UNKNOWN );
		$this->recorder
			->json( 200, $this->read( 'outcome_unknown' ) )
			->json( 200, $this->placed( self::ORDER_ID, array( 'status' => 'cancelled' ) ) );

		$this->assertSame( LineActions::DONE, $this->actions->run( $line, LineActions::CANCEL )->code );

		$this->assertSame( 1, $this->count_sent( 'POST', '/v1/orders/' . self::ORDER_ID . '/cancel' ) );
		$this->assertSame( LineState::CANCELLED, $this->line()->state() );
	}

	public function test_an_action_pixonaut_does_not_allow_is_not_sent(): void {
		$line = $this->flagged( 'failed', NeedsAttention::ORDER_FAILED );
		$this->recorder->json( 200, $this->read( 'failed' ) );

		$this->assertSame( LineActions::NOT_AVAILABLE, $this->actions->run( $line, LineActions::RETRY )->code );

		$this->assertSame( 0, $this->count_sent( 'POST', '/v1/orders/' . self::ORDER_ID . '/retry' ) );
	}

	public function test_a_sandbox_order_can_be_rehearsed_and_the_line_follows(): void {
		$this->store->save( self::connection( StoredConnection::SANDBOX ) );
		$line  = $this->ordered();
		$order = $this->read( 'submitted', array( 'statusMoves' => array( 'queued', 'printed', 'shipped', 'failed' ) ) );
		$this->assertTrue( $this->actions->reads_order( $line ) );
		$this->assertSame(
			array( 'rehearse_queued', 'rehearse_printed', 'rehearse_shipped', 'rehearse_failed' ),
			$this->actions->available( $line, $order )
		);
		$this->recorder
			->json( 200, $order )
			->json( 200, $this->placed( self::ORDER_ID, array( 'status' => 'shipped' ) ) );

		$this->assertSame( LineActions::DONE, $this->actions->run( $line, LineActions::REHEARSE . 'shipped' )->code );

		$this->assertSame( array( 'status' => 'shipped' ), $this->sent_body( 'PATCH', '/v1/orders/' . self::ORDER_ID ) );
		$this->assertSame( LineState::SHIPPED, $this->line()->state() );
	}

	public function test_a_rehearsed_failure_needs_attention(): void {
		$this->store->save( self::connection( StoredConnection::SANDBOX ) );
		$line = $this->ordered();
		$this->recorder
			->json( 200, $this->read( 'submitted', array( 'statusMoves' => array( 'failed' ) ) ) )
			->json( 200, $this->placed( self::ORDER_ID, array( 'status' => 'failed' ) ) );

		$this->assertSame( LineActions::DONE, $this->actions->run( $line, LineActions::REHEARSE . 'failed' )->code );

		$this->assertSame( LineState::NEEDS_ATTENTION, $this->line()->state() );
		$this->assertSame( NeedsAttention::ORDER_FAILED, $this->line()->reason() );
	}

	public function test_a_live_order_offers_no_rehearsal(): void {
		$line = $this->ordered();

		$this->assertFalse( $this->actions->reads_order( $line ) );
		$this->assertSame( array(), $this->actions->available( $line, $this->read( 'submitted', array( 'statusMoves' => array( 'shipped' ) ) ) ) );
	}

	public function test_a_line_stopped_before_its_order_is_retried_by_the_store(): void {
		$line = $this->line();
		$this->assertTrue( $line->transition( LineState::APPROVED ) );
		$this->assertTrue( NeedsAttention::flag( $line, $this->order, NeedsAttention::RETRIES_EXHAUSTED, 'Gave up.' ) );
		$line = $this->line();
		$this->assertSame( array( LineActions::RETRY ), $this->actions->available( $line, null ) );

		$this->assertSame( LineActions::DONE, $this->actions->run( $line, LineActions::RETRY )->code );

		$this->assertSame( LineState::APPROVED, $this->line()->state() );
		$this->assertSame( array( $this->item->get_id() ), $this->scheduled );
		$this->assertSame( array(), $this->recorder->requests );
	}

	public function test_a_line_whose_design_was_ordered_elsewhere_can_be_ordered_again_anyway(): void {
		$line = $this->line();
		$this->assertTrue( $line->transition( LineState::APPROVED ) );
		$line->set_ref( LineState::REF_ORDER_KEY, 'wc:site:item:1' );
		$this->assertTrue( NeedsAttention::flag( $line, $this->order, ErrorCodes::DESIGN_ALREADY_ORDERED, 'Ordered elsewhere.' ) );
		$line = $this->line();
		$this->assertSame( array( LineActions::RETRY, LineActions::REORDER ), $this->actions->available( $line, null ) );

		$this->assertSame( LineActions::DONE, $this->actions->run( $line, LineActions::REORDER )->code );

		$line = $this->line();
		$this->assertSame( LineState::APPROVED, $line->state() );
		$this->assertTrue( $line->ref( LineState::REF_REORDER ) );
		$this->assertNull( $line->ref( LineState::REF_ORDER_KEY ), 'The next run builds the reorder\'s own key.' );
		$this->assertSame( array( $this->item->get_id() ), $this->scheduled );
		$this->assertSame( array(), $this->recorder->requests );

		// A line that reorders already offers no second reorder.
		$this->assertTrue( NeedsAttention::flag( $line, $this->order, ErrorCodes::DESIGN_ALREADY_ORDERED, 'Ordered elsewhere.' ) );
		$this->assertSame( array( LineActions::RETRY ), $this->actions->available( $this->line(), null ) );
	}

	public function test_a_line_of_standing_designs_alone_is_never_offered_an_order_again(): void {
		$order = $this->paid_order( array() );
		$item  = current( $order->get_items() );
		$this->assertInstanceOf( WC_Order_Item_Product::class, $item );
		$line = LineState::of( $item );
		$line->set_ref( LineState::REF_STANDING, array( ( new StandingSide( 'front', 'des_standing_1', 0 ) )->to_array() ) );
		$this->assertTrue( $line->transition( LineState::APPROVED ) );
		$this->assertTrue( NeedsAttention::flag( $line, $order, ErrorCodes::DESIGN_ALREADY_ORDERED, 'Ordered elsewhere.' ) );

		$this->assertSame( array( LineActions::RETRY ), $this->actions->available( LineState::of( new WC_Order_Item_Product( $item->get_id() ) ), null ) );
	}

	public function test_a_store_retry_action_scheduler_refuses_leaves_the_line_needing_attention(): void {
		$line = $this->line();
		$this->assertTrue( $line->transition( LineState::APPROVED ) );
		$this->assertTrue( NeedsAttention::flag( $line, $this->order, NeedsAttention::RETRIES_EXHAUSTED, 'Gave up.' ) );
		$this->schedules = false;

		$this->assertSame( LineActions::NOT_SCHEDULED, $this->actions->run( $this->line(), LineActions::RETRY )->code );

		$line = $this->line();
		$this->assertSame( LineState::NEEDS_ATTENTION, $line->state() );
		$this->assertSame( NeedsAttention::RETRY_NOT_SCHEDULED, $line->reason() );
		$this->assertSame( array( LineActions::RETRY ), $this->actions->available( $line, null ) );
		$this->assertTrue( $this->has_notice() );
		$this->assertSame( 'error', $this->logger->entries[0]['level'] ?? null );
	}

	public function test_a_job_action_scheduler_refuses_is_not_reported_as_scheduled(): void {
		add_filter( 'pre_as_enqueue_async_action', static fn (): int => 0 );

		$this->assertFalse( FulfilmentJobs::schedule( $this->item->get_id() ) );
	}

	public function test_a_retry_whose_answer_was_lost_is_found_and_the_line_takes_its_order(): void {
		$this->sign_in( 'shop_manager' );
		$this->flagged( 'failed', NeedsAttention::ORDER_FAILED );
		$this->recorder
			->json( 200, $this->read( 'failed', array( 'replacedByOrderId' => self::NEWER_ORDER_ID ) ) )
			->json(
				200,
				FulfilmentAnswers::read_order(
					self::NEWER_ORDER_ID,
					$this->external_ref(),
					array(
						'status'            => 'failed',
						'replacesOrderId'   => self::ORDER_ID,
						'replacedByOrderId' => self::NEWEST_ORDER_ID,
					)
				)
			)
			->json( 200, FulfilmentAnswers::read_order( self::NEWEST_ORDER_ID, $this->external_ref(), array( 'replacesOrderId' => self::NEWER_ORDER_ID ) ) )
			->json( 200, FulfilmentAnswers::read_order( self::NEWEST_ORDER_ID, $this->external_ref(), array( 'status' => 'submitted' ) ) );

		$html = $this->render_box();

		$line = $this->line();
		$this->assertSame( LineState::ORDERED, $line->state() );
		$this->assertSame( self::NEWEST_ORDER_ID, $line->ref( LineState::REF_ORDER_ID ) );
		$this->assertSame( 'submitted', $line->ref( LineState::REF_ORDER_STATUS ) );
		$this->assertStringContainsString( ConnectionAnswers::studio_path() . 'orders/' . self::NEWEST_ORDER_ID, $html );
		$this->assertStringContainsString( self::NEWEST_ORDER_ID, $this->notes() );
		$this->assertFalse( $this->has_notice() );
	}

	public function test_a_settlement_whose_answer_was_lost_says_so_and_the_next_read_catches_the_line_up(): void {
		$this->sign_in( 'shop_manager' );
		$line = $this->flagged( 'outcome_unknown', NeedsAttention::OUTCOME_UNKNOWN );
		$this->recorder
			->json( 200, $this->read( 'outcome_unknown', array( 'statusMoves' => array( 'submitted', 'failed' ) ) ) )
			->fail()
			->json( 200, $this->read( 'submitted' ) );

		$result = $this->actions->run( $line, LineActions::SETTLE_SUBMITTED, 'Printful has it.' );

		$this->assertSame( LineActions::UNREACHABLE, $result->code );
		$this->assertSame( LineState::NEEDS_ATTENTION, $this->line()->state() );
		$this->assertSame( 'warning', $this->logger->entries[0]['level'] ?? null );
		$this->assertSame( 'transport_failed', $this->logger->entries[0]['context']['code'] ?? null );
		$this->assertStringNotContainsString( 'Printful has it.', (string) wp_json_encode( $this->logger->entries ) );

		$this->render_box();

		$line = $this->line();
		$this->assertSame( LineState::ORDERED, $line->state() );
		$this->assertSame( 'submitted', $line->ref( LineState::REF_ORDER_STATUS ) );
		$this->assertStringContainsString( 'is now "submitted" in Pixonaut', $this->notes() );
	}

	public function test_a_cancel_whose_answer_was_lost_is_caught_up_on_the_next_read(): void {
		$this->sign_in( 'shop_manager' );
		$line = $this->flagged( 'outcome_unknown', NeedsAttention::OUTCOME_UNKNOWN );
		$this->recorder
			->json( 200, $this->read( 'outcome_unknown' ) )
			->fail()
			->json( 200, $this->read( 'cancelled' ) );

		$this->assertSame( LineActions::UNREACHABLE, $this->actions->run( $line, LineActions::CANCEL )->code );

		$this->render_box();

		$line = $this->line();
		$this->assertSame( LineState::CANCELLED, $line->state() );
		$this->assertSame( 'cancelled', $line->ref( LineState::REF_ORDER_STATUS ) );
	}

	public function test_a_replacement_that_shipped_already_ships_the_line_and_completes_the_order(): void {
		$this->order->set_status( 'processing' );
		$this->order->save();
		$line = $this->flagged( 'failed', NeedsAttention::ORDER_FAILED );
		$this->recorder
			->json( 200, $this->read( 'failed', array( 'canRetry' => true ) ) )
			->json(
				201,
				$this->placed(
					self::NEWER_ORDER_ID,
					array(
						'status'          => 'shipped',
						'replacesOrderId' => self::ORDER_ID,
						'tracking'        => array(
							'carrier' => 'PostNord',
							'number'  => 'PN123',
						),
					)
				)
			);

		$this->assertSame( LineActions::DONE, $this->actions->run( $line, LineActions::RETRY )->code );

		$line = $this->line();
		$this->assertSame( LineState::SHIPPED, $line->state() );
		$this->assertSame( 'shipped', $line->ref( LineState::REF_ORDER_STATUS ) );
		$this->assertSame(
			array(
				'number'  => 'PN123',
				'carrier' => 'PostNord',
			),
			$line->ref( LineState::REF_TRACKING )
		);
		$order = wc_get_order( $this->order->get_id() );
		$this->assertInstanceOf( WC_Order::class, $order );
		$this->assertSame( 'completed', $order->get_status() );
	}

	public function test_an_unreachable_pixonaut_is_asked_once_per_render(): void {
		$this->sign_in( 'shop_manager' );
		$this->flagged( 'failed', NeedsAttention::ORDER_FAILED );
		$second = new WC_Order_Item_Product();
		$second->set_name( 'Second tee' );
		$this->order->add_item( $second );
		$this->order->save();
		$second_line = LineState::start( $second, array( 'front' ) );
		$second_line->set_ref( LineState::REF_ORDER_ID, self::NEWER_ORDER_ID );
		$this->assertTrue( $second_line->save() );
		$this->assertTrue( NeedsAttention::flag( LineState::of( $second ), $this->order, NeedsAttention::ORDER_FAILED, 'Flagged.' ) );
		$this->recorder->fail();

		$html = $this->render_box();

		$this->assertCount( 1, $this->recorder->requests );
		$this->assertSame( 2, substr_count( $html, 'pixonaut-order-line-unreadable' ) );
		$this->assertSame( 2, substr_count( $html, 'transport_failed' ) );
	}

	public function test_nothing_is_offered_while_the_store_is_not_connected(): void {
		$line = $this->flagged( 'failed', NeedsAttention::ORDER_FAILED );
		$this->store->clear();

		$this->assertSame( array(), $this->actions->available( $line, $this->read( 'failed', array( 'canRetry' => true ) ) ) );
	}

	public function test_the_actions_are_filterable(): void {
		$line = $this->flagged( 'outcome_unknown', NeedsAttention::OUTCOME_UNKNOWN );
		add_filter( 'pixonaut_wc_fulfilment_line_actions', static fn ( array $actions ): array => array_values( array_diff( $actions, array( LineActions::CANCEL ) ) ) );

		$this->assertSame( array(), $this->actions->available( $line, $this->read( 'outcome_unknown' ) ) );
	}

	public function test_the_box_shows_only_the_actions_pixonaut_allows(): void {
		$this->sign_in( 'shop_manager' );
		$this->flagged( 'outcome_unknown', NeedsAttention::OUTCOME_UNKNOWN );
		$this->recorder->json( 200, $this->read( 'outcome_unknown', array( 'statusMoves' => array( 'submitted' ) ) ) );

		$html = $this->render_box();

		$item = $this->item->get_id();
		$this->assertStringContainsString( 'value="' . $item . ':settle_submitted"', $html );
		$this->assertStringContainsString( 'value="' . $item . ':cancel"', $html );
		$this->assertStringNotContainsString( 'settle_failed', $html );
		$this->assertStringNotContainsString( ':retry"', $html );
		$this->assertStringContainsString( 'pixonaut_line[' . $item . '][reason]', $html );
		$this->assertStringContainsString( ConnectionAnswers::studio_path() . 'designs/' . self::DESIGN, $html );
		$this->assertStringContainsString( ConnectionAnswers::studio_path() . 'orders/' . self::ORDER_ID, $html );
	}

	public function test_the_box_shows_a_line_whose_order_waits_for_its_prints(): void {
		$this->sign_in( 'shop_manager' );
		$this->ordered( 'awaiting_prints' );

		$html = $this->render_box();

		$this->assertStringContainsString( 'Waiting for prints', $html );
		$this->assertStringNotContainsString( 'At the printshop', $html );
		$this->assertSame( array(), $this->recorder->requests );
	}

	public function test_the_box_shows_no_action_to_a_user_who_may_not_act(): void {
		$this->sign_in( 'editor' );
		$this->flagged( 'failed', NeedsAttention::ORDER_FAILED );
		$this->recorder->json( 200, $this->read( 'failed', array( 'canRetry' => true ) ) );

		$html = $this->render_box();

		$this->assertStringNotContainsString( 'name="pixonaut_line_action"', $html );
		$this->assertStringContainsString( 'Needs attention', $html );
	}

	public function test_the_box_says_when_pixonaut_could_not_be_asked(): void {
		$this->sign_in( 'shop_manager' );
		$this->flagged( 'failed', NeedsAttention::ORDER_FAILED );
		$this->recorder->json( 500, array( 'error' => 'db_error' ) );

		$html = $this->render_box();

		$this->assertStringContainsString( 'db_error', $html );
		$this->assertStringNotContainsString( 'name="pixonaut_line_action"', $html );
	}

	public function test_a_user_who_may_not_act_cannot_run_an_action(): void {
		$this->sign_in( 'editor' );
		$this->flagged( 'failed', NeedsAttention::ORDER_FAILED );
		$this->press( LineActions::RETRY );

		$this->box()->handle( $this->order->get_id() );

		$this->assertSame( array(), $this->recorder->requests );
		$this->assertSame( LineState::NEEDS_ATTENTION, $this->line()->state() );
	}

	public function test_an_action_without_its_nonce_is_not_run(): void {
		$this->sign_in( 'shop_manager' );
		$this->flagged( 'failed', NeedsAttention::ORDER_FAILED );
		$this->press( LineActions::RETRY );
		$_POST[ OrderBox::NONCE_FIELD ] = 'forged';

		$this->box()->handle( $this->order->get_id() );

		$this->assertSame( array(), $this->recorder->requests );
	}

	public function test_the_box_runs_an_action_and_shows_what_came_of_it_once(): void {
		$this->sign_in( 'shop_manager' );
		$this->flagged( 'outcome_unknown', NeedsAttention::OUTCOME_UNKNOWN );
		$this->recorder
			->json( 200, $this->read( 'outcome_unknown', array( 'statusMoves' => array( 'submitted', 'failed' ) ) ) )
			->json( 409, array( 'error' => 'order_reference_taken' ) )
			->json( 200, $this->read( 'outcome_unknown', array( 'statusMoves' => array( 'submitted', 'failed' ) ) ) )
			->json( 200, $this->read( 'outcome_unknown', array( 'statusMoves' => array( 'submitted', 'failed' ) ) ) );
		$this->press(
			LineActions::SETTLE_SUBMITTED,
			array(
				'reason' => 'They have it.',
				'ref'    => 'PF-1',
			)
		);

		$this->box()->handle( $this->order->get_id() );

		$this->assertSame(
			array(
				'status'           => 'submitted',
				'reason'           => 'They have it.',
				'externalOrderRef' => 'PF-1',
			),
			$this->sent_body( 'PATCH', '/v1/orders/' . self::ORDER_ID )
		);
		$this->assertStringContainsString( 'Another order in Pixonaut has that printshop reference', $this->render_box() );
		$this->assertStringNotContainsString( 'Another order in Pixonaut has that printshop reference', $this->render_box() );
	}

	/**
	 * Signs in a new user.
	 *
	 * @param string $role The user's role.
	 */
	private function sign_in( string $role ): void {
		$user = self::factory()->user->create( array( 'role' => $role ) );
		$this->assertIsInt( $user );
		wp_set_current_user( $user );
	}

	/**
	 * The line in `needs_attention` after its printshop order reached $status.
	 *
	 * @param string $status Pixonaut's order status.
	 * @param string $reason The line's reason.
	 */
	/**
	 * `409 design_already_ordered` of a retry, naming another order of the design.
	 *
	 * @return array<string, string>
	 */
	private function already_ordered(): array {
		return array(
			'error'    => 'design_already_ordered',
			'designId' => self::DESIGN,
			'orderId'  => self::NEWER_ORDER_ID,
		);
	}

	/**
	 * `GET /v1/orders/{id}` of another order of the line's design.
	 *
	 * @param string $via      Who placed it.
	 * @param int    $quantity Copies it holds.
	 *
	 * @return array<string, mixed>
	 */
	private function newer_order( string $via, int $quantity ): array {
		$order              = FulfilmentAnswers::read_order( self::NEWER_ORDER_ID, $this->external_ref(), array( 'quantity' => $quantity ) );
		$order['prints'][0] = array(
			'designId'       => self::DESIGN,
			'designRevision' => self::REVISION,
		) + $order['prints'][0];
		return FulfilmentAnswers::placed_by(
			$order,
			$via,
			'automation' === $via ? array(
				'id'   => 'auto_1',
				'name' => 'Order approved designs',
			) : null
		);
	}

	private function flagged( string $status, string $reason ): LineState {
		$line = $this->ordered( $status );
		$this->assertTrue( NeedsAttention::flag( $line, $this->order, $reason, 'Flagged.' ) );
		return $this->line();
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
		$line->set_side_ref( 'front', LineState::SIDE_PROOF_ID, 'prf_1' );
		$this->assertTrue( $line->transition( LineState::ORDERED ) );
		return $this->line();
	}

	/**
	 * Pixonaut's answer to reading the line's order.
	 *
	 * @param string               $status    The order's status.
	 * @param array<string, mixed> $overrides Further fields.
	 *
	 * @return array<string, mixed>
	 */
	private function read( string $status, array $overrides = array() ): array {
		return FulfilmentAnswers::read_order( self::ORDER_ID, $this->external_ref(), array( 'status' => $status ) + $overrides );
	}

	/**
	 * Pixonaut's answer to a move, retry or cancel: the order as it is now.
	 *
	 * @param string               $order_id  The order.
	 * @param array<string, mixed> $overrides Fields to replace.
	 *
	 * @return array<string, mixed>
	 */
	private function placed( string $order_id, array $overrides = array() ): array {
		return FulfilmentAnswers::order( $order_id, array( 'p_front' ), $this->external_ref(), $overrides );
	}

	/**
	 * Fills the order form as pressing the line's button would.
	 *
	 * @param string                $action The action.
	 * @param array<string, string> $inputs The line's inputs.
	 */
	private function press( string $action, array $inputs = array() ): void {
		$_POST[ OrderBox::ACTION_FIELD ] = $this->item->get_id() . ':' . $action;
		$_POST[ OrderBox::NONCE_FIELD ]  = wp_create_nonce( OrderBox::NONCE_ACTION );
		$_POST[ OrderBox::INPUT_FIELD ]  = array( $this->item->get_id() => $inputs );
	}

	private function render_box(): string {
		ob_start();
		$this->box()->render( wc_get_order( $this->order->get_id() ) );
		return (string) ob_get_clean();
	}

	private function box(): OrderBox {
		return new OrderBox( $this->actions, new ProofUrls( 'secret' ), Templates::for_plugin( self::plugin_file() ) );
	}

	/**
	 * The body of the one request sent to $path.
	 *
	 * @param string $method Method.
	 * @param string $path   Path below the base URL.
	 *
	 * @return array<string, mixed>
	 */
	private function sent_body( string $method, string $path ): array {
		$sent = array_values( array_filter( $this->recorder->requests, static fn ( array $request ): bool => $method === $request['method'] && self::BASE_URL . $path === $request['url'] ) );
		$this->assertCount( 1, $sent );
		return (array) json_decode( (string) $sent[0]['body'], true );
	}

	private function line_actions(): LineActions {
		$store = $this->store;
		return new LineActions(
			new Orders( $this->client() ),
			static fn (): ?StoredConnection => $store->load(),
			$this->status_changed(),
			new OrderFailed( $this->event_lines() ),
			function ( int $item_id ): bool {
				$this->scheduled[] = $item_id;
				return $this->schedules;
			},
			FulfilmentJobs::is_scheduled( ... ),
			$this->logger
		);
	}

	private function client(): Client {
		return new Client( self::BASE_URL, Operations::plugin(), $this->store, new WpHttpTransport(), new WcLogger(), new Deprecations() );
	}

	private function event_lines(): OrderEventLines {
		$connection = new Connection( $this->store, new ConnectAttempts(), new Connect( $this->client() ), new Notices( Templates::for_plugin( self::plugin_file() ) ), new WcLogger(), ConnectionScreen::settings_url() );
		return new OrderEventLines( $connection, new WcLogger() );
	}

	private function status_changed(): OrderStatusChanged {
		return new OrderStatusChanged( $this->event_lines(), new Shipments() );
	}

	private static function plugin_file(): string {
		return dirname( __DIR__, 4 ) . '/pixonaut-for-woocommerce.php';
	}

	private function external_ref(): string {
		return 'wc:' . self::SITE . ':order:' . $this->order->get_id() . ':item:' . $this->item->get_id();
	}

	private function has_notice(): bool {
		return array_key_exists( AttentionAlerts::key( $this->order->get_id() ), (array) get_option( Notices::OPTION, array() ) );
	}

	/**
	 * The order's notes as one string.
	 */
	private function notes(): string {
		$notes = wc_get_order_notes( array( 'order_id' => $this->order->get_id() ) );
		return implode( "\n", array_map( static fn ( $note ): string => (string) $note->content, $notes ) );
	}
}
