<?php
/**
 * Lets one request at a time handle the parcels of a WooCommerce order.
 *
 * @package Pixonaut\WooCommerce
 */

declare(strict_types=1);

namespace Pixonaut\WooCommerce\Fulfilment;

use Pixonaut\WooCommerce\Schema;
use RuntimeException;
use WC_Order;

/**
 * A parcel event reads what the order's ParcelRecords say, decides whom to
 * tell, and writes them back. Two events about one order at once, the
 * webhook and the events pull, or two parcels of a split line, would each
 * decide from what the other had not written yet. The lock is an option,
 * which add_option() inserts only when it is absent, so taking it is atomic;
 * an event that finds it held fails, and handling the event again retries it.
 */
final class ParcelLock {

	/**
	 * A lock older than this was left by a request that died: handling a
	 * parcel sends at most one email and a few notes.
	 */
	public const TTL_SECONDS = 60;

	/**
	 * Runs $work while holding the lock of the order, with the order read
	 * afresh once the lock is taken.
	 *
	 * @param int                     $order_id The WooCommerce order.
	 * @param callable(WC_Order):void $work     What to do with the order.
	 * @throws RuntimeException When another request holds the lock.
	 */
	public static function run( int $order_id, callable $work ): void {
		$key = Schema::PARCEL_LOCK_OPTION_PREFIX . $order_id;
		if ( ! self::take( $key ) ) {
			throw new RuntimeException( sprintf( 'The parcels of order %d are being handled by another request.', (int) $order_id ) );
		}
		try {
			$order = wc_get_order( $order_id );
			if ( $order instanceof WC_Order ) {
				$work( $order );
			}
		} finally {
			delete_option( $key );
		}
	}

	/**
	 * @param string $key The lock's option.
	 * @return bool Whether it took the lock; false while another request holds it.
	 */
	private static function take( string $key ): bool {
		if ( add_option( $key, time(), '', false ) ) {
			return true;
		}
		if ( time() - (int) get_option( $key, 0 ) < self::TTL_SECONDS ) {
			return false;
		}
		delete_option( $key );
		return add_option( $key, time(), '', false );
	}
}
