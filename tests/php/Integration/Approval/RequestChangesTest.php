<?php
/**
 * @package Pixonaut\WooCommerce
 */

declare(strict_types=1);

namespace Pixonaut\WooCommerce\Tests\Integration\Approval;

use Pixonaut\WooCommerce\Api\Hosts;
use Pixonaut\WooCommerce\Approval\ChangesRequested;
use Pixonaut\WooCommerce\Approval\OrderEdit;
use Pixonaut\WooCommerce\Approval\OrderEditRoute;
use Pixonaut\WooCommerce\ApprovalMode;
use Pixonaut\WooCommerce\Cart\FieldValue;
use Pixonaut\WooCommerce\Catalogue\ProductConfig;
use Pixonaut\WooCommerce\Emails\ChangesLinkEmail;
use Pixonaut\WooCommerce\Emails\ChangesRequestedEmail;
use Pixonaut\WooCommerce\FormLayout;
use Pixonaut\WooCommerce\Fulfilment\FulfilmentJobs;
use Pixonaut\WooCommerce\Fulfilment\LineCancellation;
use Pixonaut\WooCommerce\Fulfilment\LineState;
use Pixonaut\WooCommerce\Labels;
use Pixonaut\WooCommerce\Milliseconds;
use Pixonaut\WooCommerce\Plugin;
use Pixonaut\WooCommerce\Refs;
use Pixonaut\WooCommerce\Settings\Settings;
use Pixonaut\WooCommerce\Storefront\CorrectionMount;
use Pixonaut\WooCommerce\Storefront\InlineProducts;
use Pixonaut\WooCommerce\Storefront\Preload;
use Pixonaut\WooCommerce\Tests\Integration\Cart\PersonalisedCartTestCase;
use Pixonaut\WooCommerce\Tests\Support\ActionSchedulerRunner;
use Pixonaut\WooCommerce\Tests\Support\BlankFixture;
use Pixonaut\WooCommerce\Tests\Support\DesignFixture;
use Pixonaut\WooCommerce\Tests\Support\FulfilmentAnswers;
use Pixonaut\WooCommerce\Tests\Support\OpenApiAssert;
use Pixonaut\WooCommerce\Tests\Support\RecordingLogger;
use Pixonaut\WooCommerce\Vendor\Pixonaut\Sdk\Webhooks\Event;
use Pixonaut\WooCommerce\Vendor\Pixonaut\Sdk\Webhooks\Signature;
use Pixonaut\WooCommerce\Webhooks\Processor;
use ReflectionProperty;
use WC_Emails;
use WC_Order;
use WC_Order_Factory;
use WC_Order_Item_Product;
use WC_Product;
use WC_Product_Attribute;
use WC_Product_Variable;
use WC_Product_Variation;
use WP_REST_Request;
use WP_REST_Response;

/**
 * A customer who asked for changes on the proof page of a paid line, whose
 * approval mode is proof by email: the page sends them back to the product
 * page, which opens the design on a correction link; saving the change
 * approves the new revision with consent `edit`, and editing closes once
 * the line is approved. Pixonaut is answered from a script at its real
 * address.
 */
final class RequestChangesTest extends PersonalisedCartTestCase {

	use AwaitingCustomerOrder;

	private const REVISION = 1;

	private const CORRECTED_REVISION = 2;

	private const CORRECTION_TOKEN = 'eyJhdWQiOiJjb3JyZWN0aW9uIn0.c2lnbmF0dXJl';

	private const LINK = 'link_0123456789abcdef0123456789abcdef';

	private const CORRECTED_PROOF_ID = 'prf_corrected0123456789abcdef';

	private const CUSTOMER_EMAIL = 'ada@example.org';

	private const WEBHOOK_ROUTE = '/pixonaut/v1/webhook';

	private const WEBHOOK_SECRET = 'whsec_fixture_main';

	private const MESSAGE = 'Could the name be larger?';

	/**
	 * When the change was approved, in Unix seconds.
	 */
	private const SAVED_AT = 1_800_000_600;

	private RecordingLogger $logger;

	private WC_Order $order;

	private WC_Order_Item_Product $item;

	public function set_up(): void {
		parent::set_up();
		$this->logger = new RecordingLogger();
		reset_phpmailer_instance();
		// Emails hook their triggers when WooCommerce's mailer is made; each test restores the hooks it found, so each makes its own.
		( new ReflectionProperty( WC_Emails::class, 'instance' ) )->setValue( null, null );
		$this->waiting_order();
	}

