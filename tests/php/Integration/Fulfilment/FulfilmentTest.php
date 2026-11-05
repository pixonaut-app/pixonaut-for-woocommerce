<?php
/**
 * @package Pixonaut\WooCommerce
 */

declare(strict_types=1);

namespace Pixonaut\WooCommerce\Tests\Integration\Fulfilment;

use Pixonaut\WooCommerce\Api\Operations;
use Pixonaut\WooCommerce\Api\PreparedRequest;
use Pixonaut\WooCommerce\Fulfilment\DeliveryChange;
use Pixonaut\WooCommerce\Fulfilment\FulfilmentJobs;
use Pixonaut\WooCommerce\Fulfilment\LineState;
use Pixonaut\WooCommerce\Fulfilment\OrderAddress;
use Pixonaut\WooCommerce\Fulfilment\RetryPolicy;
use Pixonaut\WooCommerce\Refs;
use Pixonaut\WooCommerce\Tests\Support\ActionSchedulerRunner;
use Pixonaut\WooCommerce\Tests\Support\FulfilmentAnswers;
use WC_Order;
use WC_Order_Item_Product;

final class FulfilmentTest extends FulfilmentTestCase {

	public function test_a_duplicate_payment_hook_places_exactly_one_order(): void {
		$external_ref = 'wc:' . self::SITE . ':order:' . $this->order->get_id() . ':item:' . $this->item->get_id();
		$this->recorder
			->json( 200, FulfilmentAnswers::approved() )
			->json( 200, FulfilmentAnswers::orders( array() ) )
			->json( 200, FulfilmentAnswers::design_approved( self::DESIGN, self::REVISION ) )
			->json( 201, FulfilmentAnswers::order( 'o_1', array( 'p_front' ), $external_ref ) );

		do_action( 'woocommerce_payment_complete', $this->order->get_id() );
		do_action( 'woocommerce_payment_complete', $this->order->get_id() );
		$this->order->update_status( 'processing' );

		$this->assertSame( 1, ActionSchedulerRunner::run_pending( FulfilmentJobs::ACTION ) );
		$this->assertSame( array(), ActionSchedulerRunner::failed( FulfilmentJobs::ACTION ) );
		$this->assertSame( 1, $this->count_sent( 'POST', '/v1/orders' ) );
		$line = $this->line();
		$this->assertSame( LineState::ORDERED, $line->state() );
		$this->assertSame( 'o_1', $line->ref( LineState::REF_ORDER_ID ) );
		$this->assertSame( $external_ref, $line->ref( LineState::REF_EXTERNAL_REF ) );
		$sent = function ( string $path ): array {
			$posts = array_values( array_filter( $this->recorder->requests, static fn ( array $request ): bool => 'POST' === $request['method'] && self::BASE_URL . $path === $request['url'] ) );
			$this->assertNotEmpty( $posts );
			return $posts[0];
		};
		// The order names the design: Pixonaut renders its print and holds the order until it is done.
		$this->assertSame( 0, $this->count_sent( 'POST', '/v1/prints' ) );
		$this->assertSame( array( array( 'designId' => 'd_front' ) ), json_decode( (string) $sent( '/v1/orders' )['body'], true )['designs'] ?? null );
		// The order ships to the customer, not to a saved workspace address.
		$order_body = json_decode( (string) $sent( '/v1/orders' )['body'], true );
		$this->assertArrayHasKey( 'shipToAddressId', $order_body );
		$this->assertNull( $order_body['shipToAddressId'] );
		// The line knows the address it was placed with, so only a change of it is sent later.
		$this->assertSame( OrderAddress::fingerprint( $order_body ), DeliveryChange::of( $line )[ DeliveryChange::FIELD_ADDRESS ] ?? null );
		$this->assertSame( array(), ActionSchedulerRunner::pending( DeliveryChange::ACTION ) );

		// Paid again later: the line is past its order, so nothing is scheduled.
		do_action( 'woocommerce_payment_complete', $this->order->get_id() );
		$this->assertSame( 0, ActionSchedulerRunner::run_pending( FulfilmentJobs::ACTION ) );
		$this->assertCount( 4, $this->recorder->requests );
	}

