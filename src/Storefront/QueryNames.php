<?php
/**
 * The short name each global attribute goes by in the shop's addresses.
 *
 * @package Pixonaut\WooCommerce
 */

declare(strict_types=1);

namespace Pixonaut\WooCommerce\Storefront;

use Pixonaut\WooCommerce\Schema;
use WP;

/**
 * WooCommerce names an attribute in an address by its form field,
 * `?attribute_pa_storlek=50x70-cm` on a product's page, and by its filter,
 * `?filter_storlek=50x70-cm&query_type_storlek=or` on an archive. The shop's
 * addresses name each global attribute by a short name instead,
 * `?storlek=50x70-cm`: one the store gave it, else its slug without `pa_`.
 * Its values keep their slugs.
 *
 * A name WordPress or WooCommerce reads from an address, or another
 * attribute's, is refused when it is set. An attribute whose slug is such a
 * name, and has none of its own, has no short name and keeps WooCommerce's.
 *
 * `ShortAddresses` reads a short name as WooCommerce's, and writes the shop's
 * addresses with it. WooCommerce's own names go on working.
 */
final class QueryNames {

	/**
	 * The names the store gave its attributes, by attribute id.
	 */
	public const OPTION = Schema::ATTRIBUTE_QUERY_NAMES_OPTION;

	/**
	 * The longest name an attribute may be given.
	 */
	public const MAX_LENGTH = 32;

	/**
	 * Lowercase letters and digits, words joined by a hyphen or an underscore.
	 */
	private const PATTERN = '/^[a-z0-9]+(?:[-_][a-z0-9]+)*$/';

	/**
	 * A product page's attribute field, before the attribute's taxonomy (`pa_storlek`).
	 */
	public const FIELD_PREFIX = 'attribute_';

	/**
	 * An archive's attribute filter, before the attribute's slug.
	 */
	public const FILTER_PREFIX = 'filter_';

	/**
	 * How a filter's chosen values combine, before the attribute's slug.
	 */
	public const QUERY_TYPE_PREFIX = 'query_type_';

	/**
	 * The way the Product Filters block combines a filter's values unless
	 * its block says otherwise: a product holding any of them. A short
	 * address names no way, and means this one.
	 */
	public const ANY_VALUE = 'or';

	/**
	 * What WooCommerce puts before an attribute's slug to make its taxonomy.
	 */
	private const TAXONOMY_PREFIX = 'pa_';

	/**
	 * The beginnings of names WooCommerce, WordPress and this plugin read
	 * from an address, or that a link's tracking keeps.
	 */
	private const RESERVED_PREFIXES = array( self::FIELD_PREFIX, self::FILTER_PREFIX, self::QUERY_TYPE_PREFIX, self::TAXONOMY_PREFIX, 'pixonaut', 'utm_', 'wc-', 'wc_', 'wp-', 'wp_' );

	/**
	 * Names WooCommerce reads from a shop's addresses that are not query
	 * variables WordPress knows: its cart links, its filters and sorting,
	 * and the Product Filters block's taxonomies.
	 */
	private const RESERVED = array(
		'add-to-cart',
		'added-to-cart',
		'remove_item',
		'removed_item',
		'undo_item',
		'quantity',
		'product_id',
		'variation_id',
		'min_price',
		'max_price',
		'rating_filter',
		'orderby',
		'order',
		'paged',
		'product-page',
		'categories',
		'tags',
		'brands',
		'key',
		'order-pay',
		'order-received',
		'pay_for_order',
		'customize_changeset_uuid',
		'customize_theme',
		'customize_messenger_channel',
		'lang',
		'amp',
	);

	/**
	 * Forgets a deleted attribute's name, however it was deleted.
	 */
	public static function register(): void {
		add_action( 'woocommerce_attribute_deleted', array( self::class, 'forget_attribute' ) );
	}

	/**
	 * @param int $attribute_id The deleted attribute.
	 */
	public static function forget_attribute( int $attribute_id ): void {
		$names = self::stored();
		unset( $names[ $attribute_id ] );
		self::store( $names );
	}

