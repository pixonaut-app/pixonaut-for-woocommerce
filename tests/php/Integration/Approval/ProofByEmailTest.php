<?php
/**
 * @package Pixonaut\WooCommerce
 */

declare(strict_types=1);

namespace Pixonaut\WooCommerce\Tests\Integration\Approval;

use Pixonaut\WooCommerce\Api\Hosts;
use Pixonaut\WooCommerce\Approval\ProofLink;
use Pixonaut\WooCommerce\ApprovalMode;
use Pixonaut\WooCommerce\Connection\Cipher;
use Pixonaut\WooCommerce\Connection\ConnectionStore;
use Pixonaut\WooCommerce\Connection\StoredConnection;
use Pixonaut\WooCommerce\DesignerLanguage;
use Pixonaut\WooCommerce\Emails\DesignApprovedEmail;
use Pixonaut\WooCommerce\Emails\ProofReadyEmail;
use Pixonaut\WooCommerce\Fulfilment\FulfilmentJobs;
use Pixonaut\WooCommerce\Fulfilment\LineDisplay;
use Pixonaut\WooCommerce\Fulfilment\LineState;
use Pixonaut\WooCommerce\Plugin;
use Pixonaut\WooCommerce\Refs;
use Pixonaut\WooCommerce\Settings\Settings;
use Pixonaut\WooCommerce\Tests\Support\ActionSchedulerRunner;
use Pixonaut\WooCommerce\Tests\Support\AdminItemMeta;
use Pixonaut\WooCommerce\Tests\Support\DesignFixture;
use Pixonaut\WooCommerce\Tests\Support\FulfilmentAnswers;
use Pixonaut\WooCommerce\Tests\Support\HttpRecorder;
use Pixonaut\WooCommerce\Tests\Support\OpenApiAssert;
use Pixonaut\WooCommerce\Vendor\Pixonaut\Sdk\Webhooks\Signature;
use Pixonaut\WooCommerce\Webhooks\Processor;
use ReflectionProperty;
use WC_Email;
use WC_Emails;
use WC_Order;
use WC_Order_Item_Product;
use WC_Product_Simple;
use WP_REST_Request;
use WP_REST_Response;
use WP_UnitTestCase;

/**
 * A paid line whose approval mode is proof by email, through the plugin's
 * own wiring: the fulfilment job, the "proof ready" email, the webhook that
 * resumes the line, and the route the proof page returns the customer to.
 * Pixonaut is answered from a script at its real address, which the
 * recorder takes before any request leaves.
 */
final class ProofByEmailTest extends WP_UnitTestCase {

	private const SITE = '5c3a1f6e-8b2d-4e7a-9c1b-000000000001';

	private const SECRET = 'whsec_0123456789abcdef0123456789abcdef';

	private const WEBHOOK_ROUTE = '/pixonaut/v1/webhook';

	private const DESIGN = DesignFixture::ID;

	private const BACK_DESIGN = '6f1c8a2b-3d4e-4f5a-8b6c-7d8e9f0a1b2d';

	private const REVISION = 1;

	private const CUSTOMER_EMAIL = 'ada@example.org';

	private const PROOF_URL = 'https://app.pixonaut.example/h/proof/link_proof';

	/**
	 * Opening words of the "proof ready" and "design approved" emails.
	 */
	private const PROOF_INTRO = 'please check the proof of your design';

	private const APPROVED_INTRO = 'Thank you for approving your design';

	private HttpRecorder $recorder;

	private WC_Order $order;

	private WC_Order_Item_Product $item;

	private int $sequence = 0;

	public function set_up(): void {
		parent::set_up();
		( new ConnectionStore( Cipher::for_site() ) )->save( self::connection() );
		$this->recorder = ( new HttpRecorder( Hosts::server_api() ) )->start();
		reset_phpmailer_instance();
		// Emails hook their triggers when WooCommerce's mailer is made; each test restores the hooks it found, so each makes its own.
		( new ReflectionProperty( WC_Emails::class, 'instance' ) )->setValue( null, null );

		$this->use_order( $this->paid_order( array( 'front' => self::DESIGN ) ) );
	}

