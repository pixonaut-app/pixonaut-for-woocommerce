<?php
/**
 * A printshop order's latest address change, as Pixonaut answers it.
 *
 * @package Pixonaut\WooCommerce
 */

declare(strict_types=1);

namespace Pixonaut\WooCommerce\Api\Answers;

use Pixonaut\WooCommerce\Vendor\Pixonaut\Sdk\Exception\UnexpectedResponse;

/**
 * An order's `delivery`: what the printshop made of the change, and when.
 */
final class OrderDelivery {

	/**
	 * @param float       $at    `at`, epoch milliseconds.
	 * @param string      $state `state`: `sending`, `accepted`, `refused` or `carried`.
	 * @param string|null $words `words`, the printshop's own.
	 */
	public function __construct(
		public readonly float $at,
		public readonly string $state,
		public readonly ?string $words,
	) {
	}

	/**
	 * @param array<string, mixed> $data The change.
	 * @param string               $path Where it is in the answer.
	 *
	 * @throws UnexpectedResponse When a field is missing or of another type.
	 */
	public static function from_answer( array $data, string $path ): self {
		return new self(
			at: Read::float( $data, 'at', $path ),
			state: Read::string( $data, 'state', $path ),
			words: Read::nullable_string( $data, 'words', $path ),
		);
	}
}
