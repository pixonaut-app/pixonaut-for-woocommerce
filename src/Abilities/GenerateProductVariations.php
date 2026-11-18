<?php
/**
 * The ability that generates a product's variations from its blank options.
 *
 * @package Pixonaut\WooCommerce
 */

declare(strict_types=1);

namespace Pixonaut\WooCommerce\Abilities;

use Pixonaut\WooCommerce\Catalogue\VariationChanges;
use Pixonaut\WooCommerce\Catalogue\VariationPlan;
use Pixonaut\WooCommerce\Vendor\Pixonaut\Sdk\Exception\ApiException;
use WC_Product;
use WP_Error;
use WP_Http;

/**
 * Generates a variable product's variations from its plan: one per
 * combination of the values its attributes offer, each on its blank, sold
 * as the variant the values name and priced by the rule. A dry run, the
 * default, only lists what would be added, changed and disabled; run it
 * again with `dry_run` false to do it. Whoever may edit the product may
 * generate its variations.
 */
final class GenerateProductVariations implements Ability {

	/**
	 * @param VariationGenerator $generator Works out and carries out the changes.
	 */
	public function __construct( private readonly VariationGenerator $generator ) {
	}

	public function name(): string {
		return 'generate-product-variations';
	}

	public function definition(): array {
		return array(
			/* translators: Name of an ability an AI assistant or an MCP client can use on the store. */
			'label'               => __( 'Generate a product\'s variations from its blank options', 'pixonaut-for-woocommerce' ),
			'description'         => __( 'Makes a variable product\'s variations from its plan: one for every combination of the values its variation attributes offer, each on its blank, sold as the blank variant the values name, priced by the price rule. A global attribute linked to a blank option (`set-attribute-blank-option`) names the option\'s values, and is "Any" on a blank without that option; an attribute an entry picks its blank by holds that value, and is "Any" on the other blanks; any other attribute, such as with or without a photo, multiplies every blank\'s variations. Existing variations are matched by their values and kept, with their ids, orders and own prices; one no longer offered, or whose variant is retired, is disabled, never deleted; one an earlier run disabled is published again once offered; one selling no variant gets the plan\'s. Values naming no variant are skipped and listed. `entries` replaces the plan (saved only when not a dry run); left out, the product\'s plan is used. With `dry_run` true, the default, nothing changes: the answer lists what would. At most 100 variations are made per run; run again for the rest (`remaining`). Read the plan and the attributes with `get-product-variation-plan`; add or remove offered values in the product\'s attributes.', 'pixonaut-for-woocommerce' ),
			'input_schema'        => array(
				'type'                 => 'object',
				'properties'           => array(
					'product_id' => array(
						'type'    => 'integer',
						'minimum' => 1,
					),
					'entries'    => array(
						'type'        => 'array',
						'description' => __( 'The blanks the variations are printed on, one entry each; replaces the product\'s plan.', 'pixonaut-for-woocommerce' ),
						'minItems'    => 1,
						'maxItems'    => VariationPlan::MAX_ENTRIES,
						'items'       => VariationPlanRows::entry_schema(),
					),
					'dry_run'    => array(
						'type'        => 'boolean',
						'default'     => true,
						'description' => __( 'True, the default, to list what would change without changing anything; false to generate.', 'pixonaut-for-woocommerce' ),
					),
				),
				'required'             => array( 'product_id' ),
				'additionalProperties' => false,
			),
			'output_schema'       => array(
				'type'       => 'object',
				'properties' => array(
					'product_id'  => array( 'type' => 'integer' ),
					'dry_run'     => array( 'type' => 'boolean' ),
					'entries'     => array(
						'type'  => 'array',
						'items' => VariationPlanRows::entry_schema(),
					),
					'remaining'   => array(
						'type'        => 'integer',
						'description' => __( 'Variations left to make by another run.', 'pixonaut-for-woocommerce' ),
					),
					'made'        => array(
						'type'        => 'array',
						'description' => __( 'The variations made, by id; empty on a dry run.', 'pixonaut-for-woocommerce' ),
						'items'       => array( 'type' => 'integer' ),
					),
					'priced'      => array(
						'type'        => 'integer',
						'description' => __( 'How many prices the rule set.', 'pixonaut-for-woocommerce' ),
					),
					'prices_read' => array(
						'type'        => 'boolean',
						'description' => __( 'False when Pixonaut could not be asked for the costs: price them later with `price-product`.', 'pixonaut-for-woocommerce' ),
					),
					'unpriced'    => array(
						'type'        => 'array',
						'description' => __( 'Variations made or changed that have no price, so customers cannot buy them yet: no price rule is set, or no cost is known.', 'pixonaut-for-woocommerce' ),
						'items'       => array( 'type' => 'integer' ),
					),
				) + VariationPlanRows::changes_schema(),
			),
			'execute_callback'    => array( $this, 'execute' ),
			'permission_callback' => array( ProductLinks::class, 'can_edit' ),
			'annotations'         => array(
				'readonly'    => false,
				// Variations no longer offered are disabled.
				'destructive' => true,
				'idempotent'  => true,
			),
		);
	}

