<?php
/**
 * The ability that reads which of a product's values are listed as their own products.
 *
 * @package Pixonaut\WooCommerce
 */

declare(strict_types=1);

namespace Pixonaut\WooCommerce\Abilities;

use Pixonaut\WooCommerce\Catalogue\ProductConfig;
use Pixonaut\WooCommerce\Catalogue\SplitCardLooks;
use Pixonaut\WooCommerce\Catalogue\SplitListing;
use WC_Product;
use WC_Product_Attribute;
use WP_Error;

/**
 * Reads, for each global attribute of a product, whether its values are
 * listed as their own products and each value's card's own title and image,
 * as the product's Attributes tab shows them. Whoever may edit the product
 * may read it.
 */
final class GetProductSplitListing implements Ability {

	public function name(): string {
		return 'get-product-split-listing';
	}

	public function definition(): array {
		return array(
			/* translators: Name of an ability an AI assistant or an MCP client can use on the store. */
			'label'               => __( 'Read which values a product lists as their own products', 'pixonaut-for-woocommerce' ),
			'description'         => __( 'Reads, for each global attribute of a simple or variable product, its "Show each value as its own product" choice (`auto` follows the attribute\'s own setting, `attribute_default`), whether that lists its values as their own products, and each value\'s card\'s own title and image. `listed_by` is the attribute the product is listed by now; null when it is listed once. Set them with `set-product-split-listing`.', 'pixonaut-for-woocommerce' ),
			'input_schema'        => array(
				'type'                 => 'object',
				'properties'           => array(
					'product_id' => array(
						'type'    => 'integer',
						'minimum' => 1,
					),
				),
				'required'             => array( 'product_id' ),
				'additionalProperties' => false,
			),
			'output_schema'       => self::output_schema(),
			'execute_callback'    => array( $this, 'execute' ),
			'permission_callback' => array( ProductLinks::class, 'can_edit' ),
			'annotations'         => array(
				'readonly'    => true,
				'destructive' => false,
				'idempotent'  => true,
			),
		);
	}

	/**
	 * @param mixed $input `product_id`.
	 * @return array<string, mixed>|WP_Error
	 */
	public function execute( mixed $input = null ): array|WP_Error {
		$product = ProductLinks::product( $input );
		return $product instanceof WC_Product ? self::answer( $product ) : $product;
	}

	/**
	 * A product's global attributes, each with its choice and its values'
	 * cards, as this ability and `set-product-split-listing` answer them.
	 *
	 * @param WC_Product $product A simple or variable product.
	 * @return array<string, mixed>
	 */
	public static function answer( WC_Product $product ): array {
		$config     = ProductConfig::stored_on( $product );
		$looks      = SplitCardLooks::stored_on( $product->get_id() );
		$listed_by  = array_map( static fn ( WC_Product_Attribute $attribute ): int => $attribute->get_id(), SplitListing::attributes_of( $product ) );
		$attributes = array();
		foreach ( self::global_attributes( $product ) as $attribute ) {
			$values = array();
			foreach ( $attribute->get_terms() ?? array() as $term ) {
				$look     = $looks[ $term->term_id ] ?? array(
					'title' => '',
					'image' => 0,
				);
				$values[] = array(
					'term_id' => $term->term_id,
					'name'    => $term->name,
					'title'   => $look['title'],
					'image'   => self::image( $look['image'] ),
				);
			}
			$attributes[] = array(
				'attribute_id'      => $attribute->get_id(),
				'name'              => wc_attribute_label( $attribute->get_name(), $product ),
				'choice'            => SplitListing::choice( $config, $attribute->get_id() ),
				'attribute_default' => SplitListing::attribute_default( $attribute->get_id() ),
				// Its choice alone does not say: an attribute on Auto is not listed by while the product turned another on itself.
				'own_products'      => in_array( $attribute->get_id(), $listed_by, true ),
				'values'            => $values,
			);
		}
		return array(
			'product_id'    => $product->get_id(),
			// The first of them, as before a product could be listed by several.
			'listed_by'     => $listed_by[0] ?? null,
			'listed_by_all' => $listed_by,
			'attributes'    => $attributes,
		);
	}

	/**
	 * A product's global attributes, by id: the ones whose values can be
	 * listed as their own products (`SplitListing`).
	 *
	 * @param WC_Product $product A product.
	 * @return array<int, WC_Product_Attribute>
	 */
	public static function global_attributes( WC_Product $product ): array {
		$attributes = array();
		foreach ( $product->get_attributes() as $attribute ) {
			if ( $attribute instanceof WC_Product_Attribute && $attribute->is_taxonomy() && $attribute->get_id() > 0 ) {
				$attributes[ $attribute->get_id() ] = $attribute;
			}
		}
		return $attributes;
	}

	/**
	 * A card's own image; null when it has none, or the media library no
	 * longer has it.
	 *
	 * @param int $attachment_id The image's attachment id; 0 for none.
	 * @return array{id: int, src: string}|null
	 */
	private static function image( int $attachment_id ): ?array {
		$src = $attachment_id > 0 ? wp_get_attachment_url( $attachment_id ) : false;
		return is_string( $src ) && '' !== $src ? array(
			'id'  => $attachment_id,
			'src' => $src,
		) : null;
	}

	/**
	 * @return array<string, mixed>
	 */
	public static function output_schema(): array {
		return array(
			'type'       => 'object',
			'properties' => array(
				'product_id'    => array( 'type' => 'integer' ),
				'listed_by'     => array(
					'type'        => array( 'integer', 'null' ),
					'description' => __( 'The id of the attribute the product is listed by, one card per value; null when it is listed once. Only a variable product is listed by an attribute, one used for its variations.', 'pixonaut-for-woocommerce' ),
				),
				'listed_by_all' => array(
					'type'  => 'array',
					'items' => array( 'type' => 'integer' ),
				),
				'attributes'    => array(
					'type'  => 'array',
					'items' => array(
						'type'       => 'object',
						'properties' => array(
							'attribute_id'      => array( 'type' => 'integer' ),
							'name'              => array( 'type' => 'string' ),
							'choice'            => array(
								'type' => 'string',
								'enum' => SplitListing::available(),
							),
							'attribute_default' => array(
								'type'        => 'boolean',
								'description' => __( 'Whether the attribute lists its values as their own products by default.', 'pixonaut-for-woocommerce' ),
							),
							'own_products'      => array(
								'type'        => 'boolean',
								'description' => __( 'What the choice comes to: whether the product lists each value of this attribute as its own product.', 'pixonaut-for-woocommerce' ),
							),
							'values'            => array(
								'type'  => 'array',
								'items' => array(
									'type'       => 'object',
									'properties' => array(
										'term_id' => array( 'type' => 'integer' ),
										'name'    => array( 'type' => 'string' ),
										'title'   => array(
											'type'        => 'string',
											'description' => __( 'The card\'s own title; empty when it shows the product\'s.', 'pixonaut-for-woocommerce' ),
										),
										'image'   => array(
											'type'        => array( 'object', 'null' ),
											'description' => __( 'The card\'s own image, by its attachment id and URL; null when it shows the value\'s picture or the product\'s.', 'pixonaut-for-woocommerce' ),
											'properties'  => array(
												'id'  => array( 'type' => 'integer' ),
												'src' => array( 'type' => 'string' ),
											),
										),
									),
								),
							),
						),
					),
				),
			),
		);
	}
}
