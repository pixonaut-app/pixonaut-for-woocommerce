<?php
/**
 * Pixonaut's verdict on one print size.
 *
 * @package Pixonaut\WooCommerce
 */

declare(strict_types=1);

namespace Pixonaut\WooCommerce\Api\Answers;

use Pixonaut\WooCommerce\Vendor\Pixonaut\Sdk\Exception\UnexpectedResponse;

/**
 * An item of `verdicts` in the answer of `POST /v1/templates/{id}/print-size`.
 */
final class PrintSizeVerdict {

	/**
	 * @param bool        $legal  `legal`.
	 * @param string|null $reason `reason` a size is not legal; sent only with a size that is not.
	 */
	public function __construct(
		public readonly bool $legal,
		public readonly ?string $reason,
	) {
	}

	/**
	 * @param array<string, mixed> $data The verdict.
	 * @param string               $path Where it is in the answer.
	 *
	 * @throws UnexpectedResponse When a field is missing or of another type.
	 */
	public static function from_answer( array $data, string $path = '$' ): self {
		$legal = Read::bool( $data, 'legal', $path );
		return new self( $legal, $legal ? null : Read::string( $data, 'reason', $path ) );
	}
}
