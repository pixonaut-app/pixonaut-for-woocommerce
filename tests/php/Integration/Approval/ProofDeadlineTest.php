<?php
/**
 * @package Pixonaut\WooCommerce
 */

declare(strict_types=1);

namespace Pixonaut\WooCommerce\Tests\Integration\Approval;

use Pixonaut\WooCommerce\Api\Hosts;
use Pixonaut\WooCommerce\Approval\ProofDeadline;
use Pixonaut\WooCommerce\Approval\ProofLink;
use Pixonaut\WooCommerce\Approval\ProofOutcome;
use Pixonaut\WooCommerce\Approval\ProofTimers;
use Pixonaut\WooCommerce\ApprovalMode;
use Pixonaut\WooCommerce\Connection\Cipher;
use Pixonaut\WooCommerce\Connection\ConnectionStore;
use Pixonaut\WooCommerce\Connection\StoredConnection;
use Pixonaut\WooCommerce\Emails\DesignApprovedEmail;
use Pixonaut\WooCommerce\Fulfilment\FulfilmentJobs;
use Pixonaut\WooCommerce\Fulfilment\LineCancellation;
use Pixonaut\WooCommerce\Fulfilment\LineState;
use Pixonaut\WooCommerce\Fulfilment\NeedsAttention;
use Pixonaut\WooCommerce\Plugin;
use Pixonaut\WooCommerce\Refs;
use Pixonaut\WooCommerce\Settings\Settings;
use Pixonaut\WooCommerce\Tests\Support\ActionSchedulerRunner;
use Pixonaut\WooCommerce\Tests\Support\DesignFixture;
use Pixonaut\WooCommerce\Tests\Support\FulfilmentAnswers;
use Pixonaut\WooCommerce\Tests\Support\HttpRecorder;
use Pixonaut\WooCommerce\Tests\Support\OpenApiAssert;
use Pixonaut\WooCommerce\Vendor\Pixonaut\Sdk\Webhooks\Signature;
use Pixonaut\WooCommerce\Webhooks\Processor;
use ReflectionProperty;
use WC_Emails;
use WC_Order;
use WC_Order_Item_Product;
use WC_Product_Simple;
use WP_REST_Request;
use WP_REST_Response;
use WP_UnitTestCase;

/**
 * A proof-by-email line the customer leaves unanswered, through the
 * plugin's own wiring: the reminder and deadline jobs scheduled when the
 * line starts waiting, what the deadline does with each setting, and the
 * customer's rejection on the proof page. Pixonaut is answered from a script
 * at its real address, which the recorder takes before any request leaves.
 */
final class ProofDeadlineTest extends WP_UnitTestCase {

	private const SITE = '5c3a1f6e-8b2d-4e7a-9c1b-000000000001';

	private const SECRET = 'whsec_0123456789abcdef0123456789abcdef';

	private const WEBHOOK_ROUTE = '/pixonaut/v1/webhook';

	private const DESIGN = DesignFixture::ID;

	private const REVISION = 1;

	private const PRICE = 25;

	private const CUSTOMER_EMAIL = 'ada@example.org';

	private const PROOF_URL = 'https://app.pixonaut.example/h/proof/link_proof';

	private const DAY = 86400;

	/**
	 * Words of the emails and notes the tests look for.
	 */
	private const REMINDER_INTRO = 'We are still waiting for your approval';

	private const DEADLINE_SENTENCE = 'If we have not heard from you by';

	private const EXPIRED_INTRO = 'You did not approve your design by the deadline';

	private const REFUNDED = 'Its price is being refunded to you.';

	private const DEADLINE_PASSED = 'did not answer the proof of';

	private HttpRecorder $recorder;

	private WC_Order $order;

	private WC_Order_Item_Product $item;

	private int $sequence = 0;

