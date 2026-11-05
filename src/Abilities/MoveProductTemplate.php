<?php
/**
 * The ability that moves a product and its variations to another template version.
 *
 * @package Pixonaut\WooCommerce
 */

declare(strict_types=1);

namespace Pixonaut\WooCommerce\Abilities;

use Pixonaut\WooCommerce\Catalogue\ProductConfig;
use Pixonaut\WooCommerce\Catalogue\TemplateId;
use WC_Product;
use WP_Error;

/**
 * Moves every side of a personalised product, and of each of its
 * personalised variations with a setup of its own, from one template to another version of it, or to
 * another template, keeping everything else the side has: its key, label,
 * placement, print sizes, start size, form arrangement and mode. Each is
 * saved as its product screen saves it, so Pixonaut checks it again.
 * Whoever may edit the product may move it.
 */
final class MoveProductTemplate implements Ability {

	/**
	 * @param ProductLinks $links  Saves each setup.
	 * @param SetupFields  $fields Turns the moved sides into the product screen's fields.
	 */
	public function __construct( private readonly ProductLinks $links, private readonly SetupFields $fields ) {
	}

	public function name(): string {
		return 'move-product-template';
	}

	public function definition(): array {
		$answer                                    = ProductLinks::output_schema();
		$answer['properties']['variations']        = array(
			'type'        => 'array',
			'description' => __( 'Each variation with a setup of its own that was moved, as saved.', 'pixonaut-for-woocommerce' ),
			'items'       => ProductLinks::output_schema(),
		);
		$answer['properties']['moved_sides_total'] = array(
			'type'        => 'integer',
			'description' => __( 'How many sides moved, on the product and its variations.', 'pixonaut-for-woocommerce' ),
		);
		return array(
			/* translators: Name of an ability an AI assistant or an MCP client can use on the store. */
			'label'               => __( 'Move a product to another template version', 'pixonaut-for-woocommerce' ),
			'description'         => __( 'Moves every side of a personalised simple or variable product on a template, and of each of its personalised variations with a setup of its own, to another version of the template or to another template, keeping each side\'s placement, print sizes, start size, form arrangement and mode. Pixonaut checks each again; one that fails a check is saved but cannot be bought, and the answer says why.', 'pixonaut-for-woocommerce' ),
			'input_schema'        => array(
				'type'                 => 'object',
				'properties'           => array(
					'product_id'     => array(
						'type'    => 'integer',
						'minimum' => 1,
					),
					'template_id'    => array(
						'type'        => 'string',
						'description' => __( 'The template the sides are on now, by its id with or without the version, e.g. `mini-tee` or `mini-tee@2`; any version matches.', 'pixonaut-for-woocommerce' ),
					),
					'to_template_id' => array(
						'type'        => 'string',
						'description' => __( 'The template version they move to, e.g. `mini-tee@3`, which they then stay on, or a template\'s id alone for its live version, after which they follow its latest version.', 'pixonaut-for-woocommerce' ),
					),
				),
				'required'             => array( 'product_id', 'template_id', 'to_template_id' ),
				'additionalProperties' => false,
			),
			'output_schema'       => $answer,
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
	 * @param mixed $input `product_id`, `template_id` and `to_template_id`.
	 * @return array<string, mixed>|WP_Error
	 */
	public function execute( mixed $input = null ): array|WP_Error {
		$product = ProductLinks::product( $input );
		if ( ! $product instanceof WC_Product ) {
			return $product;
		}
		$input = (array) $input;
		$from  = is_string( $input['template_id'] ?? null ) ? trim( $input['template_id'] ) : '';
		$from  = TemplateId::parse( $from )->base_id ?? $from;
		$to    = is_string( $input['to_template_id'] ?? null ) ? trim( $input['to_template_id'] ) : '';
		if ( '' === $from || '' === $to ) {
			return new WP_Error( 'pixonaut_wc_ability_unknown_template', __( 'Name the template the sides are on and the one they move to.', 'pixonaut-for-woocommerce' ) );
		}

		// Every setup is worked out before any is saved, so a template that cannot be resolved changes nothing.
		$moved  = 0;
		$posted = $this->moved( ProductConfig::stored_on( $product ), $from, $to, $moved );
		if ( $posted instanceof WP_Error ) {
			return $posted;
		}
		$variations = array();
		foreach ( $product->get_children() as $variation_id ) {
			$variation = wc_get_product( $variation_id );
			if ( ! $variation instanceof WC_Product ) {
				continue;
			}
			$own    = ProductConfig::stored_on( $variation );
			$fields = $own->override ? $this->moved( $own, $from, $to, $moved ) : null;
			if ( $fields instanceof WP_Error ) {
				return $fields;
			}
			if ( null !== $fields ) {
				$variations[] = array( $variation, $fields );
			}
		}
		if ( 0 === $moved ) {
			return new WP_Error(
				'pixonaut_wc_ability_not_on_template',
				/* translators: %s: a template's id, e.g. "mini-tee". */
				sprintf( __( 'No personalised side of the product or its variations is on the template "%s".', 'pixonaut-for-woocommerce' ), $from )
			);
		}

		$answer  = null === $posted ? $this->links->summary( $product ) : $this->links->configure( $product, $posted );
		$answers = array();
		foreach ( $variations as list( $variation, $fields ) ) {
			$answers[] = $this->links->configure_variation( $variation, $fields );
		}
		return $answer + array(
			'variations'        => $answers,
			'moved_sides_total' => $moved,
		);
	}

	/**
	 * The fields to save with the sides on $from moved to $to; null when
	 * none is on it, or the setup does not personalise.
	 *
	 * @param ProductConfig $config What the product or variation has.
	 * @param string        $from   The template's base id.
	 * @param string        $to     The template version, or base id, they move to.
	 * @param int           $moved  How many sides moved so far; this one's are added.
	 * @return array<string, mixed>|WP_Error|null
	 */
	private function moved( ProductConfig $config, string $from, string $to, int &$moved ): array|WP_Error|null {
		if ( ! $config->personalise ) {
			// A link kept while personalisation is off is not checked, so it is not moved either.
			return null;
		}
		$sides = SetupFields::sides_of( $config );
		$found = 0;
		// A side moved to a version stays on it; moved to a template's id alone, it follows the latest.
		$pinned = null !== TemplateId::parse( $to );
		foreach ( $config->sides as $index => $side ) {
			if ( $side->template_base_id === $from ) {
				$sides[ $index ]['template_id']     = $to;
				$sides[ $index ]['template_pinned'] = $pinned;
				++$found;
			}
		}
		if ( 0 === $found ) {
			return null;
		}
		$moved += $found;
		return $this->fields->posted(
			array(
				'personalised' => true,
				'sides'        => $sides,
			),
			$config
		);
	}
}
