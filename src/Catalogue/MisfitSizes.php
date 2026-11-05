<?php
/**
 * The blank's sizes a template cannot be printed at.
 *
 * @package Pixonaut\WooCommerce
 */

declare(strict_types=1);

namespace Pixonaut\WooCommerce\Catalogue;

use Pixonaut\WooCommerce\Api\ApiError;
use Pixonaut\WooCommerce\Api\ErrorCodes;

/**
 * On a placement whose print area changes with an option (a cut-and-sewn
 * panel, or a position that prints one size only), a template can fit the
 * panel at some sizes only: its shape matches an S's panel and not an XL's.
 * Pixonaut names those sizes in `sizes.misfits` on the fitting-templates
 * and constraints reads, by option value (`XL`; several options' values
 * joined with ` / `), and refuses a design at one when it is committed.
 * Both lists are empty on any other placement, which the template fits at
 * every size.
 */
final class MisfitSizes {

	/**
	 * The rules of a refused design that say its print does not fit the size it is printed at.
	 */
	private const PRINT_SIZE_RULES = array( InvalidReason::WRONG_RATIO, InvalidReason::TOO_LARGE );

	/**
	 * Between the values of a row that names several options, as Pixonaut joins them.
	 */
	private const ROW_VALUE_JOINER = ' / ';

	/**
	 * A row of the blank's print areas as Pixonaut names it among the
	 * misfits: its values, in the order of the options they change with.
	 *
	 * @param array<string, string> $row The row, `Size => XL`.
	 */
	public static function value( array $row ): string {
		return implode( self::ROW_VALUE_JOINER, $row );
	}

	/**
	 * The misfits of a `sizes` object as Pixonaut answers it.
	 *
	 * @param mixed $sizes `{fits, misfits}`, or anything else for none.
	 * @return list<string>
	 */
	public static function of( mixed $sizes ): array {
		$misfits = is_array( $sizes ) && is_array( $sizes['misfits'] ?? null ) ? $sizes['misfits'] : array();
		return array_values( array_filter( $misfits, static fn ( mixed $value ): bool => is_string( $value ) && '' !== $value ) );
	}

	/**
	 * Whether Pixonaut refused a design because its print does not fit the
	 * size it is printed at (`422 constraint_violation` with a `wrong_ratio`
	 * or `too_large` violation).
	 *
	 * @param ApiError $error What Pixonaut answered.
	 */
	public static function refused( ApiError $error ): bool {
		if ( ErrorCodes::CONSTRAINT_VIOLATION !== $error->error_code ) {
			return false;
		}
		foreach ( is_array( $error->details['violations'] ?? null ) ? $error->details['violations'] : array() as $violation ) {
			if ( is_array( $violation ) && in_array( $violation['rule'] ?? null, self::PRINT_SIZE_RULES, true ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * The short note beside a template in the template search; empty for none.
	 *
	 * @param string[] $misfits Option values.
	 */
	public static function note( array $misfits ): string {
		if ( array() === $misfits ) {
			return '';
		}
		return wp_sprintf(
			/* translators: %l: the sizes, e.g. "XL and 3XL". Beside a template in the template search of the admin product page: the template cannot be printed at these sizes of the blank. */
			__( 'Cannot print at %l', 'pixonaut-for-woocommerce' ),
			$misfits
		);
	}

	/**
	 * The warning on a side whose template cannot be printed at some sizes; empty for none.
	 *
	 * @param string[] $misfits Option values.
	 */
	public static function warning( array $misfits ): string {
		if ( array() === $misfits ) {
			return '';
		}
		return wp_sprintf(
			/* translators: %l: the sizes, e.g. "XL and 3XL". Warning on a side of the admin product page. */
			__( 'This template does not fit the placement at %l: it cannot be printed at those sizes. Choose a template that fits every size, or stop selling those sizes.', 'pixonaut-for-woocommerce' ),
			$misfits
		);
	}
}
