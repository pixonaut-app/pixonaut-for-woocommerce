<?php
/**
 * The ability that sets the name a global attribute goes by in the shop's addresses.
 *
 * @package Pixonaut\WooCommerce
 */

declare(strict_types=1);

namespace Pixonaut\WooCommerce\Abilities;

use Pixonaut\WooCommerce\Storefront\QueryNames;
use WP_Error;
use WP_Http;

/**
 * Sets a global attribute's "Name in addresses", as its edit screen does,
 * refusing one WordPress or WooCommerce reads from an address or another
 * attribute goes by.
 */
final class SetAttributeQueryName implements Ability {

	public function name(): string {
		return 'set-attribute-query-name';
	}

	public function definition(): array {
		return array(
			/* translators: Name of an ability an AI assistant or an MCP client can use on the store. */
			'label'               => __( 'Set the name an attribute goes by in the shop\'s addresses', 'pixonaut-for-woocommerce' ),
			'description'         => __( 'Sets the name a product page\'s address and the shop\'s filters give a global attribute, as its edit screen\'s "Name in addresses" field does: `size` makes `?size=50x70-cm`. Lowercase letters and digits, words joined by a hyphen or an underscore. An empty name gives it back its slug. A name WordPress or WooCommerce reads from an address, or that another attribute goes by, is refused. Links shared with the old name no longer choose the value. The answer is the attribute\'s name now.', 'pixonaut-for-woocommerce' ),
			'input_schema'        => array(
				'type'                 => 'object',
				'properties'           => array(
					'attribute_id' => array(
						'type'        => 'integer',
						'minimum'     => 1,
						'description' => __( 'A global attribute, by id.', 'pixonaut-for-woocommerce' ),
					),
					'query_name'   => array(
						'type'      => 'string',
						'maxLength' => QueryNames::MAX_LENGTH,
					),
				),
				'required'             => array( 'attribute_id', 'query_name' ),
				'additionalProperties' => false,
			),
			'output_schema'       => AttributeQueryNames::row_schema(),
			'execute_callback'    => array( $this, 'execute' ),
			'permission_callback' => array( AttributeQueryNames::class, 'can_manage' ),
			'annotations'         => array(
				'readonly'    => false,
				// Shared links naming the old name no longer choose its value.
				'destructive' => true,
				'idempotent'  => true,
			),
		);
	}

	/**
	 * @param mixed $input `attribute_id` and `query_name`.
	 * @return array{attribute_id: int, name: string, query_name: string|null, own: string, slug: string}|WP_Error
	 */
	public function execute( mixed $input = null ): array|WP_Error {
		$attribute_id = is_array( $input ) && is_int( $input['attribute_id'] ?? null ) ? $input['attribute_id'] : 0;
		$name         = is_array( $input ) && is_string( $input['query_name'] ?? null ) ? trim( $input['query_name'] ) : '';
		$attribute    = $attribute_id > 0 ? wc_get_attribute( $attribute_id ) : null;
		if ( null === $attribute ) {
			return new WP_Error( 'pixonaut_wc_ability_unknown_attribute', __( 'No global attribute has this id.', 'pixonaut-for-woocommerce' ), array( 'status' => WP_Http::NOT_FOUND ) );
		}
		$refusal = QueryNames::set( $attribute_id, $name );
		if ( null !== $refusal ) {
			return new WP_Error( 'pixonaut_wc_ability_invalid_query_name', $refusal, array( 'status' => WP_Http::BAD_REQUEST ) );
		}
		return AttributeQueryNames::row( $attribute_id, (string) $attribute->name );
	}
}
