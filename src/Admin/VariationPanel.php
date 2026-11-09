<?php
/**
 * The Pixonaut panel of each variation on the product screen.
 *
 * @package Pixonaut\WooCommerce
 */

declare(strict_types=1);

namespace Pixonaut\WooCommerce\Admin;

use Pixonaut\WooCommerce\Catalogue\PostedSides;
use Pixonaut\WooCommerce\Catalogue\ProductConfig;
use Pixonaut\WooCommerce\Templates;
use WC_Product;
use WP_Post;

/**
 * A variation inherits its product's configuration unless "Override for this
 * variation" is ticked; then its own fields, the same as the product's, are
 * checked with Pixonaut and saved on it. Unticking keeps the variation's link,
 * unchecked and unused, so ticking again restores it. A variation
 * personalised on a blank also says which of the blank's variants it is
 * (`VariantField`), whether it overrides or not, and what its image is
 * drawn on (`ShowcaseField`).
 */
final class VariationPanel {

	public const NONCE_ACTION = 'pixonaut_wc_save_variation';

	/**
	 * After WooCommerce's own fields of the variation.
	 */
	private const HOOK_PRIORITY = 10;

	/**
	 * The arguments `woocommerce_product_after_variable_attributes` passes.
	 */
	private const RENDER_ARGS = 3;

	/**
	 * The arguments `woocommerce_save_product_variation` passes.
	 */
	private const SAVE_ARGS = 2;

	/**
	 * @param ProductPanel       $panel     Renders and saves the fields the product and its variations share.
	 * @param Templates          $templates Renders the panel.
	 * @param VariantField       $variant   Renders and saves the variation's blank variant.
	 * @param ShowcaseField|null $picture   Renders the choice of what the variation's image is drawn on; null for none.
	 */
	public function __construct(
		private readonly ProductPanel $panel,
		private readonly Templates $templates,
		private readonly VariantField $variant,
		private readonly ?ShowcaseField $picture = null,
	) {
	}

	public function register(): void {
		add_action( 'woocommerce_product_after_variable_attributes', array( $this, 'render' ), self::HOOK_PRIORITY, self::RENDER_ARGS );
		add_action( 'woocommerce_save_product_variation', array( $this, 'save' ), self::HOOK_PRIORITY, self::SAVE_ARGS );
		add_action( 'woocommerce_ajax_save_product_variations', array( $this, 'saved' ) );
	}

	/**
	 * Prints the variation's panel. A variation that does not override shows
	 * its product's configuration, so ticking the override starts from what
	 * it inherits.
	 *
	 * @param mixed $loop      The variation's place in the variations list.
	 * @param mixed $data      The variation's data; unused.
	 * @param mixed $variation The variation's post.
	 */
	public function render( mixed $loop, mixed $data, mixed $variation ): void {
		unset( $data );
		$product = $variation instanceof WP_Post ? wc_get_product( $variation->ID ) : null;
		if ( ! $product instanceof WC_Product ) {
			return;
		}
		$fields = PanelFields::variation( (int) $loop );
		$own    = ProductConfig::stored_on( $product );

		$this->templates->render(
			'admin/product-variation.php',
			array(
				'nonce_name'    => $fields->name( PanelFields::NONCE ),
				'nonce'         => wp_create_nonce( self::NONCE_ACTION ),
				'override_name' => $fields->name( PanelFields::OVERRIDE ),
				'override_id'   => $fields->id( PanelFields::OVERRIDE ),
				'override'      => $own->override,
				'max_sides'     => PostedSides::MAX_SIDES,
				'fields_html'   => $this->panel->fields_html( $this->panel->panel( $product, self::own_or_inherited( $product ), $fields ) ),
				'search_urls'   => ProductPanel::search_urls(),
				'rest_nonce'    => wp_create_nonce( 'wp_rest' ),
				'variant_html'  => $this->variant->html( $product, $fields ),
				'picture_html'  => null === $this->picture ? '' : $this->picture->variation_html( $product, $fields ),
			)
		);
	}

	/**
	 * Saves the variation's panel. WooCommerce has checked its own nonce and
	 * the capability by now; the panel's nonce shows the panel was posted.
	 *
	 * @param mixed $variation_id The variation.
	 * @param mixed $loop         Its place in the variations list.
	 */
	public function save( mixed $variation_id, mixed $loop ): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- the panel's own nonce is verified below.
		$all    = isset( $_POST[ PanelFields::VARIATION_FIELD ] ) && is_array( $_POST[ PanelFields::VARIATION_FIELD ] ) ? wp_unslash( $_POST[ PanelFields::VARIATION_FIELD ] ) : array(); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- ProductPanel::configure() sanitises each field.
		$posted = $all[ (int) $loop ] ?? null;
		if ( ! is_array( $posted ) || ! is_string( $posted[ PanelFields::NONCE ] ?? null ) || ! wp_verify_nonce( sanitize_key( $posted[ PanelFields::NONCE ] ), self::NONCE_ACTION ) ) {
			return;
		}
		$variation = wc_get_product( (int) $variation_id );
		if ( ! $variation instanceof WC_Product || ! $variation->is_type( 'variation' ) ) {
			return;
		}

		$this->apply( $variation, isset( $posted[ PanelFields::OVERRIDE ] ) ? $posted : null );
		$this->variant->save( $variation, $posted[ PanelFields::VARIANT ] ?? null );
		ShowcaseField::save_variation( $variation, isset( $posted[ PanelFields::PICTURE_VIEW_LISTED ] ) ? $posted[ PanelFields::PICTURE_VIEW ] ?? null : null );
	}

	/**
	 * Saves a variation's own configuration from its fields, checked with
	 * Pixonaut, or makes it follow its product's again, keeping its own
	 * link unchecked for when it overrides again.
	 *
	 * @param WC_Product        $variation The variation.
	 * @param array<mixed>|null $posted    Its fields, by `PanelFields` key; null to follow its product.
	 * @return ProductConfig The variation's own configuration as saved.
	 */
	public function apply( WC_Product $variation, ?array $posted ): ProductConfig {
		if ( null !== $posted ) {
			return $this->panel->configure( $variation, $posted, self::own_or_inherited( $variation )->with_override( true ) );
		}
		$stored = ProductConfig::stored_on( $variation );
		// A newer plugin version's configuration is kept as it is; the notice says why.
		if ( $stored->override && ProductConfig::REASON_NEWER_SCHEMA !== $stored->invalid_reason ) {
			// Nothing is checked: the link is kept for when the override is ticked again.
			$stored = $stored->with_override( false );
			$stored->save_to( $variation );
		}
		$this->panel->note( $variation, $stored );
		return $stored;
	}

	/**
	 * Raises or clears the product's variant notice once, after WooCommerce
	 * saved a batch of its variations, rather than once per variation.
	 *
	 * @param mixed $product_id The variable product.
	 */
	public function saved( mixed $product_id ): void {
		$product = wc_get_product( (int) $product_id );
		if ( $product instanceof WC_Product ) {
			$this->variant->note( $product );
		}
	}

	/**
	 * The variation's own configuration once it has one, else its product's:
	 * what its panel shows, and what a first override starts from, so a
	 * first override that stops personalising keeps the link it was shown.
	 *
	 * @param WC_Product $variation The variation.
	 */
	public static function own_or_inherited( WC_Product $variation ): ProductConfig {
		$parent = wc_get_product( $variation->get_parent_id() );
		return ProductConfig::is_stored_on( $variation ) || ! $parent instanceof WC_Product
			? ProductConfig::stored_on( $variation )
			: ProductConfig::stored_on( $parent );
	}
}
