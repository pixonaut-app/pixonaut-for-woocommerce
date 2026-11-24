<?php
/**
 * @package Pixonaut\WooCommerce
 */

declare(strict_types=1);

namespace Pixonaut\WooCommerce\Tests\Integration\Webhooks;

use Pixonaut\WooCommerce\Fulfilment\LineState;
use WC_Order_Item_Product;

/**
 * `order.cancelled` cancels the order's lines, and `item.cancelled` the line
 * of its item, however the cancel was asked for, and only once.
 */
final class OrderCancelledTest extends OrderEventsTestCase {

	/**
	 * A second item of ORDER_ID, another line's.
	 */
	private const OTHER_ITEM_ID = 'oi_second';

	private const OTHER_EXTERNAL_REF = 'wc_site_1042_8';

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
			$this->deliver( $this->status_event( $previous, $status ) );
		}
		$this->run_jobs();

		$this->deliver( $this->cancelled_event() );
		$this->run_jobs();

		$this->assertSame( LineState::SHIPPED, LineState::of( $item )->state() );
	}

	public function test_an_order_cancelled_cancels_every_line_it_holds(): void {
		$first  = $this->pixonaut_line();
		$second = $this->second_line( $first );

		$this->deliver( $this->cancelled_event() );
		$this->run_jobs();

		$this->assertSame( LineState::CANCELLED, LineState::of( $first )->state() );
		$this->assertSame( LineState::CANCELLED, LineState::of( $second )->state() );
	}

	public function test_an_item_cancelled_cancels_only_the_line_of_its_item(): void {
		$first  = $this->pixonaut_line();
		$second = $this->second_line( $first );

		$this->deliver(
			$this->item_cancelled_event(
				array(
					'itemId'          => self::OTHER_ITEM_ID,
					'itemExternalRef' => self::OTHER_EXTERNAL_REF,
				)
			)
		);
		$this->run_jobs();

		$this->assertSame( LineState::APPROVED, LineState::of( $first )->state() );
		$this->assertSame( LineState::CANCELLED, LineState::of( $second )->state() );
		$this->assertSame( 1, substr_count( $this->notes( $first ), 'was cancelled' ) );
	}

	public function test_a_line_of_another_workspace_is_not_touched(): void {
		$item = $this->pixonaut_line( array( 'tenant' => 'another-tenant' ) );

		$this->deliver( $this->cancelled_event() );
		$this->run_jobs();

		$this->assertSame( LineState::APPROVED, LineState::of( $item )->state() );
	}

	/**
	 * Adds a line to the order of $first, held in ORDER_ID as OTHER_ITEM_ID.
	 *
	 * @param WC_Order_Item_Product $first A line of the order.
	 */
	private function second_line( WC_Order_Item_Product $first ): WC_Order_Item_Product {
		return $this->pixonaut_line(
			array(
				'order'        => $this->order_of( $first ),
				'item_id'      => self::OTHER_ITEM_ID,
				'external_ref' => self::OTHER_EXTERNAL_REF,
			)
		);
	}
}
