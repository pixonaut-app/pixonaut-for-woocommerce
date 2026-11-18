<?php
/**
 * @package Pixonaut\WooCommerce
 */

declare(strict_types=1);

namespace Pixonaut\WooCommerce\Tests\Integration\Storefront;

use Pixonaut\WooCommerce\Api\Catalogue;
use Pixonaut\WooCommerce\Api\Deprecations;
use Pixonaut\WooCommerce\Api\HookListener;
use Pixonaut\WooCommerce\Api\Pixonaut;
use Pixonaut\WooCommerce\Api\WcLogger;
use Pixonaut\WooCommerce\Catalogue\CatalogueCache;
use Pixonaut\WooCommerce\Connection\ConnectionStore;
use Pixonaut\WooCommerce\Connection\WorkspaceSettings;
use Pixonaut\WooCommerce\Plugin;
use Pixonaut\WooCommerce\Schema;
use Pixonaut\WooCommerce\Storefront\ServerReads;
use Pixonaut\WooCommerce\Storefront\ServerReadsWarmUp;
use Pixonaut\WooCommerce\Tests\Support\HttpRecorder;
use Pixonaut\WooCommerce\Tests\Support\OpenApiAssert;
use WP_UnitTestCase;

/**
 * A product page's reads are read again shortly before their kept copies
 * run out, while pages still ask for them, so a visitor after a quiet
 * spell waits on none.
 */
final class ServerReadsWarmUpTest extends WP_UnitTestCase {

	/**
	 * Loopback, so the test bootstrap's host guard lets it through to the recorder.
	 */
	private const BASE_URL = 'http://127.0.0.1:9';

	private const JSON = 'application/json';

	private const FRONT = array(
		'templateId'  => 'mini-tee@2',
		'blankId'     => 'blank_tee',
		'placementId' => 'front',
	);

	/**
	 * The four reads of a side on a blank: its template, its placement and the blank's views.
	 */
	private const SIDE_READS = 4;

	private HttpRecorder $recorder;

	private ConnectionStore $store;

	private CatalogueCache $cache;

	private ServerReads $reads;

	private ServerReadsWarmUp $warm_up;

	/**
	 * The time the warm-up reads, moved by the tests.
	 *
	 * @var int
	 */
	private int $now;

	public function set_up(): void {
		parent::set_up();
		$this->recorder = ( new HttpRecorder( self::BASE_URL ) )->start();
		$this->store    = Plugin::boot( dirname( __DIR__, 4 ) . '/pixonaut-for-woocommerce.php' )->connection_store();
		StorefrontTest::connect( $this->store );
		$client        = ( new Pixonaut( $this->store, new WcLogger(), new HookListener( new Deprecations() ), null, self::BASE_URL ) )->sdk();
		$this->now     = time();
		$this->warm_up = new ServerReadsWarmUp( fn (): int => $this->now );
		$this->cache   = new CatalogueCache( $this->store, static fn (): bool => true );
		$this->reads   = new ServerReads( new Catalogue( $client ), $this->cache, $client, $this->warm_up );
		$this->cache->flush();
		delete_option( ServerReadsWarmUp::OPTION );
		as_unschedule_all_actions( ServerReadsWarmUp::HOOK, array(), Schema::ACTION_GROUP );
		delete_transient( ServerReads::PAUSE_TRANSIENT );
	}

	public function tear_down(): void {
		$this->recorder->stop();
		$this->store->clear();
		WorkspaceSettings::forget();
		delete_option( ServerReadsWarmUp::OPTION );
		as_unschedule_all_actions( ServerReadsWarmUp::HOOK, array(), Schema::ACTION_GROUP );
		delete_transient( ServerReads::PAUSE_TRANSIENT );
		parent::tear_down();
	}

	public function test_a_pages_reads_are_read_again_before_their_kept_copies_run_out(): void {
		$this->answer_side();
		$first = $this->reads->for_mounts( array( 'front' => self::FRONT ) );
		$due   = as_next_scheduled_action( ServerReadsWarmUp::HOOK, array(), Schema::ACTION_GROUP );
		$this->assertIsInt( $due );
		$this->assertGreaterThan( $this->now, $due );
		$this->assertLessThan( $this->now + CatalogueCache::KEPT_SECONDS, $due, 'before the copies run out' );
		$queued = as_get_scheduled_actions(
			array(
				'hook'   => ServerReadsWarmUp::HOOK,
				'status' => \ActionScheduler_Store::STATUS_PENDING,
			)
		);
		$this->assertSame( ServerReadsWarmUp::PRIORITY, reset( $queued )->get_priority(), 'ahead of a backlog of picture jobs' );
		$this->recorder->requests = array();

		$this->now = $due - 1;
		$this->warm();
		$this->assertSame( array(), $this->recorder->requests, 'nothing due yet' );

		$this->now = $due;
		$this->answer_side();
		$this->warm();

		$this->assertCount( self::SIDE_READS, $this->recorder->requests, 'each read again' );
		$next = as_next_scheduled_action( ServerReadsWarmUp::HOOK, array(), Schema::ACTION_GROUP );
		$this->assertIsInt( $next );
		$this->assertGreaterThan( $due, $next, 'and again before the new copies run out' );
		$this->recorder->requests = array();
		$this->assertSame( $first, $this->reads->for_mounts( array( 'front' => self::FRONT ) ) );
		$this->assertSame( array(), $this->recorder->requests, 'the next page waits on none' );
	}

