<?php
/**
 * The store's abilities another plugin may offer as its own tools.
 *
 * @package Pixonaut\WooCommerce
 */

declare(strict_types=1);

namespace Pixonaut\WooCommerce\Bridge;

use Pixonaut\WooCommerce\Abilities\Abilities;

/**
 * What `pixonaut_wc_abilities()` answers: every ability the plugin
 * registers, and WooCommerce's product and order ones, with no cap on their
 * number.
 */
final class Discovery {

	private const WOOCOMMERCE_PREFIXES = array( 'woocommerce/product', 'woocommerce/order' );

	/**
	 * @return list<string> Ability names, e.g. `pixonaut-for-woocommerce/coupon-create`.
	 */
	public function abilities(): array {
		$names = array();
		foreach ( wp_get_abilities() as $ability ) {
			if ( self::offered( $ability->get_name() ) ) {
				$names[] = $ability->get_name();
			}
		}

		/**
		 * Filters the abilities `pixonaut_wc_abilities()` answers another
		 * plugin with, such as an MCP bridge offering them as its tools.
		 *
		 * Each still runs as the current user, after its own permission
		 * check.
		 *
		 * @since 0.1.0
		 *
		 * @param list<string> $abilities Ability names, e.g. `pixonaut-for-woocommerce/order-get`.
		 *
		 * @example
		 * add_filter( 'pixonaut_wc_bridge_abilities', function ( $abilities ) {
		 *     return array_values( array_diff( $abilities, array( 'woocommerce/product-delete' ) ) );
		 * } );
		 */
		$names = (array) apply_filters( 'pixonaut_wc_bridge_abilities', $names );
		return array_values( array_unique( array_map( 'strval', $names ) ) );
	}

	/**
	 * @param string $name An ability's name.
	 */
	private static function offered( string $name ): bool {
		if ( str_starts_with( $name, Abilities::NAMESPACE . '/' ) ) {
			return true;
		}
		foreach ( self::WOOCOMMERCE_PREFIXES as $prefix ) {
			if ( str_starts_with( $name, $prefix ) ) {
				return true;
			}
		}
		return false;
	}
}
