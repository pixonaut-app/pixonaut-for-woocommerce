<?php
/**
 * Sends one HTTP request.
 *
 * @package Pixonaut\WooCommerce
 */

declare(strict_types=1);

namespace Pixonaut\WooCommerce\Api;

interface Transport {

	/**
	 * @param string                $method          HTTP method.
	 * @param string                $url             Absolute URL.
	 * @param array<string, string> $headers         Request headers.
	 * @param string|null           $body            Request body, or null for none.
	 * @param int                   $timeout_seconds Give up after this long.
	 *
	 * @throws TransportFailure When no response arrived.
	 */
	public function send( string $method, string $url, array $headers, ?string $body, int $timeout_seconds ): Response;

	/**
	 * Sends several requests at once and waits for every answer.
	 *
	 * @param list<array{method: string, url: string, headers: array<string, string>}> $requests        Requests without a body.
	 * @param int                                                                      $timeout_seconds Give up on each after this long.
	 *
	 * @return list<Response|TransportFailure> Each request's response, or why none arrived, in the order of `$requests`.
	 */
	public function send_all( array $requests, int $timeout_seconds ): array;
}
