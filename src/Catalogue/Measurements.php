<?php
/**
 * The sizes an option label names.
 *
 * @package Pixonaut\WooCommerce
 */

declare(strict_types=1);

namespace Pixonaut\WooCommerce\Catalogue;

/**
 * Reads the sizes written in an option label, so a size the store writes
 * one way matches the size a printshop writes another: "70×100 cm",
 * "70x100cm", "700 x 1000 mm" and Gelato's `28x40" / 70x100cm` all name a
 * 700 × 1000 mm size. A label may name several (a printshop often gives
 * inches and centimetres together); a number pair without a unit, or a
 * label naming no size, names none. ISO paper sizes (`A4`) name theirs.
 */
final class Measurements {

	/**
	 * Millimetres per unit a label may write.
	 */
	private const MM_PER_UNIT = array(
		'mm'     => 1.0,
		'cm'     => 10.0,
		'm'      => 1000.0,
		'in'     => 25.4,
		'inch'   => 25.4,
		'inches' => 25.4,
		'"'      => 25.4,
		'″'      => 25.4,
		"''"     => 25.4,
	);

	/**
	 * ISO 216 A sizes, width and height in mm.
	 */
	private const PAPER_SIZES = array(
		'a0' => array( 841.0, 1189.0 ),
		'a1' => array( 594.0, 841.0 ),
		'a2' => array( 420.0, 594.0 ),
		'a3' => array( 297.0, 420.0 ),
		'a4' => array( 210.0, 297.0 ),
		'a5' => array( 148.0, 210.0 ),
		'a6' => array( 105.0, 148.0 ),
	);

	/**
	 * Two sizes this close in both measures are one size: what rounding
	 * between units leaves.
	 */
	private const SAME_WITHIN_MM = 1.0;

	/**
	 * A number pair and its unit, `70 x 100 cm`; the unit may sit after
	 * each number (`28"x40"`).
	 */
	private const PAIR = '/(\d+(?:[.,]\d+)?)\s*(mm|cm|m|inches|inch|in|"|″|\'\')?\s*[x×X*]\s*(\d+(?:[.,]\d+)?)\s*(mm|cm|m|inches|inch|in|"|″|\'\')?(?![a-z])/u';

	/**
	 * A paper size standing alone, `A4`.
	 */
	private const PAPER = '/(?<![a-z0-9])(a[0-6])(?![a-z0-9])/u';

	/**
	 * The sizes a label names, each width and height in mm.
	 *
	 * @param string $label An option label.
	 * @return list<array{0: float, 1: float}>
	 */
	public static function of( string $label ): array {
		$label = mb_strtolower( $label );
		$sizes = array();
		if ( preg_match_all( self::PAIR, $label, $pairs, PREG_SET_ORDER ) > 0 ) {
			foreach ( $pairs as $pair ) {
				$unit = '' !== ( $pair[4] ?? '' ) ? $pair[4] : $pair[2];
				if ( '' === $unit ) {
					continue;
				}
				$sizes[] = array( self::number( $pair[1] ) * self::MM_PER_UNIT[ $unit ], self::number( $pair[3] ) * self::MM_PER_UNIT[ $unit ] );
			}
		}
		if ( preg_match_all( self::PAPER, $label, $papers ) > 0 ) {
			foreach ( $papers[1] as $paper ) {
				$sizes[] = self::PAPER_SIZES[ $paper ];
			}
		}
		return $sizes;
	}

	/**
	 * Whether two labels name a size in common, either way round.
	 *
	 * @param string $one   A label.
	 * @param string $other Another.
	 */
	public static function same( string $one, string $other ): bool {
		$theirs = self::of( $other );
		foreach ( self::of( $one ) as $size ) {
			foreach ( $theirs as $their ) {
				if ( self::close( $size, $their ) || self::close( $size, array( $their[1], $their[0] ) ) ) {
					return true;
				}
			}
		}
		return false;
	}

	/**
	 * @param array{0: float, 1: float} $one   A size.
	 * @param array{0: float, 1: float} $other Another.
	 */
	private static function close( array $one, array $other ): bool {
		return abs( $one[0] - $other[0] ) <= self::SAME_WITHIN_MM && abs( $one[1] - $other[1] ) <= self::SAME_WITHIN_MM;
	}

	/**
	 * A number written with a dot or a decimal comma.
	 *
	 * @param string $written The number.
	 */
	private static function number( string $written ): float {
		return (float) str_replace( ',', '.', $written );
	}
}
