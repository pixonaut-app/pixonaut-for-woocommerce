<?php
/**
 * The automation that made an entry of a printshop order's history.
 *
 * @package Pixonaut\WooCommerce
 */

declare(strict_types=1);

namespace Pixonaut\WooCommerce\Api\Answers;

use Pixonaut\WooCommerce\Vendor\Pixonaut\Sdk\Exception\UnexpectedResponse;

/**
 * A history entry's `automation`.
 */
final class OrderAutomation {

	/**
	 * @param string $name `name`, as the workspace named it.
	 */
	public function __construct(
		public readonly string $name,
	) {
	}

	/**
	 * @param array<string, mixed> $data The automation.
	 * @param string               $path Where it is in the answer.
	 *
	 * @throws UnexpectedResponse When a field is missing or of another type.
	 */
	public static function from_answer( array $data, string $path ): self {
		return new self( Read::string( $data, 'name', $path ) );
	}
}
