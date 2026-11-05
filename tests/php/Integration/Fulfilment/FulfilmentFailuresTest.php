<?php
/**
 * @package Pixonaut\WooCommerce
 */

declare(strict_types=1);

namespace Pixonaut\WooCommerce\Tests\Integration\Fulfilment;

use Pixonaut\WooCommerce\Admin\Notices;
use Pixonaut\WooCommerce\Connection\StoredConnection;
use Pixonaut\WooCommerce\Fulfilment\AttentionAlerts;
use Pixonaut\WooCommerce\Fulfilment\FulfilmentJobs;
use Pixonaut\WooCommerce\Fulfilment\LineState;
use Pixonaut\WooCommerce\Fulfilment\NeedsAttention;
use Pixonaut\WooCommerce\Fulfilment\RetryPolicy;
use Pixonaut\WooCommerce\Tests\Support\ActionSchedulerRunner;
use Pixonaut\WooCommerce\Tests\Support\DesignFixture;
use Pixonaut\WooCommerce\Tests\Support\FulfilmentAnswers;
use Pixonaut\WooCommerce\Tests\Support\SentMail;
use WC_Order_Item_Product;

/**
 * Each way Pixonaut can turn a step down, through the job, its retries and
 * the store's alerts.
 */
final class FulfilmentFailuresTest extends FulfilmentTestCase {

	public function set_up(): void {
		parent::set_up();
		reset_phpmailer_instance();
	}

	public function test_an_order_that_keeps_failing_is_retried_on_the_schedule_then_needs_attention(): void {
		$this->recorder->json( 200, FulfilmentAnswers::approved() );
		foreach ( range( 0, count( RetryPolicy::SCHEDULE ) ) as $attempt ) {
			$this->recorder
				->json( 200, FulfilmentAnswers::orders( array() ) )
				->json( 200, FulfilmentAnswers::design_approved( self::DESIGN, self::REVISION ) )
				->json( 503, array( 'error' => 'lock_timeout' ) );
		}

		do_action( 'woocommerce_payment_complete', $this->order->get_id() );
		$this->assertSame( count( RetryPolicy::SCHEDULE ) + 1, ActionSchedulerRunner::run_pending( FulfilmentJobs::ACTION ) );

		$orders = array_values( array_filter( $this->recorder->requests, static fn ( array $request ): bool => 'POST' === $request['method'] && self::BASE_URL . '/v1/orders' === $request['url'] ) );
		$this->assertCount( count( RetryPolicy::SCHEDULE ) + 1, $orders );
		$this->assertCount( 1, array_unique( array_map( static fn ( array $request ): string => $request['headers']['Idempotency-Key'] . $request['body'], $orders ) ) );
		$this->assertSame( array(), ActionSchedulerRunner::failed( FulfilmentJobs::ACTION ) );
		$line = $this->line();
		$this->assertSame( LineState::NEEDS_ATTENTION, $line->state() );
		$this->assertSame( LineState::APPROVED, $line->resume_from() );
		$this->assertSame( NeedsAttention::RETRIES_EXHAUSTED, $line->reason() );
		$this->assertNull( $line->ref( LineState::REF_RETRY ) );
		$this->assertStringContainsString( 'lock_timeout', $this->notes() );
		$this->assertTrue( $this->has_notice() );
		$this->assertCount( 1, SentMail::all() );
	}

	public function test_an_order_that_keeps_timing_out_on_a_lock_is_retried_with_the_same_key(): void {
		$this->recorder
			->json( 200, FulfilmentAnswers::approved() )
			->json( 200, FulfilmentAnswers::orders( array() ) )
			->json( 200, FulfilmentAnswers::design_approved( self::DESIGN, self::REVISION ) )
			->json( 503, array( 'error' => 'lock_timeout' ) )
			->json( 200, FulfilmentAnswers::orders( array() ) )
			->json( 200, FulfilmentAnswers::design_approved( self::DESIGN, self::REVISION ) )
			->json( 201, FulfilmentAnswers::order( 'o_1', array( 'p_front' ), $this->external_ref() ) );

		do_action( 'woocommerce_payment_complete', $this->order->get_id() );
		$this->assertSame( 2, ActionSchedulerRunner::run_pending( FulfilmentJobs::ACTION ) );

		$orders = array_values( array_filter( $this->recorder->requests, static fn ( array $request ): bool => 'POST' === $request['method'] && self::BASE_URL . '/v1/orders' === $request['url'] ) );
		$this->assertCount( 2, $orders );
		$this->assertSame( $orders[0]['headers']['Idempotency-Key'], $orders[1]['headers']['Idempotency-Key'] );
		$this->assertSame( $orders[0]['body'], $orders[1]['body'] );
		$this->assertSame( LineState::ORDERED, $this->line()->state() );
		$this->assertSame( array(), SentMail::all() );
	}