	public function tear_down(): void {
		unset( $_GET[ OrderEdit::PARAM ] );
		reset_phpmailer_instance();
		parent::tear_down();
	}

	private function edit(): OrderEdit {
		return new OrderEdit(
			$this->plugin->proof_state(),
			$this->plugin->sdk(),
			new CorrectionMount( $this->plugin->connection(), $this->plugin->sdk(), $this->logger, $this->plugin->settings() ),
			$this->plugin->settings(),
			$this->plugin->labels(),
			$this->logger,
			FulfilmentJobs::schedule( ... ),
			static fn (): int => self::SAVED_AT
		);
	}

	private function state( int $revision = self::REVISION ): string {
		return $this->plugin->proof_state()->sign( $this->order->get_id(), $this->item->get_id(), DesignFixture::ID, $revision );
	}

	public function test_the_proof_page_returns_the_customer_to_the_product_page_that_edits_the_design(): void {
		$state   = $this->state();
		$request = new WP_REST_Request( 'GET', '/' . Plugin::REST_NAMESPACE . '/order-items/' . $this->item->get_id() . '/edit' );
		$request->set_query_params( array( 'state' => $state ) );

		$response = rest_do_request( $request );

		$this->assertSame( 302, $response->get_status() );
		$product = wc_get_product( $this->product_id );
		$this->assertInstanceOf( WC_Product::class, $product );
		$this->assertSame( add_query_arg( OrderEdit::PARAM, rawurlencode( $state ), $product->get_permalink() ), $response->get_headers()['Location'] ?? null );
	}

	public function test_the_edit_page_mounts_the_side_on_a_correction_link_with_the_product_s_approval_ui(): void {
		$this->use_approval_mode( ApprovalMode::CHECKBOX );
		$_GET[ OrderEdit::PARAM ] = $this->state();
		$this->script_link();
		$edit = $this->edit();

		$designer = ( new InlineProducts( $this->plugin->settings(), $this->plugin->connection(), null, $edit ) )->for_product( $this->product() );

		$this->assertNotNull( $designer );
		$this->assertSame( $this->item->get_id(), $designer->order_edit?->item->get_id() );
		$this->assertSame( ApprovalMode::CHECKBOX, $designer->approval_mode );
		$this->assertNull( $designer->closed );
		$this->assertSame(
			array(
				'front' => array(
					'correctionLink'     => self::LINK,
					'design'             => DesignFixture::ID,
					'apiBase'            => Hosts::api(),
					'locale'             => 'en',
					'preload'            => Preload::AUTO,
					'showPrintAreaFrame' => false,
					'blankId'            => DesignFixture::BLANK_ID,
					'placementId'        => DesignFixture::PLACEMENT_ID,
					'placements'         => array( array( 'placementId' => DesignFixture::PLACEMENT_ID ) ),
					'views'              => array(),
					'variants'           => array( BlankFixture::VARIANT_REF ),
				),
			),
			$designer->mounts
		);
		$this->assertSame( $this->plugin->labels()->get( Labels::APPROVE_CHANGES ), $edit->button_text( 'Add to cart', $this->product() ) );
	}

	public function test_the_edit_page_lays_the_form_out_as_the_product_does(): void {
		$_GET[ OrderEdit::PARAM ] = $this->state();
		$this->script_link();
		$this->plugin->settings()->set( Settings::FORM_LAYOUT, FormLayout::STEPS );

		$designer = ( new InlineProducts( $this->plugin->settings(), $this->plugin->connection(), null, $this->edit() ) )->for_product( $this->product() );

		$this->assertSame( FormLayout::STEPS, $designer?->mounts['front']['layout'] );
	}

