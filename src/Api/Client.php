<?php
/**
 * The plugin's client for the Pixonaut API.
 *
 * @package Pixonaut\WooCommerce
 */

declare(strict_types=1);

namespace Pixonaut\WooCommerce\Api;

use Closure;
use InvalidArgumentException;
use Pixonaut\WooCommerce\Connection\Connection;
use Pixonaut\WooCommerce\Plugin;
use UnexpectedValueException;

/**
 * The only code that talks to Pixonaut. Area classes (`Api\Connect`, …) call
 * `call()` with an operation id from `Operations`; callers that must send a
 * request again exactly as before keep its `PreparedRequest` and pass it to
 * `send()`.
 */
final class Client {

	public const TIMEOUT_SECONDS = 15;

	/**
	 * Requests `call_all()` has out at once.
	 */
	public const MAX_CONCURRENT = 4;

	/**
	 * Times a request answered `409 idempotency_key_in_use` is sent again
	 * before the error goes to the caller.
	 */
	public const MAX_IN_USE_RESENDS = 3;

	/**
	 * Wait when `idempotency_key_in_use` came without `Retry-After`.
	 */
	private const DEFAULT_IN_USE_WAIT_SECONDS = 1;

	/**
	 * Pixonaut asks for at most 2 seconds; a longer wait would hold the PHP
	 * request, so it is capped and the bounded resends do the rest.
	 */
	private const MAX_IN_USE_WAIT_SECONDS = 5;

	/**
	 * What Pixonaut accepts as an `Idempotency-Key`: 1 to 255 printable
	 * ASCII characters.
	 */
	private const IDEMPOTENCY_KEY_PATTERN = '/^[\x21-\x7e]{1,255}$/';

	private readonly Closure $sleep;

	private readonly Closure $clock;

	/**
	 * @param string             $base_url     API origin, e.g. `https://api.pixonaut.app`.
	 * @param Operations         $operations   The operations this client may call.
	 * @param CredentialProvider $credentials  Credentials, read at every send.
	 * @param Transport          $transport    Sends the HTTP request.
	 * @param Logger             $logger       Logs unknown errors and deprecations.
	 * @param Deprecations       $deprecations Records deprecations for the admin notice.
	 * @param Closure|null       $sleep        `fn( int $seconds ): void`; `sleep()` when null.
	 * @param Closure|null       $clock        `fn(): int` Unix time; `time()` when null.
	 */
	public function __construct(
		private readonly string $base_url,
		private readonly Operations $operations,
		private readonly CredentialProvider $credentials,
		private readonly Transport $transport,
		private readonly Logger $logger,
		private readonly Deprecations $deprecations,
		?Closure $sleep = null,
		?Closure $clock = null,
	) {
		$this->sleep = $sleep ?? static function ( int $seconds ): void {
			sleep( $seconds );
		};
		$this->clock = $clock ?? static fn (): int => time();
	}

	/**
	 * Prepares and sends a request.
	 *
	 * @param string                     $operation       Operation id from `Operations`.
	 * @param array<string, string>      $path            Values for the path template's `{name}`s.
	 * @param array<string, scalar|null> $query           Query parameters; null ones are left out.
	 * @param array<string, mixed>|null  $body            JSON body, or null for none.
	 * @param string|null                $idempotency_key Key for an idempotent operation; a UUID when null.
	 *
	 * @throws ApiError When Pixonaut refused the request or could not be reached.
	 */
	public function call( string $operation, array $path = array(), array $query = array(), ?array $body = null, ?string $idempotency_key = null ): Response {
		return $this->send( $this->prepare( $operation, $path, $query, $body, $idempotency_key ) );
	}

