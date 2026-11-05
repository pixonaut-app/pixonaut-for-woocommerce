<?php
/**
 * Why a personalised product did not join the cart.
 *
 * @package Pixonaut\WooCommerce
 */

declare(strict_types=1);

namespace Pixonaut\WooCommerce\Cart;

use RuntimeException;

/**
 * Carries the label key of the notice the customer sees.
 */
final class AddRefused extends RuntimeException {

	/**
	 * @param string $label           A key from `Labels`.
	 * @param bool   $discard_designs Whether the designs this add committed are deleted: only when they cannot be sold.
	 */
	public function __construct( public readonly string $label, public readonly bool $discard_designs = false ) {
		parent::__construct( sprintf( 'Add to cart refused: %s', $label ) );
	}
}
