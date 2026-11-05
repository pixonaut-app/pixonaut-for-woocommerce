<?php
/**
 * @package Pixonaut\WooCommerce
 */

declare(strict_types=1);

namespace Pixonaut\WooCommerce\Tests\Integration\Cart;

use Pixonaut\WooCommerce\ApprovalMode;
use Pixonaut\WooCommerce\Cart\CartLine;
use Pixonaut\WooCommerce\Cart\StandingSide;
use Pixonaut\WooCommerce\Catalogue\ProductConfig;
use Pixonaut\WooCommerce\Catalogue\Side;
use Pixonaut\WooCommerce\Catalogue\StandingRow;
use Pixonaut\WooCommerce\Labels;
use Pixonaut\WooCommerce\Refs;
use Pixonaut\WooCommerce\Settings\Settings;
use Pixonaut\WooCommerce\Tests\Support\BlankFixture;
use Pixonaut\WooCommerce\Tests\Support\DesignFixture;
use Pixonaut\WooCommerce\Fulfilment\LineState;
use ReflectionMethod;
use WC_Cart_Session;
use WC_Order;
use WC_Order_Item_Product;
use WC_Product;
use WP_REST_Request;

/**
 * A standing side names the store's standing design: putting it in the
 * cart commits nothing and asks Pixonaut nothing.
 */
final class StandingCartTest extends PersonalisedCartTestCase {

	private const STANDING_ID = 'des_standing_1';

	private const BACK_ID = 'd_back';

	public function test_a_product_of_standing_sides_alone_adds_without_a_design_or_a_request(): void {
		$this->link( self::standing_front() );

		$this->assertTrue( $this->post_add_to_cart( null ), implode( ' ', self::errors() ) );

		$this->assertSame( array(), $this->recorder->requests );
		$line = $this->line();
		$this->assertSame( array(), $line->sides );
		$this->assertEquals( array( new StandingSide( 'front', self::STANDING_ID, 0 ) ), $line->standing );
		$this->assertSame( ApprovalMode::ADD_TO_CART, $line->approval_mode );
		$this->assertNull( $line->consent );
		$this->assertSame( BlankFixture::VARIANT_REF, $line->variant );
		$this->assertSame( self::TENANT, $line->tenant_id );
	}

	public function test_adding_it_again_adds_to_the_same_line(): void {
		$this->link( self::standing_front() );

		$this->assertTrue( $this->post_add_to_cart( null ) );
		$this->assertTrue( $this->post_add_to_cart( null ) );

		$items = array_values( WC()->cart->get_cart() );
		$this->assertCount( 1, $items );
		$this->assertSame( 2, $items[0]['quantity'] );
	}

	public function test_the_checkbox_mode_asks_no_approval_for_it(): void {
		$this->plugin->settings()->set( Settings::DEFAULT_APPROVAL_MODE, ApprovalMode::CHECKBOX );
		$this->link( self::standing_front() );

		$this->assertTrue( $this->post_add_to_cart( null ), implode( ' ', self::errors() ) );
		$this->assertSame( ApprovalMode::ADD_TO_CART, $this->line()->approval_mode );
	}

	public function test_the_store_api_adds_it(): void {
		add_filter( 'woocommerce_store_api_disable_nonce_check', '__return_true' );
		$this->link( self::standing_front() );
		$request = new WP_REST_Request( 'POST', '/wc/store/v1/cart/add-item' );
		$request->set_param( 'id', $this->product_id );
		$request->set_param( 'quantity', 1 );

		$response = rest_do_request( $request );

		$this->assertSame( 201, $response->get_status() );
		$this->assertEquals( array( new StandingSide( 'front', self::STANDING_ID, 0 ) ), $this->line()->standing );
		$this->assertSame( array(), $this->recorder->requests );
	}

