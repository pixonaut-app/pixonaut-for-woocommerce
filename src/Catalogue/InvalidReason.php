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
 * store manager. Pixonaut's own verdict reasons (`PIXONAUT_VERDICTS`) are
 * passed through; a code without a message of its own is named as it is.
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
	 * The refusals Pixonaut answers when it judges a print size
	 * (`POST /v1/templates/{id}/print-size`), each a reason of its own.
	 */
	public const PIXONAUT_VERDICTS = array( self::TOO_LARGE, self::WRONG_RATIO, self::PRINT_SIZE_NOT_AREA );

	/**
	 * Reasons that come with the error code Pixonaut answered, which says
	 * more than the reason does.
	 */
	private const REFUSED_REQUESTS = array( self::CHECK_FAILED, self::STANDING_REFUSED );

	/**
	 * Whether a code Pixonaut can answer, as a verdict on a print size or
	 * as a refusal of a request a check makes, has a message of its own.
	 *
	 * @param string $code The code.
	 */
	public static function knows( string $code ): bool {
		return in_array( $code, self::PIXONAUT_VERDICTS, true ) || PixonautRefusal::knows( $code );
	}

	/**
	 * Where a product's Pixonaut settings are set, as the messages name it.
	 *
	 * @param bool $variation Whether they are a variation's own.
	 */
	public static function place( bool $variation ): string {
		return $variation
			/* translators: Where a variation's Pixonaut settings are changed, used after "in" in messages such as "Choose a template in %s." Admin product page. */
			? __( 'the variation’s Pixonaut fields', 'pixonaut-for-woocommerce' )
			/* translators: Where a product's Pixonaut settings are changed, used after "in" in messages such as "Choose a template in %s." Admin product page. */
			: __( 'the product’s Pixonaut tab', 'pixonaut-for-woocommerce' );
	}

	/**
	 * The text a store manager reads for a reason code: what is wrong,
	 * where, and what to do.
	 *
	 * @param string|null        $reason    A code from this class, from Pixonaut, or `ProductConfig::REASON_NEWER_SCHEMA`.
	 * @param InvalidDetail|null $detail    The side, size and Pixonaut error code it is about, if known.
	 * @param bool               $variation Whether it is about a variation's own configuration.
	 */
	public static function message( ?string $reason, ?InvalidDetail $detail = null, bool $variation = false ): string {
		$text  = self::text( $reason, $detail?->code, $variation );
		$where = null === $detail ? '' : $detail->where();
		return '' === $where ? $text : sprintf(
			/* translators: 1: where on the product, e.g. "Side “Front” at 300 × 400 mm", 2: what is wrong there and how to fix it. */
			__( '%1$s: %2$s', 'pixonaut-for-woocommerce' ),
			$where,
			$text
		);
	}

	/**
	 * Why a product cannot be bought, as `message()` says it, naming the
	 * side, size or Pixonaut error code stored with the reason; null when
	 * nothing stops it.
	 *
	 * @param WC_Product    $product   The product or variation.
	 * @param ProductConfig $config    Its configuration as stored.
	 * @param bool          $variation Whether it is a variation's own; a detail about a variation's own size says so itself.
	 */
	public static function explain( WC_Product $product, ProductConfig $config, bool $variation = false ): ?string {
		$reason = self::of( $product, $config );
		if ( null === $reason ) {
			return null;
		}
		return $reason === $config->invalid_reason ? self::stored( $config, $variation ) : self::message( $reason, null, $variation );
	}

	/**
	 * The reason a configuration stores, as `message()` says it with the
	 * side, size or Pixonaut error code stored with it.
	 *
	 * @param ProductConfig $config    The configuration.
	 * @param bool          $variation Whether it is a variation's own; a detail about a variation's own size says so itself.
	 */
	public static function stored( ProductConfig $config, bool $variation = false ): string {
		return self::message( $config->invalid_reason, $config->invalid_detail, $variation || true === $config->invalid_detail?->own );
	}

	/**
	 * The text for a reason code, naming no side or size.
	 *
	 * @param string|null $reason    The reason code.
	 * @param string|null $code      The error code Pixonaut answered, if any.
	 * @param bool        $variation Whether it is about a variation's own configuration.
	 */
	private static function text( ?string $reason, ?string $code, bool $variation ): string {
		$place = self::place( $variation );
		if ( null !== $code && in_array( $reason, self::REFUSED_REQUESTS, true ) ) {
			return self::refused( $reason, $code, $variation );
		}
		switch ( $reason ) {
			case null:
				return __( 'Pixonaut cannot print this configuration.', 'pixonaut-for-woocommerce' );
			case self::MISSING_TEMPLATE:
				return sprintf(
					/* translators: %s: where the settings are, e.g. "the product's Pixonaut tab". */
					__( 'No template is chosen. Choose one in %s.', 'pixonaut-for-woocommerce' ),
					$place
				);
			case self::MISSING_PLACEMENT:
				return sprintf(
					/* translators: %s: where the settings are, e.g. "the product's Pixonaut tab". */
					__( 'No print position is chosen for where on the blank the design is printed. Choose one in %s.', 'pixonaut-for-woocommerce' ),
					$place
				);
			case self::UNKNOWN_BLANK:
				return sprintf(
					/* translators: %s: where the settings are, e.g. "the product's Pixonaut tab". */
					__( 'The blank no longer exists in Pixonaut. Choose another blank in %s.', 'pixonaut-for-woocommerce' ),
					$place
				);
			case self::BLANK_DELETED:
				return sprintf(
					/* translators: %s: where the settings are, e.g. "the product's Pixonaut tab". */
					__( 'The blank is deleted in Pixonaut. Restore it in the Pixonaut studio, or choose another blank in %s.', 'pixonaut-for-woocommerce' ),
					$place
				);
			case self::UNKNOWN_PLACEMENT:
				return sprintf(
					/* translators: %s: where the settings are, e.g. "the product's Pixonaut tab". */
					__( 'The blank no longer has this print position, or Pixonaut cannot size a print for it. Choose another print position in %s.', 'pixonaut-for-woocommerce' ),
					$place
				);
			case self::UNKNOWN_TEMPLATE:
				return sprintf(
					/* translators: %s: where the settings are, e.g. "the product's Pixonaut tab". */
					__( 'The template is not published in Pixonaut, or no longer exists. Publish it in the Pixonaut studio, or choose another template in %s.', 'pixonaut-for-woocommerce' ),
					$place
				);
			case self::TEMPLATE_NOT_LIVE:
				return sprintf(
					/* translators: %s: where the settings are, e.g. "the product's Pixonaut tab". */
					__( 'The template is paused or deleted in Pixonaut. Make it live again in the Pixonaut studio, or choose another template in %s.', 'pixonaut-for-woocommerce' ),
					$place
				);
			case self::TOO_MANY_SIDES:
				return sprintf(
					/* translators: 1: most sides a product can have, 2: where the settings are, e.g. "the product's Pixonaut tab". */
					__( 'A product has at most %1$d sides. Remove sides in %2$s.', 'pixonaut-for-woocommerce' ),
					PostedSides::MAX_SIDES,
					$place
				);
			case self::DUPLICATE_PLACEMENT:
				return sprintf(
					/* translators: %s: where the settings are, e.g. "the product's Pixonaut tab". */
					__( 'Two sides use the same print position, and each print position can be one side only. Choose another print position for one of them in %s.', 'pixonaut-for-woocommerce' ),
					$place
				);
			case self::DUPLICATE_SIDE_KEY:
				return sprintf(
					/* translators: %s: where the settings are, e.g. "the product's Pixonaut tab". */
					__( 'Two sides have the same key. Give each side its own in %s.', 'pixonaut-for-woocommerce' ),
					$place
				);
			case self::INVALID_PRINT_SIZE:
				return sprintf(
					/* translators: %s: where the settings are, e.g. "the product's Pixonaut tab". */
					__( 'A custom print size has no width or height, or one that is not above zero. Enter both in millimetres under the side’s print sizes in %s.', 'pixonaut-for-woocommerce' ),
					$place
				);
			case self::TOO_MANY_PRINT_SIZES:
				return sprintf(
					/* translators: 1: most print sizes one side can offer, 2: where the settings are, e.g. "the product's Pixonaut tab". */
					__( 'A side offers at most %1$d print sizes. Remove sizes under the side’s print sizes in %2$s.', 'pixonaut-for-woocommerce' ),
					PostedSides::MAX_SIZES,
					$place
				);
			case self::MISSING_PRINT_SIZE:
				return sprintf(
					/* translators: %s: where the settings are, e.g. "the product's Pixonaut tab". */
					__( 'The side has no print size, and without a blank there is no print position to take one from. Add a size in millimetres in %s, or choose a blank.', 'pixonaut-for-woocommerce' ),
					$place
				);
			case self::VARIATION_MISSING_PRINT_SIZE:
				return sprintf(
					/* translators: %s: where the product's settings are, e.g. "the product's Pixonaut tab". */
					__( 'Some variations have no print size. Add one in millimetres in %s, or one for each value of an attribute there, or add one in each of those variations’ Pixonaut fields.', 'pixonaut-for-woocommerce' ),
					self::place( false )
				);
			case self::TOO_LARGE:
				return sprintf(
					/* translators: %s: where the settings are, e.g. "the product's Pixonaut tab". */
					__( 'The print size is larger than the print position can print. Enter a smaller size, or choose the print position’s size, in %s.', 'pixonaut-for-woocommerce' ),
					$place
				);
			case self::WRONG_RATIO:
				return sprintf(
					/* translators: %s: where the settings are, e.g. "the product's Pixonaut tab". */
					__( 'The print size does not match the template’s aspect ratio. Enter a size at the template’s ratio, or choose the print position’s size, in %s.', 'pixonaut-for-woocommerce' ),
					$place
				);
			case self::PRINT_SIZE_NOT_AREA:
				return sprintf(
					/* translators: %s: where the settings are, e.g. "the product's Pixonaut tab". */
					__( 'The print position is printed across its whole print area, so the print size must be that area. Choose the print position’s size instead in %s.', 'pixonaut-for-woocommerce' ),
					$place
				);
			case self::VARIANT_SIZE_UNFIT:
				return __( 'The template cannot be printed at the print area of this variation’s blank variant. Choose a template that fits, or another variant, in the variation’s Pixonaut fields, or stop selling the variation.', 'pixonaut-for-woocommerce' );
			case self::VARIATION_SIZE_UNCHECKED:
				return __( 'The variation’s own print size has not been checked since the product changed. Update the product to check it again.', 'pixonaut-for-woocommerce' );
			case self::CHECK_FAILED:
				return __( 'Pixonaut could not be reached to check the configuration. Check the connection on the Pixonaut settings page, then update the product.', 'pixonaut-for-woocommerce' );
			case self::STANDING_REFUSED:
				return sprintf(
					/* translators: %s: where the settings are, e.g. "the product's Pixonaut tab". */
					__( 'Pixonaut would not make the design of a fixed-design side. Check the side’s template, print position and print size in %s, then update the product.', 'pixonaut-for-woocommerce' ),
					$place
				);
			case self::STANDING_FORBIDDEN:
				return __( 'Pixonaut would not make the design of a fixed-design side: the store may not create and approve designs. Check the connection’s permissions in the Pixonaut studio, then update the product.', 'pixonaut-for-woocommerce' );
			case self::NO_FITTING_SIZE:
				return sprintf(
					/* translators: %s: where the settings are, e.g. "the product's Pixonaut tab". */
					__( 'The template of a fixed-design side does not fit the print position at any of the blank’s sizes, so none of them can be printed. Choose a template that fits in %s.', 'pixonaut-for-woocommerce' ),
					$place
				);
			case self::PRINTSHOP_NEEDS_BLANK:
				return sprintf(
					/* translators: %s: where the settings are, e.g. "the product's Pixonaut tab". */
					__( 'The printshop prints only on a blank. Choose a blank, or a printshop that prints without one, in %s.', 'pixonaut-for-woocommerce' ),
					$place
				);
			case ProductConfig::REASON_NEWER_SCHEMA:
				return __( 'The configuration was saved by a newer version of Pixonaut for WooCommerce. Update the plugin.', 'pixonaut-for-woocommerce' );
			default:
				return self::unknown( $reason );
		}
	}

	/**
	 * A request of a check that Pixonaut refused, with its own message, or
	 * its code when it has none.
	 *
	 * @param string $reason    `CHECK_FAILED` or `STANDING_REFUSED`.
	 * @param string $code      The error code Pixonaut answered.
	 * @param bool   $variation Whether it is about a variation's own configuration.
	 */
	private static function refused( string $reason, string $code, bool $variation ): string {
		$message = PixonautRefusal::message( $code, $variation ) ?? self::unknown( $code );
		return self::STANDING_REFUSED !== $reason ? $message : sprintf(
			/* translators: %s: why, and what to do, e.g. "Pixonaut was asked too often. Wait a minute, then update the product." */
			__( 'Pixonaut would not make the design of a fixed-design side. %s', 'pixonaut-for-woocommerce' ),
			$message
		);
	}

	/**
	 * A code this plugin has no message for, named as it is.
	 *
	 * @param string $code The code.
	 */
	private static function unknown( string $code ): string {
		return sprintf(
			/* translators: %s: the code Pixonaut answered, e.g. "too_small", kept as it is. */
			__( 'Pixonaut cannot print this configuration, and answered “%s”. Update Pixonaut for WooCommerce, or contact Pixonaut with this code.', 'pixonaut-for-woocommerce' ),
			$code
		);
	}
}
