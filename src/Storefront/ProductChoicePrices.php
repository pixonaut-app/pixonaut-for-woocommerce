<?php
/**
 * What a product page prints for the prices beside its attributes' swatches.
 *
 * @package Pixonaut\WooCommerce
 */

declare(strict_types=1);

namespace Pixonaut\WooCommerce\Storefront;

use Pixonaut\WooCommerce\Catalogue\ApplicableAttributes;
use WC_Product;
use WC_Product_Attribute;
use WC_Product_Variable;

/**
 * For a variable product with an attribute whose choices show a price
 * (`ChoicePrices`), what the storefront script works the amounts out from
 * (`choice-prices.ts`): the product's attribute fields, each one's role,
 * each variation's values and its price as the shop shows it (taxes
 * included or not, as the store's setting says), and how the shop writes a
 * price. The swatches the page prints for the choice it opens on carry the
 * same amounts, written the same way (`text()`).
 *
 * @phpstan-import-type PricedVariation from ChoicePriceTable
 * @phpstan-import-type Amount from ChoicePriceTable
 * @phpstan-type Format array{pattern: string, symbol: string, decimalSeparator: string, thousandSeparator: string}
 * @phpstan-type Data array{fields: list<string>, roles: array<string, string>, decimals: int, variations: list<PricedVariation>, format: Format, words: array<string, string>}
 */
final class ProductChoicePrices {

	/**
	 * The data of each product read so far, by product id; null for none.
	 *
	 * @var array<int, array<string, mixed>|null>
	 * @phpstan-var array<int, Data|null>
	 */
	private array $data = array();

	/**
	 * What the page prints for the product; null when none of its attributes
	 * shows a price.
	 *
	 * @param WC_Product $product A product.
	 * @return array<string, mixed>|null
	 * @phpstan-return Data|null
	 */
	public function of( WC_Product $product ): ?array {
		$id = $product->get_id();
		if ( ! array_key_exists( $id, $this->data ) ) {
			$this->data[ $id ] = self::read( $product );
		}
		return $this->data[ $id ];
	}

	/**
	 * The text each value of one attribute shows beside its swatch for a
	 * choice, by value; a value that shows none is left out.
	 *
	 * @param WC_Product            $product A product.
	 * @param string                $field   The attribute's field, `attribute_pa_frame`.
	 * @param array<string, string> $chosen  The chosen values by field.
	 * @return array<string, string>
	 */
	public function texts( WC_Product $product, string $field, array $chosen ): array {
		$data = $this->of( $product );
		if ( null === $data || ! isset( $data['roles'][ $field ] ) ) {
			return array();
		}
		$texts = array();
		foreach ( ChoicePriceTable::amounts( $data['fields'], $data['roles'], $data['variations'], $chosen, $data['decimals'] )[ $field ] ?? array() as $value => $amount ) {
			$texts[ (string) $value ] = self::text( $amount, $data );
		}
		return $texts;
	}

	/**
	 * An amount's words around the price as the shop writes it, as
	 * `choice-prices.ts`'s `amountText()` writes it.
	 *
	 * @param array<string, mixed> $amount The amount.
	 * @param array<string, mixed> $data   The product's data, with the format and words.
	 * @phpstan-param Amount $amount
	 * @phpstan-param Data $data
	 */
	public static function text( array $amount, array $data ): string {
		$format = $data['format'];
		$number = number_format( $amount['amount'], $data['decimals'], $format['decimalSeparator'], $format['thousandSeparator'] );
		$price  = str_replace( array( '%1$s', '%2$s' ), array( $format['symbol'], $number ), $format['pattern'] );
		return str_replace( '%s', $price, $data['words'][ $amount['kind'] ] ?? '%s' );
	}

	/**
	 * @param WC_Product $product A product.
	 * @return array<string, mixed>|null
	 * @phpstan-return Data|null
	 */
	private static function read( WC_Product $product ): ?array {
		if ( ! $product instanceof WC_Product_Variable ) {
			return null;
		}
		$roles = array();
		foreach ( $product->get_attributes() as $attribute ) {
			if ( ! $attribute instanceof WC_Product_Attribute || ! $attribute->get_variation() ) {
				continue;
			}
			$role = ChoicePrices::of_name( $attribute->get_name() );
			if ( ChoicePrices::NONE !== $role ) {
				$roles[ 'attribute_' . sanitize_title( $attribute->get_name() ) ] = $role;
			}
		}
		if ( array() === $roles ) {
			return null;
		}
		return array(
			'fields'     => ApplicableAttributes::fields( $product ),
			'roles'      => $roles,
			'decimals'   => wc_get_price_decimals(),
			'variations' => ProductPageCache::remember( $product, 'choice_prices', '', static fn (): array => self::variations( $product ) ),
			'format'     => array(
				'pattern'           => self::decoded( get_woocommerce_price_format() ),
				'symbol'            => self::decoded( get_woocommerce_currency_symbol() ),
				'decimalSeparator'  => wc_get_price_decimal_separator(),
				'thousandSeparator' => self::decoded( wc_get_price_thousand_separator() ),
			),
			'words'      => array(
				/* translators: %s: a price, such as "180 kr". Beside an option of a product, such as a frame, on its page: what choosing it adds to the product's price. The customer reads it. */
				ChoicePrices::ADD_ON => __( '+%s', 'pixonaut-for-woocommerce' ),
				/* translators: %s: a price, such as "469 kr". Beside an option of a product, such as a size, on its page: the lowest price the product has with it. The customer reads it. */
				ChoicePrices::FROM   => __( 'from %s', 'pixonaut-for-woocommerce' ),
			),
		);
	}

	/**
	 * Each variation the form offers, with its values and its price as the
	 * shop shows it; variations alike in both printed once.
	 *
	 * @param WC_Product_Variable $product The product.
	 * @return list<array<string, mixed>>
	 * @phpstan-return list<PricedVariation>
	 */
	private static function variations( WC_Product_Variable $product ): array {
		$prices     = $product->get_variation_prices( true )['price'] ?? array();
		$variations = array();
		foreach ( $prices as $id => $price ) {
			if ( ! is_numeric( $price ) ) {
				continue;
			}
			$variations[] = array(
				'values' => array_map( 'strval', wc_get_product_variation_attributes( (int) $id ) ),
				'price'  => (float) $price,
			);
		}
		return array_values( array_unique( $variations, SORT_REGULAR ) );
	}

	/**
	 * WooCommerce's HTML words as text: `&nbsp;` a no-break space.
	 *
	 * @param string $html The words.
	 */
	private static function decoded( string $html ): string {
		return html_entity_decode( $html, ENT_QUOTES | ENT_HTML5, 'UTF-8' );
	}
}
