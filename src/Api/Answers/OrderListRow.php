<?php
/**
 * An order of `GET /v1/orders`, as the store reads it from Pixonaut.
 *
 * @package Pixonaut\WooCommerce
 */

declare(strict_types=1);

namespace Pixonaut\WooCommerce\Api\Answers;

use Pixonaut\WooCommerce\Vendor\Pixonaut\Sdk\Exception\UnexpectedResponse;

/**
 * The fields of an order in a list the store reads: enough to find the
 * order a line's item is in, which `GET /v1/orders/{id}` then reads whole.
 */
final class OrderListRow {

	/**
	 * @param string                     $id        `id`.
	 * @param array<string, string|null> $item_refs Each of its `items`' `externalRef`, by item `id`.
	 */
	public function __construct(
		public readonly string $id,
		public readonly array $item_refs,
	) {
	}

	/**
	 * The id of its item placed under a line's ref.
	 *
	 * @param string $ref       The line's ref.
	 * @param bool   $line_only Whether the order was listed under the line's own ref, so was placed for the line alone before orders held items: its one item is the line's, whatever its ref.
	 *
	 * @return string|null Null when it holds none.
	 */
	public function item_under( string $ref, bool $line_only = false ): ?string {
		$found = array_search( $ref, $this->item_refs, true );
		if ( false !== $found ) {
			return (string) $found;
		}
		return $line_only && 1 === count( $this->item_refs ) ? (string) array_key_first( $this->item_refs ) : null;
	}

	/**
	 * @param array<string, mixed> $data The order.
	 * @param string               $path Where it is in the answer.
	 *
	 * @throws UnexpectedResponse When a field is missing or of another type.
	 */
	public static function from_answer( array $data, string $path ): self {
		$items = Read::objects(
			$data,
			'items',
			$path,
			static fn ( array $item, string $at ): array => array( Read::string( $item, 'id', $at ), Read::nullable_string( $item, 'externalRef', $at ) )
		);
		return new self(
			id: Read::string( $data, 'id', $path ),
			item_refs: array_column( $items, 1, 0 ),
		);
	}
}
