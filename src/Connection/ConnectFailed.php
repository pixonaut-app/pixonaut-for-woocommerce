<?php
/**
 * Why a connect, a return from the studio or a disconnect did not go through.
 *
 * @package Pixonaut\WooCommerce
 */

declare(strict_types=1);

namespace Pixonaut\WooCommerce\Connection;

use RuntimeException;
use Throwable;

final class ConnectFailed extends RuntimeException {

	/**
	 * `wp-admin/` or the REST API is not on the origin of the site's own
	 * address, where Pixonaut takes the admin's return and the webhook
	 * endpoint.
	 */
	public const ORIGIN_MISMATCH = 'origin_mismatch';

	/**
	 * The return's `state` is unknown, used, expired or another admin's.
	 */
	public const INVALID_STATE = 'invalid_state';

	/**
	 * The member cancelled in the studio.
	 */
	public const ACCESS_DENIED = 'access_denied';

	/**
	 * The studio sent the merchant back with another error, or no code.
	 */
	public const REFUSED = 'refused';

	/**
	 * Pixonaut refused the code (`invalid_grant`) or could not be reached.
	 */
	public const EXCHANGE_FAILED = 'exchange_failed';

	/**
	 * The workspace's Pixonaut plan connects no more stores.
	 */
	public const PLAN_LIMIT_REACHED = 'plan_limit_reached';

	/**
	 * Pixonaut handed over the keys, but the store could not save them.
	 */
	public const STORAGE_FAILED = 'storage_failed';

	/**
	 * The store could not forget its keys when disconnecting.
	 */
	public const DISCONNECT_FAILED = 'disconnect_failed';

	/**
	 * Another request is connecting or disconnecting the store.
	 */
	public const BUSY = 'busy';

	/**
	 * A user cannot link their Pixonaut account while the store is not
	 * connected, or its connection is paused.
	 */
	public const NOT_CONNECTED = 'not_connected';

	/**
	 * @param string         $reason   One of the constants.
	 * @param Throwable|null $previous The cause.
	 */
	public function __construct( public readonly string $reason, ?Throwable $previous = null ) {
		parent::__construct( sprintf( 'Connecting to Pixonaut failed: %s.', $reason ), 0, $previous );
	}
}
