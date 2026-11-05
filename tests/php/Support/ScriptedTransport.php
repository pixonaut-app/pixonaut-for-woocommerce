<?php
/**
 * A transport that answers from a script, for unit tests.
 *
 * @package Pixonaut\WooCommerce
 */

declare(strict_types=1);

namespace Pixonaut\WooCommerce\Tests\Support;

use Closure;
use Pixonaut\WooCommerce\Api\Response;
use Pixonaut\WooCommerce\Api\Transport;
use Pixonaut\WooCommerce\Api\TransportFailure;
use PHPUnit\Framework\AssertionFailedError;

/**
 * Every request is checked against the spec and `consumed.json`, and so is
 * every scripted response unless it is queued with `off_spec()` (a non-JSON
 * 500 from a proxy, an undeclared status).
 */
final class ScriptedTransport implements Transport {

	/**
	 * @var list<array{response: Response|TransportFailure, checked: bool}>
	 */
	private array $script = array();

	/**
	 * Requests in the order they were sent.
	 *
	 * @var list<array{method: string, url: string, headers: array<string, string>, body: string|null, timeout: int}>
	 */
	public array $sent = array();

	/**
	 * @param int                   $status  Status.
	 * @param array<string, mixed>  $body    JSON body.
	 * @param array<string, string> $headers Headers.
	 */
	public function json( int $status, array $body, array $headers = array() ): self {
		return $this->respond( new Response( $status, $headers + array( 'content-type' => 'application/json' ), (string) json_encode( $body ) ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode -- no WordPress in the unit suite.
	}

	/**
	 * @param Response $response Response the next request gets.
	 */
	public function respond( Response $response ): self {
		$this->script[] = array(
			'response' => $response,
			'checked'  => true,
		);
		return $this;
	}

	/**
	 * Queues a response the spec does not declare.
	 *
	 * @param Response $response Response the next request gets.
	 */
	public function off_spec( Response $response ): self {
		$this->script[] = array(
			'response' => $response,
			'checked'  => false,
		);
		return $this;
	}

	/**
	 * Queues a request that gets no response.
	 *
	 * @param string $message Why, as the HTTP layer would say.
	 */
	public function fail( string $message ): self {
		$this->script[] = array(
			'response' => new TransportFailure( $message ),
			'checked'  => false,
		);
		return $this;
	}

	public function send( string $method, string $url, array $headers, ?string $body, int $timeout_seconds ): Response {
		$this->sent[] = array(
			'method'  => $method,
			'url'     => $url,
			'headers' => $headers,
			'body'    => $body,
			'timeout' => $timeout_seconds,
		);
		$target       = self::target( $url );
		OpenApiAssert::request( $method, $target, $headers, $body );

		$next = array_shift( $this->script );
		if ( null === $next ) {
			throw new AssertionFailedError( sprintf( 'Unscripted request: %s %s', $method, $url ) );
		}
		if ( $next['response'] instanceof TransportFailure ) {
			throw $next['response'];
		}
		if ( $next['checked'] ) {
			OpenApiAssert::response( $method, $target, $next['response']->status, $next['response']->body, $next['response']->header( 'Content-Type' ) );
		}
		return $next['response'];
	}

	/**
	 * Every batch `send_all()` was given, the URLs of each.
	 *
	 * @var list<list<string>>
	 */
	public array $batches = array();

	/**
	 * Answers each request as `send()` does, in order.
	 *
	 * @param list<array{method: string, url: string, headers: array<string, string>}> $requests        Requests.
	 * @param int                                                                      $timeout_seconds Timeout.
	 *
	 * @return list<Response|TransportFailure>
	 */
	public function send_all( array $requests, int $timeout_seconds ): array {
		$this->batches[] = array_column( $requests, 'url' );
		$answers         = array();
		foreach ( $requests as $request ) {
			try {
				$answers[] = $this->send( $request['method'], $request['url'], $request['headers'], null, $timeout_seconds );
			} catch ( TransportFailure $failure ) {
				$answers[] = $failure;
			}
		}
		return $answers;
	}

	public function remaining(): int {
		return count( $this->script );
	}

	/**
	 * Path and query of a URL.
	 *
	 * @param string $url Absolute URL.
	 */
	public static function target( string $url ): string {
		$parts = parse_url( $url ); // phpcs:ignore WordPress.WP.AlternativeFunctions.parse_url_parse_url -- no WordPress in the unit suite.
		return ( $parts['path'] ?? '/' ) . ( isset( $parts['query'] ) ? '?' . $parts['query'] : '' );
	}
}
