<?php
/**
 * @package Pixonaut\WooCommerce
 */

declare(strict_types=1);

namespace Pixonaut\WooCommerce\Tests\Integration\Cart;

use Automattic\WooCommerce\StoreApi\Utilities\OrderController;
use Pixonaut\WooCommerce\Api\Hosts;
use Pixonaut\WooCommerce\ApprovalMode;
use Pixonaut\WooCommerce\Cart\CartLine;
use Pixonaut\WooCommerce\Cart\FieldValue;
use Pixonaut\WooCommerce\Catalogue\ProductConfig;
use Pixonaut\WooCommerce\Fulfilment\LineState;
use Pixonaut\WooCommerce\Labels;
use Pixonaut\WooCommerce\Refs;
use Pixonaut\WooCommerce\Settings\Settings;
use Pixonaut\WooCommerce\Tests\Support\DesignFixture;
use Pixonaut\WooCommerce\Tests\Support\FulfilmentAnswers;
use Pixonaut\WooCommerce\Tests\Support\BlankFixture;
use WC_Order;
use WC_Order_Item_Product;
use WC_Product;
use WC_Product_Simple;

final class OrderLinesTest extends PersonalisedCartTestCase {

	/**
	 * A printshop connection of the workspace.
	 */
	private const PRINTSHOP = 'ps_signs';

	private const CHECKOUT = array(
		'payment_method'     => 'cod',
		'billing_first_name' => 'Alexandra',
		'billing_last_name'  => 'Lind',
		'billing_email'      => 'alexandra@example.org',
		'billing_country'    => 'SE',
		'billing_address_1'  => 'Storgatan 1',
		'billing_city'       => 'Stockholm',
		'billing_postcode'   => '11122',
	);

	public function set_up(): void {
		parent::set_up();
		$this->script_commit();
		$this->assertTrue( $this->post_add_to_cart() );
		$this->recorder->requests = array();
	}

	private function script_move( int $order_id, int $item_id ): void {
		$this->recorder->json(
			200,
			array(
				'designId'    => DesignFixture::ID,
				'externalRef' => Refs::order_item_ref( self::SITE, $order_id, $item_id ),
			)
		);
	}

	/**
	 * Places the cart through the classic checkout, answering the ref move.
	 */
	private function classic_checkout(): WC_Order {
		// Item ids are known only once the order is saved, so the move is
		// scripted from inside the checkout, before it is sent.
		add_action(
			'woocommerce_checkout_order_created',
			function ( WC_Order $order ): void {
				$this->script_move( $order->get_id(), self::personalised_item( $order )->get_id() );
			},
			1
		);
		$order_id = WC()->checkout()->create_order( self::CHECKOUT );
		$this->assertIsInt( $order_id );
		$order = wc_get_order( $order_id );
		$this->assertInstanceOf( WC_Order::class, $order );
		return $order;
	}

	private static function personalised_item( WC_Order $order ): WC_Order_Item_Product {
		foreach ( $order->get_items() as $item ) {
			if ( $item instanceof WC_Order_Item_Product ) {
				return $item;
			}
		}
		self::fail( 'The order has no product line.' );
	}

	public function test_the_classic_checkout_copies_the_designs_to_the_order_line(): void {
		$order = $this->classic_checkout();

		$state = LineState::of( self::personalised_item( $order ) );
		$this->assertSame( LineState::COMMITTED, $state->state() );
		$this->assertSame( array( 'front' ), $state->sides() );
		$this->assertSame( self::TENANT, $state->ref( LineState::REF_TENANT_ID ) );
		$this->assertSame( BlankFixture::VARIANT_REF, $state->ref( LineState::REF_VARIANT ) );
		$this->assertSame( ApprovalMode::ADD_TO_CART, $state->ref( LineState::REF_APPROVAL_MODE ) );
		$this->assertSame( ApprovalMode::ADD_TO_CART, $state->ref( LineState::REF_CONSENT )['kind'] ?? null );
		$this->assertSame( DesignFixture::ID, $state->side_ref( 'front', LineState::SIDE_DESIGN_ID ) );
		$this->assertSame( 1, $state->side_ref( 'front', LineState::SIDE_REVISION ) );
		$this->assertSame( DesignFixture::TEMPLATE_BASE_ID, $state->side_ref( 'front', LineState::SIDE_TEMPLATE_BASE_ID ) );
		$this->assertSame( DesignFixture::LIVE_VERSION, $state->side_ref( 'front', LineState::SIDE_TEMPLATE_VERSION ) );
		$this->assertSame( Refs::commit_key( self::SITE, self::TOKEN ), $state->side_ref( 'front', LineState::SIDE_COMMIT_KEY ) );
		$this->assertSame( DesignFixture::PROOF_ID, $state->side_ref( 'front', LineState::SIDE_PROOF_ID ) );
	}

