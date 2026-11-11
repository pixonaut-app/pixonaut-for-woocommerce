<?php
/**
 * The ability that sets a variation's own print size.
 *
 * @package Pixonaut\WooCommerce
 */

declare(strict_types=1);

namespace Pixonaut\WooCommerce\Abilities;

use Pixonaut\WooCommerce\Catalogue\InvalidReason;
use Pixonaut\WooCommerce\Catalogue\PrintSize;
use Pixonaut\WooCommerce\Catalogue\ProductConfig;
use WC_Product;
use WP_Error;

/**
 * Gives one variation a setup of its own, starting from the one it is sold
 * under (its own while it overrides, else its product's, even when it keeps
 * an unused one of its own), in which its one side is sold in one print
 * size: the width and height given, in millimetres. The size is checked with
 * Pixonaut as the variation's Pixonaut panel checks it, on the blank's
 * placement or, without a blank, against the template; a size Pixonaut
 * cannot print is refused and nothing is saved. Whoever may edit the
 * variation and its product may set it.
 */
final class SetVariationPrintSize implements Ability {

	public const NAME = 'set-variation-print-size';

	/**
	 * The code a size that cannot be printed is refused with, its reason in
	 * the error's data as `reason`.
	 */
	public const REFUSED = 'pixonaut_wc_invalid_print_size';

	/**
	 * The reasons that are about the size itself: the check refused it.
	 */
	private const SIZE_REASONS = array(
		InvalidReason::INVALID_PRINT_SIZE,
		InvalidReason::MISSING_PRINT_SIZE,
		InvalidReason::TOO_LARGE,
		InvalidReason::WRONG_RATIO,
		InvalidReason::NO_FITTING_SIZE,
	);

	/**
	 * The status a refused size carries: the input cannot be done.
	 */
	private const UNPROCESSABLE = 422;

	/**
	 * @param ProductLinks $links  Checks and saves the variation's setup.
	 * @param SetupFields  $fields Turns the setup into the fields the variation's panel posts.
	 */
	public function __construct(
		private readonly ProductLinks $links,
		private readonly SetupFields $fields,
	) {
	}

	public function name(): string {
		return self::NAME;
	}

	public function definition(): array {
		return array(
			/* translators: Name of an ability an AI assistant or an MCP client can use on the store. */
			'label'               => __( 'Set a variation\'s print size', 'pixonaut-for-woocommerce' ),
			'description'         => __( 'Gives one variation its own print size, in millimetres, on a product with or without a blank: the variation gets a setup of its own, starting from the one it has, whose one side is sold in that size alone. Pixonaut checks the size as the variation\'s Pixonaut panel does; a size it cannot print is refused with the reason, and nothing is saved. The answer gives the variation\'s validity and blank variant now.', 'pixonaut-for-woocommerce' ),
			'input_schema'        => array(
				'type'                 => 'object',
				'properties'           => array(
					'variation_id' => array(
						'type'    => 'integer',
						'minimum' => 1,
					),
					'width_mm'     => array(
						'type'             => 'number',
						'exclusiveMinimum' => 0,
					),
					'height_mm'    => array(
						'type'             => 'number',
						'exclusiveMinimum' => 0,
					),
					'label'        => array(
						'type'        => 'string',
						'description' => __( 'The name customers see, e.g. "A3"; left out for the millimetres.', 'pixonaut-for-woocommerce' ),
					),
				),
				'required'             => array( 'variation_id', 'width_mm', 'height_mm' ),
				'additionalProperties' => false,
			),
			'output_schema'       => array(
				'type'       => 'object',
				'properties' => array(
					'variation_id'      => array( 'type' => 'integer' ),
					'pixonaut_validity' => ProductSetup::validity_schema(),
					'pixonaut_variant'  => ProductSetup::variant_schema( __( 'The blank\'s variant the variation sells; null when it is not personalised on a blank.', 'pixonaut-for-woocommerce' ) ),
				),
			),
			'execute_callback'    => array( $this, 'execute' ),
			'permission_callback' => array( $this, 'can_run' ),
			'annotations'         => array(
				'readonly'    => false,
				'destructive' => true,
				'idempotent'  => true,
			),
		);
	}

	/**
	 * Whether the current user may edit the variation and its product.
	 *
	 * @param mixed $input The call's input, with `variation_id`.
	 */
	public function can_run( mixed $input = null ): bool {
		return ProductLinks::can_edit( array( 'product_id' => Abilities::integer( $input, 'variation_id', 0 ) ) );
	}