	private function use_order( WC_Order $order ): void {
		$this->order = $order;
		$item        = current( $order->get_items() );
		$this->assertInstanceOf( WC_Order_Item_Product::class, $item );
		$this->item = $item;
	}

	public function tear_down(): void {
		$this->recorder->stop();
		( new ConnectionStore( Cipher::for_site() ) )->clear();
		reset_phpmailer_instance();
		parent::tear_down();
	}

	public function test_payment_sends_the_proof_ready_email_and_the_line_waits(): void {
		$this->recorder
			->json( 200, self::design() )
			->json( 201, self::proof_page() );

		$this->pay();

		$line = $this->line();
		$this->assertSame( LineState::AWAITING_CUSTOMER, $line->state() );
		$this->assertSame( self::PROOF_URL, $line->side_ref( 'front', LineState::SIDE_PROOF_URL ) );
		$link = json_decode( (string) $this->recorder->requests[1]['body'], true );
		$this->assertIsArray( $link );
		$this->assertSame( ProofLink::return_url( $this->item->get_id() ), $link['returnUrl'] );
		$this->assertSame( ProofLink::DEFAULT_DEADLINE_DAYS + ProofLink::MARGIN_DAYS, $link['expiresInDays'] );
		$this->assertSame( 'en', $link['locale'] );
		$this->assertStringContainsString( 'awaiting design approval', $this->notes() );

		$proofs = $this->mails( self::PROOF_URL );
		$this->assertCount( 1, $proofs );
		$this->assertSame( self::CUSTOMER_EMAIL, $proofs[0]['to'][0][0] );
		$this->assertSame( array(), $this->mails( self::APPROVED_INTRO ), 'the "design approved" email is off' );
	}

	public function test_a_design_answered_without_a_field_the_store_does_not_read_still_gets_its_proof_sent(): void {
		$this->recorder
			->off_spec( 200, DesignFixture::as_answered_without_unread_fields( array( 'id' => self::DESIGN ) ) )
			->json( 201, self::proof_page() );

		$this->pay();

		$this->assertSame( LineState::AWAITING_CUSTOMER, $this->line()->state() );
		$this->assertSame( self::PROOF_URL, $this->line()->side_ref( 'front', LineState::SIDE_PROOF_URL ) );
		$this->assertCount( 1, $this->mails( self::PROOF_URL ) );
	}

	public function test_the_proof_page_is_in_the_language_the_customer_bought_in(): void {
		$line = $this->line();
		$line->set_ref( LineState::REF_LOCALE, 'de_CH' );
		$this->assertTrue( $line->save() );
		$this->recorder
			->json( 200, self::design() )
			->json( 201, self::proof_page() );

		$this->pay();

		$link = json_decode( (string) $this->recorder->requests[1]['body'], true );
		$this->assertIsArray( $link );
		$this->assertSame( 'de', $link['locale'] );
	}

	public function test_the_workspace_default_sends_the_proof_page_without_a_language(): void {
		Plugin::boot( dirname( __DIR__, 4 ) . '/pixonaut-for-woocommerce.php' )->settings()->set( Settings::DESIGNER_LANGUAGE, DesignerLanguage::WORKSPACE_DEFAULT );
		$this->recorder
			->json( 200, self::design() )
			->json( 201, self::proof_page() );

		$this->pay();

		$link = json_decode( (string) $this->recorder->requests[1]['body'], true );
		$this->assertIsArray( $link );
		$this->assertArrayNotHasKey( 'locale', $link );
	}

	public function test_a_filter_adds_to_the_proof_ready_email(): void {
		$this->recorder
			->json( 200, self::design() )
			->json( 201, self::proof_page() );
		$seen = array();
		add_filter(
			'pixonaut_wc_emails_template_args',
			static function ( array $args, WC_Email $email ) use ( &$seen ): array {
				$seen[]                     = $email->id;
				$args['additional_content'] = 'Reply to this email with any questions';
				return $args;
			},
			10,
			2
		);

		$this->pay();

		$this->assertContains( ProofReadyEmail::ID, $seen );
		$this->assertCount( 1, $this->mails( 'Reply to this email with any questions' ) );
	}

