<?php
/**
 * @package Pixonaut\WooCommerce
 */

declare(strict_types=1);

namespace Pixonaut\WooCommerce\Tests\Integration\Fulfilment;

use Pixonaut\WooCommerce\ApprovalMode;
use Pixonaut\WooCommerce\Fulfilment\FulfilmentJobs;
use Pixonaut\WooCommerce\Fulfilment\LineState;
use Pixonaut\WooCommerce\Refs;
use Pixonaut\WooCommerce\Tests\Support\ActionSchedulerRunner;
use Pixonaut\WooCommerce\Tests\Support\FulfilmentAnswers;
use Pixonaut\WooCommerce\Vendor\Pixonaut\Sdk\Generated\ErrorCode;
use WC_Order_Item_Product;

/**
 * The lines of one WooCommerce order go to Pixonaut as one order, an item
 * per line, once every line is approved.
 */
final class OrderOfLinesTest extends FulfilmentTestCase {

	/**
	 * The second line's design.
	 */
	private const SECOND_DESIGN = 'd_second';

	private WC_Order_Item_Product $second;

	public function set_up(): void {
		parent::set_up();
		$this->order->add_product( $this->product(), 1 );
		$this->order->save();
		$items = array_values( $this->order->get_items() );
		$this->assertInstanceOf( WC_Order_Item_Product::class, $items[1] );
		$this->second = $items[1];
		$line         = LineState::start( $this->second, array( 'front' ) );
		$line->set_ref( LineState::REF_TENANT_ID, FulfilmentAnswers::TENANT_ID );
		$line->set_ref( LineState::REF_APPROVAL_MODE, ApprovalMode::ADD_TO_CART );
		$line->set_ref(
			LineState::REF_CONSENT,
			array(
				'kind' => ApprovalMode::ADD_TO_CART,
				'at'   => self::CONSENT_AT_MS,
			)
		);
		$line->set_side_ref( 'front', LineState::SIDE_DESIGN_ID, self::SECOND_DESIGN );
		$line->set_side_ref( 'front', LineState::SIDE_REVISION, self::REVISION );
		$line->set_ref( LineState::REF_EXTERNAL_REF, $this->second_ref() );
		$line->set_ref( LineState::REF_EXTERNAL_REF_MOVED, true );
		$this->assertTrue( $line->save() );
	}

	public function test_the_lines_of_an_order_are_placed_as_one_order_once_both_are_approved(): void {
		$this->recorder
			->json( 200, FulfilmentAnswers::approved() )
			->json( 200, FulfilmentAnswers::approved() )
			->json( 200, FulfilmentAnswers::orders( array() ) )
			->json( 200, FulfilmentAnswers::design_approved( self::DESIGN, self::REVISION ) )
			->json( 200, FulfilmentAnswers::design_approved( self::SECOND_DESIGN, self::REVISION ) )
			->json(
				201,
				FulfilmentAnswers::order_of_items(
					'o_1',
					$this->store_ref(),
					array(
						FulfilmentAnswers::item( 'oi_first', array( 'p_front' ), $this->first_ref() ),
						FulfilmentAnswers::item( 'oi_second', array( 'p_second' ), $this->second_ref() ),
					)
				)
			);

		do_action( 'woocommerce_payment_complete', $this->order->get_id() );
		$this->assertSame( 2, ActionSchedulerRunner::run_pending( FulfilmentJobs::ACTION ) );

		$this->assertSame( array(), ActionSchedulerRunner::failed( FulfilmentJobs::ACTION ) );
		$this->assertSame( 1, $this->count_sent( 'POST', '/v1/orders' ) );
		$placed = $this->placed();
		$body   = json_decode( (string) $placed['body'], true );
		$this->assertSame( $this->store_ref(), $body['externalRef'] );
		$this->assertSame( array( $this->first_ref(), $this->second_ref() ), array_column( $body['items'], 'externalRef' ) );
		$this->assertSame( array( array( array( 'designId' => self::DESIGN ) ), array( array( 'designId' => self::SECOND_DESIGN ) ) ), array_column( $body['items'], 'designs' ) );
		$this->assertSame( array( self::QUANTITY, 1 ), array_column( $body['items'], 'quantity' ) );
		$this->assertArrayNotHasKey( 'storeOrder', $body );
		$this->assertSame( Refs::order_lines_key( self::SITE, array( $this->item->get_id(), $this->second->get_id() ) ), $placed['headers']['Idempotency-Key'] ?? $placed['headers']['idempotency-key'] ?? null );
		$first  = $this->line();
		$second = LineState::of( new WC_Order_Item_Product( $this->second->get_id() ) );
		$this->assertSame( array( LineState::ORDERED, LineState::ORDERED ), array( $first->state(), $second->state() ) );
		$this->assertSame( array( 'o_1', 'o_1' ), array( $first->ref( LineState::REF_ORDER_ID ), $second->ref( LineState::REF_ORDER_ID ) ) );
		$this->assertSame( array( 'oi_first', 'oi_second' ), array( $first->ref( LineState::REF_ORDER_ITEM_ID ), $second->ref( LineState::REF_ORDER_ITEM_ID ) ) );
		$this->assertSame( $this->store_ref(), $first->ref( LineState::REF_ORDER_EXTERNAL_REF ) );
	}

