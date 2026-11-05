<?php
/**
 * The price a personalised variable product's page opens on.
 *
 * @package Pixonaut\WooCommerce
 */

declare(strict_types=1);

namespace Pixonaut\WooCommerce\Storefront;

use WC_Data_Store;
use WC_Product;
use WC_Product_Variation;

/**
 * On the page of a variable product that shows the inline designer or the
 * Personalise button, the product's price is printed as the price of the variation the add-to-cart
 * form opens on (its chosen attributes, else the product's defaults) rather
 * than the product's range: the storefront script shows that variation's
 * price as soon as it runs, so the page prints what it will show. The
 * range comes back on the page, in `data-pixonaut-product-price`,
 * for when the customer clears the choice, with the choice the price is for in
 * `data-pixonaut-opening-choice`. A form that opens on no
 * variation, or on one without a price, keeps the range.
 */
final class OpeningPrice {

	/**
	 * After the price filters of the store and its other plugins, so the
	 * variation's price replaces the range they wrote.
	 */
	private const FILTER_PRIORITY = 100;

	/**
	 * The variation each product's page opens on, by product id; null for none.
	 *
	 * @var array<int, WC_Product_Variation|null>
	 */
	private array $opening = array();

	/**
	 * The attribute values each opening variation was found for, by product
	 * id, then by field name; a product opening on none has no entry.
	 *
	 * @var array<int, array<string, string>>
	 */
	private array $opening_choices = array();

	/**
	 * While the product's own price is read, which the filter leaves alone.
	 *
	 * @var bool
	 */
	private bool $reading_own = false;

	/**
	 * @param InlineProducts $products Resolves a product to its designer.
	 * @param ButtonProducts $buttons  Resolves a product to the Personalise button.
	 */
	public function __construct(
		private readonly InlineProducts $products,
		private readonly ButtonProducts $buttons,
	) {
	}

	public function register(): void {
		add_filter( 'woocommerce_get_price_html', array( $this, 'price_html' ), self::FILTER_PRIORITY, 2 );
	}

	/**
	 * The product page's price as the opening variation's.
	 *
	 * @param string $html    The price HTML.
	 * @param mixed  $product The product it is the price of.
	 */
	public function price_html( $html, $product ): string {
		if ( $this->reading_own || ! $product instanceof WC_Product || ! $this->is_page_product( $product ) ) {
			return (string) $html;
		}
		$variation = $this->opening_variation( $product );
		$own       = null === $variation ? '' : $variation->get_price_html();
		return '' === $own ? (string) $html : $own;
	}

	/**
	 * The product's own price, a variable product's range, when the page
	 * prints another in its place; empty when it prints its own.
	 *
	 * @param WC_Product $product The product the form is for.
	 */
	public function replaced_price( WC_Product $product ): string {
		if ( ! $this->is_page_product( $product ) || null === $this->opening_variation( $product ) ) {
			return '';
		}
		$this->reading_own = true;
		try {
			$own = $product->get_price_html();
		} finally {
			$this->reading_own = false;
		}
		return $own === $product->get_price_html() ? '' : $own;
	}

	/**
	 * The attribute values the page prints the opening variation's price for,
	 * by field name; empty when it prints the product's own. The script holds
	 * that price while the form holds this choice, until WooCommerce's script
	 * has found the variation.
	 *
	 * @param WC_Product $product The product the form is for.
	 * @return array<string, string>
	 */
	public function opening_choice( WC_Product $product ): array {
		return '' === $this->replaced_price( $product ) ? array() : ( $this->opening_choices[ $product->get_id() ] ?? array() );
	}

	/**
	 * The id of the variation the add-to-cart form opens on; 0 for none.
	 *
	 * @param WC_Product $product The product the form is for.
	 */
	public function opening_variation_id( WC_Product $product ): int {
		return $this->is_page_product( $product ) ? ( $this->opening_variation( $product )?->get_id() ?? 0 ) : 0;
	}

	/**
	 * Prints the page at a variation's price, whatever its address chooses, for
	 * a page with no add-to-cart form to choose one in.
	 *
	 * @param int                  $product_id The variable product.
	 * @param WC_Product_Variation $variation  The variation.
	 */
	public function open_on( int $product_id, WC_Product_Variation $variation ): void {
		$this->opening[ $product_id ] = $variation;
	}

