<?php
/**
 * Runs the pictures' jobs at once, side by side.
 *
 * @package Pixonaut\WooCommerce
 */

declare(strict_types=1);

namespace Pixonaut\WooCommerce\Catalogue;

use ActionScheduler;
use ActionScheduler_Compatibility;
use ActionScheduler_FatalErrorMonitor;
use Pixonaut\WooCommerce\Schema;
use WP_Async_Request;

/**
 * Action Scheduler starts its own runner from admin pages at most once a
 * minute, and otherwise waits for WP-Cron, so a drawing the store manager
 * asked for could wait minutes to start. `start()` sends `RUNNERS` requests
 * back to the store, as Action Scheduler's async runner does, each of which
 * claims the pictures' jobs due now one at a time and runs them: a product's
 * and its variations' pictures are drawn side by side. A job no runner
 * reaches is run by Action Scheduler as before.
 *
 * At most `RUNNERS` run in the store at once, however often it is started:
 * each holds one of `RUNNERS` slots while it runs.
 */
final class PictureRunner extends WP_Async_Request {

	/**
	 * How many runners run in the store at once, so how many pictures' jobs.
	 */
	public const RUNNERS = 4;

	/**
	 * Prefix of the option a runner holds while it runs, followed by its slot.
	 */
	public const SLOT_OPTION_PREFIX = Schema::PICTURE_RUNNER_OPTION_PREFIX;

	/**
	 * How long a runner claims jobs; one it started runs to its end.
	 */
	public const RUN_SECONDS = 60;

	/**
	 * How long a slot is held before another runner may take it over: a
	 * runner claims jobs for `RUN_SECONDS`, and the last one it claimed may
	 * run for as long as a drawing holds its product's lock.
	 */
	public const STALE_SECONDS = self::RUN_SECONDS + ProductPictures::LOCK_SECONDS;

	/**
	 * The jobs a runner claims: a product's or variation's drawing, and the
	 * check that queues a variable product's variations.
	 */
	private const HOOKS = array( ProductPictures::ACTION, PictureQueue::VARIATIONS_ACTION );

	/**
	 * Context Action Scheduler records the runs under.
	 */
	private const CONTEXT = 'Pixonaut pictures';

	/**
	 * A runner claims no further job once it uses this share of PHP's memory
	 * limit, as Action Scheduler's own runner stops.
	 */
	private const MEMORY_SHARE = 0.9;

	/**
	 * @var \Closure|null `fn(): int` the memory in use, in bytes; `memory_get_usage()` when null.
	 */
	private readonly ?\Closure $memory;

	/**
	 * Its admin-ajax action is `pixonaut_wc_pictures`.
	 *
	 * @var string
	 */
	protected $prefix = 'pixonaut_wc';

	/**
	 * @var string
	 */
	protected $action = 'pictures';

	/**
	 * @param \Closure|null $memory `fn(): int` the memory in use, in bytes; `memory_get_usage()` when null.
	 */
	public function __construct( ?\Closure $memory = null ) {
		parent::__construct();
		$this->memory = $memory;
	}

	/**
	 * Sends a runner for each free slot. Each request returns at once; the
	 * runners run on.
	 */
	public function start(): void {
		for ( $slot = 0; $slot < self::RUNNERS; ++$slot ) {
			if ( null === self::holder( $slot ) ) {
				$this->dispatch();
			}
		}
	}

	/**
	 * Runs the pictures' jobs due now, one at a time, until none is left,
	 * `RUN_SECONDS` passed or memory runs short. Does nothing while every
	 * slot is held.
	 *
	 * @return int How many jobs it ran.
	 */
	public function run(): int {
		$slot = self::take();
		if ( null === $slot ) {
			return 0;
		}
		try {
			return $this->claim_and_run();
		} finally {
			self::release( $slot[0], $slot[1] );
		}
	}

