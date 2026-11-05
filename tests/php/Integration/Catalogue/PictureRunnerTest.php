<?php
/**
 * @package Pixonaut\WooCommerce
 */

declare(strict_types=1);

namespace Pixonaut\WooCommerce\Tests\Integration\Catalogue;

use Pixonaut\WooCommerce\Catalogue\PictureRunner;
use Pixonaut\WooCommerce\Catalogue\ProductPictures;
use Pixonaut\WooCommerce\Tests\Support\ActionSchedulerRunner;
use WP_UnitTestCase;

/**
 * At most `PictureRunner::RUNNERS` runners run in the store at once.
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

		$this->assertCount( PictureRunner::RUNNERS - 1, $this->sent );
	}

	public function test_nothing_runs_while_every_slot_is_held(): void {
		self::hold( PictureRunner::RUNNERS, time() );

		( new PictureRunner() )->start();

		$this->assertSame( array(), $this->sent );
		$this->assertSame( 0, ( new PictureRunner() )->run() );
		$this->assertCount( 1, ActionSchedulerRunner::pending( ProductPictures::ACTION ) );
	}

	public function test_a_slot_held_longer_than_a_runner_runs_is_taken_over_and_given_up(): void {
		self::hold( PictureRunner::RUNNERS, time() - PictureRunner::STALE_SECONDS );

		$this->assertSame( 1, ( new PictureRunner() )->run() );

		wp_cache_delete( PictureRunner::SLOT_OPTION_PREFIX . 0, 'options' );
		$this->assertFalse( get_option( PictureRunner::SLOT_OPTION_PREFIX . 0 ) );
		$this->assertSame( array(), ActionSchedulerRunner::pending( ProductPictures::ACTION ) );
	}

	public function test_a_runner_gives_its_slot_up_when_it_is_done(): void {
		$this->assertSame( 1, ( new PictureRunner() )->run() );

		( new PictureRunner() )->start();

		$this->assertCount( PictureRunner::RUNNERS, $this->sent );
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
