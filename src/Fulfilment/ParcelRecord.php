<?php
/**
 * What the store did about a parcel of an order.
 *
 * @package Pixonaut\WooCommerce
 */

declare(strict_types=1);

namespace Pixonaut\WooCommerce\Fulfilment;

use Pixonaut\WooCommerce\Schema;
use WC_Order;

/**
 * Kept in the WooCommerce order's meta, one key per parcel, and written only
 * under the order's ParcelLock: whether the customer was told
 * the parcel shipped, how many copies of each line it held, whether its
 * delivery was noted, and the parcel's tracking with when the event that set
 * it was created, so an older event never replaces it and a delivery again
 * tells no one twice.
 */
final class ParcelRecord {

	/**
	 * Whether `parcel.shipped` was handled to the end.
	 */
	private const SHIPPED = 'shipped';

	private const TRACKING = 'tracking';

	/**
	 * Copies of each line the parcel held, by the line's order item id; a
	 * count the printshop did not give is null.
	 */
	private const QUANTITIES = 'quantities';

	/**
	 * Whether the parcel's delivery was noted on the order.
	 */
	private const DELIVERED = 'delivered';

	/**
	 * When the event that set the tracking was created, epoch milliseconds.
	 */
	private const TRACKING_AT = 'trackingAt';

	/**
	 * Set while the customer is still to be emailed about the parcel's new
	 * tracking, so handling the event again after a run that stopped emails
	 * them.
	 */
	private const TRACKING_NOTICE = 'trackingNotice';

	/**
	 * @param WC_Order             $order  The order.
	 * @param string               $id     The parcel's id.
	 * @param array<string, mixed> $fields The stored fields.
	 */
	private function __construct(
		private readonly WC_Order $order,
		private readonly string $id,
		private array $fields,
	) {
	}

	/**
	 * The record of a parcel, empty when the store has done nothing about it.
	 *
	 * @param WC_Order $order The order.
	 * @param string   $id    The parcel's id.
	 */
	public static function of( WC_Order $order, string $id ): self {
		$fields = $order->get_meta( Schema::PARCEL_META_PREFIX . $id );
		return new self( $order, $id, is_array( $fields ) ? $fields : array() );
	}

	/**
	 * Whether the parcel's shipment was handled to the end.
	 */
	public function shipped(): bool {
		return true === ( $this->fields[ self::SHIPPED ] ?? null );
	}

	/**
	 * Records that the parcel's shipment was handled. Saved with save().
	 */
	public function mark_shipped(): void {
		$this->fields[ self::SHIPPED ] = true;
	}

	/**
	 * Records how many copies of each line the parcel holds. Saved with save().
	 *
	 * @param ParcelLine[] $lines The parcel's lines.
	 * @phpstan-param list<ParcelLine> $lines
	 */
	public function set_quantities( array $lines ): void {
		$quantities = array();
		foreach ( $lines as $line ) {
			$quantities[ (string) $line->item->get_id() ] = $line->quantity;
		}
		$this->fields[ self::QUANTITIES ] = $quantities;
	}

	/**
	 * Copies of a line that the order's other shipped parcels hold, as far
	 * as their printshop said.
	 *
	 * @param WC_Order $order   The order.
	 * @param int      $item_id The line's order item id.
	 * @param string   $except  The parcel not to count.
	 */
	public static function shipped_elsewhere( WC_Order $order, int $item_id, string $except ): int {
		$count = 0;
		foreach ( $order->get_meta_data() as $meta ) {
			$key = (string) $meta->key;
			if ( ! str_starts_with( $key, Schema::PARCEL_META_PREFIX ) || Schema::PARCEL_META_PREFIX . $except === $key ) {
				continue;
			}
			$fields   = is_array( $meta->value ) ? $meta->value : array();
			$quantity = $fields[ self::QUANTITIES ][ (string) $item_id ] ?? null;
			if ( true === ( $fields[ self::SHIPPED ] ?? null ) && is_int( $quantity ) ) {
				$count += $quantity;
			}
		}
		return $count;
	}

	/**
	 * Whether the parcel's delivery was noted.
	 */
	public function delivered(): bool {
		return true === ( $this->fields[ self::DELIVERED ] ?? null );
	}

	/**
	 * Records that the parcel's delivery was noted. Saved with save().
	 */
	public function mark_delivered(): void {
		$this->fields[ self::DELIVERED ] = true;
	}

	/**
	 * The parcel's tracking as last set.
	 *
	 * @return array{carrier?: string, number: string, url?: string}|null
	 */
	public function tracking(): ?array {
		return Tracking::from_payload( $this->fields[ self::TRACKING ] ?? null );
	}

	/**
	 * Whether an event created at $created_at may set the tracking: no event
	 * that set it since is newer.
	 *
	 * @param int $created_at Epoch milliseconds.
	 */
	public function may_set_tracking( int $created_at ): bool {
		$at = $this->fields[ self::TRACKING_AT ] ?? null;
		return ! is_int( $at ) || $at <= $created_at;
	}

	/**
	 * Sets the tracking. Saved with save().
	 *
	 * @param array{carrier?: string, number: string, url?: string}|null $tracking   The tracking.
	 * @param int                                                        $created_at When the event that carried it was created, epoch milliseconds.
	 */
	public function set_tracking( ?array $tracking, int $created_at ): void {
		$this->fields[ self::TRACKING ]    = $tracking;
		$this->fields[ self::TRACKING_AT ] = $created_at;
	}

	/**
	 * Whether the customer is still to be emailed about new tracking.
	 */
	public function tracking_notice(): bool {
		return true === ( $this->fields[ self::TRACKING_NOTICE ] ?? null );
	}

	/**
	 * Sets whether the customer is still to be emailed about new tracking.
	 * Saved with save().
	 *
	 * @param bool $pending Whether they are.
	 */
	public function set_tracking_notice( bool $pending ): void {
		if ( $pending ) {
			$this->fields[ self::TRACKING_NOTICE ] = true;
		} else {
			unset( $this->fields[ self::TRACKING_NOTICE ] );
		}
	}

	/**
	 * Writes the record to the order's meta, and only that key.
	 */
	public function save(): void {
		$this->order->update_meta_data( Schema::PARCEL_META_PREFIX . $this->id, $this->fields );
		$this->order->save_meta_data();
	}
}
