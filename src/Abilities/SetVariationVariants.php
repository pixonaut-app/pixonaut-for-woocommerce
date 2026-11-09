<?php
/**
 * The ability that sets which blank variant each variation is printed as.
 *
 * @package Pixonaut\WooCommerce
 */

declare(strict_types=1);

namespace Pixonaut\WooCommerce\Abilities;

use Pixonaut\WooCommerce\Catalogue\BlankVariant;
use Pixonaut\WooCommerce\Catalogue\ProductConfig;
use Pixonaut\WooCommerce\Catalogue\ProductVariants;
use Pixonaut\WooCommerce\Vendor\Pixonaut\Sdk\Exception\ApiException;
use WC_Product;
use WP_Error;

/**
 * Picks, for each variation named, the variant of its blank it is printed
 * as, by the variant's options: the pick a variation's blank variant field
 * saves, so the field shows it and the product's warning clears once each
 * variation sells a variant. Every variation is checked, and its blank's
 * variants read, before any is written: one that does not fit refuses the
 * whole call. Whoever may edit the product may set them.
 */
final class SetVariationVariants implements Ability {

	/**
	 * The most variations one call sets.
	 */
	private const MAX_VARIATIONS = 100;

	/**
	 * The status a refusal carries when Pixonaut could not be asked.
	 */
	private const BAD_GATEWAY = 502;

	/**
	 * @param ProductLinks    $links    Gives the product's answer.
	 * @param ProductVariants $variants Reads a blank's variants and stores a variation's pick.
	 */
	public function __construct(
		private readonly ProductLinks $links,
		private readonly ProductVariants $variants,
	) {
	}

	public function name(): string {
		return 'set-variation-variants';
	}

	public function definition(): array {
		return array(
			/* translators: Name of an ability an AI assistant or an MCP client can use on the store. */
			'label'               => __( 'Set the blank variant of variations', 'pixonaut-for-woocommerce' ),
			'description'         => __( 'Sets which variant of its blank each listed variation of a variable product is printed as, by the variant\'s options, as picking it in the variation\'s Pixonaut panel does. Nothing is saved unless every variation listed names exactly one variant that is still sold. The answer gives every variation of the product with the variant it sells now.', 'pixonaut-for-woocommerce' ),
			'input_schema'        => array(
				'type'                 => 'object',
				'properties'           => array(
					'product_id' => array(
						'type'    => 'integer',
						'minimum' => 1,
					),
					'variations' => array(
						'type'     => 'array',
						'minItems' => 1,
						'maxItems' => self::MAX_VARIATIONS,
						'items'    => array(
							'type'                 => 'object',
							'properties'           => array(
								'variation_id' => array(
									'type'    => 'integer',
									'minimum' => 1,
								),
								'options'      => array(
									'type'                 => 'object',
									'description'          => __( 'The variant\'s option values by axis, e.g. `{"Size": "M"}`, as Pixonaut writes them or as the variation\'s Pixonaut panel shows them; every axis of the variant and no other. Empty for a blank without options.', 'pixonaut-for-woocommerce' ),
									'additionalProperties' => array( 'type' => 'string' ),
								),
							),
							'required'             => array( 'variation_id', 'options' ),
							'additionalProperties' => false,
						),
					),
				),
				'required'             => array( 'product_id', 'variations' ),
				'additionalProperties' => false,
			),
			'output_schema'       => array(
				'type'       => 'object',
				'properties' => array(
					'product_id'    => array( 'type' => 'integer' ),
					'purchasable'   => array(
						'type'        => 'boolean',
						'description' => __( 'Whether customers can buy the product now, its blank variants and WooCommerce\'s own checks included.', 'pixonaut-for-woocommerce' ),
					),
					'variant_issue' => array(
						'type'        => array( 'string', 'null' ),
						'description' => __( 'Which of the product\'s variations sell no variant of the blank, in words; null when each does.', 'pixonaut-for-woocommerce' ),
					),
					'variations'    => array(
						'type'  => 'array',
						'items' => array(
							'type'       => 'object',
							'properties' => array(
								'variation_id' => array( 'type' => 'integer' ),
								'variant'      => ProductSetup::variant_schema( __( 'The blank\'s variant the variation sells; null when it is not personalised on a blank.', 'pixonaut-for-woocommerce' ) ),
							),
						),
					),
				),
			),
			'execute_callback'    => array( $this, 'execute' ),
			'permission_callback' => array( ProductLinks::class, 'can_edit' ),
			'annotations'         => array(
				'readonly'    => false,
				'destructive' => true,
				'idempotent'  => true,
			),
		);
	}

	/**
	 * @param mixed $input `product_id` and `variations`.
	 * @return array<string, mixed>|WP_Error
	 */
	public function execute( mixed $input = null ): array|WP_Error {
		$product = wc_get_product( Abilities::integer( $input, 'product_id', 0 ) );
		if ( ! $product instanceof WC_Product || ! $product->is_type( 'variable' ) ) {
			return new WP_Error( 'pixonaut_wc_ability_not_variable', __( 'No variable product has this id.', 'pixonaut-for-woocommerce' ) );
		}
		$rows  = is_array( $input ) && is_array( $input['variations'] ?? null ) ? $input['variations'] : array();
		$picks = $this->picks( $product, $rows );
		if ( $picks instanceof WP_Error ) {
			return $picks;
		}
		try {
			foreach ( $picks as $pick ) {
				// The blank's variants were read for the checks, and are not read again in this request.
				$this->variants->pick( $pick['variation'], $pick['ref'] );
			}
		} catch ( ApiException $e ) {
			return self::unread();
		} finally {
			$this->variants->note( $product );
		}
		return $this->answer( $product );
	}

