<?php
/**
 * Makes, changes and disables a product's variations as its plan says.
 *
 * @package Pixonaut\WooCommerce
 */

declare(strict_types=1);

namespace Pixonaut\WooCommerce\Abilities;

use Closure;
use Pixonaut\WooCommerce\Catalogue\GeneratedVariation;
use Pixonaut\WooCommerce\Catalogue\ProductConfig;
use Pixonaut\WooCommerce\Catalogue\ProductVariants;
use Pixonaut\WooCommerce\Catalogue\VariationChanges;
use Pixonaut\WooCommerce\Catalogue\VariationCombinations;
use Pixonaut\WooCommerce\Catalogue\VariationPlan;
use Pixonaut\WooCommerce\Pricing\Amount;
use Pixonaut\WooCommerce\Pricing\ProductPricing;
use Pixonaut\WooCommerce\Vendor\Pixonaut\Sdk\Exception\ApiException;
use WC_Product;
use WC_Product_Variable;
use WC_Product_Variation;
use WP_Error;

/**
 * Carries out what `VariationCombinations` works out for a product and its
 * plan: makes each variation missing, on its blank (following the
 * product's setup when the blank is the product's, else the setup of a
 * variation already on that blank, else the product's with the blank
 * changed and each side at its whole area there, checked with Pixonaut
 * once per blank) and sold as its variant;
 * publishes again those the plan had disabled; gives the plan's variant to
 * those selling none; disables those no longer offered. Then the new and
 * changed variations' costs are read and the price rule sets their prices.
 *
 * A variation without a price cannot be bought, so one the rule cannot
 * price waits, unsold, for a price.
 *
 * @phpstan-import-type Added from VariationChanges
 */
final class VariationGenerator {

	/**
	 * The most variations one run makes; generating again makes the rest.
	 */
	public const MAX_MADE = 100;

	/**
	 * The status of a variation customers cannot choose.
	 */
	private const DISABLED_STATUS = 'private';

	/**
	 * @param ProductLinks    $links    Saves a variation's setup and resolves its variant.
	 * @param SetupFields     $fields   Turns a setup with another blank into the variation panel's fields.
	 * @param ProductVariants $variants Reads a blank's variants and stores a variation's.
	 * @param Closure         $pricing  Gives what reads the costs and prices by the rule, once it is needed.
	 * @phpstan-param Closure(): ProductPricing $pricing
	 */
	public function __construct(
		private readonly ProductLinks $links,
		private readonly SetupFields $fields,
		private readonly ProductVariants $variants,
		private readonly Closure $pricing,
	) {
	}

	/**
	 * What generating the product's variations from the plan does.
	 *
	 * @param WC_Product    $product A variable product.
	 * @param VariationPlan $plan    Its plan.
	 * @throws ApiException When a blank's variants could not be read.
	 */
	public function changes( WC_Product $product, VariationPlan $plan ): VariationChanges|WP_Error {
		return ( new VariationCombinations( fn ( string $blank_id ): array => $this->variants->named( $blank_id ) ) )->of( $product, $plan );
	}

