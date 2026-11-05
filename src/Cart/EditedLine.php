<?php
/**
 * A cart line whose design a product page edits.
 *
 * @package Pixonaut\WooCommerce
 */

declare(strict_types=1);

namespace Pixonaut\WooCommerce\Cart;

final class EditedLine {

	/**
	 * @param string   $key          The cart item's key.
	 * @param int      $product_id   The product (the parent of a variation) whose page edits it.
	 * @param CartLine $line         Its Pixonaut data.
	 * @param int      $variation_id The variation bought; 0 for a product without variations.
	 */
	public function __construct(
		public readonly string $key,
		public readonly int $product_id,
		public readonly CartLine $line,
		public readonly int $variation_id = 0,
	) {
	}
}
