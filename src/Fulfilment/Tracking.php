<?php
/**
 * A shipment's tracking.
 *
 * @package Pixonaut\WooCommerce
 */

declare(strict_types=1);

namespace Pixonaut\WooCommerce\Fulfilment;

/**
 * The tracking `order.status_changed` carries when an order ships, and
 * `order.tracking_added` or `order.tracking_changed` when it comes later, as a
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
