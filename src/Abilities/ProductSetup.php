<?php
/**
 * A product's Pixonaut setup, as the abilities give it.
 *
 * @package Pixonaut\WooCommerce
 */

declare(strict_types=1);

namespace Pixonaut\WooCommerce\Abilities;

use Pixonaut\WooCommerce\Catalogue\CanvasRatio;
use Pixonaut\WooCommerce\Catalogue\InvalidReason;
use Pixonaut\WooCommerce\Catalogue\PictureImage;
use Pixonaut\WooCommerce\Catalogue\ProductConfig;
use Pixonaut\WooCommerce\Catalogue\ProductPictures;
use Pixonaut\WooCommerce\Catalogue\ProductVariant;
use Pixonaut\WooCommerce\Catalogue\ShowcaseImages;
use Pixonaut\WooCommerce\Catalogue\Side;
use Pixonaut\WooCommerce\Catalogue\StandingRow;
use Pixonaut\WooCommerce\Settings\Settings;
use WC_Product;

/**
 * What a product or variation is set up with in the plugin, read from its
 * stored configuration (`_pixonaut_config`), its blank variant
 * (`_pixonaut_variant`) and its picture (`_pixonaut_picture`): the
 * templates and versions of its sides, their print sizes, the blank and
 * variant, whether Pixonaut found a problem, and where its Pixonaut
 * picture stands. In the terms `LinkProduct` takes, so a new product can
 * be set up as an existing one is.
 */
final class ProductSetup {

	/**
	 * The store does not use Pixonaut pictures as product images, or the
	 * product is not personalised.
	 */
	public const PICTURE_OFF = 'off';

	/**
	 * The picture is drawn and recorded.
	 */
	public const PICTURE_DRAWN = 'drawn';

	/**
	 * The picture is to be drawn: queued since what it is drawn from changed.
	 */
	public const PICTURE_PENDING = 'pending';

	/**
	 * Pixonaut could not draw it; it is tried again later.
	 */
	public const PICTURE_FAILED = 'failed';

	/**
	 * The store manager set the product's own image, which is kept.
	 */
	public const PICTURE_OWN_IMAGE = 'own_image';

	/**
	 * Nothing to draw it from, e.g. a template-only side without a showcase.
	 */
	public const PICTURE_NONE = 'none';

	public const PICTURE_STATES = array( self::PICTURE_OFF, self::PICTURE_DRAWN, self::PICTURE_PENDING, self::PICTURE_FAILED, self::PICTURE_OWN_IMAGE, self::PICTURE_NONE );

	/**
	 * @param Settings $settings Says whether pictures are product images.
	 */
	public function __construct( private readonly Settings $settings ) {
	}

	/**
	 * The setup of a product or variation: the configuration stored on it,
	 * or the one given, with its own variant and picture.
	 *
	 * @param WC_Product         $product A product or variation.
	 * @param ProductConfig|null $config  The configuration it follows; null for its own.
	 * @return array<string, mixed> As `schema()`.
	 */
	public function of( WC_Product $product, ?ProductConfig $config = null ): array {
		$config ??= ProductConfig::stored_on( $product );
		return array(
			'linked'          => array() !== array_filter( $config->sides, static fn ( Side $side ): bool => '' !== $side->template_base_id ),
			'personalised'    => $config->personalise,
			'valid'           => $config->valid,
			'invalid_reason'  => $config->valid ? null : InvalidReason::message( $config->invalid_reason ),
			'blank_id'        => $config->blank_id,
			'blank_name'      => $config->blank_name,
			'variant'         => self::variant( $product, $config ),
			'layout'          => $config->layout,
			'form_layout'     => $config->form_layout,
			'option_groups'   => $config->option_groups,
			'group_numbers'   => $config->group_numbers,
			'approval_mode'   => $config->approval_mode,
			'views'           => $config->views,
			'initial_view'    => $config->initial_view,
			'printshop_id'    => $config->printshop_id,
			'canvas_ratio'    => CanvasRatio::posted( $config->draw_at_template_ratio ),
			'sides'           => array_map( array( self::class, 'side' ), $config->sides ),
			'showcase_images' => ShowcaseImages::on( $product ),
			'picture'         => $this->picture( $product ),
		);
	}

