<?php
/**
 * Several database writes as one.
 *
 * @package Pixonaut\WooCommerce
 */

declare(strict_types=1);

namespace Pixonaut\WooCommerce\Catalogue;

use RuntimeException;
use Throwable;

/**
 * Runs work so that its writes land together or not at all, and another
 * request sees them only once all are in. On a connection with autocommit
 * off a transaction is already open, and `START TRANSACTION` would commit
 * it, so the work runs under a savepoint of it instead; so does work run
 * inside another `run()`.
 *
 * It must not be called inside a transaction its caller opened with
 * `START TRANSACTION`: MySQL gives no reliable way to see one, and it would
 * be committed. `MoveProductAttribute` never is.
 */
final class Transaction {

	private const SAVEPOINT = 'pixonaut_wc_work_';

	/**
	 * How many `run()` calls are under way.
	 *
	 * @var int
	 */
	private static int $depth = 0;

	/**
	 * @template T
	 * @param callable $work The writes; throwing undoes them.
	 * @phpstan-param callable(): T $work
	 * @return mixed What `$work` returned.
	 * @phpstan-return T
	 * @throws RuntimeException When the database cannot open a transaction, or commit it; a failed commit is rolled back.
	 * @throws Throwable What `$work` threw, once its writes are undone.
	 */
	public static function run( callable $work ): mixed {
		global $wpdb;
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- transaction control, with nothing to cache and no input: the savepoint is a constant and a depth.
		$savepoint = self::SAVEPOINT . self::$depth;
		$nested    = self::$depth > 0 || '0' === (string) $wpdb->get_var( 'SELECT @@autocommit' );
		if ( false === $wpdb->query( $nested ? 'SAVEPOINT ' . $savepoint : 'START TRANSACTION' ) ) {
			throw new RuntimeException( 'The database could not open a transaction.' );
		}
		++self::$depth;
		try {
			$result = $work();
			if ( false === $wpdb->query( $nested ? 'RELEASE SAVEPOINT ' . $savepoint : 'COMMIT' ) ) {
				throw new RuntimeException( 'The database could not commit a transaction.' );
			}
		} catch ( Throwable $failure ) {
			$wpdb->query( $nested ? 'ROLLBACK TO SAVEPOINT ' . $savepoint : 'ROLLBACK' );
			throw $failure;
		} finally {
			--self::$depth;
		}
		// phpcs:enable
		return $result;
	}
}
