<?php
/**
 * What each choice of a variable product's attributes shows as its price.
 *
 * @package Pixonaut\WooCommerce
 */

declare(strict_types=1);

namespace Pixonaut\WooCommerce\Storefront;

use Pixonaut\WooCommerce\Catalogue\ApplicableAttributes;

/**
 * The amounts the swatches of a variable product's attributes show, worked
 * out from its variations' prices for the shopper's choices
 * (`ChoicePrices` says which attributes show one):
 *
 * - An add-on attribute (a hanger, a frame, a frame's finish) shows what each
 *   value adds to the cheapest value of the attribute, the base product: the
 *   cheapest variation holding the value, against the cheapest holding any
 *   value, among the variations that hold the chosen values of every
 *   attribute that is not an add-on (the size, the personalisation). The
 *   cheapest value shows nothing, and so does a value no such variation
 *   holds. The choices of the other add-ons do not count, so a frame shows
 *   the same amount whatever finish is chosen; only an add-on that applies to
 *   some variations alone (a finish, under a hanger and a frame) counts the
 *   add-ons before it, and among the variations that set it, so a finish
 *   shows its difference under the chosen framing. While the choices leave
 *   more than one value of an attribute that is not an add-on, no add-on
 *   shows an amount: the base product is not known.
 * - A base attribute (the size) shows each value's lowest price: the
 *   cheapest variation holding it, among those that hold the chosen values of
 *   the other attributes that are not add-ons.
 *
 * A variation that leaves an attribute unset holds any value of it: each of
 * the values the product offers for the attribute, besides those another
 * variation holds.
 * `choice-prices.ts` applies the same rule in the browser;
 * `tests/fixtures/choice-prices.json` holds the cases both must answer alike.
 *
 * @phpstan-type PricedVariation array{values: array<string, string>, price: float}
 * @phpstan-type Amount array{kind: string, amount: float}
 */
final class ChoicePriceTable {

	/**
	 * The amounts each attribute's values show, by field and value; a value
	 * that shows none is left out.
	 *
	 * @param string[]              $fields     The product's `attribute_*` fields, in its order of attributes.
	 * @param array<string, string> $roles      `ChoicePrices::ADD_ON` or `ChoicePrices::FROM`, by field; a field left out shows no price.
	 * @param list<array>           $variations Each variation's values by field and its price.
	 * @param array<string, string> $chosen     The chosen values by field; empty or missing for none.
	 * @param int                   $decimals   The decimals prices are shown with.
	 * @param array<string, array>  $options    The values the product offers for each attribute, by field, in its order.
	 * @phpstan-param list<string> $fields
	 * @phpstan-param array<string, list<string>> $options
	 * @phpstan-param list<PricedVariation> $variations
	 * @return array<string, array<string, Amount>>
	 */
	public static function amounts( array $fields, array $roles, array $variations, array $chosen, int $decimals, array $options = array() ): array {
		$values    = array_map( static fn ( array $variation ): array => $variation['values'], $variations );
		$dependent = ApplicableAttributes::dependent( $fields, $values );
		$base      = array_values( array_filter( $fields, static fn ( string $field ): bool => ChoicePrices::ADD_ON !== ( $roles[ $field ] ?? '' ) ) );
		$amounts   = array();
		foreach ( $fields as $place => $field ) {
			$role = $roles[ $field ] ?? '';
			if ( ChoicePrices::FROM === $role ) {
				$lowest = self::lowest( $variations, $field, $options[ $field ] ?? array(), self::chosen_of( $chosen, array_diff( $base, array( $field ) ) ), false );
				foreach ( $lowest as $value => $price ) {
					$amounts[ $field ][ $value ] = array(
						'kind'   => ChoicePrices::FROM,
						'amount' => round( $price, $decimals ),
					);
				}
				continue;
			}
			if ( ChoicePrices::ADD_ON !== $role ) {
				continue;
			}
			$context = self::chosen_of( $chosen, $base );
			if ( ! self::settled( $variations, $base, $context ) ) {
				continue;
			}
			$set_only = in_array( $field, $dependent, true );
			if ( $set_only ) {
				$before  = array_filter( array_slice( $fields, 0, $place ), static fn ( string $other ): bool => ChoicePrices::ADD_ON === ( $roles[ $other ] ?? '' ) );
				$context = array_merge( $context, self::chosen_of( $chosen, $before ) );
			}
			$lowest = self::lowest( $variations, $field, $options[ $field ] ?? array(), $context, $set_only );
			if ( array() === $lowest ) {
				continue;
			}
			$cheapest = min( $lowest );
			foreach ( $lowest as $value => $price ) {
				$amount = round( $price - $cheapest, $decimals );
				if ( $amount > 0 ) {
					$amounts[ $field ][ $value ] = array(
						'kind'   => ChoicePrices::ADD_ON,
						'amount' => $amount,
					);
				}
			}
		}
		return $amounts;
	}

