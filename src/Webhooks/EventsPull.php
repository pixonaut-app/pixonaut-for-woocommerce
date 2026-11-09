<?php
/**
 * Pulls the event stream, so an event whose delivery was lost is handled too.
 *
 * @package Pixonaut\WooCommerce
 */

declare(strict_types=1);

namespace Pixonaut\WooCommerce\Webhooks;

// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- exception messages here are reasons and log text, never printed as they are: whoever shows one escapes it.

use Closure;
use Pixonaut\WooCommerce\Api\Answers\ConnectedStore;
use Pixonaut\WooCommerce\Api\Answers\EventsPage;
use Pixonaut\WooCommerce\Api\Answers\WebhookEndpoint;
use Pixonaut\WooCommerce\Connection\ConnectionStore;
use Pixonaut\WooCommerce\Connection\StoredConnection;
use Pixonaut\WooCommerce\Schema;
use Pixonaut\WooCommerce\Vendor\Pixonaut\Sdk\Exception\ApiException;
use Pixonaut\WooCommerce\Vendor\Pixonaut\Sdk\Generated\ErrorCode;
use Pixonaut\WooCommerce\Vendor\Pixonaut\Sdk\Pixonaut as Sdk;
use Pixonaut\WooCommerce\Vendor\Pixonaut\Sdk\Webhooks\Event;
use Pixonaut\WooCommerce\Vendor\Psr\Log\LoggerInterface;
use RuntimeException;
use UnexpectedValueException;

/**
 * Every PULL_INTERVAL_SECONDS a job reads the events recorded after the
 * stored cursor and hands each to the intake, the same one the webhook
 * endpoint uses, so an event that came both ways is handled once. Then it
 * asks Pixonaut to deliver again what failed to reach the endpoint, unless
 * Pixonaut reads the store as unreachable: it delivers nothing there, and
 * pulling is how that store gets its events.
 *
 * The first cursor of a connection is set by paging to the stream's end
 * without acting on anything: what happened before the store connected is
 * not the store's to replay. Then `pixonaut_wc_events_baseline_set` fires,
 * and what reads the store's state afresh hangs on it. Connecting again sets
 * a new first cursor; disconnecting forgets the cursor. While the connection
 * is paused nothing is pulled, and the pull goes on from the cursor after.
 *
 * The cursor and the baseline's progress name the connection they belong
 * to, and a job writes them only while that connection is still the stored
 * one: a job still running when the store connects again or disconnects
 * leaves nothing behind for the connection after.
 */
final class EventsPull {

	public const PULL_HOOK = 'pixonaut_wc_events_pull';

	public const BASELINE_HOOK = 'pixonaut_wc_events_baseline';

	public const GROUP = Schema::ACTION_GROUP;

	public const CURSOR_OPTION = Schema::EVENTS_CURSOR_OPTION;

	public const BASELINE_OPTION = Schema::EVENTS_BASELINE_OPTION;

	/**
	 * How often the stream is pulled: fifteen minutes.
	 */
	public const PULL_INTERVAL_SECONDS = 900;

	/**
	 * Pages one job reads at most, so a long stream cannot hold a job past
	 * Action Scheduler's time limit; the next job goes on from where it
	 * stopped.
	 */
	public const MAX_PAGES_PER_JOB = 20;

	private readonly Closure $clock;

	/**
	 * @param ConnectionStore $store     The stored connection, and whether it is paused.
	 * @param Sdk             $api       Reads the event stream, the endpoint's failed deliveries and whether Pixonaut can reach the store, and sends the deliveries again.
	 * @param Intake          $intake    Keeps and queues each event pulled.
	 * @param LoggerInterface $logger    Logs what could not be pulled.
	 * @param Closure|null    $clock     `fn(): int` Unix time; `time()` when null.
	 */
	public function __construct(
		private readonly ConnectionStore $store,
		private readonly Sdk $api,
		private readonly Intake $intake,
		private readonly LoggerInterface $logger,
		?Closure $clock = null,
	) {
		$this->clock = $clock ?? static fn (): int => time();
	}

	public function register(): void {
		add_action( self::PULL_HOOK, array( $this, 'pull' ), 10, 0 );
		add_action( self::BASELINE_HOOK, array( $this, 'set_baseline' ), 10, 0 );
		add_action( 'action_scheduler_init', array( $this, 'schedule' ), 10, 0 );
		add_action( 'pixonaut_wc_connection_connected', array( $this, 'start_baseline' ), 10, 0 );
		add_action( 'pixonaut_wc_connection_disconnected', array( $this, 'forget' ), 10, 0 );
	}

