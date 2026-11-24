<?php
/**
 * A printshop order, as the store reads it from Pixonaut.
 *
 * @package Pixonaut\WooCommerce
 */

declare(strict_types=1);

namespace Pixonaut\WooCommerce\Api\Answers;

use InvalidArgumentException;
use Pixonaut\WooCommerce\Vendor\Pixonaut\Sdk\Exception\ApiException;
use Pixonaut\WooCommerce\Vendor\Pixonaut\Sdk\Exception\UnexpectedResponse;
use Pixonaut\WooCommerce\Vendor\Pixonaut\Sdk\Generated\Model\OrdersPrintshopOrdersUpdateRequest;
use Pixonaut\WooCommerce\Vendor\Pixonaut\Sdk\Http\PreparedRequest;
use Pixonaut\WooCommerce\Vendor\Pixonaut\Sdk\Pixonaut as Sdk;

/**
 * The fields the store reads of an order as Pixonaut answers it: the answer
 * to placing, retrying, moving, cancelling or re-addressing one.
 * `GET /v1/orders/{id}` answers the same fields and more (OrderDetail).
 *
 * An order holds an item per order line, each with a status of its own; the
 * order's `status` sums them up (`open`, `partly_shipped`, …).
 */
final class Order {

	/**
	 * @param string                    $id                      `id`.
	 * @param string                    $status                  `status`, the whole order's: `held`, `open`, `partly_shipped`, `shipped`, `needs_attention`, `cancelled` or `done`.
	 * @param string|null               $external_ref            `externalRef`, the ref it was placed under.
	 * @param OrderItem[]               $items                   `items`, in the order they were placed.
	 * @param OrderParcel[]             $parcels                 `parcels`.
	 * @param OrderRecipient|null       $recipient               `recipient`, its customer.
	 * @param OrderShippingAddress|null $shipping_address        `shippingAddress`, the customer's address.
	 * @param OrderShipTo|null          $ship_to                 `shipTo`, the workspace's saved address it ships to instead.
	 * @param OrderHoldTerms|null       $hold                    `hold`, while it is held.
	 * @param OrderDelivery|null        $delivery                `delivery`, its latest address change.
	 * @param float|null                $personal_data_erased_at `personalDataErasedAt`, epoch milliseconds; null while not erased.
	 * @param array<string, mixed>      $as_sent                 The answer as Pixonaut sent it, only for the public `pixonaut_wc_fulfilment_line_actions` filter, which hands third parties the order as an array.
	 * @phpstan-param list<OrderItem> $items
	 * @phpstan-param list<OrderParcel> $parcels
	 */
	public function __construct(
		public readonly string $id,
		public readonly string $status,
		public readonly ?string $external_ref,
		public readonly array $items,
		public readonly array $parcels,
		public readonly ?OrderRecipient $recipient,
		public readonly ?OrderShippingAddress $shipping_address,
		public readonly ?OrderShipTo $ship_to,
		public readonly ?OrderHoldTerms $hold,
		public readonly ?OrderDelivery $delivery,
		public readonly ?float $personal_data_erased_at,
		public readonly array $as_sent,
	) {
	}

	/**
	 * The item placed for an order line.
	 *
	 * @param string|null $item_id The item id the line keeps; null when it keeps none.
	 * @param string|null $ref     The line's ref, which the item was placed under.
	 *
	 * @return OrderItem|null Null when the order holds no such item. An order placed for one line before orders held items holds that line's item alone, whatever its ref.
	 */
	public function item( ?string $item_id, ?string $ref ): ?OrderItem {
		foreach ( $this->items as $item ) {
			if ( null !== $item_id && $item->id === $item_id ) {
				return $item;
			}
		}
		if ( null !== $item_id ) {
			return null;
		}
		foreach ( $this->items as $item ) {
			if ( null !== $ref && '' !== $ref && $item->external_ref === $ref ) {
				return $item;
			}
		}
		return 1 === count( $this->items ) ? $this->items[0] : null;
	}

	/**
	 * The latest parcel holding an item that has left with tracking.
	 *
	 * @param string $item_id The item.
	 */
	public function shipped_parcel_of( string $item_id ): ?OrderParcel {
		$latest = null;
		foreach ( $this->parcels as $parcel ) {
			if ( null === $parcel->tracking || null === $parcel->shipped_at_ms() || ! in_array( $item_id, $parcel->item_ids, true ) ) {
				continue;
			}
			if ( null === $latest || $parcel->shipped_at_ms() >= $latest->shipped_at_ms() ) {
				$latest = $parcel;
			}
		}
		return $latest;
	}

	/**
	 * `POST /v1/orders`, sending a request kept from `prepareCreate()`.
	 *
	 * @param Sdk             $pixonaut The SDK.
	 * @param PreparedRequest $request  The request, as prepared or restored.
	 *
	 * @throws ApiException When Pixonaut refused the order, could not be reached, or answered without a field the store reads.
	 * @throws InvalidArgumentException When the request was prepared for another operation.
	 */
	public static function create( Sdk $pixonaut, PreparedRequest $request ): self {
		return Read::answer( $pixonaut->send( $request->expect( 'orders.create' ) ), self::from_answer( ... ) );
	}