	public function set_up(): void {
		parent::set_up();
		( new ConnectionStore( Cipher::for_site() ) )->save( self::connection() );
		$this->recorder = ( new HttpRecorder( Hosts::server_api() ) )->start();
		reset_phpmailer_instance();
		// Emails hook their triggers when WooCommerce's mailer is made; each test makes its own.
		( new ReflectionProperty( WC_Emails::class, 'instance' ) )->setValue( null, null );

		$this->order = $this->paid_order();
		$item        = current( $this->order->get_items() );
		$this->assertInstanceOf( WC_Order_Item_Product::class, $item );
		$this->item = $item;
	}

	public function tear_down(): void {
		$this->recorder->stop();
		( new ConnectionStore( Cipher::for_site() ) )->clear();
		delete_option( Settings::OPTION );
		reset_phpmailer_instance();
		parent::tear_down();
	}

	public function test_a_line_waiting_for_the_customer_gets_its_deadline_and_reminders_scheduled(): void {
		$this->pay();

		$line  = $this->line();
		$start = $line->changed_at();
		$at    = $start + ProofLink::DEFAULT_DEADLINE_DAYS * self::DAY;
		$this->assertSame( $at, ProofDeadline::deadline_of( $line ) );
		$this->assertSame( array( $at ), ActionSchedulerRunner::due_at( ProofDeadline::ACTION ) );
		$this->assertSame(
			array( $start + ProofOutcome::DEFAULT_REMINDER_AFTER_DAYS * self::DAY, $start + ( ProofOutcome::DEFAULT_REMINDER_AFTER_DAYS + ProofOutcome::DEFAULT_REMINDER_EVERY_DAYS ) * self::DAY ),
			ActionSchedulerRunner::due_at( ProofTimers::REMINDER_ACTION )
		);
	}

	public function test_reminders_send_the_proof_again_and_the_last_names_the_deadline_and_what_follows(): void {
		$this->pay();

		$this->assertSame( ProofOutcome::DEFAULT_REMINDERS, ActionSchedulerRunner::run_once( ProofTimers::REMINDER_ACTION ) );

		$reminders = $this->mails( self::REMINDER_INTRO );
		$this->assertCount( ProofOutcome::DEFAULT_REMINDERS, $reminders );
		$this->assertSame( self::CUSTOMER_EMAIL, $reminders[0]['to'][0][0] );
		$this->assertStringContainsString( self::PROOF_URL, (string) $reminders[0]['body'] );
		$this->assertStringNotContainsString( self::DEADLINE_SENTENCE, (string) $reminders[0]['body'] );
		$this->assertStringContainsString( self::DEADLINE_SENTENCE, (string) $reminders[1]['body'] );
		$this->assertStringContainsString( 'will not print your design', (string) $reminders[1]['body'], 'denied and left to the store, by default' );
	}

	public function test_the_last_reminder_says_the_design_is_printed_when_the_deadline_approves(): void {
		$this->settings( Settings::PROOF_NO_ANSWER, ProofOutcome::APPROVE );
		$this->settings( Settings::PROOF_REMINDERS, 1 );
		$this->pay();

		ActionSchedulerRunner::run_once( ProofTimers::REMINDER_ACTION );

		$reminders = $this->mails( self::REMINDER_INTRO );
		$this->assertCount( 1, $reminders );
		$this->assertStringContainsString( 'we will print your design as the proof shows it', (string) $reminders[0]['body'] );
	}

	public function test_reminders_and_the_deadline_stop_once_the_customer_approves(): void {
		$this->pay();

		$this->deliver( $this->event( 'design.approved' ) );
		$this->run_webhook_jobs();

		$this->assertSame( LineState::APPROVED, $this->line()->state() );
		$this->assertSame( array(), ActionSchedulerRunner::pending( ProofTimers::REMINDER_ACTION ) );
		$this->assertSame( array(), ActionSchedulerRunner::pending( ProofDeadline::ACTION ) );
		// A reminder already running when the customer approved sends nothing.
		do_action( ProofTimers::REMINDER_ACTION, $this->item->get_id(), 1 );
		$this->assertSame( array(), $this->mails( self::REMINDER_INTRO ) );
	}

