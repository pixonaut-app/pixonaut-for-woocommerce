<?php
/**
 * The ability that links a product to a Pixonaut template.
 *
 * @package Pixonaut\WooCommerce
 */

declare(strict_types=1);

namespace Pixonaut\WooCommerce\Abilities;

use Pixonaut\WooCommerce\Catalogue\PrintSize;
use Pixonaut\WooCommerce\Catalogue\TemplateId;
use WC_Product;
use WP_Error;

/**
 * Links a simple or variable product to one template, optionally on a
 * blank's placement, and turns personalisation on, as the product screen
 * saves a product with one side: in the print size given, or else in the
 * placement's default size. Whoever may edit the product may link it.
 */
final class LinkProduct implements Ability {

	/**
	 * A template version's id: its base id, `@`, and a positive whole version.
	 */
	private const TEMPLATE_ID_PATTERN = '^.+@[1-9][0-9]*$';

	/**
	 * @param ProductLinks $links Saves the link.
	 */
	public function __construct( private readonly ProductLinks $links ) {
	}

	public function name(): string {
		return 'link-product';
	}

	public function definition(): array {
		return array(
			/* translators: Name of an ability an AI assistant or an MCP client can use on the store. */
			'label'               => __( 'Link a product to a Pixonaut template', 'pixonaut-for-woocommerce' ),
			'description'         => __( 'Makes a simple or variable product personalisable with one Pixonaut template, optionally printed on a placement of a blank, and replaces the sides it had. Pixonaut checks the link; a product that fails a check is saved but cannot be bought, and the answer says why.', 'pixonaut-for-woocommerce' ),
			'input_schema'        => array(
				'type'                 => 'object',
				'properties'           => array(
					'product_id'   => array(
						'type'    => 'integer',
						'minimum' => 1,
					),
					'template_id'  => array(
						'type'        => 'string',
						'description' => __( 'The template version\'s id, as the template list gives it, e.g. `mini-tee@2`.', 'pixonaut-for-woocommerce' ),
						'pattern'     => self::TEMPLATE_ID_PATTERN,
					),
					'blank_id'     => array(
						'type'        => 'string',
						'description' => __( 'The blank the design is printed on; empty or left out for a product without a blank.', 'pixonaut-for-woocommerce' ),
						'default'     => '',
					),
					'placement_id' => array(
						'type'        => 'string',
						'description' => __( 'Where on the blank the design is printed; needed with a blank.', 'pixonaut-for-woocommerce' ),
						'default'     => '',
					),
					'width_mm'     => array(
						'type'             => 'number',
						'description'      => __( 'The print width in millimetres, with `height_mm`. Needed without a blank, where the size must match the template\'s aspect ratio; with a blank, left out for the placement\'s default size.', 'pixonaut-for-woocommerce' ),
						'exclusiveMinimum' => 0,
					),
					'height_mm'    => array(
						'type'             => 'number',
						'description'      => __( 'The print height in millimetres, with `width_mm`; one without the other is ignored.', 'pixonaut-for-woocommerce' ),
						'exclusiveMinimum' => 0,
					),
				),
				'required'             => array( 'product_id', 'template_id' ),
				'additionalProperties' => false,
			),
			'output_schema'       => ProductLinks::output_schema(),
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
	 * @param mixed $input `product_id`, `template_id`, `blank_id`, `placement_id`, `width_mm` and `height_mm`.
	 * @return array<string, mixed>|WP_Error
	 */
	public function execute( mixed $input = null ): array|WP_Error {
		$product = ProductLinks::product( $input );
		if ( ! $product instanceof WC_Product ) {
			return $product;
		}
		$input    = (array) $input;
		$template = TemplateId::parse( is_string( $input['template_id'] ?? null ) ? $input['template_id'] : '' );
		if ( null === $template ) {
			return new WP_Error( 'pixonaut_wc_ability_unknown_template', __( 'The template id is not one the template list gives.', 'pixonaut-for-woocommerce' ) );
		}
		return $this->links->link(
			$product,
			$template,
			is_string( $input['blank_id'] ?? null ) ? sanitize_text_field( $input['blank_id'] ) : '',
			is_string( $input['placement_id'] ?? null ) ? sanitize_text_field( $input['placement_id'] ) : '',
			self::print_size( $input )
		);
	}

	/**
	 * The print size given, if both dimensions were.
	 *
	 * @param array<mixed> $input The ability's input.
	 */
	private static function print_size( array $input ): ?PrintSize {
		$width  = $input['width_mm'] ?? null;
		$height = $input['height_mm'] ?? null;
		if ( ! is_numeric( $width ) || ! is_numeric( $height ) || ! PrintSize::fits( (float) $width, (float) $height ) ) {
			return null;
		}
		return new PrintSize( (float) $width, (float) $height, PrintSize::label_for( (float) $width, (float) $height ) );
	}
}
