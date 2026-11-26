<?php
/**
 * The ability that links a global attribute to a blank option.
 *
 * @package Pixonaut\WooCommerce
 */

declare(strict_types=1);

namespace Pixonaut\WooCommerce\Abilities;

use Pixonaut\WooCommerce\Catalogue\BlankOptionChoices;
use Pixonaut\WooCommerce\Catalogue\BlankOptionLinks;
use WP_Error;
use WP_Http;
use WP_Term;

/**
 * Links a global attribute to one of the options of the blanks its
 * products use, and its values to the option's values, as its edit
 * screen's "Blank option" does; or unlinks it.
 *
 * @phpstan-import-type Row from BlankOptionRows
 */
final class SetAttributeBlankOption implements Ability {

	/**
	 * @param BlankOptionChoices $choices The options an attribute can stand for.
	 */
	public function __construct( private readonly BlankOptionChoices $choices ) {
	}

	public function name(): string {
		return 'set-attribute-blank-option';
	}

	public function definition(): array {
		return array(
			/* translators: Name of an ability an AI assistant or an MCP client can use on the store. */
			'label'               => __( 'Link an attribute to a blank option', 'pixonaut-for-woocommerce' ),
			'description'         => __( 'Links a global attribute to an option of the Pixonaut blanks its products use, and its values to the option\'s values, as its edit screen\'s "Blank option" does: a frame colour attribute to the blank\'s Color, its value Svart to Black. Its values then pick the blank\'s variant and show in the designer as the option values\' colour or picture swatches. `option` and each value are named as `get-attribute-blank-options` lists them in `options`. A value given an empty `value`, or not linked yet, stands for the option\'s value of its name; values not given keep their link while the option stays the same, and lose it when it changes. `option` @pages links it to a book\'s page count instead: each value then stands for a count of inner pages, the number given as its `value`, else the first number in its name, and a variation sells its book at that count, priced by the printshop\'s cost at it. An empty `option` unlinks the attribute. Products with the attribute are re-checked in the background. The answer is the attribute\'s row; a `503` when the re-check could not be queued, the link being saved.', 'pixonaut-for-woocommerce' ),
			'input_schema'        => array(
				'type'                 => 'object',
				'properties'           => array(
					'attribute_id' => array(
						'type'        => 'integer',
						'minimum'     => 1,
						'description' => __( 'A global attribute, by id.', 'pixonaut-for-woocommerce' ),
					),
					'option'       => array(
						'type'      => 'string',
						'maxLength' => BlankOptionLinks::MAX_LENGTH,
					),
					'values'       => array(
						'type'  => 'array',
						'items' => array(
							'type'                 => 'object',
							'properties'           => array(
								'term_id' => array(
									'type'        => 'integer',
									'minimum'     => 1,
									'description' => __( 'One of the attribute\'s values, by term id; or name it by `slug`.', 'pixonaut-for-woocommerce' ),
								),
								'slug'    => array( 'type' => 'string' ),
								'value'   => array(
									'type'      => 'string',
									'maxLength' => BlankOptionLinks::MAX_LENGTH,
								),
							),
							'required'             => array( 'value' ),
							'additionalProperties' => false,
						),
					),
				),
				'required'             => array( 'attribute_id', 'option' ),
				'additionalProperties' => false,
			),
			'output_schema'       => BlankOptionRows::row_schema(),
			'execute_callback'    => array( $this, 'execute' ),
			'permission_callback' => array( AttributeQueryNames::class, 'can_manage' ),
			'annotations'         => array(
				'readonly'    => false,
				// Products with the attribute may pick another variant, or none.
				'destructive' => true,
				'idempotent'  => true,
			),
		);
	}

