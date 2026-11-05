<?php
/**
 * Schedules a proof's reminders and deadline, and sends the reminders.
 *
 * @package Pixonaut\WooCommerce
 */

declare(strict_types=1);

namespace Pixonaut\WooCommerce\Approval;

use Pixonaut\WooCommerce\Api\Logger;
use Pixonaut\WooCommerce\ApprovalMode;
use Pixonaut\WooCommerce\Fulfilment\FulfilmentJobs;
use Pixonaut\WooCommerce\Fulfilment\LineState;
use Pixonaut\WooCommerce\Fulfilment\NeedsAttention;
use Pixonaut\WooCommerce\Settings\Settings;
use WC_Order;
use WC_Order_Factory;
use WC_Order_Item_Product;

/**
 * When a proof-by-email line starts waiting for the customer, its deadline
 * and reminder times are worked out from the settings of that moment,
 * stored on the line, and scheduled as Action Scheduler jobs: one
 * `ProofDeadline` job, and one reminder job per reminder. Once the line
 * stops waiting, approved, cancelled or anything else, its jobs still
 * pending are unscheduled; a job that runs anyway finds the line no longer
 * waiting and does nothing.
 *
 * A reminder fires `pixonaut_wc_approval_proof_reminder`, which sends the
 * customer the reminder email; the last one says when the deadline is and
 * what happens then.
 */
final class ProofTimers {

	/**
	 * Action Scheduler hook of a reminder, with the order item id and the
	 * reminder's number, counted from 1, as its arguments.
	 */
	public const REMINDER_ACTION = 'pixonaut_proof_reminder';

	/**
	 * Tries to store the times when another request wrote the line meanwhile.
	 */
	private const MAX_ATTEMPTS = 3;

	/**
	 * @param Settings $settings Holds the deadline and reminder settings.
	 * @param Logger   $logger   Logs jobs Action Scheduler would not store.
	 */
	public function __construct(
		private readonly Settings $settings,
		private readonly Logger $logger,
	) {
	}

	public function register(): void {
		add_action( 'pixonaut_wc_fulfilment_awaiting_customer', array( $this, 'start' ) );
		add_action( 'pixonaut_wc_fulfilment_transition', array( $this, 'stop' ), 10, 3 );
		add_action( self::REMINDER_ACTION, array( $this, 'remind' ), 10, 2 );
	}

	/**
	 * The schedule the settings give now.
	 */
	public function schedule(): ProofSchedule {
		return new ProofSchedule(
			(int) $this->settings->get( Settings::PROOF_DEADLINE_DAYS ),
			(int) $this->settings->get( Settings::PROOF_REMINDER_AFTER_DAYS ),
			(int) $this->settings->get( Settings::PROOF_REMINDER_EVERY_DAYS ),
			(int) $this->settings->get( Settings::PROOF_REMINDERS )
		);
	}

	/**
	 * A line started waiting for the customer: stores its deadline and
	 * reminder times, and schedules their jobs.
	 *
	 * @param mixed $item The order line.
	 */
	public function start( mixed $item ): void {
		if ( ! $item instanceof WC_Order_Item_Product ) {
			return;
		}
		$schedule = $this->schedule();
		for ( $attempt = 0; $attempt < self::MAX_ATTEMPTS; $attempt++ ) {
			$line = LineState::of( $item );
			if ( LineState::AWAITING_CUSTOMER !== $line->state() || ApprovalMode::PROOF_EMAIL !== $line->ref( LineState::REF_APPROVAL_MODE ) ) {
				return;
			}
			$start     = $line->changed_at();
			$deadline  = $schedule->deadline( $start );
			$reminders = $schedule->reminders( $start );
			$line->set_ref(
				LineState::REF_DEADLINE,
				array(
					'at'        => $deadline,
					'reminders' => $reminders,
					'failures'  => 0,
				)
			);
			if ( $line->save() ) {
				$this->unschedule( $item->get_id() );
				if ( ! $this->at( $deadline, ProofDeadline::ACTION, array( 'item_id' => $item->get_id() ) ) ) {
					$this->unscheduled( $item );
					return;
				}
				foreach ( $reminders as $index => $at ) {
					$this->at(
						$at,
						self::REMINDER_ACTION,
						array(
							'item_id'  => $item->get_id(),
							'reminder' => $index + 1,
						)
					);
				}
				return;
			}
		}
		$this->logger->error( 'Proof deadline not stored: the line kept changing.', array( 'item' => $item->get_id() ) );
	}

	/**
	 * A line stopped waiting for the customer: its jobs still pending go.
	 *
	 * @param mixed $to   The new state.
	 * @param mixed $from The previous state.
	 * @param mixed $item The order line.
	 */
	public function stop( mixed $to, mixed $from, mixed $item ): void {
		if ( LineState::AWAITING_CUSTOMER === $from && LineState::AWAITING_CUSTOMER !== $to && $item instanceof WC_Order_Item_Product ) {
			$this->unschedule( $item->get_id() );
		}
	}