	public function test_nothing_is_approved_printed_or_ordered_before_the_customer_approves(): void {
		$this->recorder
			->json( 200, self::design() )
			->json( 201, self::proof_page() );

		$this->pay();
		// Paid again, and moved to processing: the line waits, so nothing is scheduled.
		do_action( 'woocommerce_payment_complete', $this->order->get_id() );
		$this->order->update_status( 'processing' );

		$this->assertSame( 0, ActionSchedulerRunner::run_pending( FulfilmentJobs::ACTION ) );
		$this->assertSame( array( 'GET /v1/designs/' . self::DESIGN, 'POST /v1/share-links' ), $this->sent() );
		$this->assertCount( 1, $this->mails( self::PROOF_URL ), 'one proof, however often the payment is reported' );
	}

	public function test_a_design_approved_before_payment_sends_no_proof_and_goes_on(): void {
		$this->recorder
			->json( 200, self::design( 'approved' ) )
			->json( 200, FulfilmentAnswers::orders( array() ) )
			->json( 200, FulfilmentAnswers::design_approved( self::DESIGN, self::REVISION ) )
			->json( 201, FulfilmentAnswers::order( 'o_1', array( 'p_front' ), $this->external_ref() ) );

		$this->pay();

		$this->assertSame( LineState::ORDERED, $this->line()->state() );
		$this->assertSame( array( 'GET /v1/designs/' . self::DESIGN, 'GET /v1/orders?externalRef=' . rawurlencode( $this->external_ref() ), 'GET /v1/designs/' . self::DESIGN, 'POST /v1/orders' ), $this->sent() );
		$this->assertSame( array(), $this->mails( self::PROOF_INTRO ) );
	}

	public function test_a_refused_proof_page_needs_attention_with_the_code(): void {
		$this->recorder
			->json( 200, self::design() )
			->json( 400, array( 'error' => 'return_url_off_store' ) );

		$this->pay();

		$line = $this->line();
		$this->assertSame( LineState::NEEDS_ATTENTION, $line->state() );
		$this->assertSame( 'return_url_off_store', $line->reason() );
		$this->assertStringContainsString( 'return_url_off_store', $this->notes() );
		$this->assertSame( array(), $this->mails( self::PROOF_INTRO ) );
	}

	public function test_the_customers_approval_resumes_the_line_to_its_printshop_order(): void {
		update_option( 'woocommerce_' . DesignApprovedEmail::ID . '_settings', array( 'enabled' => 'yes' ) );
		$this->recorder
			->json( 200, self::design() )
			->json( 201, self::proof_page() );
		$this->pay();

		$this->deliver( $this->approved_event( self::REVISION ) );
		$this->run_webhook_jobs();

		$this->assertSame( LineState::APPROVED, $this->line()->state() );
		$this->assertStringContainsString( 'the customer approved the design', $this->notes() );
		$approved = $this->mails( self::APPROVED_INTRO );
		$this->assertCount( 1, $approved );
		$this->assertSame( self::CUSTOMER_EMAIL, $approved[0]['to'][0][0] );

		$this->recorder
			->json( 200, FulfilmentAnswers::orders( array() ) )
			->json( 200, FulfilmentAnswers::design_approved( self::DESIGN, self::REVISION ) )
			->json( 201, FulfilmentAnswers::order( 'o_1', array( 'p_front' ), $this->external_ref() ) );
		$this->assertSame( 1, ActionSchedulerRunner::run_pending( FulfilmentJobs::ACTION ) );

		$this->assertSame( LineState::ORDERED, $this->line()->state() );
		$this->assertNotContains( 'POST /v1/designs/' . self::DESIGN . '/approve', $this->sent(), 'the customer approved it on the proof page' );
	}

	public function test_an_approval_naming_another_revision_is_checked_against_the_design(): void {
		$this->recorder
			->json( 200, self::design() )
			->json( 201, self::proof_page() );
		$this->pay();

		// Approved at the line's revision after all: the event's revision is not the line's.
		$this->recorder->json( 200, self::design( 'approved' ) );
		$this->deliver( $this->approved_event( self::REVISION + 1 ) );
		$this->run_webhook_jobs();

		$this->assertSame( LineState::APPROVED, $this->line()->state() );
	}

