<?php
/**
 * The workspace's saved address a printshop order ships to, as Pixonaut answers it.
 *
 * @package Pixonaut\WooCommerce
 */

declare(strict_types=1);

namespace Pixonaut\WooCommerce\Api\Answers;

use Pixonaut\WooCommerce\Vendor\Pixonaut\Sdk\Exception\UnexpectedResponse;

/**
 * An order's `shipTo`.
 */
final class OrderShipTo {

	/**
	 * @param OrderShippingAddress $address `address`.
	 */
	public function __construct(
		public readonly OrderShippingAddress $address,
	) {
	}

	/**
	 * @param array<string, mixed> $data The saved address.
	 * @param string               $path Where it is in the answer.
	 *
	 * @throws UnexpectedResponse When a field is missing or of another type.
	 */
	public static function from_answer( array $data, string $path ): self {
		return new self( Read::object( $data, 'address', $path, OrderShippingAddress::from_answer( ... ) ) );
	}
}
