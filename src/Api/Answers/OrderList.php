<?php
/**
 * The printshop orders placed under a ref, as the store reads them from Pixonaut.
 *
 * @package Pixonaut\WooCommerce
 */

declare(strict_types=1);

namespace Pixonaut\WooCommerce\Api\Answers;

use Pixonaut\WooCommerce\Vendor\Pixonaut\Sdk\Exception\ApiException;
use Pixonaut\WooCommerce\Vendor\Pixonaut\Sdk\Exception\UnexpectedResponse;
use Pixonaut\WooCommerce\Vendor\Pixonaut\Sdk\Pixonaut as Sdk;

/**
 * The fields of `GET /v1/orders` the store reads. A line's ref names at most
 * a few orders (the one it placed, and those a retry placed for it), so the
 * first page holds them all.
 */
final class OrderList {

	/**
	 * @param Order[] $orders `orders`.
	 * @phpstan-param list<Order> $orders
	 */
	public function __construct(
		public readonly array $orders,
	) {
	}

	/**
	 * `GET /v1/orders?externalRef=…`.
	 *
	 * @param Sdk    $pixonaut     The SDK.
	 * @param string $external_ref The ref the orders were placed under.
	 *
	 * @throws ApiException When Pixonaut refused, could not be reached, or answered without a field the store reads.
	 */
	public static function under( Sdk $pixonaut, string $external_ref ): self {
		return Read::answer( $pixonaut->call( 'orders.list', query: array( 'externalRef' => $external_ref ) ), self::from_answer( ... ) );
	}

	/**
	 * @param array<string, mixed> $data The answer.
	 * @param string               $path Where it is in the answer.
	 *
	 * @throws UnexpectedResponse When a field is missing or of another type.
	 */
	public static function from_answer( array $data, string $path = '$' ): self {
		return new self( Read::objects( $data, 'orders', $path, Order::from_answer( ... ) ) );
	}
}
