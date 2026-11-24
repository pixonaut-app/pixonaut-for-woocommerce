<?php
/**
 * A line of the store as a parcel holds it.
 *
 * @package Pixonaut\WooCommerce
 */

declare(strict_types=1);

namespace Pixonaut\WooCommerce\Fulfilment;

use WC_Order_Item_Product;

/**
 * What a customer email about a parcel lists: the order line, how many of
 * its copies are in the parcel, how many it was ordered with, whether the
 * parcel holds them all, and, when it does not, whether copies are still to
 * come or went in parcels before.
 */
final class ParcelLine {

	/**
	 * @param WC_Order_Item_Product $item         The order line.
	 * @param int|null              $quantity     Copies in the parcel; null when the printshop did not say.
	 * @param int                   $of_quantity  Copies ordered.
	 * @param bool                  $whole        Whether the parcel holds every copy.
	 * @param bool                  $rest_to_come Whether copies the parcel does not hold have yet to ship.
	 */
	public function __construct(
		public readonly WC_Order_Item_Product $item,
		public readonly ?int $quantity,
		public readonly int $of_quantity,
		public readonly bool $whole,
		public readonly bool $rest_to_come,
	) {
	}

	/**
	 * The lines a parcel holds, as they stand: a line split over parcels
	 * that has shipped has none of its copies still to come.
	 *
	 * @param array<array{0: LineState, 1: ParcelItem}> $held Each line with its item in the parcel.
	 * @return list<self>
	 */
	public static function all( array $held ): array {
		$lines = array();
		foreach ( $held as [ $line, $item ] ) {
			$shipped = LineState::SHIPPED === LineState::of( $line->item() )->state();
			$lines[] = new self( $line->item(), $item->quantity, $item->of_quantity, $item->whole, ! $item->whole && ! $shipped );
		}
		return $lines;
	}
}
