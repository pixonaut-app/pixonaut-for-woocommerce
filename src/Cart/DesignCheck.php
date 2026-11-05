<?php
/**
 * Whether a committed design is what the product sells.
 *
 * @package Pixonaut\WooCommerce
 */

declare(strict_types=1);

namespace Pixonaut\WooCommerce\Cart;

use Pixonaut\WooCommerce\Api\Designs;
use Pixonaut\WooCommerce\Catalogue\PrintSize;
use Pixonaut\WooCommerce\Catalogue\Side;

/**
 * The proof is made in the customer's browser, so its design is checked
 * against the side's configuration before it reaches the cart.
 */
final class DesignCheck {

	/**
	 * How far a printed width or height may be from a menu entry, in millimetres.
	 */
	public const SIZE_TOLERANCE_MM = 0.01;

	/**
	 * Rounding slack on the tolerance, so a size exactly 0.01 mm off, which
	 * floating point subtracts to a hair more, is still accepted.
	 */
	private const FLOAT_SLACK_MM = 1e-9;

	public const TEMPLATE = 'template';

	/**
	 * The design was made from a template version the product no longer sells.
	 */
	public const TEMPLATE_OUTDATED = 'template_outdated';

	public const BLANK = 'blank';

	public const PLACEMENT = 'placement';

	public const PRINT_SIZE = 'print_size';

	/**
	 * What does not match, or null when the design is what the side sells.
	 *
	 * @param array<string, mixed> $design   `GET /v1/designs/{id}` answer, as `Designs::get()` reads it.
	 * @param Side                 $side     The side the design was made for.
	 * @param string|null          $blank_id The product's blank; null for a template-only product.
	 *
	 * @return string|null One of the class constants, or null.
	 */
	public static function mismatch( array $design, Side $side, ?string $blank_id ): ?string {
		if ( ( $design[ Designs::TEMPLATE_BASE_ID ] ?? null ) !== $side->template_base_id ) {
			return self::TEMPLATE;
		}
		$version = $design[ Designs::TEMPLATE_VERSION ] ?? null;
		if ( ! is_int( $version ) || ! self::version_accepted( $version, $side ) ) {
			return self::TEMPLATE_OUTDATED;
		}
		if ( ( $design[ Designs::BLANK_ID ] ?? null ) !== $blank_id ) {
			return self::BLANK;
		}
		if ( ( $design[ Designs::PLACEMENT_ID ] ?? null ) !== $side->placement_id ) {
			return self::PLACEMENT;
		}
		if ( ! self::size_on_menu( $design[ Designs::PRINT_SIZE ] ?? null, $side->print_sizes ) ) {
			return self::PRINT_SIZE;
		}
		return null;
	}

	/**
	 * A cart accepts the live version and the one before it, so a customer
	 * who started before a publish can still buy what they made.
	 *
	 * @param int  $version The design's template version.
	 * @param Side $side    The side.
	 */
	public static function version_accepted( int $version, Side $side ): bool {
		return in_array( $version, array_filter( array( $side->template_version, $side->previous_version ), 'is_int' ), true );
	}

	/**
	 * Whether a printed size is on the side's menu. A design without a size
	 * is not, and neither is any size when the menu is empty: a side is saved
	 * with at least the placement's default size.
	 *
	 * @param mixed       $print_size `{widthMm, heightMm}` or null.
	 * @param PrintSize[] $menu       The sizes the side is sold in.
	 */
	public static function size_on_menu( mixed $print_size, array $menu ): bool {
		return null !== self::size_index( $print_size, $menu );
	}

	/**
	 * Where a printed size is on the side's menu, or null when it is not on it.
	 *
	 * @param mixed       $print_size `{widthMm, heightMm}` or null.
	 * @param PrintSize[] $menu       The sizes the side is sold in.
	 */
	public static function size_index( mixed $print_size, array $menu ): ?int {
		if ( ! is_array( $print_size ) || ! is_numeric( $print_size['widthMm'] ?? null ) || ! is_numeric( $print_size['heightMm'] ?? null ) ) {
			return null;
		}
		$width     = (float) $print_size['widthMm'];
		$height    = (float) $print_size['heightMm'];
		$tolerance = self::SIZE_TOLERANCE_MM + self::FLOAT_SLACK_MM;
		foreach ( array_values( $menu ) as $index => $size ) {
			if ( abs( $size->width_mm - $width ) <= $tolerance && abs( $size->height_mm - $height ) <= $tolerance ) {
				return $index;
			}
		}
		return null;
	}
}
