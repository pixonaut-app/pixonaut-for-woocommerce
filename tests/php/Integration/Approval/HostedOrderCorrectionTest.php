<?php
/**
 * @package Pixonaut\WooCommerce
 */

declare(strict_types=1);

namespace Pixonaut\WooCommerce\Tests\Integration\Approval;

use Pixonaut\WooCommerce\Approval\OrderEdit;
use Pixonaut\WooCommerce\ApprovalMode;
use Pixonaut\WooCommerce\Catalogue\ProductConfig;
use Pixonaut\WooCommerce\Fulfilment\LineState;
use Pixonaut\WooCommerce\Labels;
use Pixonaut\WooCommerce\Layout;
use Pixonaut\WooCommerce\Milliseconds;
use Pixonaut\WooCommerce\Plugin;
use Pixonaut\WooCommerce\Settings\Settings;
use Pixonaut\WooCommerce\Storefront\HostedCorrection;
use Pixonaut\WooCommerce\Tests\Integration\Cart\PersonalisedCartTestCase;
use Pixonaut\WooCommerce\Tests\Support\DesignFixture;
use Pixonaut\WooCommerce\Tests\Support\FulfilmentAnswers;
use WC_Order;
use WC_Order_Factory;
use WC_Order_Item_Product;
use WC_Product;
use WP_REST_Request;

/**
 * A customer who asked for changes to the proof of a line whose product is
 * on the Personalise button layout: the proof page's return opens Pixonaut's
 * hosted correction page with the signed `state`, and coming back with the
 * correction's proof token saves the change and approves it with consent
 * `edit`. Pixonaut is answered from a script at its real address.
 */
final class HostedOrderCorrectionTest extends PersonalisedCartTestCase {

	use AwaitingCustomerOrder;

	private const REVISION = 1;

	private const CORRECTED_REVISION = 2;

	private const CORRECTION_TOKEN = 'eyJhdWQiOiJjb3JyZWN0aW9uIn0.c2lnbmF0dXJl';

	private const PAGE_URL = 'http://fake-pixonaut.test/h/correct/0123456789abcdef0123456789abcdef';

	private const CORRECTED_PROOF_ID = 'prf_corrected0123456789abcdef';

	private WC_Order $order;

	private WC_Order_Item_Product $item;

	private HostedCorrection $hosted;

	public function set_up(): void {
		parent::set_up();
		$this->plugin->settings()->set( Settings::DEFAULT_LAYOUT, Layout::BUTTON );
		[ $this->order, $this->item ] = $this->awaiting_customer_order( $this->product(), 'ada@example.org', self::REVISION );
		$this->hosted                 = $this->plugin->hosted_correction();
		wc_clear_notices();
	}

	private function product(): WC_Product {
		$product = wc_get_product( $this->product_id );
		$this->assertInstanceOf( WC_Product::class, $product );
		return $product;
	}

	private function state( int $revision = self::REVISION ): string {
		return $this->plugin->proof_state()->sign( $this->order->get_id(), $this->item->get_id(), DesignFixture::ID, $revision );
	}

	private function line(): LineState {
		$item = WC_Order_Factory::get_order_item( $this->item->get_id() );
		$this->assertInstanceOf( WC_Order_Item_Product::class, $item );
		return LineState::of( $item );
	}

	private function script_page(): void {
		$this->recorder->json(
			201,
			array(
				'id'        => 'shl_0123',
				'token'     => '0123456789abcdef0123456789abcdef',
				'url'       => self::PAGE_URL,
				'expiresAt' => Milliseconds::of( time() + HOUR_IN_SECONDS ),
				'name'      => null,
				'brandId'   => null,
			)
		);
	}

	private function script_corrected(): void {
		$this->recorder->json(
			200,
			array(
				'revision'      => self::CORRECTED_REVISION,
				'edited'        => true,
				'proofId'       => self::CORRECTED_PROOF_ID,
				'printFloorDpi' => 150,
				'lowResolution' => array(),
			)
		);
	}

