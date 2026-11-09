<?php
/**
 * The address every order ships to, as Pixonaut answers it.
 *
 * @package Pixonaut\WooCommerce
 */

declare(strict_types=1);

namespace Pixonaut\WooCommerce\Api\Answers;

use Pixonaut\WooCommerce\Vendor\Pixonaut\Sdk\Exception\UnexpectedResponse;

/**
 * A connection's `deliverOrdersTo`: the workspace's saved address orders ship to instead of their customer.
 */
final class ConnectedStoreDeliverOrdersTo {

	/**
	 * @param string $name `name`, what the workspace calls the address.
	 */
	public function __construct(
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
			name: Read::string( $data, 'name', $path ),
		);
	}
}
