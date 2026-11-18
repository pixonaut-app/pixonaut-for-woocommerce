<?php
/**
 * The ability that reads which price each global attribute's choices show.
 *
 * @package Pixonaut\WooCommerce
 */

declare(strict_types=1);

namespace Pixonaut\WooCommerce\Abilities;

use Pixonaut\WooCommerce\Storefront\ChoicePrices;

/**
 * Reads, for every global attribute, the price its swatches show beside
 * each value on a product page, as its edit screen's "Price beside each
 * choice" field sets it.
 */
final class GetAttributeChoicePrices implements Ability {

	public function name(): string {
		return 'get-attribute-choice-prices';
	}

	public function definition(): array {
		return array(
			/* translators: Name of an ability an AI assistant or an MCP client can use on the store. */
			'label'               => __( 'Read the price each attribute\'s choices show', 'pixonaut-for-woocommerce' ),
			'description'         => __( 'Reads, for every global attribute, the price its options show beside their swatches on a product page: `none`, `add_on` (what each option adds to the product without add-ons in the chosen size and personalisation, such as "+180 kr" for a frame; the cheapest option shows nothing) or `from` (each option\'s lowest price, such as "from 469 kr" for a size). Set one with `set-attribute-choice-price`.', 'pixonaut-for-woocommerce' ),
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
						'items' => self::row_schema(),
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
	 * @return array{attributes: list<array{attribute_id: int, name: string, slug: string, choice_price: string}>}
	 */
	public function execute(): array {
		$rows = array();
		foreach ( wc_get_attribute_taxonomies() as $attribute ) {
			$rows[] = self::row( (int) $attribute->attribute_id, (string) $attribute->attribute_label, (string) $attribute->attribute_name );
		}
		return array( 'attributes' => $rows );
	}

	/**
	 * One attribute's row.
	 *
	 * @param int    $attribute_id A global attribute's id.
	 * @param string $name         Its name.
	 * @param string $slug         Its slug, without `pa_`.
	 * @return array{attribute_id: int, name: string, slug: string, choice_price: string}
	 */
	public static function row( int $attribute_id, string $name, string $slug ): array {
		return array(
			'attribute_id' => $attribute_id,
			'name'         => $name,
			'slug'         => $slug,
			'choice_price' => ChoicePrices::choice( $attribute_id ),
		);
	}

	/**
	 * The schema of one row.
	 *
	 * @return array<string, mixed>
	 */
	public static function row_schema(): array {
		return array(
			'type'       => 'object',
			'properties' => array(
				'attribute_id' => array( 'type' => 'integer' ),
				'name'         => array( 'type' => 'string' ),
				'slug'         => array(
					'type'        => 'string',
					'description' => __( 'Its slug without `pa_`.', 'pixonaut-for-woocommerce' ),
				),
				'choice_price' => array(
					'type' => 'string',
					'enum' => ChoicePrices::available(),
				),
			),
		);
	}
}
