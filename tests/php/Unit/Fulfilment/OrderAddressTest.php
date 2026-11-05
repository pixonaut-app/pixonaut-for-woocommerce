<?php
/**
 * @package Pixonaut\WooCommerce
 */

declare(strict_types=1);

namespace Pixonaut\WooCommerce\Tests\Unit\Fulfilment;

use Brain\Monkey\Filters;
use Brain\Monkey\Functions;
use Mockery;
use Mockery\MockInterface;
use Pixonaut\WooCommerce\Fulfilment\OrderAddress;
use Pixonaut\WooCommerce\Tests\Unit\TestCase;
use WC_Order;
use WC_Order_Item_Product;

final class OrderAddressTest extends TestCase {

	private const BILLING = array(
		'first_name' => 'Grace',
		'last_name'  => 'Hopper',
		'company'    => '',
		'address_1'  => '1 Main St',
		'address_2'  => 'Apt 2',
		'city'       => 'Arlington',
		'state'      => 'VA',
		'postcode'   => '22201',
		'country'    => 'US',
		'email'      => 'grace@example.org',
		'phone'      => '+1 555 0100',
	);

	private const BILLING_NO_NAME = array(
		'first_name' => '',
		'last_name'  => '',
	) + self::BILLING;

	public function test_the_shipping_address_is_sent_with_the_billing_email_and_phone(): void {
		$order = $this->order(
			array(
				'first_name' => ' Ada ',
				'last_name'  => 'Lovelace',
				'company'    => 'Analytical Engines',
				'address_1'  => 'Storgatan 1',
				'address_2'  => '',
				'city'       => 'Stockholm',
				'state'      => '',
				'postcode'   => '111 22',
				'country'    => 'se',
				'phone'      => '+46 8 123 456',
			)
		);

		$this->assertSame(
			array(
				'recipient'       => array(
					'name'  => 'Ada Lovelace',
					'email' => 'grace@example.org',
					'phone' => '+46 8 123 456',
				),
				'shippingAddress' => array(
					'company'    => 'Analytical Engines',
					'line1'      => 'Storgatan 1',
					'postalCode' => '111 22',
					'city'       => 'Stockholm',
					'country'    => 'SE',
				),
			),
			OrderAddress::of( $order, $this->item() )
		);
	}

	public function test_an_order_without_a_shipping_address_ships_to_its_billing_address(): void {
		$mapped = OrderAddress::of( $this->order( null ), $this->item() );

		$this->assertSame( 'Grace Hopper', $mapped['recipient']['name'] ?? null );
		$this->assertSame(
			array(
				'line1'      => '1 Main St',
				'line2'      => 'Apt 2',
				'postalCode' => '22201',
				'city'       => 'Arlington',
				'region'     => 'VA',
				'country'    => 'US',
			),
			$mapped['shippingAddress'] ?? null
		);
	}

	public function test_a_company_without_a_contact_name_is_the_recipient(): void {
		$mapped = OrderAddress::of( $this->order( array( 'company' => 'Acme AB' ) + self::BILLING_NO_NAME ), $this->item() );

		$this->assertSame( 'Acme AB', $mapped['recipient']['name'] ?? null );
	}

	public function test_an_incomplete_address_is_left_out_whole(): void {
		$mapped = OrderAddress::of( $this->order( array( 'city' => '' ) + self::BILLING ), $this->item() );

		$this->assertArrayNotHasKey( 'shippingAddress', $mapped );
		$this->assertArrayHasKey( 'recipient', $mapped );
	}

	public function test_a_fingerprint_ignores_field_order_and_other_keys_but_not_a_changed_field(): void {
		Functions\when( 'wp_json_encode' )->alias( 'json_encode' );
		$mapped                           = array(
			'recipient'       => array(
				'name'  => 'Ada Lovelace',
				'email' => 'ada@example.org',
			),
			'shippingAddress' => array(
				'line1'   => 'Storgatan 1',
				'city'    => 'Stockholm',
				'country' => 'SE',
			),
		);
		$reordered                        = array(
			'quantity'        => 2,
			'shippingAddress' => array(
				'country' => 'SE',
				'city'    => 'Stockholm',
				'line1'   => 'Storgatan 1',
			),
			'recipient'       => array(
				'email' => 'ada@example.org',
				'name'  => 'Ada Lovelace',
			),
		);
		$moved                            = $mapped;
		$moved['shippingAddress']['city'] = 'Göteborg';

		$this->assertSame( OrderAddress::fingerprint( $mapped ), OrderAddress::fingerprint( $reordered ) );
		$this->assertNotSame( OrderAddress::fingerprint( $mapped ), OrderAddress::fingerprint( $moved ) );
	}

	public function test_the_mapping_can_be_filtered(): void {
		$order = $this->order( null );
		$item  = $this->item();
		Filters\expectApplied( 'pixonaut_wc_fulfilment_order_address' )
			->once()
			->with( Mockery::type( 'array' ), $order, $item )
			->andReturnUsing(
				static function ( array $mapped ): array {
					$mapped['shippingAddress']['line2'] = 'Door code 1234';
					return $mapped;
				}
			);

		$this->assertSame( 'Door code 1234', OrderAddress::of( $order, $item )['shippingAddress']['line2'] ?? null );
	}

	/**
	 * @param array<string, string>|null $shipping The shipping address; null for none.
	 */
	private function order( ?array $shipping ): WC_Order {
		/** @var WC_Order&MockInterface $order */
		$order = Mockery::mock( WC_Order::class );
		$order->allows( 'has_shipping_address' )->andReturn( null !== $shipping );
		$order->allows( 'get_address' )->with( 'shipping' )->andReturn( $shipping ?? array() );
		$order->allows( 'get_address' )->with( 'billing' )->andReturn( self::BILLING );
		$order->allows( 'get_billing_email' )->andReturn( self::BILLING['email'] );
		$order->allows( 'get_billing_phone' )->andReturn( self::BILLING['phone'] );
		return $order;
	}

	private function item(): WC_Order_Item_Product {
		/** @var WC_Order_Item_Product&MockInterface $item */
		$item = Mockery::mock( WC_Order_Item_Product::class );
		return $item;
	}
}
