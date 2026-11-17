<?php
/**
 * No short description in a designer product's purchase panel.
 *
 * @package Pixonaut\WooCommerce
 */

declare(strict_types=1);

namespace Pixonaut\WooCommerce\Storefront;

use WC_Product;
use WP_Block;

/**
 * On the page of a product the inline designer shows on, the purchase
 * panel prints no short description: the designer starts under the title.
 * WooCommerce's own short description is left out of the product's summary
 * (the action that prints it is taken off for that summary alone, as its
 * filter also formats other text, such as a variation's description), and
 * so are the blocks a block theme prints it with, Product Summary and
 * Excerpt, for the product the page is of. Elsewhere, such as in a list of
 * related products, and on a product without the designer, they print as
 * usual.
 */
final class DesignerSummary {

	/** The blocks a block theme prints the short description with. */
	private const BLOCKS = array( 'woocommerce/product-summary', 'core/post-excerpt' );

	/** The summary's action that prints WooCommerce's short description. */
	private const SUMMARY_HOOK     = 'woocommerce_single_product_summary';
	private const EXCERPT          = 'woocommerce_template_single_excerpt';
	private const EXCERPT_PRIORITY = 20;

	/**
	 * Whether the summary printing now has the short description taken off.
	 *
	 * @var bool
	 */
	private bool $taken_off = false;

	/**
	 * @param InlineProducts $products Resolves a product to its designer.
	 */
	public function __construct(
		private readonly InlineProducts $products,
	) {
	}

	public function register(): void {
		add_action( self::SUMMARY_HOOK, array( $this, 'take_off_excerpt' ), self::EXCERPT_PRIORITY - 1 );
		add_action( self::SUMMARY_HOOK, array( $this, 'put_back_excerpt' ), self::EXCERPT_PRIORITY + 1 );
		foreach ( self::BLOCKS as $block ) {
			add_filter( 'render_block_' . $block, array( $this, 'block' ), 10, 3 );
		}
	}

	/**
	 * Takes the short description off the summary printing now, when it is
	 * of the product the page is of and the designer shows on it.
	 */
	public function take_off_excerpt(): void {
		$post = get_post();
		if ( null === $post || ! $this->hides( $post->ID ) || false === has_action( self::SUMMARY_HOOK, self::EXCERPT ) ) {
			return;
		}
		$this->taken_off = remove_action( self::SUMMARY_HOOK, self::EXCERPT, self::EXCERPT_PRIORITY );
	}

	/** Puts the short description back, for any summary printed later. */
	public function put_back_excerpt(): void {
		if ( $this->taken_off ) {
			add_action( self::SUMMARY_HOOK, self::EXCERPT, self::EXCERPT_PRIORITY );
			$this->taken_off = false;
		}
	}

	/**
	 * A short description block: empty for the product the page is of when
	 * the designer shows on it.
	 *
	 * @param mixed $content  The block's HTML.
	 * @param mixed $block    The parsed block.
	 * @param mixed $instance The block, with the post in its context.
	 * @return mixed
	 */
	public function block( mixed $content, mixed $block, mixed $instance = null ): mixed {
		unset( $block );
		$post_id = $instance instanceof WP_Block ? ( $instance->context['postId'] ?? null ) : null;
		return is_numeric( $post_id ) && $this->hides( (int) $post_id ) ? '' : $content;
	}

	/**
	 * Whether the post is the product the page is of, and the designer
	 * shows on it.
	 *
	 * @param int $post_id The post.
	 */
	private function hides( int $post_id ): bool {
		if ( ! is_singular( 'product' ) || get_queried_object_id() !== $post_id ) {
			return false;
		}
		$product = wc_get_product( $post_id );
		return $product instanceof WC_Product && null !== $this->products->for_product( $product );
	}
}
