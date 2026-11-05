<?php
/**
 * @package Pixonaut\WooCommerce
 */

declare(strict_types=1);

namespace Pixonaut\WooCommerce\Tests\Integration\Cart;

use Pixonaut\WooCommerce\Api\Hosts;
use Pixonaut\WooCommerce\Cart\CartLine;
use Pixonaut\WooCommerce\Labels;
use Pixonaut\WooCommerce\Tests\Support\DesignFixture;
use WC_Product;
use WP_REST_Request;

final class CheckoutCheckTest extends PersonalisedCartTestCase {

	private const BILLING = array(
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
		// Free and not shipped, so neither checkout asks for a payment method or a shipping rate.
		$product = wc_get_product( $this->product_id );
		$this->assertInstanceOf( WC_Product::class, $product );
		$product->set_regular_price( '0' );
		$product->set_virtual( true );
		$product->save();
		$this->script_commit();
		$this->assertTrue( $this->post_add_to_cart() );
		$this->recorder->requests = array();
	}

	public function tear_down(): void {
		foreach ( array_keys( self::BILLING ) as $field ) {
			unset( $_POST[ $field ] );
		}
		unset( $_POST['woocommerce_checkout_update_totals'], $_REQUEST['woocommerce-process-checkout-nonce'] );
		parent::tear_down();
	}

	private function refusal(): string {
		return sprintf( $this->plugin->labels()->get( Labels::DESIGN_UNAVAILABLE ), 'Personalised tee' );
	}

	/**
	 * Posts the classic checkout form.
	 *
	 * @param bool $place False posts it to update the totals, which validates without placing the order.
	 */
	private function classic_checkout( bool $place = true ): void {
		$_POST = array_merge( $_POST, self::BILLING ); // phpcs:ignore WordPress.Security.NonceVerification.Missing -- the test posts the form; WooCommerce checks its nonce.
		if ( ! $place ) {
			$_POST['woocommerce_checkout_update_totals'] = '1';
		}
		$_REQUEST['woocommerce-process-checkout-nonce'] = wp_create_nonce( 'woocommerce-process_checkout' );
		WC()->checkout()->process_checkout();
	}

	private function block_checkout(): \WP_REST_Response {
		add_filter( 'woocommerce_store_api_disable_nonce_check', '__return_true' );
		$request = new WP_REST_Request( 'POST', '/wc/store/v1/checkout' );
		$request->set_header( 'Content-Type', 'application/json' );
		$request->set_body(
			(string) wp_json_encode(
				array(
					'billing_address' => array(
						'first_name' => 'Alexandra',
						'last_name'  => 'Lind',
						'email'      => 'alexandra@example.org',
						'country'    => 'SE',
						'address_1'  => 'Storgatan 1',
						'city'       => 'Stockholm',
						'postcode'   => '11122',
						'phone'      => '0701234567',
					),
					'payment_method'  => '',
				)
			)
		);
		return rest_do_request( $request );
	}

	/**
	 * @return list<int> Every order, drafts included.
	 */
	private static function orders(): array {
		$ids = (array) wc_get_orders(
			array(
				'limit'  => -1,
				'return' => 'ids',
				'status' => array_keys( wc_get_order_statuses() ),
			)
		);
		return array_values( array_map( 'intval', $ids ) );
	}

	public function test_the_classic_checkout_stops_on_a_line_whose_design_was_deleted(): void {
		$this->recorder->json( 404, array( 'error' => 'not_found' ) );

		$this->classic_checkout();

		$this->assertContains( $this->refusal(), self::errors() );
		$this->assertSame( array(), self::orders() );
		$this->assertSame( Hosts::server_api() . '/v1/designs/' . DesignFixture::ID, $this->recorder->requests[0]['url'] );
	}

	public function test_the_classic_checkout_stops_on_a_rejected_or_erased_design(): void {
		$this->recorder
			->json( 200, DesignFixture::design( array( 'status' => 'rejected' ) ) )
			->json( 200, DesignFixture::design( array( 'personalDataErasedAt' => DesignFixture::COMMITTED_AT_MS ) ) );

		$this->classic_checkout( false );
		$this->assertContains( $this->refusal(), self::errors() );
		wc_clear_notices();
		$this->classic_checkout( false );
		$this->assertContains( $this->refusal(), self::errors() );
	}

	public function test_the_classic_checkout_goes_ahead_with_a_design_that_is_still_there(): void {
		$this->recorder->json( 200, DesignFixture::design( array( 'externalRef' => self::cart_ref() ) ) );

		$this->classic_checkout( false );

		$this->assertNotContains( $this->refusal(), self::errors() );
		$this->assertCount( 1, $this->recorder->requests );
	}

	public function test_the_classic_checkout_goes_ahead_when_pixonaut_cannot_be_reached(): void {
		$this->recorder->fail();

		$this->classic_checkout( false );

		$this->assertNotContains( $this->refusal(), self::errors() );
	}

	public function test_an_outage_costs_one_request_however_many_lines_the_cart_has(): void {
		$item = current( WC()->cart->get_cart() );
		$this->assertIsArray( $item );
		$data                         = $item[ CartLine::KEY ];
		$data['lineKey']              = 'second-line';
		$data['sides'][0]['designId'] = 'second-design';
		WC()->cart->add_to_cart( $this->product_id, 1, 0, array(), array( CartLine::KEY => $data ) );
		$this->assertCount( 2, WC()->cart->get_cart() );
		$this->recorder->fail();

		$this->classic_checkout( false );

		$this->assertNotContains( $this->refusal(), self::errors() );
		$this->assertCount( 1, $this->recorder->requests );
	}

	public function test_the_block_checkout_stops_on_a_line_whose_design_was_deleted(): void {
		$this->recorder->json( 404, array( 'error' => 'not_found' ) );

		$response = $this->block_checkout();

		$this->assertSame( 409, $response->get_status() );
		$data = $response->get_data();
		$this->assertIsArray( $data );
		$this->assertSame( $this->refusal(), $data['message'] ?? null );
		$this->assertSame( array(), self::orders() );
	}

	public function test_the_block_checkout_goes_ahead_when_pixonaut_cannot_be_reached(): void {
		// The check, then the designs' move to the order line.
		$this->recorder->fail()->fail();

		$response = $this->block_checkout();

		$this->assertSame( 200, $response->get_status(), (string) wp_json_encode( $response->get_data() ) );
		$this->assertCount( 1, self::orders() );
	}

	public function test_reading_the_store_api_cart_asks_pixonaut_nothing(): void {
		add_filter( 'woocommerce_store_api_disable_nonce_check', '__return_true' );

		$response = rest_do_request( new WP_REST_Request( 'GET', '/wc/store/v1/cart' ) );

		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( array(), $this->recorder->requests );
	}
}
