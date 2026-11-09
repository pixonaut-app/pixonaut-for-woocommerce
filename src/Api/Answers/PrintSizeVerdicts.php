<?php
/**
 * Pixonaut's verdicts on several print sizes.
 *
 * @package Pixonaut\WooCommerce
 */

declare(strict_types=1);

namespace Pixonaut\WooCommerce\Api\Answers;

use Pixonaut\WooCommerce\Vendor\Pixonaut\Sdk\Exception\UnexpectedResponse;

/**
 * The answer of `POST /v1/templates/{id}/print-size` to a list of sizes.
 */
final class PrintSizeVerdicts {

	/**
	 * @param PrintSizeVerdict[] $verdicts `verdicts`, one per size, in order.
	 * @phpstan-param list<PrintSizeVerdict> $verdicts
	 */
	public function __construct(
		public readonly array $verdicts,
	) {
	}

	/**
	 * @param array<string, mixed> $data The answer.
	 * @param string               $path Where it is in the answer.
	 *
	 * @throws UnexpectedResponse When a field is missing or of another type.
	 */
	public static function from_answer( array $data, string $path = '$' ): self {
		return new self( Read::objects( $data, 'verdicts', $path, PrintSizeVerdict::from_answer( ... ) ) );
	}
}
