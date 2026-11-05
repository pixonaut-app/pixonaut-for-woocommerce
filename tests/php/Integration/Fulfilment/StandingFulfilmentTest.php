<?php
/**
 * @package Pixonaut\WooCommerce
 */

declare(strict_types=1);

namespace Pixonaut\WooCommerce\Tests\Integration\Fulfilment;

use Pixonaut\WooCommerce\Cart\StandingSide;
use Pixonaut\WooCommerce\Fulfilment\FulfilmentJobs;
use Pixonaut\WooCommerce\Fulfilment\LineState;
use Pixonaut\WooCommerce\Refs;
use Pixonaut\WooCommerce\Tests\Support\ActionSchedulerRunner;
use Pixonaut\WooCommerce\Tests\Support\FulfilmentAnswers;
use WC_Order;
use WC_Order_Item_Product;

/**
 * A paid line of standing sides is ordered from their standing designs,
 * which it never approves and never orders again.
 */
final class StandingFulfilmentTest extends FulfilmentTestCase {

	private const STANDING_ID = 'des_standing_1';

	public function test_a_line_of_standing_sides_alone_is_ordered_without_an_approval(): void {
		[ $order, $item ] = $this->standing_order( array(), array( new StandingSide( 'front', self::STANDING_ID, 0 ) ) );
		$external_ref     = Refs::order_item_ref( self::SITE, $order->get_id(), $item->get_id() );
		$this->recorder
			->json( 200, FulfilmentAnswers::orders( array() ) )
			->json( 201, FulfilmentAnswers::order( 'o_1', array( 'p_standing' ), $external_ref ) );

		$this->fulfil( $order );

		$this->assertSame( 0, count( array_filter( $this->recorder->requests, static fn ( array $request ): bool => str_ends_with( $request['url'], '/approve' ) ) ) );
		$body = $this->order_body();
		$this->assertSame( array( array( 'designId' => self::STANDING_ID ) ), $body['designs'] ?? null );
		$this->assertSame( self::QUANTITY, $body['quantity'] ?? null );
		$this->assertSame( $external_ref, $body['externalRef'] ?? null );
		$this->assertSame( 'Storgatan 1', $body['shippingAddress']['line1'] ?? null );
		$this->assertArrayNotHasKey( 'reorder', $body );
		$line = LineState::of( new WC_Order_Item_Product( $item->get_id() ) );
		$this->assertSame( LineState::ORDERED, $line->state() );
		$this->assertSame( 'o_1', $line->ref( LineState::REF_ORDER_ID ) );
	}

	public function test_a_standing_front_and_a_personalised_back_order_both_in_side_order_approving_the_back_alone(): void {
		[ $order, $item ] = $this->standing_order( array( 'back' ), array( new StandingSide( 'front', self::STANDING_ID, 0 ) ) );
		$external_ref     = Refs::order_item_ref( self::SITE, $order->get_id(), $item->get_id() );
		$this->recorder
			->json( 200, FulfilmentAnswers::approved() )
			->json( 200, FulfilmentAnswers::orders( array() ) )
			->json( 200, FulfilmentAnswers::design_approved( self::DESIGN_PREFIX . 'back', self::REVISION ) )
			->json( 201, FulfilmentAnswers::order( 'o_1', array( 'p_standing', 'p_back' ), $external_ref ) );

		$this->fulfil( $order );

		$approved = array_values( array_filter( $this->recorder->requests, static fn ( array $request ): bool => str_ends_with( $request['url'], '/approve' ) ) );
		$this->assertSame( array( self::BASE_URL . '/v1/designs/d_back/approve' ), array_column( $approved, 'url' ) );
		$this->assertSame(
			array( array( 'designId' => self::STANDING_ID ), array( 'designId' => 'd_back' ) ),
			$this->order_body()['designs'] ?? null
		);
	}

