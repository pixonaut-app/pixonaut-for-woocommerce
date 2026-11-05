<?php
/**
 * Answers WordPress HTTP requests to a fake Pixonaut from a script.
 *
 * @package Pixonaut\WooCommerce
 */

declare(strict_types=1);

namespace Pixonaut\WooCommerce\Tests\Support;

use PHPUnit\Framework\AssertionFailedError;

/**
 * Hooks `pre_http_request` for URLs under one base, so the real transport
 * runs end to end without a server. Every request and every scripted
 * response is checked against the spec and `consumed.json`.
 */
final class HttpRecorder {

	/**
	 * @var list<array{status: int, headers: array<string, string>, body: string, checked?: bool, failure?: string}>
	 */
	private array $script = array();

	/**
	 * Requests in the order they were made.
	 *
	 * @var list<array{method: string, url: string, headers: array<string, string>, body: string|null, httpversion: string|null, timeout: float|null}>
	 */
	public array $requests = array();

	/**
	 * @param string $base_url Requests under this URL are answered; others pass.
	 */
	public function __construct( private readonly string $base_url ) {
	}

	public function start(): self {
		add_filter( 'pre_http_request', array( $this, 'intercept' ), 10, 3 );
		return $this;
	}

	public function stop(): void {
		remove_filter( 'pre_http_request', array( $this, 'intercept' ), 10 );
	}

	/**
	 * @param int                   $status  Status.
	 * @param array<mixed>          $body    JSON body: an object, or a list for an answer that is one.
	 * @param array<string, string> $headers Headers.
	 */
	public function json( int $status, array $body, array $headers = array() ): self {
		$this->script[] = array(
			'status'  => $status,
			'headers' => array_change_key_case( $headers, CASE_LOWER ) + array( 'content-type' => 'application/json' ),
			'body'    => (string) wp_json_encode( $body ),
		);
		return $this;
	}

	/**
	 * Queues a JSON answer the spec does not declare, such as a `200`
	 * missing a required field.
	 *
	 * @param int                  $status Status.
	 * @param array<string, mixed> $body   JSON body.
	 */
	public function off_spec( int $status, array $body ): self {
		$this->json( $status, $body );
		$this->script[ count( $this->script ) - 1 ]['checked'] = false;
		return $this;
	}

	/**
	 * @param int    $status       Status.
	 * @param string $body         Body.
	 * @param string $content_type Content type.
	 */
	public function raw( int $status, string $body, string $content_type ): self {
		$this->script[] = array(
			'status'  => $status,
			'headers' => array( 'content-type' => $content_type ),
			'body'    => $body,
		);
		return $this;
	}

	/**
	 * Queues a request that gets no answer, as when the connection drops
	 * after Pixonaut received it.
	 *
	 * @param string $message The transport's error.
	 */
	public function fail( string $message = 'cURL error 28: Operation timed out' ): self {
		$this->script[] = array(
			'status'  => 0,
			'headers' => array(),
			'body'    => '',
			'failure' => $message,
		);
		return $this;
	}

	/**
	 * @param false|array<string, mixed>|\WP_Error $preempt Earlier filter's answer.
	 * @param array<string, mixed>                 $args    Request arguments.
	 * @param string                               $url     Request URL.
	 *
	 * @return false|array<string, mixed>|\WP_Error
	 *
	 * @throws AssertionFailedError When the exchange breaks the contract or is not scripted.
	 */
	public function intercept( $preempt, array $args, string $url ) {
		if ( false !== $preempt || ! str_starts_with( $url, $this->base_url ) ) {
			return $preempt;
		}

		$method  = strtoupper( (string) ( $args['method'] ?? 'GET' ) );
		$headers = is_array( $args['headers'] ?? null ) ? $args['headers'] : array();
		$body    = isset( $args['body'] ) && is_string( $args['body'] ) ? $args['body'] : null;

		$this->requests[] = array(
			'method'      => $method,
			'url'         => $url,
			'headers'     => $headers,
			'body'        => $body,
			'httpversion' => isset( $args['httpversion'] ) ? (string) $args['httpversion'] : null,
			'timeout'     => is_numeric( $args['timeout'] ?? null ) ? (float) $args['timeout'] : null,
		);
		$target           = ScriptedTransport::target( $url );
		OpenApiAssert::request( $method, $target, $headers, $body );

		$next = array_shift( $this->script );
		if ( null === $next ) {
			throw new AssertionFailedError( sprintf( 'Unscripted request: %s %s', $method, $url ) );
		}
		if ( isset( $next['failure'] ) ) {
			return new \WP_Error( 'http_request_failed', $next['failure'] );
		}
		if ( $next['checked'] ?? true ) {
			OpenApiAssert::response( $method, $target, $next['status'], $next['body'], $next['headers']['content-type'] ?? null );
		}

		return array(
			'headers'  => $next['headers'],
			'body'     => $next['body'],
			'response' => array(
				'code'    => $next['status'],
				'message' => get_status_header_desc( $next['status'] ),
			),
			'cookies'  => array(),
			'filename' => null,
		);
	}
}
