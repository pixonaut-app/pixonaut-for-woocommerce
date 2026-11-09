<?php
/**
 * A page of Pixonaut's event stream.
 *
 * @package Pixonaut\WooCommerce
 */

declare(strict_types=1);

namespace Pixonaut\WooCommerce\Api\Answers;

use Pixonaut\WooCommerce\Vendor\Pixonaut\Sdk\Exception\ApiException;
use Pixonaut\WooCommerce\Vendor\Pixonaut\Sdk\Exception\UnexpectedResponse;
use Pixonaut\WooCommerce\Vendor\Pixonaut\Sdk\Pixonaut as Sdk;

/**
 * The fields of `GET /v1/events` the store reads. The events stay as
 * Pixonaut sent them: the store keeps each envelope whole, and reads it
 * later as the SDK's webhook event.
 */
final class EventsPage {

	/**
	 * @param list<array<string, mixed>> $events      `events`, in the order they were recorded.
	 * @param string|null                $next_cursor `nextCursor`, the `after` of the next page; null when nothing more is available yet.
	 */
	public function __construct(
		public readonly array $events,
		public readonly ?string $next_cursor,
	) {
	}

	/**
	 * `GET /v1/events`.
	 *
	 * @param Sdk         $pixonaut The SDK.
	 * @param string|null $after    The id of the last event looked at; null for the stream's start.
	 * @param string      $types    Comma-separated event types.
	 *
	 * @throws ApiException When Pixonaut refused (`unknown_event`, …), could not be reached, or answered without a field the store reads.
	 */
	public static function get( Sdk $pixonaut, ?string $after, string $types ): self {
		$query = array(
			'after' => $after,
			'types' => $types,
		);
		return Read::answer( $pixonaut->call( 'events.list', query: $query ), self::from_answer( ... ) );
	}

	/**
	 * @param array<string, mixed> $data The answer.
	 * @param string               $path Where it is in the answer.
	 *
	 * @throws UnexpectedResponse When a field is missing or of another type.
	 */
	public static function from_answer( array $data, string $path = '$' ): self {
		return new self(
			events: Read::objects( $data, 'events', $path, static fn ( array $event ): array => $event ),
			next_cursor: Read::nullable_string( $data, 'nextCursor', $path ),
		);
	}
}
