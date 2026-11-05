<?php
/**
 * The `state` a proof page carries back to the store.
 *
 * @package Pixonaut\WooCommerce
 */

declare(strict_types=1);

namespace Pixonaut\WooCommerce\Approval;

/**
 * Binds a proof page to the order, the order line, the design and the
 * revision it was minted for, signed with a key only the store holds. The
 * store reads it back when the page returns the customer, and trusts no
 * value in it that the signature does not cover.
 *
 * The value is `<payload>.<signature>`, both base64url: the payload is the
 * JSON of the four values, the signature its HMAC-SHA256.
 */
final class ProofState {

	private const HASH = 'sha256';

	private const SEPARATOR = '.';

	/**
	 * Longest `state` Pixonaut takes.
	 */
	public const MAX_LENGTH = 512;

	/**
	 * @param string $key Signing key; the store's own secret.
	 */
	public function __construct( private readonly string $key ) {
	}

	/**
	 * @param int    $order_id  WooCommerce order.
	 * @param int    $item_id   Its order line.
	 * @param string $design_id The design the proof page shows.
	 * @param int    $revision  The design's revision the line holds.
	 */
	public function sign( int $order_id, int $item_id, string $design_id, int $revision ): string {
		$payload = self::encode(
			(string) wp_json_encode(
				array(
					'o' => $order_id,
					'i' => $item_id,
					'd' => $design_id,
					'r' => $revision,
				)
			)
		);
		return $payload . self::SEPARATOR . self::encode( $this->mac( $payload ) );
	}

	/**
	 * The values a `state` was signed over.
	 *
	 * @param string $state A `state` as the proof page returned it.
	 *
	 * @return array{order_id: int, item_id: int, design_id: string, revision: int}|null Null when it is malformed or its signature is not the store's.
	 */
	public function verify( string $state ): ?array {
		$parts = explode( self::SEPARATOR, $state );
		if ( 2 !== count( $parts ) || ! hash_equals( self::encode( $this->mac( $parts[0] ) ), $parts[1] ) ) {
			return null;
		}
		$decoded = base64_decode( strtr( $parts[0], '-_', '+/' ), true ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- base64url, as sign() writes it.
		$values  = false === $decoded ? null : json_decode( $decoded, true );
		if ( ! is_array( $values ) || ! is_int( $values['o'] ?? null ) || ! is_int( $values['i'] ?? null ) || ! is_string( $values['d'] ?? null ) || ! is_int( $values['r'] ?? null ) ) {
			return null;
		}
		return array(
			'order_id'  => $values['o'],
			'item_id'   => $values['i'],
			'design_id' => $values['d'],
			'revision'  => $values['r'],
		);
	}

	private function mac( string $payload ): string {
		return hash_hmac( self::HASH, $payload, $this->key, true );
	}

	private static function encode( string $bytes ): string {
		return rtrim( strtr( base64_encode( $bytes ), '+/', '-_' ), '=' ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- base64url: safe in a query string as is.
	}
}