	/**
	 * The name the store gave an attribute; empty when it has none of its own.
	 *
	 * @param int $attribute_id A global attribute's id.
	 */
	public static function own( int $attribute_id ): string {
		return self::stored()[ $attribute_id ] ?? '';
	}

	/**
	 * The name an attribute goes by when the store gives it none: its slug.
	 *
	 * @param int $attribute_id A global attribute's id.
	 */
	public static function default_name( int $attribute_id ): string {
		$attribute = wc_get_attribute( $attribute_id );
		return null === $attribute ? '' : substr( (string) $attribute->slug, strlen( self::TAXONOMY_PREFIX ) );
	}

	/**
	 * The name an attribute goes by in addresses; empty for one that has
	 * none, which keeps WooCommerce's.
	 *
	 * @param int $attribute_id A global attribute's id.
	 */
	public static function of( int $attribute_id ): string {
		$attribute = wc_get_attribute( $attribute_id );
		return null === $attribute ? '' : ( self::names()[ substr( (string) $attribute->slug, strlen( self::TAXONOMY_PREFIX ) ) ] ?? '' );
	}

	/**
	 * Every global attribute's name in addresses, by its slug without `pa_`
	 * (`storlek`), in the store's order of attributes. The names the store
	 * gave come first; an attribute's slug serves only while it is free.
	 *
	 * @return array<string, string>
	 */
	public static function names(): array {
		return self::names_of( self::stored() );
	}

	/**
	 * `names()`, from the names the store gave.
	 *
	 * @param array<int, string> $own The names the store gave, by attribute id.
	 * @return array<string, string>
	 */
	private static function names_of( array $own ): array {
		$attributes = array();
		foreach ( wc_get_attribute_taxonomies() as $attribute ) {
			$attributes[ (int) $attribute->attribute_id ] = (string) $attribute->attribute_name;
		}
		$given = array();
		foreach ( $attributes as $id => $slug ) {
			$name = $own[ $id ] ?? '';
			if ( '' !== $name && ! in_array( $name, $given, true ) ) {
				$given[ $slug ] = $name;
			}
		}
		$names = array();
		foreach ( $attributes as $slug ) {
			if ( isset( $given[ $slug ] ) ) {
				$names[ $slug ] = $given[ $slug ];
			} elseif ( ! in_array( $slug, $given, true ) && ! in_array( $slug, $names, true ) && self::usable( $slug ) ) {
				$names[ $slug ] = $slug;
			}
		}
		return $names;
	}

	/**
	 * Why a name cannot be an attribute's; null when it can. An empty name
	 * gives the attribute back its slug.
	 *
	 * @param string $name         The name.
	 * @param int    $attribute_id The attribute it is for; 0 for one being added.
	 */
	public static function refusal( string $name, int $attribute_id ): ?string {
		if ( '' === $name ) {
			return null;
		}
		if ( strlen( $name ) > self::MAX_LENGTH || 1 !== preg_match( self::PATTERN, $name ) ) {
			/* translators: %d: The longest name, in characters. Refusal of the name an attribute goes by in the shop's addresses. */
			return sprintf( __( 'Use lowercase letters, digits, and hyphens or underscores between them, at most %d characters.', 'pixonaut-for-woocommerce' ), self::MAX_LENGTH );
		}
		if ( ! self::usable( $name ) ) {
			/* translators: %s: The refused name. Refusal of the name an attribute goes by in the shop's addresses. */
			return sprintf( __( 'WordPress or WooCommerce already reads “%s” in the shop\'s addresses. Choose another name.', 'pixonaut-for-woocommerce' ), $name );
		}
		foreach ( self::names_without( $attribute_id ) as $slug => $taken ) {
			if ( $taken === $name ) {
				$other = (string) ( wc_get_attribute( wc_attribute_taxonomy_id_by_name( $slug ) )->name ?? $slug );
				/* translators: 1: The refused name, 2: The attribute that has it. Refusal of the name an attribute goes by in the shop's addresses. */
				return sprintf( __( 'The attribute %2$s already goes by “%1$s” in the shop\'s addresses. Choose another name.', 'pixonaut-for-woocommerce' ), $name, $other );
			}
		}
		return null;
	}

