<?php
/**
 * Handles webhook events in Action Scheduler jobs.
 *
 * @package Pixonaut\WooCommerce
 */

declare(strict_types=1);

namespace Pixonaut\WooCommerce\Webhooks;

use Closure;
use Pixonaut\WooCommerce\Api\Logger;
use Pixonaut\WooCommerce\Schema;
use Throwable;
use UnexpectedValueException;

/**
 * One job per event, which runs the handler its type maps to and then fires
 * the type's action. A type with no handler is ignored, its action still
 * fired. A job that fails is tried again later, up to MAX_ATTEMPTS times in
 * all. While events are held, e.g. on a copied site, a job leaves its event
 * untouched. A daily maintenance job queues again the events left unhandled
 * with no job, and drops events past their retention.
 */
final class Processor {

	public const PROCESS_HOOK = 'pixonaut_wc_webhook_process';

	public const MAINTENANCE_HOOK = 'pixonaut_wc_webhook_maintenance';

	public const GROUP = Schema::ACTION_GROUP;

	/**
	 * Attempts at handling an event, the first one included, before it is
	 * given up on.
	 */
	public const MAX_ATTEMPTS = 5;

	/**
	 * Wait before the second attempt; it doubles with every attempt after.
	 */
	public const RETRY_BASE_SECONDS = 60;

	/**
	 * An unhandled event this old with no job is taken to have lost it.
	 */
	public const STRANDED_AFTER_SECONDS = 3600; // An hour.

	/**
	 * Stranded events queued again per maintenance run.
	 */
	public const STRANDED_BATCH = 100;

	/**
	 * How often the maintenance job runs: a day.
	 */
	private const MAINTENANCE_INTERVAL_SECONDS = 86400;

	private readonly Closure $clock;

	private readonly Closure $held;

	/**
	 * @param EventStore                           $events   Events received.
	 * @param array<string, callable(Event): void> $handlers Handler per event type.
	 * @param Logger                               $logger   Logs events that cannot be read.
	 * @param Closure|null                         $clock    `fn(): int` Unix time; `time()` when null.
	 * @param Closure|null                         $held     `fn(): bool` true while events must wait; never when null.
	 */
	public function __construct(
		private readonly EventStore $events,
		private readonly array $handlers,
		private readonly Logger $logger,
		?Closure $clock = null,
		?Closure $held = null,
	) {
		$this->clock = $clock ?? static fn (): int => time();
		$this->held  = $held ?? static fn (): bool => false;
	}

	public function register(): void {
		add_action( self::PROCESS_HOOK, array( $this, 'process' ) );
		add_action( self::MAINTENANCE_HOOK, array( $this, 'maintain' ), 10, 0 );
		add_action( 'action_scheduler_init', array( $this, 'schedule_maintenance' ), 10, 0 );
	}

	/**
	 * Queues the job that handles a kept event.
	 *
	 * @param string $event_id Event id.
	 * @return bool False when Action Scheduler did not take the job.
	 */
	public function queue( string $event_id ): bool {
		return 0 !== as_enqueue_async_action( self::PROCESS_HOOK, array( $event_id ), self::GROUP );
	}

	/**
	 * Whether a job for the event is pending or running.
	 *
	 * @param string $event_id Event id.
	 */
	public function is_queued( string $event_id ): bool {
		return as_has_scheduled_action( self::PROCESS_HOOK, array( $event_id ), self::GROUP );
	}

