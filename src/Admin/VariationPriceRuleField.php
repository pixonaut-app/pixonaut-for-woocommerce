<?php
/**
 * A variation's own parts of the pricing rule.
 *
 * @package Pixonaut\WooCommerce
 */

declare(strict_types=1);

namespace Pixonaut\WooCommerce\Admin;

use Pixonaut\WooCommerce\Pricing\ProductPricing;
use Pixonaut\WooCommerce\Templates;
use WC_Product;
use WP_Post;

/**
 * "Own price rule", a closed section on each variation of a product priced
 * from its printshop cost: the parts of the rule this variation changes,
 * each field showing in grey what it follows without one (its product,
 * its attribute values or the store). Saving a changed part settles the
 * variation's price when it follows the rule.
 */
final class VariationPriceRuleField {

	/**
	 * The posted field holding each variation's parts, by its place in the list, then by part.
	 */
	public const FIELD = 'pixonaut_variation_price_rule';

	public const NONCE_ACTION = 'pixonaut_wc_save_variation_price_rule';

	/**
	 * After the variation's Pixonaut panel.
	 */
	private const HOOK_PRIORITY = 30;

	/**
	 * The arguments `woocommerce_product_after_variable_attributes` passes.
	 */
	private const RENDER_ARGS = 3;

	/**
	 * The arguments `woocommerce_save_product_variation` passes.
	 */
	private const SAVE_ARGS = 2;

	/**
	 * @param ProductPricing $pricing   Reads and settles the rule.
	 * @param Templates      $templates Renders the section.
	 */
	public function __construct(
		private readonly ProductPricing $pricing,
		private readonly Templates $templates,
	) {
	}

	public function register(): void {
		add_action( 'woocommerce_product_after_variable_attributes', array( $this, 'render' ), self::HOOK_PRIORITY, self::RENDER_ARGS );
		add_action( 'woocommerce_save_product_variation', array( $this, 'save' ), self::HOOK_PRIORITY, self::SAVE_ARGS );
	}

	/**
	 * Prints the section of a variation whose product is priced from its cost.
	 *
	 * @param mixed $loop      The variation's place in the variations list.
	 * @param mixed $data      The variation's data; unused.
	 * @param mixed $variation The variation's post.
	 */
	public function render( mixed $loop, mixed $data, mixed $variation ): void {
		unset( $data );
		$item    = $variation instanceof WP_Post ? wc_get_product( $variation->ID ) : null;
		$product = $item instanceof WC_Product ? wc_get_product( $item->get_parent_id() ) : null;
		if ( ! $item instanceof WC_Product || ! $product instanceof WC_Product || ! ProductPricing::prices( $product ) ) {
			return;
		}
		$own       = ProductPricing::own_parts( $item );
		$inherited = $this->pricing->item_rule( $item, ProductPricing::own_parts( $product ), array() );
		$this->templates->render(
			'admin/variation-price-rule.php',
			array(
				'name'      => self::FIELD . '[' . (int) $loop . ']',
				'id'        => 'pixonaut_variation_price_rule_' . (int) $loop,
				'nonce'     => wp_create_nonce( self::NONCE_ACTION ),
				'own_parts' => $own,
				'inherited' => $inherited->parts,
				'sources'   => array_map( PricingPanel::source_label( ... ), $inherited->sources ),
				'parts'     => PricingPanel::part_labels(),
				'open'      => array() !== array_filter( $own, static fn ( string $part ): bool => '' !== $part ),
			)
		);
	}

	/**
	 * Stores the parts the variation's section posted, and settles its
	 * price when they changed. WooCommerce has checked its own nonce and
	 * the capability by now; the section's nonce shows it was posted.
	 *
	 * @param mixed $variation_id The variation.
	 * @param mixed $loop         Its place in the variations list.
	 */
	public function save( mixed $variation_id, mixed $loop ): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- the section's own nonce is verified below.
		$all    = isset( $_POST[ self::FIELD ] ) && is_array( $_POST[ self::FIELD ] ) ? wp_unslash( $_POST[ self::FIELD ] ) : array(); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- each part is checked as a number.
		$posted = $all[ (int) $loop ] ?? null;
		if ( ! is_array( $posted ) || ! is_string( $posted['nonce'] ?? null ) || ! wp_verify_nonce( sanitize_key( $posted['nonce'] ), self::NONCE_ACTION ) ) {
			return;
		}
		$variation = wc_get_product( (int) $variation_id );
		$product   = $variation instanceof WC_Product ? wc_get_product( $variation->get_parent_id() ) : null;
		if ( ! $variation instanceof WC_Product || ! $product instanceof WC_Product || ! ProductPricing::prices( $product ) ) {
			return;
		}
		$parts = isset( $posted['parts'] ) && is_array( $posted['parts'] ) ? array_filter( $posted['parts'], 'is_string' ) : array();
		if ( $this->pricing->save_own_parts( $variation, $parts ) ) {
			$this->pricing->reprice( $product, array( $variation->get_id() ) );
		}
	}
}
