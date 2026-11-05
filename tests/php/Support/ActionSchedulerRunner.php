<?php
/**
 * Runs Action Scheduler jobs inside the test, at once.
 *
 * @package Pixonaut\WooCommerce
 */

declare(strict_types=1);

namespace Pixonaut\WooCommerce\Tests\Support;

use ActionScheduler;
use ActionScheduler_Store;
use PHPUnit\Framework\AssertionFailedError;

/**
 * Action Scheduler runs jobs from WP-Cron or an async request, neither of
 * which happens in a test. This runs a hook's pending jobs synchronously,
 * through Action Scheduler's own runner, so a job is claimed, run and marked
 * complete or failed as in production.
 */
final class ActionSchedulerRunner {

	/**
	 * Context Action Scheduler records the runs under.
	 */
	private const CONTEXT = 'pixonaut-tests';

	/**
	 * Rounds of pending jobs run before giving up: jobs that keep scheduling
	 * more are a bug in the test or the code.
	 */
	private const MAX_ROUNDS = 10;

	/**
	 * Runs the hook's pending jobs, and any they schedule, until none is left.
	 *
	 * @param string $hook The jobs' hook.
	 *
	 * @return int How many jobs ran.
	 *
	 * @throws AssertionFailedError When jobs are still pending after MAX_ROUNDS.
	 */
	public static function run_pending( string $hook ): int {
		$ran = 0;
		for ( $round = 0; $round < self::MAX_ROUNDS; ++$round ) {
			$ids = self::pending( $hook );
			if ( array() === $ids ) {
				return $ran;
			}
			foreach ( $ids as $id ) {
				ActionScheduler::runner()->process_action( $id, self::CONTEXT );
				++$ran;
			}
		}
		throw new AssertionFailedError( sprintf( 'Jobs of %s are still pending after %d rounds.', $hook, self::MAX_ROUNDS ) );
	}

	/**
	 * Runs the hook's jobs pending now, due or not, but not the ones they schedule.
	 *
	 * @param string $hook The jobs' hook.
	 *
	 * @return int How many jobs ran.
	 */
	public static function run_once( string $hook ): int {
		$ids = self::pending( $hook );
		foreach ( $ids as $id ) {
			ActionScheduler::runner()->process_action( $id, self::CONTEXT );
		}
		return count( $ids );
	}

	/**
	 * When the hook's pending jobs are due.
	 *
	 * @param string $hook The jobs' hook.
	 *
	 * @return list<int> Unix times, one per job.
	 */
	public static function due_at( string $hook ): array {
		$times = array();
		foreach ( self::pending( $hook ) as $id ) {
			$date    = ActionScheduler::store()->get_date( $id );
			$times[] = $date->getTimestamp();
		}
		return $times;
	}

	/**
	 * @param string $hook The jobs' hook.
	 *
	 * @return list<int> Ids of its pending jobs.
	 */
	public static function pending( string $hook ): array {
		return self::ids( $hook, ActionScheduler_Store::STATUS_PENDING );
	}

	/**
	 * @param string $hook The jobs' hook.
	 *
	 * @return list<int> Ids of its failed jobs.
	 */
	public static function failed( string $hook ): array {
		return self::ids( $hook, ActionScheduler_Store::STATUS_FAILED );
	}

	/**
	 * @param string $hook   The jobs' hook.
	 * @param string $status A job status.
	 *
	 * @return list<int>
	 */
	private static function ids( string $hook, string $status ): array {
		$ids = as_get_scheduled_actions(
			array(
				'hook'     => $hook,
				'status'   => $status,
				'per_page' => -1,
			),
			'ids'
		);
		return array_values( array_map( 'intval', $ids ) );
	}
}
