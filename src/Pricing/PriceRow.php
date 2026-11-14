<?php
/**
 * One price of a product, as the pricing panel lists it.
 *
 * @package Pixonaut\WooCommerce
 */

declare(strict_types=1);

namespace Pixonaut\WooCommerce\Pricing;

/**
 * A product's or variation's cost, price and margin, how its price stands
 * to the pricing rule, and what the rule would change. Money is in
 * thousandths (`Amount`).
 */
final class PriceRow {

	/**
	 * @param int              $id         The product or variation.
	 * @param string           $label      What it is called: a variation's attribute values, a product's name.
	 * @param VariantCost|null $cost       Its cost as last read; null when none is known.
	 * @param int|null         $cost_total The product and its shipping in the store's currency, converted when charged in another; null when they price nothing.
	 * @param int|null         $price      Its regular price; null for none.
	 * @param float|null       $margin     The margin its price leaves, in percent of the price excluding tax.
	 * @param int|null         $suggested  The price the rule would set, when it is another.
	 * @param string           $standing   `PriceState::OWN`, `FOLLOWS` or `BY_HAND`.
	 * @param string|null      $flag       Why it needs the store manager, a `PriceState::FLAG_` constant.
	 * @param int|null         $rule_at    When the rule last set its price, a Unix time.
	 * @param int|null         $checked_at When its cost was last asked for, a Unix time; null when it never was.
	 * @param string           $rate_date  The day of the exchange rates its cost was converted with; empty when it was not converted.
	 * @param string           $buffer     The currency buffer added to what was converted, in percent as written; empty when nothing was.
	 */
	public function __construct(
		public readonly int $id,
		public readonly string $label,
		public readonly ?VariantCost $cost,
		public readonly ?int $cost_total,
		public readonly ?int $price,
		public readonly ?float $margin,
		public readonly ?int $suggested,
		public readonly string $standing,
		public readonly ?string $flag,
		public readonly ?int $rule_at,
		public readonly ?int $checked_at,
		public readonly string $rate_date = '',
		public readonly string $buffer = '',
	) {
	}
}
