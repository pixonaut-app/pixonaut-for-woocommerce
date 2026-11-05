<?php
/**
 * What still names a custom attribute its product was moved off.
 *
 * @package Pixonaut\WooCommerce
 */

declare(strict_types=1);

namespace Pixonaut\WooCommerce\Storefront;

use Pixonaut\WooCommerce\Catalogue\MovedAttributes;
use WC_Product;
use WP_Http;

/**
 * A link with `attribute_storlek=`, in an email or a bookmark, goes on
 * selecting the same size once the product is on `pa_storlek`. A cart line
 * made before the move stays, as WooCommerce would otherwise remove it as
 * modified, and keeps its size under the attribute's new name, so it shows
 * and is ordered as a new one would.
 */
final class MovedAttributeLinks {

	public function register(): void {
		add_action( 'template_redirect', array( $this, 'redirect' ) );
		add_filter( 'woocommerce_get_cart_item_from_session', array( $this, 'cart_line' ) );
		add_filter( 'woocommerce_cart_item_data_to_validate', array( $this, 'cart_hash_data' ), 10, 2 );
	}

	/**
	 * Sends a link naming a moved attribute to the one naming the new.
	 */
	public function redirect(): void {
		if ( ! is_product() ) {
			return;
		}
		$product = wc_get_product( get_queried_object_id() );
		$uri     = isset( $_SERVER['REQUEST_URI'] ) ? esc_url_raw( wp_unslash( $_SERVER['REQUEST_URI'] ) ) : '';
		// Selects an option on a public page; changes nothing.
		$url = $product instanceof WC_Product ? self::moved_url( $product, $uri, wp_unslash( $_GET ) ) : null; // phpcs:ignore WordPress.Security.NonceVerification.Recommended, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- only its attribute_* values are read, each sanitised by add_query_arg's encoding below.
		if ( null !== $url && wp_safe_redirect( $url, WP_Http::MOVED_PERMANENTLY, 'Pixonaut for WooCommerce' ) ) {
			exit;
		}
	}

	/**
	 * The address a link should have instead, or null when it names no moved
	 * attribute.
	 *
	 * @param WC_Product          $product The product the page shows.
	 * @param string              $url     The link.
	 * @param array<mixed, mixed> $query   Its query parameters.
	 */
	public static function moved_url( WC_Product $product, string $url, array $query ): ?string {
		$attributes = array();
		foreach ( $query as $key => $value ) {
			if ( is_string( $key ) && is_string( $value ) && str_starts_with( $key, MovedAttributes::PREFIX ) ) {
				$attributes[ $key ] = sanitize_text_field( $value );
			}
		}
		$translated = MovedAttributes::translated( $product->get_id(), $attributes );
		$gone       = array_keys( array_diff_key( $attributes, $translated ) );
		if ( array() === $gone ) {
			return null;
		}
		return add_query_arg( urlencode_deep( array_diff_key( $translated, $attributes ) ), remove_query_arg( $gone, $url ) );
	}

	/**
	 * The data WooCommerce hashes into a cart line, with a variation's
	 * attributes as they were before its product's moves.
	 *
	 * @param mixed $data    The hashed data.
	 * @param mixed $product The product or variation in the cart.
	 * @return mixed
	 */
	public function cart_hash_data( mixed $data, mixed $product ): mixed {
		if ( is_array( $data ) && $product instanceof WC_Product && is_array( $data['attributes'] ?? null ) ) {
			$data['attributes'] = MovedAttributes::as_before( $product->get_parent_id(), $data['attributes'], $product->get_id() );
		}
		return $data;
	}

	/**
	 * A cart line as the product names its attributes now.
	 *
	 * @param mixed $line The line, read from the session.
	 * @return mixed
	 */
	public function cart_line( mixed $line ): mixed {
		if ( is_array( $line ) && is_array( $line['variation'] ?? null ) && array() !== $line['variation'] ) {
			$line['variation'] = MovedAttributes::translated( (int) ( $line['product_id'] ?? 0 ), $line['variation'] );
		}
		return $line;
	}
}
