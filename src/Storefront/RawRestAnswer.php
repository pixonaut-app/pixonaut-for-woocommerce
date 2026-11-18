<?php
/**
 * Sends a route's JSON text as it is, gzip-compressed when the browser takes it.
 *
 * @package Pixonaut\WooCommerce
 */

declare(strict_types=1);

namespace Pixonaut\WooCommerce\Storefront;

use WP_REST_Request;
use WP_REST_Response;

/**
 * The REST server encodes a route's answer as JSON and sends a signed-in
 * visitor's no-cache headers over the route's own. A route that answers
 * JSON text it made itself, or its gzip bytes, with headers of its own,
 * passes its `rest_pre_serve_request` to `serve()`, which sends the bytes
 * as they are and the route's `Cache-Control` again.
 */
final class RawRestAnswer {

	public const STATUS_OK = 200;

	public const STATUS_NOT_MODIFIED = 304;

	/**
	 * Whether a request takes a gzip answer, and PHP does not compress it again.
	 *
	 * @param string $accepted The request's `Accept-Encoding`.
	 */
	public static function takes_gzip( string $accepted ): bool {
		return 1 === preg_match( '/\bgzip\b(?!\s*;\s*q=0(?:\.0*)?\s*(?:,|$))/i', $accepted ) && ! filter_var( ini_get( 'zlib.output_compression' ), FILTER_VALIDATE_BOOLEAN );
	}

	/**
	 * Sends a `200` or `304` answer of a route under `$route`, its text as it is.
	 *
	 * @param mixed  $served        Whether the answer was sent already.
	 * @param mixed  $result        The answer.
	 * @param mixed  $request       The request.
	 * @param string $route         The route's path, or the prefix of its paths, from the namespace's leading `/`.
	 * @param string $cache_control What is sent when the answer names no `Cache-Control`.
	 */
	public static function serve( mixed $served, mixed $result, mixed $request, string $route, string $cache_control ): mixed {
		if ( $served || ! $result instanceof WP_REST_Response || ! $request instanceof WP_REST_Request
			|| ! str_starts_with( $request->get_route(), $route ) || ! in_array( $result->get_status(), array( self::STATUS_OK, self::STATUS_NOT_MODIFIED ), true ) ) {
			return $served;
		}
		// For a signed-in visitor the REST server sends its no-cache headers after the answer's own.
		if ( ! headers_sent() ) {
			header_remove( 'Expires' );
			header_remove( 'Pragma' );
			$named = $result->get_headers()['Cache-Control'] ?? $cache_control;
			header( 'Cache-Control: ' . ( is_string( $named ) ? $named : $cache_control ) );
		}
		$data = $result->get_data();
		if ( is_string( $data ) ) {
			echo $data; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- the route's JSON, or its gzip bytes.
		}
		return true;
	}
}
