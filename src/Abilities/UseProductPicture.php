<?php
/**
 * The ability that puts a product's Pixonaut picture back as its image.
 *
 * @package Pixonaut\WooCommerce
 */

declare(strict_types=1);

namespace Pixonaut\WooCommerce\Abilities;

use Pixonaut\WooCommerce\Catalogue\PictureImage;
use Pixonaut\WooCommerce\Catalogue\ProductPictures;
use Pixonaut\WooCommerce\Settings\Settings;
use WC_Product;
use WP_Error;

/**
 * Puts the Pixonaut picture back in place of the image the store manager
 * set on a product or variation, as "Use the Pixonaut picture" does: the
 * drawn picture, or no image until it is drawn. Whoever may edit the
 * product may do it.
 */
final class UseProductPicture implements Ability {

	/**
	 * @param ProductLinks $links    Gives the answer.
	 * @param Settings     $settings Says whether pictures are product images.
	 */
	public function __construct( private readonly ProductLinks $links, private readonly Settings $settings ) {
	}

	public function name(): string {
		return 'use-product-picture';
	}

	public function definition(): array {
		return array(
			/* translators: Name of an ability an AI assistant or an MCP client can use on the store. */
			'label'               => __( 'Use the Pixonaut picture as a product\'s image', 'pixonaut-for-woocommerce' ),
			'description'         => __( 'Puts the Pixonaut picture back as the image of a personalised product or variation whose image the store manager set (`picture` `own_image`): the drawn picture, or no image until it is drawn, which then takes its place. Needs the store to use Pixonaut pictures as product images.', 'pixonaut-for-woocommerce' ),
			'input_schema'        => array(
				'type'                 => 'object',
				'properties'           => array(
					'product_id' => array(
						'type'        => 'integer',
						'description' => __( 'A simple or variable product, or a variation.', 'pixonaut-for-woocommerce' ),
						'minimum'     => 1,
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
		$product = ProductLinks::product_or_variation( $input );
		if ( ! $product instanceof WC_Product ) {
			return $product;
		}
		if ( ! ProductPictures::applies( $this->settings, $product ) ) {
			return new WP_Error( 'pixonaut_wc_ability_pictures_off', __( 'The product has no Pixonaut picture: the store does not use the pictures as product images, or the product is not personalised.', 'pixonaut-for-woocommerce' ) );
		}
		// Only an image set in place of the picture, which is remembered, is replaced: any other is the picture's to take once drawn.
		if ( ! PictureImage::is_own( $product ) ) {
			return new WP_Error( 'pixonaut_wc_ability_no_own_image', __( 'The product shows its Pixonaut picture, or will once it is drawn: no image of the store manager\'s is in its place.', 'pixonaut-for-woocommerce' ) );
		}
		PictureImage::use_picture( $product, ProductPictures::stored( $product )['attachment'] );
		return $this->links->summary( $product );
	}
}
