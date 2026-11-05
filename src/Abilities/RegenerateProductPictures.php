<?php
/**
 * The ability that draws products' Pixonaut pictures again.
 *
 * @package Pixonaut\WooCommerce
 */

declare(strict_types=1);

namespace Pixonaut\WooCommerce\Abilities;

use Pixonaut\WooCommerce\Admin\RegeneratePictures;
use WC_Product;
use WP_Error;

/**
 * Draws the pictures of one product or several again, as Regenerate
 * product image does on the product and on the products list: the
 * product's image, its gallery pictures and its variations' images, from
 * the template version and settings they use now. Whoever may edit every
 * product named may do it.
 */
final class RegenerateProductPictures implements Ability {

	/**
	 * The most products one call draws again.
	 */
	public const MAX_PRODUCTS = 50;

	/**
	 * @param RegeneratePictures $pictures Draws them, as the admin's action does.
	 */
	public function __construct( private readonly RegeneratePictures $pictures ) {
	}

	public function name(): string {
		return 'regenerate-product-pictures';
	}

	public function definition(): array {
		return array(
			/* translators: Name of an ability an AI assistant or an MCP client can use on the store. */
			'label'               => __( 'Regenerate product images', 'pixonaut-for-woocommerce' ),
			'description'         => sprintf(
				/* translators: %s: the name of the ability that lists products. */
				__( 'Draws the Pixonaut pictures of personalised simple or variable products again, as Regenerate in background does: each product\'s image, gallery pictures and variations\' images, from the template version and settings they use now. Needs the store to use Pixonaut pictures as product images. Answers with each product\'s `state`: `drawing` (drawn in the background; %s gives each product\'s `picture` once it is), `drawn`, `kept` (the store manager\'s own image stays), `failed` (with `failed` naming whose) or `error` (nothing was queued).', 'pixonaut-for-woocommerce' ),
				Abilities::tool( ListProducts::NAME )
			),
			'input_schema'        => array(
				'type'                 => 'object',
				'properties'           => array(
					'product_ids' => array(
						'type'        => 'array',
						'items'       => array(
							'type'    => 'integer',
							'minimum' => 1,
						),
						'minItems'    => 1,
						'maxItems'    => self::MAX_PRODUCTS,
						'uniqueItems' => true,
						'description' => __( 'Simple or variable products.', 'pixonaut-for-woocommerce' ),
					),
				),
				'required'             => array( 'product_ids' ),
				'additionalProperties' => false,
			),
			'output_schema'       => array(
				'type'       => 'object',
				'properties' => array(
					'products' => array(
						'type'  => 'array',
						'items' => array(
							'type'       => 'object',
							'properties' => array(
								'product_id' => array( 'type' => 'integer' ),
								'state'      => array(
									'type' => 'string',
									'enum' => array( RegeneratePictures::DRAWING, RegeneratePictures::DRAWN, RegeneratePictures::KEPT, RegeneratePictures::FAILED, RegeneratePictures::NOT_QUEUED ),
								),
								'failed'     => array( 'type' => 'string' ),
							),
						),
					),
				),
			),
			'execute_callback'    => array( $this, 'execute' ),
			'permission_callback' => array( $this, 'can_edit' ),
			'annotations'         => array(
				'readonly'    => false,
				'destructive' => true,
				'idempotent'  => true,
			),
		);
	}

	/**
	 * Whether the user may edit every product named.
	 *
	 * @param mixed $input `product_ids`.
	 */
	public function can_edit( mixed $input = null ): bool {
		$ids = self::ids( $input );
		foreach ( $ids as $id ) {
			if ( ! current_user_can( 'edit_post', $id ) ) {
				return false;
			}
		}
		return array() !== $ids;
	}

	/**
	 * @param mixed $input `product_ids`.
	 * @return array{products: list<array<string, mixed>>}|WP_Error
	 */
	public function execute( mixed $input = null ): array|WP_Error {
		$products = array();
		foreach ( self::ids( $input ) as $id ) {
			$product = $this->pictures->pictured_product( $id );
			if ( ! $product instanceof WC_Product ) {
				/* translators: %d: a product's id. */
				return new WP_Error( 'pixonaut_wc_ability_not_pictured', sprintf( __( 'Product %d shows no Pixonaut picture: it is not a personalised simple or variable product, or the store does not use Pixonaut pictures as product images. Nothing was drawn again.', 'pixonaut-for-woocommerce' ), $id ) );
			}
			$products[] = $product;
		}
		$answers = array();
		foreach ( $this->pictures->redraw( $products ) as $index => $standing ) {
			$answers[] = array( 'product_id' => $products[ $index ]->get_id() ) + $standing;
		}
		return array( 'products' => $answers );
	}

	/**
	 * @param mixed $input `product_ids`.
	 * @return list<int>
	 */
	private static function ids( mixed $input ): array {
		$ids = is_array( $input ) && is_array( $input['product_ids'] ?? null ) ? $input['product_ids'] : array();
		return array_values( array_unique( array_map( 'intval', array_filter( $ids, static fn ( mixed $id ): bool => is_int( $id ) || ( is_string( $id ) && ctype_digit( $id ) ) ) ) ) );
	}
}
