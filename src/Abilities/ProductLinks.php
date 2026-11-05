<?php
/**
 * Links and unlinks products as the product screen does.
 *
 * @package Pixonaut\WooCommerce
 */

declare(strict_types=1);

namespace Pixonaut\WooCommerce\Abilities;

use Pixonaut\WooCommerce\Admin\Notices;
use Pixonaut\WooCommerce\Admin\PanelFields;
use Pixonaut\WooCommerce\Admin\ProductPanel;
use Pixonaut\WooCommerce\Admin\VariationPanel;
use Pixonaut\WooCommerce\Admin\VariantField;
use Pixonaut\WooCommerce\Admin\VariantNotice;
use Pixonaut\WooCommerce\Catalogue\PostedSides;
use Pixonaut\WooCommerce\Catalogue\PrintSize;
use Pixonaut\WooCommerce\Catalogue\ProductConfig;
use Pixonaut\WooCommerce\Catalogue\ProductVariant;
use Pixonaut\WooCommerce\Catalogue\TemplateId;
use WC_Product;
use WP_Error;

/**
 * What the abilities that write a product's setup share: the product or
 * variation they act on, the save the product screen makes, and the answer
 * they give. A save goes through `ProductPanel::configure()` (a variation's
 * through `VariationPanel::apply()`), so it is checked with Pixonaut,
 * filtered and noted as the screen's is, and the blank variant is resolved
 * after it.
 */
final class ProductLinks {

	/**
	 * The product types a product is linked on, as on the product screen.
	 */
	private const PRODUCT_TYPES = array( 'simple', 'variable' );

	/**
	 * Who may link a product: whoever may edit it.
	 */
	private const CAPABILITY = 'edit_product';

	/**
	 * A variation, which some abilities set up beside its product.
	 */
	private const VARIATION_TYPE = 'variation';

	/**
	 * @param ProductPanel   $panel      Checks and saves the configuration.
	 * @param VariantField   $variant    Resolves the product's blank variant once it is saved.
	 * @param Notices        $notices    Holds the notice saying which variants cannot be bought.
	 * @param ProductSetup   $setup      Gives the setup saved.
	 * @param VariationPanel $variations Checks and saves a variation's own configuration.
	 */
	public function __construct(
		private readonly ProductPanel $panel,
		private readonly VariantField $variant,
		private readonly Notices $notices,
		private readonly ProductSetup $setup,
		private readonly VariationPanel $variations,
	) {
	}

	/**
	 * Whether the current user may change the product or variation named
	 * in $input: whoever may edit it, and a variation's product.
	 *
	 * @param mixed $input The ability's input, with `product_id`.
	 */
	public static function can_edit( mixed $input = null ): bool {
		$product_id = Abilities::integer( $input, 'product_id', 0 );
		if ( $product_id < 1 || ! current_user_can( self::CAPABILITY, $product_id ) ) {
			return false;
		}
		$parent = wp_get_post_parent_id( $product_id );
		return 'product_variation' !== get_post_type( $product_id ) || ( $parent > 0 && current_user_can( self::CAPABILITY, $parent ) );
	}

	/**
	 * The product named in $input, when it is one the product screen links.
	 *
	 * @param mixed $input The ability's input, with `product_id`.
	 */
	public static function product( mixed $input ): WC_Product|WP_Error {
		$product = wc_get_product( Abilities::integer( $input, 'product_id', 0 ) );
		if ( ! $product instanceof WC_Product || ! $product->is_type( self::PRODUCT_TYPES ) ) {
			return new WP_Error( 'pixonaut_wc_ability_unknown_product', __( 'No simple or variable product has this id.', 'pixonaut-for-woocommerce' ) );
		}
		return $product;
	}

