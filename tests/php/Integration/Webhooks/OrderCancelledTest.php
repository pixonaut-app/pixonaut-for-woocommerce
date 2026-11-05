<?php
/**
 * @package Pixonaut\WooCommerce
 */

declare(strict_types=1);

namespace Pixonaut\WooCommerce\Tests\Integration\Webhooks;

use Pixonaut\WooCommerce\Fulfilment\LineState;

/**
 * `order.cancelled` cancels the order's line, however the cancel was asked
 * for, and only once.
 */
final class OrderCancelledTest extends OrderEventsTestCase {

	public function test_an_order_cancelled_in_pixonaut_cancels_the_line_with_a_note(): void {
		$item = $this->pixonaut_line();

		$this->deliver( $this->cancelled_event() );
		$this->run_jobs();

		$line = LineState::of( $item );
		$this->assertSame( LineState::CANCELLED, $line->state() );
		$this->assertSame( 'cancelled', $line->ref( LineState::REF_ORDER_STATUS ) );
		$this->assertStringContainsString( 'was cancelled', $this->notes( $item ) );
	}

	public function test_the_stores_own_cancel_whose_answer_was_lost_cancels_the_line(): void {
		$item = $this->pixonaut_line();

		$this->deliver( $this->cancelled_event( 'store_key' ) );
		$this->run_jobs();

		$this->assertSame( LineState::CANCELLED, LineState::of( $item )->state() );
	}

	public function test_a_cancel_delivered_twice_is_noted_once(): void {
		$item = $this->pixonaut_line();

		$this->deliver( $this->cancelled_event() );
		$this->deliver( $this->cancelled_event( 'store_key' ) );
		$this->run_jobs();

		$this->assertSame( 1, substr_count( $this->notes( $item ), 'was cancelled' ) );
	}

	public function test_a_shipped_line_is_left_alone(): void {
		$item = $this->pixonaut_line();
		foreach ( self::JOURNEY as list( $previous, $status ) ) {
			$this->deliver( $this->status_event( $previous, $status, 'shipped' === $status ? self::TRACKING : null ) );
		}
		$this->run_jobs();

		$this->deliver( $this->cancelled_event() );
		$this->run_jobs();

		$this->assertSame( LineState::SHIPPED, LineState::of( $item )->state() );
	}

	public function test_a_line_of_another_workspace_is_not_touched(): void {
		$item = $this->pixonaut_line( array( 'tenant' => 'another-tenant' ) );

		$this->deliver( $this->cancelled_event() );
		$this->run_jobs();

		$this->assertSame( LineState::APPROVED, LineState::of( $item )->state() );
	}
}
