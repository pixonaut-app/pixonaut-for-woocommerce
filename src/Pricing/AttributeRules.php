<?php
/**
 * The pricing rule's parts set on the values of global attributes.
 *
 * @package Pixonaut\WooCommerce
 */

declare(strict_types=1);

namespace Pixonaut\WooCommerce\Pricing;

use Pixonaut\WooCommerce\Schema;
use WC_Product;
use WP_Term;

/**
 * A value of a global attribute (a term of a `pa_` taxonomy) may change
 * parts of the store's pricing rule for every variation with that value, in
 * every product: a poster size a fixed amount, a framing a margin. The
 * parts are kept in the term's `_pixonaut_price_rule` meta, as JSON, as a
 * product keeps its own.
 *
 * Attributes are taken in the store's attribute order, the order of
 * Products → Attributes: when two values of a variation set the same part,
 * the one whose attribute comes first wins.
 *
 * @phpstan-type Clash array{part: string, term: WP_Term, wins: bool}
 */
final class AttributeRules {

	public const META_KEY = Schema::PRICE_RULE_META;

	/**
	 * The global attributes' taxonomies, in the store's attribute order.
	 *
	 * @return list<string>
	 */
	public static function taxonomies(): array {
		$taxonomies = array();
		foreach ( wc_get_attribute_taxonomies() as $attribute ) {
			$taxonomies[] = wc_attribute_taxonomy_name( $attribute->attribute_name );
		}
		return $taxonomies;
	}

	/**
	 * Whether a taxonomy is a global attribute's.
	 *
	 * @param string $taxonomy A taxonomy.
	 */
	public static function is_attribute( string $taxonomy ): bool {
		return in_array( $taxonomy, self::taxonomies(), true );
	}

	/**
	 * The parts a value changes, by part, as written; a part it leaves to
	 * the level after it is empty.
	 *
	 * @param int $term_id The value, by term id.
	 * @return array<string, string>
	 */
	public static function parts( int $term_id ): array {
		$raw    = get_term_meta( $term_id, self::META_KEY, true );
		$stored = is_string( $raw ) && '' !== $raw ? json_decode( $raw, true ) : null;
		$parts  = array();
		foreach ( PriceRule::PARTS as $part ) {
			$written        = is_array( $stored ) ? ( $stored[ $part ] ?? '' ) : '';
			$parts[ $part ] = PriceRule::is_part( $written ) ? PriceRule::part( $written ) : '';
		}
		return $parts;
	}

	/**
	 * Whether a value changes any part.
	 *
	 * @param int $term_id The value, by term id.
	 */
	public static function has( int $term_id ): bool {
		return array() !== array_filter( self::parts( $term_id ), static fn ( string $part ): bool => '' !== $part );
	}

	/**
	 * Stores the parts a value changes; a part that is not a number is left
	 * to the level after it.
	 *
	 * @param int                  $term_id The value, by term id.
	 * @param array<string, mixed> $parts   The parts as posted, by part.
	 * @return bool Whether what is stored changed.
	 */
	public static function save( int $term_id, array $parts ): bool {
		$json = self::json( $parts );
		$was  = get_term_meta( $term_id, self::META_KEY, true );
		if ( ( is_string( $was ) ? $was : '' ) === $json ) {
			return false;
		}
		if ( '' === $json ) {
			delete_term_meta( $term_id, self::META_KEY );
		} else {
			update_term_meta( $term_id, self::META_KEY, wp_slash( $json ) );
		}
		return true;
	}

	/**
	 * Parts as stored: JSON of the ones that are numbers, empty for none.
	 *
	 * @param array<string, mixed> $parts The parts as posted, by part.
	 */
	public static function json( array $parts ): string {
		$own = array();
		foreach ( PriceRule::PARTS as $part ) {
			$written = $parts[ $part ] ?? '';
			if ( PriceRule::is_part( $written ) && '' !== PriceRule::part( $written ) ) {
				$own[ $part ] = PriceRule::part( $written );
			}
		}
		return array() === $own ? '' : (string) wp_json_encode( $own );
	}

	/**
	 * The levels a variation's global attribute values make, each with the
	 * parts it changes, in the store's attribute order; a value that changes
	 * none is left out. A variation for any value of an attribute has none
	 * of it.
	 *
	 * @param WC_Product $variation The variation.
	 * @return list<array{0: RuleSource, 1: array<string, string>}>
	 */
	public static function layers_of( WC_Product $variation ): array {
		$chosen = $variation->is_type( 'variation' ) ? $variation->get_attributes() : array();
		$layers = array();
		foreach ( self::taxonomies() as $taxonomy ) {
			$slug = $chosen[ $taxonomy ] ?? '';
			if ( ! is_string( $slug ) || '' === $slug ) {
				continue;
			}
			$term = get_term_by( 'slug', $slug, $taxonomy );
			if ( ! $term instanceof WP_Term ) {
				continue;
			}
			$parts = self::parts( $term->term_id );
			if ( array() !== array_filter( $parts, static fn ( string $part ): bool => '' !== $part ) ) {
				$layers[] = array( self::source( $term ), $parts );
			}
		}
		return $layers;
	}