	/**
	 * @param mixed $input `attribute_id`, `option` and `values`.
	 * @return Row|WP_Error
	 */
	public function execute( mixed $input = null ): array|WP_Error {
		$attribute_id = Abilities::integer( $input, 'attribute_id', 0 );
		$attribute    = $attribute_id > 0 ? wc_get_attribute( $attribute_id ) : null;
		if ( null === $attribute ) {
			return new WP_Error( 'pixonaut_wc_ability_unknown_attribute', __( 'No global attribute has this id.', 'pixonaut-for-woocommerce' ), array( 'status' => WP_Http::NOT_FOUND ) );
		}
		$taxonomy = wc_attribute_taxonomy_name_by_id( $attribute_id );
		$option   = is_array( $input ) && is_string( $input['option'] ?? null ) ? trim( $input['option'] ) : '';
		$choices  = $this->choices->for_taxonomy( $taxonomy );
		if ( '' === $option ) {
			return self::answer( BlankOptionLinks::set( $attribute_id, '', array() ), $attribute_id, (string) $attribute->name, $choices );
		}
		if ( ! isset( $choices[ $option ] ) ) {
			return self::refused(
				/* translators: 1: the blank option asked for. 2: the options there are, comma-separated. Error an AI assistant or an MCP client reads. */
				sprintf( __( 'No blank the products with this attribute use has the option "%1$s". Options: %2$s.', 'pixonaut-for-woocommerce' ), $option, self::listed( array_keys( $choices ) ) )
			);
		}
		$current = BlankOptionLinks::of( $attribute_id );
		$values  = null !== $current && $current['option'] === $option ? $current['values'] : array();
		foreach ( is_array( $input['values'] ?? null ) ? $input['values'] : array() as $given ) {
			$term = self::term_of( $taxonomy, $given );
			if ( null === $term ) {
				return self::refused( __( 'A value given is not one of the attribute\'s values: name each by its `term_id` or `slug`.', 'pixonaut-for-woocommerce' ) );
			}
			$value = is_array( $given ) && is_string( $given['value'] ?? null ) ? trim( $given['value'] ) : '';
			if ( BlankOptionLinks::PAGE_COUNT === $option && '' !== $value && null === BlankOptionLinks::count_in( $value ) ) {
				return self::refused(
					/* translators: %s: the value asked for. Error an AI assistant or an MCP client reads. */
					sprintf( __( '"%s" is not a page count: give a whole number of inner pages, such as 44.', 'pixonaut-for-woocommerce' ), $value )
				);
			}
			if ( BlankOptionLinks::PAGE_COUNT !== $option && '' !== $value && ! in_array( $value, $choices[ $option ], true ) ) {
				return self::refused(
					/* translators: 1: the option value asked for. 2: the blank option. 3: its values, comma-separated. Error an AI assistant or an MCP client reads. */
					sprintf( __( '"%1$s" is not a value of the option "%2$s". Values: %3$s.', 'pixonaut-for-woocommerce' ), $value, $option, self::listed( $choices[ $option ] ) )
				);
			}
			$values[ $term->term_id ] = $value;
		}
		$before = BlankOptionLinks::of( $attribute_id );
		$queued = BlankOptionLinks::set( $attribute_id, $option, $values );
		// The same link given again queues the re-check anyway: it is how a
		// caller recovers from a link saved whose re-check was not queued.
		if ( $queued && BlankOptionLinks::of( $attribute_id ) === $before ) {
			$queued = BlankOptionLinks::recheck( $attribute_id );
		}
		return self::answer( $queued, $attribute_id, (string) $attribute->name, $choices );
	}

	/**
	 * The attribute's row once its link is saved; an error when its
	 * products could not be queued for a re-check, which the link alone
	 * leaves on the variants they had.
	 *
	 * @param bool                        $queued       Whether `BlankOptionLinks::set()` queued the re-check.
	 * @param int                         $attribute_id The attribute.
	 * @param string                      $name         Its name.
	 * @param array<string, list<string>> $choices      The options it can stand for.
	 * @return Row|WP_Error
	 */
	private static function answer( bool $queued, int $attribute_id, string $name, array $choices ): array|WP_Error {
		if ( ! $queued ) {
			return new WP_Error(
				'pixonaut_wc_ability_recheck_not_queued',
				/* translators: Error an AI assistant or an MCP client reads. `recheck-products` is the name of another ability; keep it as it is. */
				__( 'The link is saved, but the products with this attribute could not be queued for a re-check, so they keep the blank variants they had. Run `recheck-products` to re-check them.', 'pixonaut-for-woocommerce' ),
				array( 'status' => WP_Http::SERVICE_UNAVAILABLE )
			);
		}
		return BlankOptionRows::row( $attribute_id, $name, $choices );
	}

	/**
	 * The attribute's term a given value names; null for none.
	 *
	 * @param string $taxonomy The attribute's taxonomy.
	 * @param mixed  $given    One of `values`.
	 */
	private static function term_of( string $taxonomy, mixed $given ): ?WP_Term {
		if ( ! is_array( $given ) ) {
			return null;
		}
		$term_id = Abilities::integer( $given, 'term_id', 0 );
		if ( $term_id > 0 ) {
			$term = get_term( $term_id, $taxonomy );
		} else {
			$term = is_string( $given['slug'] ?? null ) && '' !== $given['slug'] ? get_term_by( 'slug', $given['slug'], $taxonomy ) : null;
		}
		return $term instanceof WP_Term ? $term : null;
	}

	/**
	 * @param string[] $names Names.
	 * @phpstan-param list<string> $names
	 */
	private static function listed( array $names ): string {
		return array() === $names ? '—' : wp_sprintf( '%l', $names );
	}

	/**
	 * @param string $message Why the link was refused.
	 */
	private static function refused( string $message ): WP_Error {
		return new WP_Error( 'pixonaut_wc_ability_invalid_blank_option', $message, array( 'status' => WP_Http::BAD_REQUEST ) );
	}
}
