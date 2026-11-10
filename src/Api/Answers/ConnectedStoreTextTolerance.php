<?php
/**
 * How far from its outline a print file's live text may lie, as Pixonaut answers it.
 *
 * @package Pixonaut\WooCommerce
 */

declare(strict_types=1);

namespace Pixonaut\WooCommerce\Api\Answers;

use Pixonaut\WooCommerce\Vendor\Pixonaut\Sdk\Exception\UnexpectedResponse;

/**
 * A connection's `printFileTextTolerance`, or its `printing`'s.
 */
final class ConnectedStoreTextTolerance {

	/**
	 * @param string $unit  `unit`: `px` (device pixels) or `mm`.
	 * @param float  $value `value`, how many.
	 */
	public function __construct(
		public readonly string $unit,
		public readonly float $value,
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
			unit: Read::string( $data, 'unit', $path ),
			value: Read::float( $data, 'value', $path ),
		);
	}
}
