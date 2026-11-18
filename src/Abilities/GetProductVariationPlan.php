<?php
/**
 * The ability that reads what a product's variations are generated from.
 *
 * @package Pixonaut\WooCommerce
 */

declare(strict_types=1);

namespace Pixonaut\WooCommerce\Abilities;

use Pixonaut\WooCommerce\Catalogue\VariationPlan;
use WC_Product;
use WP_Error;

/**
 * Gives a variable product's variation plan, its variation attributes with
 * the values they offer and the blank option each stands for, and the
 * blanks it is set up on: what `generate-product-variations` takes.
 */
final class GetProductVariationPlan implements Ability {

	public function name(): string {
		return 'get-product-variation-plan';
	}

	public function definition(): array {
		return array(
			/* translators: Name of an ability an AI assistant or an MCP client can use on the store. */
			'label'               => __( 'Read what a product\'s variations are generated from', 'pixonaut-for-woocommerce' ),
			'description'         => __( 'Gives a variable product\'s variation plan: the blanks its variations are printed on, the attribute values that pick each, and fixed option values. Also gives the product\'s variation attributes with the values they offer, the blank option each stands for (`option`, linked once per attribute with `set-attribute-blank-option`) and what each value stands for, and the blanks the product and its variations are set up on now. Change the plan and generate with `generate-product-variations`.', 'pixonaut-for-woocommerce' ),
			'input_schema'        => array(
				'type'                 => 'object',
				'properties'           => array(
					'product_id' => array(
						'type'    => 'integer',
						'minimum' => 1,
					),
				),
				'required'             => array( 'product_id' ),
				'additionalProperties' => false,
			),
			'output_schema'       => array(
				'type'       => 'object',
				'properties' => array(
					'product_id' => array( 'type' => 'integer' ),
					'entries'    => array(
						'type'        => 'array',
						'description' => __( 'The plan\'s blanks; empty when the product has no plan.', 'pixonaut-for-woocommerce' ),
						'items'       => VariationPlanRows::entry_schema(),
					),
					'attributes' => array(
						'type'  => 'array',
						'items' => array(
							'type'       => 'object',
							'properties' => array(
								'attribute'   => array( 'type' => 'string' ),
								'name'        => array( 'type' => 'string' ),
								'option'      => array(
									'type'        => array( 'string', 'null' ),
									'description' => __( 'The blank option the attribute stands for; null when it is not linked: then it is a choice of its own, such as with or without a photo, or picks a blank.', 'pixonaut-for-woocommerce' ),
								),
								'picks_blank' => array( 'type' => 'boolean' ),
								'values'      => array(
									'type'  => 'array',
									'items' => array(
										'type'       => 'object',
										'properties' => array(
											'value'      => array( 'type' => 'string' ),
											'label'      => array( 'type' => 'string' ),
											'stands_for' => array( 'type' => array( 'string', 'null' ) ),
										),
									),
								),
							),
						),
					),
					'blanks'     => array(
						'type'  => 'array',
						'items' => array(
							'type'       => 'object',
							'properties' => array(
								'blank_id' => array( 'type' => 'string' ),
								'name'     => array( 'type' => 'string' ),
							),
						),
					),
				),
			),
			'execute_callback'    => array( $this, 'execute' ),
			'permission_callback' => array( ProductLinks::class, 'can_edit' ),
			'annotations'         => array(
				'readonly'    => true,
				'destructive' => false,
				'idempotent'  => true,
			),
		);
	}

	/**
	 * @param mixed $input `product_id`.
	 * @return array<string, mixed>|WP_Error
	 */
	public function execute( mixed $input = null ): array|WP_Error {
		$product = VariationPlanRows::product( $input );
		if ( ! $product instanceof WC_Product ) {
			return $product;
		}
		$plan = VariationPlan::stored_on( $product );
		return array(
			'product_id' => $product->get_id(),
			'entries'    => null === $plan ? array() : $plan->to_output(),
			'attributes' => VariationPlanRows::attributes( $product, $plan ),
			'blanks'     => VariationPlanRows::blanks( $product ),
		);
	}
}
