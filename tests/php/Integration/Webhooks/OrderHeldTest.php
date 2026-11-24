<?php
/**
 * @package Pixonaut\WooCommerce
 */

declare(strict_types=1);

namespace Pixonaut\WooCommerce\Tests\Integration\Webhooks;

use Pixonaut\WooCommerce\Api\Hosts;
use Pixonaut\WooCommerce\Fulfilment\LineReader;
use Pixonaut\WooCommerce\Fulfilment\LineState;
use Pixonaut\WooCommerce\Fulfilment\OrderStatus;
use Pixonaut\WooCommerce\Tests\Support\ActionSchedulerRunner;
use Pixonaut\WooCommerce\Tests\Support\HttpRecorder;
use Pixonaut\WooCommerce\Tests\Support\SentMail;

/**
 * `order.held` and `order.released`: a free workspace's order held past its
 * monthly limit, then released by an upgrade or at the month's reset. The
 * release carries the whole order's status, so each line's item is read
 * afresh from Pixonaut, answered from a script. Each event applies once,
 * whichever order they arrive in.
 */
final class OrderHeldTest extends OrderEventsTestCase {

	private const PERIOD_END = '2026-11-01T00:00:00.000Z';

	/**
	 * The free plan's orders a month, past which an order is held.
	 */
	private const MONTHLY_LIMIT = 25;

	/**
	 * The order's status once released to its printshop.
	 */
	private const OPEN = 'open';

	/**
	 * Answers the plugin's own client, where a released line's item is read.
	 *
	 * @var HttpRecorder
	 */
	private HttpRecorder $recorder;

	public function set_up(): void {
		parent::set_up();
		$this->recorder = ( new HttpRecorder( Hosts::server_api() ) )->start();
	}

	public function tear_down(): void {
		$this->recorder->stop();
		parent::tear_down();
	}

	public function test_a_held_order_shows_the_line_as_held_with_one_note(): void {
		$item = $this->pixonaut_line();
		// Saving the order may have sent WooCommerce's new order emails; the hold sends none.
		reset_phpmailer_instance();

		$this->deliver( $this->held_event() );
		$this->run_jobs();

		$line = LineState::of( $item );
		$this->assertSame( LineState::ORDERED, $line->state() );
		$this->assertSame( OrderStatus::HELD, $line->ref( LineState::REF_ORDER_STATUS ) );
		$this->assertSame( 1, substr_count( $this->notes( $item ), 'is held' ) );
		$this->assertSame( array(), SentMail::all() );
	}

	public function test_order_held_for_a_line_shown_as_held_changes_nothing(): void {
		$item = $this->pixonaut_line();
		$this->deliver( $this->held_event() );
		$this->run_jobs();
		$before = LineState::of( $item )->changed_at();

		$this->deliver( $this->held_event() );
		$this->run_jobs();

		$this->assertSame( 1, substr_count( $this->notes( $item ), 'is held' ) );
		$this->assertSame( $before, LineState::of( $item )->changed_at() );
	}

	/**
	 * @return iterable<string, array{string, string}>
	 */
	public static function releases(): iterable {
		yield 'by an upgrade' => array( 'upgrade', 'upgraded' );
		yield 'at the month reset' => array( 'month_reset', 'month' );
	}

