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
 * its currency (the printshop's billing currency, never converted), how
 * long the printshop says it takes by that shipping, and when Pixonaut read
 * them. A printshop that publishes no price gives none,
 * and a cost is then never guessed.
 */
final class VariantCost {

	/**
	 * @param int|null      $product           The product's price in thousandths; null when the printshop publishes none.
	 * @param string        $currency          Its currency, `SEK`; empty without a price.
	 * @param int|null      $shipping          The cheapest shipping in thousandths; null when none is known.
	 * @param string        $shipping_currency Its currency; empty without shipping.
	 * @param string        $region            The region the cost is for, a country code.
	 * @param string|null   $read_at           When Pixonaut read it from the printshop, ISO 8601.
	 * @param DeliveryTimes $times             How long the printshop says the variant takes by that shipping; none for a cost read before Pixonaut answered times.
	 */
	public function __construct(
		public readonly ?int $product,
		public readonly string $currency = '',
		public readonly ?int $shipping = null,
		public readonly string $shipping_currency = '',
		public readonly string $region = '',
		public readonly ?string $read_at = null,
		public readonly DeliveryTimes $times = new DeliveryTimes(),
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
				is_string( $row['readAt'] ?? null ) ? $row['readAt'] : null,
				DeliveryTimes::from_array( $row['times'] ?? null )
			);
		}
		return $costs;
	}

	/**
	 * @return array<string, mixed> `product`, `currency`, `shipping`, `shippingCurrency`, `region`, `readAt` and `times`.
	 */
	public function to_array(): array {
		return array(
			'product'          => null === $this->product ? null : Amount::decimal( $this->product ),
			'currency'         => $this->currency,
			'shipping'         => null === $this->shipping ? null : Amount::decimal( $this->shipping ),
			'shippingCurrency' => $this->shipping_currency,
			'region'           => $this->region,
			'readAt'           => $this->read_at,
			'times'            => $this->times->to_array(),
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
			is_string( $data['readAt'] ?? null ) ? $data['readAt'] : null,
			DeliveryTimes::from_array( $data['times'] ?? null )
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