	/**
	 * The product or variation named in $input.
	 *
	 * @param mixed $input The ability's input, with `product_id`.
	 */
	public static function product_or_variation( mixed $input ): WC_Product|WP_Error {
		$product = wc_get_product( Abilities::integer( $input, 'product_id', 0 ) );
		if ( ! $product instanceof WC_Product || ! $product->is_type( array_merge( self::PRODUCT_TYPES, array( self::VARIATION_TYPE ) ) ) ) {
			return new WP_Error( 'pixonaut_wc_ability_unknown_product', __( 'No simple or variable product or variation has this id.', 'pixonaut-for-woocommerce' ) );
		}
		return $product;
	}

	/**
	 * Links the product to one template, on a blank's placement or without a
	 * blank, in the size given or else the placement's default size. It
	 * replaces the sides it had. Without a blank and a size the product is
	 * saved but cannot be bought.
	 *
	 * @param WC_Product     $product      The product.
	 * @param TemplateId     $template     The template version.
	 * @param string         $blank_id     The blank; empty for a template-only product.
	 * @param string         $placement_id The blank's placement; ignored without a blank.
	 * @param PrintSize|null $size         The print size; null for the placement's default.
	 * @return array<string, mixed> As `summary()`.
	 */
	public function link( WC_Product $product, TemplateId $template, string $blank_id, string $placement_id, ?PrintSize $size = null ): array {
		$size_row = null === $size
			? array( 'kind' => PostedSides::SIZE_PLACEMENT_DEFAULT )
			: array(
				'kind'   => PostedSides::SIZE_CUSTOM,
				'width'  => (string) $size->width_mm,
				'height' => (string) $size->height_mm,
				'label'  => $size->label,
			);
		$side     = array(
			'template'  => (string) $template,
			'placement' => '' === $blank_id ? '' : $placement_id,
			'sizes'     => array( $size_row ),
		);
		return $this->save(
			$product,
			array(
				PanelFields::PERSONALISE => 'yes',
				PanelFields::BLANK       => $blank_id,
				PanelFields::SIDES       => array( $side ),
			)
		);
	}

	/**
	 * Turns personalisation off. The link is kept, so linking again or
	 * ticking the box on the product screen restores it.
	 *
	 * @param WC_Product $product The product.
	 * @return array<string, mixed> As `summary()`.
	 */
	public function unlink( WC_Product $product ): array {
		return $this->save( $product, array() );
	}

	/**
	 * Saves a product's fields as the product screen does, with its blank
	 * variant: the one picked, or the one it had.
	 *
	 * @param WC_Product           $product A simple or variable product.
	 * @param array<string, mixed> $posted  Every field, by `PanelFields` key, as the screen posts them.
	 * @param string|null          $variant The blank variant a simple product sells, `''` to match it by options; null keeps what it has.
	 * @return array<string, mixed> As `summary()`.
	 */
	public function configure( WC_Product $product, array $posted, ?string $variant = null ): array {
		$config = $this->panel->configure( $product, $posted );
		$this->variants( $product, $config, $variant );
		return $this->summary( $product );
	}

	/**
	 * Saves a variation's own setup, or makes it follow its product's, and
	 * its blank variant.
	 *
	 * @param WC_Product                $variation The variation.
	 * @param array<string, mixed>|null $posted    Its fields, by `PanelFields` key; null to follow its product.
	 * @param string|null               $variant   Its blank variant, `''` to match it by options; null keeps what it has.
	 * @return array<string, mixed> As `summary()`.
	 */
	public function configure_variation( WC_Product $variation, ?array $posted, ?string $variant = null ): array {
		$this->variations->apply( $variation, $posted );
		$stored = ProductVariant::stored_on( $variation );
		// A pick names a variant of one blank: it is kept only while the variation is on that blank.
		$blank = ProductConfig::config_for( $variation )->blank_id;
		$kept  = null !== $stored && $stored->blank_id === $blank ? $stored->pick : null;
		$this->variant->save( $variation, $variant ?? $kept ?? VariantField::MATCH_BY_OPTIONS );
		$parent = wc_get_product( $variation->get_parent_id() );
		if ( $parent instanceof WC_Product ) {
			$this->variant->note( $parent );
		}
		return $this->summary( $variation );
	}

