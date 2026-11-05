<?php
/**
 * When a proof's deadline passes and its reminders go out.
 *
 * @package Pixonaut\WooCommerce
 */

declare(strict_types=1);

namespace Pixonaut\WooCommerce\Approval;

/**
 * The times, counted from when the line started waiting for the customer:
 * the deadline after the deadline days, the first reminder after the
 * reminder days, then one every so many days, at most so many. A reminder
 * that would not go out before the deadline is not sent.
 */
final class ProofSchedule {

	private const SECONDS_PER_DAY = 86400;

	/**
	 * @param int $deadline_days   Days the customer has to answer.
	 * @param int $after_days      Days to the first reminder.
	 * @param int $every_days      Days between reminders.
	 * @param int $reminders       Most reminders sent.
	 */
	public function __construct(
		private readonly int $deadline_days,
		private readonly int $after_days,
		private readonly int $every_days,
		private readonly int $reminders,
	) {
	}

	/**
	 * @param int $start Unix time the line started waiting.
	 * @return int Unix time of the deadline.
	 */
	public function deadline( int $start ): int {
		return $start + $this->deadline_days * self::SECONDS_PER_DAY;
	}

	/**
	 * @param int $start Unix time the line started waiting.
	 * @return list<int> Unix times of the reminders, earliest first, each before the deadline.
	 */
	public function reminders( int $start ): array {
		$deadline = $this->deadline( $start );
		$times    = array();
		for ( $number = 0; $number < $this->reminders; $number++ ) {
			$at = $start + ( $this->after_days + $number * max( 1, $this->every_days ) ) * self::SECONDS_PER_DAY;
			if ( $at >= $deadline ) {
				break;
			}
			$times[] = $at;
		}
		return $times;
	}
}
