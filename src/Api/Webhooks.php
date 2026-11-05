<?php
/**
 * The store's webhook endpoint in Pixonaut.
 *
 * @package Pixonaut\WooCommerce
 */

declare(strict_types=1);

namespace Pixonaut\WooCommerce\Api;

/**
 * The endpoint Pixonaut registered at the store when it connected. The
 * integration key reaches that one endpoint alone.
 */
final class Webhooks {

	public function __construct( private readonly Client $client ) {
	}

	/**
	 * `GET /v1/webhooks`: the store's endpoint, with its health.
	 *
	 * @param string $id The endpoint's id.
	 *
	 * @return array<string, mixed>|null `id`, `url`, `eventTypes`, `active`, `failing`, `failedCount`; null when Pixonaut lists no endpoint with that id.
	 *
	 * @throws ApiError When Pixonaut refused or could not be reached.
	 */
	public function find( string $id ): ?array {
		$rows = $this->client->rows( Operations::LIST_WEBHOOKS, $this->client->call( Operations::LIST_WEBHOOKS ) );
		foreach ( $rows as $row ) {
			if ( ( $row['id'] ?? null ) === $id ) {
				return $row;
			}
		}
		return null;
	}

	/**
	 * `PATCH /v1/webhooks/{id}`: chooses the event types the endpoint receives.
	 *
	 * @param string   $id          The endpoint's id.
	 * @param string[] $event_types Event types; an empty list receives every type.
	 * @phpstan-param list<string> $event_types
	 *
	 * @throws ApiError When Pixonaut refused (`400 invalid_event_type` for a type it does not send) or could not be reached.
	 */
	public function subscribe( string $id, array $event_types ): void {
		$this->client->call( Operations::UPDATE_WEBHOOK, array( 'id' => $id ), array(), array( 'eventTypes' => $event_types ) );
	}

	/**
	 * `POST /v1/webhooks/{id}/ping`: has Pixonaut send the endpoint a signed
	 * `webhook.ping`, and says whether the store acknowledged it.
	 *
	 * @param string $id The endpoint's id.
	 *
	 * @return array{delivered: bool, error?: string} `error` says why it was not delivered.
	 *
	 * @throws ApiError When Pixonaut refused or could not be reached.
	 */
	public function ping( string $id ): array {
		$answer = $this->client->json( Operations::PING_WEBHOOK, $this->client->call( Operations::PING_WEBHOOK, array( 'id' => $id ) ) );
		if ( true === ( $answer['delivered'] ?? null ) ) {
			return array( 'delivered' => true );
		}
		return array(
			'delivered' => false,
			'error'     => is_string( $answer['error'] ?? null ) ? $answer['error'] : '',
		);
	}

	/**
	 * `POST /v1/webhooks/{id}/deliveries/redeliver-failed`: queues every
	 * delivery to the endpoint that failed for good to be sent again.
	 *
	 * @param string $id The endpoint's id.
	 *
	 * @return int How many deliveries were queued again.
	 *
	 * @throws ApiError When Pixonaut refused (`endpoint_inactive`, `endpoint_unreachable`, …) or could not be reached.
	 */
	public function redeliver_failed( string $id ): int {
		$response = $this->client->call( Operations::REDELIVER_FAILED_WEBHOOKS, array( 'id' => $id ) );
		$requeued = $this->client->json( Operations::REDELIVER_FAILED_WEBHOOKS, $response )['requeued'] ?? null;
		return is_int( $requeued ) || is_float( $requeued ) ? (int) $requeued : 0;
	}
}
