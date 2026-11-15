<?php
/**
 * Whether each value of an attribute is listed as its own product.
 *
 * @package Pixonaut\WooCommerce
 */

declare(strict_types=1);

namespace Pixonaut\WooCommerce\Catalogue;

use LogicException;
use Pixonaut\WooCommerce\Schema;
use WC_Product;
use WC_Product_Attribute;

/**
 * A variable product can be listed once per value of one of its attributes
 * ("Med bild" and "Utan bild" as two cards), its other attributes (sizes)
 * staying inside each card, or once per combination of the values of
 * several. A global attribute turns this on by default for
 * the products using it; each product follows that (Auto), or turns it on or
 * off for itself. The product's choices are kept in its `ProductConfig`.
 *
 * Only global attributes can be split: their values are terms, which the
 * shop's filters, a card's link to its value and a card's own title and
 * image are keyed by. A product's custom attribute can be moved to a global
 * one first.
 */
final class SplitListing {

	/**
	 * The product follows the attribute's setting.
	 */
	public const AUTO = 'auto';

	/**
	 * The product lists each value as its own product, whatever the attribute says.
	 */
	public const ON = 'on';

	/**
	 * The product is listed once, whatever the attribute says.
	 */
	public const OFF = 'off';

	/**
	 * Every choice a product has for an attribute, in the order a click cycles through them.
	 */
	private const CHOICES = array( self::AUTO, self::ON, self::OFF );

	/**
	 * The global attributes, by id, whose values are listed as their own products by default.
	 */
	public const OPTION = Schema::SPLIT_ATTRIBUTES_OPTION;

	/**
	 * Forgets a deleted attribute's default, however it was deleted: the
	 * attribute screens, the REST API or WP-CLI.
	 */
	public static function register(): void {
		add_action( 'woocommerce_attribute_deleted', array( self::class, 'forget_attribute' ) );
	}

	/**
	 * @param int $attribute_id The deleted attribute.
	 */
	public static function forget_attribute( int $attribute_id ): void {
		self::set_attribute_default( $attribute_id, false );
	}

	/**
	 * @return list<string> Every choice a product has for an attribute.
	 */
	public static function available(): array {
		return self::CHOICES;
	}

	/**
	 * Whether a global attribute lists its values as their own products by default.
	 *
	 * @param int $attribute_id A global attribute's id.
	 */
	public static function attribute_default( int $attribute_id ): bool {
		return in_array( $attribute_id, self::default_attributes(), true );
	}

	/**
	 * Turns a global attribute's default on or off.
	 *
	 * @param int  $attribute_id A global attribute's id.
	 * @param bool $split        Whether its values are listed as their own products by default.
	 */
	public static function set_attribute_default( int $attribute_id, bool $split ): void {
		if ( self::attribute_default( $attribute_id ) === $split ) {
			return;
		}
		$ids = array_values( array_diff( self::default_attributes(), array( $attribute_id ) ) );
		if ( $split ) {
			$ids[] = $attribute_id;
			sort( $ids );
		}
		if ( array() === $ids ) {
			delete_option( self::OPTION );
		} else {
			update_option( self::OPTION, $ids, true );
		}
		SplitCards::write_attribute( $attribute_id );
	}

	/**
	 * A product's choice for one attribute.
	 *
	 * @param ProductConfig $config       The product's stored configuration.
	 * @param int           $attribute_id A global attribute's id.
	 * @return string One of `available()`.
	 */
	public static function choice( ProductConfig $config, int $attribute_id ): string {
		return $config->split_listing[ $attribute_id ] ?? self::AUTO;
	}

	/**
	 * Whether a product lists each value of one attribute as its own product:
	 * its own choice, or on Auto the attribute's.
	 *
	 * @param ProductConfig $config       The product's stored configuration.
	 * @param int           $attribute_id A global attribute's id.
	 */
	public static function splits( ProductConfig $config, int $attribute_id ): bool {
		$choice = self::choice( $config, $attribute_id );
		return self::AUTO === $choice ? self::attribute_default( $attribute_id ) : self::ON === $choice;
	}

	/**
	 * The attributes a variable product is listed by, in its order of
	 * attributes; none when it is listed once. Only a global attribute used
	 * for variations splits: those the product turned on itself, else those
	 * on Auto whose attribute is on. With more than one, the product is
	 * listed once per combination of their values (`SplitCards`).
	 *
	 * @param WC_Product $product A product.
	 * @return list<WC_Product_Attribute>
	 */
	public static function attributes_of( WC_Product $product ): array {
		if ( ! $product->is_type( 'variable' ) ) {
			return array();
		}
		$config    = ProductConfig::stored_on( $product );
		$chosen    = array();
		$following = array();
		foreach ( $product->get_attributes() as $attribute ) {
			if ( ! $attribute instanceof WC_Product_Attribute || ! $attribute->is_taxonomy() || ! $attribute->get_variation() ) {
				continue;
			}
			$choice = self::choice( $config, $attribute->get_id() );
			if ( self::ON === $choice ) {
				$chosen[] = $attribute;
			} elseif ( self::AUTO === $choice && self::attribute_default( $attribute->get_id() ) ) {
				$following[] = $attribute;
			}
		}
		return array() === $chosen ? $following : $chosen;
	}

	/**
	 * Drops a product's choices for the attributes it no longer has, so one
	 * added back starts on Auto. Nothing is written when none is dropped.
	 *
	 * @param WC_Product $product A product.
	 */
	public static function forget_removed_attributes( WC_Product $product ): void {
		$config = ProductConfig::stored_on( $product );
		if ( array() === $config->split_listing ) {
			return;
		}
		$ids = array();
		foreach ( $product->get_attributes() as $attribute ) {
			if ( $attribute instanceof WC_Product_Attribute && $attribute->is_taxonomy() ) {
				$ids[] = $attribute->get_id();
			}
		}
		$kept = array_intersect_key( $config->split_listing, array_flip( $ids ) );
		if ( $kept === $config->split_listing ) {
			return;
		}
		try {
			$config->with_split_listing( $kept )->save_to( $product );
		} catch ( LogicException $e ) {
			// A newer plugin version's configuration is kept as it is.
			return;
		}
	}

	/**
	 * Stored or posted choices, kept to attribute ids with an On or Off
	 * choice; Auto, and anything else, is left out.
	 *
	 * @param mixed $value Choices by attribute id.
	 * @return array<int, string>
	 */
	public static function choices_from( mixed $value ): array {
		$choices = array();
		foreach ( is_array( $value ) ? $value : array() as $attribute_id => $choice ) {
			$id = is_int( $attribute_id ) || ctype_digit( $attribute_id ) ? (int) $attribute_id : 0;
			if ( $id > 0 && ( self::ON === $choice || self::OFF === $choice ) ) {
				$choices[ $id ] = $choice;
			}
		}
		ksort( $choices );
		return $choices;
	}

	/**
	 * The ids of the global attributes on by default.
	 *
	 * @return list<int>
	 */
	private static function default_attributes(): array {
		$stored = get_option( self::OPTION, array() );
		$ids    = array();
		foreach ( is_array( $stored ) ? $stored : array() as $id ) {
			if ( is_int( $id ) && $id > 0 ) {
				$ids[] = $id;
			}
		}
		return $ids;
	}
}