	public function test_the_order_line_keeps_the_language_the_customer_bought_in(): void {
		$visitor = static fn (): string => 'nb_NO';
		add_filter( 'determine_locale', $visitor );
		$order   = $this->classic_checkout();
		remove_filter( 'determine_locale', $visitor );

		$this->assertSame( 'nb_NO', LineState::of( self::personalised_item( $order ) )->ref( LineState::REF_LOCALE ) );
	}

	/**
	 * The order line of a cart whose personalised line kept a name and a photo.
	 */
	private function ordered_with_fields(): WC_Order_Item_Product {
		$lines = WC()->cart->cart_contents;
		foreach ( array_keys( $lines ) as $key ) {
			$lines[ $key ][ CartLine::KEY ]['sides'][0]['fields'] = array(
				array(
					'label' => 'Name',
					'type'  => FieldValue::TEXT,
					'value' => '<i>Anna</i>',
				),
				array(
					'label' => 'Photo',
					'type'  => FieldValue::IMAGE,
					'value' => '',
				),
			);
		}
		WC()->cart->set_cart_contents( $lines );

		return self::personalised_item( $this->classic_checkout() );
	}

	public function test_the_field_values_reach_the_order_line_and_show_under_it(): void {
		$this->plugin->settings()->set( Settings::SHOW_FIELD_VALUES, true );
		$item = $this->ordered_with_fields();

		$this->assertEquals(
			array( new FieldValue( 'Name', FieldValue::TEXT, '<i>Anna</i>' ), new FieldValue( 'Photo', FieldValue::IMAGE, '' ) ),
			LineState::of( $item )->side_fields( 'front' )
		);
		$rows  = $item->get_formatted_meta_data();
		$image = $this->plugin->labels()->get( Labels::FIELD_IMAGE );
		$this->assertSame( array( 'pixonaut_field_front_0', 'pixonaut_field_front_1' ), array_keys( $rows ), 'the customer sees no line state' );
		$fields = array_slice( $rows, 0, 2 );
		$this->assertSame( array( 'Name', 'Photo' ), array_column( $fields, 'key' ) );
		$this->assertSame( array( '<i>Anna</i>', $image ), array_column( $fields, 'value' ) );
		$this->assertSame( array( 'Name', 'Photo' ), array_column( $fields, 'display_key' ) );
		$this->assertSame( array( '&lt;i&gt;Anna&lt;/i&gt;', esc_html( $image ) ), array_column( $fields, 'display_value' ) );
	}

	public function test_by_default_only_the_admin_order_screen_lists_the_field_values(): void {
		$item = $this->ordered_with_fields();

		$this->assertSame( array(), $item->get_formatted_meta_data(), 'the customer sees no values' );

		ob_start();
		do_action( 'woocommerce_before_order_itemmeta', $item->get_id(), $item, $item->get_product() );
		$editable = array_keys( $item->get_all_formatted_meta_data( '' ) );
		do_action( 'woocommerce_after_order_itemmeta', $item->get_id(), $item, $item->get_product() );
		$admin = (string) ob_get_clean();

		$this->assertSame( array(), array_filter( $editable, static fn ( $key ): bool => str_starts_with( (string) $key, 'pixonaut_' ) ), 'the meta form, which saves what it lists, holds none of the rows' );
		$this->assertStringContainsString( '<th>Name:</th>', $admin );
		$this->assertStringContainsString( '&lt;i&gt;Anna&lt;/i&gt;', $admin );
		$this->assertStringContainsString( '<th>' . esc_html( $this->plugin->labels()->get( Labels::LINE_STATE ) ) . ':</th>', $admin );
		$this->assertSame( array(), $item->get_formatted_meta_data(), 'an email after the admin screen sees no values' );
	}