	/**
	 * A value as the source of a part.
	 *
	 * @param WP_Term $term The value.
	 */
	public static function source( WP_Term $term ): RuleSource {
		return new RuleSource( RuleSource::ATTRIBUTE, $term->term_id, $term->taxonomy, wc_attribute_label( $term->taxonomy ), $term->name, $term->slug );
	}

	/**
	 * Every value that changes a part, in the store's attribute order, then
	 * by name.
	 *
	 * @return list<WP_Term>
	 */
	public static function all(): array {
		$taxonomies = array_values( array_filter( self::taxonomies(), 'taxonomy_exists' ) );
		if ( array() === $taxonomies ) {
			return array();
		}
		$terms = get_terms(
			array(
				'taxonomy'   => $taxonomies,
				'hide_empty' => false,
				// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- few terms carry it; read by the admin and the abilities only.
				'meta_key'   => self::META_KEY,
			)
		);
		$terms = is_array( $terms ) ? array_values( $terms ) : array();
		$order = array_flip( $taxonomies );
		usort( $terms, static fn ( WP_Term $a, WP_Term $b ): int => array( $order[ $a->taxonomy ] ?? 0, $a->name ) <=> array( $order[ $b->taxonomy ] ?? 0, $b->name ) );
		return $terms;
	}

	/**
	 * The parts a value sets that a value of another attribute sets too, on
	 * some variation with both: whether this value wins (its attribute comes
	 * first in the store's attribute order), and the other value.
	 *
	 * @param WP_Term $term The value.
	 * @return list<Clash>
	 */
	public static function clashes( WP_Term $term ): array {
		return self::all_clashes()[ $term->term_id ] ?? array();
	}

	/**
	 * Every value's clashes, by term id; a value without any is left out.
	 * One query finds which values share a variation, whatever the number
	 * of values with a rule.
	 *
	 * @return array<int, list<Clash>>
	 */
	public static function all_clashes(): array {
		$terms = self::all();
		$parts = array();
		foreach ( $terms as $term ) {
			$parts[ $term->term_id ] = array_filter( self::parts( $term->term_id ), static fn ( string $part ): bool => '' !== $part );
		}
		$order   = array_flip( self::taxonomies() );
		$shared  = self::shared_variations( $terms );
		$clashes = array();
		foreach ( $terms as $term ) {
			foreach ( $terms as $other ) {
				if ( $other->taxonomy === $term->taxonomy || ! isset( $shared[ self::pair_key( $term, $other ) ] ) ) {
					continue;
				}
				foreach ( array_keys( array_intersect_key( $parts[ $term->term_id ], $parts[ $other->term_id ] ) ) as $part ) {
					$clashes[ $term->term_id ][] = array(
						'part' => (string) $part,
						'term' => $other,
						'wins' => ( $order[ $term->taxonomy ] ?? 0 ) < ( $order[ $other->taxonomy ] ?? 0 ),
					);
				}
			}
		}
		return $clashes;
	}

	/**
	 * The pairs of values, of two attributes, that some variation has both
	 * of, as `pair_key()` names them.
	 *
	 * @param WP_Term[] $terms Values with a rule.
	 * @phpstan-param list<WP_Term> $terms
	 * @return array<string, true>
	 */
	private static function shared_variations( array $terms ): array {
		global $wpdb;
		$keys  = array_values( array_unique( array_map( static fn ( WP_Term $term ): string => 'attribute_' . $term->taxonomy, $terms ) ) );
		$slugs = array_values( array_unique( array_map( static fn ( WP_Term $term ): string => $term->slug, $terms ) ) );
		if ( count( $keys ) < 2 ) {
			return array();
		}
		$key_list  = implode( ', ', array_fill( 0, count( $keys ), '%s' ) );
		$slug_list = implode( ', ', array_fill( 0, count( $slugs ), '%s' ) );
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- pairs of a variation's attribute values, which no meta query can join; the lists are placeholders; read on the admin's term screen and by the abilities only.
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT DISTINCT a.meta_key AS ak, a.meta_value AS av, b.meta_key AS bk, b.meta_value AS bv FROM {$wpdb->postmeta} a JOIN {$wpdb->postmeta} b ON b.post_id = a.post_id AND b.meta_key > a.meta_key JOIN {$wpdb->posts} p ON p.ID = a.post_id WHERE p.post_type = 'product_variation' AND a.meta_key IN ($key_list) AND b.meta_key IN ($key_list) AND a.meta_value IN ($slug_list) AND b.meta_value IN ($slug_list)",
				array_merge( $keys, $keys, $slugs, $slugs )
			),
			ARRAY_A
		);
		// phpcs:enable
		$shared = array();
		foreach ( is_array( $rows ) ? $rows : array() as $row ) {
			$one                           = substr( (string) $row['ak'], strlen( 'attribute_' ) ) . ' ' . $row['av'];
			$other                         = substr( (string) $row['bk'], strlen( 'attribute_' ) ) . ' ' . $row['bv'];
			$shared[ $one . '|' . $other ] = true;
			$shared[ $other . '|' . $one ] = true;
		}
		return $shared;
	}

	/**
	 * Two values as `shared_variations()` names their pair.
	 *
	 * @param WP_Term $one   A value.
	 * @param WP_Term $other A value of another attribute.
	 */
	private static function pair_key( WP_Term $one, WP_Term $other ): string {
		return $one->taxonomy . ' ' . $one->slug . '|' . $other->taxonomy . ' ' . $other->slug;
	}
}
