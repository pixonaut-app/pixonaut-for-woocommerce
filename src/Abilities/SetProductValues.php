<?php
/**
 * The ability that sets a product's own values for its templates' layers.
 *
 * @package Pixonaut\WooCommerce
 */

declare(strict_types=1);

namespace Pixonaut\WooCommerce\Abilities;

use Pixonaut\WooCommerce\Api\ValueSetRefused;
use Pixonaut\WooCommerce\Api\ValueSets;
use Pixonaut\WooCommerce\Catalogue\PostedSides;
use Pixonaut\WooCommerce\Catalogue\ProductConfig;
use Pixonaut\WooCommerce\Catalogue\Side;
use Pixonaut\WooCommerce\Catalogue\SideValue;
use Pixonaut\WooCommerce\Catalogue\SideValues;
use Pixonaut\WooCommerce\Catalogue\TemplateId;
use Pixonaut\WooCommerce\Vendor\Pixonaut\Sdk\Exception\ApiException;
use Pixonaut\WooCommerce\Vendor\Pixonaut\Sdk\Generated\ErrorCode;
use WC_Product;
use WP_Error;
use WP_Http;

/**
 * Sets a product's or variation's own values for its sides' templates, by
 * layer and prop: one product sells a design in one theme and for one
 * recipient, another the same design in another, from one template. Each
 * side's values are made into a value set of the side's template version
 * with the store's key, which judges them as a publish judges a showcase,
 * and the set is kept beside them (`SideValues`). The designer, the
 * pictures and the cart name the set: an offered prop starts at its value,
 * a fixed one prints it.
 *
 * A variation with a setup of its own has values of its own; one that
 * follows its product is sold with its product's, and is refused. Every
 * problem is said at once and nothing is saved; values Pixonaut refused are
 * named by side, layer and prop. Whoever may edit the product may set them.
 */
final class SetProductValues implements Ability {

	/**
	 * Why the call was refused: what it names does not fit the product, or
	 * Pixonaut refused the values.
	 */
	public const REFUSED = 'pixonaut_wc_ability_values_refused';

	/**
	 * @param ValueSets $value_sets Makes each side's value set.
	 */
	public function __construct( private readonly ValueSets $value_sets ) {
	}

	public function name(): string {
		return 'set-product-values';
	}