	public function test_saving_approves_the_new_revision_with_consent_edit_and_the_line_goes_on(): void {
		$this->script_corrected();
		$this->recorder->json( 200, FulfilmentAnswers::approved() );
		$saved_from = Milliseconds::of( time() );

		$response = rest_do_request( $this->save_request( $this->state() ) );

		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( array( 'redirectUrl' => $this->order->get_checkout_order_received_url() ), $response->get_data() );
		$this->assertSame( 'PATCH', $this->recorder->requests[0]['method'] ?? null );
		$approve = $this->recorder->requests[1] ?? array();
		$this->assertStringEndsWith( '/v1/designs/' . DesignFixture::ID . '/approve', (string) ( $approve['url'] ?? '' ) );
		$body = json_decode( (string) ( $approve['body'] ?? '' ), true );
		$this->assertIsArray( $body );
		$this->assertSame( OrderEdit::CONSENT_KIND, $body['consent']['kind'] ?? null );
		$this->assertSame( $this->external_ref(), $body['consent']['ref'] ?? null );
		$this->assertGreaterThanOrEqual( $saved_from, $body['consent']['at'] ?? 0, 'consent is given when the change is saved' );
		$this->assertSame( self::CORRECTED_REVISION, $body['expectedRevision'] ?? null );

		$line = $this->line();
		$this->assertSame( LineState::APPROVED, $line->state() );
		$this->assertSame( self::CORRECTED_REVISION, $line->side_ref( 'front', LineState::SIDE_REVISION ) );
		$this->assertSame( self::CORRECTED_PROOF_ID, $line->side_ref( 'front', LineState::SIDE_PROOF_ID ) );
		$this->assertSame( OrderEdit::CONSENT_KIND, $line->ref( LineState::REF_CONSENT )['kind'] ?? null );
		$this->assertCount( 1, ActionSchedulerRunner::pending( FulfilmentJobs::ACTION ) );
		$this->assertStringContainsString( 'changed the design of "Personalised tee" and approved it', $this->notes() );
	}

	public function test_saving_takes_the_design_s_field_values_now(): void {
		$line = $this->line();
		$line->set_side_ref(
			'front',
			LineState::SIDE_FIELDS,
			array(
				array(
					'label' => 'Name',
					'type'  => FieldValue::TEXT,
					'value' => 'Anna',
				),
			)
		);
		$this->assertTrue( $line->save() );
		$this->script_corrected();
		$this->recorder->json( 200, FulfilmentAnswers::approved() );

		$response = rest_do_request(
			$this->save_request(
				$this->state(),
				array(
					array(
						'label' => 'Name',
						'type'  => FieldValue::TEXT,
						'value' => 'Bo',
					),
				)
			)
		);

		$this->assertSame( 200, $response->get_status() );
		$this->assertEquals( array( new FieldValue( 'Name', FieldValue::TEXT, 'Bo' ) ), $this->line()->side_fields( 'front' ) );
	}

	public function test_editing_closes_once_the_line_is_approved(): void {
		$state = $this->state();
		$this->script_corrected();
		$this->recorder->json( 200, FulfilmentAnswers::approved() );
		$this->assertSame( 200, rest_do_request( $this->save_request( $state ) )->get_status() );
		$sent = count( $this->recorder->requests );

		// The proof page link keeps working, but its state names the revision before the change.
		$_GET[ OrderEdit::PARAM ] = $this->state( self::CORRECTED_REVISION );
		$designer                 = ( new InlineProducts( $this->plugin->settings(), $this->plugin->connection(), null, $this->edit() ) )->for_product( $this->product() );
		$this->assertNotNull( $designer );
		$this->assertSame( array(), $designer->mounts );
		$this->assertSame( $this->plugin->labels()->get( Labels::EDIT_CLOSED ), $designer->closed );

		$again = rest_do_request( $this->save_request( $this->state( self::CORRECTED_REVISION ) ) );
		$this->assertSame( 409, $again->get_status() );
		$this->assertSame( 'edit_closed', ( (array) $again->get_data() )['code'] ?? null );
		$this->assertCount( $sent, $this->recorder->requests, 'nothing reaches Pixonaut once editing closed' );
	}

	public function test_a_state_of_an_older_revision_says_the_proof_has_changed(): void {
		$line = $this->line();
		$line->set_side_ref( 'front', LineState::SIDE_REVISION, self::CORRECTED_REVISION );
		$this->assertTrue( $line->save() );
		$_GET[ OrderEdit::PARAM ] = $this->state();

		$designer = ( new InlineProducts( $this->plugin->settings(), $this->plugin->connection(), null, $this->edit() ) )->for_product( $this->product() );

		$this->assertNotNull( $designer );
		$this->assertSame( array(), $designer->mounts );
		$this->assertSame( $this->plugin->labels()->get( Labels::PROOF_CHANGED ), $designer->closed );
		$refused = rest_do_request( $this->save_request( $this->state() ) );
		$this->assertSame( 409, $refused->get_status() );
		$this->assertSame( 'proof_changed', ( (array) $refused->get_data() )['code'] ?? null );
		$this->assertSame( array(), $this->recorder->requests );
	}

	public function test_a_failed_order_closes_editing(): void {
		$this->order->set_status( 'failed' );
		$this->order->save();

		$this->assert_closed();
	}