	/**
	 * The schema of `of()`'s answer.
	 *
	 * @return array<string, mixed>
	 */
	public static function schema(): array {
		$text_or_null = array( 'type' => array( 'string', 'null' ) );
		return array(
			'linked'          => array(
				'type'        => 'boolean',
				'description' => __( 'Whether it is linked to a template, personalised or not.', 'pixonaut-for-woocommerce' ),
			),
			'personalised'    => array(
				'type'        => 'boolean',
				'description' => __( 'Whether customers personalise it.', 'pixonaut-for-woocommerce' ),
			),
			'valid'           => array(
				'type'        => 'boolean',
				'description' => __( 'False when Pixonaut found a problem: it cannot be bought until it is fixed.', 'pixonaut-for-woocommerce' ),
			),
			'invalid_reason'  => array(
				'type'        => array( 'string', 'null' ),
				'description' => __( 'Why it cannot be bought, in words; null when it can.', 'pixonaut-for-woocommerce' ),
			),
			'blank_id'        => array(
				'type'        => array( 'string', 'null' ),
				'description' => __( 'The blank it is printed on; null for a template-only product.', 'pixonaut-for-woocommerce' ),
			),
			'blank_name'      => $text_or_null,
			'variant'         => self::variant_schema( __( 'The blank\'s variant it sells: `ref` null and `problem` `unmapped` when none matches, `retired` when the blank no longer sells it. Null without a blank, and for a variable product, whose variations each give theirs.', 'pixonaut-for-woocommerce' ) ),
			'layout'          => array(
				'type'        => array( 'string', 'null' ),
				'description' => __( 'The product page layout it overrides the store\'s with; null for the store\'s.', 'pixonaut-for-woocommerce' ),
			),
			'form_layout'     => array(
				'type'        => array( 'string', 'null' ),
				'description' => __( 'The form layout it overrides the store\'s with; null for the store\'s.', 'pixonaut-for-woocommerce' ),
			),
			'option_groups'   => array(
				'type'        => array( 'string', 'null' ),
				'description' => __( 'Where it overrides the store on its options in the form: `on_top`, `first` or `last`; null for the store\'s.', 'pixonaut-for-woocommerce' ),
			),
			'group_numbers'   => array(
				'type'        => array( 'string', 'null' ),
				'description' => __( 'Whether it overrides the store on numbering the form\'s groups: `on` or `off`; null for the store\'s.', 'pixonaut-for-woocommerce' ),
			),
			'approval_mode'   => array(
				'type'        => array( 'string', 'null' ),
				'description' => __( 'The proof approval it overrides the store\'s with; null for the store\'s.', 'pixonaut-for-woocommerce' ),
			),
			'views'           => array(
				'type'        => 'array',
				'description' => __( 'The blank\'s views the designer shows, by id; empty for all.', 'pixonaut-for-woocommerce' ),
				'items'       => array( 'type' => 'string' ),
			),
			'initial_view'    => array(
				'type'        => array( 'string', 'null' ),
				'description' => __( 'The view the designer starts on; null for the first.', 'pixonaut-for-woocommerce' ),
			),
			'printshop_id'    => array(
				'type'        => array( 'string', 'null' ),
				'description' => __( 'The printshop a product without a blank sends its orders to; null for the workspace\'s default.', 'pixonaut-for-woocommerce' ),
			),
			'canvas_ratio'    => SetupFields::canvas_ratio_schema( __( 'empty for the template\'s own setting', 'pixonaut-for-woocommerce' ) ),
			'showcase_images' => array(
				'type'        => 'array',
				'description' => __( 'The template\'s showcase sets its product images are drawn with, set with `set-product-showcase`; a variation gives its product\'s.', 'pixonaut-for-woocommerce' ),
				'items'       => array(
					'type'       => 'object',
					'properties' => array(
						'id'    => array( 'type' => 'string' ),
						'name'  => array( 'type' => 'string' ),
						'kind'  => array(
							'type' => 'string',
							'enum' => array( ShowcaseImages::MOCKUP, ShowcaseImages::PREVIEW ),
						),
						'view'  => array(
							'type'        => 'string',
							'description' => __( 'The view of the blank it is drawn on, set with `set-product-picture-view`; absent for the one the product page starts on.', 'pixonaut-for-woocommerce' ),
						),
						'photo' => array(
							'type'        => 'string',
							'description' => __( 'The view\'s photo it is drawn on; absent for its photo of the variant sold.', 'pixonaut-for-woocommerce' ),
						),
					),
				),
			),
			'sides'           => array(
				'type'  => 'array',
				'items' => array(
					'type'       => 'object',
					'properties' => array(
						'template_id'      => array(
							'type'        => array( 'string', 'null' ),
							'description' => __( 'The template version it is linked to, e.g. `mini-tee@2`; null without a template.', 'pixonaut-for-woocommerce' ),
						),
						'template_pinned'  => array(
							'type'        => 'boolean',
							'description' => __( 'True when the side stays on its template version; false when it follows the template\'s latest published version.', 'pixonaut-for-woocommerce' ),
						),
						'placement_id'     => array(
							'type'        => array( 'string', 'null' ),
							'description' => __( 'Where on the blank it is printed; null without a blank.', 'pixonaut-for-woocommerce' ),
						),
						'template_name'    => $text_or_null,
						'placement_name'   => $text_or_null,
						'standing_options' => array(
							'type'        => 'array',
							'description' => __( 'A standing side on a blank whose print areas change with an option: the design each option is printed from, and whether its layout was adjusted by hand, which `reset-standing-layout` undoes. Empty for any other side.', 'pixonaut-for-woocommerce' ),
							'items'       => array(
								'type'       => 'object',
								'properties' => array(
									'design_id' => array( 'type' => 'string' ),
									'option'    => array(
										'type'        => 'string',
										'description' => __( 'The option\'s values, e.g. "XL".', 'pixonaut-for-woocommerce' ),
									),
									'adjusted'  => array( 'type' => 'boolean' ),
								),
							),
						),
					) + SetupFields::side_schema()['properties'],
					// The rest as `set-product-setup` takes them.
				),
			),
			'picture'         => array(
				'type'        => 'string',
				'description' => __( 'Where its Pixonaut picture, shown as its image, stands: `drawn`, `pending` to be drawn, `failed` and to be tried again, `own_image` when the store manager\'s image is kept, `none` with nothing to draw it from, or `off` when the store does not use the pictures or it is not personalised.', 'pixonaut-for-woocommerce' ),
				'enum'        => self::PICTURE_STATES,
			),
		);
	}

