<?php
/**
 * The ability that sets up many variations of one product alike.
 *
 * @package Pixonaut\WooCommerce
 */

declare(strict_types=1);

namespace Pixonaut\WooCommerce\Abilities;

use Pixonaut\WooCommerce\Api\Catalogue;
use WC_Product;
use WP_Error;

/**
 * Sets the same setup on many variations of one variable product in one
 * call, each as `set-product-setup` sets one variation: a field left out
 * keeps what that variation has, so each keeps its own blank variant, and
 * its own blank while `blank_id` is left out. Pixonaut is asked each of the
 * questions the variations' checks share once.
 */
final class SetVariationsSetup implements Ability {

	/**
	 * The most variations one call sets up: a product's variations as
	 * WooCommerce generates them at most in one go.
	 */
	public const MAX_VARIATIONS = 100;

	/**
	 * Why a call was refused: an id given is not a variation of the product.
	 */
	public const NOT_ITS_VARIATION = 'pixonaut_wc_ability_not_its_variation';

	/**
	 * The fields of `set-product-setup` this ability does not take: the
	 * product it names, and what each variation keeps or follows its
	 * product for.
	 */
	private const LEFT_OUT = array( 'product_id', 'variant', ...SetupFields::PRODUCT_KEYS );

	/**
	 * @param SetProductSetup $setup     Sets up each variation.
	 * @param Catalogue       $catalogue Asks Pixonaut, once per question for the whole call.
	 */
	public function __construct( private readonly SetProductSetup $setup, private readonly Catalogue $catalogue ) {
	}

	public function name(): string {
		return 'set-variations-setup';
	}

	public function definition(): array {
		$one = $this->setup->definition();
		return array(
			/* translators: Name of an ability an AI assistant or an MCP client can use on the store. */
			'label'               => __( 'Set up many variations for Pixonaut', 'pixonaut-for-woocommerce' ),
			'description'         => __( 'Sets the same setup on many variations of one variable product, each as `set-product-setup` sets one variation: a field left out keeps what that variation has, so each keeps its own blank variant, and its own blank unless `blank_id` is given. Every id must be a variation of the product, or nothing is saved. The answer lists each variation\'s setup, or why it was refused, in the order given.', 'pixonaut-for-woocommerce' ),
			'input_schema'        => array(
				'type'                 => 'object',
				'properties'           => array(
					'product_id'    => array(
						'type'        => 'integer',
						'description' => __( 'The variable product.', 'pixonaut-for-woocommerce' ),
						'minimum'     => 1,
					),
					'variation_ids' => array(
						'type'        => 'array',
						'description' => __( 'Its variations to set up, each once.', 'pixonaut-for-woocommerce' ),
						'items'       => array(
							'type'    => 'integer',
							'minimum' => 1,
						),
						'minItems'    => 1,
						'maxItems'    => self::MAX_VARIATIONS,
						'uniqueItems' => true,
					),
				) + array_diff_key( $one['input_schema']['properties'], array_flip( self::LEFT_OUT ) ),
				'required'             => array( 'product_id', 'variation_ids' ),
				'additionalProperties' => false,
			),
			'output_schema'       => self::output_schema(),
			'execute_callback'    => array( $this, 'execute' ),
			'permission_callback' => array( ProductLinks::class, 'can_edit' ),
			'annotations'         => $one['annotations'],
		);
	}

	/**
	 * @param mixed $input As the input schema.
	 * @return array<string, mixed>|WP_Error
	 */
	public function execute( mixed $input = null ): array|WP_Error {
		$product = ProductLinks::product( $input );
		if ( ! $product instanceof WC_Product ) {
			return $product;
		}
		$input      = (array) $input;
		$variations = array();
		$strangers  = array();
		foreach ( is_array( $input['variation_ids'] ?? null ) ? $input['variation_ids'] : array() as $id ) {
			$variation = is_int( $id ) ? wc_get_product( $id ) : null;
			if ( $variation instanceof WC_Product && $variation->is_type( 'variation' ) && $variation->get_parent_id() === $product->get_id() ) {
				$variations[] = $variation;
				continue;
			}
			$strangers[] = is_scalar( $id ) ? (string) $id : '?';
		}
		if ( array() !== $strangers ) {
			return new WP_Error(
				self::NOT_ITS_VARIATION,
				/* translators: %s: the ids given, comma-separated. */
				sprintf( __( 'These are not variations of the product: %s. Nothing was saved.', 'pixonaut-for-woocommerce' ), implode( ', ', $strangers ) ),
				array( 'variation_ids' => $strangers )
			);
		}
		$fields = array_diff_key( $input, array_flip( array( 'product_id', 'variation_ids' ) ) );
		return array(
			'product_id' => $product->get_id(),
			'variations' => $this->catalogue->remembering( fn (): array => array_map( fn ( WC_Product $variation ): array => $this->set( $variation, $fields ), $variations ) ),
		);
	}

	/**
	 * One variation's part of the answer.
	 *
	 * @param WC_Product           $variation The variation.
	 * @param array<string, mixed> $fields    The setup fields given.
	 * @return array<string, mixed>
	 */
	private function set( WC_Product $variation, array $fields ): array {
		$saved = $this->setup->set_variation( $variation, $fields );
		if ( $saved instanceof WP_Error ) {
			return array(
				'variation_id' => $variation->get_id(),
				'error'        => array(
					'code'    => (string) $saved->get_error_code(),
					'message' => $saved->get_error_message(),
				),
			);
		}
		return array( 'variation_id' => $variation->get_id() ) + $saved;
	}

	/**
	 * What the ability answers: the product, and each variation's setup as
	 * `set-product-setup` answers it, or why it was refused.
	 *
	 * @return array<string, mixed>
	 */
	private static function output_schema(): array {
		$one = ProductLinks::output_schema();
		return array(
			'type'       => 'object',
			'properties' => array(
				'product_id' => array( 'type' => 'integer' ),
				'variations' => array(
					'type'  => 'array',
					'items' => array(
						'type'       => 'object',
						'properties' => array(
							'variation_id' => array( 'type' => 'integer' ),
							'error'        => array(
								'type'        => 'object',
								'description' => __( 'Why this variation was refused, which leaves it as it was; absent once it is set up.', 'pixonaut-for-woocommerce' ),
								'properties'  => array(
									'code'    => array( 'type' => 'string' ),
									'message' => array( 'type' => 'string' ),
								),
							),
						) + $one['properties'],
					),
				),
			),
		);
	}
}
