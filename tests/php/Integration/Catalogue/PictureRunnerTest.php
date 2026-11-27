<?php
/**
 * @package Pixonaut\WooCommerce
 */

declare(strict_types=1);

namespace Pixonaut\WooCommerce\Tests\Integration\Catalogue;

use Pixonaut\WooCommerce\Catalogue\PictureRunner;
use Pixonaut\WooCommerce\Catalogue\PictureQueue;
use Pixonaut\WooCommerce\Catalogue\ProductPictures;
use Pixonaut\WooCommerce\Plugin;
use Pixonaut\WooCommerce\Settings\Settings;
use Pixonaut\WooCommerce\Tests\Support\ActionSchedulerRunner;
use WP_UnitTestCase;

/**
 * At most as many runners as the store's setting run in the store at once.
 */
final class PictureRunnerTest extends WP_UnitTestCase {

	/**
	 * A product id no product has: its job runs and draws nothing.
	 */
	private const NO_PRODUCT = 0;

	/**
	 * A memory limit the tests' process stays well below.
	 */
	private const MEMORY_LIMIT = '1G';

	/**
	 * @var list<bool> The `blocking` argument of each runner sent.
	 */
	private array $sent = array();

	public function set_up(): void {
		parent::set_up();
		$this->sent = array();
		add_filter( 'pre_http_request', array( $this, 'catch_runner' ), 10, 3 );
		as_enqueue_async_action( ProductPictures::ACTION, array( self::NO_PRODUCT ), ProductPictures::GROUP );
	}

	public function tear_down(): void {
		remove_filter( 'pre_http_request', array( $this, 'catch_runner' ) );
		parent::tear_down();
	}

	public function test_start_sends_a_runner_for_each_free_slot(): void {
		self::hold( 1, time() );

		( new PictureRunner() )->start();

		$this->assertCount( Settings::DEFAULT_PICTURE_RUNNERS - 1, $this->sent );
	}

	public function test_nothing_runs_while_every_slot_is_held(): void {
		self::hold( Settings::DEFAULT_PICTURE_RUNNERS, time() );

		( new PictureRunner() )->start();

		$this->assertSame( array(), $this->sent );
		$this->assertSame( 0, ( new PictureRunner() )->run() );
		$this->assertCount( 1, ActionSchedulerRunner::pending( ProductPictures::ACTION ) );
	}

	public function test_a_slot_held_longer_than_a_runner_runs_is_taken_over_and_given_up(): void {
		self::hold( Settings::DEFAULT_PICTURE_RUNNERS, time() - PictureRunner::STALE_SECONDS );

		$this->assertSame( 1, ( new PictureRunner() )->run() );

		wp_cache_delete( PictureRunner::SLOT_OPTION_PREFIX . 0, 'options' );
		$this->assertFalse( get_option( PictureRunner::SLOT_OPTION_PREFIX . 0 ) );
		$this->assertSame( array(), ActionSchedulerRunner::pending( ProductPictures::ACTION ) );
	}

	public function test_a_runner_gives_its_slot_up_when_it_is_done(): void {
		$this->assertSame( 1, ( new PictureRunner() )->run() );

		( new PictureRunner() )->start();

		$this->assertCount( Settings::DEFAULT_PICTURE_RUNNERS, $this->sent );
	}

	public function test_the_store_sets_how_many_run_at_once(): void {
		$settings = Plugin::boot( dirname( __DIR__, 4 ) . '/pixonaut-for-woocommerce.php' )->settings();
		$settings->set( Settings::PICTURE_RUNNERS, Settings::MAX_PICTURE_RUNNERS );
		try {
			( new PictureRunner( null, $settings ) )->start();
		} finally {
			$settings->set( Settings::PICTURE_RUNNERS, Settings::DEFAULT_PICTURE_RUNNERS );
		}

		$this->assertCount( Settings::MAX_PICTURE_RUNNERS, $this->sent );
	}

	public function test_a_request_that_queued_a_drawing_starts_the_runners_as_it_ends(): void {
		// As a request starts: nothing queued in it yet.
		( new \ReflectionProperty( PictureQueue::class, 'queued_now' ) )->setValue( null, false );
		( new PictureRunner() )->started();
		$this->assertSame( array(), $this->sent, 'nothing queued yet' );

		PictureQueue::schedule( self::NO_PRODUCT + 1 );
		( new PictureRunner() )->started();

		$this->assertCount( Settings::DEFAULT_PICTURE_RUNNERS, $this->sent );
	}

