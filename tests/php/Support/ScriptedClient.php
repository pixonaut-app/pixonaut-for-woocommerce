<?php
/**
 * A PSR-18 client that answers from a script, for unit tests.
 *
 * @package Pixonaut\WooCommerce
 */

declare(strict_types=1);

namespace Pixonaut\WooCommerce\Tests\Support;

use Closure;
use Pixonaut\WooCommerce\Api\BatchClient;
use Pixonaut\WooCommerce\Api\HookListener;
use Pixonaut\WooCommerce\Api\NetworkFailure;
use Pixonaut\WooCommerce\Api\Pixonaut;
use Pixonaut\WooCommerce\Api\Deprecations;
use Pixonaut\WooCommerce\Vendor\Nyholm\Psr7\Response;
use Pixonaut\WooCommerce\Vendor\Pixonaut\Sdk\Credentials\CredentialProvider;
use Pixonaut\WooCommerce\Vendor\Pixonaut\Sdk\Credentials\StaticCredentials;
use Pixonaut\WooCommerce\Vendor\Pixonaut\Sdk\Http\Listener;
use Pixonaut\WooCommerce\Vendor\Pixonaut\Sdk\Pixonaut as Sdk;
use Pixonaut\WooCommerce\Vendor\Psr\Http\Client\ClientExceptionInterface;
use Pixonaut\WooCommerce\Vendor\Psr\Http\Message\RequestInterface;
use Pixonaut\WooCommerce\Vendor\Psr\Http\Message\ResponseInterface;
use Pixonaut\WooCommerce\Vendor\Psr\Log\LoggerInterface;
use PHPUnit\Framework\AssertionFailedError;

/**
 * Every request is checked against the spec and `consumed.json`, and so is
 * every scripted response unless it is queued with `off_spec()` (a non-JSON
 * 500 from a proxy, an undeclared status).
 */
final class ScriptedClient implements BatchClient {

	public const BASE_URL = 'http://pixonaut.test';

	public const ORIGIN = 'http://shop.test';

	public const INTEGRATION_KEY = 'ik_test_0000000000000000';

	public const PUBLISHABLE_KEY = 'pk_test_0000000000000000';

	/**
	 * @var list<array{response: ResponseInterface|null, failure: string|null, checked: bool}>
	 */
	private array $script = array();

	/**
	 * Requests in the order they were sent.
	 *
	 * @var list<array{method: string, url: string, headers: array<string, string>, body: string|null, timeout: int}>
	 */
	public array $sent = array();

	/**
	 * Every batch `send_all()` was given, the URLs of each.
	 *
	 * @var list<list<string>>
	 */
	public array $batches = array();

	/**
	 * The timeout of the SDK client sending now.
	 *
	 * @var int
	 */
	private int $timeout = 0;

	/**
	 * The store's keys, as a connected store holds them.
	 */
	public static function credentials(): StaticCredentials {
		return new StaticCredentials( integrationKey: self::INTEGRATION_KEY, publishableKey: self::PUBLISHABLE_KEY );
	}

	/**
	 * The plugin's SDK factory over this client.
	 *
	 * @param CredentialProvider|null $credentials The store's keys; `credentials()` when null.
	 * @param LoggerInterface|null    $logger      Where the SDK logs; a `RecordingLogger` when null.
	 * @param Listener|null           $listener    The hooks; the plugin's when null.
	 * @param Closure|null            $origin      `fn(): ?string`, the origin a publishable key is sent from; `ORIGIN` when null.
	 */
	public function pixonaut( ?CredentialProvider $credentials = null, ?LoggerInterface $logger = null, ?Listener $listener = null, ?Closure $origin = null ): Pixonaut {
		return new Pixonaut(
			$credentials ?? self::credentials(),
			$logger ?? new RecordingLogger(),
			$listener ?? new HookListener( new Deprecations(), static fn (): int => 0 ),
			function ( int $timeout_seconds ): BatchClient {
				$this->timeout = $timeout_seconds;
				return $this;
			},
			self::BASE_URL,
			static function (): void {},
			$origin ?? static fn (): string => self::ORIGIN
		);
	}

