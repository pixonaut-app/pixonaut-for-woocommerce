<?php
/**
 * A printshop cost, in the store's currency.
 *
 * @package Pixonaut\WooCommerce
 */

declare(strict_types=1);

namespace Pixonaut\WooCommerce\Pricing;

/**
 * What a blank variant costs the store: the printshop's product price and
 * shipping, each converted to the store's currency when it is charged in
 * another, with the store's currency buffer added to what was converted.
 */
final class StoreCost {

	/**
	 * @param int|null    $total     The cost in the store's currency, in thousandths; null when it prices nothing.
	 * @param string|null $flag      Why it prices nothing, a `PriceState::FLAG_` constant; null when it does.
	 * @param string      $rate_date The day of the exchange rates it was converted with; empty when nothing was converted.
	 * @param string      $buffer    The currency buffer added to what was converted, in percent as written; empty when nothing was converted.
	 */
	public function __construct(
		public readonly ?int $total,
		public readonly ?string $flag = null,
		public readonly string $rate_date = '',
		public readonly string $buffer = '',
	) {
	}
}
