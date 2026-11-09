<?php
/**
 * A print of a printshop order, as Pixonaut answers it.
 *
 * @package Pixonaut\WooCommerce
 */

declare(strict_types=1);

namespace Pixonaut\WooCommerce\Api\Answers;

use Pixonaut\WooCommerce\Vendor\Pixonaut\Sdk\Exception\UnexpectedResponse;

/**
 * An item of an order's `prints`: a print made from a design at a revision,
 * or a fill print of a placement the order left undesigned, made from none.
 */
final class OrderPrint {

	/**
	 * @param string|null $design_id       `designId`; null for a fill print.
	 * @param float|null  $design_revision `designRevision`, the revision it printed; null for a fill print.
	 */
	public function __construct(
		public readonly ?string $design_id,
		public readonly ?float $design_revision,
	) {
	}

	/**
	 * @param array<string, mixed> $data The print.
	 * @param string               $path Where it is in the answer.
	 *
	 * @throws UnexpectedResponse When a field is missing or of another type.
	 */
	public static function from_answer( array $data, string $path ): self {
		$design_id = Read::nullable_string( $data, 'designId', $path );
		return new self(
			design_id: $design_id,
			// A fill print has no revision to read.
			design_revision: null === $design_id ? null : Read::float( $data, 'designRevision', $path ),
		);
	}
}