	public function test_a_runner_stopped_with_jobs_still_due_sends_another_in_its_place(): void {
		as_enqueue_async_action( ProductPictures::ACTION, array( self::NO_PRODUCT + 1 ), ProductPictures::GROUP );
		$checks = 0;
		// Short of memory once it ran a job, as a runner past its time is too.
		$memory = static function () use ( &$checks ): int {
			return 0 === $checks++ ? 0 : PHP_INT_MAX;
		};
		$limit  = (string) ini_get( 'memory_limit' );
		ini_set( 'memory_limit', self::MEMORY_LIMIT ); // phpcs:ignore WordPress.PHP.IniSet.memory_limit_Disallowed -- a limit the runner measures against.
		try {
			$ran = ( new PictureRunner( $memory ) )->run();
		} finally {
			ini_set( 'memory_limit', $limit ); // phpcs:ignore WordPress.PHP.IniSet.memory_limit_Disallowed -- the limit the tests run under.
		}

		$this->assertSame( 1, $ran );
		$this->assertCount( 1, ActionSchedulerRunner::pending( ProductPictures::ACTION ), 'one still due' );
		$this->assertCount( Settings::DEFAULT_PICTURE_RUNNERS, $this->sent, 'sent on' );
	}

	public function test_a_runner_that_ran_out_of_jobs_sends_none(): void {
		$this->assertSame( 1, ( new PictureRunner() )->run() );

		$this->assertSame( array(), $this->sent );
	}

	public function test_action_scheduler_runs_its_own_batch_beside_every_runner(): void {
		$plugin = Plugin::boot( dirname( __DIR__, 4 ) . '/pixonaut-for-woocommerce.php' )->picture_runner();
		$this->assertInstanceOf( PictureRunner::class, $plugin );
		$this->assertNotFalse( has_filter( 'action_scheduler_queue_runner_concurrent_batches', array( $plugin, 'concurrent_batches' ) ) );

		// Action Scheduler's one batch, and one per runner.
		$this->assertSame( 1 + Settings::DEFAULT_PICTURE_RUNNERS, ( new PictureRunner() )->concurrent_batches( 1 ) );
	}

	public function test_a_runner_short_of_memory_claims_no_job(): void {
		$limit = (string) ini_get( 'memory_limit' );
		ini_set( 'memory_limit', self::MEMORY_LIMIT ); // phpcs:ignore WordPress.PHP.IniSet.memory_limit_Disallowed -- a limit the runner measures against.
		try {
			$ran = ( new PictureRunner( static fn (): int => PHP_INT_MAX ) )->run();
		} finally {
			ini_set( 'memory_limit', $limit ); // phpcs:ignore WordPress.PHP.IniSet.memory_limit_Disallowed -- the limit the tests run under.
		}

		$this->assertSame( 0, $ran );
		$this->assertCount( 1, ActionSchedulerRunner::pending( ProductPictures::ACTION ) );
	}

	/**
	 * Answers a runner's request instead of sending it.
	 *
	 * @param mixed                $preempt What an earlier filter answered.
	 * @param array<string, mixed> $args    The request's arguments.
	 * @param string               $url     The request's URL.
	 *
	 * @return mixed
	 */
	public function catch_runner( mixed $preempt, array $args, string $url ): mixed {
		if ( ! str_contains( $url, 'action=pixonaut_wc_pictures' ) ) {
			return $preempt;
		}
		$this->sent[] = (bool) $args['blocking'];
		return array(
			'headers'  => array(),
			'body'     => '',
			'response' => array(
				'code'    => 200,
				'message' => 'OK',
			),
			'cookies'  => array(),
		);
	}

	/**
	 * Holds the first slots, as runners started at `$since` do.
	 *
	 * @param int $slots How many.
	 * @param int $since When their runners started, as Unix time.
	 */
	private static function hold( int $slots, int $since ): void {
		for ( $slot = 0; $slot < $slots; ++$slot ) {
			add_option( PictureRunner::SLOT_OPTION_PREFIX . $slot, (string) $since, '', false );
		}
	}
}