	/**
	 * Stores an attribute's name; an empty one, or its slug, gives it back
	 * its slug. Refuses a name `refusal()` refuses, leaving it as it was.
	 *
	 * @param int    $attribute_id A global attribute's id.
	 * @param string $name         The name.
	 * @return string|null Why it was refused; null once stored.
	 */
	public static function set( int $attribute_id, string $name ): ?string {
		$refusal = self::refusal( $name, $attribute_id );
		if ( null !== $refusal ) {
			return $refusal;
		}
		$names = self::stored();
		unset( $names[ $attribute_id ] );
		if ( '' !== $name && self::default_name( $attribute_id ) !== $name ) {
			$names[ $attribute_id ] = $name;
		}
		self::store( $names );
		return null;
	}

	/**
	 * An address with each attribute WooCommerce names its way named by its
	 * short name instead: `attribute_pa_storlek` and `filter_storlek` become
	 * `storlek`, and a filter's `query_type_storlek=or` goes, as a short
	 * address means it. Anything else in it stays as it is, in its order.
	 *
	 * @param string $url An address.
	 */
	public static function shorten( string $url ): string {
		$names = self::names();
		if ( array() === $names ) {
			return $url;
		}
		$fragment = '';
		$hash     = strpos( $url, '#' );
		if ( false !== $hash ) {
			$fragment = substr( $url, $hash );
			$url      = substr( $url, 0, $hash );
		}
		$mark = strpos( $url, '?' );
		if ( false === $mark ) {
			return $url . $fragment;
		}
		$pairs = array();
		foreach ( explode( '&', substr( $url, $mark + 1 ) ) as $pair ) {
			if ( '' !== $pair ) {
				$parts   = explode( '=', $pair, 2 );
				$pairs[] = array( rawurldecode( $parts[0] ), $parts[1] ?? null, $parts[0] );
			}
		}
		$shortened = array();
		foreach ( $pairs as list( $key ) ) {
			$slug = self::slug_after( $key, self::FILTER_PREFIX, $names );
			if ( null !== $slug ) {
				$shortened[ $slug ] = true;
			}
		}
		$kept = array();
		foreach ( $pairs as list( $key, $value, $raw ) ) {
			$slug = self::slug_after( $key, self::FIELD_PREFIX . self::TAXONOMY_PREFIX, $names ) ?? self::slug_after( $key, self::FILTER_PREFIX, $names );
			if ( null !== $slug ) {
				$raw = $names[ $slug ];
			} else {
				$typed = self::slug_after( $key, self::QUERY_TYPE_PREFIX, $names );
				if ( null !== $typed && isset( $shortened[ $typed ] ) && self::ANY_VALUE === strtolower( rawurldecode( (string) $value ) ) ) {
					continue;
				}
			}
			$kept[] = $raw . ( null === $value ? '' : '=' . $value );
		}
		$base = substr( $url, 0, $mark );
		return ( array() === $kept ? $base : $base . '?' . implode( '&', $kept ) ) . $fragment;
	}

	/**
	 * A request's query with each short name read as WooCommerce reads the
	 * attribute: its form field on a product's page, its filter anywhere
	 * else, the filter's values combining as the Product Filters block's do
	 * unless the query says otherwise. A name WooCommerce's own way already
	 * gives a value of keeps that value.
	 *
	 * @param array<array-key, mixed> $query   A request's query, as `$_GET` holds it.
	 * @param bool                    $product Whether the request is for a product's page.
	 * @return array<string, mixed> The names to add, with their values.
	 */
	public static function lengthened( array $query, bool $product ): array {
		$added = array();
		foreach ( self::names() as $slug => $name ) {
			if ( ! isset( $query[ $name ] ) || ! is_string( $query[ $name ] ) ) {
				continue;
			}
			$key = $product ? self::FIELD_PREFIX . self::TAXONOMY_PREFIX . $slug : self::FILTER_PREFIX . $slug;
			if ( isset( $query[ $key ] ) ) {
				continue;
			}
			$added[ $key ] = $query[ $name ];
			if ( ! $product && ! isset( $query[ self::QUERY_TYPE_PREFIX . $slug ] ) ) {
				$added[ self::QUERY_TYPE_PREFIX . $slug ] = self::ANY_VALUE;
			}
		}
		return $added;
	}