	/**
	 * Refusals the store cannot cure by sending again, and the answers that
	 * lead up to each.
	 *
	 * @return iterable<string, array{list<array{int, array<string, mixed>}>, string}>
	 */
	public static function refusals(): iterable {
		$approved = array( 200, FulfilmentAnswers::approved() );
		$no_order = array( 200, FulfilmentAnswers::orders( array() ) );
		$read     = array( 200, FulfilmentAnswers::design_approved( self::DESIGN, self::REVISION ) );
		yield 'revision_changed on approval' => array( array( array( 409, array( 'error' => 'revision_changed' ) ) ), 'revision_changed' );
		yield 'printshop_differs_from_blank on order' => array(
			array(
				$approved,
				$no_order,
				$read,
				array(
					422,
					array(
						'error'  => 'printshop_differs_from_blank',
						'reason' => 'The blank is made by Printful.',
					),
				),
			),
			'printshop_differs_from_blank',
		);
		yield 'printshop_disconnected on order' => array(
			array(
				$approved,
				$no_order,
				$read,
				array(
					422,
					array(
						'error'  => 'printshop_disconnected',
						'reason' => 'Printful is disconnected.',
					),
				),
			),
			'printshop_disconnected',
		);
		yield 'print_needs_printshop on order' => array( array( $approved, $no_order, $read, array( 409, array( 'error' => 'print_needs_printshop' ) ) ), 'print_needs_printshop' );
		yield 'postal_code_required on order' => array( array( $approved, $no_order, $read, array( 422, array( 'error' => 'postal_code_required' ) ) ), 'postal_code_required' );
		$size = array(
			'widthMm'  => 300,
			'heightMm' => 400,
		);
		yield 'print_not_position_size on order' => array(
			array(
				$approved,
				$no_order,
				$read,
				array(
					422,
					array(
						'error'     => 'print_not_position_size',
						'placement' => 'front',
						'position'  => 'chest',
						'printMm'   => $size,
						'sizeMm'    => $size,
						'reason'    => 'The chest prints 250 × 300 mm.',
					),
				),
			),
			'print_not_position_size',
		);
		yield 'print_exceeds_machine on order' => array(
			array(
				$approved,
				$no_order,
				$read,
				array(
					422,
					array(
						'error'     => 'print_exceeds_machine',
						'machine'   => 'DTF 60',
						'mediaMm'   => $size,
						'printMm'   => $size,
						'printshop' => 'Printful',
						'reason'    => 'The print is larger than the machine takes.',
					),
				),
			),
			'print_exceeds_machine',
		);
		yield 'design_erased on order' => array( array( $approved, $no_order, $read, array( 409, array( 'error' => 'design_erased' ) ) ), 'design_erased' );
		yield 'design_already_ordered on order' => array(
			array(
				$approved,
				$no_order,
				$read,
				array(
					409,
					array(
						'error'    => 'design_already_ordered',
						'designId' => self::DESIGN,
						'orderId'  => 'o_studio',
					),
				),
				array( 200, FulfilmentAnswers::placed_by( FulfilmentAnswers::read_order( 'o_studio', 'studio-ref' ), 'member' ) ),
			),
			'design_already_ordered',
		);
	}

	/**
	 * @dataProvider refusals
	 *
	 * @param list<array{int, array<string, mixed>}> $answers Pixonaut's answers, in order.
	 * @param string                                 $code    The refusal.
	 */
	public function test_a_refusal_needs_attention_at_once_with_its_code_in_the_note( array $answers, string $code ): void {
		foreach ( $answers as list( $status, $body ) ) {
			$this->recorder->json( $status, $body );
		}

		do_action( 'woocommerce_payment_complete', $this->order->get_id() );
		$this->assertSame( 1, ActionSchedulerRunner::run_pending( FulfilmentJobs::ACTION ) );

		$line = $this->line();
		$this->assertSame( LineState::NEEDS_ATTENTION, $line->state() );
		$this->assertSame( $code, $line->reason() );
		$this->assertStringContainsString( $code, $this->notes() );
		$this->assertStringContainsString( NeedsAttention::explain( $code ), $this->notes() );
		$this->assertTrue( $this->has_notice() );
		$this->assertCount( 1, SentMail::all() );
		$this->assertStringContainsString( $code, SentMail::all()[0]['body'] );
	}

