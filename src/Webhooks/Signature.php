<?php
/**
 * Webhook signature verification.
 *
 * @package Pixonaut\WooCommerce
 */

declare(strict_types=1);

namespace Pixonaut\WooCommerce\Webhooks;

/**
 * Pixonaut signs each delivery attempt with the endpoint's secret: the
 * `x-pixonaut-signature` header is `v1=` and the hex HMAC-SHA256 of the
 * `x-pixonaut-timestamp` value, a `.`, and the raw body. An attempt sent more
 * than TOLERANCE_SECONDS from now, either way, is refused, so a captured
 * delivery cannot be replayed later.
 */
final class Signature {

	public const TIMESTAMP_HEADER = 'x-pixonaut-timestamp';

	public const SIGNATURE_HEADER = 'x-pixonaut-signature';

	public const SCHEME = 'v1';

	public const TOLERANCE_SECONDS = 300;

	private const ALGORITHM = 'sha256';

	/**
	 * Separates signatures when the header carries several, e.g. while a
	 * secret is rotated.
	 */
	private const LIST_SEPARATOR = ',';

	/**
	 * Whether the attempt was signed with $secret within the tolerance.
	 *
	 * @param string $secret    The endpoint's signing secret.
	 * @param string $timestamp The timestamp header, Unix seconds.
	 * @param string $signature The signature header.
	 * @param string $body      The raw request body.
	 * @param int    $now       Unix time.
	 */
	public static function verify( string $secret, string $timestamp, string $signature, string $body, int $now ): bool {
		if ( '' === $secret || ! ctype_digit( $timestamp ) || abs( $now - (int) $timestamp ) > self::TOLERANCE_SECONDS ) {
			return false;
		}
		$expected = hash_hmac( self::ALGORITHM, $timestamp . '.' . $body, $secret );
		foreach ( explode( self::LIST_SEPARATOR, $signature ) as $entry ) {
			$parts = explode( '=', trim( $entry ), 2 );
			if ( 2 === count( $parts ) && self::SCHEME === $parts[0] && hash_equals( $expected, $parts[1] ) ) {
				return true;
			}
		}
		return false;
	}
}
