<?php
/**
 * How one side's designer form is arranged on the product page.
 *
 * @package Pixonaut\WooCommerce
 */

declare(strict_types=1);

namespace Pixonaut\WooCommerce\Catalogue;

/**
 * The store manager's arrangement of a side's form: the template's groups in
 * their order, the product's variation attributes placed among the
 * template's fields, groups of the product's own, and words that replace
 * the template's for this product in every language. Passed to the designer
 * as its `formGroups` mount option, an attribute item standing in for the
 * add-to-cart form's field until the storefront script finds it.
 *
 * Stored as `{groups: [{id, title?, items: [...]}]}`, an item being
 * `{field: "<layerId>:<prop>", label?, help?, placeholder?, options?}` or
 * `{attribute: <attribute slug>}`. Anything else read is left out, so a
 * stored arrangement is always one the designer takes.
 */
final class FormGroups {

	/**
	 * Put before the id of a group of the product's own, so it never names
	 * one of the template's.
	 */
	public const OWN_GROUP_PREFIX = 'wc-';

	/**
	 * Most groups kept.
	 */
	public const MAX_GROUPS = 50;

	/**
	 * Most items kept in one group.
	 */
	public const MAX_ITEMS = 100;

	/**
	 * Most offered values renamed on one field.
	 */
	public const MAX_OPTIONS = 200;

	/**
	 * Longest id kept: a group's, a field's or an attribute's.
	 */
	public const ID_MAX_LENGTH = 200;

	/**
	 * Longest group title, field label or placeholder kept.
	 */
	public const TEXT_MAX_LENGTH = 120;

	/**
	 * Longest help text kept.
	 */
	public const HELP_MAX_LENGTH = 500;

	/**
	 * The words a field item may replace, besides its offered values'.
	 */
	private const FIELD_WORDS = array(
		'label'       => self::TEXT_MAX_LENGTH,
		'help'        => self::HELP_MAX_LENGTH,
		'placeholder' => self::TEXT_MAX_LENGTH,
	);

	/**
	 * @param list<array{id: string, title?: string, items: list<array<string, mixed>>}> $groups The groups, in order.
	 */
	private function __construct( public readonly array $groups ) {
	}

	/**
	 * An arrangement as stored or posted; null when it holds no group.
	 *
	 * @param mixed $data `{groups: [...]}`, see the class.
	 */
	public static function from_array( mixed $data ): ?self {
		$groups = array();
		$seen   = array();
		$placed = array();
		foreach ( is_array( $data ) && is_array( $data['groups'] ?? null ) ? $data['groups'] : array() as $group ) {
			if ( count( $groups ) === self::MAX_GROUPS ) {
				break;
			}
			$id = is_array( $group ) ? self::id( $group['id'] ?? null ) : '';
			if ( '' === $id || isset( $seen[ $id ] ) ) {
				continue;
			}
			$title = self::words( $group['title'] ?? null, self::TEXT_MAX_LENGTH );
			// The designer passes over an untitled group the template lacks.
			if ( self::is_own( $id ) && '' === $title ) {
				continue;
			}
			$seen[ $id ] = true;
			$items       = array();
			foreach ( is_array( $group['items'] ?? null ) ? $group['items'] : array() as $item ) {
				if ( count( $items ) === self::MAX_ITEMS ) {
					break;
				}
				$parsed = is_array( $item ) ? self::item( $item ) : null;
				$key    = null === $parsed ? null : ( $parsed['field'] ?? '' ) . '|' . ( $parsed['attribute'] ?? '' );
				if ( null !== $parsed && ! isset( $placed[ $key ] ) ) {
					$placed[ $key ] = true;
					$items[]        = $parsed;
				}
			}
			$groups[] = array( 'id' => $id ) + ( '' === $title ? array() : array( 'title' => $title ) ) + array( 'items' => $items );
		}
		return array() === $groups ? null : new self( $groups );
	}

