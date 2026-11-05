<?php
/**
 * Adds a design to the cart on the customer's own return from the hosted designer.
 *
 * @package Pixonaut\WooCommerce
 */

declare(strict_types=1);

namespace Pixonaut\WooCommerce\Storefront;

use Pixonaut\WooCommerce\Cart\AddToCart;
use Pixonaut\WooCommerce\Cart\CartEdit;
use Pixonaut\WooCommerce\Settings\Settings;
use WC_Form_Handler;
use WC_Product;

/**
 * With the `add_after_approve` setting on, a return whose `state` matches the
 * trip the session has open (`DesignerVisits`) adds the design to the cart as
 * WooCommerce's own form does: the kept design's key is posted to
 * WooCommerce's form handler, so every extension's validation applies and the
 * handler says it was added. Any other return never comes here.
 */
final class AddAfterApprove {

	/**
	 * Answers where WooCommerce's form handler goes after an add.
	 */
	private const CART_REDIRECT_FILTER = 'woocommerce_add_to_cart_redirect';

	/**
	 * Answers WooCommerce's "Redirect to the cart page after successful addition" option.
	 */
	private const CART_REDIRECT_OPTION_FILTER = 'pre_option_woocommerce_cart_redirect_after_add';

	/**
	 * Fires with the new cart item's key once WooCommerce has added it.
	 */
	private const ADDED_ACTION = 'woocommerce_add_to_cart';

	/**
	 * The key of the cart item added while the handler ran, if any.
	 *
	 * @var string|null
	 */
	private ?string $added_key = null;

	/**
	 * @param Settings $settings Whether approving adds to the cart.
	 */
	public function __construct( private readonly Settings $settings ) {
	}

	/**
	 * Whether the store adds the design of the customer's own return.
	 */
	public function on(): bool {
		return true === $this->settings->get( Settings::ADD_AFTER_APPROVE );
	}

	/**
	 * Adds a kept design to the cart through WooCommerce's form handler.
	 *
	 * @param WC_Product            $product    The product or variation.
	 * @param string                $kept_key   The kept design's key.
	 * @param int                   $quantity   How many.
	 * @param array<string, string> $attributes The chosen `attribute_*` values.
	 *
	 * @return string|null The added line's page; null when the add was refused, and a notice says why.
	 */
	public function add( WC_Product $product, string $kept_key, int $quantity, array $attributes ): ?string {
		$this->added_key = null;
		$listen          = function ( mixed $cart_item_key ): void {
			$this->added_key = is_string( $cart_item_key ) ? $cart_item_key : null;
		};
		add_action( self::ADDED_ACTION, $listen );
		try {
			$this->post_to_form_handler( $product, $kept_key, $quantity, $attributes );
		} finally {
			remove_action( self::ADDED_ACTION, $listen );
		}
		$cart_key = $this->added_key;
		return null === $cart_key ? null : CartEdit::line_page( WC()->cart->get_cart_item( $cart_key ), $cart_key );
	}

	/**
	 * Runs WooCommerce's form handler on the add-to-cart form's fields, and
	 * puts the request as it was.
	 *
	 * @param WC_Product            $product    The product or variation.
	 * @param string                $kept_key   The kept design's key.
	 * @param int                   $quantity   How many.
	 * @param array<string, string> $attributes The chosen `attribute_*` values.
	 */
	private function post_to_form_handler( WC_Product $product, string $kept_key, int $quantity, array $attributes ): void {
		// phpcs:disable WordPress.Security.NonceVerification, WordPress.Security.ValidatedSanitizedInput -- put back untouched; nothing here reads them.
		$request = $_REQUEST;
		$post    = $_POST;
		// phpcs:enable WordPress.Security.NonceVerification, WordPress.Security.ValidatedSanitizedInput
		$parent_id = $product->get_parent_id();

		$_REQUEST['add-to-cart'] = (string) ( $parent_id > 0 ? $parent_id : $product->get_id() );
		$_REQUEST['quantity']    = (string) $quantity;
		if ( $parent_id > 0 ) {
			$_REQUEST['variation_id'] = (string) $product->get_id();
		}
		// The handler unslashes what it reads, as WordPress slashes every request.
		foreach ( wp_slash( $attributes ) as $name => $value ) {
			$_REQUEST[ $name ] = $value;
		}
		$_POST[ AddToCart::KEPT_FIELD ] = $kept_key;

		// The handler would redirect, to the line's page or to the cart on a store set to go there.
		$stay = static fn (): string => 'no';
		add_filter( self::CART_REDIRECT_OPTION_FILTER, $stay );
		add_filter( self::CART_REDIRECT_FILTER, '__return_empty_string', PHP_INT_MAX );
		try {
			WC_Form_Handler::add_to_cart_action();
		} finally {
			remove_filter( self::CART_REDIRECT_OPTION_FILTER, $stay );
			remove_filter( self::CART_REDIRECT_FILTER, '__return_empty_string', PHP_INT_MAX );
			$_REQUEST = $request;
			$_POST    = $post;
		}
	}
}
