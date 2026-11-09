<?php
/**
 * Why Pixonaut holds a printshop order, as it answers it.
 *
 * @package Pixonaut\WooCommerce
 */

declare(strict_types=1);

namespace Pixonaut\WooCommerce\Api\Answers;

use Pixonaut\WooCommerce\Vendor\Pixonaut\Sdk\Exception\UnexpectedResponse;

/**
 * An order's `hold`: the reason, the plan's monthly limit, and when the month ends.
 */
final class OrderHoldTerms {

	/**
	 * @param string $reason     `reason`, e.g. `monthly_order_limit`.
	 * @param int    $limit      `limit`, the plan's orders a month.
	 * @param string $period_end `periodEnd`, ISO 8601.
	 */
	public function __construct(
		public readonly string $reason,
		public readonly int $limit,
		public readonly string $period_end,
	) {
	}

	/**
	 * @param array<string, mixed> $data The hold.
	 * @param string               $path Where it is in the answer.
	 *
	 * @throws UnexpectedResponse When a field is missing or of another type.
	 */
	public static function from_answer( array $data, string $path ): self {
		return new self(
			reason: Read::string( $data, 'reason', $path ),
			limit: Read::int( $data, 'limit', $path ),
			period_end: Read::string( $data, 'periodEnd', $path ),
		);
	}
}
