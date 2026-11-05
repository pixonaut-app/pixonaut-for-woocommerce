<?php
/**
 * Whether the designer's form numbers its groups.
 *
 * @package Pixonaut\WooCommerce
 */

declare(strict_types=1);

namespace Pixonaut\WooCommerce;

use Pixonaut\WooCommerce\Catalogue\ProductConfig;
use Pixonaut\WooCommerce\Settings\Settings;

/**
 * The numbers of the designer's form on the product page: the one before each
 * group's name when it is laid out as a page, and the "Step 1 of 4" line when
 * it is laid out in steps. Pixonaut's designer the Personalise button opens
 * draws its own.
 */
final class GroupNumbers {

	public const ON = 'on';

	public const OFF = 'off';

	/**
	 * Every choice a product has, in the order the store manager sees them.
	 */
	private const CHOICES = array( self::ON, self::OFF );

	/**
	 * @return list<string> Every choice a product has.
	 */
	public static function available(): array {
		return self::CHOICES;
	}

	/**
	 * Each choice's name, for the store manager.
	 *
	 * @return array<string, string>
	 */
	public static function names(): array {
		return array(
			/* translators: Option of a product's Group numbers setting: the designer's form numbers its groups. Admin product page. */
			self::ON  => _x( 'On', 'group numbers', 'pixonaut-for-woocommerce' ),
			/* translators: Option of a product's Group numbers setting: the designer's form shows its groups' names with no numbers. Admin product page. */
			self::OFF => _x( 'Off', 'group numbers', 'pixonaut-for-woocommerce' ),
		);
	}

	/**
	 * What the store's setting says.
	 *
	 * @param Settings $settings The store's settings.
	 * @return string One of `available()`.
	 */
	public static function of_store( Settings $settings ): string {
		return true === $settings->get( Settings::GROUP_NUMBERS ) ? self::ON : self::OFF;
	}

	/**
	 * Whether a product's form numbers its groups: its own choice when it
	 * names one, else the store's.
	 *
	 * @param ProductConfig $config   The product's configuration.
	 * @param Settings      $settings The store's settings.
	 */
	public static function shown( ProductConfig $config, Settings $settings ): bool {
		$choice = in_array( $config->group_numbers, self::CHOICES, true ) ? $config->group_numbers : self::of_store( $settings );
		return self::ON === $choice;
	}
}
