<?php
/**
 * The templates the variations generated from a plan are sold on.
 *
 * @package Pixonaut\WooCommerce
 */

declare(strict_types=1);

namespace Pixonaut\WooCommerce\Abilities;

use Pixonaut\WooCommerce\Catalogue\ProductConfig;
use Pixonaut\WooCommerce\Catalogue\Side;
use Pixonaut\WooCommerce\Catalogue\VariationChanges;
use WC_Product;

/**
 * A product's variations can be sold on more than one template, picked by
 * an attribute (with a photo or without). A variation generated onto a
 * blank keeps the template its other values pick: an attribute picks a
 * template when the product's variations holding each of its values all
 * carry one template, and a new variation takes the template of the
 * variations holding its value. Attributes the plan picks blanks by never
 * pick a template. Where no attribute picks one, or two pick different
 * ones, a variation is sold on the product's own.
 *
 * @phpstan-type Held array{attributes: array<string, string>, config: ProductConfig, key: string}
 * @phpstan-type Counts array<string, array<string, array<string, int>>>
 * @phpstan-import-type Added from VariationChanges
 * @phpstan-import-type Changed from VariationChanges
 */
final class VariationTemplates {

	/**
	 * The `template_from` of a variation sold on the product's own templates.
	 */
	public const PRODUCT = 0;

	/**
	 * The changes with each variation to add or change given the templates
	 * it is sold on: `template`, their names, and `template_from`, the
	 * variation whose setup they come from, or `PRODUCT`.
	 *
	 * @param WC_Product       $product   A variable product.
	 * @param string[]         $selectors The attributes its plan picks blanks by.
	 * @param VariationChanges $changes   What generating its variations does.
	 * @phpstan-param list<string> $selectors
	 */
	public static function given( WC_Product $product, array $selectors, VariationChanges $changes ): VariationChanges {
		$own    = ProductConfig::stored_on( $product );
		$held   = self::held( $product );
		$counts = self::counts( $held );
		$give   = static function ( array $one ) use ( $own, $held, $counts, $selectors ): array {
			$from = self::from( $one, $own, $held, $counts, $selectors );
			return $one + array(
				'template'      => self::names_of( self::PRODUCT === $from ? $own : $held[ $from ]['config'] ),
				'template_from' => $from,
			);
		};
		/** @phpstan-var list<Added> $add */
		$add = array_map( $give, $changes->add );
		/** @phpstan-var list<Changed> $change */
		$change = array_map( $give, $changes->change );
		return new VariationChanges( $add, $change, $changes->disable, $changes->skipped, $changes->kept );
	}

	/**
	 * The templates a configuration's sides are on, in order, each by id
	 * and the version it is pinned to, if any: what tells one template
	 * choice from another.
	 *
	 * @param ProductConfig $config A configuration.
	 */
	public static function key_of( ProductConfig $config ): string {
		return implode(
			'|',
			array_map(
				static fn ( Side $side ): string => '' === $side->template_base_id || ! $side->pinned ? $side->template_base_id : $side->template_base_id . '@' . $side->template_version,
				$config->sides
			)
		);
	}