	public function test_a_line_without_field_values_lists_none(): void {
		$this->plugin->settings()->set( Settings::SHOW_FIELD_VALUES, true );
		$item = self::personalised_item( $this->classic_checkout() );

		$this->assertNull( LineState::of( $item )->side_ref( 'front', LineState::SIDE_FIELDS ) );
		$this->assertSame( array(), $item->get_formatted_meta_data() );
	}

	public function test_a_cart_line_from_before_variants_gets_the_variant_its_product_sells_now(): void {
		foreach ( array_keys( WC()->cart->cart_contents ) as $key ) {
			unset( WC()->cart->cart_contents[ $key ][ CartLine::KEY ]['variant'] );
		}

		$order = $this->classic_checkout();

		$this->assertSame( BlankFixture::VARIANT_REF, LineState::of( self::personalised_item( $order ) )->ref( LineState::REF_VARIANT ) );
	}

	public function test_a_line_on_no_blank_keeps_the_printshop_its_product_chose(): void {
		$product = new WC_Product_Simple();
		$product->set_name( 'Personalised sign' );
		$product->save();
		( new ProductConfig( personalise: true, sides: self::config()->sides, printshop_id: self::PRINTSHOP ) )->save_to( $product );
		$values         = current( WC()->cart->get_cart() );
		$values['data'] = wc_get_product( $product->get_id() );
		$item           = new WC_Order_Item_Product();

		do_action( 'woocommerce_checkout_create_order_line_item', $item, 'key', $values );

		$this->assertSame( self::PRINTSHOP, LineState::of( $item )->ref( LineState::REF_PRINTSHOP ) );
	}

	public function test_a_line_on_a_blank_names_no_printshop_whatever_its_product_chose(): void {
		$product = wc_get_product( $this->product_id );
		$this->assertInstanceOf( WC_Product::class, $product );
		self::config()->with_printshop( self::PRINTSHOP )->save_to( $product );

		$order = $this->classic_checkout();

		$this->assertNull( LineState::of( self::personalised_item( $order ) )->ref( LineState::REF_PRINTSHOP ), 'the blank decides its printshop' );
	}

	public function test_the_designs_move_to_the_order_line_ref(): void {
		$order = $this->classic_checkout();

		$item = self::personalised_item( $order );
		$ref  = Refs::order_item_ref( self::SITE, $order->get_id(), $item->get_id() );
		$this->assertCount( 1, $this->recorder->requests );
		$move = $this->recorder->requests[0];
		$this->assertSame( 'PUT', $move['method'] );
		$this->assertSame( Hosts::server_api() . '/v1/designs/' . DesignFixture::ID . '/external-ref', $move['url'] );
		$this->assertSame( array( 'externalRef' => $ref ), json_decode( (string) $move['body'], true ) );
		$this->assertSame( $ref, LineState::of( $item )->ref( LineState::REF_EXTERNAL_REF ) );
		$this->assertTrue( LineState::of( $item )->ref( LineState::REF_EXTERNAL_REF_MOVED ) );
	}

	public function test_a_second_run_sends_nothing_and_keeps_the_line(): void {
		$order = $this->classic_checkout();
		$item  = self::personalised_item( $order );
		$state = LineState::of( $item );

		do_action( 'woocommerce_checkout_order_created', wc_get_order( $order->get_id() ) );
		do_action( 'woocommerce_checkout_create_order_line_item', $item, 'cart-key', array( 'pixonaut' => array() ), $order );

		$this->assertCount( 1, $this->recorder->requests );
		$this->assertSame( $state->ref( LineState::REF_EXTERNAL_REF ), LineState::of( $item )->ref( LineState::REF_EXTERNAL_REF ) );
	}

