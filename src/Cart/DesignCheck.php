<?php
/**
 * Whether a committed design is what the product sells.
 *
 * @package Pixonaut\WooCommerce
 */

declare(strict_types=1);

namespace Pixonaut\WooCommerce\Cart;

use Pixonaut\WooCommerce\Api\Answers\Design;
use Pixonaut\WooCommerce\Api\Answers\DesignPrintSize;
use Pixonaut\WooCommerce\Catalogue\PrintSize;
use Pixonaut\WooCommerce\Catalogue\Side;
use Pixonaut\WooCommerce\Catalogue\TemplateRenames;

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
	 * @param Design      $design   The design, as Pixonaut answered it.
	 * @param Side        $side     The side the design was made for.
	 * @param string|null $blank_id The product's blank; null for a template-only product.
	 *
	 * @return string|null One of the class constants, or null.
	 */
	public static function mismatch( Design $design, Side $side, ?string $blank_id ): ?string {
		// Pixonaut moves a design to its template's new id; the side may hold the old one until `template.renamed` is handled.
		if ( ! TemplateRenames::same( $design->template_base_id, $side->template_base_id ) ) {
			return self::TEMPLATE;
		}
		if ( null === $design->template_version || ! self::version_accepted( $design->template_version, $side ) ) {
			return self::TEMPLATE_OUTDATED;
		}
		if ( $design->blank_id !== $blank_id ) {
			return self::BLANK;
		}
		if ( $design->placement_id !== $side->placement_id ) {
			return self::PLACEMENT;
		}
		if ( ! self::size_on_menu( $design->print_size, $side->print_sizes ) ) {
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
	 * @param DesignPrintSize|null $print_size The design's size; null for none.
	 * @param PrintSize[]          $menu       The sizes the side is sold in.
	 */
	public static function size_on_menu( ?DesignPrintSize $print_size, array $menu ): bool {
		return null !== self::size_index( $print_size, $menu );
	}

	/**
	 * Where a printed size is on the side's menu, or null when it is not on it.
	 *
	 * @param DesignPrintSize|null $print_size The design's size; null for none.
	 * @param PrintSize[]          $menu       The sizes the side is sold in.
	 */
	public static function size_index( ?DesignPrintSize $print_size, array $menu ): ?int {
		if ( null === $print_size ) {
			return null;
		}
		$tolerance = self::SIZE_TOLERANCE_MM + self::FLOAT_SLACK_MM;
		foreach ( array_values( $menu ) as $index => $size ) {
			if ( abs( $size->width_mm - $print_size->width_mm ) <= $tolerance && abs( $size->height_mm - $print_size->height_mm ) <= $tolerance ) {
				return $index;
			}
		}
		return null;
	}
}
