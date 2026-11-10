<?php
/**
 * Why a product's Pixonaut configuration cannot be sold.
 *
 * @package Pixonaut\WooCommerce
 */

declare(strict_types=1);

namespace Pixonaut\WooCommerce\Catalogue;

use WC_Product;

/**
 * The codes `ProductConfig::$invalid_reason` holds, and what each tells the
 * store manager. Pixonaut's own verdict reasons (`too_large`, `wrong_ratio`)
 * are passed through; an unknown code reads as a generic reason.
 */
final class InvalidReason {

	public const MISSING_TEMPLATE = 'missing_template';

	public const MISSING_PLACEMENT = 'missing_placement';

	public const UNKNOWN_BLANK = 'unknown_blank';

	/**
	 * The blank is deleted in Pixonaut, which still reads it until it is restored.
	 */
	public const BLANK_DELETED = 'blank_deleted';

	public const UNKNOWN_PLACEMENT = 'unknown_placement';

	/**
	 * Pixonaut has no published template with the id: never published, or gone.
	 */
	public const UNKNOWN_TEMPLATE = 'unknown_template';

	/**
	 * The template is paused or deleted: Pixonaut opens no designer for it.
	 */
	public const TEMPLATE_NOT_LIVE = 'template_not_live';

	public const TOO_MANY_SIDES = 'too_many_sides';

	public const DUPLICATE_PLACEMENT = 'duplicate_placement';

	public const DUPLICATE_SIDE_KEY = 'duplicate_side_key';

	public const INVALID_PRINT_SIZE = 'invalid_print_size';

	public const TOO_MANY_PRINT_SIZES = 'too_many_print_sizes';

	/**
	 * A side without a blank names no print size: it has no placement to take one from.
	 */
	public const MISSING_PRINT_SIZE = 'missing_print_size';

	/**
	 * A variable product without a print size of its own has variations
	 * with none either, of their own or by an attribute value. Said of the
	 * product, which stores `MISSING_PRINT_SIZE`.
	 */
	public const VARIATION_MISSING_PRINT_SIZE = 'variation_missing_print_size';

	/**
	 * Why a product cannot be bought, as its notice and its Pixonaut tab
	 * say it; null when nothing stops it. A variable product without a size
	 * of its own needs none while every variation has one, its own or its
	 * attribute value's.
	 *
	 * @param WC_Product    $product The product or variation.
	 * @param ProductConfig $config  Its configuration as stored.
	 */
	public static function of( WC_Product $product, ProductConfig $config ): ?string {
		if ( ! $config->personalise || $config->valid ) {
			return null;
		}
		if ( self::MISSING_PRINT_SIZE !== $config->invalid_reason || ! $product->is_type( 'variable' ) ) {
			return $config->invalid_reason;
		}
		return VariationSize::covers_variations( $product, $config ) ? null : self::VARIATION_MISSING_PRINT_SIZE;
	}

	/**
	 * Pixonaut: the size exceeds the placement's largest print area.
	 */
	public const TOO_LARGE = 'too_large';

	/**
	 * Pixonaut: the size is outside the template's aspect ratio.
	 */
	public const WRONG_RATIO = 'wrong_ratio';

	/**
	 * Pixonaut: the placement is printed across its whole area, and the size is not that area.
	 */
	public const PRINT_SIZE_NOT_AREA = 'print_size_not_area';

	/**
	 * A variation's template cannot be printed at its blank variant's print
	 * area, or the variant is in none of the blank's rows of print areas.
	 */
	public const VARIANT_SIZE_UNFIT = 'variant_size_unfit';

	/**
	 * A variation's own print size was judged for another template,
	 * placement or variant than it is sold with now.
	 */
	public const VARIATION_SIZE_UNCHECKED = 'variation_size_unchecked';

	/**
	 * Pixonaut could not be asked: not connected, unreachable, or it refused.
	 */
	public const CHECK_FAILED = 'check_failed';

	/**
	 * Pixonaut refused the standing design of a standing side.
	 */
	public const STANDING_REFUSED = 'standing_design_refused';

	/**
	 * Pixonaut refused the standing design: the store's key may not create and approve designs.
	 */
	public const STANDING_FORBIDDEN = 'standing_design_forbidden';

	/**
	 * A standing side on a blank whose print areas change with an option,
	 * whose template cannot be printed at any of the blank's sizes.
	 */
	public const NO_FITTING_SIZE = 'no_fitting_size';

	/**
	 * A product on no blank whose printshop prints only on a blank, as a
	 * print partner's integration does.
	 */
	public const PRINTSHOP_NEEDS_BLANK = 'printshop_needs_blank';

	/**
	 * Reasons found in what the store manager entered rather than in what
	 * Pixonaut answered: only saving the product again clears them.
	 */
	public const INPUT = array(
		self::MISSING_TEMPLATE,
		self::MISSING_PLACEMENT,
		self::TOO_MANY_SIDES,
		self::DUPLICATE_PLACEMENT,
		self::DUPLICATE_SIDE_KEY,
		self::INVALID_PRINT_SIZE,
		self::TOO_MANY_PRINT_SIZES,
		self::MISSING_PRINT_SIZE,
		self::PRINTSHOP_NEEDS_BLANK,
	);