	public function test_a_failed_move_keeps_the_cart_ref_and_the_designs(): void {
		add_action(
			'woocommerce_checkout_order_created',
			function (): void {
				$this->recorder->json( 500, array( 'error' => 'db_error' ) );
			},
			1
		);

		$order_id = WC()->checkout()->create_order( self::CHECKOUT );

		$this->assertIsInt( $order_id );
		$order = wc_get_order( $order_id );
		$this->assertInstanceOf( WC_Order::class, $order );
		$state = LineState::of( self::personalised_item( $order ) );
		$this->assertSame( self::cart_ref(), $state->ref( LineState::REF_EXTERNAL_REF ) );
		$this->assertNull( $state->ref( LineState::REF_EXTERNAL_REF_MOVED ) );
		$this->assertSame( DesignFixture::ID, $state->side_ref( 'front', LineState::SIDE_DESIGN_ID ) );
	}

	public function test_fulfilment_after_a_failed_move_places_the_order_under_the_order_line_ref(): void {
		add_action(
			'woocommerce_checkout_order_created',
			function (): void {
				$this->recorder->json( 500, array( 'error' => 'db_error' ) );
			},
			1
		);
		$order_id = WC()->checkout()->create_order( self::CHECKOUT );
		$this->assertIsInt( $order_id );
		$order = wc_get_order( $order_id );
		$this->assertInstanceOf( WC_Order::class, $order );
		$item                     = self::personalised_item( $order );
		$ref                      = Refs::order_item_ref( self::SITE, $order->get_id(), $item->get_id() );
		$this->recorder->requests = array();
		$this->script_move( $order->get_id(), $item->get_id() );
		$this->recorder
			->json( 200, FulfilmentAnswers::approved() )
			->json( 200, FulfilmentAnswers::orders( array() ) )
			->json( 200, DesignFixture::design( array( 'status' => 'approved' ) ) )
			->json( 201, FulfilmentAnswers::order( 'o_1', array( 'p_front' ), $ref ) );

		$this->plugin->fulfiller()->fulfil( $item );

		$sent = $this->recorder->requests;
		$this->assertCount( 5, $sent );
		$this->assertSame( array( 'PUT', Hosts::server_api() . '/v1/designs/' . DesignFixture::ID . '/external-ref' ), array( $sent[0]['method'], $sent[0]['url'] ) );
		$this->assertSame( array( 'externalRef' => $ref ), json_decode( (string) $sent[0]['body'], true ) );
		$this->assertSame( Hosts::server_api() . '/v1/orders?externalRef=' . rawurlencode( $ref ), $sent[2]['url'] );
		$placed = json_decode( (string) $sent[4]['body'], true );
		$this->assertIsArray( $placed );
		$this->assertSame( $ref, $placed['externalRef'] );
		$state = LineState::of( $item );
		$this->assertSame( LineState::ORDERED, $state->state() );
		$this->assertSame( $ref, $state->ref( LineState::REF_EXTERNAL_REF ) );
		$this->assertTrue( $state->ref( LineState::REF_EXTERNAL_REF_MOVED ) );
	}

	public function test_the_block_checkout_copies_and_moves_on_placing_the_order(): void {
		$order = ( new OrderController() )->create_order_from_cart();
		$item  = self::personalised_item( $order );
		$this->assertSame( DesignFixture::ID, LineState::of( $item )->side_ref( 'front', LineState::SIDE_DESIGN_ID ) );
		$this->assertSame( array(), $this->recorder->requests, 'A draft order moves nothing.' );
		$this->script_move( $order->get_id(), $item->get_id() );

		do_action( 'woocommerce_store_api_checkout_order_processed', $order );

		$ref = Refs::order_item_ref( self::SITE, $order->get_id(), $item->get_id() );
		$this->assertSame( array( 'externalRef' => $ref ), json_decode( (string) $this->recorder->requests[0]['body'], true ) );
		$this->assertSame( $ref, LineState::of( $item )->ref( LineState::REF_EXTERNAL_REF ) );
	}
}
