<?php
/**
 * The tax between a price as entered and the price excluding tax.
 *
 * @package Pixonaut\WooCommerce
 */

declare(strict_types=1);

namespace Pixonaut\WooCommerce\Pricing;

use Closure;
use WC_Product;
use WC_Tax;

/**
 * A margin is worked out on the price excluding tax, while the store manager
 * enters prices, and the price steps count, in whatever way the store enters
 * them: including tax or not. This turns one into the other for a product,
 * with WooCommerce's own tax arithmetic at the shop's base address.
 */
final class PriceTax {

	/**
	 * @param Closure $excluding Thousandths as entered to thousandths excluding tax.
	 * @param Closure $entered   Thousandths excluding tax to thousandths as entered.
	 * @phpstan-param Closure(int): int $excluding
	 * @phpstan-param Closure(int): int $entered
	 */
	private function __construct(
		private readonly Closure $excluding,
		private readonly Closure $entered,
	) {
	}

	/**
	 * Prices entered excluding tax, or a product no tax applies to.
	 */
	public static function none(): self {
		return new self( static fn ( int $amount ): int => $amount, static fn ( int $amount ): int => $amount );
	}

	/**
	 * Prices entered including one tax rate.
	 *
	 * @param float $percent The rate, in percent: 25 for 25 %.
	 */
	public static function rate( float $percent ): self {
		$factor = 1 + $percent / 100;
		return new self(
			static fn ( int $amount ): int => (int) round( $amount / $factor ),
			static fn ( int $amount ): int => (int) round( $amount * $factor )
		);
	}

	/**
	 * The tax of a product or variation as the store enters its price: none
	 * when prices are entered excluding tax, taxes are off or the product is
	 * not taxable; else its tax class's rates at the shop's base address.
	 *
	 * @param WC_Product $product The product or variation.
	 */
	public static function of( WC_Product $product ): self {
		if ( ! wc_tax_enabled() || ! wc_prices_include_tax() || ! $product->is_taxable() ) {
			return self::none();
		}
		$rates = WC_Tax::get_base_tax_rates( $product->get_tax_class( 'unfiltered' ) );
		if ( array() === $rates ) {
			return self::none();
		}
		return new self(
			static fn ( int $amount ): int => $amount - Amount::from_units( (float) array_sum( WC_Tax::calc_tax( Amount::units( $amount ), $rates, true ) ) ),
			static fn ( int $amount ): int => $amount + Amount::from_units( (float) array_sum( WC_Tax::calc_tax( Amount::units( $amount ), $rates, false ) ) )
		);
	}

	/**
	 * @param int $entered A price as entered, in thousandths.
	 * @return int The price excluding tax.
	 */
	public function excluding( int $entered ): int {
		return ( $this->excluding )( $entered );
	}

	/**
	 * @param int $excluding A price excluding tax, in thousandths.
	 * @return int The price as the store enters it.
	 */
	public function entered( int $excluding ): int {
		return ( $this->entered )( $excluding );
	}
}
