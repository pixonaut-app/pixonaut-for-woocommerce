<?php
/**
 * A variable product's variation plan as the abilities and the product screen give it.
 *
 * @package Pixonaut\WooCommerce
 */

declare(strict_types=1);

namespace Pixonaut\WooCommerce\Abilities;

use Pixonaut\WooCommerce\Catalogue\BlankOptionLinks;
use Pixonaut\WooCommerce\Catalogue\ProductConfig;
use Pixonaut\WooCommerce\Catalogue\ProductIndex;
use Pixonaut\WooCommerce\Catalogue\ProductVariants;
use Pixonaut\WooCommerce\Catalogue\VariationChanges;
use Pixonaut\WooCommerce\Catalogue\VariationCombinations;
use Pixonaut\WooCommerce\Catalogue\VariationPlan;
use WC_Product;
use WP_Error;
use WP_Http;

/**
 * The plan's entries, the product's variation attributes with the values
 * they offer and the blank option each stands for, the blanks the product
 * uses, and the changes a dry run or a run lists, in the shape the
 * abilities' schemas declare.
 */
final class VariationPlanRows {

	/**
	 * The variable product an input names, or why there is none.
	 *
	 * @param mixed $input The ability's input, with `product_id`.
	 */
	public static function product( mixed $input ): WC_Product|WP_Error {
		$product = wc_get_product( Abilities::integer( $input, 'product_id', 0 ) );
		if ( ! $product instanceof WC_Product || ! $product->is_type( 'variable' ) ) {
			return new WP_Error( 'pixonaut_wc_ability_not_variable', __( 'No variable product has this id.', 'pixonaut-for-woocommerce' ), array( 'status' => WP_Http::NOT_FOUND ) );
		}
		return $product;
	}

	/**
	 * The product's variation attributes, each with its values, the blank
	 * option it stands for, and whether an entry picks its blank by it.
	 *
	 * @param WC_Product         $product A variable product.
	 * @param VariationPlan|null $plan    Its plan; null for none.
	 * @return list<array<string, mixed>>
	 */
	public static function attributes( WC_Product $product, ?VariationPlan $plan ): array {
		$selectors = null === $plan ? array() : $plan->selectors();
		$rows      = array();
		foreach ( VariationCombinations::offered( $product ) as $name => $values ) {
			$option = BlankOptionLinks::option_of( $name );
			$rows[] = array(
				'attribute'   => $name,
				'name'        => ProductVariants::attribute_label( $name, $product ),
				'option'      => $option,
				'picks_blank' => in_array( $name, $selectors, true ),
				'values'      => array_map(
					static fn ( array $value ): array => array(
						'value'      => $value['value'],
						'label'      => $value['label'],
						'stands_for' => null === $option || null === $value['term'] ? null : BlankOptionLinks::stands_for( $name, $value['term'] ),
					),
					$values
				),
			);
		}
		return $rows;
	}

	/**
	 * The blanks the product and its variations are set up on now, the
	 * product's own first, each with its name as last read.
	 *
	 * @param WC_Product $product A variable product.
	 * @return list<array{blank_id: string, name: string}>
	 */
	public static function blanks( WC_Product $product ): array {
		$names = array();
		$own   = ProductConfig::stored_on( $product );
		if ( null !== $own->blank_id ) {
			$names[ $own->blank_id ] = (string) $own->blank_name;
		}
		$used = ProductIndex::blanks_of( $product->get_id() );
		foreach ( $product->get_children() as $child_id ) {
			$child  = wc_get_product( $child_id );
			$config = $child instanceof WC_Product && ProductConfig::is_stored_on( $child ) ? ProductConfig::stored_on( $child ) : null;
			if ( null !== $config && null !== $config->blank_id && ! isset( $names[ $config->blank_id ] ) && in_array( $config->blank_id, $used, true ) ) {
				$names[ $config->blank_id ] = (string) $config->blank_name;
			}
		}
		$blanks = array();
		foreach ( $names as $blank_id => $name ) {
			$blanks[] = array(
				'blank_id' => (string) $blank_id,
				'name'     => '' === $name ? (string) $blank_id : $name,
			);
		}
		return $blanks;
	}

