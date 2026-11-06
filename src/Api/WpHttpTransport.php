<?php
/**
 * The SDK's PSR-18 client, over the WordPress HTTP API.
 *
 * @package Pixonaut\WooCommerce
 */

declare(strict_types=1);

namespace Pixonaut\WooCommerce\Api;

use Pixonaut\WooCommerce\Vendor\Nyholm\Psr7\Response;
use Pixonaut\WooCommerce\Vendor\Psr\Http\Client\ClientExceptionInterface;
use Pixonaut\WooCommerce\Vendor\Psr\Http\Message\RequestInterface;
use Pixonaut\WooCommerce\Vendor\Psr\Http\Message\ResponseInterface;
use WP_Error;
use WP_Http;
use WP_HTTP_Proxy;
use WP_HTTP_Requests_Hooks;
use WP_HTTP_Requests_Response;
use WpOrg\Requests\Exception as RequestsException;
use WpOrg\Requests\Proxy\Http as HttpProxy;
use WpOrg\Requests\Requests;
use WpOrg\Requests\Response as RequestsResponse;

/**
 * The plugin's only caller of `wp_remote_*` for Pixonaut; a unit test keeps
 * it that way. Each instance gives up on a request after the timeout it was
 * built with.
 */
final class WpHttpTransport implements BatchClient {

	public const TIMEOUT_SECONDS = 15;

	/**
	 * WordPress's certificate bundle, under `ABSPATH`, which `WP_Http` verifies hosts against.
	 */
	private const CA_BUNDLE = 'wp-includes/certificates/ca-bundle.crt';

	/**
	 * The protocols `WP_Http::request()` lets a URL have.
	 */
	private const PROTOCOLS = array( 'http', 'https', 'ssl' );

	/**
	 * @param int $timeout_seconds Give up on each request after this long.
	 */
	public function __construct( private readonly int $timeout_seconds = self::TIMEOUT_SECONDS ) {
	}

	/**
	 * @param RequestInterface $request The request.
	 *
	 * @throws NetworkFailure When no response arrived.
	 */
	public function sendRequest( RequestInterface $request ): ResponseInterface {
		$args = array(
			'method'      => $request->getMethod(),
			'headers'     => self::headers( $request ),
			'timeout'     => $this->timeout_seconds,
			// Pixonaut never redirects an API call; following one would resend the credential elsewhere.
			'redirection' => 0,
		);
		$body = (string) $request->getBody();
		if ( '' !== $body ) {
			$args['body'] = $body;
		}

		$response = $this->result( $request, wp_remote_request( (string) $request->getUri(), $args ) );
		if ( $response instanceof NetworkFailure ) {
			throw $response;
		}
		return $response;
	}

	// phpcs:disable WooCommerce.Commenting.CommentHooks, WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- WordPress's HTTP API hooks, fired as WP_Http::request() fires them.

	/**
	 * The WordPress HTTP API sends one request at a time, so the requests go
	 * out together through the Requests library it is built on. Each passes
	 * WordPress's filters as `wp_remote_request()` would: `http_request_args`,
	 * `pre_http_request` (which may answer it), `http_request_reject_unsafe_urls`,
	 * the external-request block, the proxy, `https_ssl_verify`, `http_api_curl`,
	 * the `requests-*` actions, `http_api_debug` and `http_response`.
	 *
	 * @param RequestInterface[] $requests Requests without a body.
	 * @phpstan-param list<RequestInterface> $requests
	 *
	 * @return list<ResponseInterface|ClientExceptionInterface>
	 */
	public function send_all( array $requests ): array {
		$results = array();
		$batch   = array();
		$parsed  = array();
		foreach ( $requests as $index => $request ) {
			$url  = (string) $request->getUri();
			$args = $this->args( $request->getMethod(), $url, self::headers( $request ) );
			$pre  = apply_filters( 'pre_http_request', false, $args, $url );
			if ( false !== $pre ) {
				$results[ $index ] = $this->result( $request, $pre );
				continue;
			}
			$safe = $this->safe_url( $url, $args );
			if ( null === $safe ) {
				$results[ $index ] = new NetworkFailure( $request, sprintf( 'Requests to %s are not a valid URL.', $url ) );
				continue;
			}
			if ( ( new WP_Http() )->block_request( $safe ) ) {
				$results[ $index ] = new NetworkFailure( $request, sprintf( 'Requests to %s are blocked.', $safe ) );
				continue;
			}
			$url              = $safe;
			$parsed[ $index ] = $args;
			$batch[ $index ]  = array(
				'url'     => $url,
				'headers' => $args['headers'],
				'data'    => null,
				'type'    => $args['method'],
				'options' => $this->options( $url, $args ),
			);
		}
		if ( array() !== $batch ) {
			mbstring_binary_safe_encoding();
			try {
				$answers = Requests::request_multiple( $batch );
			} finally {
				reset_mbstring_encoding();
			}
			foreach ( $answers as $index => $answer ) {
				$url = $batch[ $index ]['url'];
				if ( $answer instanceof RequestsResponse ) {
					$wrapped  = new WP_HTTP_Requests_Response( $answer );
					$response = $wrapped->to_array() + array( 'http_response' => $wrapped );
				} else {
					$response = new WP_Error( 'http_request_failed', $answer instanceof RequestsException ? $answer->getMessage() : 'No response.' );
				}
				do_action( 'http_api_debug', $response, 'response', Requests::class, $parsed[ $index ], $url );
				if ( ! is_wp_error( $response ) ) {
					$response = apply_filters( 'http_response', $response, $parsed[ $index ], $url );
				}
				$results[ $index ] = $this->result( $requests[ $index ], $response );
			}
		}
		ksort( $results );
		return array_values( $results );
	}

