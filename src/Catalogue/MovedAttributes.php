<?php
/**
 * The custom attributes a product was moved off.
 *
 * @package Pixonaut\WooCommerce
 */

declare(strict_types=1);

namespace Pixonaut\WooCommerce\Catalogue;

use Pixonaut\WooCommerce\Schema;
use WC_Order_Item_Product;

/**
 * What `AttributeMove` leaves on a product, so what still names the old
 * attribute goes on selecting the same thing: a link with `attribute_storlek=`,
 * a cart line, an order line. Stored as
 * `{<old slug>: {to: <taxonomy>, values: {<old value>: <term slug>}, spelled:
 * {<variation id>: <its old value, when it spells it otherwise>}}}`.
 */
final class MovedAttributes {

	public const META_KEY = Schema::PRODUCT_MOVED_ATTRIBUTES_META;

	/**
	 * What an attribute's field, a link's parameter and a cart line's key
	 * start with.
	 */
	public const PREFIX = 'attribute_';

	/**
	 * Notes a move on the product.
	 *
	 * @param int                   $product_id The product.
	 * @param string                $slug       The old attribute's slug.
	 * @param string                $taxonomy   The global attribute's taxonomy.
	 * @param array<string, string> $values     Each old value as entered, and the term slug it became.
	 * @param array<int, string>    $spelled    The old value of each variation that spells it otherwise than as entered, by its id.
	 */
	public static function record( int $product_id, string $slug, string $taxonomy, array $values, array $spelled = array() ): void {
		$moves          = self::of( $product_id );
		$moves[ $slug ] = array(
			'to'      => $taxonomy,
			'values'  => $values,
			'spelled' => $spelled,
		);
		update_post_meta( $product_id, self::META_KEY, $moves );
	}

	/**
	 * The moves noted on a product, by the old attribute's slug.
	 *
	 * @param int $product_id The product.
	 * @return array<string, array{to: string, values: array<string, string>, spelled: array<int, string>}>
	 */
	public static function of( int $product_id ): array {
		$stored = get_post_meta( $product_id, self::META_KEY, true );
		$moves  = array();
		foreach ( is_array( $stored ) ? $stored : array() as $slug => $move ) {
			if ( is_array( $move ) && is_string( $move['to'] ?? null ) && is_array( $move['values'] ?? null ) ) {
				$moves[ (string) $slug ] = array(
					'to'      => $move['to'],
					'values'  => array_map( 'strval', array_filter( $move['values'], 'is_scalar' ) ),
					'spelled' => is_array( $move['spelled'] ?? null ) ? array_map( 'strval', array_filter( $move['spelled'], 'is_scalar' ) ) : array(),
				);
			}
		}
		return $moves;
	}

	/**
	 * `attribute_*` keys and values as the product names them now: those of
	 * a link's query or a cart line's `variation`. Any other key is kept,
	 * and a key the product has a value for already wins. The order is kept.
	 *
	 * @param int                  $product_id The product.
	 * @param array<string, mixed> $attributes The keys and values.
	 * @return array<string, mixed>
	 */
	public static function translated( int $product_id, array $attributes ): array {
		$moves      = self::of( $product_id );
		$translated = array();
		foreach ( $attributes as $key => $value ) {
			$move = str_starts_with( (string) $key, self::PREFIX ) ? ( $moves[ substr( (string) $key, strlen( self::PREFIX ) ) ] ?? null ) : null;
			if ( null === $move ) {
				$translated[ $key ] = $value;
				continue;
			}
			$new = self::PREFIX . $move['to'];
			if ( ! isset( $attributes[ $new ] ) ) {
				$translated[ $new ] = is_string( $value ) ? self::term_of( $move, $value ) : $value;
			}
		}
		return $translated;
	}

	/**
	 * The term an order line bought under an attribute later moved: a line
	 * keeps the old attribute's name in its meta, which matters for a
	 * variation that takes any value.
	 *
	 * @param int                   $product_id The product.
	 * @param string                $taxonomy   The global attribute's taxonomy.
	 * @param WC_Order_Item_Product $item       The order line.
	 * @return string The term's slug; empty when the line names none.
	 */
	public static function bought( int $product_id, string $taxonomy, WC_Order_Item_Product $item ): string {
		foreach ( self::of( $product_id ) as $slug => $move ) {
			$value = $taxonomy === $move['to'] ? (string) $item->get_meta( $slug ) : '';
			if ( '' !== $value ) {
				return self::term_of( $move, $value );
			}
		}
		return '';
	}

	/**
	 * A variation's `attribute_*` keys and values as they were before the
	 * product's moves, in the same order. WooCommerce hashes them into a cart
	 * line and removes a line whose hash no longer matches, so the hash is
	 * made from these: a move changes no line's hash.
	 *
	 * @param int                   $product_id   The variation's product.
	 * @param array<string, string> $attributes   The keys and values now.
	 * @param int                   $variation_id The variation.
	 * @return array<string, string>
	 */
	public static function as_before( int $product_id, array $attributes, int $variation_id ): array {
		$before = array();
		$moves  = self::of( $product_id );
		foreach ( $attributes as $key => $value ) {
			foreach ( $moves as $slug => $move ) {
				if ( self::PREFIX . $move['to'] === $key ) {
					$key   = self::PREFIX . $slug;
					$found = $move['spelled'][ $variation_id ] ?? array_search( $value, $move['values'], true );
					$value = false === $found ? $value : (string) $found;
					break;
				}
			}
			$before[ $key ] = $value;
		}
		return $before;
	}

	/**
	 * The term slug an old value became, whatever its spelling; the value
	 * itself when it became none.
	 *
	 * @param array{to: string, values: array<string, string>, spelled: array<int, string>} $move  The move.
	 * @param string                                                                        $value The old value.
	 */
	private static function term_of( array $move, string $value ): string {
		foreach ( $move['values'] as $old => $term ) {
			if ( sanitize_title( (string) $old ) === sanitize_title( $value ) ) {
				return $term;
			}
		}
		return $value;
	}
}