	/**
	 * Builds the request once: path, query, filtered and encoded body, key.
	 *
	 * @param string                     $operation       Operation id from `Operations`.
	 * @param array<string, string>      $path            Values for the path template's `{name}`s.
	 * @param array<string, scalar|null> $query           Query parameters; null ones are left out.
	 * @param array<string, mixed>|null  $body            JSON body, or null for none.
	 * @param string|null                $idempotency_key Key for an idempotent operation; a UUID when null.
	 *
	 * @throws InvalidArgumentException When the operation is unknown, a path value is missing, a key is given to an operation that takes none, or the operation does not take the credential.
	 */
	public function prepare( string $operation, array $path = array(), array $query = array(), ?array $body = null, ?string $idempotency_key = null ): PreparedRequest {
		$descriptor = $this->operations->get( $operation );

		$target = (string) preg_replace_callback(
			'/\{([^}]+)\}/',
			static function ( array $found ) use ( $path, $operation ): string {
				if ( ! isset( $path[ $found[1] ] ) || '' === $path[ $found[1] ] ) {
					throw new InvalidArgumentException( sprintf( '%s needs the path value "%s".', $operation, $found[1] ) );
				}
				return rawurlencode( $path[ $found[1] ] );
			},
			$descriptor->path
		);
		$query  = array_filter( $query, static fn ( $value ): bool => null !== $value );
		if ( array() !== $query ) {
			$target .= '?' . http_build_query( $query, '', '&', PHP_QUERY_RFC3986 );
		}

		$encoded = null;
		if ( null !== $body ) {
			/**
			 * Filters the body of a request to Pixonaut before it is encoded.
			 *
			 * Runs once per request, when it is prepared: a request sent
			 * again from its snapshot is not filtered again.
			 *
			 * @since 0.1.0
			 *
			 * @param array<string, mixed> $body      Request body.
			 * @param string               $operation Operation id, e.g. `POST /v1/designs`.
			 *
			 * @example
			 * add_filter( 'pixonaut_wc_api_request_body', function ( $body, $operation ) {
			 *     if ( 'POST /v1/designs' === $operation ) {
			 *         $body['externalRef'] = 'shop-a:' . $body['externalRef'];
			 *     }
			 *     return $body;
			 * }, 10, 2 );
			 */
			$body    = (array) apply_filters( 'pixonaut_wc_api_request_body', $body, $operation );
			$encoded = array() === $body ? '{}' : wp_json_encode( $body );
			if ( false === $encoded ) {
				throw new InvalidArgumentException( sprintf( '%s body is not encodable as JSON.', $operation ) );
			}
		}

		if ( $descriptor->idempotent && null === $idempotency_key ) {
			$idempotency_key = wp_generate_uuid4();
		}

		$request = new PreparedRequest( $operation, $target, $encoded, $idempotency_key );
		$this->assert_sendable( $descriptor, $request );
		return $request;
	}

	/**
	 * Sends a prepared request, again if Pixonaut is still answering the
	 * first one with the same key.
	 *
	 * @param PreparedRequest $request         From `prepare()`, or a snapshot of one.
	 * @param int             $timeout_seconds Give up on each send after this long.
	 *
	 * @throws ApiError When Pixonaut refused the request or could not be reached.
	 * @throws InvalidArgumentException When the request does not fit its operation; nothing is sent.
	 */
	public function send( PreparedRequest $request, int $timeout_seconds = self::TIMEOUT_SECONDS ): Response {
		$operation = $this->operations->get( $request->operation );
		$this->assert_sendable( $operation, $request );
		$headers = $this->headers( $operation, $request );

		for ( $resends = 0; ; ++$resends ) {
			$response = $this->send_once( $operation, $request, $headers, $timeout_seconds );
			if ( $response->is_success() ) {
				return $response;
			}

			$error = ApiError::from_response( $request->operation, $response, ( $this->clock )() );
			if ( ErrorCodes::IDEMPOTENCY_KEY_IN_USE === $error->error_code && null !== $request->idempotency_key && $resends < self::MAX_IN_USE_RESENDS ) {
				( $this->sleep )( min( $error->retry_after ?? self::DEFAULT_IN_USE_WAIT_SECONDS, self::MAX_IN_USE_WAIT_SECONDS ) );
				continue;
			}
			throw $this->raise( $error );
		}
	}

