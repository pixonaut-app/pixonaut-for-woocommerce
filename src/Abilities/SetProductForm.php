<?php
/**
 * The ability that arranges a product's designer form.
 *
 * @package Pixonaut\WooCommerce
 */

declare(strict_types=1);

namespace Pixonaut\WooCommerce\Abilities;

use LogicException;
use Pixonaut\WooCommerce\Catalogue\FormGroups;
use Pixonaut\WooCommerce\Catalogue\PostedSides;
use Pixonaut\WooCommerce\Catalogue\ProductConfig;
use Pixonaut\WooCommerce\Catalogue\Side;
use Pixonaut\WooCommerce\Catalogue\VariationAttributes;
use Pixonaut\WooCommerce\FormLayout;
use Pixonaut\WooCommerce\GroupNumbers;
use Pixonaut\WooCommerce\Layout;
use Pixonaut\WooCommerce\OptionGroups;
use WC_Product;
use WP_Error;
use WP_Http;

/**
 * Sets what the Pixonaut tab arranges of a simple or variable product's
 * designer form, and nothing else: the groups of the sides named, where
 * the product's options stand, whether the groups are numbered, and the
 * layouts. The sides themselves, their templates, placements and print
 * sizes, and the variations' own setups stay as they are, and Pixonaut is
 * not asked to check the product again. An arrangement is checked against
 * the product and its template before anything is saved: one naming an
 * option the product does not have, or a field or group the template does
 * not offer, refuses the whole call and says each. Whoever may edit the
 * product may set it.
 */
final class SetProductForm implements Ability {

	/**
	 * The choices a call may set; `''` takes one back to the store's.
	 */
	private const CHOICES = array( 'layout', 'form_layout', 'option_groups', 'group_numbers' );

	/**
	 * @param GetProductForm $read Reads the template's groups, and answers what the product has now.
	 */
	public function __construct( private readonly GetProductForm $read ) {
	}

	public function name(): string {
		return 'set-product-form';
	}

