<?php
/**
 * Who a Pixonaut order is shipped to.
 *
 * @package Pixonaut\WooCommerce
 */

declare(strict_types=1);

namespace Pixonaut\WooCommerce\Fulfilment;

use WC_Order;
use WC_Order_Item_Product;

/**
 * Maps a WooCommerce order's shipping address (its billing address when it
 * has none) to the `recipient` and `shippingAddress` of `POST /v1/orders`.
 * Empty fields are left out; a recipient without a name, or an address
 * without a street, city or country, is left out whole.
 */
final class OrderAddress {

	/**
	 * @param WC_Order              $order The order.
	 * @param WC_Order_Item_Product $item  The line being ordered.
	 *
	 * @return array{recipient?: array<string, string>, shippingAddress?: array<string, string>}
	 */
	public static function of( WC_Order $order, WC_Order_Item_Product $item ): array {
		$fields = $order->get_address( $order->has_shipping_address() ? 'shipping' : 'billing' );
		$get    = static fn ( string $field ): string => is_scalar( $fields[ $field ] ?? null ) ? trim( (string) $fields[ $field ] ) : '';

		$name      = trim( $get( 'first_name' ) . ' ' . $get( 'last_name' ) );
		$recipient = self::filled(
			array(
				'name'  => '' === $name ? $get( 'company' ) : $name,
				'email' => trim( $order->get_billing_email() ),
				'phone' => '' === $get( 'phone' ) ? trim( $order->get_billing_phone() ) : $get( 'phone' ),
			)
		);
		$address   = self::filled(
			array(
				'company'    => $get( 'company' ),
				'line1'      => $get( 'address_1' ),
				'line2'      => $get( 'address_2' ),
				'postalCode' => $get( 'postcode' ),
				'city'       => $get( 'city' ),
				'region'     => $get( 'state' ),
				'country'    => strtoupper( $get( 'country' ) ),
			)
		);

		$mapped = array();
		if ( isset( $recipient['name'] ) ) {
			$mapped['recipient'] = $recipient;
		}
		if ( isset( $address['line1'], $address['city'], $address['country'] ) ) {
			$mapped['shippingAddress'] = $address;
		}

		/**
		 * Filters who and where a personalised line is shipped to, as sent
		 * to Pixonaut when its printshop order is placed, and again when the
		 * order's address changes after it was placed. A retry of the same
		 * request sends what was sent first.
		 *
		 * @since 0.1.0
		 *
		 * @param array                 $mapped `recipient` (`name`, `email`, `phone`) and `shippingAddress` (`company`, `line1`, `line2`, `postalCode`, `city`, `region`, `country` as an ISO 3166-1 alpha-2 code); a key is absent when the order did not have it.
		 * @param WC_Order              $order  The order.
		 * @param WC_Order_Item_Product $item   The line being ordered.
		 *
		 * @example
		 * add_filter( 'pixonaut_wc_fulfilment_order_address', function ( $mapped, $order ) {
		 *     $mapped['shippingAddress']['line2'] = 'Door code ' . $order->get_meta( 'door_code' );
		 *     return $mapped;
		 * }, 10, 2 );
		 */
		return (array) apply_filters( 'pixonaut_wc_fulfilment_order_address', $mapped, $order, $item );
	}

	/**
	 * A digest of who and where an order ships, to tell whether an order's
	 * address changed since it was sent without keeping the address itself.
	 * The order of the fields does not count.
	 *
	 * @param array<mixed> $mapped `recipient` and `shippingAddress`, as of() maps them or a request body holds them; other keys are left out.
	 */
	public static function fingerprint( array $mapped ): string {
		$parts = array();
		foreach ( array( 'recipient', 'shippingAddress' ) as $key ) {
			$part = is_array( $mapped[ $key ] ?? null ) ? $mapped[ $key ] : array();
			ksort( $part );
			$parts[ $key ] = $part;
		}
		return hash( 'sha256', (string) wp_json_encode( $parts ) );
	}

	/**
	 * @param array<string, string> $fields Fields.
	 * @return array<string, string> Those that are not empty.
	 */
	private static function filled( array $fields ): array {
		return array_filter( $fields, static fn ( string $value ): bool => '' !== $value );
	}
}
