<?php
/**
 * Fields of a webhook event's payload.
 *
 * @package Pixonaut\WooCommerce
 */

declare(strict_types=1);

namespace Pixonaut\WooCommerce\Webhooks;

use Pixonaut\WooCommerce\Vendor\Pixonaut\Sdk\Webhooks\Event;

final class Payload {

	/**
	 * A string field of the event's payload; null when absent or not a
	 * non-empty string.
	 *
	 * @param Event  $event The event.
	 * @param string $name  Field.
	 */
	public static function string( Event $event, string $name ): ?string {
		$value = $event->payload[ $name ] ?? null;
		return is_string( $value ) && '' !== $value ? $value : null;
	}

	/**
	 * An integer field of the event's payload; null when absent or not an integer.
	 *
	 * @param Event  $event The event.
	 * @param string $name  Field.
	 */
	public static function int( Event $event, string $name ): ?int {
		$value = $event->payload[ $name ] ?? null;
		return is_int( $value ) ? $value : null;
	}

	/**
	 * An event the store builds itself, for a change Pixonaut answered
	 * rather than delivered, so it is applied as its webhook would be.
	 *
	 * @param string               $id         Event id.
	 * @param string               $type       Event type, e.g. `order.status_changed`.
	 * @param int                  $created_at When it happened, in milliseconds since the Unix epoch.
	 * @param array<string, mixed> $payload    The type's payload; an object, never a list.
	 */
	public static function event( string $id, string $type, int $created_at, array $payload ): Event {
		return Event::fromArray(
			array(
				'id'        => $id,
				'type'      => $type,
				'createdAt' => $created_at,
				'payload'   => $payload,
			)
		);
	}
}
