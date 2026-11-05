<?php
/**
 * The store's connection and a user's link, as another plugin reads them.
 *
 * @package Pixonaut\WooCommerce
 */

declare(strict_types=1);

namespace Pixonaut\WooCommerce\Bridge;

use Pixonaut\WooCommerce\Connection\Connection;

/**
 * What `pixonaut_wc_status()` answers: whether the store is connected and
 * to which workspace, and the first reason, if any, a request cannot reach
 * Pixonaut. Never a key.
 */
final class Status {

	public const NOT_CONNECTED = 'not_connected';

	public const PAUSED_UNAUTHORIZED = 'paused_unauthorized';

	public const PAUSED_SITE_CHANGED = 'paused_site_changed';

	/**
	 * @param Connection $connection The store's connection.
	 */
	public function __construct(
		private readonly Connection $connection,
	) {
	}

	/**
	 * @return array{connected: bool, paused: string|null, workspace: string|null, workspace_id: string|null, environment: string|null, reason: string|null}
	 */
	public function read(): array {
		$stored = $this->connection->current();
		$paused = null === $stored ? null : $this->connection->paused();
		return array(
			'connected'    => null !== $stored,
			'paused'       => $paused,
			'workspace'    => $stored?->tenant_name,
			'workspace_id' => $stored?->tenant_id,
			'environment'  => $stored?->environment,
			'reason'       => $this->reason( null !== $stored, $paused ),
		);
	}

	/**
	 * Why a request cannot reach Pixonaut, the first that applies; null when
	 * it can.
	 *
	 * @param bool        $connected Whether the store is connected.
	 * @param string|null $paused    Why its connection is paused.
	 */
	private function reason( bool $connected, ?string $paused ): ?string {
		if ( ! $connected ) {
			return self::NOT_CONNECTED;
		}
		if ( null === $paused ) {
			return null;
		}
		return Connection::PAUSED_SITE_CHANGED === $paused ? self::PAUSED_SITE_CHANGED : self::PAUSED_UNAUTHORIZED;
	}
}
