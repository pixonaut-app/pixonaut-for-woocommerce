<?php
/**
 * @package Pixonaut\WooCommerce
 */

declare(strict_types=1);

namespace Pixonaut\WooCommerce\Tests\Unit\Api\Answers;

use Pixonaut\WooCommerce\Api\Answers\Order;
use Pixonaut\WooCommerce\Api\Answers\OrderDetail;
use Pixonaut\WooCommerce\Api\Answers\OrderList;
use Pixonaut\WooCommerce\Tests\Support\FulfilmentAnswers;
use Pixonaut\WooCommerce\Tests\Support\RecordingLogger;
use Pixonaut\WooCommerce\Tests\Support\ScriptedClient;
use Pixonaut\WooCommerce\Tests\Unit\TestCase;
use Pixonaut\WooCommerce\Vendor\Nyholm\Psr7\Response;
use Pixonaut\WooCommerce\Vendor\Pixonaut\Sdk\Exception\ApiException;
use Pixonaut\WooCommerce\Vendor\Pixonaut\Sdk\Generated\Model\OrdersUpdateRequest;
use Pixonaut\WooCommerce\Vendor\Pixonaut\Sdk\Pixonaut as Sdk;

final class OrderTest extends TestCase {

	private const REF = 'wc:site-1:order:7:item:42';

	private ScriptedClient $transport;

	private Sdk $sdk;

	protected function setUp(): void {
		parent::setUp();
		$this->transport = new ScriptedClient();
		$this->sdk       = $this->transport->sdk( ScriptedClient::credentials(), new RecordingLogger() );
	}

	public function test_reads_the_fields_the_store_uses_of_a_listed_order(): void {
		$this->transport->json(
			200,
			FulfilmentAnswers::orders(
				array(
					FulfilmentAnswers::order(
						'o_1',
						array( 'p_front' ),
						self::REF,
						array(
							'status'                => 'queued',
							'quantity'              => 2,
							'variantRef'            => 'v_red_m',
							'printshopConnectionId' => 'pc_1',
							'hold'                  => array(
								'reason'    => 'monthly_order_limit',
								'limit'     => 25,
								'periodEnd' => '2027-01-01T00:00:00Z',
							),
							'tracking'              => array( 'number' => 'PN1' ),
							'recipient'             => array( 'name' => 'Ada Lovelace' ),
							'shippingAddress'       => array(
								'line1'   => 'Storgatan 1',
								'city'    => 'Stockholm',
								'country' => 'SE',
							),
						)
					),
				)
			)
		);

		$orders = OrderList::under( $this->sdk, self::REF )->orders;

		$this->assertStringEndsWith( '/v1/orders?externalRef=' . rawurlencode( self::REF ), $this->transport->sent[0]['url'] );
		$this->assertCount( 1, $orders );
		$order = $orders[0];
		$this->assertSame( 'o_1', $order->id );
		$this->assertSame( 'queued', $order->status );
		$this->assertSame( self::REF, $order->external_ref );
		$this->assertSame( 2, $order->quantity );
		$this->assertSame( 'v_red_m', $order->variant_ref );
		$this->assertSame( 'pc_1', $order->printshop_connection_id );
		$this->assertSame( 'd_of_p_front', $order->prints[0]->design_id );
		$this->assertSame( 1.0, $order->prints[0]->design_revision );
		$this->assertSame( array( 'monthly_order_limit', 25, '2027-01-01T00:00:00Z' ), array( $order->hold?->reason, $order->hold?->limit, $order->hold?->period_end ) );
		$this->assertSame( array( 'PN1', null, null ), array( $order->tracking?->number, $order->tracking?->carrier, $order->tracking?->url ) );
		$this->assertSame( array( 'Ada Lovelace', null, null ), array( $order->recipient?->name, $order->recipient?->email, $order->recipient?->phone ) );
		$this->assertNull( $order->shipping_address?->postal_code );
		$this->assertSame( 'Stockholm', $order->shipping_address?->city );
		$this->assertNull( $order->ship_to );
		$this->assertNull( $order->delivery );
		$this->assertSame( 'queued', $order->as_sent['status'] ?? null );
	}