	/**
	 * @param mixed $input `variation_id`, `width_mm`, `height_mm` and `label`.
	 * @return array<string, mixed>|WP_Error
	 */
	public function execute( mixed $input = null ): array|WP_Error {
		$input     = is_array( $input ) ? $input : array();
		$variation = wc_get_product( Abilities::integer( $input, 'variation_id', 0 ) );
		if ( ! $variation instanceof WC_Product || ! $variation->is_type( 'variation' ) ) {
			return new WP_Error( 'pixonaut_wc_ability_unknown_variation', __( 'No variation has this id.', 'pixonaut-for-woocommerce' ) );
		}
		$width  = is_numeric( $input['width_mm'] ?? null ) ? (float) $input['width_mm'] : 0.0;
		$height = is_numeric( $input['height_mm'] ?? null ) ? (float) $input['height_mm'] : 0.0;
		if ( ! PrintSize::fits( $width, $height ) ) {
			return self::refusal( InvalidReason::INVALID_PRINT_SIZE );
		}

		$current = self::sold_under( $variation );
		// Every side counts: one left unfinished would be dropped by saving the other alone.
		if ( count( $current->sides ) > 1 ) {
			return new WP_Error( 'pixonaut_wc_ability_several_sides', __( 'The variation has several sides, each with its own print sizes: set them with set-product-setup.', 'pixonaut-for-woocommerce' ) );
		}
		$only = $current->sides[0] ?? null;
		if ( ! $current->personalise || null === $only || '' === $only->template_base_id ) {
			return new WP_Error( 'pixonaut_wc_ability_not_personalised', __( 'The variation is not personalised with a template. Link its product first.', 'pixonaut-for-woocommerce' ) );
		}
		$side                = SetupFields::side_of( $only );
		$side['print_sizes'] = array(
			array(
				'width_mm'  => $width,
				'height_mm' => $height,
				'label'     => is_string( $input['label'] ?? null ) ? sanitize_text_field( $input['label'] ) : '',
			),
		);
		$side['start_size']  = 0;
		$posted              = $this->fields->posted( array( 'sides' => array( $side ) ), $current );
		if ( $posted instanceof WP_Error ) {
			return $posted;
		}

		$checked = $this->links->check_variation( $variation, $posted, $current );
		$refused = self::refused( $checked );
		if ( null !== $refused ) {
			return self::refusal( $refused );
		}
		$this->links->save_variation( $variation, $checked );
		// Read again: the setup and the variant were saved after $variation was read.
		$saved = wc_get_product( $variation->get_id() );
		$saved = $saved instanceof WC_Product ? $saved : $variation;
		return array(
			'variation_id'      => $saved->get_id(),
			'pixonaut_validity' => ProductSetup::validity( $saved ),
			'pixonaut_variant'  => ProductSetup::variation_variant( $saved ),
		);
	}

	/**
	 * The setup the variation is sold under, as stored: its own while it
	 * overrides, else its product's.
	 *
	 * @param WC_Product $variation The variation.
	 */
	private static function sold_under( WC_Product $variation ): ProductConfig {
		$own    = ProductConfig::stored_on( $variation );
		$parent = wc_get_product( $variation->get_parent_id() );
		return $own->override || ! $parent instanceof WC_Product ? $own : ProductConfig::stored_on( $parent );
	}

	/**
	 * Why the size cannot be printed, or null when it can, or could not be
	 * checked: a side whose check failed keeps its menu unchecked, and is
	 * saved as the panel saves it, to be checked again.
	 *
	 * @param ProductConfig $checked The variation's setup, checked.
	 */
	private static function refused( ProductConfig $checked ): ?string {
		$side = $checked->sides[0] ?? null;
		if ( null !== $side && array() === $side->print_sizes && null === $side->pending_sizes ) {
			return $checked->invalid_reason ?? InvalidReason::INVALID_PRINT_SIZE;
		}
		return ! $checked->valid && in_array( $checked->invalid_reason, self::SIZE_REASONS, true ) ? $checked->invalid_reason : null;
	}

	/**
	 * The refusal of a size that cannot be printed.
	 *
	 * @param string $reason Why, as an `InvalidReason` or Pixonaut's code.
	 */
	private static function refusal( string $reason ): WP_Error {
		return new WP_Error(
			self::REFUSED,
			InvalidReason::message( $reason, null, true ),
			array(
				'status' => self::UNPROCESSABLE,
				'reason' => $reason,
			)
		);
	}
}
