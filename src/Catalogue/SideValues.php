<?php
/**
 * Each side's own values, kept apart from the side.
 *
 * @package Pixonaut\WooCommerce
 */

declare(strict_types=1);

namespace Pixonaut\WooCommerce\Catalogue;

use Pixonaut\WooCommerce\Schema;
use WC_Product;

/**
 * The only reader and writer of `_pixonaut_side_values`: each side's own
 * values and the value set Pixonaut made of them (`SideValue`), by side key,
 * on a product or on a variation with a setup of its own. They are kept
 * apart from the configuration, which the product screen, `set-product-setup`
 * and variation generation each build anew, so none of them drops them.
 *
 * A variation that follows its product is sold with its product's values; a
 * side's values hold only while the side is on the template they were made
 * for. A write replaces the document only while it is still the one read,
 * so a slow writer never overwrites values saved after it read.
 *
 * Stored as `{v: 1, sides: {<key>: {template, valueSet, values}}}`.
 */
final class SideValues {

	public const META_KEY = Schema::PRODUCT_SIDE_VALUES_META;

	/**
	 * The document's version: a newer one is not read, nor overwritten.
	 */
	public const VERSION = 1;

	/**
	 * The values a side is sold with, under the configuration it is sold
	 * under: a variation's own when it has a setup of its own, else its
	 * product's; null when the side has none, or its values are for another
	 * template than it is on now.
	 *
	 * @param WC_Product $product A product or variation.
	 * @param Side       $side    One of the sides it is sold with.
	 */
	public static function of( WC_Product $product, Side $side ): ?SideValue {
		$value = self::stored_on( self::holder( $product ) )[ $side->key ] ?? null;
		return null !== $value && $value->applies_to( $side ) ? $value : null;
	}

	/**
	 * The value set a side is sold with (`of()`); null for none.
	 *
	 * @param WC_Product $product A product or variation.
	 * @param Side       $side    One of the sides it is sold with.
	 */
	public static function set_of( WC_Product $product, Side $side ): ?string {
		return self::of( $product, $side )?->value_set_id;
	}

	/**
	 * Where the values a product or variation is sold with are kept: a
	 * variation's own when it has a setup of its own, else its product's.
	 *
	 * @param WC_Product $product A product or variation.
	 */
	public static function holder( WC_Product $product ): WC_Product {
		if ( ! $product->is_type( 'variation' ) || ProductConfig::stored_on( $product )->override ) {
			return $product;
		}
		return ProductVariants::parent_of( $product ) ?? $product;
	}

	/**
	 * The values stored on a product or variation itself, by side key,
	 * whatever template its sides are on now.
	 *
	 * @param WC_Product $product A product or variation.
	 * @return array<string, SideValue>
	 */
	public static function stored_on( WC_Product $product ): array {
		return self::decoded( self::document_on( $product ) );
	}

	/**
	 * The document as stored, for a write over it and for what a picture
	 * depends on; null when none is.
	 *
	 * @param WC_Product $product A product or variation.
	 */
	public static function document_on( WC_Product $product ): ?string {
		$raw = $product->get_meta( self::META_KEY, true );
		return is_string( $raw ) && '' !== $raw ? $raw : null;
	}

	/**
	 * Whether the stored document can be written over: not one from a newer
	 * plugin version.
	 *
	 * @param string|null $document The document, as `document_on()` read it.
	 */
	public static function writable( ?string $document ): bool {
		if ( null === $document ) {
			return true;
		}
		$data = json_decode( $document, true );
		return ! is_array( $data ) || ! is_int( $data['v'] ?? null ) || $data['v'] <= self::VERSION;
	}

	/**
	 * The values a document holds, by side key; an entry in no shape read
	 * here is left out.
	 *
	 * @param string|null $document The document.
	 * @return array<string, SideValue>
	 */
	public static function decoded( ?string $document ): array {
		$data = null === $document ? null : json_decode( $document, true );
		if ( ! is_array( $data ) || self::VERSION !== ( $data['v'] ?? null ) || ! is_array( $data['sides'] ?? null ) ) {
			return array();
		}
		$sides = array();
		foreach ( $data['sides'] as $key => $entry ) {
			$value = SideValue::from_array( $entry );
			if ( null !== $value ) {
				$sides[ (string) $key ] = $value;
			}
		}
		return $sides;
	}

	/**
	 * The document holding the values; null for none.
	 *
	 * @param array<string, SideValue> $sides The values, by side key.
	 */
	public static function encoded( array $sides ): ?string {
		if ( array() === $sides ) {
			return null;
		}
		ksort( $sides, SORT_STRING );
		return (string) wp_json_encode(
			array(
				'v'     => self::VERSION,
				'sides' => array_map( static fn ( SideValue $value ): array => $value->to_array(), $sides ),
			)
		);
	}

	/**
	 * Stores the values on a product or variation, in place of all it holds,
	 * only while its document is still `$expected`; none takes them all off.
	 * Writes the database directly; `$product` itself is left as it was read.
	 *
	 * @param WC_Product               $product  A product or variation.
	 * @param array<string, SideValue> $sides    Every side's values, by side key.
	 * @param string|null              $expected The document they were made from, as `document_on()` read it; null when none was stored.
	 * @return bool False when another document was stored meanwhile, or the stored one is a newer plugin version's.
	 */
	public static function replace_on( WC_Product $product, array $sides, ?string $expected ): bool {
		if ( ! self::writable( $expected ) ) {
			return false;
		}
		$document = self::encoded( $sides );
		if ( $document === $expected ) {
			return true;
		}
		$product_id = $product->get_id();
		if ( null === $expected ) {
			// Added only while the product has none, as `$expected` says.
			$written = false !== add_post_meta( $product_id, self::META_KEY, wp_slash( (string) $document ), true );
		} elseif ( null === $document ) {
			$written = delete_post_meta( $product_id, self::META_KEY, wp_slash( $expected ) );
		} else {
			global $wpdb;
			// One conditional UPDATE: no row matches once another document is stored, or none is.
			// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.SlowDBQuery.slow_db_query_meta_key, WordPress.DB.SlowDBQuery.slow_db_query_meta_value -- update_post_meta() writes whatever is stored; its caches are cleared below.
			$updated = $wpdb->update(
				$wpdb->postmeta,
				array( 'meta_value' => $document ),
				array(
					'post_id'    => $product_id,
					'meta_key'   => self::META_KEY,
					'meta_value' => $expected,
				)
			);
			// phpcs:enable
			$written = is_int( $updated ) && $updated > 0;
		}
		if ( ! $written ) {
			return false;
		}
		clean_post_cache( $product_id );
		wc_delete_product_transients( $product_id );
		return true;
	}
}