	/**
	 * The reminder job: tells the customer again, while the line still waits.
	 *
	 * @param int|string $item_id The order item.
	 * @param int|string $number  The reminder's number, from 1.
	 */
	public function remind( int|string $item_id, int|string $number ): void {
		$item = WC_Order_Factory::get_order_item( (int) $item_id );
		if ( ! $item instanceof WC_Order_Item_Product || ! LineState::is_tracked( $item ) ) {
			return;
		}
		$line     = LineState::of( $item );
		$deadline = $line->ref( LineState::REF_DEADLINE );
		if ( LineState::AWAITING_CUSTOMER !== $line->state() || null !== $line->ref( LineState::REF_CANCEL ) || null !== $line->ref( LineState::REF_DENIAL ) || ! is_array( $deadline ) || ! is_int( $deadline['at'] ?? null ) ) {
			return;
		}
		if ( time() >= $deadline['at'] ) {
			// Run late, after the deadline it would announce: the deadline's job decides now.
			return;
		}
		$count = is_array( $deadline['reminders'] ?? null ) ? count( $deadline['reminders'] ) : 0;
		$last  = (int) $number >= $count;

		/**
		 * Fires when a proof-by-email line still waiting for the customer is
		 * due a reminder; the plugin sends the reminder email on it.
		 *
		 * @since 0.1.0
		 *
		 * @param WC_Order_Item_Product $item     The order line.
		 * @param int                   $number   The reminder's number, from 1.
		 * @param bool                  $last     Whether it is the last reminder before the deadline.
		 * @param int                   $deadline Unix time of the deadline.
		 * @param string                $outcome  What happens at the deadline: `approve`, or the "On deny" action (`refund_cancel`, `cancel` or `needs_attention`).
		 *
		 * @example
		 * add_action( 'pixonaut_wc_approval_proof_reminder', function ( $item, $number, $last ) {
		 *     if ( $last ) {
		 *         // Text the customer too.
		 *     }
		 * }, 10, 3 );
		 */
		do_action( 'pixonaut_wc_approval_proof_reminder', $item, (int) $number, $last, $deadline['at'], $this->outcome() );
	}

	/**
	 * What the settings say happens at the deadline: `approve`, or the "On deny" action.
	 */
	private function outcome(): string {
		return ProofOutcome::APPROVE === $this->settings->get( Settings::PROOF_NO_ANSWER )
			? ProofOutcome::APPROVE
			: (string) $this->settings->get( Settings::PROOF_ON_DENY );
	}

	/**
	 * Schedules one job.
	 *
	 * @param int                  $at   Unix time.
	 * @param string               $hook The job's hook.
	 * @param array<string, mixed> $args Its arguments.
	 * @return bool False when Action Scheduler would not store it.
	 */
	private function at( int $at, string $hook, array $args ): bool {
		if ( 0 !== as_schedule_single_action( $at, $hook, $args, FulfilmentJobs::GROUP ) ) {
			return true;
		}
		$this->logger->error(
			'Proof job could not be scheduled.',
			array(
				'item' => $args['item_id'],
				'hook' => $hook,
			)
		);
		return false;
	}

	/**
	 * A line whose deadline job could not be scheduled would wait for ever:
	 * it needs the merchant's attention instead.
	 *
	 * @param WC_Order_Item_Product $item The line.
	 */
	private function unscheduled( WC_Order_Item_Product $item ): void {
		$order = wc_get_order( $item->get_order_id() );
		if ( ! $order instanceof WC_Order ) {
			return;
		}
		NeedsAttention::flag(
			LineState::of( $item ),
			$order,
			NeedsAttention::RETRY_NOT_SCHEDULED,
			/* translators: %s: product name of the order line. */
			sprintf( __( 'Pixonaut: the proof deadline of "%s" could not be scheduled, so the store will not decide it on its own. The proof stays open for the customer; approve or cancel the item when they do not answer.', 'pixonaut-for-woocommerce' ), $item->get_name() )
		);
	}

	/**
	 * Unschedules the line's pending deadline and reminder jobs.
	 *
	 * @param int $item_id The order item.
	 */
	private function unschedule( int $item_id ): void {
		as_unschedule_all_actions( ProofDeadline::ACTION, array( 'item_id' => $item_id ), FulfilmentJobs::GROUP );
		for ( $number = 1; $number <= ProofOutcome::MAX_REMINDERS; $number++ ) {
			as_unschedule_all_actions(
				self::REMINDER_ACTION,
				array(
					'item_id'  => $item_id,
					'reminder' => $number,
				),
				FulfilmentJobs::GROUP
			);
		}
	}
}
