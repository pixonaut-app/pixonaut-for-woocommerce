<?php
/**
 * A global attribute's link to a blank option, as the abilities answer it.
 *
 * @package Pixonaut\WooCommerce
 */

declare(strict_types=1);

namespace Pixonaut\WooCommerce\Abilities;

use Pixonaut\WooCommerce\Catalogue\BlankOptionLinks;
/**
 * One global attribute's row in `get-attribute-blank-options` and
 * `set-attribute-blank-option`: the option it stands for, each of its values with
 * the option value it stands for, and the options it can be linked to.
 *
 * @phpstan-type ValueRow array{term_id: int, slug: string, name: string, value: string|null, stands_for: string|null}
 * @phpstan-type Row array{attribute_id: int, name: string, taxonomy: string, option: string|null, values: list<ValueRow>, options: array<string, list<string>>}
 */
final class BlankOptionRows {

	/**
	 * One attribute's row.
	 *
	 * @param int                         $attribute_id A global attribute's id.
	 * @param string                      $name         Its name.
	 * @param array<string, list<string>> $choices      The options it can stand for, each with its values.
	 * @return Row
	 */
	public static function row( int $attribute_id, string $name, array $choices ): array {
		$taxonomy = wc_attribute_taxonomy_name_by_id( $attribute_id );
		$link     = BlankOptionLinks::of( $attribute_id );
		$offered  = null === $link ? array() : $choices[ $link['option'] ] ?? array();
		$values   = array();
		foreach ( BlankOptionLinks::terms( $taxonomy ) as $term ) {
			$value    = null === $link ? null : $link['values'][ $term->term_id ] ?? null;
			$values[] = array(
				'term_id'    => $term->term_id,
				'slug'       => $term->slug,
				'name'       => $term->name,
				'value'      => $value,
				'stands_for' => null === $link ? null : $value ?? BlankOptionLinks::same_name( $term->name, $offered ),
			);
		}
		return array(
			'attribute_id' => $attribute_id,
			'name'         => $name,
			'taxonomy'     => $taxonomy,
			'option'       => $link['option'] ?? null,
			'values'       => $values,
			'options'      => $choices,
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
				'taxonomy'     => array( 'type' => 'string' ),
				'option'       => array(
					'type'        => array( 'string', 'null' ),
					'description' => __( 'The blank option the attribute stands for, as Pixonaut writes it; null when it is not linked.', 'pixonaut-for-woocommerce' ),
				),
				'values'       => array(
					'type'  => 'array',
					'items' => array(
						'type'       => 'object',
						'properties' => array(
							'term_id'    => array( 'type' => 'integer' ),
							'slug'       => array( 'type' => 'string' ),
							'name'       => array( 'type' => 'string' ),
							'value'      => array(
								'type'        => array( 'string', 'null' ),
								'description' => __( 'The option value the value was linked to; null when it was given none.', 'pixonaut-for-woocommerce' ),
							),
							'stands_for' => array(
								'type'        => array( 'string', 'null' ),
								'description' => __( 'The option value it stands for: the one it was linked to, else the option\'s value of its name; null when it is not linked, and then shows as text.', 'pixonaut-for-woocommerce' ),
							),
						),
					),
				),
				'options'      => array(
					'type'                 => 'object',
					'description'          => __( 'The options the attribute can be linked to, each with its values: those of the blanks the products with this attribute use, themselves or through their variations.', 'pixonaut-for-woocommerce' ),
					'additionalProperties' => array(
						'type'  => 'array',
						'items' => array( 'type' => 'string' ),
					),
				),
			),
		);
	}
}
