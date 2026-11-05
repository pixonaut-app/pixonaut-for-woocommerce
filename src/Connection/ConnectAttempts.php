<?php
/**
 * Connects in progress, one per admin.
 *
 * @package Pixonaut\WooCommerce
 */

declare(strict_types=1);

namespace Pixonaut\WooCommerce\Connection;

use Pixonaut\WooCommerce\Schema;

/**
 * The `state` and PKCE verifier of a connect, kept in the meta of the admin
 * who started it, so only that admin can finish it. The first return claims
 * it, whatever it carries, so a `state` works once.
 */
final class ConnectAttempts {

	public const META = Schema::CONNECT_ATTEMPT_META;

	/**
	 * How long the admin has to approve in the studio: ten minutes.
	 */
	public const LIFETIME_SECONDS = 600;

	/**
	 * Starts a connect for a user, replacing any they had started.
	 *
	 * @param int    $user_id      The admin.
	 * @param string $redirect_uri Where the studio sends them back.
	 * @param int    $now          Unix time.
	 * @param bool   $store_access Whether the admin allowed Pixel to make changes to the store, for once it is connected.
	 * @return array{state: string, verifier: string}
	 */
	public function begin( int $user_id, string $redirect_uri, int $now, bool $store_access = false ): array {
		$state    = Pkce::verifier();
		$verifier = Pkce::verifier();
		update_user_meta(
			$user_id,
			self::META,
			array(
				'state_hash'   => hash( 'sha256', $state ),
				'verifier'     => $verifier,
				'redirect_uri' => $redirect_uri,
				'expires_at'   => $now + self::LIFETIME_SECONDS,
				'store_access' => $store_access,
			)
		);
		return array(
			'state'    => $state,
			'verifier' => $verifier,
		);
	}

	/**
	 * Ends the user's connect, and answers it when `state` is theirs and
	 * has not expired.
	 *
	 * @param int    $user_id The admin the return came to.
	 * @param string $state   The `state` the return carried.
	 * @param int    $now     Unix time.
	 * @return array{verifier: string, redirect_uri: string, store_access: bool}|null
	 */
	public function take( int $user_id, string $state, int $now ): ?array {
		$attempt = get_user_meta( $user_id, self::META, true );
		// An empty value would delete every attempt the user has, not this one.
		if ( '' === $attempt ) {
			return null;
		}
		// Deleting by value claims exactly the attempt read: a return that
		// came in at the same time, or a connect started since, wins instead.
		if ( ! delete_user_meta( $user_id, self::META, $attempt ) ) {
			return null;
		}
		if ( ! is_array( $attempt ) || '' === $state ) {
			return null;
		}
		$hash         = $attempt['state_hash'] ?? null;
		$verifier     = $attempt['verifier'] ?? null;
		$redirect_uri = $attempt['redirect_uri'] ?? null;
		$expires_at   = $attempt['expires_at'] ?? null;
		if ( ! is_string( $hash ) || ! is_string( $verifier ) || ! is_string( $redirect_uri ) || ! is_int( $expires_at ) ) {
			return null;
		}
		if ( $expires_at < $now || ! hash_equals( $hash, hash( 'sha256', $state ) ) ) {
			return null;
		}
		return array(
			'verifier'     => $verifier,
			'redirect_uri' => $redirect_uri,
			'store_access' => true === ( $attempt['store_access'] ?? false ),
		);
	}
}