	public function test_an_address_saved_while_the_order_request_is_on_its_way_is_sent_after_it(): void {
		$external_ref = 'wc:' . self::SITE . ':order:' . $this->order->get_id() . ':item:' . $this->item->get_id();
		$this->recorder
			->json( 200, FulfilmentAnswers::approved() )
			->json( 200, FulfilmentAnswers::orders( array() ) )
			->json( 200, FulfilmentAnswers::design_approved( self::DESIGN, self::REVISION ) )
			->json( 201, FulfilmentAnswers::order( 'o_1', array( 'p_front' ), $external_ref ) );
		$order_id = $this->order->get_id();
		// The merchant saves a new address in another request while the order is placed.
		add_action(
			'pixonaut_wc_api_before_request',
			static function ( PreparedRequest $request ) use ( $order_id ): void {
				if ( Operations::CREATE_ORDER !== $request->operation ) {
					return;
				}
				$elsewhere = wc_get_order( $order_id );
				if ( $elsewhere instanceof WC_Order ) {
					$elsewhere->set_shipping_city( 'Göteborg' );
					$elsewhere->save();
				}
			}
		);

		do_action( 'woocommerce_payment_complete', $this->order->get_id() );
		$this->assertSame( 1, ActionSchedulerRunner::run_pending( FulfilmentJobs::ACTION ) );

		$this->assertSame( LineState::ORDERED, $this->line()->state() );
		$this->assertCount( 1, ActionSchedulerRunner::pending( DeliveryChange::ACTION ) );
	}

	public function test_an_order_placed_while_its_prints_render_leaves_the_line_ordered_and_waiting(): void {
		$external_ref = 'wc:' . self::SITE . ':order:' . $this->order->get_id() . ':item:' . $this->item->get_id();
		$this->recorder
			->json( 200, FulfilmentAnswers::approved() )
			->json( 200, FulfilmentAnswers::orders( array() ) )
			->json( 200, FulfilmentAnswers::design_approved( self::DESIGN, self::REVISION ) )
			->json( 201, FulfilmentAnswers::order( 'o_1', array( 'p_front' ), $external_ref, array( 'status' => 'awaiting_prints' ) ) );

		do_action( 'woocommerce_payment_complete', $this->order->get_id() );

		$this->assertSame( 1, ActionSchedulerRunner::run_pending( FulfilmentJobs::ACTION ) );
		$line = $this->line();
		$this->assertSame( LineState::ORDERED, $line->state() );
		$this->assertSame( 'o_1', $line->ref( LineState::REF_ORDER_ID ) );
		$this->assertSame( 'awaiting_prints', $line->ref( LineState::REF_ORDER_STATUS ) );
	}

	public function test_an_adopted_order_further_along_than_ordered_leaves_its_status_to_apply(): void {
		$external_ref = 'wc:' . self::SITE . ':order:' . $this->order->get_id() . ':item:' . $this->item->get_id();
		$this->recorder
			->json( 200, FulfilmentAnswers::approved() )
			->json( 200, FulfilmentAnswers::orders( array( FulfilmentAnswers::order( 'o_1', array( 'p_front' ), $external_ref, array( 'status' => 'shipped' ) ) ) ) );

		do_action( 'woocommerce_payment_complete', $this->order->get_id() );
		$this->assertSame( 1, ActionSchedulerRunner::run_pending( FulfilmentJobs::ACTION ) );

		$line = $this->line();
		$this->assertSame( LineState::ORDERED, $line->state() );
		$this->assertNull( $line->ref( LineState::REF_ORDER_STATUS ), 'a status the line has not taken on is left to apply' );
	}

	public function test_a_site_that_takes_fulfilment_over_leaves_the_line_unsent(): void {
		$seen = array();
		add_filter(
			'pixonaut_wc_fulfilment_can_fulfil',
			static function ( bool $can_fulfil, WC_Order_Item_Product $item, LineState $line ) use ( &$seen ): bool {
				$seen[] = array( $can_fulfil, $item->get_id(), $line->state() );
				return false;
			},
			10,
			3
		);

		do_action( 'woocommerce_payment_complete', $this->order->get_id() );

		$this->assertSame( 1, ActionSchedulerRunner::run_pending( FulfilmentJobs::ACTION ) );
		$this->assertSame( array( array( true, $this->item->get_id(), LineState::COMMITTED ) ), $seen );
		$this->assertSame( array(), $this->recorder->requests );
		$this->assertSame( LineState::COMMITTED, $this->line()->state() );
		$notes = array_map( static fn ( $note ): string => $note->content, wc_get_order_notes( array( 'order_id' => $this->order->get_id() ) ) );
		$this->assertStringContainsString( 'holds it back', implode( "\n", $notes ) );
	}