	/**
	 * The text a store manager reads for a reason code.
	 *
	 * @param string|null $reason A code from this class, from Pixonaut, or `ProductConfig::REASON_NEWER_SCHEMA`.
	 */
	public static function message( ?string $reason ): string {
		switch ( $reason ) {
			case self::MISSING_TEMPLATE:
				return __( 'Choose a template.', 'pixonaut-for-woocommerce' );
			case self::MISSING_PLACEMENT:
				return __( 'Choose where on the blank the design is printed.', 'pixonaut-for-woocommerce' );
			case self::UNKNOWN_BLANK:
				return __( 'The blank no longer exists in Pixonaut.', 'pixonaut-for-woocommerce' );
			case self::BLANK_DELETED:
				return __( 'The blank is deleted in Pixonaut. Restore it in the Pixonaut studio, or choose another blank.', 'pixonaut-for-woocommerce' );
			case self::UNKNOWN_PLACEMENT:
				return __( 'The blank no longer has this placement, or Pixonaut cannot size a print for it.', 'pixonaut-for-woocommerce' );
			case self::UNKNOWN_TEMPLATE:
				return __( 'The template is not published in Pixonaut, or no longer exists. Publish it in the Pixonaut studio, or choose another template.', 'pixonaut-for-woocommerce' );
			case self::TEMPLATE_NOT_LIVE:
				return __( 'The template is paused or deleted in Pixonaut. Make it live again, or choose another template.', 'pixonaut-for-woocommerce' );
			case self::TOO_MANY_SIDES:
				return sprintf(
					/* translators: %d: most sides a product can have. */
					__( 'A product has at most %d sides.', 'pixonaut-for-woocommerce' ),
					PostedSides::MAX_SIDES
				);
			case self::DUPLICATE_PLACEMENT:
				return __( 'Two sides use the same placement. Each placement can be one side only.', 'pixonaut-for-woocommerce' );
			case self::DUPLICATE_SIDE_KEY:
				return __( 'Two sides have the same key. Give each side its own.', 'pixonaut-for-woocommerce' );
			case self::INVALID_PRINT_SIZE:
				return __( 'A custom print size needs a width and height in millimetres, both above zero.', 'pixonaut-for-woocommerce' );
			case self::TOO_MANY_PRINT_SIZES:
				return sprintf(
					/* translators: %d: most print sizes one side can offer. */
					__( 'A side offers at most %d print sizes.', 'pixonaut-for-woocommerce' ),
					PostedSides::MAX_SIZES
				);
			case self::MISSING_PRINT_SIZE:
				return __( 'Add a print size in millimetres. Without a blank there is no placement to take one from.', 'pixonaut-for-woocommerce' );
			case self::VARIATION_MISSING_PRINT_SIZE:
				return __( 'Some variations have no print size. Add one in millimetres here, one for each value of an attribute, or one on each of those variations.', 'pixonaut-for-woocommerce' );
			case self::TOO_LARGE:
				return __( 'The print size is larger than the placement can print.', 'pixonaut-for-woocommerce' );
			case self::WRONG_RATIO:
				return __( "The print size does not match the template's aspect ratio.", 'pixonaut-for-woocommerce' );
			case self::PRINT_SIZE_NOT_AREA:
				return __( 'The placement is printed across its whole print area: the print size must be that area.', 'pixonaut-for-woocommerce' );
			case self::VARIANT_SIZE_UNFIT:
				return __( "The template cannot be printed at the print area of this variation's blank variant. Choose a template that fits, or stop selling the variation.", 'pixonaut-for-woocommerce' );
			case self::VARIATION_SIZE_UNCHECKED:
				return __( "The variation's print size has not been checked since the product changed. Update the product again.", 'pixonaut-for-woocommerce' );
			case self::CHECK_FAILED:
				return __( 'Pixonaut could not check the configuration. Check the connection, then update the product again.', 'pixonaut-for-woocommerce' );
			case self::STANDING_REFUSED:
				return __( "Pixonaut would not make the design of a standing side. Check the side's template, placement and print size, then update the product again.", 'pixonaut-for-woocommerce' );
			case self::STANDING_FORBIDDEN:
				return __( 'Pixonaut would not make the design of a standing side: the store may not create and approve designs. Check the connection\'s permissions in the Pixonaut studio, then update the product again.', 'pixonaut-for-woocommerce' );
			case self::NO_FITTING_SIZE:
				return __( 'The template of a standing side does not fit the placement at any of the blank\'s sizes, so none of them can be printed. Choose a template that fits.', 'pixonaut-for-woocommerce' );
			case self::PRINTSHOP_NEEDS_BLANK:
				return __( 'Choose a blank, or a printshop that prints without one.', 'pixonaut-for-woocommerce' );
			case ProductConfig::REASON_NEWER_SCHEMA:
				return __( 'The configuration was saved by a newer version of Pixonaut for WooCommerce. Update the plugin.', 'pixonaut-for-woocommerce' );
			default:
				return __( 'Pixonaut cannot print this configuration.', 'pixonaut-for-woocommerce' );
		}
	}
}
