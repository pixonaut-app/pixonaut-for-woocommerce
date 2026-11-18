<?php
/**
 * The blanks a variable product's variations are generated from.
 *
 * @package Pixonaut\WooCommerce
 */

declare(strict_types=1);

namespace Pixonaut\WooCommerce\Catalogue;

use Pixonaut\WooCommerce\Schema;
use WC_Product;

/**
 * A variable product whose variations are generated has one entry per blank
 * they are printed on, stored as versioned JSON in its
 * `_pixonaut_variation_plan` meta. An entry names its blank, the attribute
 * values that pick it when the product is sold on several (Inramning: Ram
 * for a framed blank), and the value of each option of the blank no
 * attribute offers (Orientation: Vertical) when the blank sells more than
 * one. Which values are offered is the product's own attributes: the plan
 * never lists them, so it cannot disagree with the Attributes tab.
 *
 * @phpstan-type Entry array{blank_id: string, when: array<string, string>, fixed: array<string, string>}
 */
final class VariationPlan {

	public const META_KEY = Schema::PRODUCT_VARIATION_PLAN_META;

	public const SCHEMA_VERSION = 1;

	/**
	 * The most blanks one product's variations are generated from.
	 */
	public const MAX_ENTRIES = 20;

	/**
	 * Longest attribute, value or option name kept.
	 */
	public const MAX_LENGTH = 200;

	/**
	 * @param array[] $entries Each blank the variations are printed on.
	 * @phpstan-param list<Entry> $entries
	 */
	public function __construct( public readonly array $entries ) {
	}

	/**
	 * A plan as the abilities and the product screen send it; entries
	 * without a blank are left out, names trimmed.
	 *
	 * @param mixed $entries A list of `{blank_id, when, fixed}`.
	 */
	public static function from_input( mixed $entries ): self {
		$plan = array();
		foreach ( is_array( $entries ) ? $entries : array() as $entry ) {
			$blank_id = is_array( $entry ) && is_string( $entry['blank_id'] ?? null ) ? self::clean( $entry['blank_id'] ) : '';
			if ( '' === $blank_id || count( $plan ) >= self::MAX_ENTRIES ) {
				continue;
			}
			$plan[] = array(
				'blank_id' => $blank_id,
				'when'     => self::pairs( $entry['when'] ?? null ),
				'fixed'    => self::pairs( $entry['fixed'] ?? null ),
			);
		}
		return new self( $plan );
	}

	/**
	 * The plan stored on a product; null when it has none, or one from a
	 * newer version, which is never overwritten by reading.
	 *
	 * @param WC_Product $product A variable product.
	 */
	public static function stored_on( WC_Product $product ): ?self {
		$stored = $product->get_meta( self::META_KEY, true, 'edit' );
		$data   = is_string( $stored ) && '' !== $stored ? json_decode( $stored, true ) : null;
		if ( ! is_array( $data ) || ! is_int( $data['v'] ?? null ) || $data['v'] > self::SCHEMA_VERSION ) {
			return null;
		}
		return self::from_input( $data['entries'] ?? null );
	}

	/**
	 * Stores the plan on the product, or removes it when it has no entry.
	 *
	 * @param WC_Product $product A variable product.
	 */
	public function save_to( WC_Product $product ): void {
		if ( array() === $this->entries ) {
			$product->delete_meta_data( self::META_KEY );
		} else {
			$product->update_meta_data(
				self::META_KEY,
				(string) wp_json_encode(
					array(
						'v'       => self::SCHEMA_VERSION,
						'entries' => $this->entries,
					)
				)
			);
		}
		$product->save_meta_data();
	}

	/**
	 * The attributes some entry picks its blank by.
	 *
	 * @return list<string>
	 */
	public function selectors(): array {
		$selectors = array();
		foreach ( $this->entries as $entry ) {
			$selectors = array_merge( $selectors, array_keys( $entry['when'] ) );
		}
		return array_values( array_unique( $selectors ) );
	}

	/**
	 * The entries for the abilities' answers, with empty maps as objects.
	 *
	 * @return list<array<string, mixed>>
	 */
	public function to_output(): array {
		return array_map(
			static fn ( array $entry ): array => array(
				'blank_id' => $entry['blank_id'],
				'when'     => (object) $entry['when'],
				'fixed'    => (object) $entry['fixed'],
			),
			$this->entries
		);
	}

	/**
	 * A map of names to values as given; pairs with an empty name or value
	 * are left out.
	 *
	 * @param mixed $pairs A map of strings.
	 * @return array<string, string>
	 */
	private static function pairs( mixed $pairs ): array {
		$kept = array();
		foreach ( is_array( $pairs ) ? $pairs : array() as $name => $value ) {
			$name  = self::clean( (string) $name );
			$value = is_string( $value ) ? self::clean( $value ) : '';
			if ( '' !== $name && '' !== $value ) {
				$kept[ $name ] = $value;
			}
		}
		return $kept;
	}

	/**
	 * @param string $name A blank id, attribute, value or option name.
	 */
	private static function clean( string $name ): string {
		return mb_substr( trim( sanitize_text_field( $name ) ), 0, self::MAX_LENGTH );
	}
}
