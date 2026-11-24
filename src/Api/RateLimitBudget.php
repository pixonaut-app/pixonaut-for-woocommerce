<?php
/**
 * How long one customer request may still wait on Pixonaut's commit budget.
 *
 * @package Pixonaut\WooCommerce
 */

declare(strict_types=1);

namespace Pixonaut\WooCommerce\Api;

/**
 * The waits a request's commits share: a book commits a design per page, one
 * after another, and the customer waits on all of them, so each commit's
 * `Retry-After` is taken from what is left rather than from a budget of its
 * own.
 */
final class RateLimitBudget {

	/**
	 * @param int $seconds The seconds of waiting left.
	 */
	public function __construct( private int $seconds = Designs::RATE_LIMIT_BUDGET_SECONDS ) {
	}

	/**
	 * Takes a wait out of what is left.
	 *
	 * @param int $seconds The wait.
	 *
	 * @return bool False, and nothing taken, when the wait does not fit.
	 */
	public function spend( int $seconds ): bool {
		if ( $seconds > $this->seconds ) {
			return false;
		}
		$this->seconds -= $seconds;
		return true;
	}
}
