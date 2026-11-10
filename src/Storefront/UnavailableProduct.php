<?php
/**
 * The product page of a product whose Pixonaut template is not live.
 *
 * @package Pixonaut\WooCommerce
 */

declare(strict_types=1);

namespace Pixonaut\WooCommerce\Storefront;

use Pixonaut\WooCommerce\Catalogue\NotLiveProducts;
use Pixonaut\WooCommerce\Labels;
use WC_Product;
use WP_Block;

/**
 * A product left out of the listings because its template is not live still
 * has its page, which offers no add to cart (see `Purchasability`). The page
 * says the product is not available right now, where the add-to-cart form
 * would be. A signed-in user who can edit the product also reads why
 * shoppers do not see it, so the page serves them as a preview.
 */
final class UnavailableProduct {

	/**
	 * Where WooCommerce's classic template prints the add-to-cart form on
	 * `woocommerce_single_product_summary`; added later, the message follows it.
	 */
	private const SUMMARY_FORM_PRIORITY = 30;

	/**
	 * The blocks that print the add-to-cart form on a block theme's product page.
	 */
	private const FORM_BLOCKS = array( 'woocommerce/add-to-cart-form', 'woocommerce/add-to-cart-with-options' );

	/**
	 * Arguments of `render_block_{$name}`: content, block, instance.
	 */
	private const BLOCK_ARGS = 3;

	/**
	 * Products whose message this request printed, by id.
	 *
	 * @var array<int, true>
	 */
	private array $printed = array();

	/**
	 * @param Labels $labels Customer-facing texts.
	 */
	public function __construct( private readonly Labels $labels ) {
	}

	public function register(): void {
		add_action( 'woocommerce_single_product_summary', array( $this, 'render_classic' ), self::SUMMARY_FORM_PRIORITY );
		foreach ( self::FORM_BLOCKS as $block ) {
			add_filter( 'render_block_' . $block, array( $this, 'after_form_block' ), 10, self::BLOCK_ARGS );
		}
	}

	public function render_classic(): void {
		global $product;
		if ( $product instanceof WC_Product ) {
			echo $this->html( $product ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped in html().
		}
	}

	/**
	 * @param mixed         $content  The block's HTML.
	 * @param mixed         $block    The parsed block.
	 * @param WP_Block|null $instance The block, with its context.
	 */
	public function after_form_block( $content, $block, $instance = null ): string {
		unset( $block );
		$post_id = null !== $instance && is_numeric( $instance->context['postId'] ?? null ) ? (int) $instance->context['postId'] : (int) get_the_ID();
		$product = $post_id > 0 ? wc_get_product( $post_id ) : null;
		return (string) $content . ( $product instanceof WC_Product ? $this->html( $product ) : '' );
	}

	/**
	 * The message for a product whose template is not live, once a page;
	 * empty for any other product.
	 *
	 * @param WC_Product $product The product the page shows.
	 */
	public function html( WC_Product $product ): string {
		if ( isset( $this->printed[ $product->get_id() ] ) || ! NotLiveProducts::is_not_live( $product ) ) {
			return '';
		}
		$this->printed[ $product->get_id() ] = true;
		$html                                = '<p class="stock out-of-stock pixonaut-unavailable">' . esc_html( $this->labels->get( Labels::PRODUCT_UNAVAILABLE, array( 'product_id' => $product->get_id() ) ) ) . '</p>';
		if ( current_user_can( 'edit_post', $product->get_id() ) ) {
			$html .= '<p class="pixonaut-unavailable__note">' . esc_html__( 'Shoppers do not see this product in the shop, search or related products, and cannot buy it: its template is paused or not published in Pixonaut. Make the template live in the Pixonaut studio to sell it again.', 'pixonaut-for-woocommerce' ) . '</p>';
		}
		return '<div class="pixonaut-unavailable-product">' . $html . '</div>';
	}
}
