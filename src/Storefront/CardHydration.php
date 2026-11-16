<?php
/**
 * What a product card loads into the page's product store.
 *
 * @package Pixonaut\WooCommerce
 */

declare(strict_types=1);

namespace Pixonaut\WooCommerce\Storefront;

use WC_Product;
use WP_REST_Request;
use WP_REST_Response;

/**
 * A Product Template block (the cards of a Product Collection: the shop, a
 * category, related products) loads each card's product into the
 * Interactivity API's `woocommerce/products` store, whether or not anything
 * on the card reads it: the Store API's whole answer for the product, which
 * for a variable product lists every variation and asks each whether it can
 * be bought. On a product with 72 variations that is several queries over
 * every variation's meta and some 20 kB of page per card.
 *
 * A card none of whose markup reads the store, but the Product Collection's
 * own directives (whose script reads the product's id alone), gets its
 * product's identity instead: id, name, slug, parent, type and link. A card
 * with any other Interactivity directive, such as an add to cart button,
 * gets WooCommerce's whole answer, as does the product a product page shows,
 * which its own blocks read.
 */
final class CardHydration {

	/**
	 * The block whose cards are watched.
	 */
	private const TEMPLATE_BLOCK = 'woocommerce/product-template';

	/**
	 * The block a Product Template renders each card's inner blocks as.
	 */
	private const CARD_BLOCK = 'core/null';

	/**
	 * The Store API route a card's product is loaded through, before its id.
	 */
	private const PRODUCT_PATH = '/wc/store/v1/products/';

	/**
	 * The Product Collection's store, the only one a card light on its
	 * product may name.
	 */
	private const COLLECTION_STORE = 'woocommerce/product-collection';

	/**
	 * How deep Product Template blocks are nested while one renders.
	 *
	 * @var int
	 */
	private int $depth = 0;

	/**
	 * The product of the card just rendered, while its markup reads nothing
	 * of it but its id.
	 *
	 * @var int|null
	 */
	private ?int $light = null;

	public function register(): void {
		add_filter( 'pre_render_block', array( $this, 'enter' ), 10, 2 );
		add_filter( 'render_block_' . self::TEMPLATE_BLOCK, array( $this, 'leave' ) );
		add_filter( 'render_block', array( $this, 'card' ), 10, 2 );
		add_filter( 'woocommerce_hydration_dispatch_request', array( $this, 'dispatch' ), 10, 3 );
	}

	/**
	 * Notes a Product Template block starting to render.
	 *
	 * @param mixed $pre    Markup another filter rendered the block as, or null.
	 * @param mixed $parsed The block.
	 * @return mixed
	 */
	public function enter( mixed $pre, mixed $parsed ): mixed {
		if ( null === $pre && is_array( $parsed ) && self::TEMPLATE_BLOCK === ( $parsed['blockName'] ?? null ) ) {
			++$this->depth;
		}
		return $pre;
	}

	/**
	 * Notes a Product Template block done rendering.
	 *
	 * @param mixed $content The block's markup.
	 * @return mixed
	 */
	public function leave( mixed $content ): mixed {
		$this->depth = max( 0, $this->depth - 1 );
		$this->light = null;
		return $content;
	}

	/**
	 * Notes whether the card just rendered reads its product, before the
	 * Product Template loads the product.
	 *
	 * @param mixed $content The block's markup.
	 * @param mixed $parsed  The block.
	 * @return mixed
	 */
	public function card( mixed $content, mixed $parsed ): mixed {
		if ( $this->depth > 0 && is_string( $content ) && is_array( $parsed ) && self::CARD_BLOCK === ( $parsed['blockName'] ?? null ) ) {
			$product_id  = (int) get_the_ID();
			$this->light = $product_id > 0 && ! self::reads_product( $content ) && ! self::is_page_product( $product_id ) ? $product_id : null;
		}
		return $content;
	}

	/**
	 * The identity of the product of a card that reads nothing else of it,
	 * in place of the Store API's whole answer.
	 *
	 * @param mixed $result  An answer another filter gave, or null.
	 * @param mixed $request The request.
	 * @param mixed $path    The path it was matched by.
	 * @return mixed
	 */
	public function dispatch( mixed $result, mixed $request, mixed $path ): mixed {
		$light       = $this->light;
		$this->light = null;
		if ( null !== $result || null === $light || ! $request instanceof WP_REST_Request || self::PRODUCT_PATH . $light !== $path ) {
			return $result;
		}
		$product = wc_get_product( $light );
		if ( ! $product instanceof WC_Product ) {
			return $result;
		}
		return new WP_REST_Response(
			array(
				'id'        => $product->get_id(),
				'name'      => $product->get_title(),
				'slug'      => $product->get_slug(),
				'parent'    => $product->get_parent_id(),
				'type'      => $product->get_type(),
				'permalink' => $product->get_permalink(),
			)
		);
	}

	/**
	 * Whether a card's markup has an Interactivity directive that may read
	 * its product: any but the Product Collection's own.
	 *
	 * @param string $content A card's markup.
	 */
	private static function reads_product( string $content ): bool {
		if ( ! str_contains( $content, 'data-wp-' ) ) {
			return false;
		}
		// Another store taking over part of the card.
		if ( str_contains( $content, 'data-wp-interactive' ) ) {
			return true;
		}
		// A directive naming a store other than the Product Collection's; one naming none is the Product Collection's, the card's own.
		preg_match_all( '/\sdata-wp-[a-z0-9-]+="([^"]*)"|\sdata-wp-[a-z0-9-]+=\'([^\']*)\'/i', $content, $directives, PREG_SET_ORDER );
		foreach ( $directives as $directive ) {
			$value = html_entity_decode( ( $directive[1] ?? '' ) . ( $directive[2] ?? '' ), ENT_QUOTES );
			if ( 1 === preg_match( '#^!?\s*([a-z0-9_-]+/[a-z0-9_/-]+)::#i', $value, $store ) && self::COLLECTION_STORE !== $store[1] ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Whether a product is the one the product page being rendered shows,
	 * which the page's own blocks load in full.
	 *
	 * @param int $product_id A product.
	 */
	private static function is_page_product( int $product_id ): bool {
		return is_singular( 'product' ) && get_queried_object_id() === $product_id;
	}
}
