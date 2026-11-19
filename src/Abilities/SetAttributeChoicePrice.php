<?php
/**
 * The ability that sets which price a global attribute's choices show.
 *
 * @package Pixonaut\WooCommerce
 */

declare(strict_types=1);

namespace Pixonaut\WooCommerce\Abilities;

use Pixonaut\WooCommerce\Storefront\ChoicePrices;
use WP_Error;
use WP_Http;

/**
 * Sets a global attribute's "Price beside each choice", as its edit screen
 * does.
 */
final class SetAttributeChoicePrice implements Ability {

	public function name(): string {
		return 'set-attribute-choice-price';
	}

	public function definition(): array {
		return array(
			/* translators: Name of an ability an AI assistant or an MCP client can use on the store. */
			'label'               => __( 'Set the price an attribute\'s choices show', 'pixonaut-for-woocommerce' ),
			'description'         => __( 'Sets the price a global attribute\'s options show on a product page, as its edit screen\'s "Price beside each choice" field does. `add_on` for an add-on such as a hanger, a frame or a frame\'s finish: each option shows what it adds to the product without add-ons in the chosen size and personalisation ("+180 kr"), worked out from the variations\' prices; the cheapest option shows nothing, and a finish shows its difference to the cheapest finish under the chosen framing. `from` for a base choice such as a size: each option shows its lowest price ("from 469 kr"). `price` for a base choice such as a size: each option shows the price the product has with it and the shopper\'s other choices ("599 kr"), and none while those choices leave more than one price. `none` shows no price. Prices count only variations in stock, and show beside the swatches or in the theme\'s own option field. The answer is the attribute\'s choice now.', 'pixonaut-for-woocommerce' ),
			'input_schema'        => array(
				'type'                 => 'object',
				'properties'           => array(
					'attribute_id' => array(
						'type'        => 'integer',
						'minimum'     => 1,
						'description' => __( 'A global attribute, by id.', 'pixonaut-for-woocommerce' ),
					),
					'choice_price' => array(
						'type' => 'string',
						'enum' => ChoicePrices::available(),
					),
				),
				'required'             => array( 'attribute_id', 'choice_price' ),
				'additionalProperties' => false,
			),
			'output_schema'       => GetAttributeChoicePrices::row_schema(),
			'execute_callback'    => array( $this, 'execute' ),
			'permission_callback' => array( AttributeQueryNames::class, 'can_manage' ),
			'annotations'         => array(
				'readonly'    => false,
				'destructive' => false,
				'idempotent'  => true,
			),
		);
	}

	/**
	 * @param mixed $input `attribute_id` and `choice_price`.
	 * @return array{attribute_id: int, name: string, slug: string, choice_price: string}|WP_Error
	 */
	public function execute( mixed $input = null ): array|WP_Error {
		$attribute_id = is_array( $input ) && is_int( $input['attribute_id'] ?? null ) ? $input['attribute_id'] : 0;
		$choice       = is_array( $input ) && is_string( $input['choice_price'] ?? null ) ? $input['choice_price'] : '';
		$attribute    = $attribute_id > 0 ? wc_get_attribute( $attribute_id ) : null;
		if ( null === $attribute ) {
			return new WP_Error( 'pixonaut_wc_ability_unknown_attribute', __( 'No global attribute has this id.', 'pixonaut-for-woocommerce' ), array( 'status' => WP_Http::NOT_FOUND ) );
		}
		if ( ! in_array( $choice, ChoicePrices::available(), true ) ) {
			return new WP_Error( 'pixonaut_wc_ability_invalid_choice_price', __( 'The price must be none, add_on, from or price.', 'pixonaut-for-woocommerce' ), array( 'status' => WP_Http::BAD_REQUEST ) );
		}
		ChoicePrices::set_choice( $attribute_id, $choice );
		return GetAttributeChoicePrices::row( $attribute_id, (string) $attribute->name, (string) preg_replace( '/^pa_/', '', (string) $attribute->slug ) );
	}
}