	public function test_the_deadline_approves_with_consent_deadline_and_the_line_goes_on(): void {
		$this->settings( Settings::PROOF_NO_ANSWER, ProofOutcome::APPROVE );
		update_option( 'woocommerce_' . DesignApprovedEmail::ID . '_settings', array( 'enabled' => 'yes' ) );
		$this->pay();
		$this->recorder->json( 200, array( 'status' => 'approved' ) );

		$this->run_deadline();

		$line = $this->line();
		$this->assertSame( LineState::APPROVED, $line->state() );
		$this->assertSame( 'POST /v1/designs/' . self::DESIGN . '/approve', $this->sent()[2] );
		$approval = json_decode( (string) $this->recorder->requests[2]['body'], true );
		$this->assertIsArray( $approval );
		$this->assertSame( 'deadline', $approval['consent']['kind'] );
		$this->assertSame( $this->external_ref(), $approval['consent']['ref'] );
		$this->assertSame( self::REVISION, $approval['expectedRevision'] );
		$this->assertSame( 'deadline', $line->ref( LineState::REF_CONSENT )['kind'] );
		$this->assertTrue( FulfilmentJobs::is_scheduled( $this->item->get_id() ) );
		$this->assertStringContainsString( 'its design was approved as shown', $this->notes() );
		$this->assertCount( 1, $this->mails( self::DEADLINE_PASSED ) );
		$this->assertStringContainsString( 'approved as the proof shows it', (string) $this->mails( self::DEADLINE_PASSED )[0]['body'] );
		$this->assertSame( array(), $this->mails( self::EXPIRED_INTRO ) );
		$this->assertSame( array(), $this->mails( 'Thank you for approving your design' ), 'the customer approved nothing' );
	}

	public function test_a_customer_who_rejected_just_before_an_approving_deadline_is_followed(): void {
		$this->settings( Settings::PROOF_NO_ANSWER, ProofOutcome::APPROVE );
		$this->pay();
		$this->recorder
			->json( 409, array( 'error' => 'not_committed' ) )
			->json( 200, self::design( 'rejected' ) );

		$this->run_deadline();

		$line = $this->line();
		$this->assertSame( LineState::NEEDS_ATTENTION, $line->state() );
		$this->assertSame( NeedsAttention::PROOF_REJECTED, $line->reason() );
		$this->assertStringContainsString( 'the customer rejected the proof', $this->notes() );
	}

	public function test_a_denying_deadline_rejects_the_design_and_marks_the_line_by_default(): void {
		$this->pay();
		$this->recorder->json( 200, array( 'ok' => true ) );

		$this->run_deadline();

		$line = $this->line();
		$this->assertSame( 'POST /v1/designs/' . self::DESIGN . '/reject', $this->sent()[2] );
		$this->assertSame( LineState::NEEDS_ATTENTION, $line->state() );
		$this->assertSame( NeedsAttention::PROOF_EXPIRED, $line->reason() );
		$this->assertStringContainsString( 'did not approve the design of "Personalised tee" by the deadline', $this->notes() );
		$this->assertCount( 1, $this->mails( self::DEADLINE_PASSED ) );
		$this->assertSame( array(), $this->mails( self::EXPIRED_INTRO ), 'the store decides what to tell the customer' );
	}

	public function test_a_denying_deadline_set_to_cancel_cancels_the_line_and_tells_the_customer(): void {
		$this->settings( Settings::PROOF_ON_DENY, ProofOutcome::CANCEL );
		$this->pay();
		$this->recorder
			->json( 200, array( 'ok' => true ) )
			// The cancellation rejects the design again, which Pixonaut answers the same.
			->json( 200, array( 'ok' => true ) );

		$this->run_deadline();
		ActionSchedulerRunner::run_pending( LineCancellation::ACTION );

		$this->assertSame( LineState::CANCELLED, $this->line()->state() );
		$this->assertSame( 0.0, (float) $this->order->get_total_refunded() );
		$expired = $this->mails( self::EXPIRED_INTRO );
		$this->assertCount( 1, $expired );
		$this->assertSame( self::CUSTOMER_EMAIL, $expired[0]['to'][0][0] );
		$this->assertStringNotContainsString( self::REFUNDED, (string) $expired[0]['body'] );
		$this->assertStringContainsString( 'cancelled', (string) $this->mails( self::DEADLINE_PASSED )[0]['body'] );
	}

