<?php
/**
 * Printshop orders placed through Pixonaut.
 *
 * @package Pixonaut\WooCommerce
 */

declare(strict_types=1);

namespace Pixonaut\WooCommerce\Api;

final class Orders {

	public function __construct( private readonly Client $client ) {
	}

	/**
	 * The `POST /v1/orders` request, to send with `create()` and keep, so a
	 * later attempt sends the same bytes under the same key. A blank decides
	 * its printshop; only an order on no blank names one.
	 *
	 * @param array<string, mixed> $body            `prints` or `designs` (lead first), `quantity`, `externalRef`, `recipient`, `shippingAddress`, and `printshopId` on no blank.
	 * @param string               $idempotency_key The order's key.
	 */
	public function prepare_create( array $body, string $idempotency_key ): PreparedRequest {
		return $this->client->prepare( Operations::CREATE_ORDER, array(), array(), $body, $idempotency_key );
	}

	/**
	 * Sends a `POST /v1/orders` request from `prepare_create()`.
	 *
	 * @param PreparedRequest $request The request, as prepared or restored.
	 *
	 * @return array<string, mixed> The order: `id`, `status`, `printIds`, …
	 *
	 * @throws ApiError When Pixonaut refused or could not be reached.
	 */
	public function create( PreparedRequest $request ): array {
		return $this->client->json( Operations::CREATE_ORDER, $this->client->send( $request ) );
	}

	/**
	 * `GET /v1/orders?externalRef=`: the store's orders placed under a ref.
	 *
	 * @param string $external_ref The ref.
	 *
	 * @return list<array<string, mixed>>
	 *
	 * @throws ApiError When Pixonaut refused or could not be reached.
	 */
	public function with_external_ref( string $external_ref ): array {
		$response = $this->client->call( Operations::LIST_ORDERS, array(), array( 'externalRef' => $external_ref ) );
		return $this->client->items( Operations::LIST_ORDERS, $response, 'orders' );
	}

	/**
	 * `GET /v1/orders/{id}`: the order with its history.
	 *
	 * @param string $order_id The order.
	 *
	 * @return array<string, mixed>
	 *
	 * @throws ApiError When Pixonaut refused or could not be reached.
	 */
	public function get( string $order_id ): array {
		return $this->client->json( Operations::GET_ORDER, $this->client->call( Operations::GET_ORDER, array( 'id' => $order_id ) ) );
	}

	/**
	 * `PATCH /v1/orders/{id}`: moves the order to a status its
	 * `statusMoves` allow, e.g. settling one whose outcome is unknown.
	 *
	 * @param string               $order_id The order.
	 * @param array<string, mixed> $body     `status`, and `reason`, `externalOrderRef` or `tracking` where the status takes them.
	 *
	 * @return array<string, mixed> The order as it is now.
	 *
	 * @throws ApiError When Pixonaut refused the move or could not be reached.
	 */
	public function update_status( string $order_id, array $body ): array {
		return $this->client->json( Operations::UPDATE_ORDER, $this->client->call( Operations::UPDATE_ORDER, array( 'id' => $order_id ), array(), $body ) );
	}

	/**
	 * `POST /v1/orders/{id}/retry`: places a new order for a failed one's
	 * prints, at the printshop the failed one went to.
	 *
	 * @param string $order_id The failed order.
	 *
	 * @return array<string, mixed> The new order.
	 *
	 * @throws ApiError When Pixonaut refused, e.g. `order_superseded` with the `newerOrderId` an earlier retry placed.
	 */
	public function retry( string $order_id ): array {
		return $this->client->json( Operations::RETRY_ORDER, $this->client->call( Operations::RETRY_ORDER, array( 'id' => $order_id ), array(), array() ) );
	}

	/**
	 * `POST /v1/orders/{id}/cancel`: asks the order's printshop to stop it.
	 *
	 * @param string $order_id The order.
	 *
	 * @return array<string, mixed> The order, `cancelled`.
	 *
	 * @throws ApiError When the printshop has it already, or Pixonaut refused or could not be reached.
	 */
	public function cancel( string $order_id ): array {
		return $this->client->json( Operations::CANCEL_ORDER, $this->client->call( Operations::CANCEL_ORDER, array( 'id' => $order_id ) ) );
	}

	/**
	 * `POST /v1/orders/{id}/erase-personal-data`: erases the order's
	 * recipient, shipping address and failure reasons. Refused
	 * `order_in_flight` until the order has shipped, failed or been
	 * cancelled. Erasing it again answers it unchanged.
	 *
	 * @param string $order_id The order.
	 *
	 * @return array<string, mixed> The order, `personalDataErasedAt` set.
	 *
	 * @throws ApiError When Pixonaut refused (`order_in_flight`, `order_not_found`, …) or could not be reached.
	 */
	public function erase_personal_data( string $order_id ): array {
		return $this->client->json( Operations::ERASE_ORDER, $this->client->call( Operations::ERASE_ORDER, array( 'id' => $order_id ) ) );
	}

	/**
	 * The `PATCH /v1/orders/{id}/delivery` request, to send with
	 * `send_change_delivery()` and keep, so a retry of the change sends the
	 * same bytes under the same key.
	 *
	 * @param string               $order_id        The order.
	 * @param array<string, mixed> $body            `recipient` and `shippingAddress`, as `POST /v1/orders` takes them.
	 * @param string               $idempotency_key The change's key.
	 */
	public function prepare_change_delivery( string $order_id, array $body, string $idempotency_key ): PreparedRequest {
		return $this->client->prepare( Operations::CHANGE_ORDER_DELIVERY, array( 'id' => $order_id ), array(), $body, $idempotency_key );
	}

	/**
	 * Sends a `PATCH /v1/orders/{id}/delivery` request from
	 * `prepare_change_delivery()`: gives an order that was sent a new
	 * recipient and shipping address, which Pixonaut passes on to its
	 * printshop.
	 *
	 * @param PreparedRequest $request The request, as prepared or restored.
	 *
	 * @return array<string, mixed> The order, its `delivery` saying what the printshop made of the change.
	 *
	 * @throws ApiError When Pixonaut refused (`order_past_address_change`, `address_change_not_accepted`, …) or could not be reached.
	 */
	public function send_change_delivery( PreparedRequest $request ): array {
		return $this->client->json( Operations::CHANGE_ORDER_DELIVERY, $this->client->send( $request ) );
	}
}