	public function definition(): array {
		$choice = static fn ( array $available, string $what ): array => array(
			'type'        => 'string',
			'description' => $what,
			'enum'        => array_merge( array( '' ), $available ),
		);
		return array(
			/* translators: Name of an ability an AI assistant or an MCP client can use on the store. */
			'label'               => __( 'Arrange a product\'s designer form', 'pixonaut-for-woocommerce' ),
			'description'         => __( 'Arranges a simple or variable product\'s designer form, as its Pixonaut tab does: the `form_groups` of each side named by its `key` (null for the template\'s own form), `option_groups`, `group_numbers`, `form_layout` and `layout`. Only what is given changes: a side left out keeps its arrangement, and the sides, their templates, placements and print sizes, and the variations\' own setups are kept. Read what an arrangement may name with `get-product-form`: an option the product does not have, or a field or group its template does not offer, refuses the whole call and the answer says each. The answer is the product\'s form now, as `get-product-form` gives it.', 'pixonaut-for-woocommerce' ),
			'input_schema'        => array(
				'type'                 => 'object',
				'properties'           => array(
					'product_id'    => array(
						'type'        => 'integer',
						'description' => __( 'A simple or variable product.', 'pixonaut-for-woocommerce' ),
						'minimum'     => 1,
					),
					'sides'         => array(
						'type'     => 'array',
						'maxItems' => PostedSides::MAX_SIDES,
						'items'    => array(
							'type'                 => 'object',
							'properties'           => array(
								'key'         => array(
									'type'        => 'string',
									'description' => __( 'One of the product\'s sides, by its key.', 'pixonaut-for-woocommerce' ),
								),
								'form_groups' => GetProductForm::form_groups_schema(),
							),
							'required'             => array( 'key', 'form_groups' ),
							'additionalProperties' => false,
						),
					),
					'layout'        => $choice( Layout::available(), __( 'The product page layout; empty for the store\'s.', 'pixonaut-for-woocommerce' ) ),
					'form_layout'   => $choice( FormLayout::available(), __( 'The form layout; empty for the store\'s.', 'pixonaut-for-woocommerce' ) ),
					'option_groups' => $choice( OptionGroups::available(), __( 'Where the product\'s options stand on a side not arranged by hand: `on_top`, unnumbered, or each in a group of its own, `first` or `last`; empty for the store\'s.', 'pixonaut-for-woocommerce' ) ),
					'group_numbers' => $choice( GroupNumbers::available(), __( 'Whether the form numbers its groups; empty for the store\'s.', 'pixonaut-for-woocommerce' ) ),
				),
				'required'             => array( 'product_id' ),
				'additionalProperties' => false,
			),
			'output_schema'       => GetProductForm::output_schema(),
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
	 * @param mixed $input `product_id`, and what to set.
	 * @return array<string, mixed>|WP_Error
	 */
	public function execute( mixed $input = null ): array|WP_Error {
		$product = ProductLinks::product( $input );
		if ( ! $product instanceof WC_Product ) {
			return $product;
		}
		$input = (array) $input;
		if ( ! is_array( $input['sides'] ?? null ) && array() === array_intersect_key( $input, array_flip( self::CHOICES ) ) ) {
			return new WP_Error( 'pixonaut_wc_ability_nothing_to_set', __( 'Give `sides`, `option_groups`, `group_numbers`, `form_layout` or `layout`.', 'pixonaut-for-woocommerce' ) );
		}
		if ( ! ProductConfig::writable_on( $product ) ) {
			return self::newer_configuration();
		}
		$document = ProductConfig::document_on( $product );
		$config   = ProductConfig::stored_on( $product );
		$sides    = $this->arranged( $product, $config, is_array( $input['sides'] ?? null ) ? $input['sides'] : array() );
		if ( $sides instanceof WP_Error ) {
			return $sides;
		}
		$changed = self::with_choices( $config->with_sides( $sides ), $input );
		try {
			if ( null === $document ) {
				$changed->save_to( $product );
			} elseif ( ! $changed->replace_on( $product, $document ) ) {
				return new WP_Error( 'pixonaut_wc_ability_changed_meanwhile', __( 'The product\'s Pixonaut setup was changed while this was saved, so nothing was changed. Read it again with `get-product-form`, then set it.', 'pixonaut-for-woocommerce' ), array( 'status' => WP_Http::CONFLICT ) );
			}
		} catch ( LogicException $e ) {
			return self::newer_configuration();
		}
		$saved = wc_get_product( $product->get_id() );
		return $this->read->answer( $saved instanceof WC_Product ? $saved : $product );
	}

	/**
	 * The product's sides with the arrangements given, or why the call is
	 * refused: every problem found, so one call says them all.
	 *
	 * @param WC_Product    $product The product.
	 * @param ProductConfig $config  Its configuration.
	 * @param array<mixed>  $rows    The input's `sides`.
	 * @return list<Side>|WP_Error
	 */
	private function arranged( WC_Product $product, ProductConfig $config, array $rows ): array|WP_Error {
		$sides    = $config->sides;
		$keys     = array_flip( array_map( static fn ( Side $side ): string => $side->key, $sides ) );
		$options  = array_column( VariationAttributes::of( $product ), 'slug' );
		$problems = array();
		$seen     = array();
		foreach ( $rows as $row ) {
			$key = is_array( $row ) && is_string( $row['key'] ?? null ) ? $row['key'] : '';
			if ( ! isset( $keys[ $key ] ) ) {
				/* translators: %s: a side's key. Why an AI assistant's change to the store was refused. */
				$problems[] = sprintf( __( 'the product has no side `%s`', 'pixonaut-for-woocommerce' ), $key );
				continue;
			}
			if ( isset( $seen[ $key ] ) ) {
				/* translators: %s: a side's key. Why an AI assistant's change to the store was refused. */
				$problems[] = sprintf( __( 'side `%s` is given twice', 'pixonaut-for-woocommerce' ), $key );
				continue;
			}
			$seen[ $key ] = true;
			$given        = is_array( $row ) ? ( $row['form_groups'] ?? null ) : null;
			$side         = $sides[ $keys[ $key ] ];
			$checked      = $this->checked( $side, $given, $options );
			if ( $checked instanceof WP_Error ) {
				return $checked;
			}
			$problems = array_merge( $problems, $checked );
			if ( array() === $checked ) {
				$sides[ $keys[ $key ] ] = $side->with_form_groups( FormGroups::from_array( $given ) );
			}
		}
		if ( array() !== $problems ) {
			/* translators: %s: every problem found, joined by semicolons. Why an AI assistant's change to the store was refused. */
			return new WP_Error( 'pixonaut_wc_ability_form_not_offered', sprintf( __( 'Nothing was saved: %s.', 'pixonaut-for-woocommerce' ), implode( '; ', $problems ) ) );
		}
		return array_values( $sides );
	}

	/**
	 * What one side's arrangement names that the product or its template
	 * does not have, in words; or the refusal when the template could not be
	 * read to check it.
	 *
	 * @param Side     $side    The side.
	 * @param mixed    $given   Its `form_groups`.
	 * @param string[] $options The product's options, by attribute slug.
	 * @phpstan-param list<string> $options
	 * @return list<string>|WP_Error
	 */
	private function checked( Side $side, mixed $given, array $options ): array|WP_Error {
		$groups = is_array( $given ) && is_array( $given['groups'] ?? null ) ? $given['groups'] : array();
		if ( array() === $groups ) {
			return array();
		}
		$template = $this->read->template_groups( $side );
		if ( null === $template ) {
			return null === GetProductForm::template_of( $side )
				/* translators: %s: a side's key. Why an AI assistant's change to the store was refused. */
				? array( sprintf( __( 'side `%s` has no template to arrange', 'pixonaut-for-woocommerce' ), $side->key ) )
				: new WP_Error( 'pixonaut_wc_ability_template_unread', __( 'Pixonaut could not be asked for the template\'s fields, so the arrangement was not checked and nothing was saved. Try again.', 'pixonaut-for-woocommerce' ), array( 'status' => WP_Http::BAD_GATEWAY ) );
		}
		$group_ids = array_column( $template, 'id' );
		$fields    = array_column( array_merge( array(), ...array_column( $template, 'fields' ) ), 'field' );
		$problems  = array();
		foreach ( $groups as $group ) {
			$id    = is_array( $group ) && is_string( $group['id'] ?? null ) ? $group['id'] : '';
			$title = is_array( $group ) && is_string( $group['title'] ?? null ) ? trim( $group['title'] ) : '';
			if ( FormGroups::is_own( $id ) ? '' === $title : ! in_array( $id, $group_ids, true ) ) {
				$problems[] = FormGroups::is_own( $id )
					/* translators: 1: a group's id; 2: a side's key. Why an AI assistant's change to the store was refused. */
					? sprintf( __( 'group `%1$s` on side `%2$s` is the product\'s own and needs a title', 'pixonaut-for-woocommerce' ), $id, $side->key )
					/* translators: 1: a group's id; 2: a side's key; 3: the prefix of a group of the product's own. Why an AI assistant's change to the store was refused. */
					: sprintf( __( 'the template of side `%2$s` has no group `%1$s` (a group of the product\'s own takes an id starting with `%3$s`)', 'pixonaut-for-woocommerce' ), $id, $side->key, FormGroups::OWN_GROUP_PREFIX );
			}
			foreach ( is_array( $group ) && is_array( $group['items'] ?? null ) ? $group['items'] : array() as $item ) {
				$attribute = is_array( $item ) && is_string( $item['attribute'] ?? null ) ? $item['attribute'] : null;
				$field     = is_array( $item ) && is_string( $item['field'] ?? null ) ? $item['field'] : null;
				if ( null !== $attribute && ! in_array( sanitize_title( $attribute ), $options, true ) ) {
					/* translators: %s: an attribute's slug. Why an AI assistant's change to the store was refused. */
					$problems[] = sprintf( __( 'the product has no option `%s` its customers choose a variation by', 'pixonaut-for-woocommerce' ), $attribute );
				} elseif ( null === $attribute && null !== $field && ! in_array( $field, $fields, true ) ) {
					/* translators: 1: a field, `<layerId>:<prop>`; 2: a side's key. Why an AI assistant's change to the store was refused. */
					$problems[] = sprintf( __( 'the template of side `%2$s` offers no field `%1$s`', 'pixonaut-for-woocommerce' ), $field, $side->key );
				} elseif ( null === $attribute && null === $field ) {
					/* translators: %s: a side's key. Why an AI assistant's change to the store was refused. */
					$problems[] = sprintf( __( 'an item on side `%s` names neither a `field` nor an `attribute`', 'pixonaut-for-woocommerce' ), $side->key );
				}
			}
		}
		return array_values( array_unique( $problems ) );
	}

	/**
	 * The configuration with the choices given; `''` takes one back to the store's.
	 *
	 * @param ProductConfig $config The configuration.
	 * @param array<mixed>  $input  The input.
	 */
	private static function with_choices( ProductConfig $config, array $input ): ProductConfig {
		$value = static fn ( string $key, ?string $current ): ?string => is_string( $input[ $key ] ?? null ) ? ( '' === $input[ $key ] ? null : $input[ $key ] ) : $current;
		return $config
			->with_layout( $value( 'layout', $config->layout ) )
			->with_form_layout( $value( 'form_layout', $config->form_layout ) )
			->with_option_groups( $value( 'option_groups', $config->option_groups ) )
			->with_group_numbers( $value( 'group_numbers', $config->group_numbers ) );
	}

	/**
	 * The refusal when the product's configuration is a newer plugin version's.
	 */
	private static function newer_configuration(): WP_Error {
		return new WP_Error( 'pixonaut_wc_ability_newer_configuration', __( 'The product\'s Pixonaut configuration comes from a newer version of the plugin, so it is not changed. Update the plugin.', 'pixonaut-for-woocommerce' ), array( 'status' => WP_Http::CONFLICT ) );
	}
}