	/**
	 * Handles a kept event once. When the handler or an action throws, the
	 * event is tried again later while it has attempts left, and the
	 * exception is rethrown so Action Scheduler records the failure. While
	 * events are held the job ends without counting an attempt; maintenance
	 * queues the event again, and it is handled once they no longer are.
	 *
	 * @param string $event_id Event id.
	 * @throws Throwable What the handler or an action threw.
	 */
	public function process( string $event_id ): void {
		$body = $this->events->body( $event_id );
		if ( null === $body || $this->events->processed( $event_id ) ) {
			return;
		}
		if ( ( $this->held )() ) {
			return;
		}
		$attempt = $this->events->begin_attempt( $event_id );
		try {
			$event = Event::from_json( $body );
		} catch ( UnexpectedValueException $e ) {
			$this->logger->error( 'Kept webhook event cannot be read: ' . $e->getMessage(), array( 'event' => $event_id ) );
			return;
		}
		try {
			$this->handle( $event );
		} catch ( Throwable $e ) {
			$this->logger->error(
				'Webhook event not handled: ' . $e->getMessage(),
				array(
					'event'   => $event_id,
					'type'    => $event->type,
					'attempt' => $attempt,
				)
			);
			if ( $attempt < self::MAX_ATTEMPTS ) {
				$delay = self::RETRY_BASE_SECONDS * 2 ** ( $attempt - 1 );
				as_schedule_single_action( ( $this->clock )() + $delay, self::PROCESS_HOOK, array( $event_id ), self::GROUP );
			}
			throw $e;
		}

		$this->events->mark_processed( $event_id, ( $this->clock )() );
	}

	/**
	 * Queues again the events stranded without a job, then drops events past
	 * their retention.
	 */
	public function maintain(): void {
		$now = ( $this->clock )();
		foreach ( $this->events->stranded( $now - self::STRANDED_AFTER_SECONDS, self::STRANDED_BATCH, self::MAX_ATTEMPTS ) as $event_id ) {
			if ( ! $this->is_queued( $event_id ) && ! $this->queue( $event_id ) ) {
				$this->logger->error( 'Stranded webhook event not queued: Action Scheduler refused the job.', array( 'event' => $event_id ) );
			}
		}
		$this->events->prune( $now );
	}

	public function schedule_maintenance(): void {
		if ( ! as_has_scheduled_action( self::MAINTENANCE_HOOK, null, self::GROUP ) ) {
			as_schedule_recurring_action( ( $this->clock )() + self::MAINTENANCE_INTERVAL_SECONDS, self::MAINTENANCE_INTERVAL_SECONDS, self::MAINTENANCE_HOOK, array(), self::GROUP );
		}
	}

	/**
	 * Runs the event's handler, then fires its action.
	 *
	 * @param Event $event The event.
	 */
	private function handle( Event $event ): void {
		/**
		 * Filters whether the plugin handles a webhook event. Return false to
		 * take the event over: the plugin's own handler then does not run.
		 * The event's `pixonaut_wc_webhook_<type>` action fires either way.
		 *
		 * Runs in the Action Scheduler job that handles the event, once per
		 * event id, after the signature was verified; again only when an
		 * earlier attempt threw and the event is tried again.
		 *
		 * @since 0.1.0
		 *
		 * @param bool  $handle Whether the plugin handles it.
		 * @param Event $event  The event: `id`, `type`, `created_at`, `payload`.
		 *
		 * @example
		 * add_filter( 'pixonaut_wc_webhook_handle', function ( $handle, $event ) {
		 *     return 'order.status_changed' === $event->type ? false : $handle;
		 * }, 10, 2 );
		 */
		$handle  = (bool) apply_filters( 'pixonaut_wc_webhook_handle', true, $event );
		$handler = $this->handlers[ $event->type ] ?? null;
		if ( $handle && null !== $handler ) {
			$handler( $event );
		}

		$type = $event->hook_name();

		/**
		 * Fires after a webhook event was handled, one hook per event type
		 * with its dots as underscores: `pixonaut_wc_webhook_order_status_changed`,
		 * `pixonaut_wc_webhook_design_approved` and so on. Fires for every
		 * type Pixonaut delivers, also those the plugin does not handle,
		 * once per event id; again only when an earlier attempt threw and
		 * the event is tried again.
		 *
		 * @since 0.1.0
		 *
		 * @param array<string, mixed> $payload The event's payload.
		 * @param Event                $event   The event: `id`, `type`, `created_at`, `payload`.
		 * @param bool                 $handled Whether the plugin's own handler ran.
		 *
		 * @example
		 * add_action( 'pixonaut_wc_webhook_order_status_changed', function ( $payload ) {
		 *     error_log( "Pixonaut order {$payload['orderId']} is {$payload['status']}" );
		 * } );
		 */
		do_action( "pixonaut_wc_webhook_{$type}", $event->payload, $event, $handle && null !== $handler );
	}
}
