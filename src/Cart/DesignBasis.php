<?php
/**
 * Drops cart lines whose product changed what their designs were made on.
 *
 * @package Pixonaut\WooCommerce
 */

declare(strict_types=1);

namespace Pixonaut\WooCommerce\Cart;

use Pixonaut\WooCommerce\Catalogue\ProductConfig;
use Pixonaut\WooCommerce\Catalogue\Side;
use WC_Product;

/**
 * WooCommerce stores a hash of each cart item's product data when it is
 * added, and removes the item when the cart loads and the hash no longer
 * matches, telling the customer the product was modified and firing
 * `woocommerce_remove_cart_item_from_session`, on which `CartCleanup`
 * schedules the deletion of its designs. A personalised product's hash
 * takes what its designs are made on: whether it is personalised, its
 * blank, and each side's key, mode, placement and template. Saving one of
 * those differently drops the lines made on the old ones; a price,
 * description or stock change, or a template's new live version, keeps them.
 *
 * A line carted before the basis joined the hash holds a hash without it.
 * Before WooCommerce compares, such a line whose hash still matches its
 * product without the basis takes the hash with it, so updating the plugin
 * drops no cart.
 */
final class DesignBasis {

	/**
	 * The key the basis takes in the hashed data.
	 */
	public const KEY = 'pixonaut';

	/**
	 * Whether the basis is left out of the hash, while a line's hash from before it is computed.
	 *
	 * @var bool
	 */
	private bool $without = false;

	public function register(): void {
		add_filter( 'woocommerce_cart_item_data_to_validate', array( $this, 'data' ), 10, 2 );
		add_action( 'woocommerce_load_cart_from_session', array( $this, 'upgrade' ) );
	}

	/**
	 * Gives the basis to the hash of each personalised line in the session
	 * whose hash was made without it, before WooCommerce compares them.
	 */
	public function upgrade(): void {
		$session = WC()->session;
		// Typed as always set, but null until WooCommerce starts a session.
		$cart = null === $session ? null : $session->get( 'cart' ); // @phpstan-ignore identical.alwaysFalse
		if ( ! is_array( $cart ) ) {
			return;
		}
		$upgraded = false;
		foreach ( $cart as $key => $values ) {
			$stored = is_array( $values ) ? ( $values['data_hash'] ?? null ) : null;
			if ( ! is_string( $stored ) || '' === $stored || null === CartLine::of( $values ) ) {
				continue;
			}
			$product = wc_get_product( (int) ( $values['variation_id'] ?? 0 ) > 0 ? (int) $values['variation_id'] : (int) ( $values['product_id'] ?? 0 ) );
			if ( ! $product instanceof WC_Product ) {
				continue;
			}
			$hash = wc_get_cart_item_data_hash( $product );
			if ( hash_equals( $stored, $hash ) || ! hash_equals( $stored, $this->hash_without_basis( $product ) ) ) {
				continue;
			}
			$cart[ $key ]['data_hash'] = $hash;
			$upgraded                  = true;
		}
		if ( $upgraded ) {
			$session->set( 'cart', $cart );
		}
	}

	/**
	 * WooCommerce's hash of a product without the basis, as it was before the basis joined it.
	 *
	 * @param WC_Product $product The product or variation.
	 */
	private function hash_without_basis( WC_Product $product ): string {
		$this->without = true;
		try {
			return wc_get_cart_item_data_hash( $product );
		} finally {
			$this->without = false;
		}
	}

	/**
	 * Adds a personalised product's basis to the data WooCommerce hashes.
	 *
	 * @param mixed $data    The hashed data.
	 * @param mixed $product The product or variation in the cart.
	 *
	 * @return mixed
	 */
	public function data( mixed $data, mixed $product ): mixed {
		if ( $this->without || ! is_array( $data ) || ! $product instanceof WC_Product ) {
			return $data;
		}
		$config = ProductConfig::config_for( $product );
		if ( ! $config->personalise ) {
			return $data;
		}
		$data[ self::KEY ] = array(
			'blank' => $config->blank_id,
			'sides' => array_map(
				static fn ( Side $side ): array => array( $side->key, $side->mode, $side->placement_id, $side->template_base_id ),
				$config->sides
			),
		);
		return $data;
	}
}
