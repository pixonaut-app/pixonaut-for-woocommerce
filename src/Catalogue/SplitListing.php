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
 * several. An attribute lists a card per value always (`ON`), or only
 * while a filter of the shop chooses a value of it (`FILTERED`): a framing
 * makes no cards of its own, and a listing filtered by "Ram" shows the
 * framed card of each design. A global attribute sets this by default for
 * the products using it; each product follows that (Auto), or sets it for
 * itself. The product's choices are kept in its `ProductConfig`.
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
	 * Each value is always listed as its own product.
	 */
	public const ON = 'on';

	/**
	 * Each value is listed as its own product only while a filter chooses a value of the attribute.
	 */
	public const FILTERED = 'filtered';

	/**
	 * The values are not listed as their own products.
	 */
	public const OFF = 'off';

	/**
	 * Every choice a product has for an attribute, in the order a click cycles through them.
	 */
	private const CHOICES = array( self::AUTO, self::ON, self::FILTERED, self::OFF );

	/**
	 * Every way a global attribute lists its values by default.
	 */
	private const MODES = array( self::OFF, self::ON, self::FILTERED );

	/**
	 * The global attributes, by id, whose values are always listed as their own products by default.
	 */
	public const OPTION = Schema::SPLIT_ATTRIBUTES_OPTION;

	/**
	 * The global attributes, by id, whose values are listed as their own
	 * products by default only while a filter chooses one.
	 */
	public const FILTERED_OPTION = Schema::SPLIT_FILTERED_ATTRIBUTES_OPTION;

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
		self::set_attribute_mode( $attribute_id, self::OFF );
	}

	/**
	 * @return list<string> Every choice a product has for an attribute.
	 */
	public static function available(): array {
		return self::CHOICES;
	}

	/**
	 * @return list<string> Every way a global attribute lists its values by default.
	 */
	public static function modes(): array {
		return self::MODES;
	}

	/**
	 * How a global attribute lists its values by default: `OFF`, `ON` or `FILTERED`.
	 *
	 * @param int $attribute_id A global attribute's id.
	 */
	public static function attribute_mode( int $attribute_id ): string {
		if ( in_array( $attribute_id, self::stored_ids( self::OPTION ), true ) ) {
			return self::ON;
		}
		return in_array( $attribute_id, self::stored_ids( self::FILTERED_OPTION ), true ) ? self::FILTERED : self::OFF;
	}

	/**
	 * Sets how a global attribute lists its values by default.
	 *
	 * @param int    $attribute_id A global attribute's id.
	 * @param string $mode         One of `modes()`; anything else is `OFF`.
	 */
	public static function set_attribute_mode( int $attribute_id, string $mode ): void {
		$mode = in_array( $mode, self::MODES, true ) ? $mode : self::OFF;
		if ( self::attribute_mode( $attribute_id ) === $mode ) {
			return;
		}
		foreach ( array(
			self::OPTION          => self::ON,
			self::FILTERED_OPTION => self::FILTERED,
		) as $option => $listed ) {
			$ids = array_values( array_diff( self::stored_ids( $option ), array( $attribute_id ) ) );
			if ( $listed === $mode ) {
				$ids[] = $attribute_id;
				sort( $ids );
			}
			if ( array() === $ids ) {
				delete_option( $option );
			} else {
				update_option( $option, $ids, true );
			}
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
	 * How a product lists the values of one attribute: its own choice, or
	 * on Auto the attribute's.
	 *
	 * @param ProductConfig $config       The product's stored configuration.
	 * @param int           $attribute_id A global attribute's id.
	 * @return string `OFF`, `ON` or `FILTERED`.
	 */
	public static function mode( ProductConfig $config, int $attribute_id ): string {
		$choice = self::choice( $config, $attribute_id );
		return self::AUTO === $choice ? self::attribute_mode( $attribute_id ) : $choice;
	}

	/**
	 * Whether a product lists each value of one attribute as its own
	 * product, always or while filtered.
	 *
	 * @param ProductConfig $config       The product's stored configuration.
	 * @param int           $attribute_id A global attribute's id.
	 */
	public static function splits( ProductConfig $config, int $attribute_id ): bool {
		return self::OFF !== self::mode( $config, $attribute_id );
	}

	/**
	 * The attributes a variable product is listed by, in its order of
	 * attributes, each with its mode (`ON` or `FILTERED`); none when it is
	 * listed once. Only a global attribute used for variations splits:
	 * those the product set itself, else those on Auto whose attribute
	 * does. With more than one, the product is listed once per combination
	 * of their values (`SplitCards`).
	 *
	 * @param WC_Product $product A product.
	 * @return list<array{0: WC_Product_Attribute, 1: string}>
	 */
	public static function modes_of( WC_Product $product ): array {
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
			if ( self::ON === $choice || self::FILTERED === $choice ) {
				$chosen[] = array( $attribute, $choice );
				continue;
			}
			$mode = self::AUTO === $choice ? self::attribute_mode( $attribute->get_id() ) : self::OFF;
			if ( self::OFF !== $mode ) {
				$following[] = array( $attribute, $mode );
			}
		}
		return array() === $chosen ? $following : $chosen;
	}

	/**
	 * The attributes a variable product is listed by, always or while
	 * filtered, in its order of attributes (`modes_of()`).
	 *
	 * @param WC_Product $product A product.
	 * @return list<WC_Product_Attribute>
	 */
	public static function attributes_of( WC_Product $product ): array {
		return array_column( self::modes_of( $product ), 0 );
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
	 * Stored or posted choices, kept to attribute ids with an On, Filtered
	 * or Off choice; Auto, and anything else, is left out.
	 *
	 * @param mixed $value Choices by attribute id.
	 * @return array<int, string>
	 */
	public static function choices_from( mixed $value ): array {
		$choices = array();
		foreach ( is_array( $value ) ? $value : array() as $attribute_id => $choice ) {
			$id = is_int( $attribute_id ) || ctype_digit( $attribute_id ) ? (int) $attribute_id : 0;
			if ( $id > 0 && is_string( $choice ) && in_array( $choice, self::MODES, true ) ) {
				$choices[ $id ] = $choice;
			}
		}
		ksort( $choices );
		return $choices;
	}

	/**
	 * The attribute ids an option holds.
	 *
	 * @param string $option `OPTION` or `FILTERED_OPTION`.
	 * @return list<int>
	 */
	private static function stored_ids( string $option ): array {
		$stored = get_option( $option, array() );
		$ids    = array();
		foreach ( is_array( $stored ) ? $stored : array() as $id ) {
			if ( is_int( $id ) && $id > 0 ) {
				$ids[] = $id;
			}
		}
		return $ids;
	}
}
