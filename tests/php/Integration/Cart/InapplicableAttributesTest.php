<?php
/**
 * @package Pixonaut\WooCommerce
 */

declare(strict_types=1);

namespace Pixonaut\WooCommerce\Tests\Integration\Cart;

use Pixonaut\WooCommerce\Tests\Support\DependentPosters;
use WC_Order;
use WC_Order_Item_Product;
use WC_Product_Variable;
use WP_UnitTestCase;

/**
 * The form sends a value for every attribute a variation leaves unset, the
 * hidden ones too.
 */
final class InapplicableAttributesTest extends WP_UnitTestCase {

	use DependentPosters;

	private const CHECKOUT = array(
		'payment_method'     => 'cod',
		'billing_first_name' => 'Ada',
		'billing_last_name'  => 'Lovelace',
		'billing_email'      => 'ada@example.com',
		'billing_country'    => 'SE',
		'billing_address_1'  => 'Storgatan 1',
		'billing_city'       => 'Stockholm',
		'billing_postcode'   => '11122',
	);

	private WC_Product_Variable $poster;

	public function set_up(): void {
		parent::set_up();
		$this->set_up_dependent_posters();
		$this->poster = $this->dependent_poster();
		WC()->initialize_session();
		WC()->initialize_cart();
		WC()->cart->empty_cart();
		wc_clear_notices();
	}

	public function tear_down(): void {
		WC()->cart->empty_cart();
		wc_clear_notices();
		$this->tear_down_dependent_posters();
		parent::tear_down();
	}

	/**
	 * Adds one of the poster's variations with the values the form sent.
	 *
	 * @param string                $variation One of the keys of `$poster_sold`.
	 * @param array<string, string> $sent      Values by attribute name.
	 * @return string The cart line's key.
	 */
	private function add( string $variation, array $sent ): string {
		$values = array();
		foreach ( $sent as $name => $slug ) {
			$values[ self::poster_field( $name ) ] = $slug;
		}
		$key = WC()->cart->add_to_cart( $this->poster->get_id(), 1, $this->poster_variations[ $variation ]->get_id(), $values );
		$this->assertIsString( $key, implode( ' ', array_column( wc_get_notices( 'error' ), 'notice' ) ) );
		return $key;
	}

	/**
	 * Places the cart through the classic checkout.
	 */
	private function order_line(): WC_Order_Item_Product {
		$order_id = WC()->checkout()->create_order( self::CHECKOUT );
		$this->assertIsInt( $order_id );
		$order = wc_get_order( $order_id );
		$this->assertInstanceOf( WC_Order::class, $order );
		$item = current( $order->get_items() );
		$this->assertInstanceOf( WC_Order_Item_Product::class, $item );
		return $item;
	}

	public function test_a_bare_posters_line_holds_no_option_of_an_extra(): void {
		$key = $this->add(
			'ingen',
			array(
				'Inramning'  => 'ingen',
				'Hangarfarg' => 'ek',
				'Ramfarg'    => 'svart',
				'Canvasdjup' => '2-cm',
			)
		);

		$this->assertSame( array( self::poster_field( 'Inramning' ) => 'ingen' ), WC()->cart->get_cart()[ $key ]['variation'] );
	}

	public function test_a_line_keeps_the_option_of_its_own_extra(): void {
		$key = $this->add(
			'hangare-ek',
			array(
				'Inramning'  => 'hangare',
				'Hangarfarg' => 'ek',
				'Ramfarg'    => 'vit',
				'Canvasdjup' => '4-cm',
			)
		);

		$this->assertSame(
			array(
				self::poster_field( 'Inramning' )  => 'hangare',
				self::poster_field( 'Hangarfarg' ) => 'ek',
			),
			WC()->cart->get_cart()[ $key ]['variation']
		);
	}

	public function test_the_same_choice_is_one_line_whatever_filled_the_hidden_attributes(): void {
		$first  = $this->add(
			'ingen',
			array(
				'Inramning'  => 'ingen',
				'Hangarfarg' => 'ek',
				'Ramfarg'    => 'svart',
				'Canvasdjup' => '2-cm',
			)
		);
		$second = $this->add(
			'ingen',
			array(
				'Inramning'  => 'ingen',
				'Hangarfarg' => 'svart',
				'Ramfarg'    => 'vit',
				'Canvasdjup' => '4-cm',
			)
		);

		$this->assertSame( $first, $second );
		$this->assertCount( 1, WC()->cart->get_cart() );
		$this->assertSame( 2, WC()->cart->get_cart()[ $first ]['quantity'] );
	}

	public function test_a_free_choice_stays_on_the_line(): void {
		// Every variation leaves the frame colour unset: it is the shopper's to choose.
		foreach ( array( 'ram-svart', 'ram-vit' ) as $framed ) {
			$attributes = $this->poster_variations[ $framed ]->get_attributes();
			unset( $attributes[ wc_attribute_taxonomy_name( 'Ramfarg' ) ] );
			$this->poster_variations[ $framed ]->set_attributes( $attributes );
			$this->poster_variations[ $framed ]->save();
		}

		$key = $this->add(
			'ingen',
			array(
				'Inramning'  => 'ingen',
				'Hangarfarg' => 'ek',
				'Ramfarg'    => 'vit',
				'Canvasdjup' => '2-cm',
			)
		);

		$this->assertSame(
			array(
				self::poster_field( 'Inramning' ) => 'ingen',
				self::poster_field( 'Ramfarg' )   => 'vit',
			),
			WC()->cart->get_cart()[ $key ]['variation']
		);
	}

	public function test_the_order_line_holds_no_option_of_an_extra(): void {
		$this->add(
			'ingen',
			array(
				'Inramning'  => 'ingen',
				'Hangarfarg' => 'ek',
				'Ramfarg'    => 'svart',
				'Canvasdjup' => '2-cm',
			)
		);

		$item = $this->order_line();

		$this->assertSame( 'ingen', $item->get_meta( wc_attribute_taxonomy_name( 'Inramning' ) ) );
		foreach ( array( 'Hangarfarg', 'Ramfarg', 'Canvasdjup' ) as $name ) {
			$this->assertFalse( $item->meta_exists( wc_attribute_taxonomy_name( $name ) ), $name );
		}
	}

	public function test_a_line_carted_with_them_loses_them_as_it_is_ordered(): void {
		$key = $this->add(
			'ingen',
			array(
				'Inramning'  => 'ingen',
				'Hangarfarg' => 'ek',
				'Ramfarg'    => 'svart',
				'Canvasdjup' => '2-cm',
			)
		);
		// As a cart from before the plugin dropped them holds the line.
		WC()->cart->cart_contents[ $key ]['variation'][ self::poster_field( 'Hangarfarg' ) ] = 'ek';
		WC()->cart->cart_contents[ $key ]['variation'][ self::poster_field( 'Canvasdjup' ) ] = '2-cm';

		$item = $this->order_line();

		$this->assertSame( 'ingen', $item->get_meta( wc_attribute_taxonomy_name( 'Inramning' ) ) );
		$this->assertFalse( $item->meta_exists( wc_attribute_taxonomy_name( 'Hangarfarg' ) ) );
		$this->assertFalse( $item->meta_exists( wc_attribute_taxonomy_name( 'Canvasdjup' ) ) );
	}
}
