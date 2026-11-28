<?php
/**
 * Keeps a side's own values reaching the page layouts it offers.
 *
 * @package Pixonaut\WooCommerce
 */

declare(strict_types=1);

namespace Pixonaut\WooCommerce\Catalogue;

use Pixonaut\WooCommerce\Api\ValueSetRefused;
use Pixonaut\WooCommerce\Api\ValueSets;
use Pixonaut\WooCommerce\Vendor\Pixonaut\Sdk\Exception\ApiException;
use Pixonaut\WooCommerce\Vendor\Psr\Log\LoggerInterface;
use WC_Product;

/**
 * Once a product's or variation's setup is saved, gives each side with
 * values of its own a set for every page layout it offers now, made of the
 * values for the layers the layout has (`SideValue::for_layers()`), and
 * drops the sets of layouts it no longer offers. A product's values also
 * reach the layouts a variation with a setup of its own offers on a side
 * sold with them (`SideValues::sold_with()`): their sets are kept with the
 * product's values, so they are made again whenever those are. A layout
 * whose set Pixonaut refuses, or could not be asked for, is left without
 * one, logged and kept as pending (`SideValue::$layouts_pending`): the side
 * is not sold until a later refresh, a re-check's, makes it. A layout that
 * moved to another version keeps its set, as the side's own set is kept
 * when its template moves.
 */
final class LayoutValueSets {

	/**
	 * @param ValueSets       $value_sets Makes the sets and reads the layouts' layers.
	 * @param LoggerInterface $logger     Logs a layout left without a set.
	 */
	public function __construct( private readonly ValueSets $value_sets, private readonly LoggerInterface $logger ) {
	}

	/**
	 * Remakes the layout sets of a product's or variation's own values to
	 * match the layouts its sides offer, and, for a variation with a setup
	 * of its own, those of its product's values; nothing is asked of
	 * Pixonaut while they match.
	 *
	 * @param WC_Product $product A product or variation, its setup saved.
	 * @return bool Whether every layout that takes values has its set: false while one is pending.
	 */
	public function refresh( WC_Product $product ): bool {
		$complete = $this->refresh_own( $product );
		if ( ! $product->is_type( 'variation' ) || ! ProductConfig::stored_on( $product )->override ) {
			return $complete;
		}
		$parent = wc_get_product( $product->get_parent_id() );
		// Its sides sold with its product's values open its layouts on the sets kept with those.
		return $parent instanceof WC_Product ? $this->refresh_own( $parent ) && $complete : $complete;
	}

	/**
	 * Refreshes a product or variation (`refresh()`) only while a layout
	 * its values, or a variation's product's, reach is pending: a re-check
	 * asks Pixonaut nothing for one whose sets are all made.
	 *
	 * @param WC_Product $product A product or variation.
	 * @return bool Whether no layout is left pending.
	 */
	public function retry( WC_Product $product ): bool {
		$owners = array( $product );
		if ( $product->is_type( 'variation' ) && ProductConfig::stored_on( $product )->override ) {
			$parent = wc_get_product( $product->get_parent_id() );
			if ( $parent instanceof WC_Product ) {
				$owners[] = $parent;
			}
		}
		foreach ( $owners as $owner ) {
			foreach ( SideValues::stored_on( $owner ) as $value ) {
				if ( array() !== $value->layouts_pending ) {
					return $this->refresh( $product );
				}
			}
		}
		return true;
	}