	public function test_reads_no_page_asked_for_in_days_are_read_no_more(): void {
		$this->answer_side();
		$this->reads->for_mounts( array( 'front' => self::FRONT ) );
		$this->recorder->requests = array();
		$this->now               += 4 * DAY_IN_SECONDS;

		$this->warm();

		$this->assertSame( array(), $this->recorder->requests );
		$this->assertSame( array(), get_option( ServerReadsWarmUp::OPTION ) );
		$this->assertFalse( as_next_scheduled_action( ServerReadsWarmUp::HOOK, array(), Schema::ACTION_GROUP ), 'and the job stops' );
	}

	public function test_a_failed_read_waits_while_the_others_are_read_again(): void {
		$this->answer_side();
		$this->reads->for_mounts( array( 'front' => self::FRONT ) );
		$this->recorder->requests = array();
		$due                      = as_next_scheduled_action( ServerReadsWarmUp::HOOK, array(), Schema::ACTION_GROUP );
		$this->assertIsInt( $due );
		$this->now = $due;
		// The template's read fails; the placement's and the blank's views answer.
		$this->recorder->json( 404, array( 'error' => 'not_found' ) );
		$this->answer_side( false );

		$this->warm();

		$this->assertCount( self::SIDE_READS, $this->recorder->requests, 'the others are read all the same' );
		$again = as_next_scheduled_action( ServerReadsWarmUp::HOOK, array(), Schema::ACTION_GROUP );
		$this->assertIsInt( $again );
		$this->assertGreaterThan( $this->now + MINUTE_IN_SECONDS, $again, 'the failed read waits before it is tried again' );
		$this->assertLessThan( $this->now + HOUR_IN_SECONDS, $again );
	}

	public function test_a_read_pages_keep_asking_for_from_the_cache_stays_warm(): void {
		$this->answer_side();
		$this->reads->for_mounts( array( 'front' => self::FRONT ) );
		$this->now               += 2 * DAY_IN_SECONDS;
		$this->recorder->requests = array();

		$this->reads->for_mounts( array( 'front' => self::FRONT ) );
		$this->assertSame( array(), $this->recorder->requests, 'served from the cache' );
		$this->now += 2 * DAY_IN_SECONDS;
		$this->answer_side();
		$this->warm();

		$this->assertCount( self::SIDE_READS, $this->recorder->requests, 'still in use, so read again' );
	}

	public function test_a_read_the_notes_lost_is_noted_again_by_the_next_page_and_read_soon(): void {
		$this->answer_side();
		$this->reads->for_mounts( array( 'front' => self::FRONT ) );
		delete_option( ServerReadsWarmUp::OPTION );
		as_unschedule_all_actions( ServerReadsWarmUp::HOOK, array(), Schema::ACTION_GROUP );

		$this->reads->for_mounts( array( 'front' => self::FRONT ) );

		$due = as_next_scheduled_action( ServerReadsWarmUp::HOOK, array(), Schema::ACTION_GROUP );
		$this->assertIsInt( $due );
		$this->assertLessThanOrEqual( $this->now + MINUTE_IN_SECONDS, $due, 'its copy may be of any age' );
	}

	public function test_a_job_queued_at_the_queues_default_priority_is_queued_again_ahead_of_a_backlog(): void {
		as_schedule_single_action( $this->now - HOUR_IN_SECONDS, ServerReadsWarmUp::HOOK, array(), Schema::ACTION_GROUP );
		$this->answer_side();

		$this->reads->for_mounts( array( 'front' => self::FRONT ) );

		$queued = as_get_scheduled_actions(
			array(
				'hook'   => ServerReadsWarmUp::HOOK,
				'status' => \ActionScheduler_Store::STATUS_PENDING,
			)
		);
		$this->assertCount( 1, $queued );
		$action = reset( $queued );
		$this->assertSame( ServerReadsWarmUp::PRIORITY, $action->get_priority() );
		$this->assertSame( $this->now - HOUR_IN_SECONDS, as_next_scheduled_action( ServerReadsWarmUp::HOOK, array(), Schema::ACTION_GROUP ), 'when it was due' );
	}

	/**
	 * Runs the job as the plugin does, against this test's Pixonaut.
	 */
	private function warm(): void {
		as_unschedule_all_actions( ServerReadsWarmUp::HOOK, array(), Schema::ACTION_GROUP );
		$this->warm_up->warm( fn ( string $what, array $args ): bool => $this->reads->read_again( $what, $args ) );
	}

	/**
	 * Pixonaut's answers to a side's four reads, from the pinned spec's examples.
	 *
	 * @param bool $template Whether to answer the template's read too, the first.
	 */
	private function answer_side( bool $template = true ): void {
		$constraints                   = json_decode( self::example_json( '/v1/templates/{id}/constraints' ) );
		$constraints->placement->sizes = array(
			'fits'    => array(),
			'misfits' => array(),
		);
		if ( $template ) {
			$this->recorder->raw( 200, self::example_json( '/v1/templates/{id}' ), self::JSON );
		}
		$this->recorder
			->raw( 200, (string) wp_json_encode( $constraints ), self::JSON )
			->raw( 200, self::example_json( '/v1/blanks/{id}/mockups' ), self::JSON )
			->raw( 200, self::example_json( '/v1/blanks/{id}/variants' ), self::JSON );
	}

	/**
	 * The pinned spec's example answer of a read, as JSON text with its empty objects kept.
	 *
	 * @param string $path The read's path.
	 */
	private static function example_json( string $path ): string {
		return (string) wp_json_encode( json_decode( OpenApiAssert::example( 'GET', $path, 200 ) ) );
	}
}