	/**
	 * Sends several reads at once, at most `MAX_CONCURRENT` at a time, each
	 * once: an operation that takes an `Idempotency-Key` goes through `call()`.
	 *
	 * @param list<array{0: string, 1: array<string, string>, 2: array<string, scalar|null>}> $calls Each call's operation id, path values and query parameters, as `call()` takes them.
	 *
	 * @return list<Response|ApiError> Each call's response, or why Pixonaut refused it or could not be reached, in the order of `$calls`.
	 *
	 * @throws InvalidArgumentException When a call does not fit its operation, or its operation takes an `Idempotency-Key`; nothing is sent.
	 */
	public function call_all( array $calls ): array {
		$prepared = array();
		foreach ( $calls as $call ) {
			$request = $this->prepare( $call[0], $call[1], $call[2] );
			if ( null !== $request->idempotency_key ) {
				throw new InvalidArgumentException( sprintf( '%s takes an Idempotency-Key; send it with call().', $request->operation ) );
			}
			$prepared[] = $request;
		}
		$results = array();
		foreach ( array_chunk( $prepared, self::MAX_CONCURRENT ) as $chunk ) {
			$sendable = array();
			$outgoing = array();
			foreach ( $chunk as $request ) {
				try {
					$operation  = $this->operations->get( $request->operation );
					$outgoing[] = array(
						'method'  => $operation->method,
						'url'     => rtrim( $this->base_url, '/' ) . $request->target,
						'headers' => $this->headers( $operation, $request ),
					);
					$sendable[] = $request;
					$this->before( $request );
				} catch ( ApiError $error ) {
					$results[ spl_object_id( $request ) ] = $error;
				}
			}
			$answers = array() === $outgoing ? array() : $this->transport->send_all( $outgoing, self::TIMEOUT_SECONDS );
			foreach ( $sendable as $index => $request ) {
				$results[ spl_object_id( $request ) ] = $this->answered( $request, $answers[ $index ] ?? new TransportFailure( 'No response.' ) );
			}
		}
		return array_map( static fn ( PreparedRequest $request ): Response|ApiError => $results[ spl_object_id( $request ) ], $prepared );
	}

	/**
	 * A response from `send_all()` as `call_all()` returns it, the request's
	 * after-request action fired.
	 *
	 * @param PreparedRequest           $request The request.
	 * @param Response|TransportFailure $answer  Its response, or why none arrived.
	 */
	private function answered( PreparedRequest $request, Response|TransportFailure $answer ): Response|ApiError {
		if ( $answer instanceof TransportFailure ) {
			$error = new ApiError( $request->operation, 0, ErrorCodes::TRANSPORT_FAILED, true, null, array( 'message' => $answer->getMessage() ) );
			$this->after( $request, null, $error );
			return $this->raise( $error );
		}
		$this->note_deprecation( $request, $answer );
		$this->after( $request, $answer, null );
		return $answer->is_success() ? $answer : $this->raise( ApiError::from_response( $request->operation, $answer, ( $this->clock )() ) );
	}

	/**
	 * A request built by hand or restored from a damaged snapshot must not
	 * reach Pixonaut: an idempotent write without its key would run twice.
	 *
	 * @param Operation       $operation Operation.
	 * @param PreparedRequest $request   Request.
	 *
	 * @throws InvalidArgumentException When the request does not fit its operation.
	 */
	private function assert_sendable( Operation $operation, PreparedRequest $request ): void {
		if ( ! $operation->matches( $request->target ) ) {
			throw new InvalidArgumentException( sprintf( '%s cannot be sent to %s.', $request->operation, $request->target ) );
		}
		if ( ! $operation->idempotent ) {
			if ( null !== $request->idempotency_key ) {
				throw new InvalidArgumentException( sprintf( '%s takes no Idempotency-Key.', $request->operation ) );
			}
			return;
		}
		if ( null === $request->idempotency_key || 1 !== preg_match( self::IDEMPOTENCY_KEY_PATTERN, $request->idempotency_key ) ) {
			throw new InvalidArgumentException( sprintf( '%s needs an Idempotency-Key of 1 to 255 printable ASCII characters.', $request->operation ) );
		}
	}