	private function claim_and_run(): int {
		ActionScheduler_Compatibility::raise_time_limit( self::RUN_SECONDS + ProductPictures::LOCK_SECONDS );
		$store    = ActionScheduler::store();
		$deadline = time() + self::RUN_SECONDS;
		$ran      = 0;
		while ( time() < $deadline && ! $this->memory_short() ) {
			// By hook alone: a store that never held a job of the group refuses to claim by it.
			$claim = $store->stake_claim( 1, null, self::HOOKS );
			$ids   = $claim->get_actions();
			// Marks the claimed job failed when PHP dies while it runs, as Action Scheduler's runner does.
			$monitor = new ActionScheduler_FatalErrorMonitor( $store );
			$monitor->attach( $claim );
			try {
				foreach ( $ids as $id ) {
					ActionScheduler::runner()->process_action( $id, self::CONTEXT );
					++$ran;
				}
			} finally {
				$monitor->detach();
				$store->release_claim( $claim );
			}
			if ( array() === $ids ) {
				break;
			}
			if ( wp_cache_supports( 'flush_runtime' ) ) {
				wp_cache_flush_runtime();
			}
		}
		return $ran;
	}

	/**
	 * Whether the runner uses `MEMORY_SHARE` of PHP's memory limit already;
	 * never when there is no limit.
	 */
	private function memory_short(): bool {
		$limit = wp_convert_hr_to_bytes( (string) ini_get( 'memory_limit' ) );
		if ( $limit <= 0 ) {
			return false;
		}
		$used = null === $this->memory ? memory_get_usage( true ) : ( $this->memory )();
		return $used >= $limit * self::MEMORY_SHARE;
	}

	/**
	 * Takes a free slot. `add_option()` inserts or fails atomically, so two
	 * runners never hold one; a slot held longer than a runner runs is taken
	 * over.
	 *
	 * @return array{int, string}|null The slot and the value it holds, for `release()`; null when every slot is held.
	 */
	private static function take(): ?array {
		$now = (string) time();
		for ( $slot = 0; $slot < self::RUNNERS; ++$slot ) {
			$held = self::take_slot( $slot, $now );
			if ( null !== $held ) {
				return $held;
			}
		}
		return null;
	}

	/**
	 * Takes the slot, when it is free or held too long.
	 *
	 * @param int    $slot The slot.
	 * @param string $now  The time, as Unix time.
	 *
	 * @return array{int, string}|null The slot and the value it holds; null when another runner holds it.
	 */
	private static function take_slot( int $slot, string $now ): ?array {
		$option = self::SLOT_OPTION_PREFIX . $slot;
		if ( self::add( $option, $now ) ) {
			return array( $slot, $now );
		}
		if ( null !== self::holder( $slot ) ) {
			return null;
		}
		delete_option( $option );
		return self::add( $option, $now ) ? array( $slot, $now ) : null;
	}

	/**
	 * Adds the slot's option, which fails when it is there already.
	 *
	 * @phpstan-impure
	 *
	 * @param string $option The slot's option.
	 * @param string $now    The time, as Unix time.
	 */
	private static function add( string $option, string $now ): bool {
		return add_option( $option, $now, '', false );
	}

	/**
	 * When the slot's runner started, or null when the slot is free or its
	 * runner started longer ago than a runner runs.
	 *
	 * @param int $slot The slot.
	 */
	private static function holder( int $slot ): ?int {
		$option = self::SLOT_OPTION_PREFIX . $slot;
		wp_cache_delete( $option, 'options' );
		$held_since = get_option( $option );
		if ( ! is_numeric( $held_since ) || time() - (int) $held_since >= self::STALE_SECONDS ) {
			return null;
		}
		return (int) $held_since;
	}

	/**
	 * Gives up the slot, unless another runner took it over meanwhile.
	 *
	 * @param int    $slot The slot.
	 * @param string $held The slot's value when this runner took it.
	 */
	private static function release( int $slot, string $held ): void {
		$option = self::SLOT_OPTION_PREFIX . $slot;
		wp_cache_delete( $option, 'options' );
		if ( get_option( $option ) === $held ) {
			delete_option( $option );
		}
	}

	protected function handle(): void {
		$this->run();
	}
}