	/**
	 * The changes as the abilities answer them.
	 *
	 * @param VariationChanges $changes The changes.
	 * @return array<string, mixed>
	 */
	public static function changes( VariationChanges $changes ): array {
		$rows = static fn ( array $changed, array $keys ): array => array_map(
			static fn ( array $one ): array => array_intersect_key( array( 'attributes' => (object) $one['attributes'] ) + $one, array_flip( $keys ) ),
			$changed
		);
		return array(
			'add'     => $rows( $changes->add, array( 'attributes', 'label', 'blank_id', 'ref', 'variant' ) ),
			'change'  => $rows( $changes->change, array( 'variation_id', 'attributes', 'label', 'blank_id', 'ref', 'variant', 'why' ) ),
			'disable' => $rows( $changes->disable, array( 'variation_id', 'attributes', 'label', 'why' ) ),
			'skipped' => $rows( $changes->skipped, array( 'attributes', 'label', 'blank_id', 'why' ) ),
			'kept'    => count( $changes->kept ),
		);
	}

	/**
	 * The schema of an entry of the plan.
	 *
	 * @return array<string, mixed>
	 */
	public static function entry_schema(): array {
		$map = static fn ( string $what ): array => array(
			'type'                 => 'object',
			'description'          => $what,
			'additionalProperties' => array(
				'type'      => 'string',
				'maxLength' => VariationPlan::MAX_LENGTH,
			),
		);
		return array(
			'type'                 => 'object',
			'properties'           => array(
				'blank_id' => array(
					'type'        => 'string',
					'minLength'   => 1,
					'description' => __( 'The Pixonaut blank these variations are printed on.', 'pixonaut-for-woocommerce' ),
				),
				'when'     => $map( __( 'The attribute values that pick this blank, by attribute name (`pa_frame`) with a value\'s slug or name, e.g. `{"pa_frame": "framed"}`; empty when the product is sold on this blank alone.', 'pixonaut-for-woocommerce' ) ),
				'fixed'    => $map( __( 'The value sold of each option of the blank no attribute offers, as Pixonaut writes them, e.g. `{"Orientation": "Vertical"}`; needed only where the blank sells more than one value.', 'pixonaut-for-woocommerce' ) ),
			),
			'required'             => array( 'blank_id' ),
			'additionalProperties' => false,
		);
	}

	/**
	 * The schema of the changes a dry run or a run lists.
	 *
	 * @return array<string, mixed>
	 */
	public static function changes_schema(): array {
		$row  = static fn ( array $properties ): array => array(
			'type'  => 'array',
			'items' => array(
				'type'       => 'object',
				'properties' => array(
					'attributes' => array(
						'type'                 => 'object',
						'description'          => __( 'The values by attribute name, as the variation holds them; empty for "Any".', 'pixonaut-for-woocommerce' ),
						'additionalProperties' => array( 'type' => 'string' ),
					),
					'label'      => array( 'type' => 'string' ),
				) + $properties,
			),
		);
		$sold = array(
			'blank_id' => array( 'type' => 'string' ),
			'ref'      => array(
				'type'        => 'string',
				'description' => __( 'The blank variant it is sold as.', 'pixonaut-for-woocommerce' ),
			),
			'variant'  => array( 'type' => 'string' ),
		);
		$id   = array( 'variation_id' => array( 'type' => 'integer' ) );
		return array(
			'add'     => $row( $sold ),
			'change'  => $row(
				$id + $sold + array(
					'why' => array(
						'type'        => 'string',
						'enum'        => array( VariationChanges::ENABLE, VariationChanges::VARIANT ),
						'description' => __( '`enable`: disabled by an earlier run, offered again. `variant`: sold no variant of its blank, now the plan\'s.', 'pixonaut-for-woocommerce' ),
					),
				)
			),
			'disable' => $row(
				$id + array(
					'why' => array(
						'type' => 'string',
						'enum' => array( VariationChanges::NOT_OFFERED, VariationChanges::RETIRED ),
					),
				)
			),
			'skipped' => $row(
				array(
					'blank_id' => array( 'type' => 'string' ),
					'why'      => array(
						'type'        => 'string',
						'enum'        => array( VariationChanges::NO_VARIANT, VariationChanges::SEVERAL_VARIANTS, VariationChanges::RETIRED ),
						'description' => __( 'Why no variation is made for these values: they name no variant of the blank, several (an option is left open), or a retired one.', 'pixonaut-for-woocommerce' ),
					),
				)
			),
			'kept'    => array(
				'type'        => 'integer',
				'description' => __( 'How many variations are kept as they are.', 'pixonaut-for-woocommerce' ),
			),
		);
	}
}
