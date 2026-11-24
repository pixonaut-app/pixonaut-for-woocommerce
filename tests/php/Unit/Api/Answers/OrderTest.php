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
use Pixonaut\WooCommerce\Vendor\Pixonaut\Sdk\Generated\Model\OrdersPrintshopOrdersUpdateRequest;
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

	public function test_reads_the_fields_the_store_uses_of_an_order_and_its_item(): void {
		$this->transport->json(
			200,
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
			)
		);

		$order = OrderDetail::get( $this->sdk, 'o_1' )->order;

		$this->assertSame( 'o_1', $order->id );
		$this->assertSame( 'open', $order->status );
		$this->assertSame( self::REF, $order->external_ref );
		$item = $order->item( null, self::REF );
		$this->assertNotNull( $item );
		$this->assertSame( FulfilmentAnswers::item_id( 'o_1' ), $item->id );
		$this->assertSame( 'queued', $item->status );
		$this->assertSame( 2, $item->quantity );
		$this->assertSame( 'v_red_m', $item->variant_ref );
		$this->assertSame( 'pc_1', $item->printshop_connection_id );
		$this->assertSame( 'po_' . $item->id, $item->printshop_order_id() );
		$this->assertSame( 'd_of_p_front', $item->prints[0]->design_id );
		$this->assertSame( 1.0, $item->prints[0]->design_revision );
		$this->assertSame( array( 'monthly_order_limit', 25, '2027-01-01T00:00:00Z' ), array( $order->hold?->reason, $order->hold?->limit, $order->hold?->period_end ) );
		$tracking = $order->shipped_parcel_of( $item->id )?->tracking;
		$this->assertSame( array( 'PN1', null, null ), array( $tracking?->number, $tracking?->carrier, $tracking?->url ) );
		$this->assertSame( array( 'Ada Lovelace', null, null ), array( $order->recipient?->name, $order->recipient?->email, $order->recipient?->phone ) );
		$this->assertNull( $order->shipping_address?->postal_code );
		$this->assertSame( 'Stockholm', $order->shipping_address?->city );
		$this->assertNull( $order->ship_to );
		$this->assertNull( $order->delivery );
		$this->assertSame( 'open', $order->as_sent['status'] ?? null );
	}

	public function test_an_order_finds_a_lines_item_by_its_id_its_ref_or_as_its_only_item(): void {
		$items = array(
			FulfilmentAnswers::item( 'oi_front', array( 'p_front' ), self::REF ),
			FulfilmentAnswers::item( 'oi_back', array( 'p_back' ), self::REF . ':2' ),
		);
		$order = Order::from_answer( FulfilmentAnswers::order_of_items( 'o_1', 'wc:site:order:7', $items ) );

		$this->assertSame( 'oi_back', $order->item( 'oi_back', self::REF )?->id );
		$this->assertSame( 'oi_front', $order->item( null, self::REF )?->id );
		$this->assertNull( $order->item( 'oi_gone', self::REF ), 'An item id the line keeps is not looked for by ref.' );
		$this->assertNull( $order->item( null, 'another-ref' ), 'An order of several items holds no item of another ref.' );
		// An order placed for one line before orders held items: its item is the line's.
		$alone = Order::from_answer( FulfilmentAnswers::order( 'o_2', array( 'p_front' ), self::REF ) );
		$this->assertSame( FulfilmentAnswers::item_id( 'o_2' ), $alone->item( null, 'another-ref' )?->id );
	}

	public function test_reads_the_orders_listed_under_a_ref_with_their_items_refs(): void {
		$this->transport->json( 200, FulfilmentAnswers::orders( array( FulfilmentAnswers::order( 'o_1', array( 'p_front' ), self::REF ) ) ) );

		$orders = OrderList::under( $this->sdk, self::REF )->orders;

		$this->assertStringEndsWith( '/v1/orders?externalRef=' . rawurlencode( self::REF ), $this->transport->sent[0]['url'] );
		$this->assertCount( 1, $orders );
		$this->assertSame( 'o_1', $orders[0]->id );
		$this->assertSame( FulfilmentAnswers::item_id( 'o_1' ), $orders[0]->item_under( self::REF ) );
		$this->assertNull( $orders[0]->item_under( 'another-ref' ) );
		$this->assertSame( FulfilmentAnswers::item_id( 'o_1' ), $orders[0]->item_under( 'another-ref', true ), 'An order listed under the line\'s own ref is the line\'s.' );
	}

	public function test_a_fill_print_reads_without_a_design_or_revision(): void {
		$order = FulfilmentAnswers::order( 'o_1', array( 'p_front' ), self::REF );
		$fill  = $order['items'][0]['prints'][0];
		unset( $fill['designRevision'] );
		$order['items'][0]['prints'][] = array(
			'designId'   => null,
			'fillColour' => '#ffffff',
		) + $fill;
		$this->transport->json( 200, $order );

		$prints = OrderDetail::get( $this->sdk, 'o_1' )->order->items[0]->prints;

		$this->assertNull( $prints[1]->design_id );
		$this->assertNull( $prints[1]->design_revision );
	}

	public function test_a_listed_order_without_a_field_the_store_does_not_use_reads(): void {
		$listed = FulfilmentAnswers::orders( array( FulfilmentAnswers::order( 'o_1', array( 'p_front' ), self::REF ) ) );
		unset( $listed['orders'][0]['printshopNames'], $listed['orders'][0]['items'][0]['blank'] );
		$this->transport->off_spec( self::answer( $listed ) );

		$this->assertSame( 'o_1', OrderList::under( $this->sdk, self::REF )->orders[0]->id );
	}

	public function test_an_order_without_a_field_the_store_reads_is_unexpected(): void {
		$order = FulfilmentAnswers::order( 'o_1', array( 'p_front' ), self::REF );
		unset( $order['items'][0]['quantity'] );
		$this->transport->off_spec( self::answer( $order ) );

		try {
			OrderDetail::get( $this->sdk, 'o_1' );
			$this->fail( 'An order without its item\'s quantity was read.' );
		} catch ( ApiException $error ) {
			$this->assertSame( ApiException::UNEXPECTED_RESPONSE, $error->errorCode );
			$this->assertStringContainsString( '$.items[0]: missing required field "quantity"', (string) $error->getPrevious()?->getMessage() );
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
		unset( $read['items'][0]['reroutes'], $read['items'][0]['varnishLeftOut'] );
		$this->transport->off_spec( self::answer( $read ) );

		$order = OrderDetail::get( $this->sdk, 'o_1' );

		$this->assertStringEndsWith( '/v1/orders/o_1', $this->transport->sent[0]['url'] );
		$this->assertSame( 'o_1', $order->order->id );
		$this->assertTrue( $order->order->items[0]->can_retry );
		$this->assertSame( array( 'submitted', 'failed' ), $order->order->items[0]->status_moves );
		$this->assertSame( 'automation', $order->history[0]->via );
		$this->assertSame( 'Order approved designs', $order->history[0]->automation?->name );
		$this->assertSame( 'order.created', $order->history[0]->type );
		$this->assertTrue( $order->order->as_sent['items'][0]['canRetry'] ?? null, 'The filter is handed the whole read, as sent.' );
	}

	public function test_a_move_of_a_printshop_order_sends_the_body_and_reads_the_answer(): void {
		$this->transport->json( 200, FulfilmentAnswers::order( 'o_1', array( 'p_front' ), self::REF, array( 'status' => 'shipped' ) ) );

		$moved = Order::update_printshop_order( $this->sdk, 'o_1', 'po_1', new OrdersPrintshopOrdersUpdateRequest( status: 'shipped' ) );

		$this->assertSame( 'PATCH', $this->transport->sent[0]['method'] );
		$this->assertStringEndsWith( '/v1/orders/o_1/printshop-orders/po_1', $this->transport->sent[0]['url'] );
		$this->assertSame( array( 'status' => 'shipped' ), json_decode( (string) $this->transport->sent[0]['body'], true ) );
		$this->assertSame( 'shipped', $moved->items[0]->status );
	}

	public function test_a_retry_and_a_cancel_name_the_item(): void {
		$this->transport
			->json( 201, FulfilmentAnswers::order( 'o_1', array( 'p_front' ), self::REF, array( 'status' => 'awaiting_prints' ) ) )
			->json( 200, FulfilmentAnswers::order( 'o_1', array( 'p_front' ), self::REF, array( 'status' => 'cancelled' ) ) + array( 'cancelledItemIds' => array( 'oi_1' ) ) );

		Order::retry( $this->sdk, 'o_1', 'oi_1' );
		Order::cancel_item( $this->sdk, 'o_1', 'oi_1' );

		$this->assertSame( array( 'POST', 'POST' ), array_column( $this->transport->sent, 'method' ) );
		$this->assertStringEndsWith( '/v1/orders/o_1/items/oi_1/retry', $this->transport->sent[0]['url'] );
		$this->assertStringEndsWith( '/v1/orders/o_1/items/oi_1/cancel', $this->transport->sent[1]['url'] );
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
