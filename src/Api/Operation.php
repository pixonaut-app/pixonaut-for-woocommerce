<?php
/**
 * One Pixonaut operation the plugin calls.
 *
 * @package Pixonaut\WooCommerce
 */

declare(strict_types=1);

namespace Pixonaut\WooCommerce\Api;

final class Operation {

	public const JSON = 'application/json';

	public const JPEG = 'image/jpeg';

	public const PNG = 'image/png';

	/**
	 * @param string     $method     HTTP method, upper case.
	 * @param string     $path       Path template as the spec writes it, e.g. `/v1/designs/{id}`.
	 * @param Credential $credential Credential the operation is sent with.
	 * @param bool       $idempotent Whether it takes an `Idempotency-Key`.
	 * @param bool       $sensitive  Whether its answers carry secrets, so an error keeps none of the body.
	 * @param string     $accept     The `Accept` header: what a successful answer is.
	 */
	public function __construct(
		public readonly string $method,
		public readonly string $path,
		public readonly Credential $credential,
		public readonly bool $idempotent = false,
		public readonly bool $sensitive = false,
		public readonly string $accept = self::JSON,
	) {
	}

	/**
	 * The operation's name in `consumed.json`, e.g. `GET /v1/connect`.
	 */
	public function id(): string {
		return $this->method . ' ' . $this->path;
	}

	/**
	 * Whether a request target (path and query) is this operation's path.
	 *
	 * @param string $target     E.g. `/v1/designs/d_1?v=2`.
	 */
	public function matches( string $target ): bool {
		$pattern = '#^' . preg_replace( '/\\\{[^}]+\\\}/', '[^/?]+', preg_quote( $this->path, '#' ) ) . '(\?.*)?$#';
		return 1 === preg_match( $pattern, $target );
	}
}
