<?php
/**
 * A printshop order's shipment tracking, as Pixonaut answers it.
 *
 * @package Pixonaut\WooCommerce
 */

declare(strict_types=1);

namespace Pixonaut\WooCommerce\Api\Answers;

use Pixonaut\WooCommerce\Vendor\Pixonaut\Sdk\Exception\UnexpectedResponse;

/**
 * An order's `tracking`. Pixonaut leaves out a carrier or link it does not know.
 */
final class OrderTracking {

	/**
	 * @param string      $number  `number`.
	 * @param string|null $carrier `carrier`; null when left out.
	 * @param string|null $url     `url`; null when left out.
	 */
	public function __construct(
		public readonly string $number,
		public readonly ?string $carrier,
		public readonly ?string $url,
	) {
	}

	/**
	 * @param array<string, mixed> $data The tracking.
	 * @param string               $path Where it is in the answer.
	 *
	 * @throws UnexpectedResponse When a field is missing or of another type.
	 */
	public static function from_answer( array $data, string $path ): self {
		return new self(
			number: Read::string( $data, 'number', $path ),
			carrier: Read::optional_string( $data, 'carrier', $path ),
			url: Read::optional_string( $data, 'url', $path ),
		);
	}
}