	/**
	 * An arrangement posted as JSON text; null when it holds no group or is
	 * not JSON.
	 *
	 * @param string $json What the product screen posted.
	 */
	public static function from_json( string $json ): ?self {
		return '' === $json ? null : self::from_array( json_decode( $json, true ) );
	}

	/**
	 * Whether a group id is one of the product's own.
	 *
	 * @param string $id A group id.
	 */
	public static function is_own( string $id ): bool {
		return str_starts_with( $id, self::OWN_GROUP_PREFIX );
	}

	/**
	 * The attributes placed in a group, by their slug.
	 *
	 * @return list<string>
	 */
	public function attributes(): array {
		$attributes = array();
		foreach ( $this->groups as $group ) {
			foreach ( $group['items'] as $item ) {
				if ( isset( $item['attribute'] ) && is_string( $item['attribute'] ) ) {
					$attributes[] = $item['attribute'];
				}
			}
		}
		return $attributes;
	}

	/**
	 * A copy naming an attribute by another slug.
	 *
	 * @param string $from The attribute's slug.
	 * @param string $to   Its new slug.
	 */
	public function with_attribute_renamed( string $from, string $to ): self {
		$groups = array();
		foreach ( $this->groups as $group ) {
			$group['items'] = array_map( static fn ( array $item ): array => ( $item['attribute'] ?? null ) === $from ? array_merge( $item, array( 'attribute' => $to ) ) : $item, $group['items'] );
			$groups[]       = $group;
		}
		return new self( $groups );
	}

	/**
	 * The stored shape. Offered values' words are an object, so values that
	 * are numbers stay keys rather than turning the map into a JSON list.
	 *
	 * @return array{groups: list<array{id: string, title?: string, items: list<array<string, mixed>>}>}
	 */
	public function to_array(): array {
		return array(
			'groups' => array_map(
				static fn ( array $group ): array => array_merge( $group, array( 'items' => array_map( static fn ( array $item ): array => isset( $item['options'] ) ? array_merge( $item, array( 'options' => (object) $item['options'] ) ) : $item, $group['items'] ) ) ),
				$this->groups
			),
		);
	}

	/**
	 * A stored or posted item, or null when it is neither kind.
	 *
	 * @param array<mixed> $item One item.
	 * @return array<string, mixed>|null
	 */
	private static function item( array $item ): ?array {
		$attribute = self::id( $item['attribute'] ?? null );
		if ( '' !== $attribute ) {
			return array( 'attribute' => sanitize_title( $attribute ) );
		}
		$field = self::id( $item['field'] ?? null );
		if ( '' === $field ) {
			return null;
		}
		$parsed = array( 'field' => $field );
		foreach ( self::FIELD_WORDS as $key => $max ) {
			$words = self::words( $item[ $key ] ?? null, $max );
			if ( '' !== $words ) {
				$parsed[ $key ] = $words;
			}
		}
		$options = array();
		foreach ( is_array( $item['options'] ?? null ) ? $item['options'] : array() as $value => $words ) {
			$words = self::words( $words, self::TEXT_MAX_LENGTH );
			if ( '' !== $words && count( $options ) < self::MAX_OPTIONS ) {
				$options[ (string) $value ] = $words;
			}
		}
		if ( array() !== $options ) {
			$parsed['options'] = $options;
		}
		return $parsed;
	}

	/**
	 * An id as text without markup, or empty. Not sanitised as words: an
	 * attribute slug in another script is percent-encoded.
	 *
	 * @param mixed $value The id.
	 */
	private static function id( mixed $value ): string {
		return is_string( $value ) && strlen( $value ) <= self::ID_MAX_LENGTH ? trim( wp_strip_all_tags( $value ) ) : '';
	}

	/**
	 * Words as text, cut to `$max` characters; empty for anything else.
	 *
	 * @param mixed $value The words.
	 * @param int   $max   Most characters kept.
	 */
	private static function words( mixed $value, int $max ): string {
		return is_string( $value ) ? mb_substr( trim( sanitize_text_field( $value ) ), 0, $max ) : '';
	}
}