	/**
	 * Whether WordPress's query variables are for a product's own page,
	 * where an attribute's name chooses a value of it rather than filters.
	 *
	 * @param array<array-key, mixed> $query_vars The request's query variables, as WordPress parsed them.
	 */
	public static function is_product_request( array $query_vars ): bool {
		if ( '' !== (string) ( is_scalar( $query_vars['product'] ?? null ) ? $query_vars['product'] : '' ) ) {
			return true;
		}
		$type = $query_vars['post_type'] ?? '';
		return 'product' === $type && ( ! empty( $query_vars['p'] ) || ! empty( $query_vars['name'] ) );
	}

	/**
	 * Whether a name is free of what WordPress and WooCommerce read from an
	 * address.
	 *
	 * @param string $name A name.
	 */
	private static function usable( string $name ): bool {
		foreach ( self::RESERVED_PREFIXES as $prefix ) {
			if ( str_starts_with( $name, $prefix ) ) {
				return false;
			}
		}
		if ( in_array( $name, self::RESERVED, true ) || wc_check_if_attribute_name_is_reserved( $name ) ) {
			return false;
		}
		global $wp;
		$public = $wp instanceof WP ? $wp->public_query_vars : array();
		if ( in_array( $name, $public, true ) ) {
			return false;
		}
		// WooCommerce's account and checkout endpoints, by key and by the slug the store gave each.
		$endpoints = function_exists( 'WC' ) && null !== WC()->query ? WC()->query->get_query_vars() : array();
		foreach ( $endpoints as $key => $slug ) {
			if ( $name === $key || $name === $slug ) {
				return false;
			}
		}
		foreach ( get_taxonomies( array(), 'objects' ) as $taxonomy ) {
			if ( $name === $taxonomy->name || $name === $taxonomy->query_var ) {
				return false;
			}
		}
		foreach ( get_post_types( array(), 'objects' ) as $type ) {
			if ( $name === $type->name || $name === $type->query_var ) {
				return false;
			}
		}
		return true;
	}

	/**
	 * Every attribute's name but one's, as `names()` would give them were
	 * that one to have none of its own.
	 *
	 * @param int $attribute_id The attribute left out.
	 * @return array<string, string>
	 */
	private static function names_without( int $attribute_id ): array {
		$attribute = $attribute_id > 0 ? wc_get_attribute( $attribute_id ) : null;
		$own       = self::stored();
		unset( $own[ $attribute_id ] );
		$names = self::names_of( $own );
		if ( null !== $attribute ) {
			unset( $names[ substr( (string) $attribute->slug, strlen( self::TAXONOMY_PREFIX ) ) ] );
		}
		return $names;
	}

	/**
	 * The attribute an address's name names after a prefix, when it has a
	 * short name; null for any other.
	 *
	 * @param string                $key    A name in an address.
	 * @param string                $prefix One of WooCommerce's prefixes.
	 * @param array<string, string> $names  `names()`.
	 */
	private static function slug_after( string $key, string $prefix, array $names ): ?string {
		if ( ! str_starts_with( $key, $prefix ) ) {
			return null;
		}
		$slug = substr( $key, strlen( $prefix ) );
		return isset( $names[ $slug ] ) ? $slug : null;
	}

	/**
	 * @return array<int, string>
	 */
	private static function stored(): array {
		$stored = get_option( self::OPTION, array() );
		$names  = array();
		foreach ( is_array( $stored ) ? $stored : array() as $attribute_id => $name ) {
			if ( is_int( $attribute_id ) && is_string( $name ) && '' !== $name ) {
				$names[ $attribute_id ] = $name;
			}
		}
		return $names;
	}

	/**
	 * @param array<int, string> $names The names to keep, by attribute id.
	 */
	private static function store( array $names ): void {
		if ( array() === $names ) {
			delete_option( self::OPTION );
			return;
		}
		ksort( $names );
		update_option( self::OPTION, $names, true );
	}
}
