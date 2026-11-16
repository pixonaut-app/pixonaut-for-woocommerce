<?php
/**
 * @package Pixonaut\WooCommerce
 */

declare(strict_types=1);

namespace Pixonaut\WooCommerce\Tests\Integration\Catalogue;

use ActionScheduler;
use Pixonaut\WooCommerce\Admin\RegeneratePictures;
use Pixonaut\WooCommerce\Catalogue\PictureQueue;
use Pixonaut\WooCommerce\Catalogue\ProductPictures;
use Pixonaut\WooCommerce\Migrations\DrawStoppedPictures;
use Pixonaut\WooCommerce\Migrations\Migrations;
use Pixonaut\WooCommerce\Tests\Support\ActionSchedulerRunner;
use Pixonaut\WooCommerce\Tests\Support\SlowImageEditor;
use WC_Product;
use WP_UnitTestCase;

/**
 * A picture's job can be cut off while WordPress makes the image sizes:
 * it runs with time enough for them, and one that stops anyway is tried
 * again, a bounded number of times, and by the next pictures pass.
 */
final class StoppedPictureJobsTest extends WP_UnitTestCase {

	use PicturedProducts;

	/**
	 * PHP's time limit in a web request on most hosts, in seconds.
	 */
	private const WEB_LIMIT = 30;

	/**
	 * What PHP's error handler records for a request stopped by its time limit.
	 */
	private const TIME_LIMIT_ERROR = array(
		'type'    => E_ERROR,
		'message' => 'Maximum execution time of 30 seconds exceeded',
		'file'    => 'wp-includes/class-wp-image-editor-imagick.php',
		'line'    => 1225,
	);

	public function set_up(): void {
		parent::set_up();
		$this->set_up_pictures();
		SlowImageEditor::use();
	}

	public function tear_down(): void {
		SlowImageEditor::stop();
		// The command line runs without a time limit.
		set_time_limit( 0 );
		$this->tear_down_pictures();
		delete_option( PictureQueue::BACKFILL_OPTION );
		parent::tear_down();
	}

	public function test_a_job_started_under_a_web_requests_limit_has_time_to_make_the_image_sizes(): void {
		$product = $this->product( self::config() );
		set_time_limit( self::WEB_LIMIT );

		$this->drawn( $product );

		$this->assertGreaterThan( 0, SlowImageEditor::$made );
		$this->assertSame( array(), ActionSchedulerRunner::failed( ProductPictures::ACTION ) );
		$this->assertGreaterThanOrEqual( ProductPictures::LOCK_SECONDS, (int) ini_get( 'max_execution_time' ) );
	}

	public function test_a_cut_off_job_is_tried_again_a_bounded_number_of_times(): void {
		$product                      = $this->product( self::config() );
		SlowImageEditor::$limit_fixed = true;
		$runs                         = 1 + ProductPictures::CUT_OFF_RETRIES;

		for ( $run = 1; $run <= $runs; ++$run ) {
			$this->recorder->raw( 200, self::png(), 'image/png' );
			$this->assertSame( 1, ActionSchedulerRunner::run_once( ProductPictures::ACTION ) );

			$this->assertSame( $this->own_image, (int) $this->reload( $product )->get_image_id() );
			$this->assertSame( $run, ProductPictures::stored( $this->reload( $product ) )['cut_offs'] );
			$retries = ActionSchedulerRunner::due_at( ProductPictures::RETRY_ACTION );
			if ( $run > ProductPictures::CUT_OFF_RETRIES ) {
				$this->assertSame( array(), $retries );
				break;
			}
			$this->assertCount( 1, $retries );
			$this->assertGreaterThan( time() + ProductPictures::RETRY_SECONDS - MINUTE_IN_SECONDS, $retries[0] );
			ActionSchedulerRunner::run_once( ProductPictures::RETRY_ACTION );
		}

		$this->assertCount( $runs, ActionSchedulerRunner::failed( ProductPictures::ACTION ) );
		$this->assertSame( array(), ActionSchedulerRunner::pending( ProductPictures::ACTION ) );
		$this->assertSame( RegeneratePictures::FAILED, RegeneratePictures::state( $this->reload( $product ) ) );
	}

	public function test_the_next_pictures_pass_queues_a_picture_whose_job_was_cut_off(): void {
		$product                      = $this->product( self::config() );
		SlowImageEditor::$limit_fixed = true;
		$this->recorder->raw( 200, self::png(), 'image/png' );
		ActionSchedulerRunner::run_once( ProductPictures::ACTION );
		as_unschedule_all_actions( ProductPictures::RETRY_ACTION );
		$this->fail_earlier( $product, ProductPictures::RETRY_SECONDS );

		$this->assertTrue( PictureQueue::queue_all() );
		ActionSchedulerRunner::run_pending( PictureQueue::QUEUE_ACTION );

		$this->assertCount( 1, ActionSchedulerRunner::pending( ProductPictures::ACTION ) );
		SlowImageEditor::$limit_fixed = false;
		$drawn                        = $this->drawn( $product )['product'];
		$this->assertSame( 0, ProductPictures::stored( $this->reload( $drawn ) )['cut_offs'] );
		$this->assertNull( ProductPictures::stored( $this->reload( $drawn ) )['failed_at'] );
	}

