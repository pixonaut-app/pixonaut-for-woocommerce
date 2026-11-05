<?php
/**
 * An HTTP response from Pixonaut.
 *
 * @package Pixonaut\WooCommerce
 */

declare(strict_types=1);

namespace Pixonaut\WooCommerce\Api;

use stdClass;
use UnexpectedValueException;

final class Response {

	/**
	 * @var array<string, string>
	 */
	public readonly array $headers;

	/**
	 * @param int                   $status  HTTP status.
	 * @param array<string, string> $headers Response headers; names are matched case-insensitively.
	 * @param string                $body    Raw body.
	 */
	public function __construct(
		public readonly int $status,
		array $headers,
		public readonly string $body,
	) {
		$this->headers = array_change_key_case( $headers, CASE_LOWER );
	}

	public function header( string $name ): ?string {
		return $this->headers[ strtolower( $name ) ] ?? null;
	}

	public function is_success(): bool {
		return $this->status >= 200 && $this->status < 300;
	}

	/**
	 * Whether Pixonaut answered a repeated idempotent request with what the
	 * first one made.
	 */
	public function replayed(): bool {
		return 'true' === $this->header( 'Idempotent-Replayed' );
	}

	/**
	 * The body decoded as a JSON object.
	 *
	 * @return array<string, mixed>
	 *
	 * @throws UnexpectedValueException When the body is not a JSON object.
	 */
	public function json(): array {
		// Decoded as objects first: associative decoding turns a JSON list into an array too.
		if ( ! json_decode( $this->body ) instanceof stdClass ) {
			throw new UnexpectedValueException( 'Pixonaut response body is not a JSON object.' );
		}
		return (array) json_decode( $this->body, true );
	}
}
