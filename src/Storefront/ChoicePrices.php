<?php
/**
 * Which price a global attribute's choices show on a product page.
 *
 * @package Pixonaut\WooCommerce
 */

declare(strict_types=1);

namespace Pixonaut\WooCommerce\Storefront;

use Pixonaut\WooCommerce\Schema;

/**
 * A global attribute's swatches can show a price beside each value: what an
 * add-on adds to the base product ("+N"), a base choice's lowest price
 * ("from N"), or each choice's own price with the shopper's other choices
 * ("N"). The merchant chooses per attribute, on its edit screen or with
 * the `set-attribute-choice-price` ability; an attribute shows none until
 * then, and so does a product's custom attribute. `ChoicePriceTable` works
 * the amounts out.
 */
final class ChoicePrices {

	/**
	 * The attribute's choices show no price.
	 */
	public const NONE = 'none';

	/**
	 * Each value shows what it adds to the base product.
	 */
	public const ADD_ON = 'add_on';

	/**
	 * Each value shows its lowest price.
	 */
	public const FROM = 'from';

	/**
	 * Each value shows its price with the shopper's other choices.
	 */
	public const PRICE = 'price';

	/**
	 * Each global attribute's choice other than None, by attribute id.
	 */
	public const OPTION = Schema::CHOICE_PRICES_OPTION;

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
		self::set_choice( $attribute_id, self::NONE );
	}

	/**
	 * @return list<string> Every choice an attribute has, None first.
	 */
	public static function available(): array {
		return array( self::NONE, self::ADD_ON, self::FROM, self::PRICE );
	}

	/**
	 * A global attribute's choice.
	 *
	 * @param int $attribute_id A global attribute's id.
	 * @return string One of `available()`.
	 */
	public static function choice( int $attribute_id ): string {
		$choice = self::stored()[ $attribute_id ] ?? self::NONE;
		return in_array( $choice, self::available(), true ) ? $choice : self::NONE;
	}

	/**
	 * Stores a global attribute's choice; anything not available stores None.
	 *
	 * @param int    $attribute_id A global attribute's id.
	 * @param string $choice       One of `available()`.
	 */
	public static function set_choice( int $attribute_id, string $choice ): void {
		$choices = self::stored();
		unset( $choices[ $attribute_id ] );
		if ( self::NONE !== $choice && in_array( $choice, self::available(), true ) ) {
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
	 * The choice of an attribute by its name: the global attribute's own,
	 * else None.
	 *
	 * @param string $name The attribute's name: `pa_frame` for a global one, else as entered.
	 */
	public static function of_name( string $name ): string {
		$attribute_id = taxonomy_exists( $name ) ? wc_attribute_taxonomy_id_by_name( $name ) : 0;
		return $attribute_id > 0 ? self::choice( $attribute_id ) : self::NONE;
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
