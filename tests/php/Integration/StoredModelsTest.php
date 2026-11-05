<?php
/**
 * @package Pixonaut\WooCommerce
 */

declare(strict_types=1);

namespace Pixonaut\WooCommerce\Tests\Integration;

use Pixonaut\WooCommerce\Catalogue\PrintSize;
use Pixonaut\WooCommerce\Catalogue\ProductConfig;
use Pixonaut\WooCommerce\Catalogue\Side;
use Pixonaut\WooCommerce\Fulfilment\LineState;
use WC_Order;
use WC_Order_Item_Product;
use WC_Product;
use WC_Product_Simple;
use WC_Product_Variable;
use WC_Product_Variation;
use WP_UnitTestCase;

final class StoredModelsTest extends WP_UnitTestCase {

	public function test_product_config_round_trips_through_post_meta(): void {
		$product = new WC_Product_Simple();
		$product->save();
		$config = new ProductConfig(
			personalise: true,
			blank_id: 'blank_tshirt',
			blank_name: 'T-shirt',
			sides: array(
				new Side( 'front', 'Front', 'front', 'Chest', 'tpl_name', 3, 2, 'Name print', array( new PrintSize( 300.0, 400.0, '30×40' ) ) ),
			),
			approval_mode: 'checkbox',
			views: array( 'view_flat' ),
		);

		$config->save_to( $product );

		$this->assertEquals( $config, ProductConfig::stored_on( $this->reload( $product->get_id() ) ) );
	}

	public function test_variation_inherits_unless_it_overrides(): void {
		$parent = new WC_Product_Variable();
		$parent->save();
		$variation = new WC_Product_Variation();
		$variation->set_parent_id( $parent->get_id() );
		$variation->save();
		$this->config( 'tpl_parent', false )->save_to( $parent );
		$this->config( 'tpl_variation', false )->save_to( $variation );

		$this->assertSame( 'tpl_parent', ProductConfig::config_for( $this->reload( $variation->get_id() ) )->sides[0]->template_base_id );

		$this->config( 'tpl_variation', true )->save_to( $variation );

		$this->assertSame( 'tpl_variation', ProductConfig::config_for( $this->reload( $variation->get_id() ) )->sides[0]->template_base_id );
	}

	public function test_line_state_round_trips_through_order_item_meta(): void {
		$order = new WC_Order();
		$item  = new WC_Order_Item_Product();
		$item->set_name( 'Personalised T-shirt' );
		$order->add_item( $item );
		$order->save();

		$state = LineState::start( $item, array( 'front', 'back' ) );
		$state->set_ref( LineState::REF_TENANT_ID, 'ten/1 ×' );
		$state->set_side_ref( 'front', LineState::SIDE_DESIGN_ID, 'des_front' );
		$state->save();
		$state->transition( LineState::APPROVED );

		$read = LineState::of( $this->first_item( $order->get_id() ) );

		$this->assertSame( LineState::APPROVED, $read->state() );
		$this->assertSame( array( 'front', 'back' ), $read->sides() );
		$this->assertSame( 'ten/1 ×', $read->ref( LineState::REF_TENANT_ID ) );
		$this->assertSame( 'des_front', $read->side_ref( 'front', LineState::SIDE_DESIGN_ID ) );
	}

	public function test_line_state_on_an_unsaved_item_is_saved_with_its_order(): void {
		$order = new WC_Order();
		$item  = new WC_Order_Item_Product();
		LineState::start( $item, array( 'front' ) )->save();
		$order->add_item( $item );
		$order->save();

		$this->assertSame( LineState::COMMITTED, LineState::of( $this->first_item( $order->get_id() ) )->state() );
	}

	public function test_line_meta_and_state_are_hidden_from_the_customers_order_display(): void {
		$order = new WC_Order();
		$item  = new WC_Order_Item_Product();
		$order->add_item( $item );
		$order->save();
		LineState::start( $item, array( 'front' ) )->save();

		$this->assertSame( array(), $this->first_item( $order->get_id() )->get_formatted_meta_data() );
	}

	public function test_line_meta_is_hidden_from_the_admin_order_screen(): void {
		$order = new WC_Order();
		$item  = new WC_Order_Item_Product();
		$order->add_item( $item );
		$order->save();
		LineState::start( $item, array( 'front' ) )->save();
		$item->add_meta_data( LineState::ORDER_ID_META_KEY, 'ord_1' );
		$item->add_meta_data( LineState::DESIGN_ID_META_KEY, 'd_front' );
		$item->add_meta_data( 'Engraving', 'Anna' );
		$item->save();

		/** This filter is documented in WooCommerce's admin/meta-boxes/views/html-order-item-meta.php. */
		$hidden = (array) apply_filters( 'woocommerce_hidden_order_itemmeta', array( '_qty' ) );
		$listed = array_values(
			array_filter(
				array_column( $this->first_item( $order->get_id() )->get_all_formatted_meta_data( '' ), 'key' ),
				static fn ( $key ): bool => ! in_array( $key, $hidden, true )
			)
		);

		$this->assertContains( '_qty', $hidden, 'WooCommerce\'s own keys stay hidden' );
		$this->assertSame( array( 'Engraving' ), $listed );
	}

	public function test_a_stale_line_state_cannot_overwrite_a_newer_one(): void {
		$order = new WC_Order();
		$item  = new WC_Order_Item_Product();
		$order->add_item( $item );
		$order->save();
		$started = LineState::start( $item, array( 'front' ) );
		$started->save();
		$started->transition( LineState::APPROVED );

		$first  = LineState::of( $this->first_item( $order->get_id() ) );
		$second = LineState::of( $this->first_item( $order->get_id() ) );
		$first->set_ref( LineState::REF_TRACKING, 'TRACK1' );

		$this->assertTrue( $first->transition( LineState::SHIPPED ) );
		$this->assertFalse( $second->transition( LineState::PRINTED ) );

		$stored = LineState::of( $this->first_item( $order->get_id() ) );
		$this->assertSame( LineState::SHIPPED, $stored->state() );
		$this->assertSame( 'TRACK1', $stored->ref( LineState::REF_TRACKING ) );
	}

	private function reload( int $product_id ): WC_Product {
		$product = wc_get_product( $product_id );
		if ( ! $product instanceof WC_Product ) {
			$this->fail( "Product $product_id not found." );
		}
		return $product;
	}

	private function first_item( int $order_id ): WC_Order_Item_Product {
		$order = wc_get_order( $order_id );
		if ( ! $order instanceof WC_Order ) {
			$this->fail( "Order $order_id not found." );
		}
		$items = $order->get_items();
		$item  = reset( $items );
		if ( ! $item instanceof WC_Order_Item_Product ) {
			$this->fail( "Order $order_id has no product line." );
		}
		return $item;
	}

	private function config( string $template, bool $override ): ProductConfig {
		return new ProductConfig(
			personalise: true,
			override: $override,
			sides: array( new Side( 'front', 'Front', null, null, $template, 1, null, null ) ),
		);
	}
}