	/**
	 * @return list<string> Error notices shown to the customer.
	 */
	private static function error_notices(): array {
		return array_values( array_map( static fn ( array $notice ): string => (string) $notice['notice'], wc_get_notices( 'error' ) ) );
	}

	public function test_the_proof_page_returns_the_customer_to_the_hosted_correction(): void {
		$state   = $this->state();
		$request = new WP_REST_Request( 'GET', '/' . Plugin::REST_NAMESPACE . '/order-items/' . $this->item->get_id() . '/edit' );
		$request->set_query_params( array( 'state' => $state ) );

		$response = rest_do_request( $request );

		$this->assertSame( 302, $response->get_status() );
		$this->assertSame( HostedCorrection::order_url( $state ), $response->get_headers()['Location'] ?? null );
	}

	public function test_starting_mints_a_correction_link_carrying_the_signed_state_and_no_locale(): void {
		$state = $this->state();
		$this->script_page();

		$this->assertSame( self::PAGE_URL, $this->hosted->start_order( $state ) );

		$sent = $this->recorder->requests[0] ?? null;
		$this->assertIsArray( $sent );
		$this->assertStringEndsWith( '/v1/designs/' . DesignFixture::ID . '/correction-links', $sent['url'] );
		$this->assertSame(
			array(
				'returnUrl' => HostedCorrection::return_url( HostedCorrection::ORDER ),
				'state'     => $state,
			),
			json_decode( (string) $sent['body'], true )
		);
	}

	public function test_returning_saves_the_change_and_approves_it_with_consent_edit(): void {
		$this->script_corrected();
		$this->recorder->json( 200, FulfilmentAnswers::approved() );

		$this->assertSame( $this->order->get_checkout_order_received_url(), $this->hosted->finish_order( $this->state(), self::CORRECTION_TOKEN ) );

		$this->assertSame( 'PATCH', $this->recorder->requests[0]['method'] ?? null );
		$this->assertSame( array( 'proofToken' => self::CORRECTION_TOKEN ), json_decode( (string) ( $this->recorder->requests[0]['body'] ?? '' ), true ) );
		$approve = json_decode( (string) ( $this->recorder->requests[1]['body'] ?? '' ), true );
		$this->assertIsArray( $approve );
		$this->assertSame( OrderEdit::CONSENT_KIND, $approve['consent']['kind'] ?? null );
		$this->assertSame( self::CORRECTED_REVISION, $approve['expectedRevision'] ?? null );
		$line = $this->line();
		$this->assertSame( LineState::APPROVED, $line->state() );
		$this->assertSame( self::CORRECTED_REVISION, $line->side_ref( 'front', LineState::SIDE_REVISION ) );
		$this->assertSame( array(), self::error_notices() );
	}

	public function test_a_repeated_return_goes_on_when_the_design_answers_without_a_field_the_store_does_not_read(): void {
		$this->script_corrected();
		$this->recorder
			->json( 409, array( 'error' => 'not_committed' ) )
			->off_spec(
				200,
				DesignFixture::as_answered_without_unread_fields(
					array(
						'status'   => 'approved',
						'revision' => self::CORRECTED_REVISION,
					)
				)
			);

		$this->assertSame( $this->order->get_checkout_order_received_url(), $this->hosted->finish_order( $this->state(), self::CORRECTION_TOKEN ) );

		$this->assertSame( LineState::APPROVED, $this->line()->state() );
		$this->assertSame( self::CORRECTED_REVISION, $this->line()->side_ref( 'front', LineState::SIDE_REVISION ) );
		$this->assertSame( array(), self::error_notices() );
	}

	public function test_the_hosted_approval_stands_in_for_the_approval_box(): void {
		$base = self::config();
		( new ProductConfig( personalise: true, blank_id: $base->blank_id, blank_name: $base->blank_name, sides: $base->sides, approval_mode: ApprovalMode::CHECKBOX, layout: Layout::BUTTON ) )
			->save_to( $this->product() );
		DesignFixture::sell_variant( $this->product() );
		$this->script_corrected();
		$this->recorder->json( 200, FulfilmentAnswers::approved() );

		$this->hosted->finish_order( $this->state(), self::CORRECTION_TOKEN );

		$this->assertSame( LineState::APPROVED, $this->line()->state() );
	}