	public function test_a_line_being_cancelled_closes_editing(): void {
		$line = $this->line();
		$line->set_ref(
			LineState::REF_CANCEL,
			array(
				'trigger'  => LineCancellation::ORDER_CANCELLED,
				'failures' => 0,
			)
		);
		$this->assertTrue( $line->save() );

		$this->assert_closed();
	}

	/**
	 * The page shows editing closed and the route refuses it, without a call to Pixonaut.
	 */
	private function assert_closed(): void {
		$_GET[ OrderEdit::PARAM ] = $this->state();

		$designer = ( new InlineProducts( $this->plugin->settings(), $this->plugin->connection(), null, $this->edit() ) )->for_product( $this->product() );
		$refused  = rest_do_request( $this->save_request( $this->state() ) );

		$this->assertSame( $this->plugin->labels()->get( Labels::EDIT_CLOSED ), $designer?->closed );
		$this->assertSame( 'edit_closed', ( (array) $refused->get_data() )['code'] ?? null );
		$this->assertSame( array(), $this->recorder->requests );
		$this->assertSame( array(), ActionSchedulerRunner::pending( FulfilmentJobs::ACTION ) );
	}

	public function test_a_change_whose_approval_failed_is_approved_from_the_same_page(): void {
		$state = $this->state();
		$this->script_corrected();
		$this->recorder->json( 500, array( 'error' => 'internal' ) );

		$failed = rest_do_request( $this->save_request( $state ) );

		$this->assertSame( 502, $failed->get_status() );
		$line = $this->line();
		$this->assertSame( LineState::AWAITING_CUSTOMER, $line->state() );
		$this->assertSame( self::CORRECTED_REVISION, $line->side_ref( 'front', LineState::SIDE_REVISION ) );
		$this->assertTrue( (bool) $this->edit()->find( $state )?->open(), 'the page that made the change stays open' );

		$this->script_corrected( self::CORRECTED_REVISION + 1 );
		$this->recorder->json( 200, FulfilmentAnswers::approved() );
		$this->assertSame( 200, rest_do_request( $this->save_request( $state ) )->get_status() );

		$line = $this->line();
		$this->assertSame( LineState::APPROVED, $line->state() );
		$this->assertSame( self::CORRECTED_REVISION + 1, $line->side_ref( 'front', LineState::SIDE_REVISION ) );
		$this->assertNull( $line->side_ref( 'front', LineState::SIDE_EDIT_BASE ) );
	}

	public function test_a_revision_stored_while_the_change_was_approved_is_not_approved(): void {
		$this->script_corrected();
		$this->recorder->json( 200, FulfilmentAnswers::approved() );
		// A change made in the studio reaches the line while Pixonaut approves this one.
		$newer = function ( $request ): void {
			if ( 'designs.approve' === $request->operation ) {
				$line = $this->line();
				$line->set_side_ref( 'front', LineState::SIDE_REVISION, self::CORRECTED_REVISION + 1 );
				$this->assertTrue( $line->save() );
			}
		};
		add_action( 'pixonaut_wc_api_after_request', $newer );

		$refused = rest_do_request( $this->save_request( $this->state() ) );
		remove_action( 'pixonaut_wc_api_after_request', $newer );

		$this->assertSame( 'revision_changed', ( (array) $refused->get_data() )['code'] ?? null );
		$line = $this->line();
		$this->assertSame( LineState::AWAITING_CUSTOMER, $line->state() );
		$this->assertNotTrue( $line->side_ref( 'front', LineState::SIDE_APPROVED ) );
		$this->assertSame( array(), ActionSchedulerRunner::pending( FulfilmentJobs::ACTION ) );
	}

	public function test_a_variation_bought_with_any_value_returns_to_the_value_bought(): void {
		$parent    = new WC_Product_Variable();
		$attribute = new WC_Product_Attribute();
		$attribute->set_name( 'size' );
		$attribute->set_options( array( 'S', 'L' ) );
		$attribute->set_variation( true );
		$parent->set_attributes( array( $attribute ) );
		$parent->save();
		self::config()->save_to( $parent );
		DesignFixture::sell_variant( $parent );
		$variation = new WC_Product_Variation();
		$variation->set_parent_id( $parent->get_id() );
		// "Any size".
		$variation->set_attributes( array( 'size' => '' ) );
		$variation->set_regular_price( '30' );
		$variation->save();
		$this->item->set_product( $variation );
		$this->item->update_meta_data( 'size', 'L' );
		$this->item->save();

		$line = $this->edit()->find( $this->state() );

		$this->assertNotNull( $line );
		$url = (string) $this->edit()->page_url( $line );
		$this->assertStringContainsString( 'attribute_size=L', $url );
		$this->assertStringContainsString( OrderEdit::PARAM . '=', $url );
	}

