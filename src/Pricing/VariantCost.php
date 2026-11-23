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
 *
 * A book is priced only at a page count, and not in even steps, so a
 * variant of a blank printed as pages is read with its cost at each page
 * count its paged placement offers (`by_pages`). The variant's own cost is
 * the one at its fewest priced pages; `at_pages()` gives the one at a count.
 */
final class VariantCost {

	/**
	 * @param int|null         $product           The product's price in thousandths; null when the printshop publishes none.
	 * @param string           $currency          Its currency, `SEK`; empty without a price.
	 * @param int|null         $shipping          The cheapest shipping in thousandths; null when none is known.
	 * @param string           $shipping_currency Its currency; empty without shipping.
	 * @param string           $region            The region the cost is for, a country code.
	 * @param string|null      $read_at           When Pixonaut read it from the printshop, ISO 8601.
	 * @param DeliveryTimes    $times             How long the printshop says the variant takes by that shipping; none for a cost read before Pixonaut answered times.
	 * @param int|null         $pages             The page count the cost is at, inner pages only; null for the variant's own cost.
	 * @param array<int, self> $by_pages       The cost at each page count the variant's paged placement offers, fewest first, as read; empty for a variant printed as no pages, and on a cost read back from storage.
	 */
	public function __construct(
		public readonly ?int $product,
		public readonly string $currency = '',
		public readonly ?int $shipping = null,
		public readonly string $shipping_currency = '',
		public readonly string $region = '',
		public readonly ?string $read_at = null,
		public readonly DeliveryTimes $times = new DeliveryTimes(),
		public readonly ?int $pages = null,
		public readonly array $by_pages = array(),
	) {
	}

	/**
	 * The cost at a page count: the variant's own for no count, or for a
	 * variant printed as no pages; at a count its placement does not offer,
	 * one without a price, which nothing is sold at.
	 *
	 * @param int|null $pages The page count, inner pages only; null for none.
	 */
	public function at_pages( ?int $pages ): self {
		if ( null === $pages || array() === $this->by_pages ) {
			return $this;
		}
		return $this->by_pages[ $pages ] ?? new self( null, region: $this->region, read_at: $this->read_at, pages: $pages );
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
			$region   = is_string( $row['region'] ?? null ) ? $row['region'] : '';
			$read_at  = is_string( $row['readAt'] ?? null ) ? $row['readAt'] : null;
			$by_pages = array();
			foreach ( is_array( $row['pages'] ?? null ) ? $row['pages'] : array() as $at ) {
				if ( is_array( $at ) && is_int( $at['pages'] ?? null ) && $at['pages'] > 0 ) {
					$by_pages[ $at['pages'] ] = self::read( $at, $region, $read_at, $at['pages'] );
				}
			}
			ksort( $by_pages );
			$costs[ $row['variant'] ] = self::read( $row, $region, $read_at, null, $by_pages );
		}
		return $costs;
	}

	/**
	 * A cost as the answer writes one, the variant's own or at a page count.
	 *
	 * @param array<mixed>     $row      Its `product`, `shipping` and `times`.
	 * @param string           $region   The region it is for.
	 * @param string|null      $read_at  When Pixonaut read it.
	 * @param int|null         $pages    The page count it is at; null for the variant's own.
	 * @param array<int, self> $by_pages The variant's costs by page count.
	 */
	private static function read( array $row, string $region, ?string $read_at, ?int $pages, array $by_pages = array() ): self {
		list( $product, $currency )           = self::money( $row['product'] ?? null );
		list( $shipping, $shipping_currency ) = self::money( $row['shipping'] ?? null );
		return new self( $product, $currency, $shipping, $shipping_currency, $region, $read_at, DeliveryTimes::from_array( $row['times'] ?? null ), $pages, $by_pages );
	}

	/**
	 * @return array<string, mixed> `product`, `currency`, `shipping`, `shippingCurrency`, `region`, `readAt`, `times`, and `pages` for a cost at a page count.
	 */
	public function to_array(): array {
		$data = array(
			'product'          => null === $this->product ? null : Amount::decimal( $this->product ),
			'currency'         => $this->currency,
			'shipping'         => null === $this->shipping ? null : Amount::decimal( $this->shipping ),
			'shippingCurrency' => $this->shipping_currency,
			'region'           => $this->region,
			'readAt'           => $this->read_at,
			'times'            => $this->times->to_array(),
		);
		return null === $this->pages ? $data : $data + array( 'pages' => $this->pages );
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
			DeliveryTimes::from_array( $data['times'] ?? null ),
			is_int( $data['pages'] ?? null ) ? $data['pages'] : null
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
