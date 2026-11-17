<?php
/**
 * The rule that lets a side's poster cover a box of nearly its shape.
 *
 * @package Pixonaut\WooCommerce
 */

declare(strict_types=1);

namespace Pixonaut\WooCommerce\Storefront;

defined( 'ABSPATH' ) || exit;

/**
 * A poster is contained in its preview's box, which takes the poster's shape
 * but for a fraction of a pixel where the page bounds its height. Contained,
 * the poster then paints that much short of the box while the designer's
 * picture fills it, and the browser reports the designer's as the page's
 * largest contentful paint, though nothing visible changed. In a box within
 * `COVER_SHARE` of its shape the poster covers the box instead; in one of
 * another shape (a theme's fixed preview height) it stays whole.
 *
 * The rule has to stand before the poster's first paint, which is the one
 * the browser measures, so it is printed with the poster rather than set
 * by a script.
 */
final class PosterFit {

	/** How far the box's shape may be from the poster's, as a share of it, for the poster to cover it: 0.2% crops at most about a pixel of a 600-pixel preview. */
	private const COVER_SHARE = 0.002;

	/** Decimals the shape bounds are printed to: finer than any box's rounding. */
	private const DECIMALS = 5;

	/** What a side's name may hold to stand in a CSS id selector unescaped. */
	private const SIDE_PATTERN = '/^[A-Za-z0-9_-]+$/';

	/**
	 * The style element that lets side `$side`'s poster of `$width` × `$height`
	 * cover its box, or '' where there is none to print.
	 *
	 * @param string $side   The side the poster stands in.
	 * @param int    $width  The poster's width in pixels.
	 * @param int    $height The poster's height in pixels.
	 * @return string The `<style>` element, its values numbers and a checked name.
	 */
	public static function style( string $side, int $width, int $height ): string {
		if ( $width <= 0 || $height <= 0 || 1 !== preg_match( self::SIDE_PATTERN, $side ) ) {
			return '';
		}
		$shape = $width / $height;
		$from  = number_format( $shape * ( 1 - self::COVER_SHARE ), self::DECIMALS, '.', '' );
		$to    = number_format( $shape * ( 1 + self::COVER_SHARE ), self::DECIMALS, '.', '' );
		return '<style>@container pixonaut-poster (aspect-ratio >= ' . $from . ') and (aspect-ratio <= ' . $to . '){#pixonaut-preview-' . $side . ' .pixonaut-preview__poster{object-fit:cover}}</style>';
	}
}