	/**
	 * @dataProvider releases
	 *
	 * @param string $released_by `releasedBy`.
	 * @param string $named       What the note says about it.
	 */
	public function test_a_released_order_moves_the_line_on_with_a_note_naming_why( string $released_by, string $named ): void {
		$item = $this->pixonaut_line();
		$this->deliver( $this->held_event() );
		$this->run_jobs();

		$this->deliver( $this->released_event( $released_by ) );
		$this->run_jobs();
		$this->read_lines( array( OrderStatus::HELD, OrderStatus::SUBMITTED ) );

		$line = LineState::of( $item );
		$this->assertSame( OrderStatus::SUBMITTED, $line->ref( LineState::REF_ORDER_STATUS ) );
		$this->assertSame( $released_by, $line->ref( LineState::REF_RELEASED_BY ) );
		$this->assertNull( $line->ref( LineState::REF_HOLD ) );
		$released = array_values( array_filter( explode( "\n", $this->notes( $item ) ), static fn ( string $note ): bool => str_contains( $note, 'released' ) ) );
		$this->assertCount( 1, $released );
		$this->assertStringContainsString( $named, $released[0] );

		// Later moves apply from the status the read found.
		$this->deliver( $this->status_event( OrderStatus::SUBMITTED, OrderStatus::QUEUED ) );
		$this->deliver( $this->status_event( OrderStatus::QUEUED, OrderStatus::PRINTED ) );
		$this->run_jobs();
		$this->assertSame( OrderStatus::PRINTED, LineState::of( $item )->ref( LineState::REF_ORDER_STATUS ) );
	}

	public function test_a_release_to_awaiting_prints_waits_for_its_prints(): void {
		$item = $this->pixonaut_line();
		$this->deliver( $this->held_event() );
		$this->deliver( $this->released_event( 'upgrade' ) );
		$this->run_jobs();
		$this->read_lines( array( OrderStatus::HELD, OrderStatus::AWAITING_PRINTS ) );

		$this->assertSame( OrderStatus::AWAITING_PRINTS, LineState::of( $item )->ref( LineState::REF_ORDER_STATUS ) );
		$this->assertSame( LineState::ORDERED, LineState::of( $item )->state() );
	}

	public function test_a_release_delivered_twice_is_noted_once(): void {
		$item = $this->pixonaut_line();
		$this->deliver( $this->held_event() );
		$released = $this->released_event( 'upgrade' );
		$this->deliver( $released );
		$this->run_jobs();
		$this->read_lines( array( OrderStatus::HELD, OrderStatus::SUBMITTED ) );

		$this->deliver( $released );
		$this->deliver( $this->released_event( 'upgrade' ) );
		$this->run_jobs();
		$this->read_lines( array( OrderStatus::HELD, OrderStatus::SUBMITTED ) );

		$this->assertSame( 1, substr_count( $this->notes( $item ), 'released' ) );
		$this->assertSame( OrderStatus::SUBMITTED, LineState::of( $item )->ref( LineState::REF_ORDER_STATUS ) );
	}

	public function test_a_released_item_that_fails_needs_attention_with_one_release_note(): void {
		$item = $this->pixonaut_line();
		$this->deliver( $this->held_event() );
		$this->run_jobs();

		$released = $this->released_event( 'upgrade' );
		$this->deliver( $released );
		$this->run_jobs();
		$this->read_lines( array( OrderStatus::HELD, OrderStatus::SUBMITTED ) );
		$this->deliver( $this->failed_event( 'the printshop refused the order' ) );
		$this->deliver( $released );
		$this->run_jobs();

		$line = LineState::of( $item );
		$this->assertSame( LineState::NEEDS_ATTENTION, $line->state() );
		$this->assertSame( OrderStatus::FAILED, $line->ref( LineState::REF_ORDER_STATUS ) );
		$this->assertNull( $line->ref( LineState::REF_HOLD ) );
		$notes = $this->notes( $item );
		$this->assertSame( 1, substr_count( $notes, 'released' ) );
		$this->assertSame( 1, substr_count( $notes, 'failed:' ) );
	}

	public function test_a_release_to_cancelled_cancels_the_line_with_one_release_note(): void {
		$item = $this->pixonaut_line();
		$this->deliver( $this->held_event() );
		$this->run_jobs();

		$released = $this->released_event( 'month_reset', OrderStatus::CANCELLED );
		$this->deliver( $released );
		$this->run_jobs();
		$this->deliver( $released );
		$this->deliver( $this->released_event( 'month_reset', OrderStatus::CANCELLED ) );
		$this->run_jobs();

		$line = LineState::of( $item );
		$this->assertSame( LineState::CANCELLED, $line->state() );
		$this->assertSame( OrderStatus::CANCELLED, $line->ref( LineState::REF_ORDER_STATUS ) );
		$notes = $this->notes( $item );
		$this->assertSame( 1, substr_count( $notes, 'released' ) );
		$this->assertSame( 1, substr_count( $notes, 'was cancelled' ) );
		$this->assertStringNotContainsString( 'goes on to the printshop', $notes );
	}