	public function test_a_refusal_of_one_item_needs_the_merchant_for_its_line_and_the_other_line_is_placed_alone(): void {
		$this->recorder
			->json( 200, FulfilmentAnswers::approved() )
			->json( 200, FulfilmentAnswers::approved() )
			->json( 200, FulfilmentAnswers::orders( array() ) )
			->json( 200, FulfilmentAnswers::design_approved( self::DESIGN, self::REVISION ) )
			->json( 200, FulfilmentAnswers::design_approved( self::SECOND_DESIGN, self::REVISION ) )
			->json(
				422,
				array(
					'error'     => ErrorCode::VARIANT_RETIRED,
					'itemIndex' => 1,
				)
			)
			->json( 200, FulfilmentAnswers::orders( array() ) )
			->json( 200, FulfilmentAnswers::design_approved( self::DESIGN, self::REVISION ) )
			->json( 201, FulfilmentAnswers::order( 'o_1', array( 'p_front' ), $this->first_ref(), array( 'externalRef' => $this->store_ref() ) ) );

		do_action( 'woocommerce_payment_complete', $this->order->get_id() );
		ActionSchedulerRunner::run_pending( FulfilmentJobs::ACTION );

		$this->assertSame( 2, $this->count_sent( 'POST', '/v1/orders' ) );
		$second = LineState::of( new WC_Order_Item_Product( $this->second->get_id() ) );
		$this->assertSame( LineState::NEEDS_ATTENTION, $second->state() );
		$this->assertSame( ErrorCode::VARIANT_RETIRED, $second->reason() );
		$this->assertNull( $second->ref( LineState::REF_ORDER_REQUEST ) );
		$first = $this->line();
		$this->assertSame( LineState::ORDERED, $first->state() );
		$this->assertSame( 'o_1', $first->ref( LineState::REF_ORDER_ID ) );
		$body = json_decode( (string) $this->placed( 1 )['body'], true );
		$this->assertSame( array( $this->first_ref() ), array_column( $body['items'], 'externalRef' ) );
	}

	public function test_a_line_waiting_for_its_customer_holds_the_order_back(): void {
		$second = LineState::of( new WC_Order_Item_Product( $this->second->get_id() ) );
		$this->assertTrue( $second->transition( LineState::AWAITING_CUSTOMER ) );
		$this->recorder->json( 200, FulfilmentAnswers::approved() );

		do_action( 'woocommerce_payment_complete', $this->order->get_id() );
		$this->assertSame( 1, ActionSchedulerRunner::run_pending( FulfilmentJobs::ACTION ) );

		$this->assertSame( 0, $this->count_sent( 'POST', '/v1/orders' ) );
		$this->assertSame( LineState::APPROVED, $this->line()->state() );

		// The customer never approves: the line is cancelled, and the other goes on alone.
		$this->recorder
			->json( 200, FulfilmentAnswers::orders( array() ) )
			->json( 200, FulfilmentAnswers::design_approved( self::DESIGN, self::REVISION ) )
			->json( 201, FulfilmentAnswers::order( 'o_1', array( 'p_front' ), $this->first_ref(), array( 'externalRef' => $this->store_ref() ) ) );
		$second = LineState::of( new WC_Order_Item_Product( $this->second->get_id() ) );
		$this->assertTrue( $second->transition( LineState::CANCELLED ) );
		$this->assertSame( 1, ActionSchedulerRunner::run_pending( FulfilmentJobs::ACTION ) );

		$this->assertSame( 1, $this->count_sent( 'POST', '/v1/orders' ) );
		$this->assertSame( LineState::ORDERED, $this->line()->state() );
	}

