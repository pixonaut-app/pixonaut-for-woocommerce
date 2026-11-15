<?php
/**
 * Keeps the attributes that do not apply to a choice off the cart and order line.
 *
 * @package Pixonaut\WooCommerce
 */

declare(strict_types=1);

namespace Pixonaut\WooCommerce\Cart;

use Pixonaut\WooCommerce\Catalogue\ApplicableAttributes;
use WC_Order_Item_Product;
use WC_Product;

/**
 * WooCommerce asks for a value of every attribute a variation leaves unset,
 * and copies it to the cart line and from there to the order line. An
 * attribute the product page hides (`ApplicableAttributes`) is sent with a
 * value all the same, which the shopper never chose: it is dropped from the
 * line as it joins the cart, and from the cart id, so the same choice is one
 * line whatever value was sent. It is dropped again as the order line is
 * made, which covers a line carted before the plugin did this; carts in
 * customers' sessions are left as they are.
 */
final class InapplicableAttributes {

	/**
	 * How `WC_Order_Item_Product::set_variation()` names a line's meta: the
	 * cart line's field without this.
	 */
	private const FIELD_PREFIX = 'attribute_';

	/**
	 * The arguments `woocommerce_cart_id` passes.
	 */
	private const CART_ID_ARGS = 5;

	/**
	 * The arguments `woocommerce_checkout_create_order_line_item` passes that are read.
	 */
	private const ORDER_LINE_ARGS = 3;

	public function register(): void {
		// First, so every other filter of the id works on the id without the hidden attributes.
		add_filter( 'woocommerce_cart_id', array( $this, 'cart_id' ), PHP_INT_MIN, self::CART_ID_ARGS );
		add_filter( 'woocommerce_add_cart_item', array( $this, 'cart_line' ) );
		add_action( 'woocommerce_checkout_create_order_line_item', array( $this, 'order_line' ), 10, self::ORDER_LINE_ARGS );
	}

	/**
	 * The id of a cart line, worked out without the attributes its choice
	 * hides. The other filters of the id follow this one, and are passed
	 * the choice as it was sent.
	 *
	 * @param mixed $cart_id        The id WooCommerce worked out.
	 * @param mixed $product_id     The product.
	 * @param mixed $variation_id   The variation.
	 * @param mixed $variation      The chosen `attribute_*` values.
	 * @param mixed $cart_item_data The line's other data.
	 * @return mixed
	 */
	public function cart_id( mixed $cart_id, mixed $product_id = 0, mixed $variation_id = 0, mixed $variation = array(), mixed $cart_item_data = array() ): mixed {
		if ( ! is_array( $variation ) || ! is_numeric( $product_id ) ) {
			return $cart_id;
		}
		$applying = self::applying( (int) $product_id, $variation );
		if ( $applying === $variation ) {
			return $cart_id;
		}
		return self::unfiltered_id( $product_id, $variation_id, $applying, $cart_item_data );
	}

	/**
	 * A cart line's id before any filter, as `WC_Cart::generate_cart_id()`
	 * works it out: asking WooCommerce for it from inside its filter would
	 * run every other filter of the id twice. A test holds the two equal.
	 *
	 * @param mixed               $product_id     The product.
	 * @param mixed               $variation_id   The variation.
	 * @param array<mixed, mixed> $variation      The chosen `attribute_*` values.
	 * @param mixed               $cart_item_data The line's other data.
	 */
	public static function unfiltered_id( mixed $product_id, mixed $variation_id, array $variation, mixed $cart_item_data ): string {
		$parts = array( $product_id );
		if ( $variation_id ) {
			$parts[] = $variation_id;
		}
		foreach ( array( $variation, is_array( $cart_item_data ) ? $cart_item_data : array() ) as $data ) {
			if ( array() === $data ) {
				continue;
			}
			$part = '';
			foreach ( $data as $key => $value ) {
				$part .= trim( (string) $key ) . trim( is_array( $value ) || is_object( $value ) ? http_build_query( $value ) : (string) $value );
			}
			$parts[] = $part;
		}
		return md5( implode( '_', $parts ) );
	}

	/**
	 * A line joining the cart, without the attributes its choice hides.
	 *
	 * @param mixed $cart_item The cart item.
	 * @return mixed
	 */
	public function cart_line( mixed $cart_item ): mixed {
		if ( is_array( $cart_item ) && is_array( $cart_item['variation'] ?? null ) && is_numeric( $cart_item['product_id'] ?? null ) ) {
			$cart_item['variation'] = self::applying( (int) $cart_item['product_id'], $cart_item['variation'] );
		}
		return $cart_item;
	}

	/**
	 * Drops from a new order line the attributes its cart line's choice hides.
	 *
	 * @param mixed $item          The new order line.
	 * @param mixed $cart_item_key The cart item's key; unused.
	 * @param mixed $values        The cart item.
	 */
	public function order_line( mixed $item, mixed $cart_item_key, mixed $values ): void {
		unset( $cart_item_key );
		if ( ! $item instanceof WC_Order_Item_Product || ! is_array( $values ) || ! is_array( $values['variation'] ?? null ) || ! is_numeric( $values['product_id'] ?? null ) ) {
			return;
		}
		$applying = self::applying( (int) $values['product_id'], $values['variation'] );
		foreach ( array_keys( array_diff_key( $values['variation'], $applying ) ) as $field ) {
			$item->delete_meta_data( substr( (string) $field, strlen( self::FIELD_PREFIX ) ) );
		}
	}

	/**
	 * A choice without the attributes it hides.
	 *
	 * @param int                 $product_id The variable product.
	 * @param array<mixed, mixed> $variation  The chosen `attribute_*` values.
	 * @return array<mixed, mixed>
	 */
	private static function applying( int $product_id, array $variation ): array {
		if ( array() === $variation ) {
			return $variation;
		}
		$product = wc_get_product( $product_id );
		if ( ! $product instanceof WC_Product ) {
			return $variation;
		}
		$chosen = array_map( 'strval', array_filter( $variation, 'is_scalar' ) );
		return array_diff_key( $variation, array_flip( ApplicableAttributes::hidden_on( $product, $chosen ) ) );
	}
}
