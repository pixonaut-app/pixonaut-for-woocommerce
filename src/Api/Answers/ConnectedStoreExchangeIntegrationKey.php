<?php
/**
 * The integration key a connect hands over, as Pixonaut answers it.
 *
 * @package Pixonaut\WooCommerce
 */

declare(strict_types=1);

namespace Pixonaut\WooCommerce\Api\Answers;

use Pixonaut\WooCommerce\Vendor\Pixonaut\Sdk\Exception\UnexpectedResponse;

/**
 * A connect answer's `integrationKey`, the store's server-side key.
 */
final class ConnectedStoreExchangeIntegrationKey {

	/**
	 * @param string $id    `id`.
	 * @param string $key   `key`, the `ik_` key; secret, and answered only this once.
	 * @param string $label `label`, its name when it was issued.
	 */
	public function __construct(
		public readonly string $id,
		public readonly string $key,
		public readonly string $label,
	) {
	}

	/**
	 * @param array<string, mixed> $data The object.
	 * @param string               $path Where it is in the answer.
	 *
	 * @throws UnexpectedResponse When a field is missing or of another type.
	 */
	public static function from_answer( array $data, string $path ): self {
		return new self(
			id: Read::string( $data, 'id', $path ),
			key: Read::string( $data, 'key', $path ),
			label: Read::string( $data, 'label', $path ),
		);
	}
}
