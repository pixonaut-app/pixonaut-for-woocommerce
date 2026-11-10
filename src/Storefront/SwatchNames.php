<?php
/**
 * Whether an attribute's colour and picture swatches show their values' names.
 *
 * @package Pixonaut\WooCommerce
 */

declare(strict_types=1);

namespace Pixonaut\WooCommerce\Storefront;

use Pixonaut\WooCommerce\Schema;
use Pixonaut\WooCommerce\Settings\Settings;

/**
 * A swatch drawn as its value's colour or picture can show the value's name
 * beside it or leave it out, the name then being the swatch's accessible
 * name and tooltip. The store's setting decides for every attribute; a
 * global attribute can show or hide them whatever it says (Inherit follows
 * it). A product's custom attribute follows the store's setting. A value
 * drawn as text always shows its name.
 */
final class SwatchNames {

	/**
	 * The attribute follows the store's setting.
	 */
	public const INHERIT = 'inherit';

	/**
	 * The attribute's swatches show their names.
	 */
	public const SHOW = 'show';

	/**
	 * The attribute's swatches leave their names out.
	 */
	public const HIDE = 'hide';

	/**
	 * Each global attribute's choice other than Inherit, by attribute id.
	 */
	public const OPTION = Schema::SWATCH_NAMES_OPTION;

	/**
	 * Forgets a deleted attribute's choice, however it was deleted.
	 */
	public static function register(): void {
		add_action( 'woocommerce_attribute_deleted', array( self::class, 'forget_attribute' ) );
	}

	/**
	 * @param int $attribute_id The deleted attribute.
	 */
	public static function forget_attribute( int $attribute_id ): void {
		self::set_choice( $attribute_id, self::INHERIT );
	}

	/**
	 * @return list<string> Every choice an attribute has, Inherit first.
	 */
	public static function available(): array {
		return array( self::INHERIT, self::SHOW, self::HIDE );
	}

	/**
	 * A global attribute's choice.
	 *
	 * @param int $attribute_id A global attribute's id.
	 * @return string One of `available()`.
	 */
	public static function choice( int $attribute_id ): string {
		$choice = self::stored()[ $attribute_id ] ?? self::INHERIT;
		return in_array( $choice, self::available(), true ) ? $choice : self::INHERIT;
	}

	/**
	 * Stores a global attribute's choice; anything but Show or Hide stores Inherit.
	 *
	 * @param int    $attribute_id A global attribute's id.
	 * @param string $choice       One of `available()`.
	 */
	public static function set_choice( int $attribute_id, string $choice ): void {
		$choices = self::stored();
		unset( $choices[ $attribute_id ] );
		if ( self::SHOW === $choice || self::HIDE === $choice ) {
			$choices[ $attribute_id ] = $choice;
			ksort( $choices );
		}
		if ( array() === $choices ) {
			delete_option( self::OPTION );
			return;
		}
		update_option( self::OPTION, $choices, true );
	}

	/**
	 * Whether the swatches of an attribute show their values' names: the
	 * global attribute's own choice, else the store's setting.
	 *
	 * @param Settings $settings The store's settings.
	 * @param string   $name     The attribute's name: `pa_frame` for a global one, else as entered.
	 */
	public static function shown( Settings $settings, string $name ): bool {
		$attribute_id = taxonomy_exists( $name ) ? wc_attribute_taxonomy_id_by_name( $name ) : 0;
		$choice       = $attribute_id > 0 ? self::choice( $attribute_id ) : self::INHERIT;
		return self::INHERIT === $choice ? true === $settings->get( Settings::SWATCH_NAMES ) : self::SHOW === $choice;
	}

	/**
	 * @return array<int, string>
	 */
	private static function stored(): array {
		$stored  = get_option( self::OPTION, array() );
		$choices = array();
		foreach ( is_array( $stored ) ? $stored : array() as $attribute_id => $choice ) {
			if ( is_int( $attribute_id ) && is_string( $choice ) ) {
				$choices[ $attribute_id ] = $choice;
			}
		}
		return $choices;
	}
}