	public function test_a_variation_whose_job_php_stopped_is_queued_again(): void {
		$product   = $this->sign();
		$variation = $this->reload_id( $product->get_children()[0] );
		$job       = $this->stopped_job( $variation );

		do_action( 'action_scheduler_unexpected_shutdown', $job, self::TIME_LIMIT_ERROR );

		$this->assertSame( 1, ProductPictures::stored( $this->reload( $variation ) )['cut_offs'] );
		$this->assertCount( 1, ActionSchedulerRunner::pending( ProductPictures::RETRY_ACTION ) );
		ActionSchedulerRunner::run_once( ProductPictures::RETRY_ACTION );
		$this->assertContains( $variation->get_id(), $this->queued_ids() );
	}

	public function test_a_job_action_scheduler_gave_up_on_is_tried_again(): void {
		$product = $this->product( self::config() );
		$job     = $this->stopped_job( $product );

		do_action( 'action_scheduler_failed_action', $job, 300 );

		$this->assertSame( 1, ProductPictures::stored( $this->reload( $product ) )['cut_offs'] );
		$this->assertCount( 1, ActionSchedulerRunner::pending( ProductPictures::RETRY_ACTION ) );
	}

	public function test_a_job_given_up_while_its_drawing_may_still_run_is_left_to_it(): void {
		$product = $this->product( self::config() );
		$job     = $this->stopped_job( $product );
		add_option( ProductPictures::LOCK_OPTION_PREFIX . $product->get_id(), (string) time(), '', false );

		do_action( 'action_scheduler_failed_action', $job, 300 );

		$this->assertSame( 0, ProductPictures::stored( $this->reload( $product ) )['cut_offs'] );
		$this->assertSame( array(), ActionSchedulerRunner::pending( ProductPictures::RETRY_ACTION ) );
		delete_option( ProductPictures::LOCK_OPTION_PREFIX . $product->get_id() );
	}

	public function test_another_failed_job_records_nothing(): void {
		$product = $this->product( self::config() );
		$other   = as_enqueue_async_action( PictureQueue::VARIATIONS_ACTION, array( $product->get_id() ), ProductPictures::GROUP );
		ActionScheduler::store()->mark_failure( $other );

		do_action( 'action_scheduler_unexpected_shutdown', $other, self::TIME_LIMIT_ERROR );

		$this->assertSame( 0, ProductPictures::stored( $this->reload( $product ) )['cut_offs'] );
		$this->assertSame( array(), ActionSchedulerRunner::pending( ProductPictures::RETRY_ACTION ) );
	}

	public function test_the_upgrade_checks_every_picture_again(): void {
		$product = $this->product( self::config() );
		$this->stopped_job( $product );

		( new DrawStoppedPictures() )->up();
		$this->plugin->picture_queue()->queue_backfill();
		ActionSchedulerRunner::run_pending( PictureQueue::QUEUE_ACTION );

		$this->assertContains( DrawStoppedPictures::class, array_map( 'get_class', Migrations::all() ) );
		$this->assertFalse( get_option( PictureQueue::BACKFILL_OPTION ) );
		$this->assertContains( $product->get_id(), $this->queued_ids() );
	}

	/**
	 * The product's drawing job, as Action Scheduler leaves it once PHP
	 * stopped it: marked failed, nothing else queued for the product.
	 *
	 * @param WC_Product $product A product or variation.
	 *
	 * @return int The job.
	 */
	private function stopped_job( WC_Product $product ): int {
		as_unschedule_all_actions( ProductPictures::ACTION );
		$job = as_enqueue_async_action( ProductPictures::ACTION, array( $product->get_id() ), ProductPictures::GROUP );
		// It started: the queue forgets it was queued, as at the start of every drawing.
		$this->plugin->picture_queue()->running( $product->get_id() );
		ActionScheduler::store()->mark_failure( $job );
		return $job;
	}

	/**
	 * Moves the product's recorded failure back in time.
	 *
	 * @param WC_Product $product A product or variation whose drawing failed.
	 * @param int        $ago     How long ago it failed, in seconds.
	 */
	private function fail_earlier( WC_Product $product, int $ago ): void {
		$stored              = ProductPictures::stored( $this->reload( $product ) );
		$stored['failed_at'] = time() - $ago;
		update_post_meta( $product->get_id(), ProductPictures::META_KEY, wp_slash( (string) wp_json_encode( $stored ) ) );
	}

	/**
	 * The products and variations whose drawing waits to start.
	 *
	 * @return list<int>
	 */
	private function queued_ids(): array {
		$ids = array();
		foreach ( ActionSchedulerRunner::pending( ProductPictures::ACTION ) as $job ) {
			$ids[] = (int) ( ActionScheduler::store()->fetch_action( (string) $job )->get_args()[0] ?? 0 );
		}
		return $ids;
	}

	private function reload_id( int $id ): WC_Product {
		$product = wc_get_product( $id );
		$this->assertInstanceOf( WC_Product::class, $product );
		return $product;
	}
}
