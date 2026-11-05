<?php
/**
 * Deprecations Pixonaut announced on the operations the plugin calls.
 *
 * @package Pixonaut\WooCommerce
 */

declare(strict_types=1);

namespace Pixonaut\WooCommerce\Api;

use Pixonaut\WooCommerce\Schema;

/**
 * Pixonaut marks a response to a deprecated operation, or to a request that
 * still sends a deprecated field, with `Deprecation` and `Sunset` headers.
 * Each operation's latest notice is kept for the admin notice that tells the
 * merchant to update the plugin.
 */
final class Deprecations {

	public const OPTION = Schema::API_DEPRECATIONS_OPTION;

	/**
	 * Records a notice. Returns whether it is new or changed, so the caller
	 * logs it once rather than on every call.
	 *
	 * @param string      $operation   Operation id.
	 * @param string      $deprecation `Deprecation` header: `@` and Unix seconds.
	 * @param string|null $sunset      `Sunset` header: an HTTP date.
	 * @param int         $now         Unix time.
	 */
	public function record( string $operation, string $deprecation, ?string $sunset, int $now ): bool {
		$all      = $this->all();
		$existing = $all[ $operation ] ?? null;
		if ( null !== $existing && $existing['deprecation'] === $deprecation && $existing['sunset'] === $sunset ) {
			return false;
		}
		$all[ $operation ] = array(
			'deprecation' => $deprecation,
			'sunset'      => $sunset,
			'seen_at'     => $now,
		);
		update_option( self::OPTION, $all, false );
		return true;
	}

	/**
	 * @return array<string, array{deprecation: string, sunset: string|null, seen_at: int}> Keyed by operation id.
	 */
	public function all(): array {
		$stored = get_option( self::OPTION, array() );
		return is_array( $stored ) ? $stored : array();
	}
}
