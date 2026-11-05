<?php
/**
 * Runs pending schema migrations.
 *
 * @package Pixonaut\WooCommerce
 */

declare(strict_types=1);

namespace Pixonaut\WooCommerce\Migrations;

use LogicException;
use Pixonaut\WooCommerce\Api\Logger;
use Pixonaut\WooCommerce\Api\WcLogger;
use Pixonaut\WooCommerce\Schema;
use RuntimeException;

/**
 * Applies every migration newer than the stored schema version, in version
 * order, recording the version after each one so a failure resumes from there.
 * One request runs them at a time; the others carry on without waiting.
 *
 * A migration that throws MigrationPostponed stops the run without failing the
 * request: it is logged, the later migrations wait for it, and the lock stays
 * until it expires, so a failure that lasts costs one try per lock lifetime.
 * Any other exception reaches the caller and fails the request.
 */
final class MigrationRunner {

	public const VERSION_OPTION = Schema::VERSION_OPTION;

	public const LOCK_OPTION = Schema::MIGRATION_LOCK_OPTION;

	/**
	 * After this long a lock is taken to belong to a request that died, and
	 * the next request takes it over.
	 */
	public const LOCK_TTL_SECONDS = 600; // Ten minutes.

	/**
	 * @var list<Migration>
	 */
	private readonly array $migrations;

	/**
	 * @param Migration[] $migrations
	 * @param Logger      $logger     Logs a postponed migration.
	 * @phpstan-param list<Migration> $migrations
	 * @throws LogicException When a version is below 1 or two migrations share one.
	 */
	public function __construct(
		array $migrations,
		private readonly Logger $logger = new WcLogger(),
	) {
		usort( $migrations, static fn ( Migration $a, Migration $b ): int => $a->version() <=> $b->version() );
		$seen = array();
		foreach ( $migrations as $migration ) {
			if ( $migration->version() < 1 ) {
				throw new LogicException( sprintf( 'Migration version %d is below 1.', (int) $migration->version() ) );
			}
			if ( isset( $seen[ $migration->version() ] ) ) {
				throw new LogicException( sprintf( 'Two migrations share version %d.', (int) $migration->version() ) );
			}
			$seen[ $migration->version() ] = true;
		}
		$this->migrations = $migrations;
	}

	public function current_version(): int {
		return (int) get_option( self::VERSION_OPTION, 0 );
	}

	/**
	 * @return int The number of migrations applied; 0 also when another
	 *             request holds the lock.
	 * @throws RuntimeException When a version cannot be recorded.
	 */
	public function run(): int {
		if ( ! $this->has_pending( $this->current_version() ) || ! $this->acquire_lock() ) {
			return 0;
		}
		$postponed = false;
		try {
			// Another request may have migrated between the first read and the lock.
			wp_cache_delete( 'alloptions', 'options' );
			wp_cache_delete( self::VERSION_OPTION, 'options' );
			return $this->apply_pending( $this->current_version(), $postponed );
		} finally {
			if ( ! $postponed ) {
				delete_option( self::LOCK_OPTION );
			}
		}
	}

	private function has_pending( int $current ): bool {
		foreach ( $this->migrations as $migration ) {
			if ( $migration->version() > $current ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * @param int  $current   The stored schema version.
	 * @param bool $postponed Set when a migration postponed the rest of the run.
	 * @throws RuntimeException When a version cannot be recorded.
	 */
	private function apply_pending( int $current, bool &$postponed ): int {
		$applied = 0;
		foreach ( $this->migrations as $migration ) {
			if ( $migration->version() <= $current ) {
				continue;
			}
			try {
				$migration->up();
			} catch ( MigrationPostponed $e ) {
				$postponed = true;
				$this->logger->error(
					sprintf( 'Migration %d was postponed: %s', (int) $migration->version(), $e->getMessage() )
				);
				return $applied;
			}
			$current = $migration->version();
			update_option( self::VERSION_OPTION, $current, true );
			if ( $this->current_version() !== $current ) {
				throw new RuntimeException( sprintf( 'Could not record schema version %d; later migrations were not run.', (int) $current ) );
			}
			++$applied;
		}
		return $applied;
	}

	/**
	 * Takes the lock. The add_option() call inserts only when the option is absent, which the options
	 * table's unique name makes atomic.
	 */
	private function acquire_lock(): bool {
		if ( add_option( self::LOCK_OPTION, time(), '', false ) ) {
			return true;
		}
		$held_since = (int) get_option( self::LOCK_OPTION, 0 );
		if ( time() - $held_since < self::LOCK_TTL_SECONDS ) {
			return false;
		}
		delete_option( self::LOCK_OPTION );
		return add_option( self::LOCK_OPTION, time(), '', false );
	}
}
