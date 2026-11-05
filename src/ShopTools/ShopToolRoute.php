<?php
/**
 * The route Pixonaut calls the shop tools on.
 *
 * @package Pixonaut\WooCommerce
 */

declare(strict_types=1);

namespace Pixonaut\WooCommerce\ShopTools;

use Pixonaut\WooCommerce\Api\Logger;
use Pixonaut\WooCommerce\Connection\StoreAccess;
use Pixonaut\WooCommerce\Plugin;
use Throwable;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

/**
 * `POST /pixonaut/v1/shop-tools/{tool}`, with `Authorization: Bearer <secret>`
 * and the tool's input as a JSON body. Answers `{result}`, or `{code, message}`.
 *
 * Pixonaut is not a WordPress user: the credential is the authentication,
 * and each call runs as nobody, with WooCommerce's own permission check let
 * through only for the length of the tool. The `code` is what Pixonaut reads;
 * the messages are for its logs and stay in English, whatever the site's
 * language.
 */
final class ShopToolRoute {

	public const ROUTE = '/shop-tools/(?P<tool>[^/]+)';

	/**
	 * The part of a request's route that says it is for this one.
	 */
	private const ROUTE_PREFIX = '/' . Plugin::REST_NAMESPACE . '/shop-tools/';

	private const OK = 200;

	private const UNAUTHORIZED = 401;

	private const FORBIDDEN = 403;

	private const NOT_FOUND = 404;

	private const UNPROCESSABLE = 422;

	private const STORE_FAILED = 502;

	private const CREDENTIAL_INVALID = 'credential_invalid';

	private const STORE_ACCESS_OFF = 'store_access_off';

	private const UNKNOWN_TOOL = 'unknown_tool';

	private const INVALID_INPUT = 'invalid_input';

	private const STORE_ERROR = 'store_error';

	/**
	 * What a route answers when the input names something that is not there,
	 * or is not one it takes. Any other refusal is the shop's own failure.
	 */
	private const STATUSES_OF_BAD_INPUT = array( 400, 404, 409, 422 );

	/**
	 * What the code of an error from one of the plugin's own abilities starts with.
	 */
	private const OWN_ERROR_PREFIX = 'pixonaut_wc_';

	private const BEARER_PATTERN = '/^Bearer\s+(\S+)$/i';

	/**
	 * After WooCommerce's own uses of the permission filter, so nothing narrows it for the tool.
	 */
	private const LAST = PHP_INT_MAX;

	/**
	 * @param StoreAccess $access Knows the credential and the switch.
	 * @param ShopTools   $tools  What the credential reaches.
	 * @param Logger      $logger Logs a tool that failed.
	 */
	public function __construct(
		private readonly StoreAccess $access,
		private readonly ShopTools $tools,
		private readonly Logger $logger,
	) {
	}

	public function register(): void {
		add_action( 'rest_api_init', array( $this, 'register_route' ) );
		add_filter( 'rest_request_after_callbacks', array( $this, 'answer_a_body_wordpress_refused' ), 10, 3 );
	}