	/**
	 * Carries the changes out, at most `MAX_MADE` new variations at a time.
	 *
	 * The setup of each blank is worked out, and checked with Pixonaut where
	 * needed, before any variation is written, so a setup Pixonaut cannot
	 * check refuses the run with nothing changed. A new variation, or one
	 * published again, stays disabled until it is on its blank and sells its
	 * variant: a run cut short leaves it disabled for the plan, so the next
	 * run finishes it. A variation the store manager changed while the
	 * changes were worked out is left as it now is.
	 *
	 * @param WC_Product       $product A variable product.
	 * @param VariationChanges $changes What to do, as `changes()` worked it out.
	 * @return array{made: list<int>, changed: list<int>, disabled: list<int>, priced: int, prices_read: bool, unpriced: list<int>}|WP_Error
	 * @throws ApiException When a blank's variants could not be read; what is done is kept.
	 */
	public function apply( WC_Product $product, VariationChanges $changes ): array|WP_Error {
		$add    = array_slice( $changes->add, 0, self::MAX_MADE );
		$setups = array();
		foreach ( array_unique( array_column( array_merge( $add, $changes->change ), 'blank_id' ) ) as $blank_id ) {
			$setup = $this->setup_of( $product, (string) $blank_id );
			if ( $setup instanceof WP_Error ) {
				return $setup;
			}
			$setups[ $blank_id ] = $setup;
		}
		$made     = array();
		$changed  = array();
		$disabled = array();
		$order    = count( $product->get_children() );
		try {
			GeneratedVariation::$writing = true;
			foreach ( $add as $one ) {
				$variation = new WC_Product_Variation();
				$variation->set_parent_id( $product->get_id() );
				$variation->set_attributes( $one['attributes'] );
				$variation->set_status( self::DISABLED_STATUS );
				$variation->set_menu_order( $order++ );
				GeneratedVariation::mark( $variation, GeneratedVariation::DISABLED );
				$variation->save();
				$this->sell( $variation, $one, $setups );
				$made[] = $variation->get_id();
			}
			foreach ( $changes->change as $one ) {
				$variation = wc_get_product( $one['variation_id'] );
				$enable    = VariationChanges::ENABLE === $one['why'];
				// Changed by the store manager meanwhile: published or disabled by hand.
				if ( ! $variation instanceof WC_Product || ( $enable && ! GeneratedVariation::disabled( $variation ) ) ) {
					continue;
				}
				$this->sell( $variation, $one, $setups, $enable );
				$changed[] = $variation->get_id();
			}
			foreach ( $changes->disable as $one ) {
				$variation = wc_get_product( $one['variation_id'] );
				if ( $variation instanceof WC_Product && ProductConfig::PUBLIC_STATUS === $variation->get_status( 'edit' ) ) {
					$variation->set_status( self::DISABLED_STATUS );
					GeneratedVariation::mark( $variation, GeneratedVariation::DISABLED );
					$variation->save();
					$disabled[] = $variation->get_id();
				}
			}
		} finally {
			GeneratedVariation::$writing = false;
			WC_Product_Variable::sync( $product->get_id() );
			$this->variants->note( $product );
		}
		return array(
			'made'     => $made,
			'changed'  => $changed,
			'disabled' => $disabled,
		) + $this->price( $product->get_id(), array_merge( $made, $changed ) );
	}

	/**
	 * Puts a variation on its entry's blank, unless it already is, sells it
	 * as the plan's variant, and publishes it.
	 *
	 * @param WC_Product                        $variation The variation.
	 * @param array                             $one       What the plan sells it as.
	 * @param array<string, ProductConfig|null> $setups    The setup each blank's variations get; null to follow the product.
	 * @param bool                              $publish   Whether to publish it; a new one always is.
	 * @phpstan-param Added $one
	 * @throws ApiException When the blank's variants could not be read.
	 */
	private function sell( WC_Product $variation, array $one, array $setups, bool $publish = true ): void {
		$blank_id = $one['blank_id'];
		if ( ProductConfig::config_for( $variation )->blank_id !== $blank_id ) {
			$setup = $setups[ $blank_id ] ?? null;
			if ( null === $setup ) {
				$this->links->configure_variation( $variation, null );
			} else {
				$this->links->save_variation( $variation, $setup );
			}
		}
		$saved = wc_get_product( $variation->get_id() );
		$saved = $saved instanceof WC_Product ? $saved : $variation;
		$this->variants->pick( $saved, $one['ref'] );
		if ( $publish ) {
			$saved = wc_get_product( $variation->get_id() );
			if ( $saved instanceof WC_Product ) {
				$saved->set_status( ProductConfig::PUBLIC_STATUS );
				GeneratedVariation::mark( $saved, GeneratedVariation::MADE );
				$saved->save();
			}
		}
	}

