<?php
/**
 * A request as it is sent, kept so it can be sent again unchanged.
 *
 * @package Pixonaut\WooCommerce
 */

declare(strict_types=1);

namespace Pixonaut\WooCommerce\Api;

use InvalidArgumentException;

/**
 * The body is encoded and the idempotency key chosen once, when the request
 * is prepared. A caller that persists the snapshot (`to_array()`) and later
 * sends `from_array()` of it sends the same bytes under the same key, so
 * Pixonaut recognises it as a repeat. The credential is not part of it; it is
 * added at every send.
 */
final class PreparedRequest {

	/**
	 * @param string      $operation       Operation id, e.g. `POST /v1/designs`.
	 * @param string      $target          Path and query, e.g. `/v1/designs/d_1?v=2`.
	 * @param string|null $body            JSON body, or null for none.
	 * @param string|null $idempotency_key `Idempotency-Key`, or null when the operation takes none.
	 */
	public function __construct(
		public readonly string $operation,
		public readonly string $target,
		public readonly ?string $body = null,
		public readonly ?string $idempotency_key = null,
	) {
	}

	/**
	 * @return array{operation: string, target: string, body: string|null, idempotency_key: string|null}
	 */
	public function to_array(): array {
		return array(
			'operation'       => $this->operation,
			'target'          => $this->target,
			'body'            => $this->body,
			'idempotency_key' => $this->idempotency_key,
		);
	}

	/**
	 * @param array<mixed> $data            What `to_array()` returned.
	 *
	 * @throws InvalidArgumentException When the snapshot is malformed or a field is missing.
	 */
	public static function from_array( array $data ): self {
		foreach ( array( 'operation', 'target', 'body', 'idempotency_key' ) as $field ) {
			// A lost key would turn a repeat into a second write, so null must be stored, not implied.
			if ( ! array_key_exists( $field, $data ) ) {
				throw new InvalidArgumentException( sprintf( 'Pixonaut request snapshot has no "%s".', $field ) );
			}
		}
		$operation = $data['operation'];
		$target    = $data['target'];
		$body      = $data['body'];
		$key       = $data['idempotency_key'];
		if ( ! is_string( $operation ) || ! is_string( $target ) || ! ( null === $body || is_string( $body ) ) || ! ( null === $key || is_string( $key ) ) ) {
			throw new InvalidArgumentException( 'Malformed Pixonaut request snapshot.' );
		}
		return new self( $operation, $target, $body, $key );
	}
}
