<?php
/**
 * The ability that undoes a standing side's layout adjustment for one option.
 *
 * @package Pixonaut\WooCommerce
 */

declare(strict_types=1);

namespace Pixonaut\WooCommerce\Abilities;

use Pixonaut\WooCommerce\Admin\LayoutAdjustments;
use Pixonaut\WooCommerce\Catalogue\ProductConfig;
use WC_Product;
use WP_Error;

/**
 * Lays one option of a standing side out as the side again, as Reset
 * beside the option on the product screen does: the option is printed
 * from the design the side's template gives it rather than the one laid
 * out by hand. Whoever may edit the product may reset it.
 */
final class ResetStandingLayout implements Ability {

	/**
	 * @param ProductLinks      $links       Gives the answer.
	 * @param LayoutAdjustments $adjustments Resets the option.
	 */
	public function __construct( private readonly ProductLinks $links, private readonly LayoutAdjustments $adjustments ) {
	}

	public function name(): string {
		return 'reset-standing-layout';
	}

	public function definition(): array {
		return array(
			/* translators: Name of an ability an AI assistant or an MCP client can use on the store. */
			'label'               => __( 'Reset a standing side\'s layout for an option', 'pixonaut-for-woocommerce' ),
			'description'         => __( 'Undoes the layout adjusted by hand for one option of a standing side, such as one size, so the option is printed from the design the side\'s template gives it again. The side and option are as `list-products` gives them in `standing_options`.', 'pixonaut-for-woocommerce' ),
			'input_schema'        => array(
				'type'                 => 'object',
				'properties'           => array(
					'product_id' => array(
						'type'        => 'integer',
						'description' => __( 'A simple or variable product, or a variation with a setup of its own.', 'pixonaut-for-woocommerce' ),
						'minimum'     => 1,
					),
					'side_key'   => array( 'type' => 'string' ),
					'design_id'  => array(
						'type'        => 'string',
						'description' => __( 'The option\'s design, as `standing_options` gives it.', 'pixonaut-for-woocommerce' ),
					),
				),
				'required'             => array( 'product_id', 'side_key', 'design_id' ),
				'additionalProperties' => false,
			),
			'output_schema'       => ProductLinks::output_schema(),
			'execute_callback'    => array( $this, 'execute' ),
			'permission_callback' => array( ProductLinks::class, 'can_edit' ),
			'annotations'         => array(
				'readonly'    => false,
				'destructive' => true,
				'idempotent'  => true,
			),
		);
	}

	/**
	 * @param mixed $input `product_id`, `side_key` and `design_id`.
	 * @return array<string, mixed>|WP_Error
	 */
	public function execute( mixed $input = null ): array|WP_Error {
		$product = ProductLinks::product_or_variation( $input );
		if ( ! $product instanceof WC_Product ) {
			return $product;
		}
		$input = (array) $input;
		$found = LayoutAdjustments::find(
			$product,
			is_string( $input['side_key'] ?? null ) ? $input['side_key'] : '',
			is_string( $input['design_id'] ?? null ) ? $input['design_id'] : ''
		);
		if ( null === $found || ( $product->is_type( 'variation' ) && ! ProductConfig::stored_on( $product )->override ) ) {
			return new WP_Error( 'pixonaut_wc_ability_unknown_option', __( 'No standing side of the product has this key and option.', 'pixonaut-for-woocommerce' ) );
		}
		if ( LayoutAdjustments::FAILED === $this->adjustments->reset_option( $product, $found[0], $found[1] ) ) {
			return new WP_Error( 'pixonaut_wc_ability_reset_failed', __( 'Pixonaut could not reset the option\'s layout. Try again in a moment.', 'pixonaut-for-woocommerce' ) );
		}
		return $this->links->summary( $product );
	}
}