	/**
	 * The schema of a blank variant as `variant()` gives it.
	 *
	 * @param string $description What the field is.
	 * @return array<string, mixed>
	 */
	public static function variant_schema( string $description ): array {
		$text_or_null = array( 'type' => array( 'string', 'null' ) );
		return array(
			'type'        => array( 'object', 'null' ),
			'description' => $description,
			'properties'  => array(
				'ref'     => $text_or_null,
				'label'   => array( 'type' => 'string' ),
				'picked'  => array(
					'type'        => 'boolean',
					'description' => __( 'Whether the store manager picked it rather than it being matched on the product\'s attributes.', 'pixonaut-for-woocommerce' ),
				),
				'problem' => array(
					'type' => array( 'string', 'null' ),
					'enum' => array( ProductVariant::UNMAPPED, ProductVariant::RETIRED, null ),
				),
			),
		);
	}

	/**
	 * Whether a product or variation can be sold as far as the configuration
	 * it is sold under goes, and why not: the reason's code (an
	 * `InvalidReason`, or Pixonaut's own) and the text the store manager
	 * reads for it. One not personalised can. Its blank variant is given
	 * apart, by `variation_variant()`. Read from what is stored, without
	 * asking Pixonaut.
	 *
	 * @param WC_Product $product A product or variation.
	 * @return array{valid: bool, reason: string|null, message: string|null}
	 */
	public static function validity( WC_Product $product ): array {
		$config = ProductConfig::config_for( $product );
		$valid  = ! $config->personalise || $config->valid;
		return array(
			'valid'   => $valid,
			'reason'  => $valid ? null : $config->invalid_reason,
			'message' => $valid ? null : InvalidReason::message( $config->invalid_reason ),
		);
	}