	public function test_a_state_not_the_store_s_opens_nothing(): void {
		$_GET[ OrderEdit::PARAM ] = $this->state() . 'x';

		$designer = ( new InlineProducts( $this->plugin->settings(), $this->plugin->connection(), null, $this->edit() ) )->for_product( $this->product() );

		$this->assertNull( $designer?->order_edit );
		$this->assertSame( 409, rest_do_request( $this->save_request( $this->state() . 'x' ) )->get_status() );
	}

	public function test_the_checkbox_mode_needs_the_box_ticked(): void {
		$this->use_approval_mode( ApprovalMode::CHECKBOX );

		$refused = rest_do_request( $this->save_request( $this->state() ) );

		$this->assertSame( 400, $refused->get_status() );
		$this->assertSame( array(), $this->recorder->requests );
	}

	public function test_a_refused_correction_leaves_the_line_waiting(): void {
		$this->recorder->json( 409, array( 'error' => 'revision_changed' ) );

		$refused = rest_do_request( $this->save_request( $this->state() ) );

		$this->assertSame( 409, $refused->get_status() );
		$this->assertSame( 'revision_changed', ( (array) $refused->get_data() )['code'] ?? null );
		$this->assertSame( LineState::AWAITING_CUSTOMER, $this->line()->state() );
		$this->assertSame( self::REVISION, $this->line()->side_ref( 'front', LineState::SIDE_REVISION ) );
	}

	public function test_the_link_route_mints_the_side_s_link_again(): void {
		$this->script_link();

		$request = new WP_REST_Request( 'POST', '/' . Plugin::REST_NAMESPACE . '/order-items/' . $this->item->get_id() . '/design-link' );
		$request->set_header( 'Content-Type', 'application/json' );
		$request->set_body(
			(string) wp_json_encode(
				array(
					'key'  => $this->state(),
					'side' => 'front',
				)
			)
		);
		$response = rest_do_request( $request );

		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( self::LINK, ( (array) $response->get_data() )['mounts']['front']['correctionLink'] ?? null );
	}

	public function test_a_request_for_changes_emails_the_customer_an_edit_link_and_the_store_the_request(): void {
		$this->deliver( $this->changes_requested( self::REVISION ) );
		ActionSchedulerRunner::run_pending( Processor::PROCESS_HOOK );
		$this->assertSame( array(), ActionSchedulerRunner::failed( Processor::PROCESS_HOOK ) );

		$edit_url = ( new ChangesRequested( $this->plugin->connection(), $this->plugin->proof_state() ) )->edit_url( $this->item, DesignFixture::ID, self::REVISION );
		$customer = $this->mails( $this->plugin->labels()->get( Labels::CHANGES_LINK_INTRO ) );
		$this->assertCount( 1, $customer );
		$this->assertSame( self::CUSTOMER_EMAIL, $customer[0]['to'][0][0] );
		// The mailer escapes the address's `&`; the signed state is what opens the design.
		$this->assertStringContainsString( (string) wp_parse_args( (string) wp_parse_url( $edit_url, PHP_URL_QUERY ) )['state'], (string) $customer[0]['body'] );
		$store = $this->mails( self::MESSAGE );
		$this->assertCount( 1, $store );
		$this->assertSame( get_option( 'admin_email' ), $store[0]['to'][0][0] );
		$this->assertStringContainsString( 'asked for changes to the design of "Personalised tee": ' . self::MESSAGE, $this->notes() );
		$this->assertSame( LineState::AWAITING_CUSTOMER, $this->line()->state() );
		$this->assertArrayHasKey( ChangesLinkEmail::class, WC()->mailer()->get_emails() );
		$this->assertArrayHasKey( ChangesRequestedEmail::class, WC()->mailer()->get_emails() );
	}

