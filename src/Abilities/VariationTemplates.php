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
 * @phpstan-type Held array{attributes: array<string, string>, config: ProductConfig}
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
		$own  = ProductConfig::stored_on( $product );
		$held = self::held( $product );
		$give = static function ( array $one ) use ( $own, $held, $selectors ): array {
			$from = self::from( $one, $own, $held, $selectors );
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
	 * The templates a configuration's sides are on, by id, in order: what
	 * tells one template choice from another.
	 *
	 * @param ProductConfig $config A configuration.
	 */
	public static function key_of( ProductConfig $config ): string {
		return implode( '|', array_map( static fn ( Side $side ): string => $side->template_base_id, $config->sides ) );
	}

	/**
	 * The variation whose templates a variation to add or change is sold
	 * on: one already on its blank keeps its own.
	 *
	 * @param array<string, mixed> $one       A variation to add or change.
	 * @param ProductConfig        $own       The product's setup.
	 * @param array<int, array>    $held      The product's variations on a template.
	 * @param string[]             $selectors The attributes the plan picks blanks by.
	 * @phpstan-param array<int, Held> $held
	 * @phpstan-param list<string> $selectors
	 */
	private static function from( array $one, ProductConfig $own, array $held, array $selectors ): int {
		$self = is_int( $one['variation_id'] ?? null ) ? $one['variation_id'] : self::PRODUCT;
		if ( isset( $held[ $self ] ) && $held[ $self ]['config']->blank_id === $one['blank_id'] ) {
			return $self;
		}
		unset( $held[ $self ] );
		$picked = array();
		foreach ( is_array( $one['attributes'] ?? null ) ? $one['attributes'] : array() as $name => $value ) {
			if ( '' !== $value && ! in_array( $name, $selectors, true ) ) {
				$key = self::picked_by( (string) $name, (string) $value, $held );
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
			if ( self::key_of( $variation['config'] ) !== $key ) {
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
	 * @param string            $name  The attribute.
	 * @param string            $value The value.
	 * @param array<int, array> $held  The product's variations on a template.
	 * @phpstan-param array<int, Held> $held
	 */
	private static function picked_by( string $name, string $value, array $held ): ?string {
		$by_value = array();
		foreach ( $held as $variation ) {
			$holds = $variation['attributes'][ $name ] ?? '';
			if ( '' !== $holds ) {
				$by_value[ $holds ][ self::key_of( $variation['config'] ) ] = true;
			}
		}
		foreach ( $by_value as $keys ) {
			if ( count( $keys ) > 1 ) {
				return null;
			}
		}
		return isset( $by_value[ $value ] ) ? (string) array_key_first( $by_value[ $value ] ) : null;
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
			if ( '' !== self::key_of( $config ) ) {
				$held[ (int) $child_id ] = array(
					'attributes' => array_map( 'strval', $child->get_attributes( 'edit' ) ),
					'config'     => $config,
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
