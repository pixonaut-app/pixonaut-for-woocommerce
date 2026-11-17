<?php
/**
 * Serves an archive's index.
 *
 * @package Pixonaut\WooCommerce
 */

declare(strict_types=1);

namespace Pixonaut\WooCommerce\Storefront;

use Pixonaut\WooCommerce\Catalogue\ArchiveIndexes;
use Pixonaut\WooCommerce\Plugin;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

/**
 * `GET /pixonaut/v1/archive-index/<archive>?v=<version>` answers an
 * archive's index (`ArchiveIndexes`) as JSON, compressed with gzip when the
 * browser takes it, as it is kept. The address of one version never
 * changes what it answers, so it is cached for a year; an older version
 * answers the current index, cached for a minute, and an archive without
 * an index answers `404`. A request naming the index's `ETag` is answered
 * `304`.
 */
final class ArchiveIndexRoute {

	public const ROUTE = '/archive-index';

	/**
	 * How long one version is cached: a year, and it never changes.
	 */
	private const CACHE_CONTROL = 'public, max-age=31536000, immutable';

	/**
	 * How long the answer to an older version is cached.
	 */
	private const STALE_CACHE_CONTROL = 'public, max-age=60';

	private const STATUS_OK = 200;

	private const STATUS_NOT_MODIFIED = 304;

	private const STATUS_NOT_FOUND = 404;

	public function register(): void {
		add_action( 'rest_api_init', array( $this, 'register_route' ) );
		add_filter( 'rest_pre_serve_request', array( $this, 'serve' ), 10, 3 );
	}

	public function register_route(): void {
		register_rest_route(
			Plugin::REST_NAMESPACE,
			self::ROUTE . '/(?P<archive>' . ArchiveIndexes::name_pattern() . ')',
			array(
				'methods'             => WP_REST_Server::READABLE,
				// What the archive's own page lists to every shopper.
				'permission_callback' => '__return_true',
				'callback'            => array( $this, 'handle' ),
				'args'                => array(
					'v' => array(
						'type'     => 'string',
						'required' => false,
					),
				),
			)
		);
	}

	/**
	 * The address of a version of an archive's index.
	 *
	 * @param string $archive The archive.
	 * @param string $version Its index's version.
	 */
	public static function url( string $archive, string $version ): string {
		return add_query_arg( 'v', rawurlencode( $version ), rest_url( Plugin::REST_NAMESPACE . self::ROUTE . '/' . $archive ) );
	}

	/**
	 * Answers an archive's index, its gzip bytes as kept.
	 *
	 * @param WP_REST_Request $request The request.
	 */
	public function handle( WP_REST_Request $request ): WP_REST_Response {
		$kept = ArchiveIndexes::body( (string) $request['archive'] );
		if ( null === $kept ) {
			return new WP_REST_Response(
				array(
					'code'    => 'pixonaut_archive_index_not_found',
					'message' => 'This archive has no index.',
					'data'    => array( 'status' => self::STATUS_NOT_FOUND ),
				),
				self::STATUS_NOT_FOUND,
				array( 'Cache-Control' => 'no-store' )
			);
		}
		$body    = $kept['gzip'];
		$etag    = '"' . $kept['version'] . '"';
		$headers = array(
			'Content-Type'           => 'application/json; charset=utf-8',
			'Cache-Control'          => (string) $request['v'] === $kept['version'] ? self::CACHE_CONTROL : self::STALE_CACHE_CONTROL,
			'ETag'                   => $etag,
			'Vary'                   => 'Accept-Encoding',
			'X-Content-Type-Options' => 'nosniff',
		);
		if ( trim( (string) $request->get_header( 'if_none_match' ) ) === $etag ) {
			return new WP_REST_Response( null, self::STATUS_NOT_MODIFIED, $headers );
		}
		if ( self::takes_gzip( (string) $request->get_header( 'accept_encoding' ) ) ) {
			$headers['Content-Encoding'] = 'gzip';
		} else {
			$body = (string) gzdecode( $body );
		}
		$headers['Content-Length'] = (string) strlen( $body );
		return new WP_REST_Response( $body, self::STATUS_OK, $headers );
	}

	/**
	 * Whether a request takes a gzip answer, and PHP does not compress it again.
	 *
	 * @param string $accepted The request's `Accept-Encoding`.
	 */
	private static function takes_gzip( string $accepted ): bool {
		return 1 === preg_match( '/\bgzip\b(?!\s*;\s*q=0(?:\.0*)?\s*(?:,|$))/i', $accepted ) && ! filter_var( ini_get( 'zlib.output_compression' ), FILTER_VALIDATE_BOOLEAN );
	}

	/**
	 * Sends the index's bytes as they are; the REST server would encode them as JSON.
	 *
	 * @param mixed $served  Whether the answer was sent already.
	 * @param mixed $result  The answer.
	 * @param mixed $request The request.
	 */
	public function serve( mixed $served, mixed $result, mixed $request ): mixed {
		if ( $served || ! $result instanceof WP_REST_Response || ! $request instanceof WP_REST_Request
			|| ! str_starts_with( $request->get_route(), '/' . Plugin::REST_NAMESPACE . self::ROUTE . '/' ) || ! in_array( $result->get_status(), array( self::STATUS_OK, self::STATUS_NOT_MODIFIED ), true ) ) {
			return $served;
		}
		// For a signed-in visitor the REST server sends its no-cache headers after the answer's own.
		if ( ! headers_sent() ) {
			header_remove( 'Expires' );
			header_remove( 'Pragma' );
			$cache_control = $result->get_headers()['Cache-Control'] ?? self::STALE_CACHE_CONTROL;
			header( 'Cache-Control: ' . ( is_string( $cache_control ) ? $cache_control : self::STALE_CACHE_CONTROL ) );
		}
		$data = $result->get_data();
		if ( is_string( $data ) ) {
			echo $data; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- the index's JSON, or its gzip bytes.
		}
		return true;
	}
}
