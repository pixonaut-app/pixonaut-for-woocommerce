<?php
/**
 * @package Pixonaut\WooCommerce
 */

declare(strict_types=1);

namespace Pixonaut\WooCommerce\Tests\Integration\Approval;

use Pixonaut\WooCommerce\ApprovalMode;
use Pixonaut\WooCommerce\Fulfilment\LineState;
use Pixonaut\WooCommerce\Refs;
use Pixonaut\WooCommerce\Tests\Support\DesignFixture;
use WC_Order;
use WC_Order_Item_Product;
use WC_Product;

/**
 * A paid order whose one line waits for the customer's approval of its
 * proof, by email, for a test case with a personalised product.
 */
trait AwaitingCustomerOrder {

	/**
	 * @param WC_Product $product  The personalised product.
	 * @param string     $email    The customer's address.
	 * @param int        $revision The revision the line holds.
	 *
	 * @return array{0: WC_Order, 1: WC_Order_Item_Product}
	 */
	private function awaiting_customer_order( WC_Product $product, string $email, int $revision ): array {
		$order = wc_create_order();
		$this->assertInstanceOf( WC_Order::class, $order );
		$order->add_product( $product );
		$order->set_billing_email( $email );
		$order->save();
		$item = current( $order->get_items() );
		$this->assertInstanceOf( WC_Order_Item_Product::class, $item );

		$line = LineState::start( $item, array( 'front' ) );
		$line->set_ref( LineState::REF_TENANT_ID, self::TENANT );
		$line->set_ref( LineState::REF_APPROVAL_MODE, ApprovalMode::PROOF_EMAIL );
		$line->set_side_ref( 'front', LineState::SIDE_DESIGN_ID, DesignFixture::ID );
		$line->set_side_ref( 'front', LineState::SIDE_REVISION, $revision );
		$line->set_ref( LineState::REF_EXTERNAL_REF, Refs::order_item_ref( self::SITE, $order->get_id(), $item->get_id() ) );
		$line->set_ref( LineState::REF_EXTERNAL_REF_MOVED, true );
		$this->assertTrue( $line->save() );
		$this->assertTrue( LineState::of( $item )->transition( LineState::AWAITING_CUSTOMER ) );
		return array( $order, $item );
	}
}
