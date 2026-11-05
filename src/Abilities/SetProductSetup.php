<?php
/**
 * The ability that sets up a product or variation as its product screen does.
 *
 * @package Pixonaut\WooCommerce
 */

declare(strict_types=1);

namespace Pixonaut\WooCommerce\Abilities;

use Pixonaut\WooCommerce\Admin\VariationPanel;
use Pixonaut\WooCommerce\ApprovalMode;
use Pixonaut\WooCommerce\Catalogue\PostedSides;
use Pixonaut\WooCommerce\Catalogue\ProductConfig;
use Pixonaut\WooCommerce\FormLayout;
use Pixonaut\WooCommerce\GroupNumbers;
use Pixonaut\WooCommerce\Layout;
use Pixonaut\WooCommerce\OptionGroups;
use WC_Product;
use WP_Error;

/**
 * Sets everything the Pixonaut tab of a simple or variable product, or a
 * variation's Pixonaut panel, sets: personalisation, the blank, the sides
 * with their templates, placements, print sizes, start size, form
 * arrangement and mode, the layout, form layout and approval mode, the
 * designer's views and starting view, the printshop, and the blank variant.
 * A field left out keeps what the product or variation has. Whoever may
 * edit the product may set it up.
 */
final class SetProductSetup implements Ability {

	/**
	 * @param ProductLinks $links  Saves the setup.
	 * @param SetupFields  $fields Turns the input into the product screen's fields.
	 */
	public function __construct( private readonly ProductLinks $links, private readonly SetupFields $fields ) {
	}

	public function name(): string {
		return 'set-product-setup';
	}

	public function definition(): array {
		$choice = static fn ( array $available, string $what ): array => array(
			'type'        => 'string',
			'description' => $what,
			'enum'        => array_merge( array( '' ), $available ),
		);
		return array(
			/* translators: Name of an ability an AI assistant or an MCP client can use on the store. */
			'label'               => __( 'Set up a product for Pixonaut', 'pixonaut-for-woocommerce' ),
			'description'         => __( 'Sets up a simple or variable product, or one variation, as its Pixonaut tab does: the blank, every side (template, placement, print sizes, start size, form arrangement, personalised or standing), layout, form layout, approval mode, canvas shape, the designer\'s views, the printshop and the blank variant. A field left out keeps what it has, so read the setup with `list-products` and send what changes. A variation given any setup field gets a setup of its own; `own_setup` false makes it follow its product again. Pixonaut checks the setup; one that fails a check is saved but cannot be bought, and the answer says why.', 'pixonaut-for-woocommerce' ),
			'input_schema'        => array(
				'type'                 => 'object',
				'properties'           => array(
					'product_id'    => array(
						'type'        => 'integer',
						'description' => __( 'A simple or variable product, or a variation.', 'pixonaut-for-woocommerce' ),
						'minimum'     => 1,
					),
					'personalised'  => array(
						'type'        => 'boolean',
						'description' => __( 'Whether customers personalise it; false keeps the setup, unused. Left out, true when `sides` are given, else as it is.', 'pixonaut-for-woocommerce' ),
					),
					'own_setup'     => array(
						'type'        => 'boolean',
						'description' => __( 'For a variation: true for a setup of its own, false to follow its product\'s again, keeping its own for later.', 'pixonaut-for-woocommerce' ),
					),
					'blank_id'      => array(
						'type'        => 'string',
						'description' => __( 'The blank the design is printed on; empty for a product without a blank, which has one side.', 'pixonaut-for-woocommerce' ),
					),
					'sides'         => array(
						'type'        => 'array',
						'description' => __( 'Every side, in order; replaces the sides it has.', 'pixonaut-for-woocommerce' ),
						'maxItems'    => PostedSides::MAX_SIDES,
						'items'       => SetupFields::side_schema(),
					),
					'layout'        => $choice( Layout::available(), __( 'The product page layout; empty for the store\'s.', 'pixonaut-for-woocommerce' ) ),
					'form_layout'   => $choice( FormLayout::available(), __( 'The form layout; empty for the store\'s.', 'pixonaut-for-woocommerce' ) ),
					'approval_mode' => $choice( ApprovalMode::available(), __( 'How customers approve their proofs; empty for the store\'s.', 'pixonaut-for-woocommerce' ) ),
					'option_groups' => $choice( OptionGroups::available(), __( 'Where the product\'s options stand in the form, on a side whose groups were not arranged by hand: `on_top`, unnumbered, or each in a group of its own, `first` or `last`; empty for the store\'s. A product\'s only: a variation follows its product.', 'pixonaut-for-woocommerce' ) ),
					'group_numbers' => $choice( GroupNumbers::available(), __( 'Whether the form numbers its groups; empty for the store\'s. A product\'s only: a variation follows its product.', 'pixonaut-for-woocommerce' ) ),
					'canvas_ratio'  => SetupFields::canvas_ratio_schema( __( 'empty for the template\'s own setting', 'pixonaut-for-woocommerce' ) ),
					'views'         => array(
						'type'        => 'array',
						'description' => __( 'The blank\'s views the designer shows, by id; empty for all.', 'pixonaut-for-woocommerce' ),
						'items'       => array( 'type' => 'string' ),
					),
					'initial_view'  => array(
						'type'        => 'string',
						'description' => __( 'The view the designer starts on, one of `views`; empty for the first.', 'pixonaut-for-woocommerce' ),
					),
					'printshop_id'  => array(
						'type'        => 'string',
						'description' => __( 'The printshop a product without a blank sends its orders to; empty for the workspace\'s default.', 'pixonaut-for-woocommerce' ),
					),
					'variant'       => array(
						'type'        => 'string',
						'description' => __( 'For a simple product or a variation on a blank: the blank\'s variant it sells, by its `v_…` id; empty to match it by the product\'s options.', 'pixonaut-for-woocommerce' ),
					),
				),
				'required'             => array( 'product_id' ),
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
	 * @param mixed $input As the input schema.
	 * @return array<string, mixed>|WP_Error
	 */
	public function execute( mixed $input = null ): array|WP_Error {
		$product = ProductLinks::product_or_variation( $input );
		if ( ! $product instanceof WC_Product ) {
			return $product;
		}
		$input   = (array) $input;
		$variant = is_string( $input['variant'] ?? null ) ? sanitize_text_field( $input['variant'] ) : null;
		if ( ! $product->is_type( 'variation' ) ) {
			$posted = $this->fields->posted( $input, ProductConfig::stored_on( $product ) );
			return $posted instanceof WP_Error ? $posted : $this->links->configure( $product, $posted, $variant );
		}

		if ( array() !== array_intersect_key( $input, array_flip( SetupFields::PRODUCT_KEYS ) ) ) {
			return new WP_Error( 'pixonaut_wc_ability_product_only', __( 'A variation follows its product for option_groups and group_numbers: set them on the product.', 'pixonaut-for-woocommerce' ) );
		}
		$given = array() !== array_intersect_key( $input, array_flip( SetupFields::SETUP_KEYS ) );
		$own   = (bool) ( $input['own_setup'] ?? ( $given || ProductConfig::stored_on( $product )->override ) );
		if ( ! $own ) {
			return $this->links->configure_variation( $product, null, $variant );
		}
		$posted = $this->fields->posted( $input, VariationPanel::own_or_inherited( $product ) );
		return $posted instanceof WP_Error ? $posted : $this->links->configure_variation( $product, $posted, $variant );
	}
}
