<?php
/**
 * An entry of a printshop order's history, as Pixonaut answers it.
 *
 * @package Pixonaut\WooCommerce
 */

declare(strict_types=1);

namespace Pixonaut\WooCommerce\Api\Answers;

use Pixonaut\WooCommerce\Vendor\Pixonaut\Sdk\Exception\UnexpectedResponse;

/**
 * An item of an order's `history`: one event of the order, oldest first.
 */
final class OrderHistoryEntry {

	/**
	 * @param float                $at         `at`, epoch milliseconds.
	 * @param string               $status     `status`, the order's after it.
	 * @param string               $type       `type`, the event's, e.g. `order.status_changed`.
	 * @param string|null          $via        `via`, who made it, e.g. `automation`.
	 * @param OrderAutomation|null $automation `automation`, the one that made it.
	 */
	public function __construct(
		public readonly float $at,
		public readonly string $status,
		public readonly string $type,
		public readonly ?string $via,
		public readonly ?OrderAutomation $automation,
	) {
	}

	/**
	 * @param array<string, mixed> $data The entry.
	 * @param string               $path Where it is in the answer.
	 *
	 * @throws UnexpectedResponse When a field is missing or of another type.
	 */
	public static function from_answer( array $data, string $path ): self {
		return new self(
			at: Read::float( $data, 'at', $path ),
			status: Read::string( $data, 'status', $path ),
			type: Read::string( $data, 'type', $path ),
			via: Read::nullable_string( $data, 'via', $path ),
			automation: Read::nullable_object( $data, 'automation', $path, OrderAutomation::from_answer( ... ) ),
		);
	}
}