	public function test_a_denying_deadline_set_to_refund_refunds_and_cancels_the_line(): void {
		$this->settings( Settings::PROOF_ON_DENY, ProofOutcome::REFUND_AND_CANCEL );
		$this->pay();
		$this->recorder
			->json( 200, array( 'ok' => true ) )
			->json( 200, array( 'ok' => true ) );

		$this->run_deadline();
		ActionSchedulerRunner::run_pending( LineCancellation::ACTION );

		$order = wc_get_order( $this->order->get_id() );
		$this->assertInstanceOf( WC_Order::class, $order );
		$this->assertSame( LineState::CANCELLED, $this->line()->state() );
		$this->assertSame( (float) self::PRICE, (float) $order->get_total_refunded() );
		$this->assertSame( -1, $order->get_qty_refunded_for_item( $this->item->get_id() ) );
		$this->assertStringContainsString( 'the payment method cannot return money by itself', $this->notes() );
		$expired = $this->mails( self::EXPIRED_INTRO );
		$this->assertCount( 1, $expired );
		$this->assertStringContainsString( self::REFUNDED, (string) $expired[0]['body'] );
	}

	public function test_a_customer_who_approved_just_before_a_denying_deadline_is_followed(): void {
		$this->pay();
		$this->recorder
			->json( 409, array( 'error' => 'not_committed' ) )
			->json( 200, self::design( 'approved' ) );

		$this->run_deadline();

		$this->assertSame( LineState::APPROVED, $this->line()->state() );
		$this->assertTrue( FulfilmentJobs::is_scheduled( $this->item->get_id() ) );
		$this->assertStringContainsString( 'just before its deadline', $this->notes() );
	}

	public function test_a_design_neither_approved_nor_rejected_at_a_denying_deadline_needs_attention(): void {
		$this->settings( Settings::PROOF_ON_DENY, ProofOutcome::REFUND_AND_CANCEL );
		$this->pay();
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

		$this->run_deadline();

		$line = $this->line();
		$this->assertSame( LineState::NEEDS_ATTENTION, $line->state() );
		$this->assertSame( NeedsAttention::DESIGN_CHANGED, $line->reason() );
		$this->assertSame( 0.0, (float) $this->order->get_total_refunded(), 'nothing was rejected, so nothing is refunded' );
		$this->assertStringContainsString( 'could not decide its proof', (string) $this->mails( self::DEADLINE_PASSED )[0]['body'] );
		$this->assertSame( array(), $this->mails( self::EXPIRED_INTRO ) );
	}

	public function test_a_decision_another_write_kept_from_being_saved_is_tried_again(): void {
		$this->settings( Settings::PROOF_NO_ANSWER, ProofOutcome::APPROVE );
		$this->pay();
		$this->recorder->json( 200, array( 'status' => 'approved' ) );
		// Another request writes the line while the design is being approved.
		$write = function ( $preempt ) {
			$line = $this->line();
			$line->set_ref( LineState::REF_RETRY, null );
			$line->set_side_ref( 'front', LineState::SIDE_SEEN_REVISION, self::REVISION );
			$this->assertTrue( $line->save() );
			return $preempt;
		};
		add_filter( 'pre_http_request', $write, 1 );
		$this->run_deadline();
		remove_filter( 'pre_http_request', $write, 1 );

		$this->assertSame( LineState::AWAITING_CUSTOMER, $this->line()->state() );
		$this->assertCount( 1, ActionSchedulerRunner::pending( ProofDeadline::ACTION ) );

		// Approving it again at the same revision answers the same.
		$this->recorder->json( 200, array( 'status' => 'approved' ) );
		$this->run_deadline();

		$this->assertSame( LineState::APPROVED, $this->line()->state() );
	}

