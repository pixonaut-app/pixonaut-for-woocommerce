<?php
/**
 * A shipment's tracking.
 *
 * @package Pixonaut\WooCommerce
 */

declare(strict_types=1);

namespace Pixonaut\WooCommerce\Fulfilment;

use Pixonaut\WooCommerce\Api\Answers\OrderTracking;
use RuntimeException;
use WC_Order_Item_Product;

/**
 * The tracking of the parcel a line's item left in, from a `parcel.*` event,
 * Pixonaut's answer to a move the store made or a read of the order, as a
 * line stores it in `LineState::REF_TRACKING`: a number, with the carrier and
 * a link when Pixonaut knows them. `LineState::REF_TRACKING_AT` holds when the
 * event that carried it was created, so an older event never replaces it.
 */
final class Tracking {

	/**
	 * The tracking of a payload, sanitised for storing.
	 *
	 * @param mixed $tracking The payload's `tracking`.
	 * @return array{carrier?: string, number: string, url?: string}|null Null when it carries no number.
	 */
	public static function from_payload( mixed $tracking ): ?array {
		if ( ! is_array( $tracking ) || ! is_string( $tracking['number'] ?? null ) || '' === $tracking['number'] ) {
			return null;
		}
		$kept = array( 'number' => sanitize_text_field( $tracking['number'] ) );
		if ( is_string( $tracking['carrier'] ?? null ) && '' !== $tracking['carrier'] ) {
			$kept['carrier'] = sanitize_text_field( $tracking['carrier'] );
		}
		if ( is_string( $tracking['url'] ?? null ) && '' !== esc_url_raw( $tracking['url'], array( 'http', 'https' ) ) ) {
			$kept['url'] = esc_url_raw( $tracking['url'], array( 'http', 'https' ) );
		}
		return $kept;
	}

	/**
	 * The tracking of an order Pixonaut answered, sanitised for storing.
	 *
	 * @param OrderTracking|null $tracking The order's `tracking`.
	 * @return array{carrier?: string, number: string, url?: string}|null Null when it carries no number.
	 */
	public static function from_answer( ?OrderTracking $tracking ): ?array {
		return self::from_payload( self::as_sent( $tracking ) );
	}

	/**
	 * An order's tracking in the shape Pixonaut sends it, as an event's
	 * payload carries it: a carrier or link it does not know left out.
	 *
	 * @param OrderTracking|null $tracking The order's `tracking`.
	 * @return array{carrier?: string, number: string, url?: string}|null
	 */
	public static function as_sent( ?OrderTracking $tracking ): ?array {
		if ( null === $tracking ) {
			return null;
		}
		$sent = array( 'number' => $tracking->number );
		if ( null !== $tracking->carrier ) {
			$sent['carrier'] = $tracking->carrier;
		}
		if ( null !== $tracking->url ) {
			$sent['url'] = $tracking->url;
		}
		return $sent;
	}

	/**
	 * The tracking a line stored.
	 *
	 * @param LineState $line The line.
	 * @return array{carrier?: string, number: string, url?: string}|null Null when it stored none.
	 */
	public static function of( LineState $line ): ?array {
		return self::from_payload( $line->ref( LineState::REF_TRACKING ) );
	}

	/**
	 * Whether an event created at $created_at may set the line's tracking:
	 * none of the events that set it since is newer.
	 *
	 * @param LineState $line       The line.
	 * @param int       $created_at When the event was created, epoch milliseconds.
	 */
	public static function may_set( LineState $line, int $created_at ): bool {
		$at = $line->ref( LineState::REF_TRACKING_AT );
		return ! is_int( $at ) || $at <= $created_at;
	}

	/**
	 * Sets the line's tracking from an event. Saved with LineState::save().
	 *
	 * @param LineState                                             $line       The line.
	 * @param array{carrier?: string, number: string, url?: string} $tracking   The tracking.
	 * @param int                                                   $created_at When the event that carried it was created, epoch milliseconds.
	 */
	public static function set( LineState $line, array $tracking, int $created_at ): void {
		$line->set_ref( LineState::REF_TRACKING, $tracking );
		$line->set_ref( LineState::REF_TRACKING_AT, $created_at );
	}

	/**
	 * Keeps a parcel's tracking on a line, unless an event newer than the one
	 * that carried it set the line's.
	 *
	 * @param WC_Order_Item_Product                                 $item       A line of the parcel.
	 * @param array{carrier?: string, number: string, url?: string} $tracking   The parcel's tracking.
	 * @param int                                                   $created_at When the event that carried it was created, epoch milliseconds.
	 * @throws RuntimeException When the line kept changing for OrderStatusChanged::MAX_ATTEMPTS tries.
	 */
	public static function keep( WC_Order_Item_Product $item, array $tracking, int $created_at ): void {
		for ( $attempt = 0; $attempt < OrderStatusChanged::MAX_ATTEMPTS; $attempt++ ) {
			$line = LineState::of( $item );
			if ( ! self::may_set( $line, $created_at ) || ( self::of( $line ) === $tracking && $line->ref( LineState::REF_TRACKING_AT ) === $created_at ) ) {
				return;
			}
			self::set( $line, $tracking, $created_at );
			if ( $line->save() ) {
				return;
			}
		}
		throw new RuntimeException( sprintf( 'Parcel tracking not applied to order item %d: the line kept changing.', (int) $item->get_id() ) );
	}

	/**
	 * The carrier, number and link for an order note, e.g.
	 * `PostNord PN123456789SE (https://…)`.
	 *
	 * @param array{carrier?: string, number: string, url?: string} $tracking The tracking.
	 */
	public static function details( array $tracking ): string {
		$details = self::text( $tracking );
		return isset( $tracking['url'] ) ? $details . ' (' . $tracking['url'] . ')' : $details;
	}

	/**
	 * The carrier and number, e.g. `PostNord PN123456789SE`.
	 *
	 * @param array{carrier?: string, number: string, url?: string} $tracking The tracking.
	 */
	public static function text( array $tracking ): string {
		return trim( ( $tracking['carrier'] ?? '' ) . ' ' . $tracking['number'] );
	}
}
