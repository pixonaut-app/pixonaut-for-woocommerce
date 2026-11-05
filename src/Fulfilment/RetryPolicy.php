<?php
/**
 * What a fulfilment run does after Pixonaut did not take a step.
 *
 * @package Pixonaut\WooCommerce
 */

declare(strict_types=1);

namespace Pixonaut\WooCommerce\Fulfilment;

use Pixonaut\WooCommerce\Api\ApiError;
use Pixonaut\WooCommerce\Api\ErrorCodes;

/**
 * A failure that sending the same request again may cure is retried on a
 * backoff schedule: the line keeps its state, refs and keys, and the next run
 * sends the same bytes under the same key. Every other failure, and one that
 * outlasts the schedule, needs the merchant: the line moves to
 * `needs_attention`. Steps that have an answer of their own (`already_printed`,
 * `not_committed`) are handled by the Fulfiller
 * before an error reaches the policy.
 */
final class RetryPolicy {

	public const RETRY = 'retry';

	public const NEEDS_ATTENTION = 'needs_attention';

	/**
	 * Waits before each retry, in seconds: 1 min, 5 min, 30 min, 2 h, 12 h.
	 */
	public const SCHEDULE = array( 60, 300, 1800, 7200, 43200 );

	/**
	 * Codes that are transient whatever else the answer says.
	 */
	private const TRANSIENT_CODES = array(
		ErrorCodes::LOCK_TIMEOUT,
		ErrorCodes::TRANSPORT_FAILED,
		ErrorCodes::RATE_LIMITED,
		ErrorCodes::IDEMPOTENCY_KEY_IN_USE,
	);

	/**
	 * Whether a failed step is retried or needs the merchant.
	 *
	 * @param ApiError $error What Pixonaut answered, or why no answer came.
	 *
	 * @return string RETRY or NEEDS_ATTENTION.
	 */
	public static function decide( ApiError $error ): string {
		if ( in_array( $error->error_code, ErrorCodes::IDEMPOTENCY_BUGS, true ) ) {
			return self::NEEDS_ATTENTION;
		}
		return $error->retryable || in_array( $error->error_code, self::TRANSIENT_CODES, true ) ? self::RETRY : self::NEEDS_ATTENTION;
	}

	/**
	 * How long to wait before the next try, after $failures failed tries in a
	 * row; never shorter than the `Retry-After` Pixonaut sent.
	 *
	 * @param int      $failures    Tries that failed in a row before this one, this one not counted.
	 * @param int|null $retry_after Seconds Pixonaut asked to wait, when it did.
	 *
	 * @return int|null Seconds; null when the schedule is used up.
	 */
	public static function delay( int $failures, ?int $retry_after = null ): ?int {
		$schedule = self::schedule();
		if ( $failures < 0 || ! isset( $schedule[ $failures ] ) ) {
			return null;
		}
		return max( $schedule[ $failures ], $retry_after ?? 0 );
	}

	/**
	 * The waits before each retry, filtered.
	 *
	 * @return list<int>
	 */
	public static function schedule(): array {
		/**
		 * Filters the waits between fulfilment retries. A line whose step keeps
		 * failing with a transient error is tried again after each wait in
		 * turn; once they are used up it moves to `needs_attention`.
		 *
		 * @since 0.1.0
		 *
		 * @param int[] $schedule Seconds to wait before each retry, in order; an empty list never retries.
		 *
		 * @example
		 * add_filter( 'pixonaut_wc_fulfilment_retry_schedule', function ( $schedule ) {
		 *     return array( 60, 600, 3600 ); // Three retries, then needs attention.
		 * } );
		 */
		$schedule = (array) apply_filters( 'pixonaut_wc_fulfilment_retry_schedule', self::SCHEDULE );
		// A wait that is not a whole number of seconds is left out.
		return array_values( array_filter( array_filter( $schedule, 'is_int' ), static fn ( int $wait ): bool => $wait >= 0 ) );
	}
}