	/**
	 * Keeps the recurring pull scheduled while the store is connected.
	 */
	public function schedule(): void {
		if ( null !== $this->store->load() && ! as_has_scheduled_action( self::PULL_HOOK, null, self::GROUP ) ) {
			as_schedule_recurring_action( ( $this->clock )() + self::PULL_INTERVAL_SECONDS, self::PULL_INTERVAL_SECONDS, self::PULL_HOOK, array(), self::GROUP );
		}
	}

	/**
	 * Drops the cursor and queues the job that sets a new first one: the
	 * store connected, or connected again.
	 */
	public function start_baseline(): void {
		delete_option( self::CURSOR_OPTION );
		delete_option( self::BASELINE_OPTION );
		as_unschedule_all_actions( self::BASELINE_HOOK, array(), self::GROUP );
		as_enqueue_async_action( self::BASELINE_HOOK, array(), self::GROUP );
		$this->schedule();
	}

	/**
	 * Forgets the cursor and stops pulling: the store disconnected.
	 */
	public function forget(): void {
		delete_option( self::CURSOR_OPTION );
		delete_option( self::BASELINE_OPTION );
		as_unschedule_all_actions( self::BASELINE_HOOK, array(), self::GROUP );
		as_unschedule_all_actions( self::PULL_HOOK, array(), self::GROUP );
	}

	/**
	 * The job that sets the first cursor: pages to the stream's end without
	 * acting on any event, stores the cursor, then fires
	 * `pixonaut_wc_events_baseline_set`. A stream longer than one job reads
	 * is paged on in the next.
	 */
	public function set_baseline(): void {
		$connection = $this->active();
		if ( null === $connection || null !== self::cursor( $connection ) ) {
			return;
		}
		$after_start = self::stored_after( self::BASELINE_OPTION, $connection )['after'] ?? null;
		$after       = $after_start;
		try {
			for ( $page = 0; $page < self::MAX_PAGES_PER_JOB; $page++ ) {
				$read  = $this->page( $after );
				$after = self::moved_on( $after, $read );
				if ( null === $read['next_cursor'] ) {
					$this->baseline_set( $after, $connection );
					return;
				}
			}
		} catch ( ApiException $e ) {
			if ( ErrorCode::UNKNOWN_EVENT === $e->errorCode && null !== $after_start && $this->is_current( $connection ) ) {
				// Pixonaut no longer keeps the event paging stopped at: page again from the stream's start.
				$this->logger->warning( 'The first event cursor was paging from an event Pixonaut no longer keeps; starting over.' );
				delete_option( self::BASELINE_OPTION );
				as_enqueue_async_action( self::BASELINE_HOOK, array(), self::GROUP );
				return;
			}
			$this->logger->error( 'The first event cursor could not be set: ' . $e->getMessage(), array( 'code' => $e->errorCode ) );
			// The next pull queues this job again.
			return;
		}
		if ( $this->write_after( self::BASELINE_OPTION, $after, $connection ) ) {
			as_enqueue_async_action( self::BASELINE_HOOK, array(), self::GROUP );
		}
	}

	/**
	 * The recurring job: hands every event recorded after the cursor to the
	 * intake, moving the cursor past each one taken, then asks for the
	 * endpoint's failed deliveries again. An event that could not be kept or
	 * queued stops the pull with the cursor before it, so the next pull
	 * reads it again.
	 */
	public function pull(): void {
		$this->pull_now();
	}

	/**
	 * As `pull()`, at once, saying whether it read the events.
	 *
	 * @return bool False when there is no connection, the first cursor is not
	 *              set yet, or the pull stopped.
	 */
	public function pull_now(): bool {
		$connection = $this->active();
		if ( null === $connection ) {
			return false;
		}
		$after = self::cursor( $connection );
		if ( null === $after ) {
			// The first cursor is not set yet, or the job setting it was lost.
			if ( ! as_has_scheduled_action( self::BASELINE_HOOK, null, self::GROUP ) ) {
				as_enqueue_async_action( self::BASELINE_HOOK, array(), self::GROUP );
			}
			return false;
		}
		try {
			if ( ! $this->pull_from( $after['after'], $connection ) ) {
				return false;
			}
		} catch ( ApiException $e ) {
			if ( ErrorCode::UNKNOWN_EVENT === $e->errorCode ) {
				// Pixonaut no longer keeps the cursor's event: start over from the stream's end.
				$this->logger->warning( 'The event cursor is no event Pixonaut keeps; setting a new one.' );
				$this->start_baseline();
				return false;
			}
			$this->logger->error( 'Events not pulled: ' . $e->getMessage(), array( 'code' => $e->errorCode ) );
			return false;
		} catch ( RuntimeException $e ) {
			$this->logger->error( $e->getMessage() );
			return false;
		}
		$this->redeliver_failed( $connection );
		return true;
	}