	public function test_a_design_with_a_live_order_names_that_order_in_the_note(): void {
		$this->recorder
			->json( 200, FulfilmentAnswers::approved() )
			->json( 200, FulfilmentAnswers::orders( array() ) )
			->json( 200, FulfilmentAnswers::design_approved( self::DESIGN, self::REVISION ) )
			->json(
				409,
				array(
					'error'    => 'design_already_ordered',
					'designId' => self::DESIGN,
					'orderId'  => 'o_studio',
				)
			)
			->json( 200, FulfilmentAnswers::placed_by( FulfilmentAnswers::read_order( 'o_studio', 'studio-ref' ), 'member' ) );

		do_action( 'woocommerce_payment_complete', $this->order->get_id() );
		ActionSchedulerRunner::run_pending( FulfilmentJobs::ACTION );

		$this->assertSame( 'design_already_ordered', $this->line()->reason() );
		$this->assertStringContainsString( 'o_studio', $this->notes() );
	}

	public function test_a_design_edited_after_its_approval_is_not_ordered_and_needs_attention(): void {
		$this->recorder
			->json( 200, FulfilmentAnswers::approved() )
			->json( 200, FulfilmentAnswers::orders( array() ) )
			->json( 200, FulfilmentAnswers::design_approved( self::DESIGN, self::REVISION + 1 ) );

		do_action( 'woocommerce_payment_complete', $this->order->get_id() );
		ActionSchedulerRunner::run_pending( FulfilmentJobs::ACTION );

		$line = $this->line();
		$this->assertSame( LineState::NEEDS_ATTENTION, $line->state() );
		$this->assertSame( NeedsAttention::DESIGN_EDITED, $line->reason() );
		$this->assertSame( 0, $this->count_sent( 'POST', '/v1/orders' ) );
		$this->assertStringContainsString( 'changed in Pixonaut after it was approved', $this->notes() );
		$this->assertTrue( $this->has_notice() );
	}

	public function test_an_answer_this_version_does_not_know_needs_attention(): void {
		$this->recorder->json( 200, FulfilmentAnswers::approved() )->off_spec( 418, array( 'error' => 'teapot' ) );

		do_action( 'woocommerce_payment_complete', $this->order->get_id() );
		ActionSchedulerRunner::run_pending( FulfilmentJobs::ACTION );

		$this->assertSame( LineState::NEEDS_ATTENTION, $this->line()->state() );
		$this->assertSame( 'teapot', $this->line()->reason() );
	}

	public function test_an_approval_refused_as_not_committed_goes_on_once_the_design_reads_as_approved(): void {
		$this->recorder
			->json( 409, array( 'error' => 'not_committed' ) )
			->json(
				200,
				DesignFixture::design(
					array(
						'id'       => self::DESIGN,
						'status'   => 'approved',
						'revision' => self::REVISION,
					)
				)
			)
			->json( 200, FulfilmentAnswers::orders( array() ) )
			->json( 200, FulfilmentAnswers::design_approved( self::DESIGN, self::REVISION ) )
			->json( 201, FulfilmentAnswers::order( 'o_1', array( 'p_front' ), $this->external_ref() ) );

		do_action( 'woocommerce_payment_complete', $this->order->get_id() );
		ActionSchedulerRunner::run_pending( FulfilmentJobs::ACTION );

		$this->assertSame( LineState::ORDERED, $this->line()->state() );
	}

	public function test_an_approval_refused_as_not_committed_at_another_revision_needs_attention(): void {
		$this->recorder
			->json( 409, array( 'error' => 'not_committed' ) )
			->json(
				200,
				DesignFixture::design(
					array(
						'id'       => self::DESIGN,
						'status'   => 'approved',
						'revision' => self::REVISION + 1,
					)
				)
			);

		do_action( 'woocommerce_payment_complete', $this->order->get_id() );
		ActionSchedulerRunner::run_pending( FulfilmentJobs::ACTION );

		$this->assertSame( LineState::NEEDS_ATTENTION, $this->line()->state() );
		$this->assertSame( 'not_committed', $this->line()->reason() );
	}