	public function test_a_request_about_a_design_changed_since_tells_the_store_only(): void {
		( new ChangesRequested( $this->plugin->connection(), $this->plugin->proof_state() ) )
			->handle( Event::fromArray( $this->changes_requested( self::CORRECTED_REVISION ) ) );

		$this->assertSame( array(), $this->mails( $this->plugin->labels()->get( Labels::CHANGES_LINK_INTRO ) ), 'no link to a design the customer changed already' );
		$this->assertCount( 1, $this->mails( self::MESSAGE ) );
		$this->assertStringContainsString( self::MESSAGE, $this->notes() );
		$this->assertSame( LineState::AWAITING_CUSTOMER, $this->line()->state() );
	}

	/**
	 * A paid order whose one line waits for the customer's approval of its
	 * proof, by email.
	 */
	private function waiting_order(): void {
		[ $this->order, $this->item ] = $this->awaiting_customer_order( $this->product(), self::CUSTOMER_EMAIL, self::REVISION );
		reset_phpmailer_instance();
	}

	private function product(): WC_Product {
		$product = wc_get_product( $this->product_id );
		$this->assertInstanceOf( WC_Product::class, $product );
		return $product;
	}

	/**
	 * @param string $mode The product's approval mode.
	 */
	private function use_approval_mode( string $mode ): void {
		$base = self::config();
		( new ProductConfig( personalise: true, blank_id: $base->blank_id, blank_name: $base->blank_name, sides: $base->sides, approval_mode: $mode ) )
			->save_to( $this->product() );
		DesignFixture::sell_variant( $this->product() );
	}

	private function line(): LineState {
		$item = WC_Order_Factory::get_order_item( $this->item->get_id() );
		$this->assertInstanceOf( WC_Order_Item_Product::class, $item );
		return LineState::of( $item );
	}

	private function external_ref(): string {
		return Refs::order_item_ref( self::SITE, $this->order->get_id(), $this->item->get_id() );
	}

	/**
	 * Scripts the side's correction link, then the read of its design.
	 */
	private function script_link(): void {
		$this->recorder->json(
			201,
			array(
				'id'        => 'shl_0123',
				'token'     => self::LINK,
				'url'       => null,
				'expiresAt' => Milliseconds::of( self::SAVED_AT + HOUR_IN_SECONDS ),
				'name'      => null,
				'brandId'   => null,
			)
		)->json( 200, DesignFixture::design() );
	}

	private function script_corrected( int $revision = self::CORRECTED_REVISION ): void {
		$this->recorder->json(
			200,
			array(
				'revision'      => $revision,
				'edited'        => true,
				'proofId'       => self::CORRECTED_PROOF_ID,
				'printFloorDpi' => 150,
				'lowResolution' => array(),
			)
		);
	}

	/**
	 * @param string $state The signed state.
	 *
	 * @return WP_REST_Request<array<string, mixed>>
	 */
	/**
	 * @param string                     $state  The state that opened the page.
	 * @param list<array<string, mixed>> $fields The field values the page posts.
	 */
	private function save_request( string $state, array $fields = array() ): WP_REST_Request {
		$request = new WP_REST_Request( 'POST', '/' . Plugin::REST_NAMESPACE . str_replace( '(?P<item>\d+)', (string) $this->item->get_id(), OrderEditRoute::UPDATE_ROUTE ) );
		$request->set_header( 'Content-Type', 'application/json' );
		$request->set_body(
			(string) wp_json_encode(
				array(
					'key'        => $state,
					'side'       => 'front',
					'proofToken' => self::CORRECTION_TOKEN,
					'fields'     => $fields,
				)
			)
		);
		return $request;
	}

	/**
	 * @param int $revision The revision the request was about.
	 *
	 * @return array<string, mixed>
	 */
	private function changes_requested( int $revision ): array {
		$created = Milliseconds::of( time() );
		return array(
			'id'        => "{$created}-4c5d6e7f-8a9b-4c0d-9e1f-2a3b4c5d6e7f",
			'type'      => ChangesRequested::TYPE,
			'payload'   => array(
				'designId' => DesignFixture::ID,
				'revision' => $revision,
				'message'  => self::MESSAGE,
			),
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
		$request->set_header( Signature::SIGNATURE_HEADER, 'v1=' . hash_hmac( 'sha256', "{$timestamp}.{$body}", self::WEBHOOK_SECRET ) );
		$request->set_body( $body );
		$response = rest_do_request( $request );
		$this->assertInstanceOf( WP_REST_Response::class, $response );
		$this->assertLessThan( 300, $response->get_status() );
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

	private function notes(): string {
		$notes = wc_get_order_notes( array( 'order_id' => $this->order->get_id() ) );
		return implode( "\n", array_map( static fn ( $note ): string => (string) $note->content, $notes ) );
	}
}
