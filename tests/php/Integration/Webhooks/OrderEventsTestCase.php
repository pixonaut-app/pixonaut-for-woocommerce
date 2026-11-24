<?php
/**
 * @package Pixonaut\WooCommerce
 */

declare(strict_types=1);

namespace Pixonaut\WooCommerce\Tests\Integration\Webhooks;

use ActionScheduler;
use ActionScheduler_Store;
use Pixonaut\WooCommerce\Connection\Cipher;
use Pixonaut\WooCommerce\Connection\Connection;
use Pixonaut\WooCommerce\Connection\ConnectionStore;
use Pixonaut\WooCommerce\Connection\StoredConnection;
use Pixonaut\WooCommerce\Fulfilment\LineState;
use Pixonaut\WooCommerce\Fulfilment\Tracking;
use Pixonaut\WooCommerce\Milliseconds;
use Pixonaut\WooCommerce\Tests\Support\FulfilmentAnswers;
use Pixonaut\WooCommerce\Tests\Support\OpenApiAssert;
use Pixonaut\WooCommerce\Vendor\Pixonaut\Sdk\Webhooks\Signature;
use Pixonaut\WooCommerce\Webhooks\Processor;
use WC_Order;
use WC_Order_Item_Product;
use WP_REST_Request;
use WP_REST_Response;
use WP_UnitTestCase;

/**
 * Delivers Pixonaut's order events to the store, signed and checked against
 * the spec, and runs the jobs that handle them, for a processing order with
 * a Pixonaut line, its item in one Pixonaut order placed for the whole
 * WooCommerce order.
 */
abstract class OrderEventsTestCase extends WP_UnitTestCase {

	protected const ROUTE = '/pixonaut/v1/webhook';

	protected const SECRET = 'whsec_0123456789abcdef0123456789abcdef';

	protected const TENANT_ID = '0b1c2d3e-4f50-4a6b-8c7d-000000000003';

	protected const ORDER_ID = 'e1f2a3b4-c5d6-4e7f-8a9b-0c1d2e3f4a5b';

	protected const PRINT_ID = 'c4d5e6f7-a8b9-4c0d-9e1f-2a3b4c5d6e7f';

	/**
	 * The line's `externalRef`, its item's in ORDER_ID.
	 */
	protected const EXTERNAL_REF = 'wc_site_1042_7';

	/**
	 * The `externalRef` of ORDER_ID, placed for the line's WooCommerce order.
	 */
	protected const ORDER_EXTERNAL_REF = 'wc_site_1042';

	/**
	 * The line's item in ORDER_ID, as FulfilmentAnswers::item_id() names it.
	 */
	protected const ITEM_ID = 'oi_' . self::ORDER_ID;

	protected const TRACKING = array(
		'carrier' => 'PostNord',
		'number'  => 'PN123456789SE',
		'url'     => 'https://tracking.example/PN123456789SE',
	);

	/**
	 * Pixonaut's item statuses on the way to the customer, as change events.
	 */
	protected const JOURNEY = array(
		array( 'submitted', 'queued' ),
		array( 'queued', 'printed' ),
		array( 'printed', 'shipped' ),
	);

	private int $sequence = 0;

	public function set_up(): void {
		parent::set_up();
		( new ConnectionStore( Cipher::for_site() ) )->save( self::connection() );
	}

	/**
	 * An `item.status_changed` envelope for ITEM_ID of ORDER_ID.
	 *
	 * @param string               $previous Previous status.
	 * @param string               $status   New status.
	 * @param array<string, mixed> $payload  Payload fields to override, e.g. `itemId` for another item.
	 * @return array{id: string, type: string, payload: array<string, mixed>, createdAt: int}
	 */
	protected function status_event( string $previous, string $status, array $payload = array() ): array {
		return $this->item_event(
			'item.status_changed',
			$payload + array(
				'previousStatus' => $previous,
				'status'         => $status,
				'via'            => 'integration',
			)
		);
	}

	/**
	 * An `item.failed` envelope for ITEM_ID of ORDER_ID.
	 *
	 * @param string               $reason  Why it failed.
	 * @param array<string, mixed> $payload Payload fields to override.
	 * @return array{id: string, type: string, payload: array<string, mixed>, createdAt: int}
	 */
	protected function failed_event( string $reason, array $payload = array() ): array {
		return $this->item_event(
			'item.failed',
			$payload + array(
				'reason' => $reason,
				'via'    => 'integration',
			)
		);
	}

	/**
	 * An `item.submitted` envelope: ITEM_ID of ORDER_ID handed to its
	 * printshop, once its prints were done or again after a retry.
	 *
	 * @param array<string, mixed> $payload Payload fields to override.
	 * @return array{id: string, type: string, payload: array<string, mixed>, createdAt: int}
	 */
	protected function submitted_event( array $payload = array() ): array {
		return $this->item_event(
			'item.submitted',
			$payload + array(
				'printshopOrderId' => 'po_' . self::ITEM_ID,
				'externalOrderRef' => null,
			)
		);
	}

