<?php
/**
 * The developer tools' log of requests to Pixonaut.
 *
 * @package Pixonaut\WooCommerce
 */

declare(strict_types=1);

namespace Pixonaut\WooCommerce\Api;

use Closure;
use Pixonaut\WooCommerce\Schema;
use Pixonaut\WooCommerce\Settings\Settings;

/**
 * While the request log is on, keeps the last requests to Pixonaut, newest
 * first: method, operation, path, status, duration, request id and
 * idempotency key. Nothing secret is kept: the client's hooks never see the
 * credential, no body is kept, and anything in the path that looks like a key
 * or token is redacted.
 */
final class RequestLog {

	public const OPTION = Schema::REQUEST_LOG_OPTION;

	public const MAX_ENTRIES = 50;

	/**
	 * The header Pixonaut names the request it answers in.
	 */
	public const REQUEST_ID_HEADER = 'X-Request-Id';

	public const REDACTED = '[redacted]';

	/**
	 * Pixonaut's secret and token prefixes: integration, publishable and
	 * secret keys, webhook secrets, and share and designer link tokens.
	 */
	private const SECRET_PATTERN = '/\b(ik|pk|sk|whsec|link|shl)_[A-Za-z0-9_\-]+/';

	private const MILLISECONDS_PER_SECOND = 1000;

	/**
	 * When each request in flight started, by the request object's id.
	 *
	 * @var array<int, float>
	 */
	private array $started = array();

	private readonly Closure $clock;

	/**
	 * @param Settings     $settings Whether the log is on.
	 * @param Closure|null $clock    `fn(): float` seconds; `microtime( true )` when null.
	 */
	public function __construct( private readonly Settings $settings, ?Closure $clock = null ) {
		$this->clock = $clock ?? static fn (): float => microtime( true );
	}

	public function register(): void {
		add_action( 'pixonaut_wc_api_before_request', array( $this, 'before' ), 10, 1 );
		add_action( 'pixonaut_wc_api_after_request', array( $this, 'after' ), 10, 3 );
	}

	public function enabled(): bool {
		return true === $this->settings->get( Settings::REQUEST_LOG );
	}

	/**
	 * @param mixed $request The request about to be sent.
	 */
	public function before( mixed $request ): void {
		if ( $request instanceof PreparedRequest && $this->enabled() ) {
			$this->started[ spl_object_id( $request ) ] = ( $this->clock )();
		}
	}

	/**
	 * @param mixed $request  The request sent.
	 * @param mixed $response The response, or null when none arrived.
	 * @param mixed $error    Why no response arrived, or null.
	 */
	public function after( mixed $request, mixed $response, mixed $error = null ): void {
		if ( ! $request instanceof PreparedRequest ) {
			return;
		}
		$id      = spl_object_id( $request );
		$started = $this->started[ $id ] ?? null;
		unset( $this->started[ $id ] );
		if ( null === $started || ! $this->enabled() ) {
			return;
		}
		$response = $response instanceof Response ? $response : null;
		$entry    = array(
			'at'              => time(),
			'method'          => strtok( $request->operation, ' ' ),
			'operation'       => $request->operation,
			'path'            => self::redact( $request->target ),
			'status'          => $response->status ?? 0,
			'error'           => $error instanceof ApiError ? $error->error_code : '',
			'duration_ms'     => (int) round( ( ( $this->clock )() - $started ) * self::MILLISECONDS_PER_SECOND ),
			'request_id'      => self::redact( (string) $response?->header( self::REQUEST_ID_HEADER ) ),
			'idempotency_key' => self::redact( (string) $request->idempotency_key ),
		);
		$entries  = array_slice( array_merge( array( $entry ), $this->entries() ), 0, self::MAX_ENTRIES );
		update_option( self::OPTION, $entries, false );
	}

	/**
	 * The requests kept, newest first.
	 *
	 * @return list<array<string, mixed>>
	 */
	public function entries(): array {
		$stored = get_option( self::OPTION, array() );
		return is_array( $stored ) ? array_values( array_filter( $stored, 'is_array' ) ) : array();
	}

	public function clear(): void {
		delete_option( self::OPTION );
	}

	/**
	 * @param string $text A path, header or key.
	 * @return string It with every key and token replaced.
	 */
	public static function redact( string $text ): string {
		return (string) preg_replace( self::SECRET_PATTERN, '$1_' . self::REDACTED, $text );
	}
}
