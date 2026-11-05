<?php
/**
 * Where a product's options stand in the designer's form.
 *
 * @package Pixonaut\WooCommerce
 */

declare(strict_types=1);

namespace Pixonaut\WooCommerce;

use Pixonaut\WooCommerce\Catalogue\ProductConfig;
use Pixonaut\WooCommerce\Settings\Settings;

/**
 * A product's options used for variations, in a form nobody arranged by hand:
 * on top of the form, unnumbered, or each in a group of its own named after
 * it, before or after the template's groups. A side with hand-arranged groups
 * keeps them whatever this says.
 */
final class OptionGroups {

	/**
	 * On top of the form, unnumbered.
	 */
	public const ON_TOP = 'on_top';

	/**
	 * Each in a group of its own, before the template's groups.
	 */
	public const FIRST = 'first';

	/**
	 * Each in a group of its own, after the template's groups.
	 */
	public const LAST = 'last';

	/**
	 * Every choice a product has, in the order the store manager sees them.
	 */
	private const CHOICES = array( self::ON_TOP, self::FIRST, self::LAST );

	/**
	 * Where the store's setting can place the groups.
	 */
	private const POSITIONS = array( self::FIRST, self::LAST );

	/**
	 * @return list<string> Every choice a product has.
	 */
	public static function available(): array {
		return self::CHOICES;
	}

	/**
	 * @return list<string> Where the store's setting can place the groups.
	 */
	public static function positions(): array {
		return self::POSITIONS;
	}

	/**
	 * Each choice's name, for the store manager.
	 *
	 * @return array<string, string>
	 */
	public static function names(): array {
		return array(
			/* translators: Option of a product's setting for where its options, such as Size or Colour, stand in the designer's form: above the numbered groups. Admin product page. */
			self::ON_TOP => __( 'On top, unnumbered', 'pixonaut-for-woocommerce' ),
			/* translators: Option of a product's setting for where its options, such as Size or Colour, stand in the designer's form: each in a group of its own, before the template's groups. Admin product page. */
			self::FIRST  => __( 'Own groups, first', 'pixonaut-for-woocommerce' ),
			/* translators: Option of a product's setting for where its options, such as Size or Colour, stand in the designer's form: each in a group of its own, after the template's groups. Admin product page. */
			self::LAST   => __( 'Own groups, last', 'pixonaut-for-woocommerce' ),
		);
	}

	/**
	 * Each position's name, for the store manager.
	 *
	 * @return array<string, string>
	 */
	public static function position_names(): array {
		return array(
			/* translators: Option of the setting for where the groups of a product's options stand: before the template's groups. Settings page. */
			self::FIRST => _x( 'First', 'place of the option groups', 'pixonaut-for-woocommerce' ),
			/* translators: Option of the setting for where the groups of a product's options stand: after the template's groups. Settings page. */
			self::LAST  => _x( 'Last', 'place of the option groups', 'pixonaut-for-woocommerce' ),
		);
	}

	/**
	 * What the store's settings say.
	 *
	 * @param Settings $settings The store's settings.
	 * @return string One of `available()`.
	 */
	public static function of_store( Settings $settings ): string {
		if ( true !== $settings->get( Settings::OPTIONS_AS_GROUPS ) ) {
			return self::ON_TOP;
		}
		return self::LAST === $settings->get( Settings::OPTION_GROUPS_POSITION ) ? self::LAST : self::FIRST;
	}

	/**
	 * Where a product's options stand: its own choice when it names one, else
	 * the store's.
	 *
	 * @param ProductConfig $config   The product's configuration.
	 * @param Settings      $settings The store's settings.
	 * @return string One of `available()`.
	 */
	public static function resolve( ProductConfig $config, Settings $settings ): string {
		return in_array( $config->option_groups, self::CHOICES, true ) ? (string) $config->option_groups : self::of_store( $settings );
	}
}