	public function test_a_request_one_line_kept_is_sent_for_every_line_it_names(): void {
		// The request was kept on the first line; saving it on the second lost a race.
		$this->keep_request( $this->item, array( $this->first_ref(), $this->second_ref() ), 'wc:' . self::SITE . ':items:kept' );
		$this->approve_lines();
		$this->recorder
			->json( 200, FulfilmentAnswers::orders( array() ) )
			->json( 200, FulfilmentAnswers::design_approved( self::DESIGN, self::REVISION ) )
			->json( 200, FulfilmentAnswers::design_approved( self::SECOND_DESIGN, self::REVISION ) )
			->json( 201, $this->both_placed() );

		do_action( FulfilmentJobs::ACTION, $this->second->get_id() );

		$this->assertSame( 1, $this->count_sent( 'POST', '/v1/orders' ) );
		$placed = $this->placed();
		$this->assertSame( 'wc:' . self::SITE . ':items:kept', $placed['headers']['Idempotency-Key'] ?? $placed['headers']['idempotency-key'] ?? null );
		$this->assertSame( array( LineState::ORDERED, LineState::ORDERED ), array( $this->line()->state(), $this->second_line()->state() ) );
	}

	public function test_a_request_naming_a_line_no_longer_placed_is_dropped_and_the_rest_placed_anew(): void {
		$this->keep_request( $this->item, array( $this->first_ref(), $this->second_ref() ), 'wc:' . self::SITE . ':items:kept' );
		$this->approve_lines();
		$this->assertTrue( $this->second_line()->transition( LineState::CANCELLED ) );
		$this->recorder
			->json( 200, FulfilmentAnswers::orders( array() ) )
			->json( 200, FulfilmentAnswers::orders( array() ) )
			->json( 200, FulfilmentAnswers::design_approved( self::DESIGN, self::REVISION ) )
			->json( 201, FulfilmentAnswers::order( 'o_1', array( 'p_front' ), $this->first_ref(), array( 'externalRef' => $this->store_ref() ) ) );

		do_action( FulfilmentJobs::ACTION, $this->item->get_id() );

		$body = json_decode( (string) $this->placed()['body'], true );
		$this->assertSame( array( $this->first_ref() ), array_column( $body['items'], 'externalRef' ) );
		$this->assertSame( LineState::ORDERED, $this->line()->state() );
	}

	public function test_a_line_a_plugin_holds_back_is_left_out_of_the_order(): void {
		$held = $this->second->get_id();
		add_filter( 'pixonaut_wc_fulfilment_can_fulfil', static fn ( bool $can, WC_Order_Item_Product $item ): bool => $item->get_id() === $held ? false : $can, 10, 2 );
		$this->approve_lines();
		$this->recorder
			->json( 200, FulfilmentAnswers::orders( array() ) )
			->json( 200, FulfilmentAnswers::design_approved( self::DESIGN, self::REVISION ) )
			->json( 201, FulfilmentAnswers::order( 'o_1', array( 'p_front' ), $this->first_ref(), array( 'externalRef' => $this->store_ref() ) ) );

		do_action( FulfilmentJobs::ACTION, $this->item->get_id() );

		$body = json_decode( (string) $this->placed()['body'], true );
		$this->assertSame( array( $this->first_ref() ), array_column( $body['items'], 'externalRef' ) );
		$this->assertSame( LineState::APPROVED, $this->second_line()->state() );
	}

