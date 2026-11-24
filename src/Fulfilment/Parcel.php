<?php
/**
 * One shipment from a printshop, as `parcel.*` events carry it.
 *
 * @package Pixonaut\WooCommerce
 */

declare(strict_types=1);

namespace Pixonaut\WooCommerce\Fulfilment;

/**
 * A parcel holds items of one Pixonaut order, each with how many of its
 * copies are in it, when the printshop says: a printshop may split an
 * item's copies over parcels.
 * Its id and items never change once it shipped; its tracking may come
 * later, or be corrected.
 */
final class Parcel {

	/**
	 * @param string                                                     $id       The parcel's id.
	 * @param array{carrier?: string, number: string, url?: string}|null $tracking Its tracking; null when it shipped without any.
	 * @param ParcelItem[]                                               $items    What it holds.
	 * @phpstan-param list<ParcelItem> $items
	 */
	public function __construct(
		public readonly string $id,
		public readonly ?array $tracking,
		public readonly array $items,
	) {
	}

	/**
	 * The parcel of an event's payload, sanitised.
	 *
	 * @param mixed $parcel The payload's `parcel`.
	 * @return self|null Null when it has no id or no item.
	 */
	public static function from_payload( mixed $parcel ): ?self {
		if ( ! is_array( $parcel ) || ! is_string( $parcel['id'] ?? null ) || '' === $parcel['id'] || ! is_array( $parcel['items'] ?? null ) ) {
			return null;
		}
		$items = array();
		foreach ( $parcel['items'] as $item ) {
			$read = ParcelItem::from_payload( $item );
			if ( null !== $read ) {
				$items[] = $read;
			}
		}
		if ( array() === $items ) {
			return null;
		}
		return new self( sanitize_text_field( $parcel['id'] ), Tracking::from_payload( $parcel['tracking'] ?? null ), $items );
	}
}