	/**
	 * The schema of what the abilities answer: the setup saved, and whether
	 * customers can buy it now.
	 *
	 * @return array<string, mixed>
	 */
	public static function output_schema(): array {
		return array(
			'type'       => 'object',
			'properties' => array(
				'product_id'    => array( 'type' => 'integer' ),
				'own_setup'     => array(
					'type'        => 'boolean',
					'description' => __( 'For a variation, whether it has a setup of its own rather than following its product\'s, whose setup it then gives; false for a product.', 'pixonaut-for-woocommerce' ),
				),
				'purchasable'   => array(
					'type'        => 'boolean',
					'description' => __( 'Whether customers can buy it now, its blank variants and WooCommerce\'s own checks included.', 'pixonaut-for-woocommerce' ),
				),
				'variant_issue' => array(
					'type'        => array( 'string', 'null' ),
					'description' => __( 'Which of the product\'s variations sell no variant of the blank, in words; null when each does. Set a variation\'s `variant` with `set-product-setup`.', 'pixonaut-for-woocommerce' ),
				),
			) + ProductSetup::schema(),
		);
	}

	/**
	 * Saves what is posted, with the product's own overrides.
	 *
	 * @param WC_Product           $product The product.
	 * @param array<string, mixed> $posted  The fields that change, by `PanelFields` key.
	 * @return array<string, mixed>
	 */
	private function save( WC_Product $product, array $posted ): array {
		$current = ProductConfig::stored_on( $product );
		return $this->configure(
			$product,
			$posted + array(
				PanelFields::LAYOUT        => $current->layout ?? '',
				PanelFields::FORM_LAYOUT   => $current->form_layout ?? '',
				PanelFields::APPROVAL_MODE => $current->approval_mode ?? '',
			)
		);
	}

	/**
	 * Resolves the blank variants once the configuration is saved: a
	 * variable product's variations', or a simple product's own.
	 *
	 * @param WC_Product    $product The product.
	 * @param ProductConfig $config  Its configuration as saved.
	 * @param string|null   $pick    The variant picked, `''` to match by options; null keeps the one picked on the same blank.
	 */
	private function variants( WC_Product $product, ProductConfig $config, ?string $pick ): void {
		if ( $product->is_type( 'variable' ) ) {
			$this->variant->refresh( $product );
			return;
		}
		$stored = ProductVariant::stored_on( $product );
		$this->variant->save( $product, $pick ?? ( null !== $stored && $stored->blank_id === $config->blank_id ? $stored->pick : null ) );
	}

	/**
	 * The setup that applies to the product or variation, as saved.
	 *
	 * @param WC_Product $product The product or variation.
	 * @return array<string, mixed>
	 */
	public function summary( WC_Product $product ): array {
		// Loaded again: the variants were written after $product was read.
		$saved  = wc_get_product( $product->get_id() );
		$saved  = $saved instanceof WC_Product ? $saved : $product;
		$parent = $saved->is_type( self::VARIATION_TYPE ) ? $saved->get_parent_id() : $saved->get_id();
		$notice = $this->notices->all()[ VariantNotice::key( $parent ) ]['message'] ?? null;
		$own    = ProductConfig::stored_on( $saved );
		$owner  = $saved->is_type( self::VARIATION_TYPE ) && ! $own->override ? wc_get_product( $parent ) : null;
		return array(
			'product_id'    => $saved->get_id(),
			'own_setup'     => $saved->is_type( self::VARIATION_TYPE ) && $own->override,
			'purchasable'   => $saved->is_purchasable(),
			'variant_issue' => null === $notice ? null : wp_strip_all_tags( $notice ),
		) + $this->setup->of( $saved, $owner instanceof WC_Product ? ProductConfig::stored_on( $owner ) : $own );
	}
}
