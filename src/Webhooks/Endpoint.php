<?php
/**
 * The store's webhook endpoint: what it receives, and whether it works.
 *
 * @package Pixonaut\WooCommerce
 */

declare(strict_types=1);

namespace Pixonaut\WooCommerce\Webhooks;

use Closure;
use Pixonaut\WooCommerce\Api\Answers\WebhookEndpoint;
use Pixonaut\WooCommerce\Api\Answers\WebhookPing;
use Pixonaut\WooCommerce\Connection\Connection;
use Pixonaut\WooCommerce\Connection\StoredConnection;
use Pixonaut\WooCommerce\Schema;
use Pixonaut\WooCommerce\Vendor\Pixonaut\Sdk\Exception\ApiException;
use Pixonaut\WooCommerce\Vendor\Pixonaut\Sdk\Generated\Model\WebhookEndpointsUpdateRequest;
use Pixonaut\WooCommerce\Vendor\Pixonaut\Sdk\Pixonaut as Sdk;
use Pixonaut\WooCommerce\Vendor\Psr\Log\LoggerInterface;

/**
 * Pixonaut registers the endpoint when the store connects, subscribed to
 * every event type. Right after, the store narrows it to the types it
 * handles and has Pixonaut send it a test request, whose outcome the
 * connection card shows until the next one.
 */
final class Endpoint {

	/**
	 * The event types the store receives: the `webhooks` of
	 * `contracts/pixonaut/consumed.json` but `webhook.ping`, which is sent
	 * to every endpoint and is no type one subscribes to.
	 */
	public const EVENT_TYPES = array(
		'blank.deleted',
		'blank.restored',
		'blank.updated',
		'design.approved',
		'design.changes_requested',
		'design.deleted',
		'design.edited',
		'design.personal_data_erased',
		'design.rejected',
		'order.cancelled',
		'order.delivery_changed',
		'order.failed',
		'order.held',
		'order.released',
		'order.status_changed',
		'order.submitted',
		'order.tracking_added',
		'order.tracking_changed',
		'share_link.regenerated',
		'share_link.revoked',
		'store_access.off',
		'store_access.requested',
		'template.deleted',
		'template.live_changed',
		'template.published',
		'template.renamed',
	);

	public const PING_OPTION = Schema::WEBHOOK_PING_OPTION;

	public const EVENT_TYPES_OPTION = Schema::WEBHOOK_EVENT_TYPES_OPTION;

	private readonly Closure $clock;

	/**
	 * The health read in this request, by endpoint id.
	 *
	 * @var array<string, array{active: bool, failing: bool, failed_count: int, url: string}|null>
	 */
	private array $health = array();

	/**
	 * Why the health could not be read in this request, by endpoint id.
	 *
	 * @var array<string, ApiException>
	 */
	private array $health_failures = array();

	/**
	 * @param Connection      $connection The connection whose endpoint it is.
	 * @param Sdk             $api        The SDK, for its `/v1/webhooks` operations.
	 * @param LoggerInterface $logger     Logs why the endpoint could not be narrowed.
	 * @param Closure|null    $clock      `fn(): int` Unix time; `time()` when null.
	 */
	public function __construct(
		private readonly Connection $connection,
		private readonly Sdk $api,
		private readonly LoggerInterface $logger,
		?Closure $clock = null,
	) {
		$this->clock = $clock ?? static fn (): int => time();
	}

	public function register(): void {
		add_action( 'pixonaut_wc_connection_connected', array( $this, 'on_connected' ), 10, 0 );
		add_action( 'pixonaut_wc_connection_disconnected', array( $this, 'forget_ping' ), 10, 0 );
		add_action( Processor::MAINTENANCE_HOOK, array( $this, 'sync_event_types' ), 10, 0 );
	}

	/**
	 * The event types to subscribe the endpoint to.
	 *
	 * @return list<string>
	 */
	public static function event_types(): array {
		/**
		 * Filters the event types the store's webhook endpoint receives,
		 * set each time the store connects, and again by a daily job when
		 * the list changed since.
		 *
		 * A type Pixonaut does not send is refused (`400 invalid_event_type`)
		 * and the endpoint keeps receiving every type. An empty list receives
		 * every type too.
		 *
		 * @since 0.1.0
		 *
		 * @param list<string> $event_types Event types, e.g. `order.status_changed`.
		 *
		 * @example
		 * add_filter( 'pixonaut_wc_webhooks_event_types', function ( $event_types ) {
		 *     return array_values( array_diff( $event_types, array( 'template.published' ) ) );
		 * } );
		 */
		$filtered = (array) apply_filters( 'pixonaut_wc_webhooks_event_types', self::EVENT_TYPES );
		return array_values( array_filter( $filtered, 'is_string' ) );
	}