	public function test_an_approved_design_answered_without_a_field_the_store_does_not_read_still_counts(): void {
		$this->recorder
			->json( 200, self::design() )
			->json( 201, self::proof_page() );
		$this->pay();

		$this->recorder->off_spec(
			200,
			DesignFixture::as_answered_without_unread_fields(
				array(
					'id'     => self::DESIGN,
					'status' => 'approved',
				)
			)
		);
		$this->deliver( $this->approved_event( self::REVISION + 1 ) );
		$this->run_webhook_jobs();

		$this->assertSame( LineState::APPROVED, $this->line()->state() );
	}

	public function test_an_approval_of_a_design_changed_since_needs_attention(): void {
		$this->recorder
			->json( 200, self::design() )
			->json( 201, self::proof_page() );
		$this->pay();

		$this->recorder->json( 200, self::design( 'approved', self::REVISION + 1 ) );
		$this->deliver( $this->approved_event( null ) );
		$this->run_webhook_jobs();

		$this->assertSame( LineState::NEEDS_ATTENTION, $this->line()->state() );
		$this->assertSame( array(), ActionSchedulerRunner::pending( FulfilmentJobs::ACTION ) );
	}

	public function test_the_proof_page_returns_the_customer_to_their_order_for_a_product_not_personalised_inline(): void {
		$state = Plugin::boot( dirname( __DIR__, 4 ) . '/pixonaut-for-woocommerce.php' )->proof_state();
		$valid = $state->sign( $this->order->get_id(), $this->item->get_id(), self::DESIGN, self::REVISION );

		$this->assertSame( $this->order->get_checkout_order_received_url(), $this->return_to( $this->item->get_id(), $valid ) );
		$this->assertSame( home_url( '/' ), $this->return_to( $this->item->get_id() + 1, $valid ), 'another line in the address' );
		$this->assertSame( home_url( '/' ), $this->return_to( $this->item->get_id(), $valid . 'x' ), 'a signature not the store\'s' );
		$other = $state->sign( $this->order->get_id(), $this->item->get_id(), 'd_other', self::REVISION );
		$this->assertSame( home_url( '/' ), $this->return_to( $this->item->get_id(), $other ), 'a design not the line\'s' );
	}

	public function test_the_admin_order_screen_shows_a_line_waiting_for_the_customers_approval(): void {
		$this->recorder
			->json( 200, self::design() )
			->json( 201, self::proof_page() );
		$this->pay();

		$item = $this->line()->item();
		$this->assertStringContainsString( '<td>Awaiting your approval</td>', AdminItemMeta::html( $item ) );
		$this->assertArrayNotHasKey( LineDisplay::STATE_ROW, $item->get_formatted_meta_data(), 'the customer is not shown it' );
	}

	public function test_a_side_approved_before_payment_counts_when_the_customer_approves_the_other(): void {
		$this->use_order(
			$this->paid_order(
				array(
					'front' => self::DESIGN,
					'back'  => self::BACK_DESIGN,
				)
			)
		);
		$this->recorder
			->json( 200, self::design( 'approved' ) )
			->json( 200, self::design( 'committed', self::REVISION, self::BACK_DESIGN ) )
			->json( 201, self::proof_page() );
		$this->pay();

		$line = $this->line();
		$this->assertSame( LineState::AWAITING_CUSTOMER, $line->state() );
		$this->assertTrue( $line->side_ref( 'front', LineState::SIDE_APPROVED ) );
		$this->assertNull( $line->side_ref( 'front', LineState::SIDE_PROOF_URL ), 'no proof page for the side approved already' );
		$this->assertSame( self::PROOF_URL, $line->side_ref( 'back', LineState::SIDE_PROOF_URL ) );

		$this->deliver( $this->approved_event( self::REVISION, self::BACK_DESIGN ) );
		$this->run_webhook_jobs();

		$this->assertSame( LineState::APPROVED, $this->line()->state() );
		$this->assertCount( 1, ActionSchedulerRunner::pending( FulfilmentJobs::ACTION ) );
	}

