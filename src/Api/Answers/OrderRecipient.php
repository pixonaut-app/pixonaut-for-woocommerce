<?php
/**
 * Who a printshop order is for, as Pixonaut answers it.
 *
 * @package Pixonaut\WooCommerce
 */

declare(strict_types=1);

namespace Pixonaut\WooCommerce\Api\Answers;

use Pixonaut\WooCommerce\Vendor\Pixonaut\Sdk\Exception\UnexpectedResponse;

/**
 * An order's `recipient`. Pixonaut leaves out an email or phone it does not have.
 */
final class OrderRecipient {

	/**
	 * @param string      $name  `name`.
	 * @param string|null $email `email`; null when left out.
	 * @param string|null $phone `phone`; null when left out.
	 */
	public function __construct(
		public readonly string $name,
		public readonly ?string $email,
		public readonly ?string $phone,
	) {
	}

	/**
	 * @param array<string, mixed> $data The recipient.
	 * @param string               $path Where it is in the answer.
	 *
	 * @throws UnexpectedResponse When a field is missing or of another type.
	 */
	public static function from_answer( array $data, string $path ): self {
		return new self(
			name: Read::string( $data, 'name', $path ),
			email: Read::optional_string( $data, 'email', $path ),
			phone: Read::optional_string( $data, 'phone', $path ),
		);
	}
}