	public function test_a_job_stopped_after_its_order_request_retries_and_adopts_the_order_it_placed(): void {
		$external_ref = 'wc:' . self::SITE . ':order:' . $this->order->get_id() . ':item:' . $this->item->get_id();
		$this->recorder
			->json( 200, FulfilmentAnswers::approved() )
			->json( 200, FulfilmentAnswers::orders( array() ) )
			->json( 200, FulfilmentAnswers::design_approved( self::DESIGN, self::REVISION ) )
			// Pixonaut placed the order, but the answer never arrived intact.
			->json( 500, array( 'error' => 'db_error' ) );

		do_action( 'woocommerce_payment_complete', $this->order->get_id() );
		$started = time();
		$this->assertSame( 1, ActionSchedulerRunner::run_once( FulfilmentJobs::ACTION ) );

		$this->assertSame( array(), ActionSchedulerRunner::failed( FulfilmentJobs::ACTION ) );
		$due = ActionSchedulerRunner::due_at( FulfilmentJobs::ACTION );
		$this->assertCount( 1, $due );
		$this->assertGreaterThanOrEqual( $started + RetryPolicy::SCHEDULE[0], $due[0] );
		$line = $this->line();
		$this->assertSame( LineState::APPROVED, $line->state() );

		// Paid again meanwhile: the retry is the line's one job.
		do_action( 'woocommerce_payment_complete', $this->order->get_id() );
		// The customer moved after the order request was made.
		$this->order->set_shipping_city( 'Göteborg' );
		$this->order->save();
		$this->assertSame( array(), ActionSchedulerRunner::pending( DeliveryChange::ACTION ) );
		$this->recorder->json( 200, FulfilmentAnswers::orders( array( FulfilmentAnswers::order( 'o_placed', array( 'p_front' ), $external_ref ) ) ) );
		$this->assertSame( 1, ActionSchedulerRunner::run_pending( FulfilmentJobs::ACTION ) );

		$this->assertSame( 1, $this->count_sent( 'POST', '/v1/orders' ) );
		$line = $this->line();
		$this->assertSame( LineState::ORDERED, $line->state() );
		$this->assertSame( 'o_placed', $line->ref( LineState::REF_ORDER_ID ) );
		// The order was placed with the address it had, so the new one is sent after it.
		$this->assertCount( 1, ActionSchedulerRunner::pending( DeliveryChange::ACTION ) );
	}

	public function test_a_front_and_a_back_go_to_the_printshop_as_one_order_led_by_the_front(): void {
		$order = $this->paid_order( array( 'front', 'back' ) );
		$item  = current( $order->get_items() );
		$this->assertInstanceOf( WC_Order_Item_Product::class, $item );
		$external_ref = Refs::order_item_ref( self::SITE, $order->get_id(), $item->get_id() );
		$this->recorder
			->json( 200, FulfilmentAnswers::approved() )
			->json( 200, FulfilmentAnswers::approved() )
			->json( 200, FulfilmentAnswers::orders( array() ) )
			->json( 200, FulfilmentAnswers::design_approved( self::DESIGN, self::REVISION ) )
			->json( 200, FulfilmentAnswers::design_approved( self::DESIGN_PREFIX . 'back', self::REVISION ) )
			->json( 201, FulfilmentAnswers::order( 'o_1', array( 'p_front', 'p_back' ), $external_ref ) );

		do_action( 'woocommerce_payment_complete', $order->get_id() );
		$this->assertSame( 1, ActionSchedulerRunner::run_pending( FulfilmentJobs::ACTION ) );

		$this->assertSame( array(), ActionSchedulerRunner::failed( FulfilmentJobs::ACTION ) );
		$approved = array_values( array_filter( $this->recorder->requests, static fn ( array $request ): bool => str_ends_with( $request['url'], '/approve' ) ) );
		$this->assertSame(
			array( self::BASE_URL . '/v1/designs/d_front/approve', self::BASE_URL . '/v1/designs/d_back/approve' ),
			array_column( $approved, 'url' )
		);
		$this->assertSame( 1, $this->count_sent( 'POST', '/v1/orders' ) );
		$placed = current( array_filter( $this->recorder->requests, fn ( array $request ): bool => 'POST' === $request['method'] && self::BASE_URL . '/v1/orders' === $request['url'] ) );
		$this->assertIsArray( $placed );
		$this->assertSame(
			array( array( 'designId' => 'd_front' ), array( 'designId' => 'd_back' ) ),
			json_decode( (string) $placed['body'], true )['designs'] ?? null
		);
		$line = LineState::of( new WC_Order_Item_Product( $item->get_id() ) );
		$this->assertSame( LineState::ORDERED, $line->state() );
		$this->assertSame( 'o_1', $line->ref( LineState::REF_ORDER_ID ) );
	}

	public function test_an_order_line_without_pixonaut_state_schedules_nothing(): void {
		$order = $this->new_order();
		$order->add_product( $this->product() );
		$order->save();

		do_action( 'woocommerce_payment_complete', $order->get_id() );

		$this->assertSame( array(), ActionSchedulerRunner::pending( FulfilmentJobs::ACTION ) );
	}
}
