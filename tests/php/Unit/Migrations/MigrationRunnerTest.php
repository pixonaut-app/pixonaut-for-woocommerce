<?php
/**
 * @package Pixonaut\WooCommerce
 */

declare(strict_types=1);

namespace Pixonaut\WooCommerce\Tests\Unit\Migrations;

use LogicException;
use Pixonaut\WooCommerce\Migrations\Migration;
use Pixonaut\WooCommerce\Migrations\MigrationPostponed;
use Pixonaut\WooCommerce\Migrations\MigrationRunner;
use Pixonaut\WooCommerce\Tests\Support\MigrationLog;
use Pixonaut\WooCommerce\Tests\Support\RecordingLogger;
use Pixonaut\WooCommerce\Tests\Support\RecordingMigration;
use Pixonaut\WooCommerce\Tests\Unit\FakeOptions;
use Pixonaut\WooCommerce\Tests\Unit\TestCase;
use RuntimeException;

final class MigrationRunnerTest extends TestCase {

	use FakeOptions;

	protected function setUp(): void {
		parent::setUp();
		$this->fake_options();
	}

	public function test_runs_pending_migrations_in_version_order_and_records_the_version(): void {
		$log    = new MigrationLog();
		$runner = new MigrationRunner(
			array(
				new RecordingMigration( 3, $log ),
				new RecordingMigration( 1, $log ),
				new RecordingMigration( 2, $log ),
			)
		);

		$this->assertSame( 3, $runner->run() );
		$this->assertSame( array( 1, 2, 3 ), $log->versions );
		$this->assertSame( 3, $this->options[ MigrationRunner::VERSION_OPTION ] );
		$this->assertArrayNotHasKey( MigrationRunner::LOCK_OPTION, $this->options );
	}

	public function test_runs_each_migration_once(): void {
		$log    = new MigrationLog();
		$runner = new MigrationRunner( array( new RecordingMigration( 1, $log ), new RecordingMigration( 2, $log ) ) );

		$runner->run();

		$this->assertSame( 0, $runner->run() );
		$this->assertSame( array( 1, 2 ), $log->versions );
	}

	public function test_skips_migrations_at_or_below_the_stored_version(): void {
		$this->options[ MigrationRunner::VERSION_OPTION ] = '2';
		$log    = new MigrationLog();
		$runner = new MigrationRunner(
			array(
				new RecordingMigration( 1, $log ),
				new RecordingMigration( 2, $log ),
				new RecordingMigration( 3, $log ),
			)
		);

		$this->assertSame( 1, $runner->run() );
		$this->assertSame( array( 3 ), $log->versions );
		$this->assertSame( 3, $runner->current_version() );
	}

	public function test_an_empty_list_leaves_the_version_alone(): void {
		$runner = new MigrationRunner( array() );

		$this->assertSame( 0, $runner->run() );
		$this->assertArrayNotHasKey( MigrationRunner::VERSION_OPTION, $this->options );
	}

	public function test_does_nothing_while_another_request_holds_the_lock(): void {
		$this->options[ MigrationRunner::LOCK_OPTION ] = time();
		$log    = new MigrationLog();
		$runner = new MigrationRunner( array( new RecordingMigration( 1, $log ) ) );

		$this->assertSame( 0, $runner->run() );
		$this->assertSame( array(), $log->versions );
		$this->assertArrayHasKey( MigrationRunner::LOCK_OPTION, $this->options );
	}

	public function test_takes_over_a_lock_older_than_its_lifetime(): void {
		$this->options[ MigrationRunner::LOCK_OPTION ] = time() - MigrationRunner::LOCK_TTL_SECONDS - 1;
		$log    = new MigrationLog();
		$runner = new MigrationRunner( array( new RecordingMigration( 1, $log ) ) );

		$this->assertSame( 1, $runner->run() );
		$this->assertSame( array( 1 ), $log->versions );
		$this->assertArrayNotHasKey( MigrationRunner::LOCK_OPTION, $this->options );
	}

	public function test_stops_and_releases_the_lock_when_a_version_cannot_be_recorded(): void {
		$this->failing_options[] = MigrationRunner::VERSION_OPTION;
		$log                     = new MigrationLog();
		$runner                  = new MigrationRunner( array( new RecordingMigration( 1, $log ), new RecordingMigration( 2, $log ) ) );

		try {
			$runner->run();
			$this->fail( 'Expected the run to stop.' );
		} catch ( RuntimeException $e ) {
			$this->assertSame( array( 1 ), $log->versions );
			$this->assertArrayNotHasKey( MigrationRunner::LOCK_OPTION, $this->options );
		}
	}

	public function test_a_postponed_migration_stops_the_run_without_failing_it_and_holds_the_lock(): void {
		$log    = new MigrationLog();
		$logger = new RecordingLogger();
		$runner = new MigrationRunner(
			array(
				new RecordingMigration( 1, $log ),
				self::migration_throwing( 2, new MigrationPostponed( 'the keys stayed' ) ),
				new RecordingMigration( 3, $log ),
			),
			$logger
		);

		$this->assertSame( 1, $runner->run() );

		$this->assertSame( array( 1 ), $log->versions );
		$this->assertSame( 1, $runner->current_version() );
		$this->assertArrayHasKey( MigrationRunner::LOCK_OPTION, $this->options );
		$this->assertCount( 1, $logger->entries );
		$this->assertSame( 'error', $logger->entries[0]['level'] );
		$this->assertSame( 'Migration 2 was postponed: the keys stayed', $logger->entries[0]['message'] );
	}

	public function test_any_other_failure_still_reaches_the_caller_and_releases_the_lock(): void {
		$logger = new RecordingLogger();
		$runner = new MigrationRunner( array( self::migration_throwing( 1, new RuntimeException( 'no table' ) ) ), $logger );

		try {
			$runner->run();
			$this->fail( 'Expected the run to stop.' );
		} catch ( RuntimeException $e ) {
			$this->assertSame( 'no table', $e->getMessage() );
			$this->assertArrayNotHasKey( MigrationRunner::LOCK_OPTION, $this->options );
			$this->assertSame( array(), $logger->entries );
		}
	}

	public function test_refuses_two_migrations_with_the_same_version(): void {
		$log = new MigrationLog();

		$this->expectException( LogicException::class );

		new MigrationRunner( array( new RecordingMigration( 1, $log ), new RecordingMigration( 1, $log ) ) );
	}

	public function test_refuses_a_version_below_one(): void {
		$log = new MigrationLog();

		$this->expectException( LogicException::class );

		new MigrationRunner( array( new RecordingMigration( 0, $log ) ) );
	}

	private static function migration_throwing( int $version, RuntimeException $failure ): Migration {
		return new class( $version, $failure ) implements Migration {

			public function __construct(
				private readonly int $version,
				private readonly RuntimeException $failure,
			) {
			}

			public function version(): int {
				return $this->version;
			}

			public function up(): void {
				throw $this->failure;
			}
		};
	}
}
