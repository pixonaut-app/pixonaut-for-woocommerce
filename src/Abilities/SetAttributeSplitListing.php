<?php
/**
 * The ability that sets how a global attribute lists its values in the shop by default.
 *
 * @package Pixonaut\WooCommerce
 */

declare(strict_types=1);

namespace Pixonaut\WooCommerce\Abilities;

use Pixonaut\WooCommerce\Catalogue\SplitListing;
use WP_Error;
use WP_Http;

/**
 * Sets a global attribute's "Cards in the shop" setting, as its edit
 * screen does: a card per value always, a card per value only while a
 * filter of the shop chooses one, or none. The products on Auto for the
 * attribute follow it. Whoever may manage the store's attributes may set it.
 */
final class SetAttributeSplitListing implements Ability {

	/**
	 * What WooCommerce's attribute screens ask of whoever changes an attribute.
	 */
	private const CAPABILITY = 'manage_product_terms';

	public function name(): string {
		return 'set-attribute-split-listing';
	}

	public function definition(): array {
		return array(
			/* translators: Name of an ability an AI assistant or an MCP client can use on the store. */
			'label'               => __( 'Set how an attribute lists its values in the shop', 'pixonaut-for-woocommerce' ),
			'description'         => __( 'Sets a global attribute\'s "Cards in the shop" setting, as its edit screen does: `on` lists a variable product using it for its variations once per value; `filtered` does so only while a filter of the shop chooses a value of it, as for a framing that should not make cards of its own but should show the framed card while the customer filters by it; `off` makes no card per value. Each product on `auto` for the attribute follows it (`get-product-split-listing`, `set-product-split-listing`). The answer is the attribute\'s setting now.', 'pixonaut-for-woocommerce' ),
			'input_schema'        => array(
				'type'                 => 'object',
				'properties'           => array(
					'attribute_id' => array(
						'type'        => 'integer',
						'minimum'     => 1,
						'description' => __( 'A global attribute, by id.', 'pixonaut-for-woocommerce' ),
					),
					'mode'         => array(
						'type' => 'string',
						'enum' => SplitListing::modes(),
					),
				),
				'required'             => array( 'attribute_id', 'mode' ),
				'additionalProperties' => false,
			),
			'output_schema'       => array(
				'type'       => 'object',
				'properties' => array(
					'attribute_id' => array( 'type' => 'integer' ),
					'name'         => array( 'type' => 'string' ),
					'mode'         => array(
						'type' => 'string',
						'enum' => SplitListing::modes(),
					),
				),
			),
			'execute_callback'    => array( $this, 'execute' ),
			'permission_callback' => array( self::class, 'can_manage' ),
			'annotations'         => array(
				'readonly'    => false,
				// The shop lists every product following the attribute again.
				'destructive' => true,
				'idempotent'  => true,
			),
		);
	}

	/**
	 * Whether the current user may change the store's attributes.
	 */
	public static function can_manage(): bool {
		return current_user_can( self::CAPABILITY );
	}

	/**
	 * @param mixed $input `attribute_id` and `mode`.
	 * @return array{attribute_id: int, name: string, mode: string}|WP_Error
	 */
	public function execute( mixed $input = null ): array|WP_Error {
		$attribute_id = is_array( $input ) && is_int( $input['attribute_id'] ?? null ) ? $input['attribute_id'] : 0;
		$mode         = is_array( $input ) && is_string( $input['mode'] ?? null ) ? $input['mode'] : '';
		$attribute    = $attribute_id > 0 ? wc_get_attribute( $attribute_id ) : null;
		if ( null === $attribute ) {
			return new WP_Error( 'pixonaut_wc_ability_unknown_attribute', __( 'No global attribute has this id.', 'pixonaut-for-woocommerce' ), array( 'status' => WP_Http::NOT_FOUND ) );
		}
		if ( ! in_array( $mode, SplitListing::modes(), true ) ) {
			return new WP_Error( 'pixonaut_wc_ability_invalid_mode', __( 'The mode is not one of on, filtered or off.', 'pixonaut-for-woocommerce' ), array( 'status' => WP_Http::BAD_REQUEST ) );
		}
		SplitListing::set_attribute_mode( $attribute_id, $mode );
		return array(
			'attribute_id' => $attribute_id,
			'name'         => (string) $attribute->name,
			'mode'         => SplitListing::attribute_mode( $attribute_id ),
		);
	}
}