	/**
	 * The arguments WordPress fills in for a GET from `send_all()`, filtered as
	 * `WP_Http::request()` filters them.
	 *
	 * @param string                $method  HTTP method.
	 * @param string                $url     Absolute URL.
	 * @param array<string, string> $headers Request headers.
	 *
	 * @return array<string, mixed>
	 */
	private function args( string $method, string $url, array $headers ): array {
		$user_agent             = apply_filters( 'http_headers_useragent', 'WordPress/' . get_bloginfo( 'version' ) . '; ' . get_bloginfo( 'url' ), $url );
		$args                   = (array) apply_filters(
			'http_request_args',
			array(
				'method'              => $method,
				'timeout'             => $this->timeout_seconds,
				'redirection'         => 0,
				'httpversion'         => '1.0',
				'user-agent'          => $user_agent,
				'reject_unsafe_urls'  => apply_filters( 'http_request_reject_unsafe_urls', false, $url ),
				'blocking'            => true,
				'headers'             => $headers,
				'cookies'             => array(),
				'body'                => null,
				'compress'            => false,
				'decompress'          => true,
				'sslverify'           => true,
				'sslcertificates'     => ABSPATH . self::CA_BUNDLE,
				'stream'              => false,
				'filename'            => null,
				'limit_response_size' => null,
			),
			$url
		);
		$args['_redirection'] ??= $args['redirection'];
		return $args;
	}

	/**
	 * The URL as `WP_Http::request()` lets it through: checked by
	 * `wp_http_validate_url()` when the arguments reject unsafe URLs, and
	 * held to the HTTP protocols.
	 *
	 * @param string               $url  Absolute URL.
	 * @param array<string, mixed> $args From `args()`.
	 *
	 * @return string|null Null when it may not be requested.
	 */
	private function safe_url( string $url, array $args ): ?string {
		if ( ! empty( $args['reject_unsafe_urls'] ) ) {
			$url = wp_http_validate_url( $url );
		}
		if ( false === $url || '' === $url ) {
			return null;
		}
		$url = wp_kses_bad_protocol( $url, self::PROTOCOLS );
		return '' === $url || empty( wp_parse_url( $url, PHP_URL_SCHEME ) ) ? null : $url;
	}

