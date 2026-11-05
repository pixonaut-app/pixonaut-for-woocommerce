<?php
/**
 * The store's event stream in Pixonaut.
 *
 * @package Pixonaut\WooCommerce
 */

declare(strict_types=1);

namespace Pixonaut\WooCommerce\Api;

final class Events {

	public function __construct( private readonly Client $client ) {
	}

	/**
	 * `GET /v1/events?after=&types=`: one page of the events recorded after
	 * an event, in the order they were recorded, each in the envelope a
	 * webhook delivery carries.
	 *
	 * @param string|null $after The id of the last event looked at; from the stream's start when null.
	 * @param string[]    $types The event types to ask for.
	 * @phpstan-param list<string> $types
	 *
	 * @return array{events: list<array<string, mixed>>, next_cursor: string|null} `next_cursor` is the `after` of the next page; null when nothing more is available yet.
	 *
	 * @throws ApiError When Pixonaut refused (`unknown_event` for an `after` it does not know) or could not be reached.
	 */
	public function page( ?string $after, array $types ): array {
		$query = array( 'types' => implode( ',', $types ) );
		if ( null !== $after ) {
			$query['after'] = $after;
		}
		$response = $this->client->call( Operations::LIST_EVENTS, array(), $query );
		$cursor   = $this->client->json( Operations::LIST_EVENTS, $response )['nextCursor'] ?? null;
		return array(
			'events'      => $this->client->items( Operations::LIST_EVENTS, $response, 'events' ),
			'next_cursor' => is_string( $cursor ) && '' !== $cursor ? $cursor : null,
		);
	}
}
