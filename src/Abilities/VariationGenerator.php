<?php
/**
 * Makes, changes and disables a product's variations as its plan says.
 *
 * @package Pixonaut\WooCommerce
 */

declare(strict_types=1);

namespace Pixonaut\WooCommerce\Abilities;

use Closure;
use Pixonaut\WooCommerce\Admin\VariationPanel;
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
 * changed, checked with Pixonaut once per blank) and sold as its variant;
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
	 * @param WC_Product       $product A variable product.
	 * @param VariationChanges $changes What to do, as `changes()` worked it out.
	 * @return array{made: list<int>, changed: list<int>, disabled: list<int>, priced: int, prices_read: bool, unpriced: list<int>}|WP_Error
	 * @throws ApiException When a blank's variants could not be read; what is done is kept.
	 */
	public function apply( WC_Product $product, VariationChanges $changes ): array|WP_Error {
		$setups   = array();
		$made     = array();
		$changed  = array();
		$disabled = array();
		$order    = count( $product->get_children() );
		foreach ( array_slice( $changes->add, 0, self::MAX_MADE ) as $one ) {
			$variation = new WC_Product_Variation();
			$variation->set_parent_id( $product->get_id() );
			$variation->set_attributes( $one['attributes'] );
			$variation->set_status( ProductConfig::PUBLIC_STATUS );
			$variation->set_menu_order( $order++ );
			GeneratedVariation::mark( $variation, GeneratedVariation::MADE );
			$variation->save();
			$set = $this->sell( $product, $variation, $one, $setups );
			if ( $set instanceof WP_Error ) {
				return $set;
			}
			$made[] = $variation->get_id();
		}
		foreach ( $changes->change as $one ) {
			$variation = wc_get_product( $one['variation_id'] );
			if ( ! $variation instanceof WC_Product ) {
				continue;
			}
			if ( VariationChanges::ENABLE === $one['why'] ) {
				$variation->set_status( ProductConfig::PUBLIC_STATUS );
				GeneratedVariation::mark( $variation, GeneratedVariation::MADE );
				$variation->save();
			} else {
				$set = $this->sell( $product, $variation, $one, $setups );
				if ( $set instanceof WP_Error ) {
					return $set;
				}
			}
			$changed[] = $variation->get_id();
		}
		foreach ( $changes->disable as $one ) {
			$variation = wc_get_product( $one['variation_id'] );
			if ( $variation instanceof WC_Product ) {
				$variation->set_status( self::DISABLED_STATUS );
				GeneratedVariation::mark( $variation, GeneratedVariation::DISABLED );
				$variation->save();
				$disabled[] = $variation->get_id();
			}
		}
		WC_Product_Variable::sync( $product->get_id() );
		$this->variants->note( $product );
		return array(
			'made'     => $made,
			'changed'  => $changed,
			'disabled' => $disabled,
		) + $this->price( $product->get_id(), array_merge( $made, $changed ) );
	}

	/**
	 * Puts a variation on its entry's blank, unless it already is, and
	 * sells it as the plan's variant.
	 *
	 * @param WC_Product                        $product   The variable product.
	 * @param WC_Product                        $variation The variation.
	 * @param array                             $one       What the plan sells it as.
	 * @param array<string, ProductConfig|null> $setups    The setup each blank's variations get, as worked out so far; null to follow the product.
	 * @phpstan-param Added $one
	 * @throws ApiException When the blank's variants could not be read.
	 */
	private function sell( WC_Product $product, WC_Product $variation, array $one, array &$setups ): ?WP_Error {
		$blank_id = $one['blank_id'];
		if ( ProductConfig::config_for( $variation )->blank_id !== $blank_id ) {
			if ( ! array_key_exists( $blank_id, $setups ) ) {
				$setups[ $blank_id ] = $this->setup_of( $product, $blank_id );
			}
			$setup = $setups[ $blank_id ];
			if ( null === $setup ) {
				$this->links->configure_variation( $variation, null );
			} else {
				$checked = $setup->blank_id === $blank_id && $setup->override ? $setup : null;
				if ( null === $checked ) {
					$posted = $this->fields->posted( array( 'blank_id' => $blank_id ), VariationPanel::own_or_inherited( $variation ) );
					if ( $posted instanceof WP_Error ) {
						return $posted;
					}
					$checked             = $this->links->check_variation( $variation, $posted );
					$setups[ $blank_id ] = $checked;
				}
				$this->links->save_variation( $variation, $checked );
			}
			$saved     = wc_get_product( $variation->get_id() );
			$variation = $saved instanceof WC_Product ? $saved : $variation;
		}
		$this->variants->pick( $variation, $one['ref'] );
		return null;
	}

	/**
	 * The setup a variation on a blank gets: null to follow the product's,
	 * on its blank; a variation's own setup already on the blank; else the
	 * product's, to be checked with the blank changed.
	 *
	 * @param WC_Product $product  The variable product.
	 * @param string     $blank_id The blank.
	 */
	private function setup_of( WC_Product $product, string $blank_id ): ?ProductConfig {
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
		return $own;
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
}