	/**
	 * Remakes the layout sets of the values a product or variation keeps itself.
	 *
	 * @param WC_Product $product A product or variation.
	 * @return bool Whether no layout is left pending.
	 */
	private function refresh_own( WC_Product $product ): bool {
		$document = SideValues::document_on( $product );
		if ( null === $document || ! SideValues::writable( $document ) ) {
			return true;
		}
		$setup    = ProductConfig::document_on( $product );
		$stored   = SideValues::decoded( $document );
		$sides    = $stored;
		$complete = true;
		foreach ( ProductConfig::stored_on( $product )->sides as $side ) {
			$value = $stored[ $side->key ] ?? null;
			if ( null !== $value && $value->applies_to( $side ) ) {
				list( $sets, $pending ) = $this->sets( $product, $side, $value, $this->layouts( $product, $side ) );
				$complete               = $complete && array() === $pending;
				if ( $sets !== $value->layout_sets || $pending !== $value->layouts_pending ) {
					$sides[ $side->key ] = $value->with_layout_sets( $sets, $pending );
				}
			}
		}
		if ( $sides === $stored ) {
			return $complete;
		}
		$product_id = $product->get_id();
		// The sets were made for the setup read above: one saved again meanwhile makes its own.
		SideValues::replace_on( $product, $sides, $document, static fn (): bool => get_post_meta( $product_id, ProductConfig::META_KEY, true ) === $setup );
		return $complete;
	}

	/**
	 * The layouts a side's own values reach: the side's, and on a product,
	 * those its variations with a setup of their own offer on the side of
	 * that key while it is sold with the product's values.
	 *
	 * @param WC_Product $product A product or variation.
	 * @param Side       $side    One of its own sides.
	 * @return array<string, PageTemplate> By base id.
	 */
	private function layouts( WC_Product $product, Side $side ): array {
		$layouts = array();
		foreach ( $side->page_templates as $layout ) {
			$layouts[ $layout->base_id ] = $layout;
		}
		if ( $product->is_type( 'variation' ) ) {
			return $layouts;
		}
		foreach ( $product->get_children() as $child_id ) {
			$variation = wc_get_product( $child_id );
			if ( ! $variation instanceof WC_Product ) {
				continue;
			}
			$config = ProductConfig::stored_on( $variation );
			if ( ! $config->override ) {
				continue;
			}
			foreach ( $config->sides as $own ) {
				if ( $own->key !== $side->key || array() === $own->page_templates || SideValues::FROM_PRODUCT !== SideValues::sold_with( $variation, $own )['from'] ) {
					continue;
				}
				foreach ( $own->page_templates as $layout ) {
					$layouts[ $layout->base_id ] ??= $layout;
				}
			}
		}
		return $layouts;
	}

	/**
	 * A side's set for each layout its values reach: the one it has, else
	 * one made; and the layouts whose set could not be made.
	 *
	 * @param WC_Product                  $product The product or variation, for the log.
	 * @param Side                        $side    The side.
	 * @param SideValue                   $value   Its values.
	 * @param array<string, PageTemplate> $layouts The layouts its values reach, by base id.
	 * @return array{0: array<string, string>, 1: list<string>} The sets by the layout's base id, and the pending layouts' base ids.
	 */
	private function sets( WC_Product $product, Side $side, SideValue $value, array $layouts ): array {
		$sets    = array();
		$pending = array();
		foreach ( $layouts as $layout ) {
			$set = $value->layout_set( $layout->base_id );
			if ( null === $set ) {
				try {
					$set = $this->made( $layout, $value );
				} catch ( ValueSetRefused | ApiException $e ) {
					$this->logger->warning(
						sprintf( 'A page layout of side "%s" was left without the side\'s values, so the side is not sold until it has them: %s', $side->key, $e->getMessage() ),
						array(
							'product_id'  => $product->get_id(),
							'side'        => $side->key,
							'template_id' => (string) $layout->id(),
						)
					);
					$pending[] = $layout->base_id;
				}
			}
			if ( null !== $set ) {
				$sets[ $layout->base_id ] = $set;
			}
		}
		return array( $sets, $pending );
	}

	/**
	 * The set made of the values for a layout's layers; null when it has
	 * none of them.
	 *
	 * @param PageTemplate $layout The layout.
	 * @param SideValue    $value  The side's values.
	 *
	 * @throws ValueSetRefused When Pixonaut refused the values.
	 * @throws ApiException    When Pixonaut refused otherwise or could not be reached.
	 */
	private function made( PageTemplate $layout, SideValue $value ): ?string {
		$id    = (string) $layout->id();
		$given = SideValue::for_layers( $value->values, $this->value_sets->layer_ids( $id ) );
		return array() === $given ? null : $this->value_sets->make( $id, $given );
	}
}