	/**
	 * @param Operation             $operation Operation.
	 * @param PreparedRequest       $request   Request.
	 * @param array<string, string> $headers   Headers, credential included.
	 * @param int                   $timeout   Give up after this many seconds.
	 *
	 * @throws ApiError When no response arrived.
	 */
	private function send_once( Operation $operation, PreparedRequest $request, array $headers, int $timeout = self::TIMEOUT_SECONDS ): Response {
		$this->before( $request );

		$response = null;
		$error    = null;
		try {
			$url      = rtrim( $this->base_url, '/' ) . $request->target;
			$response = $this->transport->send( $operation->method, $url, $headers, $request->body, $timeout );
			$this->note_deprecation( $request, $response );
		} catch ( TransportFailure $failure ) {
			$error = new ApiError( $request->operation, 0, ErrorCodes::TRANSPORT_FAILED, true, null, array( 'message' => $failure->getMessage() ) );
		}

		$this->after( $request, $response, $error );

		if ( null === $response ) {
			throw $this->raise( $error ?? new ApiError( $request->operation, 0, ErrorCodes::TRANSPORT_FAILED, true ) );
		}
		return $response;
	}

	/**
	 * @param PreparedRequest $request The request about to be sent.
	 */
	private function before( PreparedRequest $request ): void {
		/**
		 * Fires before every request to Pixonaut, including each resend.
		 *
		 * @since 0.1.0
		 *
		 * @param PreparedRequest $request The request; it holds no credential.
		 *
		 * @example
		 * add_action( 'pixonaut_wc_api_before_request', function ( $request ) {
		 *     error_log( 'Pixonaut call: ' . $request->operation );
		 * } );
		 */
		do_action( 'pixonaut_wc_api_before_request', $request );
	}

	/**
	 * @param PreparedRequest $request  The request.
	 * @param Response|null   $response The response, or null when none arrived.
	 * @param ApiError|null   $error    Why no response arrived, or null.
	 */
	private function after( PreparedRequest $request, ?Response $response, ?ApiError $error ): void {
		/**
		 * Fires after every request to Pixonaut, including each resend.
		 *
		 * @since 0.1.0
		 *
		 * @param PreparedRequest $request  The request; it holds no credential.
		 * @param Response|null   $response The response, or null when none arrived.
		 * @param ApiError|null   $error    Why no response arrived, or null.
		 *
		 * @example
		 * add_action( 'pixonaut_wc_api_after_request', function ( $request, $response ) {
		 *     if ( $response && $response->status >= 500 ) {
		 *         error_log( 'Pixonaut failed: ' . $request->operation );
		 *     }
		 * }, 10, 2 );
		 */
		do_action( 'pixonaut_wc_api_after_request', $request, $response, $error );
	}

	/**
	 * @param Operation       $operation Operation.
	 * @param PreparedRequest $request   Request.
	 *
	 * @return array<string, string>
	 *
	 * @throws ApiError When the operation needs a credential the store does not hold.
	 */
	private function headers( Operation $operation, PreparedRequest $request ): array {
		$headers = array(
			'Accept'     => $operation->accept,
			'User-Agent' => 'pixonaut-for-woocommerce/' . Plugin::VERSION,
		);
		if ( null !== $request->body ) {
			$headers['Content-Type'] = 'application/json';
		}
		if ( null !== $request->idempotency_key ) {
			$headers['Idempotency-Key'] = $request->idempotency_key;
		}
		if ( Credential::None !== $operation->credential ) {
			$secret = $this->credentials->secret( $operation->credential );
			if ( null === $secret || '' === $secret ) {
				throw new ApiError( $request->operation, 0, ErrorCodes::NOT_CONNECTED, false );
			}
			$headers['Authorization'] = 'Bearer ' . $secret;
		}
		// A publishable key is refused from any origin it is not bound to,
		// and it was bound to the site's.
		if ( Credential::PublishableKey === $operation->credential ) {
			$headers['Origin'] = Connection::origin( Connection::site_url() );
		}
		return $headers;
	}

