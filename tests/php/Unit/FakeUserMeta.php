<?php
/**
 * In-memory user meta for unit tests.
 *
 * @package Pixonaut\WooCommerce
 */

declare(strict_types=1);

namespace Pixonaut\WooCommerce\Tests\Unit;

use Brain\Monkey\Functions;

trait FakeUserMeta {

	/**
	 * Single values, by user id, then key.
	 *
	 * @var array<int, array<string, mixed>>
	 */
	private array $user_meta = array();

	private function fake_user_meta(): void {
		Functions\when( 'get_user_meta' )->alias(
			fn ( int $user_id, string $key = '', bool $single = false ): mixed => $this->user_meta[ $user_id ][ $key ] ?? ( $single ? '' : array() )
		);
		Functions\when( 'update_user_meta' )->alias(
			function ( int $user_id, string $key, mixed $value ): bool {
				$this->user_meta[ $user_id ][ $key ] = $value;
				return true;
			}
		);
		Functions\when( 'delete_user_meta' )->alias(
			function ( int $user_id, string $key, mixed $value = '' ): bool {
				if ( ! isset( $this->user_meta[ $user_id ][ $key ] ) ) {
					return false;
				}
				// As WordPress does, a value deletes the meta only when it is that value.
				if ( '' !== $value && $this->user_meta[ $user_id ][ $key ] !== $value ) {
					return false;
				}
				unset( $this->user_meta[ $user_id ][ $key ] );
				return true;
			}
		);
	}
}