	/**
	 * Each variation named with the ref of the variant its options name,
	 * or why the call is refused.
	 *
	 * @param WC_Product   $product The variable product.
	 * @param array<mixed> $rows    The input's `variations`.
	 * @return list<array{variation: WC_Product, ref: string}>|WP_Error
	 */
	private function picks( WC_Product $product, array $rows ): array|WP_Error {
		$picks = array();
		$seen  = array();
		foreach ( $rows as $row ) {
			$id = Abilities::integer( $row, 'variation_id', 0 );
			if ( isset( $seen[ $id ] ) ) {
				/* translators: %d: a variation's id. Why an AI assistant's change to the store was refused. */
				return new WP_Error( 'pixonaut_wc_ability_variation_twice', sprintf( __( 'Variation %d is listed twice.', 'pixonaut-for-woocommerce' ), $id ) );
			}
			$seen[ $id ] = true;
			$variation   = wc_get_product( $id );
			if ( ! $variation instanceof WC_Product || ! $variation->is_type( 'variation' ) || $variation->get_parent_id() !== $product->get_id() ) {
				/* translators: 1: a variation's id; 2: a product's id. Why an AI assistant's change to the store was refused. */
				return new WP_Error( 'pixonaut_wc_ability_not_a_variation', sprintf( __( 'Variation %1$d is not a variation of product %2$d.', 'pixonaut-for-woocommerce' ), $id, $product->get_id() ) );
			}
			$config = ProductConfig::config_for( $variation );
			if ( ! $config->personalise || null === $config->blank_id ) {
				/* translators: %d: a variation's id. Why an AI assistant's change to the store was refused. */
				return new WP_Error( 'pixonaut_wc_ability_variation_without_blank', sprintf( __( 'Variation %d is not personalised on a blank.', 'pixonaut-for-woocommerce' ), $id ) );
			}
			try {
				$variants = $this->variants->named( $config->blank_id );
			} catch ( ApiException $e ) {
				return self::unread();
			}
			$ref = self::named( $id, self::options( $row ), $variants );
			if ( $ref instanceof WP_Error ) {
				return $ref;
			}
			$picks[] = array(
				'variation' => $variation,
				'ref'       => $ref,
			);
		}
		return $picks;
	}

	/**
	 * The ref of the one variant the options name, still sold.
	 *
	 * @param int                      $id       The variation's id, for the refusal.
	 * @param array<array-key, string> $options The options given.
	 * @param BlankVariant[]           $variants The blank's variants.
	 * @phpstan-param list<BlankVariant> $variants
	 */
	private static function named( int $id, array $options, array $variants ): string|WP_Error {
		$named = array_values( array_filter( $variants, static fn ( BlankVariant $variant ): bool => $variant->is_named_by( $options ) ) );
		if ( array() === $named ) {
			/* translators: %d: a variation's id. Why an AI assistant's change to the store was refused. */
			return new WP_Error( 'pixonaut_wc_ability_no_variant', sprintf( __( 'The options of variation %d name no variant of its blank.', 'pixonaut-for-woocommerce' ), $id ) );
		}
		if ( count( $named ) > 1 ) {
			/* translators: %d: a variation's id. Why an AI assistant's change to the store was refused. */
			return new WP_Error( 'pixonaut_wc_ability_several_variants', sprintf( __( 'The options of variation %d name several variants of its blank.', 'pixonaut-for-woocommerce' ), $id ) );
		}
		if ( null !== $named[0]->retired ) {
			/* translators: %d: a variation's id. Why an AI assistant's change to the store was refused. */
			return new WP_Error( 'pixonaut_wc_ability_retired_variant', sprintf( __( 'The options of variation %d name a variant of its blank that is retired.', 'pixonaut-for-woocommerce' ), $id ) );
		}
		return $named[0]->ref;
	}

	/**
	 * A row's options, labels by axis; a numeric axis stays an integer key.
	 *
	 * @param mixed $row One of the input's `variations`.
	 * @return array<array-key, string>
	 */
	private static function options( mixed $row ): array {
		$options = array();
		foreach ( is_array( $row ) && is_array( $row['options'] ?? null ) ? $row['options'] : array() as $axis => $label ) {
			if ( is_string( $label ) ) {
				$options[ $axis ] = $label;
			}
		}
		return $options;
	}

	/**
	 * The product as saved, with the variant each of its variations sells.
	 *
	 * @param WC_Product $product The variable product.
	 * @return array<string, mixed>
	 */
	private function answer( WC_Product $product ): array {
		$summary    = $this->links->summary( $product );
		$variations = array();
		foreach ( $product->get_children() as $child_id ) {
			$variation = wc_get_product( $child_id );
			if ( $variation instanceof WC_Product ) {
				$variations[] = array(
					'variation_id' => $variation->get_id(),
					'variant'      => ProductSetup::variation_variant( $variation ),
				);
			}
		}
		return array(
			'product_id'    => $product->get_id(),
			'purchasable'   => $summary['purchasable'],
			'variant_issue' => $summary['variant_issue'],
			'variations'    => $variations,
		);
	}

	/**
	 * The refusal when a blank's variants could not be read from Pixonaut.
	 */
	private static function unread(): WP_Error {
		return new WP_Error( 'pixonaut_wc_ability_variants_unread', __( 'Pixonaut could not be reached to read the blank\'s variants.', 'pixonaut-for-woocommerce' ), array( 'status' => self::BAD_GATEWAY ) );
	}
}
