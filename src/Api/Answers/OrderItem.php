<?php
/**
 * An item of a printshop order, as Pixonaut answers it.
 *
 * @package Pixonaut\WooCommerce
 */

declare(strict_types=1);

namespace Pixonaut\WooCommerce\Api\Answers;

use Pixonaut\WooCommerce\Vendor\Pixonaut\Sdk\Exception\UnexpectedResponse;

/**
 * An item of an order's `items`: the physical item one order line buys,
 * with the status it has reached on its own. An order the store placed
 * holds one item per line; one placed before orders held items holds the
 * single item of the line it was placed for.
 */
final class OrderItem {

	/**
	 * @param string            $id                      `id`.
	 * @param string|null       $external_ref            `externalRef`, the line's ref it was placed under.
	 * @param string            $status                  `status`: `awaiting_prints`, `submitted`, `held`, `failed`, ….
	 * @param int               $quantity                `quantity`.
	 * @param string|null       $variant_ref             `variantRef`, the variant it names.
	 * @param string|null       $printshop_connection_id `printshopConnectionId`.
	 * @param OrderPrint[]      $prints                  `prints`, the lead first.
	 * @param string|null       $last_error              `lastError`.
	 * @param bool              $can_retry               `canRetry`: whether a retry is allowed now.
	 * @param string[]          $status_moves            `statusMoves`, the statuses it may be moved to.
	 * @param OrderSubmission[] $submissions             `submissions`, oldest first.
	 * @phpstan-param list<OrderPrint> $prints
	 * @phpstan-param list<string> $status_moves
	 * @phpstan-param list<OrderSubmission> $submissions
	 */
	public function __construct(
		public readonly string $id,
		public readonly ?string $external_ref,
		public readonly string $status,
		public readonly int $quantity,
		public readonly ?string $variant_ref,
		public readonly ?string $printshop_connection_id,
		public readonly array $prints,
		public readonly ?string $last_error,
		public readonly bool $can_retry,
		public readonly array $status_moves,
		public readonly array $submissions,
	) {
	}

	/**
	 * The printshop order its latest submission went to.
	 *
	 * @return string|null Null while no submission reached a printshop order.
	 */
	public function printshop_order_id(): ?string {
		foreach ( array_reverse( $this->submissions ) as $submission ) {
			if ( ! $submission->struck && null !== $submission->printshop_order_id ) {
				return $submission->printshop_order_id;
			}
		}
		return null;
	}

	/**
	 * @param array<string, mixed> $data The item.
	 * @param string               $path Where it is in the answer.
	 *
	 * @throws UnexpectedResponse When a field is missing or of another type.
	 */
	public static function from_answer( array $data, string $path ): self {
		return new self(
			id: Read::string( $data, 'id', $path ),
			external_ref: Read::nullable_string( $data, 'externalRef', $path ),
			status: Read::string( $data, 'status', $path ),
			quantity: Read::int( $data, 'quantity', $path ),
			variant_ref: Read::nullable_string( $data, 'variantRef', $path ),
			printshop_connection_id: Read::nullable_string( $data, 'printshopConnectionId', $path ),
			prints: Read::objects( $data, 'prints', $path, OrderPrint::from_answer( ... ) ),
			last_error: Read::nullable_string( $data, 'lastError', $path ),
			can_retry: Read::bool( $data, 'canRetry', $path ),
			status_moves: Read::strings( $data, 'statusMoves', $path ),
			submissions: Read::objects( $data, 'submissions', $path, OrderSubmission::from_answer( ... ) ),
		);
	}
}