	public function test_a_fill_print_reads_without_a_design_or_revision(): void {
		$order = FulfilmentAnswers::order( 'o_1', array( 'p_front' ), self::REF );
		$fill  = $order['prints'][0];
		unset( $fill['designRevision'] );
		$order['prints'][] = array(
			'designId'   => null,
			'fillColour' => '#ffffff',
		) + $fill;
		$this->transport->json( 200, FulfilmentAnswers::orders( array( $order ) ) );

		$prints = OrderList::under( $this->sdk, self::REF )->orders[0]->prints;

		$this->assertNull( $prints[1]->design_id );
		$this->assertNull( $prints[1]->design_revision );
	}

	public function test_a_listed_order_without_a_field_the_store_does_not_use_reads(): void {
		$order = FulfilmentAnswers::order( 'o_1', array( 'p_front' ), self::REF );
		unset( $order['adapter'], $order['printshopAsPlaced'], $order['blank'] );
		$this->transport->off_spec( self::answer( FulfilmentAnswers::orders( array( $order ) ) ) );

		$this->assertSame( 'o_1', OrderList::under( $this->sdk, self::REF )->orders[0]->id );
	}

	public function test_an_order_without_a_field_the_store_reads_is_unexpected(): void {
		$order = FulfilmentAnswers::order( 'o_1', array( 'p_front' ), self::REF );
		unset( $order['quantity'] );
		$this->transport->off_spec( self::answer( FulfilmentAnswers::orders( array( $order ) ) ) );

		try {
			OrderList::under( $this->sdk, self::REF );
			$this->fail( 'An order without its quantity was read.' );
		} catch ( ApiException $error ) {
			$this->assertSame( ApiException::UNEXPECTED_RESPONSE, $error->errorCode );
			$this->assertStringContainsString( '$.orders[0]: missing required field "quantity"', (string) $error->getPrevious()?->getMessage() );
		}
	}

	public function test_reads_an_order_on_its_own_with_its_history_and_what_the_store_may_do(): void {
		$read = FulfilmentAnswers::placed_by(
			FulfilmentAnswers::read_order(
				'o_1',
				self::REF,
				array(
					'canRetry'    => true,
					'statusMoves' => array( 'submitted', 'failed' ),
				)
			),
			'automation',
			array(
				'id'   => 'au_1',
				'name' => 'Order approved designs',
			)
		);
		unset( $read['reroutes'], $read['varnishLeftOut'] );
		$this->transport->off_spec( self::answer( $read ) );

		$order = OrderDetail::get( $this->sdk, 'o_1' );

		$this->assertStringEndsWith( '/v1/orders/o_1', $this->transport->sent[0]['url'] );
		$this->assertSame( 'o_1', $order->order->id );
		$this->assertTrue( $order->can_retry );
		$this->assertSame( array( 'submitted', 'failed' ), $order->status_moves );
		$this->assertSame( 'automation', $order->history[0]->via );
		$this->assertSame( 'Order approved designs', $order->history[0]->automation?->name );
		$this->assertSame( 'order.created', $order->history[0]->type );
		$this->assertTrue( $order->order->as_sent['canRetry'] ?? null, 'The filter is handed the whole read, as sent.' );
	}

	public function test_a_move_sends_the_body_and_reads_the_answer(): void {
		$this->transport->json( 200, FulfilmentAnswers::order( 'o_1', array( 'p_front' ), self::REF, array( 'status' => 'shipped' ) ) );

		$moved = Order::update( $this->sdk, 'o_1', new OrdersUpdateRequest( status: 'shipped' ) );

		$this->assertSame( 'PATCH', $this->transport->sent[0]['method'] );
		$this->assertSame( array( 'status' => 'shipped' ), json_decode( (string) $this->transport->sent[0]['body'], true ) );
		$this->assertSame( 'shipped', $moved->status );
	}

	public function test_an_answer_that_is_not_an_object_is_unexpected(): void {
		$this->transport->off_spec( new Response( 200, array( 'content-type' => 'application/json' ), '"o_1"' ) );

		$this->expectException( ApiException::class );
		Order::cancel( $this->sdk, 'o_1' );
	}

	/**
	 * @param array<string, mixed> $body The answer's body.
	 */
	private static function answer( array $body ): Response {
		return new Response( 200, array( 'content-type' => 'application/json' ), (string) json_encode( $body ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode -- no WordPress in the unit suite.
	}
}
