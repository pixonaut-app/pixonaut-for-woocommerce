<?php
/**
 * Encrypts what the plugin stores about its connection.
 *
 * @package Pixonaut\WooCommerce
 */

declare(strict_types=1);

namespace Pixonaut\WooCommerce\Connection;

use LogicException;
use SodiumException;

/**
 * Authenticated encryption (libsodium secretbox; WordPress ships
 * sodium_compat where the extension is missing) with a key derived from the
 * site's auth salt, which holds `AUTH_KEY`. A copy of the database alone
 * does not reveal the store's keys, and a changed salt makes them unreadable
 * rather than wrong.
 */
final class Cipher {

	/**
	 * Marks the format, so a later one can be told apart.
	 */
	private const PREFIX = 'pxc1:';

	/**
	 * HKDF context: a key for this purpose only, whatever else uses the salt.
	 */
	private const KEY_INFO = 'pixonaut-for-woocommerce connection v1';

	/**
	 * @param string $secret The material the key is derived from; never empty.
	 * @throws LogicException When it is empty.
	 */
	public function __construct( private readonly string $secret ) {
		if ( '' === $secret ) {
			throw new LogicException( 'The connection cipher needs a secret.' );
		}
	}

	/**
	 * The cipher keyed from this site's `AUTH_KEY` and `AUTH_SALT`.
	 */
	public static function for_site(): self {
		return new self( wp_salt( 'auth' ) );
	}

	/**
	 * @param string $plaintext What to encrypt.
	 */
	public function encrypt( string $plaintext ): string {
		$nonce = random_bytes( SODIUM_CRYPTO_SECRETBOX_NONCEBYTES );
		return self::PREFIX . base64_encode( $nonce . sodium_crypto_secretbox( $plaintext, $nonce, $this->key() ) ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- binary ciphertext stored as text.
	}

	/**
	 * @param string $sealed From `encrypt()`.
	 * @return string|null The plaintext, or null when it was not sealed with this key or was altered.
	 */
	public function decrypt( string $sealed ): ?string {
		if ( ! str_starts_with( $sealed, self::PREFIX ) ) {
			return null;
		}
		$raw = base64_decode( substr( $sealed, strlen( self::PREFIX ) ), true ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- binary ciphertext stored as text.
		if ( false === $raw || strlen( $raw ) < SODIUM_CRYPTO_SECRETBOX_NONCEBYTES + SODIUM_CRYPTO_SECRETBOX_MACBYTES ) {
			return null;
		}
		try {
			$plaintext = sodium_crypto_secretbox_open( substr( $raw, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES ), substr( $raw, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES ), $this->key() );
		} catch ( SodiumException $e ) {
			return null;
		}
		return false === $plaintext ? null : $plaintext;
	}

	private function key(): string {
		return hash_hkdf( 'sha256', $this->secret, SODIUM_CRYPTO_SECRETBOX_KEYBYTES, self::KEY_INFO );
	}
}
