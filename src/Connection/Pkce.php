<?php
/**
 * PKCE (RFC 7636) for the connect flow.
 *
 * @package Pixonaut\WooCommerce
 */

declare(strict_types=1);

namespace Pixonaut\WooCommerce\Connection;

/**
 * The verifier stays on the server; the studio is sent only its S256
 * challenge, and the code it hands back works only with the verifier.
 */
final class Pkce {

	/**
	 * Random bytes in a verifier or a `state`: 43 base64url characters,
	 * within RFC 7636's 43 to 128.
	 */
	private const RANDOM_BYTES = 32;

	public const METHOD = 'S256';

	/**
	 * A new random verifier, or any other single-use random token.
	 */
	public static function verifier(): string {
		return self::base64url( random_bytes( self::RANDOM_BYTES ) );
	}

	/**
	 * The S256 challenge for a verifier: base64url of its SHA-256.
	 *
	 * @param string $verifier From `verifier()`.
	 */
	public static function challenge( string $verifier ): string {
		return self::base64url( hash( 'sha256', $verifier, true ) );
	}

	/**
	 * @param string $bytes Raw bytes.
	 */
	private static function base64url( string $bytes ): string {
		return rtrim( strtr( base64_encode( $bytes ), '+/', '-_' ), '=' ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- base64url is what PKCE sends.
	}
}
