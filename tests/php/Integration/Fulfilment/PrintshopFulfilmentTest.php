<?php
/**
 * @package Pixonaut\WooCommerce
 */

declare(strict_types=1);

namespace Pixonaut\WooCommerce\Tests\Integration\Fulfilment;

use Pixonaut\WooCommerce\ApprovalMode;
use Pixonaut\WooCommerce\Fulfilment\FulfilmentJobs;
use Pixonaut\WooCommerce\Fulfilment\LineState;
use Pixonaut\WooCommerce\Refs;
use Pixonaut\WooCommerce\Tests\Support\ActionSchedulerRunner;
use Pixonaut\WooCommerce\Tests\Support\FulfilmentAnswers;
use WC_Order_Item_Product;

/**
 * A line on no blank goes to the printshop its product chose at checkout:
 * each line is its own printshop order, so an order whose lines chose
 * different printshops sends each to its own.
 */
final class PrintshopFulfilmentTest extends FulfilmentTestCase {

	private const SIGNS = 'ps_signs';

	private const POSTERS = 'ps_posters';

	/**
	 * Answers one line's run: its approval, no order under its ref, its
	 * design still approved, and the order placed.
	 *
	 * @param string $order_id The order Pixonaut places.
	 */
	private function script_line( string $order_id ): void {
		$this->recorder
			->json( 200, FulfilmentAnswers::approved() )
			->json( 200, FulfilmentAnswers::orders( array() ) )
			->json( 200, FulfilmentAnswers::design_approved( self::DESIGN, self::REVISION ) )
			->json( 201, FulfilmentAnswers::order( $order_id, array( 'p_' . $order_id ), 'ref' ) );
	}

	/**
	 * The bodies of the `POST /v1/orders` sent, by the line ref they name.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	private function placed(): array {
		$bodies = array();
		foreach ( $this->recorder->requests as $request ) {
			if ( 'POST' === $request['method'] && self::BASE_URL . '/v1/orders' === $request['url'] ) {
				$body                                    = json_decode( (string) $request['body'], true );
				$bodies[ (string) $body['externalRef'] ] = $body;
			}
		}
		return $bodies;
	}

	private function choose( WC_Order_Item_Product $item, ?string $printshop ): void {
		$line = LineState::of( $item );
		$line->set_ref( LineState::REF_PRINTSHOP, $printshop );
		$this->assertTrue( $line->save() );
	}

	public function test_lines_for_different_printshops_are_each_ordered_from_their_own(): void {
		$this->choose( $this->item, self::SIGNS );
		$this->order->add_product( $this->product(), self::QUANTITY );
		$this->order->save();
		$items  = array_values( $this->order->get_items() );
		$second = $items[ count( $items ) - 1 ];
		$this->assertInstanceOf( WC_Order_Item_Product::class, $second );
		$line = LineState::start( $second, array( 'front' ) );
		$line->set_ref( LineState::REF_TENANT_ID, FulfilmentAnswers::TENANT_ID );
		$line->set_ref( LineState::REF_APPROVAL_MODE, ApprovalMode::ADD_TO_CART );
		$line->set_ref( LineState::REF_CONSENT, LineState::of( $this->item )->ref( LineState::REF_CONSENT ) );
		$line->set_side_ref( 'front', LineState::SIDE_DESIGN_ID, self::DESIGN_PREFIX . 'sign' );
		$line->set_side_ref( 'front', LineState::SIDE_REVISION, self::REVISION );
		$line->set_ref( LineState::REF_EXTERNAL_REF, Refs::order_item_ref( self::SITE, $this->order->get_id(), $second->get_id() ) );
		$line->set_ref( LineState::REF_EXTERNAL_REF_MOVED, true );
		$line->set_ref( LineState::REF_PRINTSHOP, self::POSTERS );
		$this->assertTrue( $line->save() );
		$this->script_line( 'o_signs' );
		$this->script_line( 'o_posters' );

		do_action( 'woocommerce_payment_complete', $this->order->get_id() );
		$this->assertSame( 2, ActionSchedulerRunner::run_pending( FulfilmentJobs::ACTION ) );

		$this->assertSame( array(), ActionSchedulerRunner::failed( FulfilmentJobs::ACTION ) );
		$placed = $this->placed();
		$first  = Refs::order_item_ref( self::SITE, $this->order->get_id(), $this->item->get_id() );
		$other  = Refs::order_item_ref( self::SITE, $this->order->get_id(), $second->get_id() );
		$this->assertSame( self::SIGNS, $placed[ $first ]['printshopId'] ?? null );
		$this->assertSame( self::POSTERS, $placed[ $other ]['printshopId'] ?? null );
		$orders = array(
			LineState::of( new WC_Order_Item_Product( $this->item->get_id() ) )->ref( LineState::REF_ORDER_ID ),
			LineState::of( new WC_Order_Item_Product( $second->get_id() ) )->ref( LineState::REF_ORDER_ID ),
		);
		sort( $orders );
		$this->assertSame( array( 'o_posters', 'o_signs' ), $orders, 'each line keeps its own Pixonaut order' );
	}

	public function test_a_line_left_on_the_workspace_default_names_no_printshop(): void {
		$this->script_line( 'o_1' );

		do_action( 'woocommerce_payment_complete', $this->order->get_id() );
		$this->assertSame( 1, ActionSchedulerRunner::run_pending( FulfilmentJobs::ACTION ) );

		$placed = $this->placed();
		$this->assertCount( 1, $placed );
		$this->assertArrayNotHasKey( 'printshopId', array_values( $placed )[0] );
	}
}
