<?php
/**
 * Each side's own values, kept apart from the side.
 *
 * @package Pixonaut\WooCommerce
 */

declare(strict_types=1);

namespace Pixonaut\WooCommerce\Catalogue;

use Pixonaut\WooCommerce\Schema;
use WC_Meta_Data;
use WC_Product;

/**
 * The only reader and writer of `_pixonaut_side_values`: each side's own
 * values and the value set Pixonaut made of them (`SideValue`), by side key,
 * on a product or on a variation with a setup of its own. They are kept
 * apart from the configuration, which the product screen, `set-product-setup`
 * and variation generation each build anew, so none of them drops them.
 *
 * A variation that follows its product is sold with its product's values.
 * One with a setup of its own is sold with its own values for a side, and,
 * for a side it has none for, with its product's for the side of that key
 * while both sides are on the template those were made for: a setup of its
 * own is most often only another print size or blank. A side's values hold
 * only while the side is on the template they were made for. A write replaces the document only while it is still the one read,
 * so a slow writer never overwrites values saved after it read.
 *
 * Stored as `{v: 1, sides: {<key>: {template, valueSet, values, layoutSets?, layoutsPending?}}}`,
 * `layoutSets` being the set of each of the side's page layouts by its base
 * id, and `layoutsPending` the layouts whose set could not be made yet; a
 * document without them reads as none. A product's values hold a set for
 * each layout its own side offers, and for each one a variation with a
 * setup of its own offers on a side sold with them (`LayoutValueSets`).
 */
final class SideValues {

	public const META_KEY = Schema::PRODUCT_SIDE_VALUES_META;

	/**
	 * The document's version: a newer one is not read, nor overwritten.
	 */
	public const VERSION = 1;

	/**
	 * Prefix of the lock a product's values are written under (`PostLock`).
	 */
	private const LOCK_PREFIX = 'pixonaut_side_values_';

	/**
	 * Where the values a side is sold with come from: the product or
	 * variation's own.
	 */
	public const FROM_OWN = 'own';

	/**
	 * Where the values a side is sold with come from: a variation's product.
	 */
	public const FROM_PRODUCT = 'product';