	/**
	 * An `order.cancelled` envelope for ORDER_ID.
	 *
	 * @param string $via Who asked for it: `store_key` for the store's own key, `member` for the studio.
	 * @return array{id: string, type: string, payload: array<string, mixed>, createdAt: int}
	 */
	protected function cancelled_event( string $via = 'member' ): array {
		return $this->order_event( 'order.cancelled', array( 'via' => $via ) );
	}

	/**
	 * An `item.cancelled` envelope for ITEM_ID of ORDER_ID.
	 *
	 * @param array<string, mixed> $payload Payload fields to override.
	 * @return array{id: string, type: string, payload: array<string, mixed>, createdAt: int}
	 */
	protected function item_cancelled_event( array $payload = array() ): array {
		return $this->item_event( 'item.cancelled', $payload + array( 'via' => 'member' ) );
	}

	/**
	 * An `item.*` envelope, about ITEM_ID of ORDER_ID unless $fields say otherwise.
	 *
	 * @param string               $type   Event type.
	 * @param array<string, mixed> $fields Payload fields beside, or replacing, the item's.
	 * @return array{id: string, type: string, payload: array<string, mixed>, createdAt: int}
	 */
	protected function item_event( string $type, array $fields ): array {
		$item_id = is_string( $fields['itemId'] ?? null ) ? $fields['itemId'] : self::ITEM_ID;
		return $this->order_event(
			$type,
			$fields + array(
				'itemId'          => $item_id,
				'itemExternalRef' => self::EXTERNAL_REF,
				'submissionId'    => 'sub_' . $item_id,
				'printIds'        => array( self::PRINT_ID ),
			)
		);
	}

	/**
	 * An `order.*` envelope, about ORDER_ID unless $fields say otherwise.
	 *
	 * @param string               $type   Event type.
	 * @param array<string, mixed> $fields Payload fields beside, or replacing, the order's.
	 * @return array{id: string, type: string, payload: array<string, mixed>, createdAt: int}
	 */
	protected function order_event( string $type, array $fields ): array {
		++$this->sequence;
		$created = time() * 1000 + $this->sequence;
		return array(
			'id'        => "{$created}-0f1e2d3c-4b5a-4968-8776-6554433221" . str_pad( (string) $this->sequence, 2, '0', STR_PAD_LEFT ),
			'type'      => $type,
			'payload'   => $fields + array(
				'orderId'     => self::ORDER_ID,
				'externalRef' => self::ORDER_EXTERNAL_REF,
			),
			'createdAt' => $created,
		);
	}

	/**
	 * Leaves tracking on the line as the `parcel.shipped` event Pixonaut
	 * sends before its items' `item.status_changed` to `shipped` does.
	 *
	 * @param WC_Order_Item_Product                                 $item     The line.
	 * @param array{carrier?: string, number: string, url?: string} $tracking The parcel's tracking.
	 */
	protected function parcel_shipped( WC_Order_Item_Product $item, array $tracking ): void {
		$line = LineState::of( $item );
		Tracking::set( $line, $tracking, Milliseconds::of( time() ) );
		$this->assertTrue( $line->save() );
	}

	/**
	 * `200` of `GET /v1/orders/{id}`: ORDER_ID, placed for the WooCommerce
	 * order under ORDER_EXTERNAL_REF, its item ITEM_ID at the last of $statuses.
	 *
	 * @param string[]             $statuses  The item's statuses so far, oldest first.
	 * @param array<string, mixed> $overrides Fields of the order to replace, e.g. `externalRef`.
	 * @phpstan-param non-empty-list<string> $statuses
	 * @return array<string, mixed>
	 */
	protected static function order_read( array $statuses, array $overrides = array() ): array {
		return $overrides + array( 'externalRef' => self::ORDER_EXTERNAL_REF ) + FulfilmentAnswers::order_read( self::ORDER_ID, self::PRINT_ID, self::EXTERNAL_REF, $statuses );
	}

	/**
	 * Delivers an envelope the spec describes, signed as Pixonaut signs it.
	 *
	 * @param array<string, mixed> $envelope  The event.
	 * @param int|null             $timestamp When it was sent; now when null.
	 * @param string               $secret    Signing secret.
	 */
	protected function deliver( array $envelope, ?int $timestamp = null, string $secret = self::SECRET ): WP_REST_Response {
		$body = (string) wp_json_encode( $envelope );
		OpenApiAssert::webhook( (string) $envelope['type'], $body );
		return $this->deliver_raw( $body, $timestamp, $secret );
	}

	/**
	 * Delivers an envelope the spec does not describe, e.g. a type or status
	 * a later Pixonaut adds.
	 *
	 * @param array<string, mixed> $envelope The event.
	 */
	protected function deliver_unchecked( array $envelope ): WP_REST_Response {
		return $this->deliver_raw( (string) wp_json_encode( $envelope ) );
	}