	/**
	 * The setup a variation on a blank gets: null to follow the product's,
	 * on its blank; a variation's own setup already on the blank; else the
	 * product's with the blank changed, each side at its placement's whole
	 * area, checked with Pixonaut.
	 *
	 * @param WC_Product $product  The variable product.
	 * @param string     $blank_id The blank.
	 */
	private function setup_of( WC_Product $product, string $blank_id ): ProductConfig|WP_Error|null {
		$own = ProductConfig::stored_on( $product );
		if ( $own->blank_id === $blank_id ) {
			return null;
		}
		foreach ( $product->get_children() as $child_id ) {
			$child  = wc_get_product( $child_id );
			$config = $child instanceof WC_Product && ProductConfig::is_stored_on( $child ) ? ProductConfig::stored_on( $child ) : null;
			if ( null !== $config && $config->override && $config->personalise && $config->blank_id === $blank_id && null === $config->invalid_reason ) {
				return $config;
			}
		}
		// Checked on a variation not saved yet, which starts from the product's setup.
		$unsaved = new WC_Product_Variation();
		$unsaved->set_parent_id( $product->get_id() );
		// A size picked for the product's blank names an area of that blank: on another, each side is sold at its placement's whole area, as each of its variants has it.
		$sides  = array_map(
			static fn ( array $side ): array => array(
				'print_sizes' => array(),
				'start_size'  => 0,
			) + $side,
			SetupFields::sides_of( $own )
		);
		$posted = $this->fields->posted(
			array(
				'blank_id'     => $blank_id,
				'sides'        => $sides,
				'personalised' => $own->personalise,
			),
			$own
		);
		return $posted instanceof WP_Error ? $posted : $this->links->check_variation( $unsaved, $posted, $own );
	}

	/**
	 * Reads the costs of the variations made or changed and prices them by
	 * the rule.
	 *
	 * @param int   $product_id The variable product.
	 * @param int[] $ids        The variations to price.
	 * @phpstan-param list<int> $ids
	 * @return array{priced: int, prices_read: bool, unpriced: list<int>}
	 */
	private function price( int $product_id, array $ids ): array {
		$ids    = array_values( array_unique( array_merge( $ids, self::unpriced_made( $product_id ) ) ) );
		$priced = 0;
		$read   = true;
		if ( array() !== $ids ) {
			$pricing = ( $this->pricing )();
			try {
				$product = wc_get_product( $product_id );
				if ( $product instanceof WC_Product ) {
					$pricing->read_costs( $product );
					$fresh  = wc_get_product( $product_id );
					$priced = $fresh instanceof WC_Product ? $pricing->apply( $fresh, $ids ) : 0;
				}
			} catch ( ApiException $e ) {
				$read = false;
			}
		}
		$unpriced = array();
		foreach ( $ids as $id ) {
			$variation = wc_get_product( $id );
			if ( $variation instanceof WC_Product && null === Amount::parse( $variation->get_regular_price( 'edit' ) ) ) {
				$unpriced[] = $id;
			}
		}
		return array(
			'priced'      => $priced,
			'prices_read' => $read,
			'unpriced'    => $unpriced,
		);
	}

	/**
	 * The variations an earlier run made that still have no price: a run cut
	 * short, or one Pixonaut gave no costs to.
	 *
	 * @param int $product_id The variable product.
	 * @return list<int>
	 */
	private static function unpriced_made( int $product_id ): array {
		$product = wc_get_product( $product_id );
		$ids     = array();
		foreach ( $product instanceof WC_Product ? $product->get_children() : array() as $child_id ) {
			$child = wc_get_product( $child_id );
			if ( $child instanceof WC_Product && GeneratedVariation::MADE === $child->get_meta( GeneratedVariation::META_KEY, true, 'edit' ) && null === Amount::parse( $child->get_regular_price( 'edit' ) ) ) {
				$ids[] = (int) $child_id;
			}
		}
		return $ids;
	}
}
