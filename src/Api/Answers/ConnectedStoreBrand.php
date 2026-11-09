<?php
/**
 * One of the workspace's brands, as Pixonaut answers it.
 *
 * @package Pixonaut\WooCommerce
 */

declare(strict_types=1);

namespace Pixonaut\WooCommerce\Api\Answers;

use Pixonaut\WooCommerce\Vendor\Pixonaut\Sdk\Exception\UnexpectedResponse;

/**
 * An item of a connection's `brands`, which the store may choose from.
 */
final class ConnectedStoreBrand {

	/**
	 * @param string $id         `id`.
	 * @param bool   $is_default `isDefault`, whether it is the workspace's default brand.
	 * @param string $name       `name`.
	 */
	public function __construct(
		public readonly string $id,
		public readonly bool $is_default,
		public readonly string $name,
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
			is_default: Read::bool( $data, 'isDefault', $path ),
			name: Read::string( $data, 'name', $path ),
		);
	}
}
