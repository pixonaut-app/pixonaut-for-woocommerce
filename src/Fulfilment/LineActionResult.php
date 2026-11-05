<?php
/**
 * What came of an action on a line.
 *
 * @package Pixonaut\WooCommerce
 */

declare(strict_types=1);

namespace Pixonaut\WooCommerce\Fulfilment;

/**
 * One of LineActions' result codes, with the detail it needs: Pixonaut's
 * error code for a refusal, or the status of an order placed again that is
 * not on its way yet. A refusal may carry its reason in words, which the
 * order screen shows in place of the code's own message.
 */
final class LineActionResult {

	/**
	 * @param string $code   One of LineActions' result codes, e.g. `done`.
	 * @param string $detail  Pixonaut's error code or order status; empty when the code says it all.
	 * @param string $message Why, in translated words; empty when the code's own message says it.
	 */
	public function __construct(
		public readonly string $code,
		public readonly string $detail = '',
		public readonly string $message = '',
	) {
	}
}