	/**
	 * @param mixed $input `product_id`, `entries` and `dry_run`.
	 * @return array<string, mixed>|WP_Error
	 */
	public function execute( mixed $input = null ): array|WP_Error {
		$product = VariationPlanRows::product( $input );
		if ( ! $product instanceof WC_Product ) {
			return $product;
		}
		$given   = is_array( $input ) && array_key_exists( 'entries', $input );
		$plan    = $given ? VariationPlan::from_input( $input['entries'] ) : VariationPlan::stored_on( $product );
		$dry_run = ! is_array( $input ) || false !== ( $input['dry_run'] ?? true );
		if ( null === $plan ) {
			return new WP_Error( 'pixonaut_wc_ability_no_variation_plan', __( 'The product has no variation plan: give its `entries`.', 'pixonaut-for-woocommerce' ), array( 'status' => WP_Http::BAD_REQUEST ) );
		}
		return $this->run( $product, $plan, $dry_run, $given );
	}

	/**
	 * Works out the changes, and unless on a dry run saves the plan and
	 * carries them out.
	 *
	 * @param WC_Product    $product The variable product.
	 * @param VariationPlan $plan    The plan.
	 * @param bool          $dry_run Whether only to list the changes.
	 * @param bool          $save    Whether to store the plan on the product.
	 * @return array<string, mixed>|WP_Error
	 */
	public function run( WC_Product $product, VariationPlan $plan, bool $dry_run, bool $save ): array|WP_Error {
		try {
			$changes = $this->generator->changes( $product, $plan );
			if ( $changes instanceof WP_Error ) {
				return $changes;
			}
			$done = array(
				'made'        => array(),
				'priced'      => 0,
				'prices_read' => true,
				'unpriced'    => array(),
			);
			if ( ! $dry_run ) {
				if ( $save ) {
					$plan->save_to( $product );
				}
				$applied = $this->generator->apply( $product, $changes );
				if ( $applied instanceof WP_Error ) {
					return $applied;
				}
				$done = array_intersect_key( $applied, $done );
			}
		} catch ( ApiException $e ) {
			return new WP_Error( 'pixonaut_wc_ability_variants_unread', __( 'Pixonaut could not be reached to read the blank\'s variants.', 'pixonaut-for-woocommerce' ), array( 'status' => WP_Http::BAD_GATEWAY ) );
		}
		return array(
			'product_id' => $product->get_id(),
			'dry_run'    => $dry_run,
			'entries'    => $plan->to_output(),
			'remaining'  => $dry_run ? 0 : max( 0, count( $changes->add ) - VariationGenerator::MAX_MADE ),
		) + $done + VariationPlanRows::changes( self::listed( $changes, $dry_run ) );
	}

	/**
	 * The changes a run lists: on a dry run all of them, else those it did.
	 *
	 * @param VariationChanges $changes The changes worked out.
	 * @param bool             $dry_run Whether it is a dry run.
	 */
	private static function listed( VariationChanges $changes, bool $dry_run ): VariationChanges {
		return $dry_run ? $changes : new VariationChanges( array_slice( $changes->add, 0, VariationGenerator::MAX_MADE ), $changes->change, $changes->disable, $changes->skipped, $changes->kept );
	}
}