	/**
	 * `POST /v1/orders/{id}/items/{itemId}/retry`, to the printshop it went to.
	 *
	 * @param Sdk    $pixonaut The SDK.
	 * @param string $id       The order.
	 * @param string $item_id  Its failed item.
	 *
	 * @throws ApiException When Pixonaut refused, could not be reached, or answered without a field the store reads.
	 */
	public static function retry( Sdk $pixonaut, string $id, string $item_id ): self {
		return Read::answer(
			$pixonaut->call(
				'orders.items.retry',
				array(
					'id'     => $id,
					'itemId' => $item_id,
				)
			),
			self::from_answer( ... )
		);
	}

	/**
	 * `PATCH /v1/orders/{id}/printshop-orders/{printshopOrderId}`: moves the
	 * items of one printshop order.
	 *
	 * @param Sdk                                $pixonaut           The SDK.
	 * @param string                             $id                 The order.
	 * @param string                             $printshop_order_id Its printshop order.
	 * @param OrdersPrintshopOrdersUpdateRequest $body               The status to move it to, and why.
	 *
	 * @throws ApiException When Pixonaut refused, could not be reached, or answered without a field the store reads.
	 */
	public static function update_printshop_order( Sdk $pixonaut, string $id, string $printshop_order_id, OrdersPrintshopOrdersUpdateRequest $body ): self {
		return Read::answer(
			$pixonaut->call(
				'orders.printshopOrders.update',
				array(
					'id'               => $id,
					'printshopOrderId' => $printshop_order_id,
				),
				body: $body->toArray()
			),
			self::from_answer( ... )
		);
	}

	/**
	 * `POST /v1/orders/{id}/items/{itemId}/cancel`.
	 *
	 * @param Sdk    $pixonaut The SDK.
	 * @param string $id       The order.
	 * @param string $item_id  The item to cancel.
	 *
	 * @throws ApiException When the printshop could not stop it, Pixonaut refused, could not be reached, or answered without a field the store reads.
	 */
	public static function cancel_item( Sdk $pixonaut, string $id, string $item_id ): self {
		return Read::answer(
			$pixonaut->call(
				'orders.items.cancel',
				array(
					'id'     => $id,
					'itemId' => $item_id,
				)
			),
			self::from_answer( ... )
		);
	}

	/**
	 * `POST /v1/orders/{id}/cancel`.
	 *
	 * @param Sdk    $pixonaut The SDK.
	 * @param string $id       The order.
	 *
	 * @throws ApiException When the printshop could not stop it, Pixonaut refused, could not be reached, or answered without a field the store reads.
	 */
	public static function cancel( Sdk $pixonaut, string $id ): self {
		return Read::answer( $pixonaut->call( 'orders.cancel', array( 'id' => $id ) ), self::from_answer( ... ) );
	}

	/**
	 * `PATCH /v1/orders/{id}/delivery`, sending a request kept from `prepareUpdateDelivery()`.
	 *
	 * @param Sdk             $pixonaut The SDK.
	 * @param PreparedRequest $request  The request, as prepared or restored.
	 *
	 * @throws ApiException When Pixonaut refused the change, could not be reached, or answered without a field the store reads.
	 * @throws InvalidArgumentException When the request was prepared for another operation.
	 */
	public static function update_delivery( Sdk $pixonaut, PreparedRequest $request ): self {
		return Read::answer( $pixonaut->send( $request->expect( 'orders.delivery.update' ) ), self::from_answer( ... ) );
	}

	/**
	 * @param array<string, mixed> $data The order.
	 * @param string               $path Where it is in the answer.
	 *
	 * @throws UnexpectedResponse When a field is missing or of another type.
	 */
	public static function from_answer( array $data, string $path = '$' ): self {
		return new self(
			id: Read::string( $data, 'id', $path ),
			status: Read::string( $data, 'status', $path ),
			external_ref: Read::nullable_string( $data, 'externalRef', $path ),
			items: Read::objects( $data, 'items', $path, OrderItem::from_answer( ... ) ),
			parcels: Read::objects( $data, 'parcels', $path, OrderParcel::from_answer( ... ) ),
			recipient: Read::nullable_object( $data, 'recipient', $path, OrderRecipient::from_answer( ... ) ),
			shipping_address: Read::nullable_object( $data, 'shippingAddress', $path, OrderShippingAddress::from_answer( ... ) ),
			ship_to: Read::nullable_object( $data, 'shipTo', $path, OrderShipTo::from_answer( ... ) ),
			hold: Read::nullable_object( $data, 'hold', $path, OrderHoldTerms::from_answer( ... ) ),
			delivery: Read::nullable_object( $data, 'delivery', $path, OrderDelivery::from_answer( ... ) ),
			personal_data_erased_at: Read::nullable_float( $data, 'personalDataErasedAt', $path ),
			as_sent: $data,
		);
	}
}
