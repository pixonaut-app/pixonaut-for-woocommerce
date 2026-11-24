<?php
/**
 * An item of a Pixonaut order, as a parcel holds it.
 *
 * @package Pixonaut\WooCommerce
 */

declare(strict_types=1);

namespace Pixonaut\WooCommerce\Fulfilment;

/**
 * Which item, how many of its copies are in the parcel, how many it was
 * ordered with, and whether the parcel holds them all. Its line is the one
 * keeping the item's id.
 */
final class ParcelItem {

	/**
	 * @param string   $item_id     The order's item, as its line keeps it.
	 * @param int|null $quantity    Copies of the item in the parcel; null when the printshop did not say.
	 * @param int      $of_quantity Copies the item was ordered with.
	 * @param bool     $whole       Whether the parcel holds every copy.
	 */
	public function __construct(
		public readonly string $item_id,
		public readonly ?int $quantity,
		public readonly int $of_quantity,
		public readonly bool $whole,
	) {
	}

	/**
	 * An item of a payload's parcel.
	 *
	 * @param mixed $item One of the parcel's `items`.
	 * @return self|null Null when it names no item or its quantities are not counts.
	 */
	public static function from_payload( mixed $item ): ?self {
		if ( ! is_array( $item ) || ! is_string( $item['itemId'] ?? null ) || '' === $item['itemId'] || ! is_int( $item['ofQuantity'] ?? null ) || 1 > $item['ofQuantity'] ) {
			return null;
		}
		$quantity = $item['quantity'] ?? null;
		if ( null !== $quantity && ( ! is_int( $quantity ) || 1 > $quantity ) ) {
			return null;
		}
		$whole = $item['whole'] ?? false;
		return new self( $item['itemId'], $quantity, $item['ofQuantity'], true === $whole );
	}
}
