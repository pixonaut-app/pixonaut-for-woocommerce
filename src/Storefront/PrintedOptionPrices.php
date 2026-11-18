<?php
/**
 * The prices a product page prints in its classic selects' options.
 *
 * @package Pixonaut\WooCommerce
 */

declare(strict_types=1);

namespace Pixonaut\WooCommerce\Storefront;

use Pixonaut\WooCommerce\Settings\Settings;
use WC_Product;

/**
 * Where the store shows product options in the theme's own field rather
 * than as swatches, the classic select of an attribute that shows prices
 * (`ChoicePrices`) prints each option with the price it shows for the choice
 * the form opens on (`ProductChoicePrices`), "50×70 · 599 kr", so the first
 * paint already has them. Each such option carries its own name and the
 * words printed, which the storefront script (`option-prices.ts`) reads to
 * write them again for every other choice: WooCommerce's variation script
 * rebuilds the options from these first ones.
 *
 * @phpstan-import-type Data from ProductChoicePrices as ChoiceData
 */
final class PrintedOptionPrices {

	/**
	 * On an option whose words carry a price: its own name, and the words
	 * written in it. `option-prices.ts` reads the same attributes.
	 */
	public const NAME_ATTRIBUTE  = 'data-pixonaut-name';
	public const SHOWN_ATTRIBUTE = 'data-pixonaut-shown';

	/**
	 * @param InlineProducts      $products Resolves a product to its designer.
	 * @param Settings            $settings The store's attribute options setting.
	 * @param ProductChoicePrices $prices   The prices the options show.
	 */
	public function __construct(
		private readonly InlineProducts $products,
		private readonly Settings $settings,
		private readonly ProductChoicePrices $prices,
	) {
	}

	public function register(): void {
		add_filter( 'woocommerce_dropdown_variation_attribute_options_html', array( $this, 'with_prices' ), 10, 2 );
	}

	/**
	 * The attribute's select, each option with its price.
	 *
	 * @param string $html The select.
	 * @param mixed  $args `wc_dropdown_variation_attribute_options()`'s arguments.
	 */
	public function with_prices( $html, $args ): string {
		$html    = (string) $html;
		$product = is_array( $args ) ? ( $args['product'] ?? null ) : null;
		if ( ! $product instanceof WC_Product || ! $this->prints( $product ) ) {
			return $html;
		}
		$data  = $this->prices->of( $product );
		$field = is_string( $args['attribute'] ?? null ) ? wc_variation_attribute_name( $args['attribute'] ) : '';
		$texts = null === $data || '' === $field ? array() : $this->prices->texts( $product, $field, OpeningChoice::values( $product ) );
		if ( null === $data || array() === $texts ) {
			return $html;
		}
		return self::labelled( $html, $texts, $data );
	}

	/**
	 * `$select` with each option that shows a price in `$texts` written with it.
	 *
	 * @param string                $select The select's markup.
	 * @param array<string, string> $texts  The amount's text each value shows, by value.
	 * @param array<string, mixed>  $data   The product's data, with the label's words.
	 * @phpstan-param ChoiceData $data
	 */
	private static function labelled( string $select, array $texts, array $data ): string {
		return (string) preg_replace_callback(
			'/<option(\s+value="([^"]*)"[^>]*)>(.*?)<\/option>/s',
			static function ( array $option ) use ( $texts, $data ): string {
				$value = html_entity_decode( $option[2], ENT_QUOTES );
				if ( ! isset( $texts[ $value ] ) ) {
					return $option[0];
				}
				$name  = trim( html_entity_decode( wp_strip_all_tags( $option[3] ), ENT_QUOTES ) );
				$shown = ProductChoicePrices::label( $name, $texts[ $value ], $data );
				return '<option' . $option[1] . ' ' . self::NAME_ATTRIBUTE . '="' . esc_attr( $name ) . '" ' . self::SHOWN_ATTRIBUTE . '="' . esc_attr( $shown ) . '">' . esc_html( $shown ) . '</option>';
			},
			$select
		);
	}

	/**
	 * Whether the page is the product's, shows its options in the theme's
	 * own field, and has its designer, which reads the prices the script
	 * writes again.
	 *
	 * @param WC_Product $product The product the field is for.
	 */
	private function prints( WC_Product $product ): bool {
		if ( AttributeOptions::swatches( $this->settings ) || ! is_product() || get_queried_object_id() !== $product->get_id() ) {
			return false;
		}
		$designer = $this->products->for_product( $product );
		return null !== $designer && $designer->available() && array() !== $designer->mounts;
	}
}
