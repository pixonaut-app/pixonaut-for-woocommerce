<?php
/**
 * Drops the field values of a cart line whose design was erased.
 *
 * @package Pixonaut\WooCommerce
 */

declare(strict_types=1);

namespace Pixonaut\WooCommerce\Cart;

use Pixonaut\WooCommerce\Proofs\ProofCache;

/**
 * The values a customer chose are their personal data, as the design is.
 * Once a design in a cart had its personal data erased, the side's values
 * are dropped as WooCommerce loads the cart from its session or the
 * customer's persistent cart, so the copy WooCommerce saves next holds
 * none.
 */
final class ErasedFieldValues {

	public function register(): void {
		add_filter( 'woocommerce_get_cart_item_from_session', array( $this, 'forget' ) );
	}

	/**
	 * @param mixed $cart_item The cart item, as loaded from the session.
	 *
	 * @return mixed
	 */
	public function forget( mixed $cart_item ): mixed {
		$line = is_array( $cart_item ) ? CartLine::of( $cart_item ) : null;
		if ( null === $line ) {
			return $cart_item;
		}
		$kept = $line;
		foreach ( $line->sides as $side ) {
			if ( array() !== $side->fields && ProofCache::is_erased( $side->design_id ) ) {
				$kept = $kept->with_side( $side->without_fields(), $kept->consent );
			}
		}
		if ( $kept !== $line ) {
			$cart_item[ CartLine::KEY ] = $kept->to_array();
		}
		return $cart_item;
	}
}
