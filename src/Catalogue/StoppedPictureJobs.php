<?php
/**
 * Records a picture's job Action Scheduler marked failed.
 *
 * @package Pixonaut\WooCommerce
 */

declare(strict_types=1);

namespace Pixonaut\WooCommerce\Catalogue;

use ActionScheduler;

/**
 * A drawing's job can stop before it is done: PHP's time limit or another
 * fatal error ends the request, an error is thrown, or the job runs so long
 * that Action Scheduler gives it up. Action Scheduler then marks it failed
 * and never runs it again, and the product's record does not say so. Each
 * such job of `ProductPictures::ACTION` is recorded on its product
 * (`ProductPictures::cut_off()`), which tries it again a bounded number of
 * times. A job given up while its drawing may still run is left to it.
 */
final class StoppedPictureJobs {

	/**
	 * Action Scheduler's actions telling a job failed, the job's id first:
	 * PHP stopped while it ran, it threw, or it ran past the failure period.
	 */
	private const FAILURE_ACTIONS = array( 'action_scheduler_unexpected_shutdown', 'action_scheduler_failed_execution', self::GIVEN_UP_ACTION );

	/**
	 * Action Scheduler's action telling it gave up a job that ran longer than
	 * its failure period, 300 seconds by default: shorter than a drawing may
	 * run, so the job may still be drawing.
	 */
	private const GIVEN_UP_ACTION = 'action_scheduler_failed_action';

	/**
	 * @param ProductPictures $pictures Records the stopped drawing.
	 */
	public function __construct( private readonly ProductPictures $pictures ) {
	}

	public function register(): void {
		foreach ( self::FAILURE_ACTIONS as $action ) {
			add_action( $action, array( $this, 'failed' ) );
		}
	}

	/**
	 * Records the drawing on its product when the job is one of a picture.
	 *
	 * @param mixed $action_id The job Action Scheduler marked failed.
	 */
	public function failed( mixed $action_id ): void {
		$action = ActionScheduler::store()->fetch_action( (string) $action_id );
		if ( ProductPictures::ACTION !== $action->get_hook() ) {
			return;
		}
		$args       = $action->get_args();
		$product_id = (int) ( $args[0] ?? 0 );
		// A drawing still within its time records itself, or PHP's stopping it does.
		if ( self::GIVEN_UP_ACTION === current_action() && $this->pictures->drawing_now( $product_id ) ) {
			return;
		}
		$product = PictureQueue::fresh( $product_id );
		if ( null !== $product ) {
			$this->pictures->cut_off( $product );
		}
	}
}
