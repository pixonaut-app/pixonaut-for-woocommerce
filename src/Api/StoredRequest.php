<?php
/**
 * A request kept to be sent again exactly as before.
 *
 * @package Pixonaut\WooCommerce
 */

declare(strict_types=1);

namespace Pixonaut\WooCommerce\Api;

use InvalidArgumentException;
use Pixonaut\WooCommerce\Vendor\Pixonaut\Sdk\Http\PreparedRequest;
use Pixonaut\WooCommerce\Vendor\Pixonaut\Sdk\Http\Operation;

/**
 * Reads back what the store kept of a prepared request: the SDK's snapshot
 * (`PreparedRequest::toArray()`), or the one plugin 1.0 kept, whose
 * operation was the method and path (`POST /v1/orders`) and whose key was
 * `idempotency_key`. A line placed before an update sends its order again
 * under the key it was first sent with.
 */
final class StoredRequest {

	/**
	 * What plugin 1.0 kept under the key.
	 */
	private const LEGACY_KEY = 'idempotency_key';

	/**
	 * @param array<mixed> $stored A snapshot.
	 *
	 * @throws InvalidArgumentException When it is neither the SDK's snapshot nor plugin 1.0's.
	 */
	public static function restore( array $stored ): PreparedRequest {
		if ( ! array_key_exists( 'idempotencyKey', $stored ) && array_key_exists( self::LEGACY_KEY, $stored ) ) {
			$stored = array(
				'operation'      => self::operation_id( $stored['operation'] ?? null ),
				'target'         => $stored['target'] ?? null,
				'body'           => $stored['body'] ?? null,
				'idempotencyKey' => $stored[ self::LEGACY_KEY ],
			);
		}
		return PreparedRequest::fromArray( $stored );
	}

	/**
	 * The SDK's operation id for plugin 1.0's `<METHOD> <path template>`.
	 *
	 * @param mixed $legacy E.g. `POST /v1/orders`.
	 *
	 * @throws InvalidArgumentException When no operation the plugin calls has that method and path.
	 */
	private static function operation_id( mixed $legacy ): string {
		$catalogue = Pixonaut::catalogue();
		foreach ( Pixonaut::OPERATIONS as $id ) {
			$operation = $catalogue->operation( $id );
			if ( self::legacy_id( $operation ) === $legacy ) {
				return $id;
			}
		}
		throw new InvalidArgumentException( 'The request snapshot names an operation the plugin does not call.' );
	}

	/**
	 * @param Operation $operation An operation.
	 */
	private static function legacy_id( Operation $operation ): string {
		return $operation->method . ' ' . $operation->path;
	}
}
