<?php
/**
 * @package Pixonaut\WooCommerce
 */

declare(strict_types=1);

namespace Pixonaut\WooCommerce\Tests\Integration\Fulfilment;

use Pixonaut\WooCommerce\Cart\StandingSide;
use Pixonaut\WooCommerce\Fulfilment\FulfilmentJobs;
use Pixonaut\WooCommerce\Fulfilment\LineState;
use Pixonaut\WooCommerce\Refs;
use Pixonaut\WooCommerce\Tests\Support\ActionSchedulerRunner;
use Pixonaut\WooCommerce\Tests\Support\FulfilmentAnswers;
use WC_Order;
use WC_Order_Item_Product;

/**
 * A book's order names each design of its pages with its page, the other
 * sides' designs without one; a refusal of the pages is not retried.
 */
final class BookFulfilmentTest extends FulfilmentTestCase {

	private const STANDING_ID = 'des_standing_back';

	/** The cover, then the book's pages, by slot. */
	private const SIDES = array( 'cover', 'pages#1', 'pages#2' );

	/**
	 * A paid order of a cover and a book of two pages, and a standing back
	 * after the pages.
	 *
	 * @return array{0: WC_Order, 1: WC_Order_Item_Product}
	 */
	private function book_order(): array {
		$order = $this->paid_order( self::SIDES );
		$item  = current( $order->get_items() );
		$this->assertInstanceOf( WC_Order_Item_Product::class, $item );
		$line = LineState::of( $item );
		$line->set_side_ref( 'pages#1', LineState::SIDE_PAGE, 1 );
		$line->set_side_ref( 'pages#2', LineState::SIDE_PAGE, 2 );
		$line->set_ref( LineState::REF_STANDING, array( ( new StandingSide( 'back', self::STANDING_ID, 2 ) )->to_array() ) );
		$this->assertTrue( $line->save() );
		return array( $order, $item );
	}

	/**
	 * Scripts each side's approval and the read before the order.
	 */
	private function script_approvals(): void {
		foreach ( self::SIDES as $_ ) {
			$this->recorder->json( 200, FulfilmentAnswers::approved() );
		}
		$this->recorder->json( 200, FulfilmentAnswers::orders( array() ) );
		foreach ( self::SIDES as $side ) {
			$this->recorder->json( 200, FulfilmentAnswers::design_approved( self::DESIGN_PREFIX . $side, self::REVISION ) );
		}
	}

	private function fulfil( WC_Order $order ): void {
		do_action( 'woocommerce_payment_complete', $order->get_id() );
		ActionSchedulerRunner::run_pending( FulfilmentJobs::ACTION );
		$this->assertSame( array(), ActionSchedulerRunner::failed( FulfilmentJobs::ACTION ) );
	}

	/**
	 * The `POST /v1/orders` bodies sent.
	 *
	 * @return list<array<string, mixed>>
	 */
	private function order_bodies(): array {
		$orders = array_values( array_filter( $this->recorder->requests, static fn ( array $request ): bool => 'POST' === $request['method'] && self::BASE_URL . '/v1/orders' === $request['url'] ) );
		return array_map( static fn ( array $request ): array => json_decode( (string) $request['body'], true ), $orders );
	}

	public function test_the_order_names_each_page_and_counts_a_standing_side_after_the_book(): void {
		[ $order, $item ] = $this->book_order();
		$this->script_approvals();
		$this->recorder->json( 201, FulfilmentAnswers::order( 'o_1', array( 'p_cover', 'p_1', 'p_2', 'p_back' ), Refs::order_item_ref( self::SITE, $order->get_id(), $item->get_id() ) ) );

		$this->fulfil( $order );

		$this->assertSame(
			array(
				array( 'designId' => 'd_cover' ),
				array(
					'designId' => 'd_pages#1',
					'page'     => 1,
				),
				array(
					'designId' => 'd_pages#2',
					'page'     => 2,
				),
				array( 'designId' => self::STANDING_ID ),
			),
			$this->order_bodies()[0]['items'][0]['designs'] ?? null
		);
		$this->assertSame( LineState::ORDERED, LineState::of( new WC_Order_Item_Product( $item->get_id() ) )->state() );
	}

	public function test_a_refusal_of_the_pages_needs_the_merchant_and_is_not_retried(): void {
		[ $order, $item ] = $this->book_order();
		$this->script_approvals();
		$this->recorder->json(
			422,
			array(
				'error'        => 'page_not_as_proofed',
				'placementId'  => 'pages',
				'page'         => 2,
				'proofedPage'  => 1,
				'proofedCount' => 2,
				'itemIndex'    => 0,
			)
		);

		$this->fulfil( $order );

		$line = LineState::of( new WC_Order_Item_Product( $item->get_id() ) );
		$this->assertSame( LineState::NEEDS_ATTENTION, $line->state() );
		$this->assertCount( 1, $this->order_bodies() );
		$this->assertFalse( FulfilmentJobs::is_scheduled( $item->get_id() ) );
		$notes = array_map( static fn ( $note ): string => $note->content, wc_get_order_notes( array( 'order_id' => $order->get_id() ) ) );
		$this->assertNotEmpty( array_filter( $notes, static fn ( string $note ): bool => str_contains( $note, 'page_not_as_proofed' ) ) );
	}
}
