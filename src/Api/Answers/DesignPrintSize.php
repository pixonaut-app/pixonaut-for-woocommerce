<?php
/**
 * The size a design is printed at, as Pixonaut answers it.
 *
 * @package Pixonaut\WooCommerce
 */

declare(strict_types=1);

namespace Pixonaut\WooCommerce\Api\Answers;

use Pixonaut\WooCommerce\Vendor\Pixonaut\Sdk\Exception\UnexpectedResponse;

/**
 * A design's `printSize`, in millimetres.
 */
final class DesignPrintSize {

	/**
	 * @param float $width_mm  `widthMm`.
	 * @param float $height_mm `heightMm`.
	 */
	public function __construct(
		public readonly float $width_mm,
		public readonly float $height_mm,
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
			width_mm: Read::float( $data, 'widthMm', $path ),
			height_mm: Read::float( $data, 'heightMm', $path ),
		);
	}
}