	public function test_an_order_placed_as_failed_needs_attention(): void {
		$this->recorder
			->json( 200, FulfilmentAnswers::approved() )
			->json( 200, FulfilmentAnswers::orders( array() ) )
			->json( 200, FulfilmentAnswers::design_approved( self::DESIGN, self::REVISION ) )
			->json(
				201,
				FulfilmentAnswers::order(
					'o_failed',
					array( 'p_front' ),
					$this->external_ref(),
					array(
						'status'    => 'failed',
						'lastError' => 'Printful refused the order.',
					)
				)
			);

		do_action( 'woocommerce_payment_complete', $this->order->get_id() );
		ActionSchedulerRunner::run_pending( FulfilmentJobs::ACTION );

		$line = $this->line();
		$this->assertSame( LineState::NEEDS_ATTENTION, $line->state() );
		$this->assertSame( NeedsAttention::ORDER_FAILED, $line->reason() );
		$this->assertSame( 'o_failed', $line->ref( LineState::REF_ORDER_ID ) );
		$this->assertStringContainsString( 'Printful refused the order.', $this->notes() );
		$this->assertCount( 1, SentMail::all() );
	}

	public function test_a_design_deleted_after_checkout_needs_attention_as_deleted(): void {
		$this->recorder->json( 404, array( 'error' => 'not_found' ) );

		do_action( 'woocommerce_payment_complete', $this->order->get_id() );
		ActionSchedulerRunner::run_pending( FulfilmentJobs::ACTION );

		$this->assertSame( LineState::NEEDS_ATTENTION, $this->line()->state() );
		$this->assertSame( NeedsAttention::DESIGN_DELETED, $this->line()->reason() );
		$this->assertStringContainsString( 'was deleted in Pixonaut', $this->notes() );
		$this->assertStringNotContainsString( 'not_found', $this->notes() );
	}

	public function test_a_sandbox_design_that_is_gone_needs_attention_with_a_sandbox_note(): void {
		$this->store->save( self::connection( StoredConnection::SANDBOX ) );
		$this->recorder->json( 404, array( 'error' => 'not_found' ) );

		do_action( 'woocommerce_payment_complete', $this->order->get_id() );
		ActionSchedulerRunner::run_pending( FulfilmentJobs::ACTION );

		$this->assertSame( LineState::NEEDS_ATTENTION, $this->line()->state() );
		$this->assertSame( 'not_found', $this->line()->reason() );
		$this->assertStringContainsString( 'sandbox', $this->notes() );
	}

	public function test_the_retry_schedule_is_filterable(): void {
		add_filter( 'pixonaut_wc_fulfilment_retry_schedule', static fn (): array => array() );
		$this->recorder->json( 500, array( 'error' => 'db_error' ) );

		do_action( 'woocommerce_payment_complete', $this->order->get_id() );
		$this->assertSame( 1, ActionSchedulerRunner::run_pending( FulfilmentJobs::ACTION ) );

		$this->assertSame( LineState::NEEDS_ATTENTION, $this->line()->state() );
		$this->assertSame( NeedsAttention::RETRIES_EXHAUSTED, $this->line()->reason() );
	}

	public function test_a_retry_action_scheduler_will_not_store_needs_attention(): void {
		$this->recorder->json( 500, array( 'error' => 'db_error' ) );

		do_action( 'woocommerce_payment_complete', $this->order->get_id() );
		add_filter( 'pre_as_schedule_single_action', static fn (): int => 0 );
		$this->assertSame( 1, ActionSchedulerRunner::run_pending( FulfilmentJobs::ACTION ) );

		$line = $this->line();
		$this->assertSame( LineState::NEEDS_ATTENTION, $line->state() );
		$this->assertSame( NeedsAttention::RETRY_NOT_SCHEDULED, $line->reason() );
		$this->assertNull( $line->ref( LineState::REF_RETRY ) );
		$this->assertStringContainsString( 'db_error', $this->notes() );
		$this->assertTrue( $this->has_notice() );
		$this->assertSame( array(), ActionSchedulerRunner::pending( FulfilmentJobs::ACTION ) );
	}

	public function test_the_notice_goes_when_the_last_line_leaves_needs_attention(): void {
		$line = $this->line();
		$this->assertTrue( NeedsAttention::flag( $line, $this->order, 'design_erased', 'Erased.' ) );
		$this->assertTrue( $this->has_notice() );

		$this->assertTrue( LineState::of( new WC_Order_Item_Product( $this->item->get_id() ) )->transition( LineState::APPROVED ) );

		$this->assertFalse( $this->has_notice() );
	}

	public function test_every_line_of_an_order_gets_its_own_job(): void {
		$second = new WC_Order_Item_Product();
		$second->set_name( 'Second tee' );
		$this->order->add_item( $second );
		$this->order->save();
		$this->assertTrue( LineState::start( $second, array( 'front' ) )->save() );
		$this->order->save();

		do_action( 'woocommerce_payment_complete', $this->order->get_id() );

		$this->assertCount( 2, ActionSchedulerRunner::pending( FulfilmentJobs::ACTION ) );
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
