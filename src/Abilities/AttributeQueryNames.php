<?php
/**
 * What the abilities on attributes' names in addresses answer.
 *
 * @package Pixonaut\WooCommerce
 */

declare(strict_types=1);

namespace Pixonaut\WooCommerce\Abilities;

use Pixonaut\WooCommerce\Storefront\QueryNames;

/**
 * A global attribute's name in the shop's addresses, as
 * `get-attribute-query-names` and `set-attribute-query-name` answer it.
 */
final class AttributeQueryNames {

	/**
	 * What WooCommerce's attribute screens ask of whoever changes an attribute.
	 */
	public const CAPABILITY = 'manage_product_terms';

	/**
	 * Whether the current user may change the store's attributes.
	 */
	public static function can_manage(): bool {
		return current_user_can( self::CAPABILITY );
	}

	/**
	 * One attribute's row.
	 *
	 * @param int    $attribute_id A global attribute's id.
	 * @param string $name         Its name.
	 * @return array{attribute_id: int, name: string, query_name: string|null, own: string, slug: string}
	 */
	public static function row( int $attribute_id, string $name ): array {
		$query_name = QueryNames::of( $attribute_id );
		return array(
			'attribute_id' => $attribute_id,
			'name'         => $name,
			'query_name'   => '' === $query_name ? null : $query_name,
			'own'          => QueryNames::own( $attribute_id ),
			'slug'         => QueryNames::default_name( $attribute_id ),
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
				'query_name'   => array(
					'type'        => array( 'string', 'null' ),
					'description' => __( 'The name the shop\'s addresses give the attribute, as in `?size=50x70-cm`; null when it has none, as for a slug WordPress or WooCommerce reads itself, and keeps WooCommerce\'s `attribute_pa_<slug>` and `filter_<slug>`.', 'pixonaut-for-woocommerce' ),
				),
				'own'          => array(
					'type'        => 'string',
					'description' => __( 'The name the store gave it; empty when it goes by its slug.', 'pixonaut-for-woocommerce' ),
				),
				'slug'         => array(
					'type'        => 'string',
					'description' => __( 'Its slug without `pa_`, the name it goes by without one of its own while that is free.', 'pixonaut-for-woocommerce' ),
				),
			),
		);
	}
}