	public function test_a_reorder_of_a_mixed_line_is_sent_for_its_customer_design(): void {
		[ $order, $item ] = $this->standing_order( array( 'back' ), array( new StandingSide( 'front', self::STANDING_ID, 0 ) ) );
		$line             = LineState::of( $item );
		$line->set_ref( LineState::REF_REORDER, true );
		$this->assertTrue( $line->save() );
		$this->recorder
			->json( 200, FulfilmentAnswers::approved() )
			->json( 200, FulfilmentAnswers::orders( array() ) )
			->json( 200, FulfilmentAnswers::design_approved( self::DESIGN_PREFIX . 'back', self::REVISION ) )
			->json( 201, FulfilmentAnswers::order( 'o_1', array( 'p_standing', 'p_back' ), Refs::order_item_ref( self::SITE, $order->get_id(), $item->get_id() ) ) );

		$this->fulfil( $order );

		$this->assertTrue( $this->order_body()['reorder'] ?? null );
	}

	public function test_the_same_standing_product_in_two_orders_places_two_printshop_orders(): void {
		$placed = array();
		foreach ( array( 'o_1', 'o_2' ) as $order_id ) {
			[ $order, $item ] = $this->standing_order( array(), array( new StandingSide( 'front', self::STANDING_ID, 0 ) ) );
			$this->recorder
				->json( 200, FulfilmentAnswers::orders( array() ) )
				->json( 201, FulfilmentAnswers::order( $order_id, array( 'p_' . $order_id ), Refs::order_item_ref( self::SITE, $order->get_id(), $item->get_id() ) ) );
			$this->fulfil( $order );
			$placed[] = LineState::of( new WC_Order_Item_Product( $item->get_id() ) )->ref( LineState::REF_ORDER_ID );
		}

		$this->assertSame( array( 'o_1', 'o_2' ), $placed );
		$orders = array_values( array_filter( $this->recorder->requests, static fn ( array $request ): bool => 'POST' === $request['method'] && self::BASE_URL . '/v1/orders' === $request['url'] ) );
		$this->assertCount( 2, $orders );
		$this->assertNotSame( $orders[0]['headers']['Idempotency-Key'], $orders[1]['headers']['Idempotency-Key'] );
	}

	/**
	 * A paid order of one line whose personalised sides hold `d_<key>` and
	 * whose standing sides name their standing designs.
	 *
	 * @param string[]       $sides    The personalised sides' keys, in order.
	 * @param StandingSide[] $standing The standing sides.
	 * @phpstan-param list<string> $sides
	 * @phpstan-param list<StandingSide> $standing
	 * @return array{0: WC_Order, 1: WC_Order_Item_Product}
	 */
	private function standing_order( array $sides, array $standing ): array {
		$order = $this->paid_order( $sides );
		$item  = current( $order->get_items() );
		$this->assertInstanceOf( WC_Order_Item_Product::class, $item );
		$line = LineState::of( $item );
		$line->set_ref( LineState::REF_STANDING, array_map( static fn ( StandingSide $side ): array => $side->to_array(), $standing ) );
		$this->assertTrue( $line->save() );
		return array( $order, $item );
	}

	private function fulfil( WC_Order $order ): void {
		do_action( 'woocommerce_payment_complete', $order->get_id() );
		ActionSchedulerRunner::run_pending( FulfilmentJobs::ACTION );
		$this->assertSame( array(), ActionSchedulerRunner::failed( FulfilmentJobs::ACTION ) );
	}

	/**
	 * The last `POST /v1/orders` body sent.
	 *
	 * @return array<string, mixed>
	 */
	private function order_body(): array {
		$orders = array_values( array_filter( $this->recorder->requests, static fn ( array $request ): bool => 'POST' === $request['method'] && self::BASE_URL . '/v1/orders' === $request['url'] ) );
		$this->assertNotSame( array(), $orders );
		$body = json_decode( (string) $orders[ count( $orders ) - 1 ]['body'], true );
		$this->assertIsArray( $body );
		return $body;
	}
}
