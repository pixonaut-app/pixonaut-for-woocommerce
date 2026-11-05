<?php
/**
 * A Pixonaut call that did not succeed.
 *
 * @package Pixonaut\WooCommerce
 */

declare(strict_types=1);

namespace Pixonaut\WooCommerce\Api;

use RuntimeException;

/**
 * `$code` and `$status` are open sets: whatever Pixonaut answered is kept,
 * known or not. `$status` is 0 when no response arrived.
 */
class ApiError extends RuntimeException {

	/**
	 * Statuses that say "try again later" whatever the code.
	 */
	private const RETRYABLE_STATUSES = array( 408, 429 );

	/**
	 * Lowest status of a server-side failure; those may succeed when sent again.
	 */
	private const SERVER_ERROR_MIN = 500;

	/**
	 * Longest stretch of a body kept in `details` when it was not JSON.
	 */
	private const RAW_BODY_EXCERPT_BYTES = 500;

	/**
	 * @param string               $operation   Operation id.
	 * @param int                  $status      HTTP status, 0 when no response arrived.
	 * @param string               $error_code  Pixonaut's `error`, or one of the client's own codes.
	 * @param bool                 $retryable   Whether sending the same request again may succeed.
	 * @param int|null             $retry_after Seconds to wait before sending again, when Pixonaut said.
	 * @param array<string, mixed> $details     The rest of the error body.
	 */
	final public function __construct(
		public readonly string $operation,
		public readonly int $status,
		public readonly string $error_code,
		public readonly bool $retryable,
		public readonly ?int $retry_after = null,
		public readonly array $details = array(),
	) {
		parent::__construct( sprintf( 'Pixonaut %s answered %d %s', $operation, $status, $error_code ) );
	}

	/**
	 * Maps an unsuccessful response to the error it carries.
	 *
	 * @param string   $operation Operation id.
	 * @param Response $response  An unsuccessful response.
	 * @param int      $now       Unix time, for a `Retry-After` given as a date.
	 */
	public static function from_response( string $operation, Response $response, int $now ): self {
		$body = json_decode( $response->body, true );
		if ( is_array( $body ) && isset( $body['error'] ) && is_string( $body['error'] ) ) {
			$code    = $body['error'];
			$details = $body;
			unset( $details['error'] );
		} else {
			$code    = ErrorCodes::UNEXPECTED_RESPONSE;
			$details = self::excerpt( $response );
		}

		$is_bug = in_array( $code, ErrorCodes::IDEMPOTENCY_BUGS, true );
		if ( $is_bug ) {
			// Resending the same broken request never helps, whatever the body says.
			$retryable = false;
		} elseif ( isset( $details['retryable'] ) && is_bool( $details['retryable'] ) ) {
			$retryable = $details['retryable'];
		} else {
			$retryable = $response->status >= self::SERVER_ERROR_MIN
				|| in_array( $response->status, self::RETRYABLE_STATUSES, true )
				|| ErrorCodes::IDEMPOTENCY_KEY_IN_USE === $code;
		}

		$class = $is_bug ? IdempotencyBug::class : self::class;
		return new $class(
			$operation,
			$response->status,
			$code,
			$retryable,
			self::retry_after( $response->header( 'Retry-After' ), $now ),
			$details
		);
	}

	/**
	 * A response whose body is not what the operation answers.
	 *
	 * @param string   $operation Operation id.
	 * @param Response $response  The response.
	 */
	public static function unexpected( string $operation, Response $response ): self {
		return new self( $operation, $response->status, ErrorCodes::UNEXPECTED_RESPONSE, false, null, self::excerpt( $response ) );
	}

	/**
	 * The same error without its details, for an operation whose answers
	 * carry secrets: the body may hold them.
	 */
	public function redacted(): static {
		return new static( $this->operation, $this->status, $this->error_code, $this->retryable, $this->retry_after, array( 'redacted' => true ) );
	}

	/**
	 * @param Response $response The response.
	 *
	 * @return array{body: string}
	 */
	private static function excerpt( Response $response ): array {
		return array( 'body' => substr( $response->body, 0, self::RAW_BODY_EXCERPT_BYTES ) );
	}

	/**
	 * `Retry-After` in seconds; the header is either seconds or an HTTP date.
	 *
	 * @param string|null $header The header.
	 * @param int         $now    Unix time.
	 */
	private static function retry_after( ?string $header, int $now ): ?int {
		if ( null === $header || '' === trim( $header ) ) {
			return null;
		}
		$header = trim( $header );
		if ( ctype_digit( $header ) ) {
			return (int) $header;
		}
		$at = strtotime( $header );
		return false === $at ? null : max( 0, $at - $now );
	}
}