	/**
	 * The values a side is sold with (see the class): null when the side has
	 * none, or its values are for another template than it is on now.
	 *
	 * @param WC_Product $product A product or variation.
	 * @param Side       $side    One of the sides it is sold with.
	 */
	public static function of( WC_Product $product, Side $side ): ?SideValue {
		return self::sold_with( $product, $side )['value'];
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
	 * The value set of every template a side is designed on, by base id:
	 * the side's own set under its template's, and the set of each of its
	 * page layouts that has one under the layout's. Empty without values.
	 *
	 * @param WC_Product $product A product or variation.
	 * @param Side       $side    One of the sides it is sold with.
	 * @return array<string, string>
	 */
	public static function sets_of( WC_Product $product, Side $side ): array {
		$value = self::of( $product, $side );
		if ( null === $value ) {
			return array();
		}
		$sets = array( $side->template_base_id => $value->value_set_id );
		foreach ( $side->page_templates as $layout ) {
			$set = $value->layout_set( $layout->base_id );
			if ( null !== $set ) {
				$sets[ $layout->base_id ] = $set;
			}
		}
		return $sets;
	}

	/**
	 * The base ids of a side's page layouts whose set could not be made
	 * (`SideValue::$layouts_pending`): while there is one, a page on it
	 * would open without the side's values, so the side is not sold.
	 *
	 * @param WC_Product $product A product or variation.
	 * @param Side       $side    One of the sides it is sold with.
	 * @return list<string>
	 */
	public static function pending_of( WC_Product $product, Side $side ): array {
		$value = self::of( $product, $side );
		if ( null === $value ) {
			return array();
		}
		$pending = array();
		foreach ( $side->page_templates as $layout ) {
			if ( $value->layout_pending( $layout->base_id ) ) {
				$pending[] = $layout->base_id;
			}
		}
		return $pending;
	}

	/**
	 * Whether a side of the configuration a product or variation is sold
	 * under has a page layout whose set could not be made (`pending_of()`).
	 *
	 * @param WC_Product    $product A product or variation.
	 * @param ProductConfig $config  The configuration it is sold under.
	 */
	public static function any_pending( WC_Product $product, ProductConfig $config ): bool {
		foreach ( $config->sides as $side ) {
			if ( array() !== $side->page_templates && array() !== self::pending_of( $product, $side ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * The values a side is sold with and where they come from:
	 * `FROM_OWN`, `FROM_PRODUCT`, or null with none.
	 *
	 * @param WC_Product $product A product or variation.
	 * @param Side       $side    One of the sides it is sold with.
	 * @return array{value: SideValue|null, from: string|null}
	 */
	public static function sold_with( WC_Product $product, Side $side ): array {
		$parent = $product->is_type( 'variation' ) ? ProductVariants::parent_of( $product ) : null;
		if ( null === $parent ) {
			return self::found( self::applying( $product, $side ), self::FROM_OWN );
		}
		if ( ! ProductConfig::stored_on( $product )->override ) {
			return self::found( self::applying( $parent, $side ), self::FROM_PRODUCT );
		}
		$own = self::applying( $product, $side );
		if ( null !== $own ) {
			return self::found( $own, self::FROM_OWN );
		}
		$document = self::document_on( $product );
		if ( null !== $document && array() === self::decoded( $document ) ) {
			// Values of its own this plugin cannot read (a newer version's): never sold with its product's in their place.
			return self::found( null, null );
		}
		$value = self::applying( $parent, $side );
		if ( null === $value ) {
			return self::found( null, null );
		}
		// The product's values hold for the variation's side only while the product's own side of that key is on their template too.
		foreach ( ProductConfig::stored_on( $parent )->sides as $parent_side ) {
			if ( $parent_side->key === $side->key && $value->applies_to( $parent_side ) ) {
				return self::found( $value, self::FROM_PRODUCT );
			}
		}
		return self::found( null, null );
	}

	/**
	 * The values a product or variation itself keeps for a side, while they
	 * are for the template the side is on.
	 *
	 * @param WC_Product $product A product or variation.
	 * @param Side       $side    The side.
	 */
	private static function applying( WC_Product $product, Side $side ): ?SideValue {
		$value = self::stored_on( $product )[ $side->key ] ?? null;
		return null !== $value && $value->applies_to( $side ) ? $value : null;
	}

	/**
	 * @param SideValue|null $value The values found.
	 * @param string|null    $from  Where they come from.
	 * @return array{value: SideValue|null, from: string|null}
	 */
	private static function found( ?SideValue $value, ?string $from ): array {
		return array(
			'value' => $value,
			'from'  => null === $value ? null : $from,
		);
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
		// The newest row, should a write from before the lock have left two.
		$rows = $product->get_meta( self::META_KEY, false );
		$last = is_array( $rows ) && array() !== $rows ? end( $rows ) : null;
		$raw  = $last instanceof WC_Meta_Data ? $last->value : $last;
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
	 * only while its document is still `$expected` and `$still` holds; none
	 * takes them all off. The stored document is read again and written
	 * under a lock of the product's own (`PostLock`), so two first writers
	 * never both add a row, and a write of the document it read still finds
	 * whether another was stored meanwhile. Rows a write from before the
	 * lock left beside the newest are dropped. Writes the database directly;
	 * `$product` itself is left as it was read.
	 *
	 * @param WC_Product               $product  A product or variation.
	 * @param array<string, SideValue> $sides    Every side's values, by side key.
	 * @param string|null              $expected The document they were made from, as `document_on()` read it; null when none was stored.
	 * @param (callable(): bool)|null  $still    Whether what the values were made for still stands, asked under the lock before writing; null for always.
	 * @return bool False when another document was stored meanwhile, `$still` no longer holds, the stored one is a newer plugin version's, or another write held the lock too long.
	 */
	public static function replace_on( WC_Product $product, array $sides, ?string $expected, ?callable $still = null ): bool {
		if ( ! self::writable( $expected ) ) {
			return false;
		}
		$product_id = $product->get_id();
		return PostLock::alone(
			self::LOCK_PREFIX,
			$product_id,
			static function () use ( $product_id, $sides, $expected, $still ): bool {
				$rows = self::rows( $product_id );
				$now  = array() === $rows ? null : $rows[0]['value'];
				if ( $now !== $expected || ( null !== $still && ! $still() ) ) {
					return false;
				}
				$document = self::encoded( $sides );
				$extra    = array_slice( $rows, 1 );
				if ( $document === $now && array() === $extra ) {
					return true;
				}
				global $wpdb;
				// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.SlowDBQuery.slow_db_query_meta_key, WordPress.DB.SlowDBQuery.slow_db_query_meta_value -- under the product's lock; the caches are dropped below.
				if ( null === $document ) {
					$extra = $rows;
				} elseif ( null === $now ) {
					$wpdb->insert(
						$wpdb->postmeta,
						array(
							'post_id'    => $product_id,
							'meta_key'   => self::META_KEY,
							'meta_value' => $document,
						)
					);
				} elseif ( $document !== $now ) {
					$wpdb->update( $wpdb->postmeta, array( 'meta_value' => $document ), array( 'meta_id' => $rows[0]['id'] ) );
				}
				foreach ( $extra as $row ) {
					$wpdb->delete( $wpdb->postmeta, array( 'meta_id' => $row['id'] ) );
				}
				// phpcs:enable
				ProductMeta::written_directly( $product_id );
				return true;
			},
			static fn (): bool => false
		);
	}

	/**
	 * The product's rows of the document, the newest first, read from the
	 * database: a write from before the lock may have left more than one.
	 *
	 * @param int $product_id A product or variation.
	 * @return list<array{id: int, value: string}>
	 */
	private static function rows( int $product_id ): array {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- read under the product's lock, past every cache.
		$found = $wpdb->get_results( $wpdb->prepare( "SELECT meta_id, meta_value FROM {$wpdb->postmeta} WHERE post_id = %d AND meta_key = %s ORDER BY meta_id DESC", $product_id, self::META_KEY ), ARRAY_A );
		$rows  = array();
		foreach ( is_array( $found ) ? $found : array() as $row ) {
			if ( is_array( $row ) && is_string( $row['meta_value'] ?? null ) && '' !== $row['meta_value'] ) {
				$rows[] = array(
					'id'    => (int) $row['meta_id'],
					'value' => $row['meta_value'],
				);
			}
		}
		return $rows;
	}
}