	public function test_a_request_an_earlier_version_kept_that_placed_nothing_is_placed_in_the_same_run(): void {
		$line = $this->line();
		$line->set_ref(
			LineState::REF_ORDER_REQUEST,
			array(
				'operation'      => 'orders.create',
				'target'         => '/v1/orders',
				'body'           => (string) wp_json_encode( array( 'externalRef' => $this->first_ref() ) ),
				'idempotencyKey' => 'wc:' . self::SITE . ':item:' . $this->item->get_id(),
			)
		);
		$this->assertTrue( $line->save() );
		$this->approve_lines();
		$this->recorder
			->json( 200, FulfilmentAnswers::orders( array() ) )
			->json( 200, FulfilmentAnswers::orders( array() ) )
			->json( 200, FulfilmentAnswers::design_approved( self::DESIGN, self::REVISION ) )
			->json( 200, FulfilmentAnswers::design_approved( self::SECOND_DESIGN, self::REVISION ) )
			->json( 201, $this->both_placed() );

		do_action( FulfilmentJobs::ACTION, $this->item->get_id() );

		$this->assertSame( 1, $this->count_sent( 'POST', '/v1/orders' ) );
		$this->assertSame( array( LineState::ORDERED, LineState::ORDERED ), array( $this->line()->state(), $this->second_line()->state() ) );
	}

	/**
	 * Moves both lines on to `approved`, as their own runs would.
	 */
	private function approve_lines(): void {
		foreach ( array( $this->item, $this->second ) as $item ) {
			$this->assertTrue( LineState::of( new WC_Order_Item_Product( $item->get_id() ) )->transition( LineState::APPROVED ) );
		}
	}

	/**
	 * Keeps a request of the order naming $refs on one line, as a run that stopped after saving it there.
	 *
	 * @param WC_Order_Item_Product $item The line keeping it.
	 * @param string[]              $refs The lines it names.
	 * @param string                $key  Its idempotency key.
	 * @phpstan-param list<string> $refs
	 */
	private function keep_request( WC_Order_Item_Product $item, array $refs, string $key ): void {
		$line = LineState::of( new WC_Order_Item_Product( $item->get_id() ) );
		$line->set_ref(
			LineState::REF_ORDER_REQUEST,
			array(
				'operation'      => 'orders.create',
				'target'         => '/v1/orders',
				'body'           => (string) wp_json_encode(
					array(
						'externalRef' => $this->store_ref(),
						'items'       => array_map( static fn ( string $ref ): array => array( 'externalRef' => $ref ), $refs ),
					)
				),
				'idempotencyKey' => $key,
			)
		);
		$this->assertTrue( $line->save() );
	}

	/**
	 * `201` of `POST /v1/orders` placing both lines.
	 *
	 * @return array<string, mixed>
	 */
	private function both_placed(): array {
		return FulfilmentAnswers::order_of_items(
			'o_1',
			$this->store_ref(),
			array(
				FulfilmentAnswers::item( 'oi_first', array( 'p_front' ), $this->first_ref() ),
				FulfilmentAnswers::item( 'oi_second', array( 'p_second' ), $this->second_ref() ),
			)
		);
	}

	private function second_line(): LineState {
		return LineState::of( new WC_Order_Item_Product( $this->second->get_id() ) );
	}

	private function store_ref(): string {
		return Refs::store_order_ref( self::SITE, $this->order->get_id() );
	}

	private function first_ref(): string {
		return Refs::order_item_ref( self::SITE, $this->order->get_id(), $this->item->get_id() );
	}

	private function second_ref(): string {
		return Refs::order_item_ref( self::SITE, $this->order->get_id(), $this->second->get_id() );
	}

	/**
	 * @param int $nth Which `POST /v1/orders`, from 0.
	 *
	 * @return array<string, mixed> The request.
	 */
	private function placed( int $nth = 0 ): array {
		$posts = array_values( array_filter( $this->recorder->requests, static fn ( array $request ): bool => 'POST' === $request['method'] && self::BASE_URL . '/v1/orders' === $request['url'] ) );
		$this->assertArrayHasKey( $nth, $posts );
		return $posts[ $nth ];
	}
}
