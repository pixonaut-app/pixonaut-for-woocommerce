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
 * drops the sets of layouts it no longer offers. A layout whose set Pixonaut
 * refuses, or could not be asked for, is left without one, and logged: its
 * pages open on the layout's own values until the values are set again. A
 * layout that moved to another version keeps its set, as the side's own
 * set is kept when its template moves.
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
	 * match the layouts its sides offer; nothing is asked of Pixonaut while
	 * they match.
	 *
	 * @param WC_Product $product A product or variation, its setup saved.
	 */
	public function refresh( WC_Product $product ): void {
		$document = SideValues::document_on( $product );
		if ( null === $document || ! SideValues::writable( $document ) ) {
			return;
		}
		$setup  = ProductConfig::document_on( $product );
		$stored = SideValues::decoded( $document );
		$sides  = $stored;
		foreach ( ProductConfig::stored_on( $product )->sides as $side ) {
			$value = $stored[ $side->key ] ?? null;
			if ( null !== $value && $value->applies_to( $side ) ) {
				$sets = $this->sets( $product, $side, $value );
				if ( $sets !== $value->layout_sets ) {
					$sides[ $side->key ] = $value->with_layout_sets( $sets );
				}
			}
		}
		if ( $sides === $stored ) {
			return;
		}
		$product_id = $product->get_id();
		// The sets were made for the setup read above: one saved again meanwhile makes its own.
		SideValues::replace_on( $product, $sides, $document, static fn (): bool => get_post_meta( $product_id, ProductConfig::META_KEY, true ) === $setup );
	}

	/**
	 * A side's set for each layout it offers: the one it has, else one made.
	 *
	 * @param WC_Product $product The product or variation, for the log.
	 * @param Side       $side    The side.
	 * @param SideValue  $value   Its values.
	 * @return array<string, string> By the layout's base id.
	 */
	private function sets( WC_Product $product, Side $side, SideValue $value ): array {
		$sets = array();
		foreach ( $side->page_templates as $layout ) {
			$set = $value->layout_set( $layout->base_id );
			if ( null === $set ) {
				$set = $this->made( $product, $side, $layout, $value );
			}
			if ( null !== $set ) {
				$sets[ $layout->base_id ] = $set;
			}
		}
		return $sets;
	}

	/**
	 * The set made of the values for a layout's layers; null when it has
	 * none of them, or Pixonaut refused or could not be asked.
	 *
	 * @param WC_Product   $product The product or variation, for the log.
	 * @param Side         $side    The side.
	 * @param PageTemplate $layout  The layout.
	 * @param SideValue    $value   The side's values.
	 */
	private function made( WC_Product $product, Side $side, PageTemplate $layout, SideValue $value ): ?string {
		$id = (string) $layout->id();
		try {
			$given = SideValue::for_layers( $value->values, $this->value_sets->layer_ids( $id ) );
			return array() === $given ? null : $this->value_sets->make( $id, $given );
		} catch ( ValueSetRefused | ApiException $e ) {
			$this->logger->warning(
				sprintf( 'A page layout of side "%s" was left without the side\'s values: %s', $side->key, $e->getMessage() ),
				array(
					'product_id'  => $product->get_id(),
					'side'        => $side->key,
					'template_id' => $id,
				)
			);
			return null;
		}
	}
}
