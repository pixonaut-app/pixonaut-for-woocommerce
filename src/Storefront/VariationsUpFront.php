<?php
/**
 * A personalised variable product's page prints all of its variations.
 *
 * @package Pixonaut\WooCommerce
 */

declare(strict_types=1);

namespace Pixonaut\WooCommerce\Storefront;

use WC_Product;
use WC_Product_Variable;

/**
 * On the page of a variable product that shows the inline designer, the
 * add-to-cart form is given every variation up front, however many the
 * product has. Above WooCommerce's threshold (30 variations by default) the
 * form would ask the server for each choice instead: it blocks itself under
 * a spinner, disables Add to cart and shows the product's range while it
 * waits, which makes every switch of a variation on another blank flash and
 * lag.
 *
 * What WooCommerce tells the form of each variation is kept between
 * requests (`ProductPageCache`): the form is handed it from there, by a
 * script at the top of the form that sets it before WooCommerce's script
 * reads it, while WooCommerce is told to give the form none of its own.
 */
final class VariationsUpFront {

	/**
	 * After the store's and its other plugins' own thresholds, which this
	 * sets for these products alone.
	 */
	private const FILTER_PRIORITY = 100;

	/**
	 * A threshold below any product's number of variations: WooCommerce
	 * reads none of them for the form.
	 */
	private const READ_NONE = -1;

	/**
	 * What the form of each product is handed, by product id, until it is printed.
	 *
	 * @var array<int, list<array<string, mixed>>>
	 */
	private array $handed = array();

	/**
	 * @param InlineProducts $products Resolves a product to its designer.
	 */
	public function __construct(
		private readonly InlineProducts $products,
	) {
	}

	public function register(): void {
		add_filter( 'woocommerce_ajax_variation_threshold', array( $this, 'threshold' ), self::FILTER_PRIORITY, 2 );
		add_action( 'woocommerce_before_variations_form', array( $this, 'hand_over' ) );
	}

	/**
	 * The number of variations up to which WooCommerce gives the form them
	 * all. On a product the designer mounts on, none: the form is handed
	 * them from what is kept. With none to hand, at least the product's
	 * own count, as WooCommerce says a product it gives no variations of
	 * is unavailable.
	 *
	 * @param mixed $threshold The threshold so far.
	 * @param mixed $product   The product the form is for.
	 */
	public function threshold( $threshold, $product ): int {
		$threshold = is_numeric( $threshold ) ? (int) $threshold : 0;
		if ( ! $product instanceof WC_Product_Variable || null === $this->products->for_product( $product ) ) {
			return $threshold;
		}
		$variations = ProductPageCache::remember( $product, 'variations', '', static fn (): array => array_values( $product->get_available_variations() ) );
		if ( array() === $variations ) {
			return max( $threshold, count( $product->get_children() ) );
		}
		/** @var list<array<string, mixed>> $variations */
		$this->handed[ $product->get_id() ] = $variations;
		return self::READ_NONE;
	}

	/**
	 * Prints, at the top of the form, the script that hands it its
	 * variations: WooCommerce's script reads them from the form's
	 * `data-product_variations` once the page has loaded.
	 */
	public function hand_over(): void {
		$product = $GLOBALS['product'] ?? null;
		if ( ! $product instanceof WC_Product || ! isset( $this->handed[ $product->get_id() ] ) ) {
			return;
		}
		$variations = $this->handed[ $product->get_id() ];
		unset( $this->handed[ $product->get_id() ] );
		// Encoded twice, as the attribute's text, with no "<" a block template's texturizing would take for a tag.
		$json = (string) wp_json_encode( (string) wp_json_encode( $variations ), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT );
		wp_print_inline_script_tag( sprintf( '(function(s){(s.closest("form")||s.parentNode).setAttribute("data-product_variations",%s);s.remove()})(document.currentScript)', $json ) );
	}
}
