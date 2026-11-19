<?php
/**
 * @package Pixonaut\WooCommerce
 */

declare(strict_types=1);

namespace Pixonaut\WooCommerce\Tests\Integration\Migrations;

use ActionScheduler;
use Pixonaut\WooCommerce\Catalogue\PictureQueue;
use Pixonaut\WooCommerce\Catalogue\ProductPictures;
use Pixonaut\WooCommerce\Catalogue\ProductRecheck;
use Pixonaut\WooCommerce\Migrations\MoveCatalogueJobsBack;
use Pixonaut\WooCommerce\Schema;
use Pixonaut\WooCommerce\Webhooks\Processor;
use WP_UnitTestCase;

/**
 * Upgrading moves the catalogue's waiting jobs behind the store's other jobs.
 */
final class MoveCatalogueJobsBackTest extends WP_UnitTestCase {

	/**
	 * Action Scheduler's default priority.
	 */
	private const DEFAULT_PRIORITY = 10;

	/**
	 * A priority a job was queued at on purpose.
	 */
	private const OWN_PRIORITY = 5;

	public function test_waiting_picture_and_recheck_jobs_move_back_and_other_jobs_stay(): void {
		$picture   = as_enqueue_async_action( ProductPictures::ACTION, array( 1 ), ProductPictures::GROUP );
		$retry     = as_schedule_single_action( time() + HOUR_IN_SECONDS, ProductPictures::RETRY_ACTION, array( 1 ), ProductPictures::GROUP );
		$queue     = as_enqueue_async_action( PictureQueue::QUEUE_ACTION, array( 0 ), ProductPictures::GROUP );
		$recheck   = as_enqueue_async_action( ProductRecheck::ACTION, array( array( 1 ), 1 ), ProductRecheck::GROUP );
		$webhook   = as_enqueue_async_action( Processor::PROCESS_HOOK, array( 'evt_1' ), Processor::GROUP );
		$own       = as_enqueue_async_action( ProductPictures::ACTION, array( 2 ), ProductPictures::GROUP, false, self::OWN_PRIORITY );
		$completed = as_enqueue_async_action( ProductPictures::ACTION, array( 3 ), ProductPictures::GROUP );
		ActionScheduler::store()->mark_complete( $completed );

		( new MoveCatalogueJobsBack() )->up();

		foreach ( array( $picture, $retry, $queue, $recheck ) as $job ) {
			$this->assertSame( Schema::CATALOGUE_JOB_PRIORITY, self::priority( $job ) );
		}
		$this->assertSame( self::DEFAULT_PRIORITY, self::priority( $webhook ) );
		$this->assertSame( self::OWN_PRIORITY, self::priority( $own ) );
		$this->assertSame( self::DEFAULT_PRIORITY, self::priority( $completed ) );
	}

	private static function priority( int $job ): int {
		return ActionScheduler::store()->fetch_action( (string) $job )->get_priority();
	}
}
