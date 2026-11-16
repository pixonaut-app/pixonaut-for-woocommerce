<?php
/**
 * The ability that reads how a product's designer form is arranged.
 *
 * @package Pixonaut\WooCommerce
 */

declare(strict_types=1);

namespace Pixonaut\WooCommerce\Abilities;

use Pixonaut\WooCommerce\Admin\CatalogueSearch;
use Pixonaut\WooCommerce\Catalogue\ProductConfig;
use Pixonaut\WooCommerce\Catalogue\Side;
use Pixonaut\WooCommerce\Catalogue\VariationAttributes;
use Pixonaut\WooCommerce\Vendor\Pixonaut\Sdk\Exception\ApiException;
use WC_Product;
use WP_Error;

/**
 * Reads what the Pixonaut tab arranges of a simple or variable product's
 * designer form: each side's groups (`form_groups`, null when the side shows
 * the template's own form), where the product's options stand and whether
 * the groups are numbered, the layouts, and what an arrangement may name:
 * the attributes customers choose a variation by, and each side's
 * template's groups and fields. `set-product-form` takes the same.
 */
final class GetProductForm implements Ability {

	/**
	 * @param CatalogueSearch $search Reads a template version's groups and fields.
	 */
	public function __construct( private readonly CatalogueSearch $search ) {
	}

	/**
	 * Whether Pixonaut failed a template read in this request: each later
	 * read then answers null at once, so a product of several sides never
	 * waits out the read's timeout more than once.
	 *
	 * @var bool
	 */
	private bool $unreachable = false;

	public function name(): string {
		return 'get-product-form';
	}