	/**
	 * The lowest price of each value of `$field` among the variations holding
	 * `$context`, by value: the values the product offers, in its order, then
	 * any other a variation holds, in the order the variations first hold them.
	 *
	 * @param list<array>           $variations The variations and their prices.
	 * @param string                $field      The attribute's field.
	 * @param array<string>         $offered    The values the product offers for the attribute.
	 * @param array<string, string> $context    The chosen values that count.
	 * @param bool                  $set_only   Whether only variations that set the attribute count; else one leaving it unset holds every value.
	 * @phpstan-param list<PricedVariation> $variations
	 * @return array<string, float>
	 */
	private static function lowest( array $variations, string $field, array $offered, array $context, bool $set_only ): array {
		$values = array_fill_keys( $offered, true );
		foreach ( $variations as $variation ) {
			$value = $variation['values'][ $field ] ?? '';
			if ( '' !== $value ) {
				$values[ $value ] = true;
			}
		}
		$lowest = array();
		foreach ( $variations as $variation ) {
			if ( ! ApplicableAttributes::matches( $variation['values'], $context ) ) {
				continue;
			}
			$held = $variation['values'][ $field ] ?? '';
			if ( '' === $held && $set_only ) {
				continue;
			}
			foreach ( '' === $held ? array_keys( $values ) : array( $held ) as $value ) {
				$value            = (string) $value;
				$lowest[ $value ] = min( $lowest[ $value ] ?? INF, $variation['price'] );
			}
		}
		// In the order the variations first hold the values.
		$ordered = array();
		foreach ( array_keys( $values ) as $value ) {
			if ( isset( $lowest[ (string) $value ] ) ) {
				$ordered[ (string) $value ] = $lowest[ (string) $value ];
			}
		}
		return $ordered;
	}

	/**
	 * Whether the variations holding `$context` hold one value at most of
	 * each of `$fields`: the base product is known.
	 *
	 * @param list<array>           $variations The variations and their prices.
	 * @param string[]              $fields     The attributes that are not add-ons.
	 * @param array<string, string> $context    Their chosen values.
	 * @phpstan-param list<PricedVariation> $variations
	 * @phpstan-param list<string> $fields
	 */
	private static function settled( array $variations, array $fields, array $context ): bool {
		$held = array();
		foreach ( $variations as $variation ) {
			if ( ! ApplicableAttributes::matches( $variation['values'], $context ) ) {
				continue;
			}
			foreach ( $fields as $field ) {
				$value = $variation['values'][ $field ] ?? '';
				if ( '' !== $value ) {
					$held[ $field ][ $value ] = true;
				}
			}
		}
		foreach ( $held as $values ) {
			if ( count( $values ) > 1 ) {
				return false;
			}
		}
		return true;
	}

	/**
	 * The chosen values of `$fields`.
	 *
	 * @param array<string, string> $chosen The chosen values by field.
	 * @param array<string>         $fields The fields that count.
	 * @return array<string, string>
	 */
	private static function chosen_of( array $chosen, array $fields ): array {
		$of = array();
		foreach ( $fields as $field ) {
			$of[ $field ] = $chosen[ $field ] ?? '';
		}
		return $of;
	}
}
