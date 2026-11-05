<?php
/**
 * A webhook event.
 *
 * @package Pixonaut\WooCommerce
 */

declare(strict_types=1);

namespace Pixonaut\WooCommerce\Webhooks;

use UnexpectedValueException;

/**
 * The envelope Pixonaut delivers: `{id, type, createdAt, payload}`. A retry
 * or redelivery of an event carries the same id.
 */
final class Event {

	/**
	 * Sent by the endpoint's ping, never as an event of the stream.
	 */
	public const PING = 'webhook.ping';

	/**
	 * @param string               $id         Event id.
	 * @param string               $type       Event type, e.g. `order.status_changed`.
	 * @param int                  $created_at When it happened, in milliseconds since the Unix epoch.
	 * @param array<string, mixed> $payload    The type's payload.
	 */
	public function __construct(
		public readonly string $id,
		public readonly string $type,
		public readonly int $created_at,
		public readonly array $payload,
	) {
	}

	/**
	 * @param string $body A delivery's body.
	 * @throws UnexpectedValueException When it is not an event envelope.
	 */
	public static function from_json( string $body ): self {
		$data = json_decode( $body, true );
		if ( ! is_array( $data ) ) {
			throw new UnexpectedValueException( 'The body is not a JSON object.' );
		}
		return self::from_array( $data );
	}

	/**
	 * @param array<mixed> $data A decoded envelope, as a delivery's body or a pulled event holds it.
	 * @throws UnexpectedValueException When it is not an event envelope.
	 */
	public static function from_array( array $data ): self {
		$id      = $data['id'] ?? null;
		$type    = $data['type'] ?? null;
		$created = $data['createdAt'] ?? null;
		$payload = $data['payload'] ?? null;
		if ( ! is_string( $id ) || '' === $id || ! is_string( $type ) || '' === $type ) {
			throw new UnexpectedValueException( 'The event has no id or type.' );
		}
		if ( ! is_int( $created ) || ! is_array( $payload ) ) {
			throw new UnexpectedValueException( 'The event has no createdAt or payload.' );
		}
		return new self( $id, $type, $created, $payload );
	}

	/**
	 * The type as it appears in hook names: `order.status_changed` is
	 * `order_status_changed`.
	 */
	public function hook_name(): string {
		return str_replace( '.', '_', $this->type );
	}

	/**
	 * A string field of the payload; null when absent or not a non-empty string.
	 *
	 * @param string $name Field.
	 */
	public function string( string $name ): ?string {
		$value = $this->payload[ $name ] ?? null;
		return is_string( $value ) && '' !== $value ? $value : null;
	}
}
