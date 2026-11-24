<?php
/**
 * A submission of an order item to a printshop, as Pixonaut answers it.
 *
 * @package Pixonaut\WooCommerce
 */

declare(strict_types=1);

namespace Pixonaut\WooCommerce\Api\Answers;

use Pixonaut\WooCommerce\Vendor\Pixonaut\Sdk\Exception\UnexpectedResponse;

/**
 * An item of an order item's `submissions`: one time it was sent to a
 * printshop. A retry or a reroute strikes the one it replaces.
 */
final class OrderSubmission {

	/**
	 * @param string      $id                 `id`.
	 * @param string|null $printshop_order_id `printshopOrderId`; null while it is not in a printshop order.
	 * @param bool        $struck             `struck`: replaced by a later submission.
	 */
	public function __construct(
		public readonly string $id,
		public readonly ?string $printshop_order_id,
		public readonly bool $struck,
	) {
	}

	/**
	 * @param array<string, mixed> $data The submission.
	 * @param string               $path Where it is in the answer.
	 *
	 * @throws UnexpectedResponse When a field is missing or of another type.
	 */
	public static function from_answer( array $data, string $path ): self {
		return new self(
			id: Read::string( $data, 'id', $path ),
			printshop_order_id: Read::nullable_string( $data, 'printshopOrderId', $path ),
			struck: Read::bool( $data, 'struck', $path ),
		);
	}
}
