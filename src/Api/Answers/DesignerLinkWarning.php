<?php
/**
 * A warning on a designer link Pixonaut made.
 *
 * @package Pixonaut\WooCommerce
 */

declare(strict_types=1);

namespace Pixonaut\WooCommerce\Api\Answers;

use Pixonaut\WooCommerce\Vendor\Pixonaut\Sdk\Exception\UnexpectedResponse;

/**
 * An item of `warnings` in the answer of `POST /v1/templates/{id}/designer-links`.
 */
final class DesignerLinkWarning {

	/**
	 * @param string $code        `code`, such as `sides_over_recommended`.
	 * @param int    $sides       `sides` the link holds.
	 * @param int    $recommended `recommended`, the most sides the designer is built for.
	 */
	public function __construct(
		public readonly string $code,
		public readonly int $sides,
		public readonly int $recommended,
	) {
	}

	/**
	 * @param array<string, mixed> $data The warning.
	 * @param string               $path Where it is in the answer.
	 *
	 * @throws UnexpectedResponse When a field is missing or of another type.
	 */
	public static function from_answer( array $data, string $path = '$' ): self {
		return new self(
			code: Read::string( $data, 'code', $path ),
			sides: Read::int( $data, 'sides', $path ),
			recommended: Read::int( $data, 'recommended', $path ),
		);
	}
}
