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
use Pixonaut\WooCommerce\Vendor\Pixonaut\Sdk\Generated\Model\OrdersRetryRequest;
use Pixonaut\WooCommerce\Vendor\Pixonaut\Sdk\Generated\Model\OrdersUpdateRequest;
use Pixonaut\WooCommerce\Vendor\Pixonaut\Sdk\Http\PreparedRequest;
use Pixonaut\WooCommerce\Vendor\Pixonaut\Sdk\Pixonaut as Sdk;

/**
 * The fields the store reads of an order as Pixonaut answers it: an item of
 * `GET /v1/orders`, and the answer to placing, retrying, moving, cancelling
 * or re-addressing one. `GET /v1/orders/{id}` answers the same fields and
 * more (OrderDetail).
 */
final class Order {

	/**
	 * @param string                    $id                      `id`.
	 * @param string                    $status                  `status`: `awaiting_prints`, `submitted`, `held`, `failed`, ….
	 * @param string|null               $external_ref            `externalRef`, the ref it was placed under.
	 * @param int                       $quantity                `quantity`.
	 * @param string|null               $variant_ref             `variantRef`, the variant it names.
	 * @param string|null               $printshop_connection_id `printshopConnectionId`.
	 * @param OrderPrint[]              $prints                  `prints`, the lead first.
	 * @param OrderRecipient|null       $recipient               `recipient`, its customer.
	 * @param OrderShippingAddress|null $shipping_address        `shippingAddress`, the customer's address.
	 * @param OrderShipTo|null          $ship_to                 `shipTo`, the workspace's saved address it ships to instead.
	 * @param OrderTracking|null        $tracking                `tracking`.
	 * @param OrderHoldTerms|null       $hold                    `hold`, while it is held.
	 * @param OrderDelivery|null        $delivery                `delivery`, its latest address change.
	 * @param string|null               $last_error              `lastError`.
	 * @param string|null               $replaced_by_order_id    `replacedByOrderId`, the order a retry placed for it.
	 * @param float|null                $personal_data_erased_at `personalDataErasedAt`, epoch milliseconds; null while not erased.
	 * @param array<string, mixed>      $as_sent                 The answer as Pixonaut sent it, only for the public `pixonaut_wc_fulfilment_line_actions` filter, which hands third parties the order as an array.
	 * @phpstan-param list<OrderPrint> $prints
	 */
	public function __construct(
		public readonly string $id,
		public readonly string $status,
		public readonly ?string $external_ref,
		public readonly int $quantity,
		public readonly ?string $variant_ref,
		public readonly ?string $printshop_connection_id,
		public readonly array $prints,
		public readonly ?OrderRecipient $recipient,
		public readonly ?OrderShippingAddress $shipping_address,
		public readonly ?OrderShipTo $ship_to,
		public readonly ?OrderTracking $tracking,
		public readonly ?OrderHoldTerms $hold,
		public readonly ?OrderDelivery $delivery,
		public readonly ?string $last_error,
		public readonly ?string $replaced_by_order_id,
		public readonly ?float $personal_data_erased_at,
		public readonly array $as_sent,
	) {
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
	 * `POST /v1/orders/{id}/retry`, to the printshop it went to.
	 *
	 * @param Sdk    $pixonaut The SDK.
	 * @param string $id       The failed order.
	 *
	 * @throws ApiException When Pixonaut refused, could not be reached, or answered without a field the store reads.
	 */
	public static function retry( Sdk $pixonaut, string $id ): self {
		return Read::answer( $pixonaut->call( 'orders.retry', array( 'id' => $id ), body: ( new OrdersRetryRequest() )->toArray() ), self::from_answer( ... ) );
	}

	/**
	 * `PATCH /v1/orders/{id}`.
	 *
	 * @param Sdk                 $pixonaut The SDK.
	 * @param string              $id       The order.
	 * @param OrdersUpdateRequest $body     The status to move it to, and why.
	 *
	 * @throws ApiException When Pixonaut refused, could not be reached, or answered without a field the store reads.
	 */
	public static function update( Sdk $pixonaut, string $id, OrdersUpdateRequest $body ): self {
		return Read::answer( $pixonaut->call( 'orders.update', array( 'id' => $id ), body: $body->toArray() ), self::from_answer( ... ) );
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
			quantity: Read::int( $data, 'quantity', $path ),
			variant_ref: Read::nullable_string( $data, 'variantRef', $path ),
			printshop_connection_id: Read::nullable_string( $data, 'printshopConnectionId', $path ),
			prints: Read::objects( $data, 'prints', $path, OrderPrint::from_answer( ... ) ),
			recipient: Read::nullable_object( $data, 'recipient', $path, OrderRecipient::from_answer( ... ) ),
			shipping_address: Read::nullable_object( $data, 'shippingAddress', $path, OrderShippingAddress::from_answer( ... ) ),
			ship_to: Read::nullable_object( $data, 'shipTo', $path, OrderShipTo::from_answer( ... ) ),
			tracking: Read::nullable_object( $data, 'tracking', $path, OrderTracking::from_answer( ... ) ),
			hold: Read::nullable_object( $data, 'hold', $path, OrderHoldTerms::from_answer( ... ) ),
			delivery: Read::nullable_object( $data, 'delivery', $path, OrderDelivery::from_answer( ... ) ),
			last_error: Read::nullable_string( $data, 'lastError', $path ),
			replaced_by_order_id: Read::nullable_string( $data, 'replacedByOrderId', $path ),
			personal_data_erased_at: Read::nullable_float( $data, 'personalDataErasedAt', $path ),
			as_sent: $data,
		);
	}
}
