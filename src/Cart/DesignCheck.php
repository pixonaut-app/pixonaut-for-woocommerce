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
	 * The design is another page, or of a book of another page count, than the line holds it as.
	 */
	public const PAGES = 'pages';

	/**
	 * The design was made with other values than the side's own: without
	 * the side's value set, with another, or with one where the side has none.
	 */
	public const VALUES = 'values';

	/**
	 * What does not match, or null when the design is what the side sells.
	 *
	 * A page of a side printed as pages may be made from one of the side's
	 * page layouts instead of its template: it is then judged by the
	 * layout's versions and value set.
	 *
	 * @param Design                $design          The design, as Pixonaut answered it.
	 * @param Side                  $side            The side the design was made for.
	 * @param string|null           $blank_id        The product's blank; null for a template-only product.
	 * @param string|null           $value_set_id    The side's own value set (`SideValues::set_of()`); null when it has none.
	 * @param array<string, string> $layout_sets     The value set of each of the side's page layouts that has one, by its
	 *                                               base id (`SideValues::sets_of()`); a layout without one has none.
	 * @param string[]              $layouts_pending The base ids of the side's page layouts whose set could not be made
	 *                                               yet (`SideValues::pending_of()`): a page on one is refused, as it
	 *                                               was made without the side's values.
	 *
	 * @phpstan-param list<string> $layouts_pending
	 *
	 * @return string|null One of the class constants, or null.
	 */
	public static function mismatch( Design $design, Side $side, ?string $blank_id, ?string $value_set_id, array $layout_sets = array(), array $layouts_pending = array() ): ?string {
		// Pixonaut moves a design to its template's new id; the side may hold the old one until `template.renamed` is handled.
		$own    = TemplateRenames::same( $design->template_base_id, $side->template_base_id );
		$layout = $own || null === $design->page ? null : $side->page_template( $design->template_base_id );
		if ( ! $own && null === $layout ) {
			return self::TEMPLATE;
		}
		$accepted = null === $layout ? self::version_accepted( $design->template_version ?? 0, $side ) : in_array( $design->template_version, $layout->accepted_versions(), true );
		if ( null === $design->template_version || ! $accepted ) {
			return self::TEMPLATE_OUTDATED;
		}
		if ( null !== $layout ) {
			foreach ( $layouts_pending as $pending ) {
				if ( TemplateRenames::same( $pending, $layout->base_id ) ) {
					return self::VALUES;
				}
			}
			$value_set_id = $layout_sets[ $layout->base_id ] ?? null;
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
		// A fixed value the product sells is printed from the set the design was read with.
		if ( $design->value_set_id !== $value_set_id ) {
			return self::VALUES;
		}
		return null;
	}

	/**
	 * Whether a design is the page a line holds it as, of a book of the
	 * pages the line holds and the product is sold at.
	 *
	 * @param Design   $design The design, as Pixonaut answered it.
	 * @param int|null $page   The page the line holds it as; null on a side of one design.
	 * @param int      $held   How many pages the line holds of its side; 0 on a side of one design.
	 * @param int|null $sold   The pages the design must be made for (`book_count()`); null for any.
	 */
	public static function pages_match( Design $design, ?int $page, int $held, ?int $sold ): bool {
		if ( $design->page !== $page ) {
			return false;
		}
		if ( null !== $page && ( $design->page_count !== $held || ! self::count_sold( $held, $sold ) ) ) {
			return false;
		}
		// A cover is made for a book of a count too.
		return null === $design->page_count || self::count_sold( $design->page_count, $sold );
	}

	/**
	 * The page count each design of a line must be made for: the one sold,
	 * else the one the line's sides printed as pages hold; null for none, and
	 * for sides of different counts.
	 *
	 * @param int|null   $sold The pages the product or variation is sold at; null when it names none.
	 * @param array<int> $held How many pages each side of the line holds; 0 for a side of one design.
	 */
	public static function book_count( ?int $sold, array $held ): ?int {
		$counts = array_values( array_unique( array_filter( $held ) ) );
		return $sold ?? ( 1 === count( $counts ) ? $counts[0] : null );
	}

	/**
	 * Whether a book of a page count is what is sold: any count is where the
	 * product or variation names none.
	 *
	 * @param int      $count The book's page count.
	 * @param int|null $sold  The pages the product or variation is sold at; null when it names none.
	 */
	public static function count_sold( int $count, ?int $sold ): bool {
		return null === $sold || $count === $sold;
	}

	/**
	 * A cart accepts the live version and the one before it, so a customer
	 * who started before a publish can still buy what they made. A page
	 * layout's versions are accepted alike (`PageTemplate::accepted_versions()`).
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