	public function test_a_line_is_denied_once_however_many_handlers_deny_it(): void {
		$this->settings( Settings::PROOF_ON_DENY, ProofOutcome::REFUND_AND_CANCEL );
		$this->pay();
		$denial = Plugin::boot( dirname( __DIR__, 4 ) . '/pixonaut-for-woocommerce.php' )->proof_denial();
		$stale  = $this->line();

		$this->assertSame( ProofOutcome::REFUND_AND_CANCEL, $denial->deny( $this->line(), $this->order, 'customer' ) );
		$this->assertNull( $denial->deny( $stale, $this->order, 'customer' ) );
		$this->assertNull( $denial->deny( $this->line(), $this->order, 'deadline' ) );

		$order = wc_get_order( $this->order->get_id() );
		$this->assertInstanceOf( WC_Order::class, $order );
		$this->assertCount( 1, $order->get_refunds() );
	}

	public function test_a_reminder_run_after_the_deadline_sends_nothing(): void {
		$this->pay();
		$line           = $this->line();
		$deadline       = (array) $line->ref( LineState::REF_DEADLINE );
		$deadline['at'] = time() - 1;
		$line->set_ref( LineState::REF_DEADLINE, $deadline );
		$this->assertTrue( $line->save() );

		ActionSchedulerRunner::run_once( ProofTimers::REMINDER_ACTION );

		$this->assertSame( array(), $this->mails( self::REMINDER_INTRO ) );
	}

	public function test_a_deadline_that_could_not_be_scheduled_needs_attention(): void {
		$refuse = static fn ( $pre, $timestamp, $hook ) => ProofDeadline::ACTION === $hook ? 0 : $pre;
		add_filter( 'pre_as_schedule_single_action', $refuse, 10, 3 );
		$this->pay_until_waiting();
		remove_filter( 'pre_as_schedule_single_action', $refuse, 10 );

		$line = $this->line();
		$this->assertSame( LineState::NEEDS_ATTENTION, $line->state() );
		$this->assertSame( NeedsAttention::RETRY_NOT_SCHEDULED, $line->reason() );
		$this->assertStringContainsString( 'could not be scheduled', $this->notes() );
	}

	public function test_a_failure_that_may_pass_tries_the_deadline_again_later(): void {
		$this->pay();
		$this->recorder->fail();

		$this->run_deadline();

		$this->assertSame( LineState::AWAITING_CUSTOMER, $this->line()->state() );
		$this->assertCount( 1, ActionSchedulerRunner::pending( ProofDeadline::ACTION ) );
		$this->assertSame( 1, $this->line()->ref( LineState::REF_DEADLINE )['failures'] );
	}

	public function test_the_customers_rejection_on_the_proof_page_applies_on_deny(): void {
		$this->settings( Settings::PROOF_ON_DENY, ProofOutcome::CANCEL );
		$this->pay();

		$this->deliver( $this->event( 'design.rejected' ) );
		$this->recorder->json( 200, array( 'ok' => true ) );
		$this->run_webhook_jobs();
		ActionSchedulerRunner::run_pending( LineCancellation::ACTION );

		$this->assertSame( LineState::CANCELLED, $this->line()->state() );
		$this->assertStringContainsString( 'the customer rejected the proof of "Personalised tee", so it is cancelled', $this->notes() );
		$this->assertSame( array(), ActionSchedulerRunner::pending( ProofDeadline::ACTION ) );
		$this->assertSame( array(), $this->mails( self::EXPIRED_INTRO ), 'the deadline did not pass' );
	}

	public function test_a_rejection_of_a_line_no_longer_waiting_changes_nothing(): void {
		$this->pay();
		$this->deliver( $this->event( 'design.approved' ) );
		$this->run_webhook_jobs();

		$this->deliver( $this->event( 'design.rejected' ) );
		$this->run_webhook_jobs();

		$this->assertSame( LineState::APPROVED, $this->line()->state() );
	}

