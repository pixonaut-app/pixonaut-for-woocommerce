<?php
/**
 * Ability: whether a picture is drawn for every variation, and what turning
 * it on or off would cost the shop's storage.
 *
 * @package Pixonaut\WooCommerce
 */

declare(strict_types=1);

namespace Pixonaut\WooCommerce\Abilities;

use WP_Error;

/**
 * Reads "Generate a picture for every variation", store-wide or for a
 * variable product, with what turning it on (new pictures, their space, the
 * share of the shop's free storage, and the warning) and off (the space
 * freed) would do, as the settings page and the product's Pixonaut tab
 * show it before saving.
 */
final class GetVariationPictures implements Ability {

	public const NAME = 'get-variation-pictures';

	/**
	 * @param VariationPicturesState $state Reads it and its costs.
	 */
	public function __construct( private readonly VariationPicturesState $state ) {
	}

	public function name(): string {
		return self::NAME;
	}

	public function definition(): array {
		return array(
			/* translators: Name of an ability an AI assistant or an MCP client can use on the store. */
			'label'               => __( 'Read the picture for every variation setting', 'pixonaut-for-woocommerce' ),
			'description'         => sprintf(
				/* translators: %s: the name of the ability that changes the setting. */
				__( 'Whether a Pixonaut picture is drawn for every variation, on its blank\'s photo, store-wide or for a variable product (`choice`: `default` follows the store-wide setting). `turning_on` says how many new pictures turning it on draws, about how many bytes they take, the share of the shop\'s free storage in `percent` (null when it cannot be read) and the `warning` that needs confirming; `turning_off` says how many variation pictures turning it off deletes and the bytes it frees. `queue` counts the store\'s picture jobs, store-wide: drawings waiting or running (`queued`), checks of products whose drawings are still to be queued (`checking`), and drawings finished in the last hour (`finished_recently`), saved or to be tried again; a queue that shortens is being worked through, one that stays while none finish is stuck. The pictures are files in the shop\'s media library, on the shop\'s own server, not in Pixonaut. Change it with %s.', 'pixonaut-for-woocommerce' ),
				Abilities::tool( SetVariationPictures::NAME )
			),
			'input_schema'        => array(
				'type'                 => 'object',
				'properties'           => array(
					'product_id' => VariationPicturesState::product_input(),
				),
				'additionalProperties' => false,
			),
			'output_schema'       => VariationPicturesState::output_schema(),
			'execute_callback'    => array( $this, 'execute' ),
			'permission_callback' => array( SetVariationPictures::class, 'can_use' ),
			'annotations'         => array(
				'readonly'    => true,
				'destructive' => false,
				'idempotent'  => true,
			),
		);
	}

	/**
	 * @param mixed $input `product_id`, or nothing for store-wide.
	 * @return array<string, mixed>|WP_Error
	 */
	public function execute( mixed $input = null ): array|WP_Error {
		$product = VariationPicturesState::product( $input );
		return $product instanceof WP_Error ? $product : $this->state->read( $product );
	}
}