	public function test_a_release_before_the_hold_leaves_the_line_released(): void {
		$item = $this->pixonaut_line();

		$this->deliver( $this->released_event( 'month_reset' ) );
		$this->run_jobs();
		$this->read_lines( array( OrderStatus::HELD, OrderStatus::SUBMITTED ) );
		$this->deliver( $this->held_event() );
		$this->run_jobs();

		$line = LineState::of( $item );
		$this->assertSame( LineState::ORDERED, $line->state() );
		$this->assertSame( OrderStatus::SUBMITTED, $line->ref( LineState::REF_ORDER_STATUS ) );
		$this->assertNull( $line->ref( LineState::REF_HOLD ) );
		$this->assertStringNotContainsString( 'is held', $this->notes( $item ) );
		$this->assertSame( 1, substr_count( $this->notes( $item ), 'released' ) );
	}

	public function test_a_release_after_a_later_move_does_not_move_the_line_back(): void {
		$item = $this->pixonaut_line();
		$this->deliver( $this->held_event() );
		$this->run_jobs();

		$this->deliver( $this->status_event( OrderStatus::SUBMITTED, OrderStatus::QUEUED ) );
		$this->run_jobs();
		$this->deliver( $this->released_event( 'upgrade' ) );
		$this->run_jobs();
		$this->read_lines( array( OrderStatus::HELD, OrderStatus::SUBMITTED, OrderStatus::QUEUED ) );

		$this->assertSame( OrderStatus::QUEUED, LineState::of( $item )->ref( LineState::REF_ORDER_STATUS ) );
		$this->assertSame( 1, substr_count( $this->notes( $item ), 'released' ) );
	}

	public function test_a_line_of_another_workspace_is_not_touched(): void {
		$item = $this->pixonaut_line( array( 'tenant' => 'another-tenant' ) );

		$this->deliver( $this->held_event() );
		$this->deliver( $this->released_event( 'upgrade' ) );
		$this->run_jobs();

		$this->assertSame( array(), ActionSchedulerRunner::pending( LineReader::ACTION ), 'nothing to read' );
		$this->assertSame( LineState::APPROVED, LineState::of( $item )->state() );
		$this->assertNull( LineState::of( $item )->ref( LineState::REF_ORDER_STATUS ) );
	}

	/**
	 * An `order.held` envelope for ORDER_ID.
	 *
	 * @return array{id: string, type: string, payload: array<string, mixed>, createdAt: int}
	 */
	private function held_event(): array {
		return $this->order_event(
			'order.held',
			array(
				'reason'    => 'monthly_order_limit',
				'limit'     => self::MONTHLY_LIMIT,
				'periodEnd' => self::PERIOD_END,
			)
		);
	}

	/**
	 * An `order.released` envelope for ORDER_ID.
	 *
	 * @param string $released_by `upgrade` or `month_reset`.
	 * @param string $status      The status the whole order moved to.
	 * @return array{id: string, type: string, payload: array<string, mixed>, createdAt: int}
	 */
	private function released_event( string $released_by, string $status = self::OPEN ): array {
		return $this->order_event(
			'order.released',
			array(
				'releasedBy' => $released_by,
				'status'     => $status,
			)
		);
	}

	/**
	 * Runs the read the release queued of the line, Pixonaut answering
	 * its item at the last of $statuses.
	 *
	 * @param string[] $statuses The item's statuses so far, oldest first.
	 * @phpstan-param non-empty-list<string> $statuses
	 */
	private function read_lines( array $statuses ): void {
		$this->recorder->json( 200, self::order_read( $statuses ) );
		$this->assertSame( 1, ActionSchedulerRunner::run_pending( LineReader::ACTION ) );
	}
}
