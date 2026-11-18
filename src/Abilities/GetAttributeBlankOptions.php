<?php
/**
 * The ability that reads the blank option each global attribute stands for.
 *
 * @package Pixonaut\WooCommerce
 */

declare(strict_types=1);

namespace Pixonaut\WooCommerce\Abilities;

use Pixonaut\WooCommerce\Catalogue\BlankOptionChoices;

/**
 * Reads, for every global attribute or one, the blank option it stands for,
 * the option value each of its values stands for, and the options it can be
 * linked to, as its edit screen's "Blank option" shows them.
 *
 * @phpstan-import-type Row from BlankOptionRows
 */
final class GetAttributeBlankOptions implements Ability {

	/**
	 * @param BlankOptionChoices $choices The options an attribute can stand for.
	 */
	public function __construct( private readonly BlankOptionChoices $choices ) {
	}

	public function name(): string {
		return 'get-attribute-blank-options';
	}

	public function definition(): array {
		return array(
			/* translators: Name of an ability an AI assistant or an MCP client can use on the store. */
			'label'               => __( 'Read the blank options attributes stand for', 'pixonaut-for-woocommerce' ),
			'description'         => __( 'Reads, for every global attribute or the one given, the option of the Pixonaut blank it stands for (a frame colour attribute for the blank\'s Color), the option value each of its values stands for, and `options`: the options it can be linked to, each with its values, from the blanks its products use. A linked attribute\'s values pick the blank\'s variant and show in the designer as the option values\' colour or picture swatches; a value whose `stands_for` is null is not linked and shows as text. Link one with `set-attribute-blank-option`.', 'pixonaut-for-woocommerce' ),
			'input_schema'        => array(
				'type'                 => 'object',
				'properties'           => array(
					'attribute_id' => array(
						'type'        => 'integer',
						'minimum'     => 1,
						'description' => __( 'Only this global attribute, by id.', 'pixonaut-for-woocommerce' ),
					),
				),
				'additionalProperties' => false,
				'default'              => array(),
			),
			'output_schema'       => array(
				'type'       => 'object',
				'properties' => array(
					'attributes' => array(
						'type'  => 'array',
						'items' => BlankOptionRows::row_schema(),
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
	 * @param mixed $input Optionally `attribute_id`.
	 * @return array{attributes: list<Row>}
	 */
	public function execute( mixed $input = null ): array {
		$only = Abilities::integer( $input, 'attribute_id', 0 );
		$rows = array();
		foreach ( wc_get_attribute_taxonomies() as $attribute ) {
			$attribute_id = (int) $attribute->attribute_id;
			if ( $only > 0 && $only !== $attribute_id ) {
				continue;
			}
			$rows[] = BlankOptionRows::row( $attribute_id, (string) $attribute->attribute_label, $this->choices->for_taxonomy( wc_attribute_taxonomy_name_by_id( $attribute_id ) ) );
		}
		return array( 'attributes' => $rows );
	}
}
