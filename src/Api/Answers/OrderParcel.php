<?php
/**
 * A parcel of a printshop order, as Pixonaut answers it.
 *
 * @package Pixonaut\WooCommerce
 */

declare(strict_types=1);

namespace Pixonaut\WooCommerce\Api\Answers;

use Pixonaut\WooCommerce\Milliseconds;
use Pixonaut\WooCommerce\Vendor\Pixonaut\Sdk\Exception\UnexpectedResponse;

/**
 * An item of an order's `parcels`: what a printshop sent, with its tracking.
 */
final class OrderParcel {

	/**
	 * @param string             $id         `id`.
	 * @param string             $status     `status`: `preparing`, `shipped` or `delivered`.
	 * @param OrderTracking|null $tracking   `tracking`.
	 * @param string|null        $shipped_at `shippedAt`, an ISO 8601 time; null while it has not left.
	 * @param string[]           $item_ids   The `itemId` of each of its `items`.
	 * @phpstan-param list<string> $item_ids
	 */
	public function __construct(
		public readonly string $id,
		public readonly string $status,
		public readonly ?OrderTracking $tracking,
		public readonly ?string $shipped_at,
		public readonly array $item_ids,
	) {
	}

	/**
	 * @param array<string, mixed> $data The parcel.
	 * @param string               $path Where it is in the answer.
	 *
	 * @throws UnexpectedResponse When a field is missing or of another type.
	 */
	public static function from_answer( array $data, string $path ): self {
		$items = Read::objects( $data, 'items', $path, static fn ( array $item, string $at ): string => Read::string( $item, 'itemId', $at ) );
		return new self(
			id: Read::string( $data, 'id', $path ),
			status: Read::string( $data, 'status', $path ),
			tracking: Read::nullable_object( $data, 'tracking', $path, OrderTracking::from_answer( ... ) ),
			shipped_at: Read::nullable_string( $data, 'shippedAt', $path ),
			item_ids: $items,
		);
	}

	/**
	 * When it left.
	 *
	 * @return int|null Epoch milliseconds; null while it has not left, or for a time that does not read.
	 */
	public function shipped_at_ms(): ?int {
		$at = null === $this->shipped_at ? false : strtotime( $this->shipped_at );
		return false === $at ? null : Milliseconds::of( $at );
	}
}
