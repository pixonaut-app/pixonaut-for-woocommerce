<?php
/**
 * Names a template's new id on the cart lines made from it.
 *
 * @package Pixonaut\WooCommerce
 */

declare(strict_types=1);

namespace Pixonaut\WooCommerce\Cart;

use Pixonaut\WooCommerce\Catalogue\TemplateRenames;

/**
 * Cart lines live in customers' sessions and persistent carts, which a
 * `template.renamed` event cannot reach. As WooCommerce loads a cart, each
 * side made from a template whose id Pixonaut changed takes the id the
 * template has now, so the copy WooCommerce saves next, and the order line
 * made from it, name the new one.
 */
final class CartTemplateRenames {

	public function register(): void {
		add_filter( 'woocommerce_get_cart_item_from_session', array( $this, 'current' ) );
	}

	/**
	 * @param mixed $cart_item The cart item, as loaded from the session.
	 *
	 * @return mixed
	 */
	public function current( mixed $cart_item ): mixed {
		$line = is_array( $cart_item ) ? CartLine::of( $cart_item ) : null;
		if ( null === $line ) {
			return $cart_item;
		}
		$kept = $line;
		foreach ( $line->sides as $side ) {
			$current = TemplateRenames::current( $side->template_base_id );
			if ( $current !== $side->template_base_id ) {
				$kept = $kept->with_side( $side->on_template( $current ), $kept->consent );
			}
		}
		if ( $kept !== $line ) {
			$cart_item[ CartLine::KEY ] = $kept->to_array();
		}
		return $cart_item;
	}
}