	public function register_route(): void {
		register_rest_route(
			Plugin::REST_NAMESPACE,
			self::ROUTE,
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'call' ),
				// Pixonaut is not a WordPress user: the credential is the authentication, checked in call().
				'permission_callback' => '__return_true',
			)
		);
	}

	/**
	 * @param WP_REST_Request $request The call.
	 * @phpstan-param WP_REST_Request<array<string, mixed>> $request
	 */
	public function call( WP_REST_Request $request ): WP_REST_Response {
		$refused = $this->refusal_of_the_caller( $request );
		if ( null !== $refused ) {
			return $refused;
		}
		$tool = $this->tools->all()[ (string) $request['tool'] ] ?? null;
		if ( null === $tool ) {
			return self::error( self::NOT_FOUND, self::UNKNOWN_TOOL, 'The store has no such tool.' );
		}
		$input = '' === $request->get_body() ? array() : json_decode( $request->get_body(), true );
		if ( ! is_array( $input ) ) {
			return self::error( self::UNPROCESSABLE, self::INVALID_INPUT, 'The input is not a JSON object.' );
		}
		$definition = $tool->definition();
		$schema     = $definition['input_schema'] ?? array();
		$valid      = rest_validate_value_from_schema( $input, is_array( $schema ) ? $schema : array(), 'input' );
		if ( is_wp_error( $valid ) ) {
			return self::error( self::UNPROCESSABLE, self::INVALID_INPUT, $valid->get_error_message() );
		}
		return $this->run( (string) $request['tool'], $definition['execute_callback'], $input );
	}

	/**
	 * WordPress refuses a body that is not JSON before the route is called,
	 * in its own shape, whoever asks.
	 *
	 * @param mixed           $response What the request answers so far.
	 * @param mixed           $handler  The route's handler.
	 * @param WP_REST_Request $request  The request.
	 * @phpstan-param WP_REST_Request<array<string, mixed>> $request
	 * @return mixed
	 */
	public function answer_a_body_wordpress_refused( mixed $response, mixed $handler, WP_REST_Request $request ): mixed {
		if ( ! $response instanceof WP_Error || ! str_starts_with( $request->get_route(), self::ROUTE_PREFIX ) ) {
			return $response;
		}
		return $this->refusal_of_the_caller( $request )
			?? self::error( self::UNPROCESSABLE, self::INVALID_INPUT, 'The input is not a JSON object.' );
	}

	/**
	 * @param WP_REST_Request $request The call.
	 * @phpstan-param WP_REST_Request<array<string, mixed>> $request
	 */
	private function refusal_of_the_caller( WP_REST_Request $request ): ?WP_REST_Response {
		$secret = 1 === preg_match( self::BEARER_PATTERN, self::authorization( $request ), $found ) ? $found[1] : null;
		if ( null === $secret ) {
			return self::invalid_credential();
		}
		if ( ! $this->access->is_on() ) {
			return self::error( self::FORBIDDEN, self::STORE_ACCESS_OFF, 'The store does not allow Pixel to make changes to it.' );
		}
		return $this->access->accepts( $secret ) ? null : self::invalid_credential();
	}

	/**
	 * The request's `Authorization` header. Apache's PHP module keeps a
	 * bearer token out of the server variables WordPress reads, unless the
	 * rewrite in WordPress's `.htaccess` put it there, which plain permalinks
	 * go without.
	 *
	 * @param WP_REST_Request $request The call.
	 * @phpstan-param WP_REST_Request<array<string, mixed>> $request
	 */
	private static function authorization( WP_REST_Request $request ): string {
		$header = (string) $request->get_header( 'authorization' );
		if ( '' !== $header || ! function_exists( 'apache_request_headers' ) ) {
			return $header;
		}
		foreach ( apache_request_headers() as $name => $value ) {
			if ( 0 === strcasecmp( (string) $name, 'authorization' ) ) {
				return (string) $value;
			}
		}
		return '';
	}

	/**
	 * Runs a tool as nobody.
	 *
	 * @param string       $name    The tool's name.
	 * @param callable     $execute Runs the tool.
	 * @param array<mixed> $input   Its input, valid for its schema.
	 */
	private function run( string $name, callable $execute, array $input ): WP_REST_Response {
		wp_set_current_user( 0 );
		add_filter( 'woocommerce_rest_check_permissions', '__return_true', self::LAST );
		try {
			$result = $execute( $input );
		} catch ( Throwable $e ) {
			$this->logger->error( sprintf( 'The shop tool %1$s failed: %2$s', $name, $e->getMessage() ) );
			return self::error( self::STORE_FAILED, self::STORE_ERROR, 'The store could not do that.' );
		} finally {
			remove_filter( 'woocommerce_rest_check_permissions', '__return_true', self::LAST );
		}
		if ( $result instanceof WP_Error ) {
			return self::answer_error( $result );
		}
		return new WP_REST_Response( array( 'result' => $result ), self::OK );
	}

	/**
	 * What a tool refused, as the contract names it. With no status, the
	 * plugin's own abilities are saying the input does not fit; anything else
	 * with none is a failure the REST server would answer 500.
	 *
	 * @param WP_Error $error What the tool answered.
	 */
	private static function answer_error( WP_Error $error ): WP_REST_Response {
		$data   = $error->get_error_data();
		$status = is_array( $data ) ? ( $data['status'] ?? null ) : null;
		$own    = str_starts_with( (string) $error->get_error_code(), self::OWN_ERROR_PREFIX );
		if ( ( null === $status && $own ) || in_array( $status, self::STATUSES_OF_BAD_INPUT, true ) ) {
			return self::error( self::UNPROCESSABLE, self::INVALID_INPUT, $error->get_error_message() );
		}
		return self::error( self::STORE_FAILED, self::STORE_ERROR, $error->get_error_message() );
	}

	private static function invalid_credential(): WP_REST_Response {
		return self::error( self::UNAUTHORIZED, self::CREDENTIAL_INVALID, 'The credential is unknown or was revoked.' );
	}

	/**
	 * @param int    $status  The HTTP status.
	 * @param string $code    The refusal's code.
	 * @param string $message What it means.
	 */
	private static function error( int $status, string $code, string $message ): WP_REST_Response {
		return new WP_REST_Response(
			array(
				'code'    => $code,
				'message' => $message,
			),
			$status
		);
	}
}
