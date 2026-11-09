<?php
/**
 * An address a printshop order ships to, as Pixonaut answers it.
 *
 * @package Pixonaut\WooCommerce
 */

declare(strict_types=1);

namespace Pixonaut\WooCommerce\Api\Answers;

use Pixonaut\WooCommerce\Vendor\Pixonaut\Sdk\Exception\UnexpectedResponse;

/**
 * An order's `shippingAddress`, or the `address` of its `shipTo`. Pixonaut
 * leaves out a part the address does not have.
 */
final class OrderShippingAddress {

	/**
	 * @param string      $city        `city`.
	 * @param string      $country     `country`.
	 * @param string      $line1       `line1`.
	 * @param string|null $company     `company`; null when left out.
	 * @param string|null $line2       `line2`; null when left out.
	 * @param string|null $postal_code `postalCode`; null when left out.
	 * @param string|null $region      `region`; null when left out.
	 */
	public function __construct(
		public readonly string $city,
		public readonly string $country,
		public readonly string $line1,
		public readonly ?string $company,
		public readonly ?string $line2,
		public readonly ?string $postal_code,
		public readonly ?string $region,
	) {
	}

	/**
	 * @param array<string, mixed> $data The address.
	 * @param string               $path Where it is in the answer.
	 *
	 * @throws UnexpectedResponse When a field is missing or of another type.
	 */
	public static function from_answer( array $data, string $path ): self {
		return new self(
			city: Read::string( $data, 'city', $path ),
			country: Read::string( $data, 'country', $path ),
			line1: Read::string( $data, 'line1', $path ),
			company: Read::optional_string( $data, 'company', $path ),
			line2: Read::optional_string( $data, 'line2', $path ),
			postal_code: Read::optional_string( $data, 'postalCode', $path ),
			region: Read::optional_string( $data, 'region', $path ),
		);
	}
}
