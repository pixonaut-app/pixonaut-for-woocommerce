<?php
/**
 * A printshop order read on its own, as the store reads it from Pixonaut.
 *
 * @package Pixonaut\WooCommerce
 */

declare(strict_types=1);

namespace Pixonaut\WooCommerce\Api\Answers;

use Pixonaut\WooCommerce\Vendor\Pixonaut\Sdk\Exception\ApiException;
use Pixonaut\WooCommerce\Vendor\Pixonaut\Sdk\Exception\UnexpectedResponse;
use Pixonaut\WooCommerce\Vendor\Pixonaut\Sdk\Pixonaut as Sdk;

/**
 * The fields of `GET /v1/orders/{id}` the store reads: those every answer
 * about an order has, as `$order`, and its history and what the store may
 * do with it now, which only this read answers.
 */
final class OrderDetail {

	/**
	 * @param Order               $order        The fields every answer about an order has, read from the same object.
	 * @param OrderHistoryEntry[] $history      `history`, oldest first.
	 * @param string[]            $status_moves `statusMoves`, the statuses the store may move it to now.
	 * @param bool                $can_retry    `canRetry`, whether the store may retry it now.
	 * @phpstan-param list<OrderHistoryEntry> $history
	 * @phpstan-param list<string> $status_moves
	 */
	public function __construct(
		public readonly Order $order,
		public readonly array $history,
		public readonly array $status_moves,
		public readonly bool $can_retry,
	) {
	}

	/**
	 * `GET /v1/orders/{id}`.
	 *
	 * @param Sdk    $pixonaut The SDK.
	 * @param string $id       The order.
	 *
	 * @throws ApiException When Pixonaut refused (`order_not_found`, …), could not be reached, or answered without a field the store reads.
	 */
	public static function get( Sdk $pixonaut, string $id ): self {
		return Read::answer( $pixonaut->call( 'orders.get', array( 'id' => $id ) ), self::from_answer( ... ) );
	}

	/**
	 * @param array<string, mixed> $data The answer.
	 * @param string               $path Where it is in the answer.
	 *
	 * @throws UnexpectedResponse When a field is missing or of another type.
	 */
	public static function from_answer( array $data, string $path = '$' ): self {
		return new self(
			order: Order::from_answer( $data, $path ),
			history: Read::objects( $data, 'history', $path, OrderHistoryEntry::from_answer( ... ) ),
			status_moves: Read::strings( $data, 'statusMoves', $path ),
			can_retry: Read::bool( $data, 'canRetry', $path ),
		);
	}
}