	public function test_an_approval_after_the_order_was_cancelled_prints_nothing(): void {
		$this->recorder
			->json( 200, self::design() )
			->json( 201, self::proof_page() );
		$this->pay();
		$this->order->update_status( 'cancelled' );

		$this->deliver( $this->approved_event( self::REVISION ) );
		$this->run_webhook_jobs();

		$this->assertSame( LineState::AWAITING_CUSTOMER, $this->line()->state() );
		$this->assertSame( array(), ActionSchedulerRunner::pending( FulfilmentJobs::ACTION ) );
		$this->assertStringContainsString( 'after the order was cancelled, refunded or failed', $this->notes() );
	}

	public function test_a_line_the_store_keeps_after_a_cancel_is_sent_once_approved(): void {
		$this->recorder
			->json( 200, self::design() )
			->json( 201, self::proof_page() );
		$this->pay();
		add_filter( 'pixonaut_wc_fulfilment_cancel_line', '__return_false' );
		$this->order->update_status( 'cancelled' );

		$this->deliver( $this->approved_event( self::REVISION ) );
		$this->run_webhook_jobs();

		$this->assertSame( LineState::APPROVED, $this->line()->state() );
		$this->assertCount( 1, ActionSchedulerRunner::pending( FulfilmentJobs::ACTION ) );
	}

	public function test_an_approval_whose_job_was_not_stored_schedules_it_when_handled_again(): void {
		$this->recorder
			->json( 200, self::design() )
			->json( 201, self::proof_page() );
		$this->pay();

		$refuse = static fn ( $pre, string $hook ) => FulfilmentJobs::ACTION === $hook ? 0 : $pre;
		add_filter( 'pre_as_enqueue_async_action', $refuse, 10, 2 );
		$this->deliver( $this->approved_event( self::REVISION ) );
		ActionSchedulerRunner::run_once( Processor::PROCESS_HOOK );
		remove_filter( 'pre_as_enqueue_async_action', $refuse, 10 );

		$this->assertSame( LineState::APPROVED, $this->line()->state() );
		$this->assertSame( array(), ActionSchedulerRunner::pending( FulfilmentJobs::ACTION ) );
		$this->assertCount( 1, ActionSchedulerRunner::failed( Processor::PROCESS_HOOK ) );

		ActionSchedulerRunner::run_once( Processor::PROCESS_HOOK );

		$this->assertCount( 1, ActionSchedulerRunner::pending( FulfilmentJobs::ACTION ) );
	}

	public function test_an_approval_while_the_proof_is_prepared_is_applied_once_the_line_waits(): void {
		// Paid, but the job that sends the proof has not run yet.
		do_action( 'woocommerce_payment_complete', $this->order->get_id() );
		$this->deliver( $this->approved_event( self::REVISION ) );
		ActionSchedulerRunner::run_once( Processor::PROCESS_HOOK );

		$this->assertSame( LineState::COMMITTED, $this->line()->state() );
		$this->assertCount( 1, ActionSchedulerRunner::failed( Processor::PROCESS_HOOK ) );

		$this->recorder
			->json( 200, self::design() )
			->json( 201, self::proof_page() );
		ActionSchedulerRunner::run_pending( FulfilmentJobs::ACTION );
		$this->assertSame( LineState::AWAITING_CUSTOMER, $this->line()->state() );

		ActionSchedulerRunner::run_once( Processor::PROCESS_HOOK );

		$this->assertSame( LineState::APPROVED, $this->line()->state() );
	}

	public function test_a_proof_email_not_sent_leaves_the_links_in_an_order_note(): void {
		add_filter( 'pre_wp_mail', '__return_false' );
		$this->recorder
			->json( 200, self::design() )
			->json( 201, self::proof_page() );
		$this->pay();
		remove_filter( 'pre_wp_mail', '__return_false' );

		$this->assertSame( LineState::AWAITING_CUSTOMER, $this->line()->state() );
		$this->assertStringContainsString( 'Send the customer the proof link(s): ' . self::PROOF_URL, $this->notes() );
	}