	/**
	 * Whether the variation the add-to-cart form opens on lets its quantity
	 * be changed: the block form then shows its quantity selector from the
	 * start, which it would otherwise hide until its script has chosen that
	 * variation.
	 *
	 * @param WC_Product $product The product the form is for.
	 */
	public function opens_with_quantity( WC_Product $product ): bool {
		if ( ! $this->is_page_product( $product ) ) {
			return false;
		}
		$variation = $this->opening_variation( $product );
		return null !== $variation && ! $variation->is_sold_individually();
	}

	/**
	 * Whether `$product` is the variable product the page shows.
	 *
	 * @param WC_Product $product A product.
	 */
	private function is_page_product( WC_Product $product ): bool {
		return $product->is_type( 'variable' ) && is_product() && get_queried_object_id() === $product->get_id();
	}

	/**
	 * The variation the add-to-cart form opens on, when the page shows the
	 * product's inline designer or the Personalise button; null for none.
	 *
	 * @param WC_Product $product A variable product.
	 */
	private function opening_variation( WC_Product $product ): ?WC_Product_Variation {
		$id = $product->get_id();
		if ( array_key_exists( $id, $this->opening ) ) {
			return $this->opening[ $id ];
		}
		$this->opening[ $id ] = null;
		$followed             = $this->followed_variations( $product );
		if ( array() === $followed ) {
			return null;
		}
		$chosen = self::chosen_attributes( $product );
		if ( null === $chosen ) {
			return null;
		}
		/**
		 * The product data store, which answers through `WC_Data_Store::__call()`.
		 *
		 * @var \WC_Product_Data_Store_Interface $data_store
		 */
		$data_store           = WC_Data_Store::load( 'product' );
		$variation_id         = (int) $data_store->find_matching_product_variation( $product, $chosen );
		$variation            = $variation_id > 0 && isset( $followed[ $variation_id ] ) ? wc_get_product( $variation_id ) : null;
		$this->opening[ $id ] = $variation instanceof WC_Product_Variation && self::offered( $variation ) ? $variation : null;
		if ( null !== $this->opening[ $id ] ) {
			$this->opening_choices[ $id ] = $chosen;
		}
		return $this->opening[ $id ];
	}

	/**
	 * The variations whose price the page follows, keyed by variation id.
	 *
	 * @param WC_Product $product A variable product.
	 * @return array<int, mixed>
	 */
	private function followed_variations( WC_Product $product ): array {
		$designer = $this->products->for_product( $product );
		if ( null !== $designer ) {
			return $designer->variations;
		}
		$variants = $this->buttons->variants( $product );
		return null === $this->buttons->page_config( $product, $variants ) ? array() : $variants;
	}

	/**
	 * Whether the add-to-cart form offers the variation, as
	 * `WC_Product_Variable::get_available_variations()` decides: a variation
	 * out of stock while the store hides those, or one not visible, is left
	 * out of the form, which then opens on none.
	 *
	 * @param WC_Product_Variation $variation A variation.
	 */
	private static function offered( WC_Product_Variation $variation ): bool {
		if ( 'yes' === get_option( 'woocommerce_hide_out_of_stock_items' ) && ! $variation->is_in_stock() ) {
			return false;
		}
		return $variation->exists() && $variation->variation_is_visible();
	}

	/**
	 * The value the form opens each attribute on, by its field name, as
	 * `wc_dropdown_variation_attribute_options()` chooses it: the request's,
	 * else the product's default; null when one opens on no value.
	 *
	 * @param WC_Product $product A variable product.
	 * @return array<string, string>|null
	 */
	private static function chosen_attributes( WC_Product $product ): ?array {
		if ( ! $product instanceof \WC_Product_Variable ) {
			return null;
		}
		$chosen = array();
		foreach ( array_keys( $product->get_variation_attributes() ) as $name ) {
			$field = 'attribute_' . sanitize_title( (string) $name );
			// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read as WooCommerce reads it to choose the option; nothing is changed.
			$value = isset( $_REQUEST[ $field ] ) ? wc_clean( wp_unslash( (string) $_REQUEST[ $field ] ) ) : $product->get_variation_default_attribute( (string) $name );
			if ( ! is_string( $value ) || '' === $value ) {
				return null;
			}
			$chosen[ $field ] = $value;
		}
		return array() === $chosen ? null : $chosen;
	}
}
