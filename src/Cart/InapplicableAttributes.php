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

	/**
	 * Whether the cart id is being worked out again without the hidden attributes.
	 *
	 * @var bool
	 */
	private bool $generating = false;

	/**
	 * The id WooCommerce worked out without the hidden attributes, before any filter.
	 *
	 * @var string
	 */
	private string $without_hidden = '';

	public function register(): void {
		// First, so every other filter of the id runs once, on the id without the hidden attributes.
		add_filter( 'woocommerce_cart_id', array( $this, 'cart_id' ), PHP_INT_MIN, self::CART_ID_ARGS );
		add_filter( 'woocommerce_add_cart_item', array( $this, 'cart_line' ) );
		add_action( 'woocommerce_checkout_create_order_line_item', array( $this, 'order_line' ), 10, self::ORDER_LINE_ARGS );
	}

	/**
	 * The id of a cart line, as WooCommerce works it out without the
	 * attributes its choice hides. WooCommerce is asked for that id again,
	 * and what it worked out before any filter is taken: the other filters
	 * of the id then run on it once, after this one.
	 *
	 * @param mixed $cart_id        The id WooCommerce worked out.
	 * @param mixed $product_id     The product.
	 * @param mixed $variation_id   The variation.
	 * @param mixed $variation      The chosen `attribute_*` values.
	 * @param mixed $cart_item_data The line's other data.
	 * @return mixed
	 */
	public function cart_id( mixed $cart_id, mixed $product_id = 0, mixed $variation_id = 0, mixed $variation = array(), mixed $cart_item_data = array() ): mixed {
		if ( $this->generating ) {
			$this->without_hidden = is_string( $cart_id ) ? $cart_id : '';
			return $cart_id;
		}
		if ( ! is_array( $variation ) || ! is_numeric( $product_id ) || ! function_exists( 'WC' ) || null === WC()->cart ) {
			return $cart_id;
		}
		$applying = self::applying( (int) $product_id, $variation );
		if ( $applying === $variation ) {
			return $cart_id;
		}
		$this->generating = true;
		try {
			WC()->cart->generate_cart_id( (int) $product_id, is_numeric( $variation_id ) ? (int) $variation_id : 0, $applying, is_array( $cart_item_data ) ? $cart_item_data : array() );
		} finally {
			$this->generating = false;
		}
		$without_hidden       = $this->without_hidden;
		$this->without_hidden = '';
		return '' === $without_hidden ? $cart_id : $without_hidden;
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