	/**
	 * The Requests library's options for the arguments, as `WP_Http::request()` sets them.
	 *
	 * @param string               $url  Absolute URL.
	 * @param array<string, mixed> $args From `args()`.
	 *
	 * @return array<string, mixed>
	 */
	private function options( string $url, array $args ): array {
		$options = array(
			'timeout'   => $args['timeout'],
			'useragent' => $args['user-agent'],
		);
		if ( empty( $args['redirection'] ) ) {
			$options['follow_redirects'] = false;
		} else {
			$options['redirects'] = $args['redirection'];
		}
		if ( isset( $args['limit_response_size'] ) ) {
			$options['max_bytes'] = $args['limit_response_size'];
		}
		if ( $args['sslverify'] ) {
			$options['verify'] = $args['sslcertificates'];
		} else {
			$options['verify']     = false;
			$options['verifyname'] = false;
		}
		$options['verify'] = apply_filters( 'https_ssl_verify', $options['verify'], $url );
		$options['hooks']  = $this->hooks( $url, $args, $options );
		$proxy             = new WP_HTTP_Proxy();
		if ( $proxy->is_enabled() && $proxy->send_through_proxy( $url ) ) {
			$options['proxy'] = new HttpProxy( $proxy->host() . ':' . $proxy->port() );
			if ( $proxy->use_authentication() ) {
				$options['proxy']->use_authentication = true;
				$options['proxy']->user               = $proxy->username();
				$options['proxy']->pass               = $proxy->password();
			}
		}
		return $options;
	}

	/**
	 * WordPress's hooks for the request, as `WP_Http::request()` passes them.
	 *
	 * Requests' cURL transport neither fires `curl.before_send` nor applies
	 * `verify` and `verifyname` to a request sent with others; only
	 * `request()` does, while `request_multiple()` fires
	 * `curl.before_multi_add` with each handle. So the handle is set up there
	 * as `request()` sets it, and `curl.before_send` fired on it: the proxy
	 * Requests registers and `http_api_curl` apply. The socket transport sends
	 * each request through its own `request()`, which does all of it already.
	 *
	 * @param string               $url     Absolute URL.
	 * @param array<string, mixed> $args    From `args()`.
	 * @param array<string, mixed> $options The Requests library's options so far.
	 */
	private function hooks( string $url, array $args, array $options ): WP_HTTP_Requests_Hooks {
		$hooks      = new WP_HTTP_Requests_Hooks( $url, $args );
		$verify     = $options['verify'];
		$verifyname = $options['verifyname'] ?? true;
		$hooks->register(
			'curl.before_multi_add',
			static function ( &$handle ) use ( $hooks, $verify, $verifyname ): void {
				// phpcs:disable WordPress.WP.AlternativeFunctions.curl_curl_setopt -- the handle Requests sends, set up as its own request() does.
				if ( false === $verify ) {
					curl_setopt( $handle, CURLOPT_SSL_VERIFYHOST, 0 );
					curl_setopt( $handle, CURLOPT_SSL_VERIFYPEER, false );
				} elseif ( is_string( $verify ) && '' !== $verify ) {
					curl_setopt( $handle, CURLOPT_CAINFO, $verify );
				}
				if ( false === $verifyname ) {
					curl_setopt( $handle, CURLOPT_SSL_VERIFYHOST, 0 );
				}
				// phpcs:enable WordPress.WP.AlternativeFunctions.curl_curl_setopt
				$hooks->dispatch( 'curl.before_send', array( &$handle ) );
			}
		);
		return $hooks;
	}

	// phpcs:enable

	/**
	 * A request's headers as the WordPress HTTP API takes them.
	 *
	 * @param RequestInterface $request The request.
	 *
	 * @return array<string, string>
	 */
	private static function headers( RequestInterface $request ): array {
		$headers = array();
		foreach ( array_keys( $request->getHeaders() ) as $name ) {
			$headers[ (string) $name ] = $request->getHeaderLine( (string) $name );
		}
		return $headers;
	}

	/**
	 * The response WordPress answered, or why none arrived.
	 *
	 * @param RequestInterface $request  The request it answers.
	 * @param mixed            $response What `wp_remote_request()` or a `pre_http_request` filter returned.
	 */
	private function result( RequestInterface $request, mixed $response ): ResponseInterface|NetworkFailure {
		if ( is_wp_error( $response ) ) {
			return new NetworkFailure( $request, $response->get_error_message() );
		}
		if ( ! is_array( $response ) ) {
			return new NetworkFailure( $request, 'No response.' );
		}

		$headers = array();
		foreach ( wp_remote_retrieve_headers( $response ) as $name => $value ) {
			$headers[ strtolower( (string) $name ) ] = is_array( $value ) ? implode( ', ', $value ) : (string) $value;
		}

		return new Response(
			(int) wp_remote_retrieve_response_code( $response ),
			$headers,
			wp_remote_retrieve_body( $response )
		);
	}
}
