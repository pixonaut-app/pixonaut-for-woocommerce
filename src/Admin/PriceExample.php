<?php
/**
 * The pricing rule's example on the settings page.
 *
 * @package Pixonaut\WooCommerce
 */

declare(strict_types=1);

namespace Pixonaut\WooCommerce\Admin;

use Pixonaut\WooCommerce\Pricing\Amount;
use Pixonaut\WooCommerce\Pricing\PriceRule;
use Pixonaut\WooCommerce\Pricing\PriceTax;
use Pixonaut\WooCommerce\Pricing\ProductPricing;
use Pixonaut\WooCommerce\Settings\Settings;
use Pixonaut\WooCommerce\Templates;
use WC_Tax;

/**
 * Under the pricing rule's fields on the Prices tab: what the rule prices a
 * few costs at, with the contribution and margin each price leaves. It is
 * drawn from the rule as saved, and the settings script works it out again
 * as the fields change (`price-example.ts`), with the same arithmetic.
 *
 * Products are taxed by their own class; the example uses the standard
 * rate at the shop's base address.
 */
final class PriceExample {

	/**
	 * The settings field's type.
	 */
	public const FIELD_TYPE = 'pixonaut_price_example';

	/**
	 * The costs the example prices, in the store's currency.
	 */
	public const COSTS = array( '100', '300', '600', '1000' );

	public function __construct(
		private readonly ProductPricing $pricing,
		private readonly Templates $templates,
	) {
	}

	public function register(): void {
		add_action( 'woocommerce_admin_field_' . self::FIELD_TYPE, array( $this, 'render' ) );
	}

	/**
	 * The example's settings field, for the settings page.
	 *
	 * @return array<string, mixed>
	 */
	public static function field(): array {
		return array(
			'id'        => 'pixonaut_wc_price_example',
			'type'      => self::FIELD_TYPE,
			'is_option' => false,
		);
	}

	public function render(): void {
		$vat = self::vat_percent();
		$this->templates->render(
			'admin/price-example.php',
			array(
				'rows'   => $this->rows( $this->pricing->rule_with( array() ), $vat ),
				'parts'  => array_map( static fn ( string $setting ): string => Settings::OPTION . '[' . $setting . ']', Settings::PRICE_RULE ),
				'vat'    => $vat,
				'format' => self::format(),
			)
		);
	}

	/**
	 * Each example cost with the price the rule sets for it, and the
	 * contribution and margin that price leaves; empty where no price is set.
	 *
	 * @param PriceRule $rule The rule.
	 * @param float     $vat  The tax rate in the price as entered, in percent; 0 for none.
	 * @return list<array{amount: string, cost: string, price: string, contribution: string, margin: string}>
	 */
	public function rows( PriceRule $rule, float $vat ): array {
		$tax = $vat > 0 ? PriceTax::rate( $vat ) : PriceTax::none();
		return array_map(
			static function ( string $written ) use ( $rule, $tax ): array {
				$cost   = (int) Amount::parse( $written );
				$price  = $rule->price_for( $cost, $tax );
				$margin = null === $price ? null : PriceRule::margin_of( $price, $cost, $tax );
				return array(
					'amount'       => $written,
					'cost'         => PricingPanel::money( $cost ),
					'price'        => null === $price ? '' : PricingPanel::money( $price ),
					'contribution' => null === $price ? '' : PricingPanel::money( PriceRule::contribution_of( $price, $cost, $tax ) ),
					'margin'       => null === $margin ? '' : PricingPanel::percent( $margin ),
				);
			},
			self::COSTS
		);
	}

	/**
	 * The tax rate in a price as the store enters it, at the shop's base
	 * address and the standard class: 0 when prices are entered without tax.
	 */
	public static function vat_percent(): float {
		if ( ! wc_tax_enabled() || ! wc_prices_include_tax() ) {
			return 0.0;
		}
		$rates = WC_Tax::get_base_tax_rates( '' );
		return (float) array_sum( array_map( static fn ( array $rate ): float => (float) ( $rate['rate'] ?? 0 ), $rates ) );
	}

	/**
	 * How the store shows money and a margin, for the settings script.
	 *
	 * @return array{symbol: string, position: string, decimals: int, decimal: string, thousand: string, number_decimal: string}
	 */
	private static function format(): array {
		global $wp_locale;
		return array(
			'symbol'         => html_entity_decode( get_woocommerce_currency_symbol(), ENT_QUOTES, 'UTF-8' ),
			'position'       => (string) get_option( 'woocommerce_currency_pos', 'left' ),
			'decimals'       => wc_get_price_decimals(),
			'decimal'        => wc_get_price_decimal_separator(),
			'thousand'       => wc_get_price_thousand_separator(),
			'number_decimal' => isset( $wp_locale->number_format['decimal_point'] ) ? (string) $wp_locale->number_format['decimal_point'] : '.',
		);
	}
}
