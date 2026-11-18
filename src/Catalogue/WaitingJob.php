<?php
/**
 * Whether a job waits to start.
 *
 * @package Pixonaut\WooCommerce
 */

declare(strict_types=1);

namespace Pixonaut\WooCommerce\Catalogue;

use ActionScheduler_Store;

/**
 * Asks Action Scheduler for a pending job by its exact arguments.
 * `as_next_scheduled_action()` cannot say it: it answers `true` both for a
 * job running and for an async one waiting, and a time only for one
 * scheduled for later.
 */
final class WaitingJob {

	/**
	 * Whether a job of the hook with exactly these arguments waits to start.
	 * One running does not count: it may have read what changed since.
	 *
	 * @param string       $hook  The job's hook.
	 * @param array<mixed> $args  Its arguments.
	 * @param string       $group Its group.
	 */
	public static function exists( string $hook, array $args, string $group ): bool {
		$ids = as_get_scheduled_actions(
			array(
				'hook'     => $hook,
				'args'     => $args,
				'group'    => $group,
				'status'   => ActionScheduler_Store::STATUS_PENDING,
				'per_page' => 1,
			),
			'ids'
		);
		return array() !== $ids;
	}
}
