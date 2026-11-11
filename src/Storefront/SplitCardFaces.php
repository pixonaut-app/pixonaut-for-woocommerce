<?php
/**
 * The title, image and price a split product's card shows.
 *
 * @package Pixonaut\WooCommerce
 */

declare(strict_types=1);

namespace Pixonaut\WooCommerce\Storefront;

use Pixonaut\WooCommerce\Catalogue\SplitCardLooks;
use Pixonaut\WooCommerce\Catalogue\SplitCards;
use WC_Product;

/**
 * While the shop's loop shows a split product's card (`SplitCardListings`),
 * the product's title, image and price are the card's:
 *
 * - its own title (`SplitCardLooks`), else the product's;
 * - its own image, else the image of the first of its variations that has
 *   one, else the product's;
 * - "From:" its variations' lowest price, or their one price as WooCommerce
 *   prints it when they all cost the same.
 *
 * Themes print a card's title with `get_the_title()`, its image from the
 * product's image id and its price with `get_price_html()`, classic loops
 * and the Product Collection's blocks alike; the card's values replace
 * those. Out of a card, the product is shown as itself.
 */
final class SplitCardFaces {

	/**
	 * After the price filters of the store and its other plugins, so the
	 * card's price replaces the range they wrote.
	 */
	private const PRICE_PRIORITY = 100;

	/**
	 * @param SplitCardListings $listings Knows the card a loop shows.
	 */
	public function __construct( private readonly SplitCardListings $listings ) {
	}

	public function register(): void {
		add_filter( 'the_title', array( $this, 'title' ), 10, 2 );
		add_filter( 'woocommerce_product_get_image_id', array( $this, 'image' ), 10, 2 );
		add_filter( 'woocommerce_get_price_html', array( $this, 'price' ), self::PRICE_PRIORITY, 2 );
	}

	/**
	 * A card's title.
	 *
	 * @param mixed $title   The post's title.
	 * @param mixed $post_id The post.
	 * @return mixed
	 */
	public function title( mixed $title, mixed $post_id = 0 ): mixed {
		$term_id = is_numeric( $post_id ) ? $this->card_of( (int) $post_id ) : 0;
		if ( 0 === $term_id ) {
			return $title;
		}
		$own = SplitCardLooks::of( (int) $post_id, $term_id )['title'];
		return '' === $own ? $title : esc_html( $own );
	}

	/**
	 * A card's image.
	 *
	 * @param mixed $image_id The product's image.
	 * @param mixed $product  The product.
	 * @return mixed
	 */
	public function image( mixed $image_id, mixed $product ): mixed {
		$term_id = $product instanceof WC_Product ? $this->card_of( $product->get_id() ) : 0;
		if ( ! $product instanceof WC_Product || 0 === $term_id ) {
			return $image_id;
		}
		$own = SplitCardLooks::of( $product->get_id(), $term_id )['image'];
		if ( $own > 0 && wp_attachment_is_image( $own ) ) {
			return $own;
		}
		foreach ( SplitCards::card_variations( $product, $term_id ) as $variation_id ) {
			$variation = wc_get_product( $variation_id );
			$image     = $variation instanceof WC_Product ? (int) $variation->get_image_id( 'edit' ) : 0;
			if ( $image > 0 ) {
				return $image;
			}
		}
		return $image_id;
	}

	/**
	 * A card's price.
	 *
	 * @param mixed $html    The product's price HTML.
	 * @param mixed $product The product.
	 * @return mixed
	 */
	public function price( mixed $html, mixed $product ): mixed {
		$term_id = $product instanceof WC_Product ? $this->card_of( $product->get_id() ) : 0;
		if ( ! $product instanceof WC_Product || 0 === $term_id ) {
			return $html;
		}
		$lowest       = null;
		$lowest_price = 0.0;
		$highest      = 0.0;
		foreach ( SplitCards::card_variations( $product, $term_id ) as $variation_id ) {
			$variation = wc_get_product( $variation_id );
			if ( ! $variation instanceof WC_Product || '' === $variation->get_price() ) {
				continue;
			}
			$price   = (float) wc_get_price_to_display( $variation );
			$highest = null === $lowest ? $price : max( $highest, $price );
			if ( null === $lowest || $price < $lowest_price ) {
				$lowest       = $variation;
				$lowest_price = $price;
			}
		}
		if ( null === $lowest ) {
			return $html;
		}
		if ( $lowest_price === $highest ) {
			return $lowest->get_price_html();
		}
		return wc_get_price_html_from_text() . wc_price( $lowest_price ) . $lowest->get_price_suffix();
	}

	/**
	 * The value of the card a loop shows now, when it is one of the
	 * product's; 0 otherwise.
	 *
	 * @param int $product_id A product.
	 */
	private function card_of( int $product_id ): int {
		$current = $this->listings->current();
		return null !== $current && $current[0] === $product_id ? $current[1] : 0;
	}
}