	private function note_deprecation( PreparedRequest $request, Response $response ): void {
		$deprecation = $response->header( 'Deprecation' );
		if ( null === $deprecation ) {
			return;
		}
		$sunset = $response->header( 'Sunset' );
		if ( $this->deprecations->record( $request->operation, $deprecation, $sunset, ( $this->clock )() ) ) {
			$this->logger->warning(
				sprintf( 'Pixonaut deprecated something %s uses (Deprecation: %s, Sunset: %s). Update the plugin before the sunset.', $request->operation, $deprecation, $sunset ?? 'none' ),
				array(
					'operation'   => $request->operation,
					'deprecation' => $deprecation,
					'sunset'      => $sunset,
				)
			);
		}
	}

	/**
	 * @param ApiError $error The refusal or failure.
	 */
	private function raise( ApiError $error ): ApiError {
		$operations = $this->operations->all();
		$operation  = $operations[ $error->operation ] ?? null;
		if ( null !== $operation && $operation->sensitive ) {
			$error = $error->redacted();
		}

		$no_response = 0 === $error->status;
		if ( ! ErrorCodes::is_known( $error->error_code ) || ( ! $no_response && ! ErrorCodes::is_known_status( $error->status ) ) ) {
			$this->logger->error(
				$error->getMessage(),
				array(
					'operation' => $error->operation,
					'status'    => $error->status,
					'code'      => $error->error_code,
					'details'   => $error->details,
				)
			);
		}

		if ( ErrorCodes::UNAUTHORIZED === $error->error_code || 401 === $error->status ) {
			/**
			 * Fires when Pixonaut refused the store's credential: the
			 * connection was removed in Pixonaut, or the key revoked.
			 *
			 * @since 0.1.0
			 *
			 * @param string   $operation Operation id.
			 * @param ApiError $error     The refusal.
			 *
			 * @example
			 * add_action( 'pixonaut_wc_api_unauthorized', function ( $operation ) {
			 *     wp_mail( get_option( 'admin_email' ), 'Pixonaut disconnected', $operation );
			 * } );
			 */
			do_action( 'pixonaut_wc_api_unauthorized', $error->operation, $error );
		}

		return $error;
	}

	/**
	 * Decodes a successful response that must be a JSON object.
	 *
	 * @param string   $operation Operation id.
	 * @param Response $response  The response.
	 *
	 * @return array<string, mixed>
	 *
	 * @throws ApiError When it is not one.
	 */
	public function json( string $operation, Response $response ): array {
		try {
			return $response->json();
		} catch ( UnexpectedValueException $e ) {
			throw $this->raise( ApiError::unexpected( $operation, $response ) );
		}
	}

	/**
	 * Decodes a successful list answer: the objects under `$key`.
	 *
	 * @param string   $operation Operation id.
	 * @param Response $response  The response.
	 * @param string   $key       The list's field, e.g. `orders`.
	 *
	 * @return list<array<string, mixed>>
	 *
	 * @throws ApiError When the answer is not a JSON object, the field is not a list, or a row is not an object.
	 */
	public function items( string $operation, Response $response, string $key ): array {
		$page = $this->json( $operation, $response );
		return $this->objects( $operation, $response, $page[ $key ] ?? null );
	}

	/**
	 * Decodes a successful answer that is a JSON list of objects.
	 *
	 * @param string   $operation Operation id.
	 * @param Response $response  The response.
	 *
	 * @return list<array<string, mixed>>
	 *
	 * @throws ApiError When the answer is not a JSON list, or a row is not an object.
	 */
	public function rows( string $operation, Response $response ): array {
		return $this->objects( $operation, $response, json_decode( $response->body, true ) );
	}

	/**
	 * @param string   $operation Operation id.
	 * @param Response $response  The response, for the error.
	 * @param mixed    $rows      What should be a list of objects.
	 *
	 * @return list<array<string, mixed>>
	 *
	 * @throws ApiError When it is not.
	 */
	private function objects( string $operation, Response $response, mixed $rows ): array {
		if ( ! is_array( $rows ) || ! array_is_list( $rows ) ) {
			throw $this->raise( ApiError::unexpected( $operation, $response ) );
		}
		foreach ( $rows as $row ) {
			if ( ! is_array( $row ) || ( array() !== $row && array_is_list( $row ) ) ) {
				throw $this->raise( ApiError::unexpected( $operation, $response ) );
			}
		}
		/** @var list<array<string, mixed>> $rows */
		return $rows;
	}
}
