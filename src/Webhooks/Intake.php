<?php
/**
 * Takes in an event, however it reached the store.
 *
 * @package Pixonaut\WooCommerce
 */

declare(strict_types=1);

namespace Pixonaut\WooCommerce\Webhooks;

use Closure;
use Pixonaut\WooCommerce\Api\Logger;

/**
 * Keeps each new event once and queues the job that handles it, whether the
 * event came in a webhook delivery or a pull of the event stream, so an event
 * that arrives both ways is handled once. An event kept already is dropped,
 * after queueing it again when it was never handled and has no job.
 */
final class Intake {

	/**
	 * What take() returns when the event was new and its job is queued.
	 */
	public const TAKEN = 'taken';

	/**
	 * What take() returns when the event was kept already; its job is queued
	 * again when it was never handled and had none.
	 */
	public const DUPLICATE = 'duplicate';

	/**
	 * What take() returns when the event is too long to keep.
	 */
	public const TOO_LONG = 'too_long';

	/**
	 * What take() returns when the database refused the event; nothing is kept.
	 */
	public const NOT_KEPT = 'not_kept';

	/**
	 * What take() returns when Action Scheduler refused the job. A new event
	 * is forgotten then, so it is taken as new the next time it comes.
	 */
	public const NOT_QUEUED = 'not_queued';

	private readonly Closure $clock;

	/**
	 * @param EventStore   $events    Events received.
	 * @param Processor    $processor Queues the job that handles an event.
	 * @param Logger       $logger    Logs events not taken; never bodies.
	 * @param Closure|null $clock     `fn(): int` Unix time; `time()` when null.
	 */
	public function __construct(
		private readonly EventStore $events,
		private readonly Processor $processor,
		private readonly Logger $logger,
		?Closure $clock = null,
	) {
		$this->clock = $clock ?? static fn (): int => time();
	}

	/**
	 * @param Event  $event The event.
	 * @param string $body  The envelope as it came, kept for the job.
	 * @phpstan-return self::TAKEN|self::DUPLICATE|self::TOO_LONG|self::NOT_KEPT|self::NOT_QUEUED
	 */
	public function take( Event $event, string $body ): string {
		if ( strlen( $event->id ) > EventStore::MAX_ID_LENGTH || strlen( $event->type ) > EventStore::MAX_TYPE_LENGTH ) {
			$this->logger->warning( 'Event not taken: its id or type is too long to keep.', array( 'type' => substr( $event->type, 0, EventStore::MAX_TYPE_LENGTH ) ) );
			return self::TOO_LONG;
		}
		$kept = $this->events->add( $event, $body, ( $this->clock )() );
		if ( EventStore::FAILED === $kept ) {
			$this->logger->error(
				'Event not kept: the database refused it.',
				array(
					'event' => $event->id,
					'type'  => $event->type,
				)
			);
			return self::NOT_KEPT;
		}
		if ( EventStore::DUPLICATE === $kept ) {
			return $this->duplicate( $event );
		}
		if ( ! $this->processor->queue( $event->id ) ) {
			$this->events->remove( $event->id );
			$this->logger->error( 'Event not queued: Action Scheduler refused the job.', array( 'event' => $event->id ) );
			return self::NOT_QUEUED;
		}
		return self::TAKEN;
	}

	/**
	 * @param Event $event An event kept already.
	 * @phpstan-return self::DUPLICATE|self::NOT_QUEUED
	 */
	private function duplicate( Event $event ): string {
		if ( $this->events->processed( $event->id ) || $this->processor->is_queued( $event->id ) ) {
			return self::DUPLICATE;
		}
		if ( ! $this->processor->queue( $event->id ) ) {
			$this->logger->error( 'Event not queued again: Action Scheduler refused the job.', array( 'event' => $event->id ) );
			return self::NOT_QUEUED;
		}
		return self::DUPLICATE;
	}
}