	public function test_a_standing_side_whose_design_was_not_found_is_not_sold(): void {
		$this->link( self::standing_front( null ) );

		$this->assertFalse( $this->post_add_to_cart( null ) );

		$this->assertSame( array( $this->plugin->labels()->get( Labels::PERSONALISATION_UNAVAILABLE ) ), self::errors() );
		$this->assertTrue( WC()->cart->is_empty() );
	}

	public function test_a_side_with_a_design_per_row_adds_the_design_of_the_variants_row(): void {
		$this->link(
			self::standing_front( null )->with_standing_rows(
				array(
					new StandingRow( array( 'Size' => 'S' ), 'des_row_s', array( 'v_other' ) ),
					new StandingRow( array( 'Size' => 'M' ), 'des_row_m', array( BlankFixture::VARIANT_REF ) ),
				)
			)
		);

		$this->assertTrue( $this->post_add_to_cart( null ), implode( ' ', self::errors() ) );

		$this->assertEquals( array( new StandingSide( 'front', 'des_row_m', 0 ) ), $this->line()->standing );
		$this->assertSame( array(), $this->recorder->requests );
	}

	public function test_a_variant_in_no_row_is_not_sold(): void {
		$this->link( self::standing_front( null )->with_standing_rows( array( new StandingRow( array( 'Size' => 'S' ), 'des_row_s', array( 'v_other' ) ) ) ) );

		$this->assertFalse( $this->post_add_to_cart( null ) );

		$this->assertSame( array( $this->plugin->labels()->get( Labels::PERSONALISATION_UNAVAILABLE ) ), self::errors() );
		$this->assertTrue( WC()->cart->is_empty() );
	}

	public function test_a_standing_front_and_a_personalised_back_commit_the_back_alone(): void {
		$front = self::standing_front();
		$back  = new Side( 'back', 'Back', 'back', 'Back', $front->template_base_id, $front->template_version, $front->previous_version, null, $front->print_sizes );
		$this->link( $front, $back );
		$ref = Refs::cart_ref( self::SITE, Refs::line_key( array( 'back' => self::TOKEN ), (string) WC()->session->get_customer_id() ) );
		$this->recorder
			->json(
				201,
				array(
					'designId'      => self::BACK_ID,
					'printFloorDpi' => 150,
					'lowResolution' => array(),
				)
			)
			->json(
				200,
				DesignFixture::design(
					array(
						'id'          => self::BACK_ID,
						'placementId' => 'back',
						'externalRef' => $ref,
					)
				)
			);

		$this->assertTrue( $this->post_add_to_cart( array( 'back' => self::TOKEN ) ), implode( ' ', self::errors() ) );

		$commits = array_values( array_filter( $this->recorder->requests, static fn ( array $request ): bool => 'POST' === $request['method'] ) );
		$this->assertCount( 1, $commits );
		$this->assertSame( self::TOKEN, json_decode( (string) $commits[0]['body'], true )['proofToken'] ?? null );
		$line = $this->line();
		$this->assertSame( array( 'back' ), array_map( static fn ( $side ): string => $side->key, $line->sides ) );
		$this->assertEquals( array( new StandingSide( 'front', self::STANDING_ID, 0 ) ), $line->standing );
	}

	public function test_a_mixed_product_still_needs_the_personalised_sides_design(): void {
		$front = self::standing_front();
		$this->link( $front, new Side( 'back', 'Back', 'back', 'Back', $front->template_base_id, $front->template_version, $front->previous_version, null, $front->print_sizes ) );

		$this->assertFalse( $this->post_add_to_cart( null ) );

		$this->assertSame( array( $this->plugin->labels()->get( Labels::DESIGN_REQUIRED ) ), self::errors() );
		$this->assertSame( array(), $this->recorder->requests );
	}