	/**
	 * Narrows the new connection's endpoint to the store's event types, then
	 * tests it.
	 */
	public function on_connected(): void {
		$connection = $this->connection->current();
		if ( null === $connection ) {
			return;
		}
		$this->subscribe( $connection );
		$this->ping( $connection );
	}

	/**
	 * Subscribes a connection made before the list last changed to the list
	 * as it is now. Pixonaut sends an endpoint only the types it subscribed to.
	 */
	public function sync_event_types(): void {
		$connection = $this->connection->current();
		if ( null === $connection || null !== $this->connection->paused() || get_option( self::EVENT_TYPES_OPTION ) === self::event_types() ) {
			return;
		}
		$this->subscribe( $connection );
	}

	/**
	 * @param StoredConnection $connection The connection whose endpoint to narrow.
	 */
	private function subscribe( StoredConnection $connection ): void {
		$event_types = self::event_types();
		try {
			$this->api->webhooks()->update( $connection->webhook_id, new WebhookEndpointsUpdateRequest( eventTypes: $event_types ) );
			update_option( self::EVENT_TYPES_OPTION, $event_types, false );
		} catch ( ApiException $e ) {
			$this->logger->warning(
				sprintf( 'The store\'s webhook endpoint still receives every event type: %s.', $e->getMessage() ),
				array(
					'operation'  => $e->operation,
					'status'     => $e->status,
					'error_code' => $e->errorCode,
				)
			);
		}
	}

	/**
	 * Has Pixonaut send the endpoint a test request, and keeps the outcome.
	 *
	 * @param StoredConnection $connection The connection whose endpoint to test.
	 *
	 * @return array{delivered: bool, error: string, at: int} `error` says why it was not delivered; empty when it was.
	 */
	public function ping( StoredConnection $connection ): array {
		try {
			$answer = WebhookPing::send( $this->api, $connection->webhook_id );
			$result = array(
				'delivered' => $answer->delivered,
				'error'     => $answer->error ?? '',
			);
		} catch ( ApiException $e ) {
			$result = array(
				'delivered' => false,
				/* translators: %s: why Pixonaut did not answer, e.g. rate_limited. */
				'error'     => sprintf( __( 'Pixonaut did not send the test request (%s).', 'pixonaut-for-woocommerce' ), $e->errorCode ),
			);
		}
		$result['at'] = ( $this->clock )();
		update_option( self::PING_OPTION, $result, false );
		return $result;
	}

	/**
	 * The last test request's outcome, or null when none was sent since the
	 * store connected.
	 *
	 * @return array{delivered: bool, error: string, at: int}|null
	 */
	public function last_ping(): ?array {
		$stored = get_option( self::PING_OPTION, null );
		if ( ! is_array( $stored ) || ! is_bool( $stored['delivered'] ?? null ) || ! is_int( $stored['at'] ?? null ) ) {
			return null;
		}
		return array(
			'delivered' => $stored['delivered'],
			'error'     => is_string( $stored['error'] ?? null ) ? $stored['error'] : '',
			'at'        => $stored['at'],
		);
	}

	public function forget_ping(): void {
		delete_option( self::PING_OPTION );
	}

	/**
	 * The endpoint's health as Pixonaut reports it.
	 *
	 * @param StoredConnection $connection The connection whose endpoint to read.
	 *
	 * @return array{active: bool, failing: bool, failed_count: int, url: string}|null Null when Pixonaut lists no such endpoint.
	 *
	 * @throws ApiException When Pixonaut refused or could not be reached.
	 */
	public function health( StoredConnection $connection ): ?array {
		// The settings tab shows it twice, on the connection card and in the developer tools.
		$id = $connection->webhook_id;
		if ( isset( $this->health_failures[ $id ] ) ) {
			throw $this->health_failures[ $id ];
		}
		if ( array_key_exists( $id, $this->health ) ) {
			return $this->health[ $id ];
		}
		try {
			$endpoint = null;
			foreach ( WebhookEndpoint::all( $this->api ) as $listed ) {
				if ( $listed->id === $id ) {
					$endpoint = $listed;
				}
			}
		} catch ( ApiException $e ) {
			// A second read would wait for the same timeout.
			$this->health_failures[ $id ] = $e;
			throw $e;
		}
		$this->health[ $id ] = null === $endpoint ? null : array(
			'active'       => $endpoint->active,
			'failing'      => $endpoint->failing,
			'failed_count' => (int) $endpoint->failed_count,
			'url'          => $endpoint->url,
		);
		return $this->health[ $id ];
	}
}