	/**
	 * @param string|null      $after      The cursor.
	 * @param StoredConnection $connection The connection the cursor belongs to.
	 * @return bool False when the connection changed meanwhile, and the pull stopped.
	 *
	 * @throws ApiException When a page could not be read.
	 * @throws RuntimeException When an event could not be taken.
	 */
	private function pull_from( ?string $after, StoredConnection $connection ): bool {
		for ( $page = 0; $page < self::MAX_PAGES_PER_JOB; $page++ ) {
			$read = $this->page( $after );
			foreach ( $read['events'] as $envelope ) {
				if ( ! $this->is_current( $connection ) ) {
					return false;
				}
				$this->take( $envelope );
				$after = is_string( $envelope['id'] ?? null ) ? $envelope['id'] : $after;
				if ( ! $this->write_after( self::CURSOR_OPTION, $after, $connection ) ) {
					return false;
				}
			}
			$after = self::moved_on( $after, $read );
			if ( ! $this->write_after( self::CURSOR_OPTION, $after, $connection ) ) {
				return false;
			}
			if ( null === $read['next_cursor'] ) {
				return true;
			}
		}
		return true;
	}

	/**
	 * @param array<string, mixed> $envelope A pulled event.
	 *
	 * @throws RuntimeException When it could not be kept or queued.
	 */
	private function take( array $envelope ): void {
		try {
			$event = Event::fromArray( $envelope );
		} catch ( UnexpectedValueException $e ) {
			$this->logger->warning( 'Pulled event skipped: ' . $e->getMessage() );
			return;
		}
		$body = wp_json_encode( $envelope );
		if ( false === $body ) {
			$this->logger->warning( 'Pulled event skipped: it cannot be encoded.', array( 'event' => $event->id ) );
			return;
		}
		$taken = $this->intake->take( $event, $body );
		if ( Intake::NOT_KEPT === $taken || Intake::NOT_QUEUED === $taken ) {
			throw new RuntimeException( sprintf( 'Pulled event %s was not taken (%s); the next pull reads it again.', $event->id, $taken ) );
		}
	}

	/**
	 * Asks Pixonaut to deliver again what failed to reach the store's endpoint,
	 * unless it cannot reach the store at all. A failure is logged, but for
	 * `endpoint_unreachable`, which says the same as the read when the address
	 * stopped answering after it; the pull already took the events.
	 *
	 * @param StoredConnection $connection The connection.
	 */
	private function redeliver_failed( StoredConnection $connection ): void {
		try {
			$endpoint = null;
			foreach ( WebhookEndpoint::all( $this->api ) as $listed ) {
				if ( $listed->id === $connection->webhook_id ) {
					$endpoint = $listed;
				}
			}
			if ( null !== $endpoint && $endpoint->has_failed() && ConnectedStore::get( $this->api )->reachable ) {
				$this->api->webhooks()->redeliverFailed( $connection->webhook_id );
			}
		} catch ( ApiException $e ) {
			if ( ErrorCode::ENDPOINT_UNREACHABLE === $e->errorCode ) {
				return;
			}
			$this->logger->warning( 'Failed webhook deliveries not sent again: ' . $e->getMessage(), array( 'code' => $e->errorCode ) );
		}
	}

