<?php
/**
 * Money as whole thousandths of a currency unit.
 *
 * @package Pixonaut\WooCommerce
 */

declare(strict_types=1);

namespace Pixonaut\WooCommerce\Pricing;

/**
 * Prices and costs are counted in thousandths of a currency unit, as
 * integers, so a margin is never worked out on a rounded float. A thousandth
 * holds every decimal a currency in use has (three, as the dinar).
 */
final class Amount {

	/**
	 * Thousandths in one currency unit.
	 */
	public const PER_UNIT = 1000;

	/**
	 * Decimals a thousandth holds.
	 */
	private const DECIMALS = 3;

	/**
	 * A decimal number without a sign, with a point or a comma.
	 */
	private const PATTERN = '/^(\d+)(?:[.,](\d+))?$/';

	/**
	 * The digit from which a decimal rounds up.
	 */
	private const ROUND_UP_FROM = 5;

	/**
	 * An amount as written, `167.10` or `167,1`, in thousandths; null for
	 * anything else, a negative amount included. Decimals past the third
	 * round half up.
	 *
	 * @param mixed $written A decimal string, an int or a float.
	 */
	public static function parse( mixed $written ): ?int {
		if ( is_int( $written ) || is_float( $written ) ) {
			$written = number_format( (float) $written, self::DECIMALS + 1, '.', '' );
		}
		if ( ! is_string( $written ) || 1 !== preg_match( self::PATTERN, trim( $written ), $parts ) ) {
			return null;
		}
		$decimals = str_pad( $parts[2] ?? '', self::DECIMALS + 1, '0' );
		$amount   = (int) $parts[1] * self::PER_UNIT + (int) substr( $decimals, 0, self::DECIMALS );
		// The first decimal a thousandth does not hold decides the rounding.
		return (int) $decimals[ self::DECIMALS ] >= self::ROUND_UP_FROM ? $amount + 1 : $amount;
	}

	/**
	 * An amount as a decimal string with a point, without trailing zeros:
	 * `399`, `417.5`. What WooCommerce stores as a price.
	 *
	 * @param int $amount Thousandths.
	 */
	public static function decimal( int $amount ): string {
		$whole    = intdiv( $amount, self::PER_UNIT );
		$decimals = rtrim( str_pad( (string) ( $amount % self::PER_UNIT ), self::DECIMALS, '0', STR_PAD_LEFT ), '0' );
		return '' === $decimals ? (string) $whole : $whole . '.' . $decimals;
	}

	/**
	 * A float of currency units in thousandths, rounded half up.
	 *
	 * @param float $units Currency units.
	 */
	public static function from_units( float $units ): int {
		return (int) round( $units * self::PER_UNIT );
	}

	/**
	 * Thousandths as a float of currency units, for WooCommerce's own
	 * arithmetic and formatting.
	 *
	 * @param int $amount Thousandths.
	 */
	public static function units( int $amount ): float {
		return $amount / self::PER_UNIT;
	}
}