	/**
	 * The SDK over this client, with the usual timeout.
	 *
	 * @param CredentialProvider|null $credentials The store's keys; `credentials()` when null.
	 * @param LoggerInterface|null    $logger      Where the SDK logs; a `RecordingLogger` when null.
	 */
	public function sdk( ?CredentialProvider $credentials = null, ?LoggerInterface $logger = null ): Sdk {
		return $this->pixonaut( $credentials, $logger )->sdk();
	}

	/**
	 * @param int                   $status  Status.
	 * @param array<mixed>          $body    JSON body.
	 * @param array<string, string> $headers Headers.
	 */
	public function json( int $status, array $body, array $headers = array() ): self {
		return $this->respond( new Response( $status, $headers + array( 'content-type' => 'application/json' ), (string) json_encode( $body ) ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode -- no WordPress in the unit suite.
	}

	/**
	 * @param ResponseInterface $response Response the next request gets.
	 */
	public function respond( ResponseInterface $response ): self {
		$this->script[] = array(
			'response' => $response,
			'failure'  => null,
			'checked'  => true,
		);
		return $this;
	}

	/**
	 * Queues a response the spec does not declare.
	 *
	 * @param ResponseInterface $response Response the next request gets.
	 */
	public function off_spec( ResponseInterface $response ): self {
		$this->script[] = array(
			'response' => $response,
			'failure'  => null,
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
			'response' => null,
			'failure'  => $message,
			'checked'  => false,
		);
		return $this;
	}

	/**
	 * @param RequestInterface $request The request.
	 *
	 * @throws AssertionFailedError When the request breaks the contract or is not scripted.
	 * @throws NetworkFailure When the script says it gets no response.
	 */
	public function sendRequest( RequestInterface $request ): ResponseInterface {
		$headers = array();
		foreach ( array_keys( $request->getHeaders() ) as $name ) {
			$headers[ (string) $name ] = $request->getHeaderLine( (string) $name );
		}
		$body         = (string) $request->getBody();
		$body         = '' === $body ? null : $body;
		$url          = (string) $request->getUri();
		$this->sent[] = array(
			'method'  => $request->getMethod(),
			'url'     => $url,
			'headers' => $headers,
			'body'    => $body,
			'timeout' => $this->timeout,
		);
		$target       = self::target( $url );
		OpenApiAssert::request( $request->getMethod(), $target, $headers, $body );

		$next = array_shift( $this->script );
		if ( null === $next ) {
			throw new AssertionFailedError( sprintf( 'Unscripted request: %s %s', $request->getMethod(), $url ) );
		}
		if ( null === $next['response'] ) {
			throw new NetworkFailure( $request, (string) $next['failure'] );
		}
		$response = $next['response'];
		if ( $next['checked'] ) {
			$type = $response->getHeaderLine( 'Content-Type' );
			OpenApiAssert::response( $request->getMethod(), $target, $response->getStatusCode(), (string) $response->getBody(), '' === $type ? null : $type );
		}
		$response->getBody()->rewind();
		return $response;
	}

	/**
	 * Answers each request as `sendRequest()` does, in order.
	 *
	 * @param RequestInterface[] $requests Requests.
	 * @phpstan-param list<RequestInterface> $requests
	 *
	 * @return list<ResponseInterface|ClientExceptionInterface>
	 */
	public function send_all( array $requests ): array {
		$this->batches[] = array_map( static fn ( RequestInterface $request ): string => (string) $request->getUri(), $requests );
		$answers         = array();
		foreach ( $requests as $request ) {
			try {
				$answers[] = $this->sendRequest( $request );
			} catch ( NetworkFailure $failure ) {
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
