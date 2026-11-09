<?php
/**
 * The workspace's print defaults, as Pixonaut answers them.
 *
 * @package Pixonaut\WooCommerce
 */

declare(strict_types=1);

namespace Pixonaut\WooCommerce\Api\Answers;

use Pixonaut\WooCommerce\Vendor\Pixonaut\Sdk\Exception\UnexpectedResponse;

/**
 * A connection's `printing`.
 */
final class ConnectedStorePrinting {

	/**
	 * @param float $min_print_dpi `minPrintDpi`, the least resolution a print may have.
	 */
	public function __construct(
		public readonly float $min_print_dpi,
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
			min_print_dpi: Read::float( $data, 'minPrintDpi', $path ),
		);
	}
}