	/**
	 * The schema of `validity()`'s answer.
	 *
	 * @return array<string, mixed>
	 */
	public static function validity_schema(): array {
		$text_or_null = array( 'type' => array( 'string', 'null' ) );
		return array(
			'type'       => 'object',
			'properties' => array(
				'valid'   => array(
					'type'        => 'boolean',
					'description' => __( 'False when Pixonaut found a problem: it cannot be bought until it is fixed.', 'pixonaut-for-woocommerce' ),
				),
				'reason'  => $text_or_null + array(
					'description' => __( 'Why not, as a code such as `too_large` or `template_not_live`; null when valid.', 'pixonaut-for-woocommerce' ),
				),
				'message' => $text_or_null + array(
					'description' => __( 'Why not, in words, as the product screen says it; null when valid.', 'pixonaut-for-woocommerce' ),
				),
			),
		);
	}

	/**
	 * The blank variant a variation sells, as `variant()` gives it under
	 * the configuration it is sold under; null when it is not personalised
	 * on a blank. Read from what is stored, without asking Pixonaut.
	 *
	 * @param WC_Product $variation A variation.
	 * @return array{ref: string|null, label: string, picked: bool, problem: string|null}|null
	 */
	public static function variation_variant( WC_Product $variation ): ?array {
		$config = ProductConfig::config_for( $variation );
		return $config->personalise ? self::variant( $variation, $config ) : null;
	}

	/**
	 * The blank variant a product or variation sells under its
	 * configuration: none matched when the one stored is of another blank,
	 * was matched on attributes it no longer has, or is a pick the blank no
	 * longer has. Null without a blank,
	 * and for a variable product, whose variations each sell their own.
	 *
	 * @param WC_Product    $product A product or variation.
	 * @param ProductConfig $config  The configuration it follows.
	 * @return array{ref: string|null, label: string, picked: bool, problem: string|null}|null
	 */
	private static function variant( WC_Product $product, ProductConfig $config ): ?array {
		if ( null === $config->blank_id || $product->is_type( 'variable' ) ) {
			return null;
		}
		$stored = ProductVariant::stored_on( $product );
		if ( null === $stored || null === $stored->ref || $stored->blank_id !== $config->blank_id || ! $stored->is_current( $product ) ) {
			return array(
				'ref'     => null,
				'label'   => '',
				'picked'  => false,
				'problem' => ProductVariant::UNMAPPED,
			);
		}
		return array(
			'ref'     => $stored->ref,
			'label'   => $stored->label,
			'picked'  => null !== $stored->pick,
			'problem' => $stored->problem(),
		);
	}

	/**
	 * A side as `set-product-setup` takes it, with the names it shows.
	 *
	 * @param Side $side A side of the configuration.
	 * @return array<string, mixed>
	 */
	private static function side( Side $side ): array {
		return SetupFields::side_of( $side ) + array(
			'template_name'    => $side->template_name,
			'placement_name'   => $side->placement_name,
			'standing_options' => array_map(
				static fn ( StandingRow $row ): array => array(
					'design_id' => $row->design_id,
					'option'    => $row->label(),
					'adjusted'  => null !== $row->adjusted,
				),
				$side->is_standing() ? array_values( $side->standing_rows ) : array()
			),
		);
	}

	/**
	 * Where the picture stands, from the record `ProductPictures` keeps of
	 * its last drawing against what it is drawn from now.
	 *
	 * @param WC_Product $product A product or variation.
	 */
	private function picture( WC_Product $product ): string {
		if ( ! ProductPictures::applies( $this->settings, $product ) ) {
			return self::PICTURE_OFF;
		}
		if ( PictureImage::is_own( $product ) ) {
			return self::PICTURE_OWN_IMAGE;
		}
		$stored = ProductPictures::stored( $product );
		if ( ProductPictures::basis( $product ) !== $stored['basis'] ) {
			return self::PICTURE_PENDING;
		}
		if ( null !== $stored['failed_at'] ) {
			return self::PICTURE_FAILED;
		}
		return $stored['attachment'] > 0 ? self::PICTURE_DRAWN : self::PICTURE_NONE;
	}
}