	/**
	 * @param string   $body      Request body.
	 * @param int|null $timestamp When it was sent; now when null.
	 * @param string   $secret    Signing secret.
	 */
	protected function deliver_raw( string $body, ?int $timestamp = null, string $secret = self::SECRET ): WP_REST_Response {
		$timestamp = (string) ( $timestamp ?? time() );
		$request   = new WP_REST_Request( 'POST', self::ROUTE );
		$request->set_header( 'content-type', 'application/json' );
		$request->set_header( Signature::TIMESTAMP_HEADER, $timestamp );
		$request->set_header( Signature::SIGNATURE_HEADER, 'v1=' . hash_hmac( 'sha256', "{$timestamp}.{$body}", $secret ) );
		$request->set_body( $body );
		return rest_do_request( $request );
	}

	/**
	 * @return list<int> Ids of the queued webhook jobs.
	 */
	protected function pending_jobs(): array {
		$ids = as_get_scheduled_actions(
			array(
				'hook'     => Processor::PROCESS_HOOK,
				'group'    => Processor::GROUP,
				'status'   => ActionScheduler_Store::STATUS_PENDING,
				'per_page' => -1,
				'orderby'  => 'action_id',
				'order'    => 'ASC',
			),
			'ids'
		);
		return array_map( 'intval', array_values( $ids ) );
	}

	/**
	 * Runs the queued webhook jobs, as Action Scheduler's runner would.
	 */
	protected function run_jobs(): void {
		foreach ( $this->pending_jobs() as $id ) {
			ActionScheduler::runner()->process_action( $id, 'pixonaut-test' );
		}
	}

	/**
	 * A processing order with one Pixonaut line, ordered at ORDER_ID as its
	 * item ITEM_ID and approved; or such a line added to `order`, ordered at
	 * `order_id` as its item `item_id`. An `item_id` of null makes a line
	 * placed before orders held items: it keeps no item id, and its order
	 * was placed under the line's own `externalRef`.
	 *
	 * @param array{quantity?: int, tenant?: string, order?: WC_Order, order_id?: string, item_id?: string|null, external_ref?: string} $options Line options.
	 */
	protected function pixonaut_line( array $options = array() ): WC_Order_Item_Product {
		$order = $options['order'] ?? new WC_Order();
		$item  = new WC_Order_Item_Product();
		$item->set_name( 'Personalised T-shirt' );
		$item->set_quantity( $options['quantity'] ?? 1 );
		$order->add_item( $item );
		$order->set_billing_email( 'ada@example.com' );
		$order->set_status( 'processing' );
		$order->save();

		$order_id = $options['order_id'] ?? self::ORDER_ID;
		$item_id  = array_key_exists( 'item_id', $options ) ? $options['item_id'] : FulfilmentAnswers::item_id( $order_id );
		$line     = LineState::start( $item, array( 'front' ) );
		$line->set_ref( LineState::REF_TENANT_ID, $options['tenant'] ?? self::TENANT_ID );
		$line->set_ref( LineState::REF_EXTERNAL_REF, $options['external_ref'] ?? self::EXTERNAL_REF );
		$line->set_ref( LineState::REF_ORDER_ID, $order_id );
		if ( null !== $item_id ) {
			$line->set_ref( LineState::REF_ORDER_ITEM_ID, $item_id );
			$line->set_ref( LineState::REF_ORDER_EXTERNAL_REF, self::ORDER_EXTERNAL_REF );
		}
		$line->save();
		$line->transition( LineState::APPROVED );
		return $item;
	}

	protected function order_of( WC_Order_Item_Product $item ): WC_Order {
		$order = wc_get_order( $item->get_order_id() );
		$this->assertInstanceOf( WC_Order::class, $order );
		return $order;
	}

	/**
	 * The order's notes, newest first, as one string.
	 *
	 * @param WC_Order_Item_Product $item A line of the order.
	 */
	protected function notes( WC_Order_Item_Product $item ): string {
		$notes = wc_get_order_notes( array( 'order_id' => $item->get_order_id() ) );
		return implode( "\n", array_map( static fn ( $note ): string => (string) $note->content, $notes ) );
	}

	protected static function connection(): StoredConnection {
		return new StoredConnection(
			'5c3a1f6e-8b2d-4e7a-9c1b-000000000001',
			Connection::site_url(),
			time(),
			self::TENANT_ID,
			'Acme',
			StoredConnection::LIVE,
			'sc_0b1c2d3e-4f50-4a6b-8c7d-000000000004',
			'ik_live_0123456789abcdef01234567',
			'0b1c2d3e-4f50-4a6b-8c7d-000000000005',
			'WooCommerce · Acme Shop',
			'pk_live_89abcdef0123456789abcdef',
			'0b1c2d3e-4f50-4a6b-8c7d-000000000006',
			array( 'http://example.org' ),
			'0b1c2d3e-4f50-4a6b-8c7d-000000000007',
			'http://example.org/wp-json/pixonaut/v1/webhook',
			self::SECRET
		);
	}
}
