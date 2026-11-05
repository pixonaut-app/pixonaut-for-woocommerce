<?php
/**
 * How the designer draws a print size whose ratio is not the template's.
 *
 * @package Pixonaut\WooCommerce
 */

declare(strict_types=1);

namespace Pixonaut\WooCommerce\Catalogue;

/**
 * The product screen's choice of the canvas the customer draws on, as the
 * SDK's `drawAtTemplateRatio` mount option takes it: null follows the
 * template's own setting, true draws at the template's ratio whatever size
 * is chosen (the print is still made at the chosen size, cropped or padded
 * by the template's fit), false reshapes the canvas to each size chosen.
 *
 * Posted and given to the abilities as text: `FOLLOW`, `TEMPLATE` or
 * `PRINT_SIZE`.
 */
final class CanvasRatio {

	/**
	 * Follows the template's own setting.
	 */
	public const FOLLOW = '';

	/**
	 * Always draws at the template's ratio.
	 */
	public const TEMPLATE = 'template';

	/**
	 * Always draws at the chosen print size.
	 */
	public const PRINT_SIZE = 'print_size';

	public const CHOICES = array( self::FOLLOW, self::TEMPLATE, self::PRINT_SIZE );

	/**
	 * The stored value of a posted choice; anything else follows the template.
	 *
	 * @param mixed $posted `FOLLOW`, `TEMPLATE` or `PRINT_SIZE`.
	 */
	public static function from_posted( mixed $posted ): ?bool {
		return match ( $posted ) {
			self::TEMPLATE   => true,
			self::PRINT_SIZE => false,
			default          => null,
		};
	}

	/**
	 * The posted choice of a stored value.
	 *
	 * @param bool|null $value The stored value; null follows the template.
	 */
	public static function posted( ?bool $value ): string {
		return match ( $value ) {
			true  => self::TEMPLATE,
			false => self::PRINT_SIZE,
			null  => self::FOLLOW,
		};
	}

	/**
	 * The stored value read from a document: a boolean, else null.
	 *
	 * @param mixed $stored The stored `drawAtTemplateRatio`.
	 */
	public static function from_stored( mixed $stored ): ?bool {
		return is_bool( $stored ) ? $stored : null;
	}

	/**
	 * The choices' names, by posted choice, for a dropdown.
	 *
	 * @param string $follow The name of following: the template's on a product, the product's on a side.
	 * @return array<string, string>
	 */
	public static function names( string $follow ): array {
		return array(
			self::FOLLOW     => $follow,
			/* translators: Option of the Canvas shape dropdown: the designer's canvas keeps the template's own proportions at every print size. Admin product page. */
			self::TEMPLATE   => __( 'Always the template’s shape', 'pixonaut-for-woocommerce' ),
			/* translators: Option of the Canvas shape dropdown: the designer's canvas takes the proportions of the print size the customer chose. Admin product page. */
			self::PRINT_SIZE => __( 'Always the chosen print size’s shape', 'pixonaut-for-woocommerce' ),
		);
	}
}