	/**
	 * Stores the first cursor and tells what reads the store's state afresh;
	 * nothing when the connection changed meanwhile.
	 *
	 * @param string|null      $after      The stream's end; null when it holds no event.
	 * @param StoredConnection $connection The connection.
	 */
	private function baseline_set( ?string $after, StoredConnection $connection ): void {
		if ( ! $this->write_after( self::CURSOR_OPTION, $after, $connection ) ) {
			return;
		}
		delete_option( self::BASELINE_OPTION );

		/**
		 * Fires once per connection, after the first cursor of the event
		 * stream was set at its end: every event recorded before was skipped,
		 * and the pull goes on from there. What can have changed while the
		 * store was not connected, its linked products and its open order
		 * lines, is read afresh on this action.
		 *
		 * @since 0.1.0
		 *
		 * @param array<string, mixed> $connection `site`, `home_url`, `connected_at`, `tenant_id`, `tenant_name`, `environment`, `connection_id`, `integration_key_label`; no keys.
		 *
		 * @example
		 * add_action( 'pixonaut_wc_events_baseline_set', function ( $connection ) {
		 *     error_log( 'Pulling events for ' . $connection['tenant_name'] );
		 * } );
		 */
		do_action( 'pixonaut_wc_events_baseline_set', $connection->summary() );
	}

	/**
	 * `GET /v1/events?after=&types=`: one page of the events recorded after
	 * an event, in the order they were recorded, each in the envelope a
	 * webhook delivery carries.
	 *
	 * @param string|null $after The id of the last event looked at; from the stream's start when null.
	 *
	 * @return array{events: list<array<string, mixed>>, next_cursor: string|null} `next_cursor` is the `after` of the next page; null when nothing more is available yet.
	 *
	 * @throws ApiException When Pixonaut refused (`unknown_event` for an `after` it does not know) or could not be reached.
	 */
	private function page( ?string $after ): array {
		$page = EventsPage::get( $this->api, $after, implode( ',', Endpoint::EVENT_TYPES ) );
		return array(
			'events'      => $page->events,
			'next_cursor' => '' !== $page->next_cursor ? $page->next_cursor : null,
		);
	}

	/**
	 * The cursor after a page: its `nextCursor`, or else the last event's id,
	 * or else the cursor as it was.
	 *
	 * @param string|null                                                         $after The cursor before the page.
	 * @param array{events: list<array<string, mixed>>, next_cursor: string|null} $read  The page.
	 */
	public static function moved_on( ?string $after, array $read ): ?string {
		if ( null !== $read['next_cursor'] ) {
			return $read['next_cursor'];
		}
		$last = end( $read['events'] );
		return is_array( $last ) && is_string( $last['id'] ?? null ) && '' !== $last['id'] ? $last['id'] : $after;
	}

	/**
	 * The connection's cursor; null until its first one is set.
	 *
	 * @param StoredConnection $connection The connection.
	 * @return array{after: string|null}|null
	 */
	private static function cursor( StoredConnection $connection ): ?array {
		return self::stored_after( self::CURSOR_OPTION, $connection );
	}

	/**
	 * What the option holds for the connection; null when it is absent or
	 * belongs to another connection.
	 *
	 * @param string           $option     CURSOR_OPTION or BASELINE_OPTION.
	 * @param StoredConnection $connection The connection.
	 * @return array{after: string|null}|null
	 */
	private static function stored_after( string $option, StoredConnection $connection ): ?array {
		$stored = get_option( $option );
		if ( ! is_array( $stored ) || ! array_key_exists( 'after', $stored ) || ( $stored['connection'] ?? null ) !== $connection->connection_id ) {
			return null;
		}
		return array( 'after' => is_string( $stored['after'] ) && '' !== $stored['after'] ? $stored['after'] : null );
	}

	/**
	 * Stores $after in the option for the connection, unless it is no longer
	 * the stored one.
	 *
	 * @param string           $option     CURSOR_OPTION or BASELINE_OPTION.
	 * @param string|null      $after      An event id; null for the stream's start.
	 * @param StoredConnection $connection The connection the job started with.
	 * @return bool Whether it was stored.
	 */
	private function write_after( string $option, ?string $after, StoredConnection $connection ): bool {
		if ( ! $this->is_current( $connection ) ) {
			return false;
		}
		update_option(
			$option,
			array(
				'after'      => $after,
				'connection' => $connection->connection_id,
			),
			false
		);
		return true;
	}

	/**
	 * Whether the connection a job started with is still the stored one.
	 *
	 * @param StoredConnection $connection The connection.
	 */
	private function is_current( StoredConnection $connection ): bool {
		return $this->store->load()?->connection_id === $connection->connection_id;
	}

	/**
	 * The connection to pull for; null while there is none or it is paused.
	 */
	private function active(): ?StoredConnection {
		return null === $this->store->paused() ? $this->store->load() : null;
	}
}
