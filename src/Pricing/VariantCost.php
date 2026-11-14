<?php
/**
 * What a printshop charges for one blank variant.
 *
 * @package Pixonaut\WooCommerce
 */

declare(strict_types=1);

namespace Pixonaut\WooCommerce\Pricing;

/**
 * A blank variant's cost as `GET /v1/blanks/{id}/variants/costs` answers
 * it: the product's price and the cheapest shipping to a region, each in
 * its currency, and when Pixonaut read them. A printshop that publishes no
 * price gives none, and a cost is then never guessed.
 */
final class VariantCost {

	/**
	 * The printshop publishes no price for the variant.
	 */
	public const NO_PRICE = 'no_price';

	/**
	 * The cost is in another currency than the store's.
	 */
	public const OTHER_CURRENCY = 'other_currency';

	/**
	 * @param int|null    $product           The product's price in thousandths; null when the printshop publishes none.
	 * @param string      $currency          Its currency, `SEK`; empty without a price.
	 * @param int|null    $shipping          The cheapest shipping in thousandths; null when none is known.
	 * @param string      $shipping_currency Its currency; empty without shipping.
	 * @param string      $region            The region the cost is for, a country code.
	 * @param string|null $read_at           When Pixonaut read it from the printshop, ISO 8601.
	 */
	public function __construct(
		public readonly ?int $product,
		public readonly string $currency = '',
		public readonly ?int $shipping = null,
		public readonly string $shipping_currency = '',
		public readonly string $region = '',
		public readonly ?string $read_at = null,
	) {
	}

	/**
	 * The costs in an answer's `costs` list, by variant ref; an entry
	 * without a variant is left out, and a price that is not an amount in a
	 * currency reads as none.
	 *
	 * @param array<mixed> $rows The list.
	 * @return array<string, self>
	 */
	public static function list_from( array $rows ): array {
		$costs = array();
		foreach ( $rows as $row ) {
			if ( ! is_array( $row ) || ! is_string( $row['variant'] ?? null ) || '' === $row['variant'] ) {
				continue;
			}
			list( $product, $currency )           = self::money( $row['product'] ?? null );
			list( $shipping, $shipping_currency ) = self::money( $row['shipping'] ?? null );
			$costs[ $row['variant'] ]             = new self(
				$product,
				$currency,
				$shipping,
				$shipping_currency,
				is_string( $row['region'] ?? null ) ? $row['region'] : '',
				is_string( $row['readAt'] ?? null ) ? $row['readAt'] : null
			);
		}
		return $costs;
	}

	/**
	 * Why the cost cannot price anything in a store: `NO_PRICE`,
	 * `OTHER_CURRENCY` when the product or its shipping is charged in
	 * another currency than the store's, or null when it can.
	 *
	 * @param string $store_currency The store's currency.
	 */
	public function problem( string $store_currency ): ?string {
		if ( null === $this->product ) {
			return self::NO_PRICE;
		}
		if ( $this->currency !== $store_currency || ( null !== $this->shipping && $this->shipping_currency !== $store_currency ) ) {
			return self::OTHER_CURRENCY;
		}
		return null;
	}

	/**
	 * The product and its shipping together, in thousandths; null when the
	 * cost has a `problem()` in the store.
	 *
	 * @param string $store_currency The store's currency.
	 */
	public function total( string $store_currency ): ?int {
		return null === $this->product || null !== $this->problem( $store_currency ) ? null : $this->product + ( $this->shipping ?? 0 );
	}

	/**
	 * Whether another cost charges the same.
	 *
	 * @param self|null $other The other cost.
	 */
	public function charges_as( ?self $other ): bool {
		return null !== $other
			&& $this->product === $other->product && $this->currency === $other->currency
			&& $this->shipping === $other->shipping && $this->shipping_currency === $other->shipping_currency;
	}

	/**
	 * @return array{product: string|null, currency: string, shipping: string|null, shippingCurrency: string, region: string, readAt: string|null}
	 */
	public function to_array(): array {
		return array(
			'product'          => null === $this->product ? null : Amount::decimal( $this->product ),
			'currency'         => $this->currency,
			'shipping'         => null === $this->shipping ? null : Amount::decimal( $this->shipping ),
			'shippingCurrency' => $this->shipping_currency,
			'region'           => $this->region,
			'readAt'           => $this->read_at,
		);
	}

	/**
	 * A cost as `to_array()` wrote it; null for anything else.
	 *
	 * @param mixed $data The stored cost.
	 */
	public static function from_array( mixed $data ): ?self {
		if ( ! is_array( $data ) ) {
			return null;
		}
		$text = static fn ( string $key ): string => is_string( $data[ $key ] ?? null ) ? $data[ $key ] : '';
		return new self(
			Amount::parse( $data['product'] ?? null ),
			$text( 'currency' ),
			Amount::parse( $data['shipping'] ?? null ),
			$text( 'shippingCurrency' ),
			$text( 'region' ),
			is_string( $data['readAt'] ?? null ) ? $data['readAt'] : null
		);
	}

	/**
	 * An amount with its currency, as the answer writes it.
	 *
	 * @param mixed $money `{amount, currency}`, or null.
	 * @return array{0: int|null, 1: string} The amount in thousandths and its currency; null and empty when it is not one.
	 */
	private static function money( mixed $money ): array {
		$amount   = is_array( $money ) ? Amount::parse( $money['amount'] ?? null ) : null;
		$currency = is_array( $money ) && is_string( $money['currency'] ?? null ) ? strtoupper( $money['currency'] ) : '';
		return null === $amount || '' === $currency ? array( null, '' ) : array( $amount, $currency );
	}
}
