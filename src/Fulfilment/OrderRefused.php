<?php
/**
 * Pixonaut refused a printshop order placed for several lines.
 *
 * @package Pixonaut\WooCommerce
 */

declare(strict_types=1);

namespace Pixonaut\WooCommerce\Fulfilment;

use Pixonaut\WooCommerce\Vendor\Pixonaut\Sdk\Exception\ApiException;
use RuntimeException;
use WC_Order_Item_Product;

/**
 * The lines of an order a refusal of `POST /v1/orders` is about. A refusal
 * naming one item (`itemIndex`) is that line's alone, and the other lines are
 * placed again without it; any other is about every line the order held.
 */
final class OrderRefused extends RuntimeException {

	/**
	 * @param ApiException                $error The refusal.
	 * @param list<WC_Order_Item_Product> $lines The lines it is about.
	 * @param bool                        $whole Whether it refused the order as a whole rather than one item of it.
	 */
	public function __construct(
		public readonly ApiException $error,
		public readonly array $lines,
		public readonly bool $whole,
	) {
		parent::__construct( $error->getMessage(), 0, $error );
	}
}