	public function definition(): array {
		return array(
			/* translators: Name of an ability an AI assistant or an MCP client can use on the store. */
			'label'               => __( 'Read how a product\'s designer form is arranged', 'pixonaut-for-woocommerce' ),
			'description'         => __( 'Reads how a simple or variable product\'s designer form is arranged, as its Pixonaut tab shows it: each side\'s `form_groups` (the groups in order, each with its items: a template field as `field` `"<layerId>:<prop>"` with the words that replace the template\'s, or one of the product\'s options as `attribute` by its slug; null when the side shows the template\'s own form), `option_groups`, `group_numbers`, `form_layout` and `layout` (null for the store\'s). With them, what an arrangement may name: the product\'s `attributes`, and each side\'s `template_groups`, its template\'s groups and fields (null when Pixonaut could not be asked). Set it with `set-product-form`.', 'pixonaut-for-woocommerce' ),
			'input_schema'        => array(
				'type'                 => 'object',
				'properties'           => array(
					'product_id' => array(
						'type'        => 'integer',
						'description' => __( 'A simple or variable product.', 'pixonaut-for-woocommerce' ),
						'minimum'     => 1,
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
		return $product instanceof WC_Product ? $this->answer( $product ) : $product;
	}

	/**
	 * What the product's form is now, as `output_schema()` says.
	 *
	 * @param WC_Product $product A simple or variable product.
	 * @return array<string, mixed>
	 */
	public function answer( WC_Product $product ): array {
		$config = ProductConfig::stored_on( $product );
		return array(
			'product_id'    => $product->get_id(),
			'personalised'  => $config->personalise,
			'blank_id'      => $config->blank_id,
			'layout'        => $config->layout,
			'form_layout'   => $config->form_layout,
			'option_groups' => $config->option_groups,
			'group_numbers' => $config->group_numbers,
			'attributes'    => VariationAttributes::of( $product ),
			'sides'         => array_map(
				fn ( Side $side ): array => self::side( $side ) + array( 'template_groups' => $this->template_groups( $side ) ),
				$config->sides
			),
		);
	}

	/**
	 * The template version a side is on, as Pixonaut names it; null without one.
	 *
	 * @param Side $side The side.
	 */
	public static function template_of( Side $side ): ?string {
		$template = SetupFields::side_of( $side )['template_id'];
		return is_string( $template ) ? $template : null;
	}

	/**
	 * The groups and fields of a side's template, each field by the name an
	 * arrangement gives it; null without a template or when Pixonaut could
	 * not be asked.
	 *
	 * @param Side $side The side.
	 * @return list<array{id: string, label: string, fields: list<array{field: string, label: string}>}>|null
	 */
	public function template_groups( Side $side ): ?array {
		$template = self::template_of( $side );
		if ( null === $template || $this->unreachable ) {
			return null;
		}
		try {
			$groups = $this->search->template_groups_of( $template );
		} catch ( ApiException $e ) {
			$this->unreachable = true;
			return null;
		}
		$answer = array();
		foreach ( $groups as $group ) {
			$fields = array();
			foreach ( is_array( $group['fields'] ?? null ) ? $group['fields'] : array() as $field ) {
				if ( is_array( $field ) && is_string( $field['field'] ?? null ) ) {
					$fields[] = array(
						'field' => $field['field'],
						'label' => is_string( $field['label'] ?? null ) ? $field['label'] : '',
					);
				}
			}
			$answer[] = array(
				'id'     => is_string( $group['id'] ?? null ) ? $group['id'] : '',
				'label'  => is_string( $group['label'] ?? null ) ? $group['label'] : '',
				'fields' => $fields,
			);
		}
		return $answer;
	}

	/**
	 * A side as the answer gives it, without its template's groups.
	 *
	 * @param Side $side The side.
	 * @return array<string, mixed>
	 */
	private static function side( Side $side ): array {
		$given = SetupFields::side_of( $side );
		return array(
			'key'             => $side->key,
			'label'           => $side->label,
			'mode'            => $side->mode,
			'template_id'     => $given['template_id'],
			'template_pinned' => $side->pinned,
			'placement_id'    => $side->placement_id,
			'form_groups'     => $given['form_groups'],
		);
	}

	/**
	 * The schema of one side's `form_groups`, as the answer gives it and
	 * `set-product-form` takes it.
	 *
	 * @return array<string, mixed>
	 */
	public static function form_groups_schema(): array {
		$words = array( 'type' => 'string' );
		return array(
			'type'        => array( 'object', 'null' ),
			'description' => __( 'The side\'s groups, in order; null for the template\'s own form.', 'pixonaut-for-woocommerce' ),
			'required'    => array( 'groups' ),
			'properties'  => array(
				'groups' => array(
					'type'  => 'array',
					'items' => array(
						'type'       => 'object',
						'required'   => array( 'id', 'items' ),
						'properties' => array(
							'id'    => array(
								'type'        => 'string',
								'description' => __( 'One of the template\'s groups, by id; or a group of the product\'s own, whose id starts with `wc-` and which takes a `title`.', 'pixonaut-for-woocommerce' ),
							),
							'title' => array(
								'type'        => 'string',
								'description' => __( 'The group\'s title, in place of the template\'s.', 'pixonaut-for-woocommerce' ),
							),
							'items' => array(
								'type'  => 'array',
								'items' => array(
									'type'       => 'object',
									'properties' => array(
										'field'       => array(
											'type'        => 'string',
											'description' => __( 'One of the template\'s fields, as `"<layerId>:<prop>"`.', 'pixonaut-for-woocommerce' ),
										),
										'attribute'   => array(
											'type'        => 'string',
											'description' => __( 'One of the product\'s options, by its attribute\'s slug, in place of a field.', 'pixonaut-for-woocommerce' ),
										),
										'label'       => $words,
										'help'        => $words,
										'placeholder' => $words,
										'options'     => array(
											'type'        => 'object',
											'description' => __( 'Words in place of the field\'s offered values, by value.', 'pixonaut-for-woocommerce' ),
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

	/**
	 * The schema of `answer()`.
	 *
	 * @return array<string, mixed>
	 */
	public static function output_schema(): array {
		$choice = static fn ( string $what ): array => array(
			'type'        => array( 'string', 'null' ),
			'description' => $what,
		);
		return array(
			'type'       => 'object',
			'properties' => array(
				'product_id'    => array( 'type' => 'integer' ),
				'personalised'  => array( 'type' => 'boolean' ),
				'blank_id'      => array( 'type' => array( 'string', 'null' ) ),
				'layout'        => $choice( __( 'The product page layout; null for the store\'s.', 'pixonaut-for-woocommerce' ) ),
				'form_layout'   => $choice( __( 'The form layout; null for the store\'s.', 'pixonaut-for-woocommerce' ) ),
				'option_groups' => $choice( __( 'Where the product\'s options stand on a side not arranged by hand: `on_top`, `first` or `last`; null for the store\'s.', 'pixonaut-for-woocommerce' ) ),
				'group_numbers' => $choice( __( 'Whether the form numbers its groups: `on` or `off`; null for the store\'s.', 'pixonaut-for-woocommerce' ) ),
				'attributes'    => array(
					'type'        => 'array',
					'description' => __( 'The options customers choose a variation by, as an arrangement names them: each attribute\'s slug and name. Empty for a simple product.', 'pixonaut-for-woocommerce' ),
					'items'       => array(
						'type'       => 'object',
						'properties' => array(
							'slug'  => array( 'type' => 'string' ),
							'label' => array( 'type' => 'string' ),
						),
					),
				),
				'sides'         => array(
					'type'  => 'array',
					'items' => array(
						'type'       => 'object',
						'properties' => array(
							'key'             => array( 'type' => 'string' ),
							'label'           => array( 'type' => 'string' ),
							'mode'            => array(
								'type' => 'string',
								'enum' => Side::MODES,
							),
							'template_id'     => array( 'type' => array( 'string', 'null' ) ),
							'template_pinned' => array( 'type' => 'boolean' ),
							'placement_id'    => array( 'type' => array( 'string', 'null' ) ),
							'form_groups'     => self::form_groups_schema(),
							'template_groups' => array(
								'type'        => array( 'array', 'null' ),
								'description' => __( 'The template\'s groups in its order, each with its fields; null without a template or when Pixonaut could not be asked.', 'pixonaut-for-woocommerce' ),
								'items'       => array(
									'type'       => 'object',
									'properties' => array(
										'id'     => array( 'type' => 'string' ),
										'label'  => array( 'type' => 'string' ),
										'fields' => array(
											'type'  => 'array',
											'items' => array(
												'type' => 'object',
												'properties' => array(
													'field' => array( 'type' => 'string' ),
													'label' => array( 'type' => 'string' ),
												),
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