	public function test_a_disabled_proof_email_leaves_the_links_in_an_order_note(): void {
		update_option( 'woocommerce_' . ProofReadyEmail::ID . '_settings', array( 'enabled' => 'no' ) );
		$this->recorder
			->json( 200, self::design() )
			->json( 201, self::proof_page() );
		$this->pay();

		$this->assertSame( array(), $this->mails( self::PROOF_INTRO ) );
		$this->assertStringContainsString( 'Send the customer the proof link(s): ' . self::PROOF_URL, $this->notes() );
	}

	/**
	 * Reports the payment and runs the fulfilment job.
	 */
	private function pay(): void {
		do_action( 'woocommerce_payment_complete', $this->order->get_id() );
		ActionSchedulerRunner::run_pending( FulfilmentJobs::ACTION );
		$this->assertSame( array(), ActionSchedulerRunner::failed( FulfilmentJobs::ACTION ) );
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

	private function return_to( int $item_id, string $state ): string {
		$request = new WP_REST_Request( 'GET', '/' . Plugin::REST_NAMESPACE . '/order-items/' . $item_id . '/edit' );
		$request->set_query_params( array( 'state' => $state ) );
		$response = rest_do_request( $request );
		$this->assertSame( 302, $response->get_status() );
		return (string) ( $response->get_headers()['Location'] ?? '' );
	}

	/**
	 * @param int|null $revision  The revision the event names; null for none.
	 * @param string   $design_id The design approved.
	 * @return array<string, mixed>
	 */
	private function approved_event( ?int $revision, string $design_id = self::DESIGN ): array {
		++$this->sequence;
		$created = time() * 1000 + $this->sequence;
		$payload = array(
			'designId'    => $design_id,
			'approvedVia' => 'explicit',
			'consent'     => array(
				'kind' => 'proof_page',
				'at'   => $created,
				'ref'  => null,
			),
		);
		if ( null !== $revision ) {
			$payload['revision'] = $revision;
		}
		return array(
			'id'        => "{$created}-5d7e1f2a-3b4c-4d5e-8f60-7182930000" . str_pad( (string) $this->sequence, 2, '0', STR_PAD_LEFT ),
			'type'      => 'design.approved',
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
	 *
	 * @param array<string, string> $designs The line's design id per side key.
	 */
	private function paid_order( array $designs ): WC_Order {
		$product = new WC_Product_Simple();
		$product->set_name( 'Personalised tee' );
		$product->set_regular_price( '25' );
		$product->save();
		$order = wc_create_order();
		$this->assertInstanceOf( WC_Order::class, $order );
		$order->add_product( $product );
		$order->set_address(
			array(
				'first_name' => 'Ada',
				'last_name'  => 'Lovelace',
				'address_1'  => 'Storgatan 1',
				'city'       => 'Stockholm',
				'postcode'   => '111 22',
				'country'    => 'SE',
			),
			'shipping'
		);
		$order->set_billing_email( self::CUSTOMER_EMAIL );
		$order->save();

		$item = current( $order->get_items() );
		$this->assertInstanceOf( WC_Order_Item_Product::class, $item );
		$line = LineState::start( $item, array_keys( $designs ) );
		$line->set_ref( LineState::REF_TENANT_ID, FulfilmentAnswers::TENANT_ID );
		$line->set_ref( LineState::REF_APPROVAL_MODE, ApprovalMode::PROOF_EMAIL );
		foreach ( $designs as $side => $design_id ) {
			$line->set_side_ref( $side, LineState::SIDE_DESIGN_ID, $design_id );
			$line->set_side_ref( $side, LineState::SIDE_REVISION, self::REVISION );
		}
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
	 * @param string $status    The design's status.
	 * @param int    $revision  Its revision.
	 * @param string $design_id Its id.
	 * @return array<string, mixed>
	 */
	private static function design( string $status = 'committed', int $revision = self::REVISION, string $design_id = self::DESIGN ): array {
		return DesignFixture::design(
			array(
				'id'       => $design_id,
				'status'   => $status,
				'revision' => $revision,
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
