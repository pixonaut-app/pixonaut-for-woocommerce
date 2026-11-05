<?php
/**
 * The ability that stops a product being personalised.
 *
 * @package Pixonaut\WooCommerce
 */

declare(strict_types=1);

namespace Pixonaut\WooCommerce\Abilities;

use WC_Product;
use WP_Error;

/**
 * Turns personalisation off for a product, as unticking it on the product
 * screen does: the link is kept, so it can be turned on again. Whoever may
 * edit the product may unlink it.
 */
final class UnlinkProduct implements Ability {

	/**
	 * @param ProductLinks $links Saves the change.
	 */
	public function __construct( private readonly ProductLinks $links ) {
	}

	public function name(): string {
		return 'unlink-product';
	}

	public function definition(): array {
		return array(
			/* translators: Name of an ability an AI assistant or an MCP client can use on the store. */
			'label'               => __( 'Unlink a product from Pixonaut', 'pixonaut-for-woocommerce' ),
			'description'         => __( 'Stops customers personalising a product; it is sold as a plain product again. Its template and blank are kept, so linking it again restores them.', 'pixonaut-for-woocommerce' ),
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
	 * @param mixed $input `product_id`.
	 * @return array<string, mixed>|WP_Error
	 */
	public function execute( mixed $input = null ): array|WP_Error {
		$product = ProductLinks::product( $input );
		return $product instanceof WC_Product ? $this->links->unlink( $product ) : $product;
	}
}