	/**
	 * Reports the payment and runs the fulfilment job, which sends the proof.
	 */
	private function pay(): void {
		$this->pay_until_waiting();
		$this->assertSame( LineState::AWAITING_CUSTOMER, $this->line()->state() );
	}

	private function pay_until_waiting(): void {
		$this->recorder
			->json( 200, self::design() )
			->json( 201, self::proof_page() );
		do_action( 'woocommerce_payment_complete', $this->order->get_id() );
		ActionSchedulerRunner::run_pending( FulfilmentJobs::ACTION );
		$this->assertSame( array(), ActionSchedulerRunner::failed( FulfilmentJobs::ACTION ) );
	}

	/**
	 * Runs the line's deadline job as if the deadline had come.
	 */
	private function run_deadline(): void {
		$this->assertSame( 1, ActionSchedulerRunner::run_once( ProofDeadline::ACTION ) );
		$this->assertSame( array(), ActionSchedulerRunner::failed( ProofDeadline::ACTION ) );
	}

	private function settings( string $key, mixed $value ): void {
		( new Settings() )->set( $key, $value );
	}

	/**
	 * The emails sent so far whose body holds $text.
	 *
	 * @param string $text Text to look for.
	 * @return list<array<string, mixed>>
	 */
	private function mails( string $text ): array {
		$mailer = tests_retrieve_phpmailer_instance();
		$this->assertNotFalse( $mailer );
		return array_values( array_filter( $mailer->mock_sent, static fn ( array $mail ): bool => str_contains( (string) $mail['body'], $text ) ) );
	}

	/**
	 * A proof page's decision about the line's design, as Pixonaut delivers it.
	 *
	 * @param string $type `design.approved` or `design.rejected`.
	 * @return array<string, mixed>
	 */
	private function event( string $type ): array {
		++$this->sequence;
		$created = time() * 1000 + $this->sequence;
		$payload = array(
			'designId' => self::DESIGN,
			'revision' => self::REVISION,
		);
		if ( 'design.approved' === $type ) {
			$payload += array(
				'approvedVia' => 'explicit',
				'consent'     => array(
					'kind' => 'proof_page',
					'at'   => $created,
					'ref'  => null,
				),
			);
		}
		return array(
			'id'        => "{$created}-5d7e1f2a-3b4c-4d5e-8f60-7182930000" . str_pad( (string) $this->sequence, 2, '0', STR_PAD_LEFT ),
			'type'      => $type,
			'payload'   => $payload,
			'createdAt' => $created,
		);
	}

	/**
	 * Delivers an event to the plugin's receiver, signed as Pixonaut signs it.
	 *
	 * @param array<string, mixed> $envelope The event.
	 */
	private function deliver( array $envelope ): void {
		$body = (string) wp_json_encode( $envelope );
		OpenApiAssert::webhook( (string) $envelope['type'], $body );
		$timestamp = (string) time();
		$request   = new WP_REST_Request( 'POST', self::WEBHOOK_ROUTE );
		$request->set_header( 'content-type', 'application/json' );
		$request->set_header( Signature::TIMESTAMP_HEADER, $timestamp );
		$request->set_header( Signature::SIGNATURE_HEADER, 'v1=' . hash_hmac( 'sha256', "{$timestamp}.{$body}", self::SECRET ) );
		$request->set_body( $body );
		$response = rest_do_request( $request );
		$this->assertInstanceOf( WP_REST_Response::class, $response );
		$this->assertLessThan( 300, $response->get_status() );
	}

	private function run_webhook_jobs(): void {
		ActionSchedulerRunner::run_pending( Processor::PROCESS_HOOK );
		$this->assertSame( array(), ActionSchedulerRunner::failed( Processor::PROCESS_HOOK ) );
	}