	public function test_cancelling_returns_to_the_order_and_the_link_opens_again(): void {
		$state = $this->state();

		$this->assertSame( $this->order->get_checkout_order_received_url(), $this->hosted->finish_order( $state, null ) );
		$this->assertSame( array(), $this->recorder->requests );

		$this->script_page();
		$this->assertSame( self::PAGE_URL, $this->hosted->start_order( $state ) );
	}

	public function test_a_state_of_an_older_revision_says_the_proof_has_changed(): void {
		$line = $this->line();
		$line->set_side_ref( 'front', LineState::SIDE_REVISION, self::CORRECTED_REVISION );
		$this->assertTrue( $line->save() );

		$this->assertSame( $this->order->get_checkout_order_received_url(), $this->hosted->start_order( $this->state() ) );
		$this->hosted->finish_order( $this->state(), self::CORRECTION_TOKEN );

		$this->assertSame( array(), $this->recorder->requests );
		$changed = $this->plugin->labels()->get( Labels::PROOF_CHANGED );
		$this->assertSame( array( $changed, $changed ), self::error_notices() );
	}

	public function test_an_approved_line_closes_editing(): void {
		$this->script_corrected();
		$this->recorder->json( 200, FulfilmentAnswers::approved() );
		$this->hosted->finish_order( $this->state(), self::CORRECTION_TOKEN );
		$sent = count( $this->recorder->requests );

		$this->hosted->start_order( $this->state( self::CORRECTED_REVISION ) );

		$this->assertCount( $sent, $this->recorder->requests );
		$this->assertSame( array( $this->plugin->labels()->get( Labels::EDIT_CLOSED ) ), self::error_notices() );
	}

	public function test_a_refused_correction_leaves_the_line_waiting_and_says_why(): void {
		$this->recorder->json( 410, array( 'error' => 'proof_expired' ) );

		$this->hosted->finish_order( $this->state(), self::CORRECTION_TOKEN );

		$this->assertSame( LineState::AWAITING_CUSTOMER, $this->line()->state() );
		$this->assertSame( array( $this->plugin->labels()->get( Labels::DESIGN_EXPIRED ) ), self::error_notices() );
	}

	public function test_a_refused_link_returns_to_the_order_with_personalisation_unavailable(): void {
		$this->recorder->json( 409, array( 'error' => 'not_editable' ) );

		$this->assertSame( $this->order->get_checkout_order_received_url(), $this->hosted->start_order( $this->state() ) );

		$this->assertSame( array( $this->plugin->labels()->get( Labels::PERSONALISATION_UNAVAILABLE ) ), self::error_notices() );
	}

	public function test_the_order_received_page_shows_the_notice_the_return_left(): void {
		$this->script_corrected();
		$this->recorder->json( 200, FulfilmentAnswers::approved() );
		$this->hosted->register();
		$this->hosted->finish_order( $this->state(), self::CORRECTION_TOKEN );

		ob_start();
		do_action( 'woocommerce_before_thankyou', $this->order->get_id() );
		$html = (string) ob_get_clean();

		$this->assertStringContainsString( esc_html( $this->plugin->labels()->get( Labels::DESIGN_UPDATED ) ), $html );
		$this->assertSame( 0, wc_notice_count() );
	}

	public function test_a_state_not_the_store_s_goes_home(): void {
		$forged = 'eyJvIjoxfQ.forged';

		$this->assertSame( home_url( '/' ), $this->hosted->start_order( $forged ) );
		$this->assertSame( home_url( '/' ), $this->hosted->finish_order( $forged, self::CORRECTION_TOKEN ) );
		$this->assertSame( array(), $this->recorder->requests );
	}
}
