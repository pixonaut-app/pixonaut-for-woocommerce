<?php
/**
 * Turns a printshop cost into the store's currency.
 *
 * @package Pixonaut\WooCommerce
 */

declare(strict_types=1);

namespace Pixonaut\WooCommerce\Pricing;

use Pixonaut\WooCommerce\Settings\Settings;

/**
 * Pixonaut reports a cost in the printshop's billing currency, never
 * converted. A part of it charged in another currency than the store's is
 * converted with the day's exchange rate (`ExchangeRates`), and the store's
 * currency buffer is added to it: what the card or bank takes for the
 * exchange, and what the rate may move between the sale and the charge.
 */
final class CostConverter {

	/**
	 * A buffer is a share of the converted cost, in percent.
	 */
	private const PERCENT = 100;

	public function __construct(
		private readonly ExchangeRates $rates,
		private readonly Settings $settings,
	) {
	}

	/**
	 * Reads the day's exchange rates when the ones kept are a day old, and
	 * one of the costs is charged in another currency than the store's: a
	 * store whose printshops charge in its own currency asks for none.
	 *
	 * @param array<VariantCost|null> $costs The costs about to be converted.
	 */
	public function refresh_for( array $costs ): void {
		$store = get_woocommerce_currency();
		foreach ( $costs as $cost ) {
			if ( null !== $cost && ( ( null !== $cost->product && $cost->currency !== $store ) || ( null !== $cost->shipping && $cost->shipping_currency !== $store ) ) ) {
				$this->rates->refresh();
				return;
			}
		}
	}

	/**
	 * A cost in the store's currency: the product and its shipping, each
	 * converted when it is charged in another currency. It prices nothing
	 * without a cost, without a published price, without an exchange rate
	 * for its currency, or with rates over a week old.
	 *
	 * @param VariantCost|null $cost The cost as Pixonaut reported it.
	 */
	public function in_store( ?VariantCost $cost ): StoreCost {
		if ( null === $cost ) {
			return new StoreCost( null, PriceState::FLAG_UNREAD );
		}
		if ( null === $cost->product ) {
			return new StoreCost( null, PriceState::FLAG_NO_COST );
		}
		$store     = get_woocommerce_currency();
		$total     = 0;
		$converted = false;
		foreach ( array( array( $cost->product, $cost->currency ), array( $cost->shipping, $cost->shipping_currency ) ) as list( $amount, $currency ) ) {
			if ( null === $amount ) {
				continue;
			}
			if ( $currency === $store ) {
				$total += $amount;
				continue;
			}
			$rate = $this->rates->rate( $currency, $store );
			if ( null === $rate ) {
				return new StoreCost( null, $this->rates->is_stale() ? PriceState::FLAG_RATE_STALE : PriceState::FLAG_CURRENCY );
			}
			$total    += (int) round( $amount * $rate * ( 1 + $this->buffer_percent() / self::PERCENT ) );
			$converted = true;
		}
		return $converted ? new StoreCost( $total, null, $this->rates->date(), $this->buffer() ) : new StoreCost( $total );
	}

	/**
	 * The currency buffer, in percent as the setting writes it.
	 */
	private function buffer(): string {
		$written = $this->settings->get( Settings::PRICE_CURRENCY_BUFFER );
		return is_string( $written ) && '' !== $written ? $written : '0';
	}

	private function buffer_percent(): float {
		return Amount::units( Amount::parse( $this->buffer() ) ?? 0 );
	}
}