	/**
	 * The variation whose templates a variation to add or change is sold
	 * on: one already on its blank keeps its own.
	 *
	 * @param array<string, mixed> $one       A variation to add or change.
	 * @param ProductConfig        $own       The product's setup.
	 * @param array<int, array>    $held      The product's variations on a template.
	 * @param array                $counts    How many of them hold each value with each template, as `counts()` gives them.
	 * @param string[]             $selectors The attributes the plan picks blanks by.
	 * @phpstan-param array<int, Held> $held
	 * @phpstan-param Counts $counts
	 * @phpstan-param list<string> $selectors
	 */
	private static function from( array $one, ProductConfig $own, array $held, array $counts, array $selectors ): int {
		$self = is_int( $one['variation_id'] ?? null ) ? $one['variation_id'] : self::PRODUCT;
		if ( isset( $held[ $self ] ) && $held[ $self ]['config']->blank_id === $one['blank_id'] ) {
			return $self;
		}
		// A variation moving to another blank has no say in its own template.
		if ( isset( $held[ $self ] ) ) {
			foreach ( $held[ $self ]['attributes'] as $name => $value ) {
				if ( '' !== $value ) {
					--$counts[ $name ][ $value ][ $held[ $self ]['key'] ];
				}
			}
			unset( $held[ $self ] );
		}
		$picked = array();
		foreach ( is_array( $one['attributes'] ?? null ) ? $one['attributes'] : array() as $name => $value ) {
			if ( '' !== $value && ! in_array( $name, $selectors, true ) ) {
				$key = self::picked_by( $counts[ (string) $name ] ?? array(), (string) $value );
				if ( null !== $key ) {
					$picked[ $key ] = true;
				}
			}
		}
		$key = 1 === count( $picked ) ? (string) array_key_first( $picked ) : null;
		if ( null === $key || self::key_of( $own ) === $key ) {
			return self::PRODUCT;
		}
		$found = self::PRODUCT;
		foreach ( $held as $id => $variation ) {
			if ( $variation['key'] !== $key ) {
				continue;
			}
			// One on the same blank already has the placement and the sizes there.
			if ( $variation['config']->blank_id === $one['blank_id'] ) {
				return $id;
			}
			$found = self::PRODUCT === $found ? $id : $found;
		}
		return $found;
	}

	/**
	 * The template the variations holding an attribute's value carry, when
	 * the attribute picks the template: the variations holding each of its
	 * values all carry one.
	 *
	 * @param array<string, array<string, int>> $by_value How many variations hold each of the attribute's values with each template.
	 * @param string                            $value    The value.
	 */
	private static function picked_by( array $by_value, string $value ): ?string {
		$picked = null;
		foreach ( $by_value as $holds => $keys ) {
			$carried = array_keys( array_filter( $keys ) );
			if ( count( $carried ) > 1 ) {
				return null;
			}
			if ( (string) $holds === $value && 1 === count( $carried ) ) {
				$picked = (string) $carried[0];
			}
		}
		return $picked;
	}

	/**
	 * How many of the product's variations hold each value of each
	 * attribute with each template, worked out once for every variation
	 * to add or change.
	 *
	 * @param array<int, array> $held The product's variations on a template.
	 * @phpstan-param array<int, Held> $held
	 * @return array
	 * @phpstan-return Counts
	 */
	private static function counts( array $held ): array {
		$counts = array();
		foreach ( $held as $variation ) {
			foreach ( $variation['attributes'] as $name => $value ) {
				if ( '' !== $value ) {
					$counts[ $name ][ $value ][ $variation['key'] ] = ( $counts[ $name ][ $value ][ $variation['key'] ] ?? 0 ) + 1;
				}
			}
		}
		return $counts;
	}

	/**
	 * The product's variations sold on a template, with their values and
	 * setups, by id.
	 *
	 * @param WC_Product $product A variable product.
	 * @return array<int, array>
	 * @phpstan-return array<int, Held>
	 */
	private static function held( WC_Product $product ): array {
		$held = array();
		foreach ( $product->get_children() as $child_id ) {
			$child = wc_get_product( $child_id );
			if ( ! $child instanceof WC_Product ) {
				continue;
			}
			$config = ProductConfig::config_for( $child );
			$key    = self::key_of( $config );
			if ( '' !== $key ) {
				$held[ (int) $child_id ] = array(
					'attributes' => array_map( 'strval', $child->get_attributes( 'edit' ) ),
					'config'     => $config,
					'key'        => $key,
				);
			}
		}
		return $held;
	}

	/**
	 * The names of a configuration's templates, as the plan's preview lists them.
	 *
	 * @param ProductConfig $config A configuration.
	 */
	private static function names_of( ProductConfig $config ): string {
		return implode(
			', ',
			array_map( static fn ( Side $side ): string => $side->template_name ?? $side->template_base_id, $config->sides )
		);
	}
}