	/**
	 * A placed order with one Pixonaut line whose mode is proof by email.
	 */
	private function paid_order(): WC_Order {
		$product = new WC_Product_Simple();
		$product->set_name( 'Personalised tee' );
		$product->set_regular_price( (string) self::PRICE );
		$product->save();
		$order = wc_create_order();
		$this->assertInstanceOf( WC_Order::class, $order );
		$order->add_product( $product );
		$order->set_billing_email( self::CUSTOMER_EMAIL );
		$order->calculate_totals();
		$order->save();

		$item = current( $order->get_items() );
		$this->assertInstanceOf( WC_Order_Item_Product::class, $item );
		$line = LineState::start( $item, array( 'front' ) );
		$line->set_ref( LineState::REF_TENANT_ID, FulfilmentAnswers::TENANT_ID );
		$line->set_ref( LineState::REF_APPROVAL_MODE, ApprovalMode::PROOF_EMAIL );
		$line->set_side_ref( 'front', LineState::SIDE_DESIGN_ID, self::DESIGN );
		$line->set_side_ref( 'front', LineState::SIDE_REVISION, self::REVISION );
		$line->set_ref( LineState::REF_EXTERNAL_REF, Refs::order_item_ref( self::SITE, $order->get_id(), $item->get_id() ) );
		$line->set_ref( LineState::REF_EXTERNAL_REF_MOVED, true );
		$this->assertTrue( $line->save() );
		return $order;
	}

	private function external_ref(): string {
		return Refs::order_item_ref( self::SITE, $this->order->get_id(), $this->item->get_id() );
	}

	private function line(): LineState {
		$item = \WC_Order_Factory::get_order_item( $this->item->get_id() );
		$this->assertInstanceOf( WC_Order_Item_Product::class, $item );
		return LineState::of( $item );
	}

	/**
	 * @return list<string> Each request as `METHOD /path?query`.
	 */
	private function sent(): array {
		return array_map(
			static fn ( array $request ): string => $request['method'] . ' ' . substr( $request['url'], strlen( Hosts::server_api() ) ),
			$this->recorder->requests
		);
	}

	/**
	 * The order's notes, as one string.
	 */
	private function notes(): string {
		$notes = wc_get_order_notes( array( 'order_id' => $this->order->get_id() ) );
		return implode( "\n", array_map( static fn ( $note ): string => (string) $note->content, $notes ) );
	}

	/**
	 * @param string $status The design's status.
	 * @return array<string, mixed>
	 */
	private static function design( string $status = 'committed' ): array {
		return DesignFixture::design(
			array(
				'id'       => self::DESIGN,
				'status'   => $status,
				'revision' => self::REVISION,
			)
		);
	}

	/**
	 * @return array<string, mixed>
	 */
	private static function proof_page(): array {
		return array(
			'id'             => '0b1c2d3e-4f50-4a6b-8c7d-000000000010',
			'kind'           => 'proof-page',
			'subjectId'      => self::DESIGN,
			'mayDecide'      => true,
			'returnUrl'      => null,
			'token'          => 'link_proof',
			'url'            => self::PROOF_URL,
			'createdAt'      => 1_800_000_000_000,
			'expiresAt'      => 1_801_814_400_000,
			'name'           => null,
			'brandId'        => null,
			'designLimit'    => null,
			'printAreaFrame' => false,
			'placementFrame' => false,
			'locale'         => null,
		);
	}

	/**
	 * The store's connection, made from this site's address so it is not paused as a copied site.
	 */
	private static function connection(): StoredConnection {
		return new StoredConnection(
			self::SITE,
			untrailingslashit( home_url() ),
			time(),
			FulfilmentAnswers::TENANT_ID,
			'Acme',
			StoredConnection::LIVE,
			'sc_0b1c2d3e-4f50-4a6b-8c7d-000000000004',
			'ik_live_0123456789abcdef01234567',
			'0b1c2d3e-4f50-4a6b-8c7d-000000000005',
			'WooCommerce · Acme Shop',
			'pk_live_89abcdef0123456789abcdef',
			'0b1c2d3e-4f50-4a6b-8c7d-000000000006',
			array( untrailingslashit( home_url() ) ),
			'0b1c2d3e-4f50-4a6b-8c7d-000000000007',
			home_url( '/wp-json/pixonaut/v1/webhook' ),
			self::SECRET
		);
	}
}
