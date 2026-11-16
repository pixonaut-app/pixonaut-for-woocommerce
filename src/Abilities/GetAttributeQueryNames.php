<?php
/**
 * The ability that reads the name each global attribute goes by in the shop's addresses.
 *
 * @package Pixonaut\WooCommerce
 */

declare(strict_types=1);

namespace Pixonaut\WooCommerce\Abilities;

/**
 * Reads, for every global attribute, the short name a product page's
 * address and the shop's filters give it, as its edit screen's "Name in
 * addresses" field sets it.
 */
final class GetAttributeQueryNames implements Ability {

	public function name(): string {
		return 'get-attribute-query-names';
	}

	public function definition(): array {
		return array(
			/* translators: Name of an ability an AI assistant or an MCP client can use on the store. */
			'label'               => __( 'Read the names attributes go by in the shop\'s addresses', 'pixonaut-for-woocommerce' ),
			'description'         => __( 'Reads, for every global attribute, the name a product page\'s address and the shop\'s filters give it: `?size=50x70-cm&frame=oak` rather than WooCommerce\'s `?attribute_pa_size=50x70-cm`. Each goes by the name the store gave it (`own`), else by its slug. Set one with `set-attribute-query-name`.', 'pixonaut-for-woocommerce' ),
			'input_schema'        => array(
				'type'                 => 'object',
				'properties'           => array(),
				'additionalProperties' => false,
				'default'              => array(),
			),
			'output_schema'       => array(
				'type'       => 'object',
				'properties' => array(
					'attributes' => array(
						'type'  => 'array',
						'items' => AttributeQueryNames::row_schema(),
					),
				),
			),
			'execute_callback'    => array( $this, 'execute' ),
			'permission_callback' => array( AttributeQueryNames::class, 'can_manage' ),
			'annotations'         => array(
				'readonly'    => true,
				'destructive' => false,
				'idempotent'  => true,
			),
		);
	}

	/**
	 * @return array{attributes: list<array{attribute_id: int, name: string, query_name: string|null, own: string, slug: string}>}
	 */
	public function execute(): array {
		$rows = array();
		foreach ( wc_get_attribute_taxonomies() as $attribute ) {
			$rows[] = AttributeQueryNames::row( (int) $attribute->attribute_id, (string) $attribute->attribute_label );
		}
		return array( 'attributes' => $rows );
	}
}