	public function definition(): array {
		return array(
			/* translators: Name of an ability an AI assistant or an MCP client can use on the store. */
			'label'               => __( 'Set a product\'s own values for its template', 'pixonaut-for-woocommerce' ),
			'description'         => __( 'Sets the values a simple or variable product, or a variation with a setup of its own (`variation_id`), gives its sides\' template layers, by layer id and prop, e.g. `{"title": {"content": "farmor"}}`: one template then sells as many products, each in its own theme or for its own recipient. A value on a prop the customer may change is where the designer starts; one on a fixed prop is printed as given. Each side named by its `key` takes its `values`, or null (or an empty object) to take them off; a side left out keeps its own. Pixonaut judges the values against the side\'s template version: a layer or prop the template does not have, or a value its rules refuse, refuses the whole call, and the answer names each by side, layer and prop. So does a side the product does not have, a side without a template, a product without a Pixonaut setup, and a variation that follows its product. A product\'s values reach its variations: those that follow it, and, for a side they have no values of their own for, those with a setup of their own whose side is on the same template as the product\'s. The answer is every side with its `template_id`, the `value_set_id` Pixonaut made of the values it is sold with, its `values`, null where it has none, and `values_from`, whose they are.', 'pixonaut-for-woocommerce' ),
			'input_schema'        => array(
				'type'                 => 'object',
				'properties'           => array(
					'product_id'   => array(
						'type'        => 'integer',
						'description' => __( 'A simple or variable product.', 'pixonaut-for-woocommerce' ),
						'minimum'     => 1,
					),
					'variation_id' => array(
						'type'        => 'integer',
						'description' => __( 'One of the product\'s variations with a setup of its own; left out for the product.', 'pixonaut-for-woocommerce' ),
						'minimum'     => 1,
					),
					'sides'        => array(
						'type'     => 'array',
						'minItems' => 1,
						'maxItems' => PostedSides::MAX_SIDES,
						'items'    => array(
							'type'                 => 'object',
							'properties'           => array(
								'key'    => array(
									'type'        => 'string',
									'description' => __( 'One of the product\'s sides, by its key.', 'pixonaut-for-woocommerce' ),
								),
								'values' => array(
									'type'                 => array( 'object', 'null' ),
									'description'          => __( 'The values by layer id, each an object of props; null to take the side\'s off.', 'pixonaut-for-woocommerce' ),
									'additionalProperties' => array( 'type' => 'object' ),
								),
							),
							'required'             => array( 'key', 'values' ),
							'additionalProperties' => false,
						),
					),
				),
				'required'             => array( 'product_id', 'sides' ),
				'additionalProperties' => false,
			),
			'output_schema'       => self::output_schema(),
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
	 * The answer's schema.
	 *
	 * @return array<string, mixed>
	 */
	public static function output_schema(): array {
		return array(
			'type'       => 'object',
			'properties' => array(
				'product_id'   => array( 'type' => 'integer' ),
				'variation_id' => array( 'type' => array( 'integer', 'null' ) ),
				'sides'        => array(
					'type'  => 'array',
					'items' => array(
						'type'       => 'object',
						'properties' => array(
							'key'         => array( 'type' => 'string' ),
							'template_id' => array(
								'type'        => array( 'string', 'null' ),
								'description' => __( 'The template version the side is on, e.g. `mini-mug@3`; null without a template.', 'pixonaut-for-woocommerce' ),
							),
						) + ProductSetup::side_values_schema(),
					),
				),
			),
		);
	}

	/**
	 * @param mixed $input `product_id`, `variation_id` and `sides`.
	 * @return array<string, mixed>|WP_Error
	 */
	public function execute( mixed $input = null ): array|WP_Error {
		$product = ProductLinks::product( $input );
		if ( ! $product instanceof WC_Product ) {
			return $product;
		}
		$target = $this->target( $product, $input );
		if ( $target instanceof WP_Error ) {
			return $target;
		}
		$config = ProductConfig::stored_on( $target );
		$setup  = ProductConfig::document_on( $target );
		if ( null === $setup || array() === $config->sides ) {
			return new WP_Error( self::REFUSED, __( 'The product has no Pixonaut setup, so it has no sides to give values. Link it to a template first.', 'pixonaut-for-woocommerce' ) );
		}
		$document = SideValues::document_on( $target );
		if ( ! SideValues::writable( $document ) ) {
			return new WP_Error( 'pixonaut_wc_ability_newer_configuration', __( 'The product\'s values come from a newer version of the plugin, so they are not changed. Update the plugin.', 'pixonaut-for-woocommerce' ), array( 'status' => WP_Http::CONFLICT ) );
		}
		$given = $this->given( $config, is_array( $input ) && is_array( $input['sides'] ?? null ) ? $input['sides'] : array() );
		if ( $given instanceof WP_Error ) {
			return $given;
		}
		$sides = $this->made( $config, $given, SideValues::decoded( $document ) );
		if ( $sides instanceof WP_Error ) {
			return $sides;
		}
		$target_id = $target->get_id();
		// The values were judged against the setup read above: one relinked meanwhile, or a variation that follows its product again, takes none of them.
		$setup_stands = static fn (): bool => get_post_meta( $target_id, ProductConfig::META_KEY, true ) === $setup;
		if ( ! SideValues::replace_on( $target, $sides, $document, $setup_stands ) ) {
			return new WP_Error( 'pixonaut_wc_ability_changed_meanwhile', __( 'The product\'s values or its Pixonaut setup were changed while these were saved, so nothing was changed. Read the product again, then set them.', 'pixonaut-for-woocommerce' ), array( 'status' => WP_Http::CONFLICT ) );
		}
		$saved = wc_get_product( $target->get_id() );
		return self::answer( $product, $saved instanceof WC_Product ? $saved : $target );
	}

	/**
	 * What the values are set on: the product, or the variation named when
	 * it is the product's and has a setup of its own.
	 *
	 * @param WC_Product $product The product.
	 * @param mixed      $input   The call's input.
	 */
	private function target( WC_Product $product, mixed $input ): WC_Product|WP_Error {
		if ( ! is_array( $input ) || ! isset( $input['variation_id'] ) ) {
			return $product;
		}
		$variation = wc_get_product( Abilities::integer( $input, 'variation_id', 0 ) );
		if ( ! $variation instanceof WC_Product || ! $variation->is_type( 'variation' ) || $variation->get_parent_id() !== $product->get_id() ) {
			return new WP_Error( 'pixonaut_wc_ability_unknown_variation', __( 'The product has no variation with this id.', 'pixonaut-for-woocommerce' ) );
		}
		if ( ! ProductConfig::stored_on( $variation )->override ) {
			return new WP_Error( self::REFUSED, __( 'The variation follows its product\'s Pixonaut setup, so it is sold with its product\'s values: set them on the product, or give the variation a setup of its own first.', 'pixonaut-for-woocommerce' ) );
		}
		return $variation;
	}

	/**
	 * Each side named with its values (null to take them off), or why the
	 * call is refused: every problem found, so one call says them all.
	 *
	 * @param ProductConfig $config The configuration of what the values are set on.
	 * @param array<mixed>  $rows   The input's `sides`.
	 * @return array<string, array{side: Side, values: array<string, array<string, mixed>>|null}>|WP_Error
	 */
	private function given( ProductConfig $config, array $rows ): array|WP_Error {
		$sides = array();
		foreach ( $config->sides as $side ) {
			$sides[ $side->key ] = $side;
		}
		$given    = array();
		$problems = array();
		foreach ( $rows as $row ) {
			$key = is_array( $row ) && is_string( $row['key'] ?? null ) ? $row['key'] : '';
			if ( ! isset( $sides[ $key ] ) ) {
				/* translators: %s: a side's key. Why an AI assistant's change to the store was refused. */
				$problems[] = sprintf( __( 'the product has no side `%s`', 'pixonaut-for-woocommerce' ), $key );
				continue;
			}
			if ( isset( $given[ $key ] ) ) {
				/* translators: %s: a side's key. Why an AI assistant's change to the store was refused. */
				$problems[] = sprintf( __( 'side `%s` is given twice', 'pixonaut-for-woocommerce' ), $key );
				continue;
			}
			$raw    = is_array( $row ) ? ( $row['values'] ?? null ) : null;
			$values = SideValue::values( $raw );
			if ( null === $values && null !== $raw && array() !== $raw ) {
				/* translators: %s: a side's key. Why an AI assistant's change to the store was refused. */
				$problems[] = sprintf( __( 'the values of side `%s` are not an object of layers, each an object of props', 'pixonaut-for-woocommerce' ), $key );
				continue;
			}
			if ( null !== $values && ( '' === $sides[ $key ]->template_base_id || null === $sides[ $key ]->template_version ) ) {
				/* translators: %s: a side's key. Why an AI assistant's change to the store was refused. */
				$problems[] = sprintf( __( 'side `%s` has no template to give values', 'pixonaut-for-woocommerce' ), $key );
				continue;
			}
			$given[ $key ] = array(
				'side'   => $sides[ $key ],
				'values' => $values,
			);
		}
		return array() === $problems ? $given : self::refused( $problems );
	}

	/**
	 * Every side's values once each given side's set is made: the sides
	 * given in place of what they held, the others as they were.
	 *
	 * @param ProductConfig                                                                      $config The configuration.
	 * @param array<string, array{side: Side, values: array<string, array<string, mixed>>|null}> $given  The sides given.
	 * @param array<string, SideValue>                                                           $stored The values stored now, by side key.
	 * @return array<string, SideValue>|WP_Error
	 */
	private function made( ProductConfig $config, array $given, array $stored ): array|WP_Error {
		$problems = array();
		$sides    = $stored;
		foreach ( $given as $key => $entry ) {
			$side = $entry['side'];
			if ( null === $entry['values'] ) {
				unset( $sides[ $key ] );
				continue;
			}
			$template = (string) new TemplateId( $side->template_base_id, (int) $side->template_version );
			try {
				$sides[ $key ] = new SideValue( $side->template_base_id, $this->value_sets->make( $template, $entry['values'] ), $entry['values'] );
			} catch ( ValueSetRefused $refused ) {
				$problems = array_merge( $problems, self::issues( $key, $refused ) );
			} catch ( ApiException $error ) {
				if ( ErrorCode::TEMPLATE_NOT_FOUND === $error->errorCode ) {
					/* translators: 1: a side's key; 2: a template version, e.g. `mini-mug@3`. Why an AI assistant's change to the store was refused. */
					$problems[] = sprintf( __( 'Pixonaut has no template `%2$s` for side `%1$s`', 'pixonaut-for-woocommerce' ), $key, $template );
					continue;
				}
				if ( WP_Http::NOT_FOUND === $error->status ) {
					// No such route: a Pixonaut from before value sets.
					return new WP_Error( 'pixonaut_wc_ability_values_unsupported', __( 'The Pixonaut this store is connected to does not keep a product\'s own values yet, so nothing was saved. Try again once Pixonaut is updated.', 'pixonaut-for-woocommerce' ), array( 'status' => WP_Http::BAD_GATEWAY ) );
				}
				return new WP_Error( 'pixonaut_wc_ability_values_unchecked', __( 'Pixonaut could not be asked to judge the values, so nothing was saved. Try again.', 'pixonaut-for-woocommerce' ), array( 'status' => WP_Http::BAD_GATEWAY ) );
			}
		}
		// Only the sides the product has now; values a removed side left go.
		$keys = array_flip( array_map( static fn ( Side $side ): string => $side->key, $config->sides ) );
		return array() === $problems ? array_intersect_key( $sides, $keys ) : self::refused( $problems );
	}

	/**
	 * What Pixonaut refused of a side's values, in words.
	 *
	 * @param string          $key     The side's key.
	 * @param ValueSetRefused $refused The refusal.
	 * @return list<string>
	 */
	private static function issues( string $key, ValueSetRefused $refused ): array {
		if ( array() === $refused->issues ) {
			/* translators: %s: a side's key. Why an AI assistant's change to the store was refused. */
			return array( sprintf( __( 'the template of side `%s` does not take its values', 'pixonaut-for-woocommerce' ), $key ) );
		}
		return array_map(
			static fn ( array $issue ): string => '' === $issue['layer_id']
				/* translators: 1: a side's key; 2: why, e.g. `unknown_prop`. Why an AI assistant's change to the store was refused. */
				? sprintf( __( 'the template of side `%1$s` does not take its values (`%2$s`)', 'pixonaut-for-woocommerce' ), $key, $issue['rule'] )
				: ( '' === $issue['prop']
					/* translators: 1: a side's key; 2: a layer's id; 3: why, e.g. `unknown_layer`. Why an AI assistant's change to the store was refused. */
					? sprintf( __( 'side `%1$s`, layer `%2$s`: `%3$s`', 'pixonaut-for-woocommerce' ), $key, $issue['layer_id'], $issue['rule'] )
					/* translators: 1: a side's key; 2: a layer's id; 3: a prop, e.g. `content`; 4: why, e.g. `unknown_prop`. Why an AI assistant's change to the store was refused. */
					: sprintf( __( 'side `%1$s`, layer `%2$s`, prop `%3$s`: `%4$s`', 'pixonaut-for-woocommerce' ), $key, $issue['layer_id'], $issue['prop'], $issue['rule'] ) ),
			$refused->issues
		);
	}

	/**
	 * The refusal saying every problem.
	 *
	 * @param string[] $problems Each problem, in words.
	 * @phpstan-param list<string> $problems
	 */
	private static function refused( array $problems ): WP_Error {
		/* translators: %s: every problem found, joined by semicolons. Why an AI assistant's change to the store was refused. */
		return new WP_Error( self::REFUSED, sprintf( __( 'Nothing was saved: %s.', 'pixonaut-for-woocommerce' ), implode( '; ', array_values( array_unique( $problems ) ) ) ) );
	}

	/**
	 * Every side of what the values were set on, with its values now.
	 *
	 * @param WC_Product $product The product.
	 * @param WC_Product $target  The product, or its variation the values were set on.
	 * @return array<string, mixed>
	 */
	private static function answer( WC_Product $product, WC_Product $target ): array {
		return array(
			'product_id'   => $product->get_id(),
			'variation_id' => $target->get_id() === $product->get_id() ? null : $target->get_id(),
			'sides'        => array_map(
				static fn ( Side $side ): array => array(
					'key'         => $side->key,
					'template_id' => SetupFields::side_of( $side )['template_id'],
				) + ProductSetup::side_values( $target, $side ),
				ProductConfig::stored_on( $target )->sides
			),
		);
	}
}
