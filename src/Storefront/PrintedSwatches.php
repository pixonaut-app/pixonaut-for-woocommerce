<?php
/**
 * The variation swatches printed with the page.
 *
 * @package Pixonaut\WooCommerce
 */

declare(strict_types=1);

namespace Pixonaut\WooCommerce\Storefront;

use Pixonaut\WooCommerce\Settings\Settings;
use WC_Product;
use WP_Block;

/**
 * When the store draws variation options as swatches
 * (`AttributeOptions::SWATCHES`) and the designer draws the add-to-cart form's
 * variation fields in its own form (a product with one side), each
 * attribute's field, a classic select or the block form's chips or dropdown,
 * is printed with the swatches the storefront script draws for it, in the
 * field's words and order, `.pixonaut-swatches--printed`, so the field the customer
 * sees does not change when the script takes over. They are not a field of
 * their own: `inert` and hidden from assistive technology. The stylesheet
 * shows them in the select's place only while the printed form
 * (`.pixonaut-form__placeholder`) stands in for the designer's; the script
 * swaps them for live swatches.
 */
final class PrintedSwatches {

	/**
	 * The blocks the block form draws an attribute's options with.
	 */
	private const BLOCK_FIELDS = array( 'woocommerce/product-filter-chips', 'woocommerce/dropdown' );

	/**
	 * The block context holding an attribute's options.
	 */
	private const BLOCK_ITEMS_CONTEXT = 'woocommerce/selectableItems';

	/**
	 * Whether each product's page prints swatches, by product id.
	 *
	 * @var array<int, bool>
	 */
	private array $prints = array();

	/**
	 * @param InlineProducts $products Resolves a product to its designer.
	 * @param Settings       $settings The store's attribute options setting.
	 */
	public function __construct(
		private readonly InlineProducts $products,
		private readonly Settings $settings,
	) {
	}

	public function register(): void {
		add_filter( 'woocommerce_dropdown_variation_attribute_options_html', array( $this, 'with_swatches' ), 10, 2 );
		foreach ( self::BLOCK_FIELDS as $block ) {
			add_filter( 'render_block_' . $block, array( $this, 'block_with_swatches' ), 10, 3 );
		}
	}

	/**
	 * The block form's field of one attribute, followed by its swatches.
	 *
	 * @param string               $content  The block's output.
	 * @param array<string, mixed> $block    The parsed block.
	 * @param WP_Block|null        $instance The block instance, with the attribute's items in its context.
	 */
	public function block_with_swatches( $content, $block, $instance = null ): string {
		global $product;
		$content = (string) $content;
		$items   = $instance instanceof WP_Block ? ( $instance->context[ self::BLOCK_ITEMS_CONTEXT ]['items'] ?? null ) : null;
		// The same blocks draw the shop's filters, with no attribute of a product.
		if ( ! is_array( $items ) || ! $product instanceof WC_Product || ! $this->prints( $product ) ) {
			return $content;
		}
		$choices = array();
		$chosen  = '';
		foreach ( $items as $item ) {
			if ( is_array( $item ) && is_scalar( $item['value'] ?? null ) && is_string( $item['label'] ?? null ) ) {
				$choices[ (string) $item['value'] ] = $item['label'];
				$chosen                             = true === ( $item['selected'] ?? null ) ? (string) $item['value'] : $chosen;
			}
		}
		return $content . self::swatches( $choices, $chosen );
	}

	/**
	 * The attribute's select, followed by its swatches.
	 *
	 * @param string $html The select.
	 * @param mixed  $args `wc_dropdown_variation_attribute_options()`'s arguments.
	 */
	public function with_swatches( $html, $args ): string {
		$html    = (string) $html;
		$product = is_array( $args ) ? ( $args['product'] ?? null ) : null;
		if ( ! $product instanceof WC_Product || ! $this->prints( $product ) ) {
			return $html;
		}
		return $html . self::swatches( self::choices( $html ), (string) ( $args['selected'] ?? '' ) );
	}

	/**
	 * The printed swatches of `$choices`; empty for no choice.
	 *
	 * @param array<string, string> $choices Each value's words, in order.
	 * @param string                $chosen  The value the field opens on.
	 */
	private static function swatches( array $choices, string $chosen ): string {
		$swatches = '';
		foreach ( $choices as $value => $label ) {
			$swatches .= '<span class="' . esc_attr( 'pixonaut-swatches__swatch' . ( (string) $value === $chosen ? ' is-chosen' : '' ) ) . '">' . esc_html( $label ) . '</span>';
		}
		return '' === $swatches ? '' : '<div class="pixonaut-swatches pixonaut-swatches--printed" aria-hidden="true" inert>' . $swatches . '</div>';
	}

	/**
	 * Whether the product's page prints its select's swatches: the page is
	 * the product's, the store draws swatches, and its designer draws the
	 * variation fields in its form.
	 *
	 * @param WC_Product $product The product the select is for.
	 */
	private function prints( WC_Product $product ): bool {
		$id = $product->get_id();
		if ( ! isset( $this->prints[ $id ] ) ) {
			$designer            = AttributeOptions::swatches( $this->settings ) && is_product() && get_queried_object_id() === $id ? $this->products->for_product( $product ) : null;
			$this->prints[ $id ] = null !== $designer && $designer->available() && 1 === count( $designer->mounts );
		}
		return $this->prints[ $id ];
	}

	/**
	 * The options the select offers, each value's words, as WooCommerce
	 * printed them in it, in its order; "Choose an option" left out.
	 *
	 * @param string $select The select's markup.
	 * @return array<string, string>
	 */
	private static function choices( string $select ): array {
		$choices = array();
		preg_match_all( '/<option\s+value="([^"]*)"[^>]*>(.*?)<\/option>/s', $select, $options, PREG_SET_ORDER );
		foreach ( $options as $option ) {
			$value = html_entity_decode( $option[1], ENT_QUOTES );
			if ( '' !== $value ) {
				$choices[ $value ] = trim( html_entity_decode( wp_strip_all_tags( $option[2] ), ENT_QUOTES ) );
			}
		}
		return $choices;
	}
}