	public function test_checkout_carries_the_standing_sides_to_the_order_line_and_asks_pixonaut_nothing(): void {
		$this->link( self::standing_front() );
		$this->assertTrue( $this->post_add_to_cart( null ) );

		$order_id = WC()->checkout()->create_order(
			array(
				'payment_method'     => 'cod',
				'billing_first_name' => 'Alexandra',
				'billing_last_name'  => 'Lind',
				'billing_email'      => 'alexandra@example.org',
				'billing_country'    => 'SE',
				'billing_address_1'  => 'Storgatan 1',
				'billing_city'       => 'Stockholm',
				'billing_postcode'   => '11122',
			)
		);

		$this->assertIsInt( $order_id );
		$order = wc_get_order( $order_id );
		$this->assertInstanceOf( WC_Order::class, $order );
		$item = current( $order->get_items() );
		$this->assertInstanceOf( WC_Order_Item_Product::class, $item );
		$line = LineState::of( $item );
		$this->assertSame( array(), $line->sides() );
		$this->assertEquals( array( new StandingSide( 'front', self::STANDING_ID, 0 ) ), $line->standing() );
		$this->assertSame( Refs::order_item_ref( self::SITE, $order_id, $item->get_id() ), $line->ref( LineState::REF_EXTERNAL_REF ) );
		$this->assertSame( array(), $this->recorder->requests );
	}

	public function test_order_again_puts_a_line_of_standing_sides_back_with_its_standing_design(): void {
		$customer = self::factory()->user->create( array( 'role' => 'customer' ) );
		$this->assertIsInt( $customer );
		wp_set_current_user( $customer );
		$this->link( self::standing_front() );
		$this->assertTrue( $this->post_add_to_cart( null ) );
		$order_id = WC()->checkout()->create_order(
			array(
				'payment_method'     => 'cod',
				'billing_first_name' => 'Alexandra',
				'billing_email'      => 'alexandra@example.org',
			)
		);
		$this->assertIsInt( $order_id );
		$order = wc_get_order( $order_id );
		$this->assertInstanceOf( WC_Order::class, $order );
		$order->set_customer_id( $customer );
		$order->set_status( 'completed' );
		$order->save();
		WC()->cart->empty_cart();

		$session  = new WC_Cart_Session( WC()->cart );
		$populate = new ReflectionMethod( $session, 'populate_cart_from_order' );
		$populate->setAccessible( true );
		$cart = $populate->invoke( $session, $order_id, array() );
		wp_set_current_user( 0 );

		$this->assertIsArray( $cart );
		$this->assertCount( 1, $cart );
		$line = CartLine::of( current( $cart ) );
		$this->assertNotNull( $line );
		$this->assertEquals( array( new StandingSide( 'front', self::STANDING_ID, 0 ) ), $line->standing );
		$this->assertSame( array(), $this->recorder->requests );
	}

	/**
	 * The fixture's front, standing.
	 *
	 * @param string|null $design_id Its standing design; null for one not found yet.
	 */
	private static function standing_front( ?string $design_id = self::STANDING_ID ): Side {
		$front = self::config()->sides[0];
		return new Side( $front->key, $front->label, $front->placement_id, $front->placement_name, $front->template_base_id, $front->template_version, $front->previous_version, $front->template_name, $front->print_sizes, mode: Side::STANDING, standing_design_id: $design_id );
	}

	/**
	 * Sells the product with these sides.
	 *
	 * @param Side ...$sides The sides, in order.
	 */
	private function link( Side ...$sides ): void {
		$product = wc_get_product( $this->product_id );
		$this->assertInstanceOf( WC_Product::class, $product );
		( new ProductConfig( personalise: true, blank_id: DesignFixture::BLANK_ID, sides: array_values( $sides ) ) )->save_to( $product );
		DesignFixture::sell_variant( $product );
	}

	/**
	 * The cart's one line.
	 */
	private function line(): CartLine {
		$items = array_values( WC()->cart->get_cart() );
		$this->assertCount( 1, $items );
		$line = CartLine::of( $items[0] );
		$this->assertNotNull( $line );
		return $line;
	}
}
