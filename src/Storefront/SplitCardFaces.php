<?php
/**
 * The title, image and price a split product's card shows.
 *
 * @package Pixonaut\WooCommerce
 */

declare(strict_types=1);

namespace Pixonaut\WooCommerce\Storefront;

use Pixonaut\WooCommerce\Catalogue\CardPages;
use Pixonaut\WooCommerce\Catalogue\CardPhotos;
use Pixonaut\WooCommerce\Catalogue\PictureQueue;
use Pixonaut\WooCommerce\Catalogue\ProductMeta;
use Pixonaut\WooCommerce\Catalogue\RequestCache;
use Pixonaut\WooCommerce\Catalogue\SplitCardLooks;
use Pixonaut\WooCommerce\Catalogue\SplitCards;
use WC_Product;
use WC_Product_Attribute;
use WC_Product_Variable;

/**
 * While the shop's loop shows a split product's card (`SplitCardListings`),
 * the product's title, image and price are the card's:
 *
 * - its own title (`SplitCardLooks`), else the product's;
 * - when the filters pick a value the card holds none of, a frame's
 *   colour on a card of a framing, say, the picture of the variation the
 *   filters open it on (`FilteredOpening`) on its blank's photo: the whole
 *   filtered combination, even over the card's own image
 *   (`CardPages::image()`). While that picture is not drawn yet, its
 *   drawing is queued (`CardPhotos`), at most `WANTS_PER_REQUEST` new ones
 *   a request, and the card shows what it shows otherwise meanwhile;
 * - its own image, else the flat picture of its design, or, for a card a
 *   filter on a framing shows, the picture of the variation the filters
 *   open it on, else the product's. While that variation's picture is not
 *   drawn yet, its drawing is queued, and the card shows the picture of
 *   its variation agreeing with most of the filters meanwhile;
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
	 * The filters through which a variation's price can differ from the
	 * one WooCommerce keeps for its product's price range.
	 */
	private const PRICE_FILTERS = array(
		'woocommerce_product_variation_get_price',
		'woocommerce_variation_prices_price',
		'woocommerce_variation_prices',
		'woocommerce_get_price_including_tax',
		'woocommerce_get_price_excluding_tax',
	);

	/**
	 * The most variations a request marks to be drawn on their blank's
	 * photo (`CardPhotos::want()`): a filtered page of cards asks for one
	 * per card at first, and each is a picture job, so the rest wait for
	 * the next requests rather than flooding the picture queue at once.
	 */
	private const WANTS_PER_REQUEST = 4;

	/**
	 * How many variations this request marked to be drawn on their photo.
	 *
	 * @var int
	 */
	private int $wants = 0;

	/**
	 * The variations whose drawing a card checked in this request, by id:
	 * a theme reads a card's image more than once.
	 *
	 * @var array<int, true>
	 */
	private array $checked = array();

	/**
	 * @param SplitCardListings $listings Knows the card a loop shows.
	 * @param PictureQueue      $pictures Queues the drawing of a picture a card waits for.
	 */
	public function __construct(
		private readonly SplitCardListings $listings,
		private readonly PictureQueue $pictures,
	) {
	}

	public function register(): void {
		add_filter( 'the_title', array( $this, 'title' ), 10, 2 );
		add_filter( 'woocommerce_product_get_image_id', array( $this, 'image' ), 10, 2 );
		add_filter( 'woocommerce_get_price_html', array( $this, 'price' ), self::PRICE_PRIORITY, 2 );
		add_action( 'wp', array( $this, 'begin' ) );
	}

	/**
	 * Starts a request's count of the drawings its cards ask for.
	 */
	public function begin(): void {
		$this->checked = array();
		$this->wants   = 0;
	}

	/**
	 * A card's title.
	 *
	 * @param mixed $title   The post's title.
	 * @param mixed $post_id The post.
	 * @return mixed
	 */
	public function title( mixed $title, mixed $post_id = 0 ): mixed {
		$key = is_numeric( $post_id ) ? $this->card_of( (int) $post_id ) : null;
		if ( null === $key ) {
			return $title;
		}
		$own = SplitCardLooks::of( (int) $post_id, $key )['title'];
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
		$key = $product instanceof WC_Product ? $this->card_of( $product->get_id() ) : null;
		if ( ! $product instanceof WC_Product || null === $key ) {
			return $image_id;
		}
		$own = CardPages::image( $product, $key, FilteredOpening::values( $product, $key ), $this->draw( ... ), self::picks_beyond( $product, $key ) );
		if ( $own > 0 ) {
			return $own;
		}
		return $image_id;
	}

	/**
	 * Queues the drawing of the picture a card waits for, once a request:
	 * drawn in a job, as every picture, the card shows it from then on. One
	 * on the variation's blank's photo marks the variation for it first,
	 * up to `WANTS_PER_REQUEST` a request, which queues it.
	 *
	 * @param WC_Product $variation The variation the card opens on.
	 * @param bool       $on_photo  Whether the card waits for its picture on its blank's photo, rather than its image.
	 */
	private function draw( WC_Product $variation, bool $on_photo = false ): void {
		if ( isset( $this->checked[ $variation->get_id() ] ) ) {
			return;
		}
		if ( $on_photo && ! CardPhotos::wanted( $variation ) ) {
			if ( $this->wants >= self::WANTS_PER_REQUEST ) {
				// Asked for again by a later request.
				return;
			}
			++$this->wants;
			$this->checked[ $variation->get_id() ] = true;
			CardPhotos::want( $variation );
			return;
		}
		$this->checked[ $variation->get_id() ] = true;
		$this->pictures->queue_if_due( $variation );
	}

	/**
	 * Whether the request's filters pick a value of an attribute the
	 * product's variations differ by that the card holds none of: a
	 * frame's colour on the card of a framing. A card holding a value the
	 * filter is waived under, such as "no frame" for a frame's colour, is
	 * not picked beyond.
	 *
	 * @param WC_Product $product The card's product.
	 * @param string     $key     The card's key.
	 */
	private static function picks_beyond( WC_Product $product, string $key ): bool {
		$held       = SplitCards::term_ids( $key );
		$attributes = $product->get_attributes();
		$chosen     = FilterMatches::chosen();
		$exempt     = FilterMatches::exempt( $chosen );
		foreach ( $chosen as $taxonomy => $term_ids ) {
			$attribute = $attributes[ $taxonomy ] ?? null;
			if ( $attribute instanceof WC_Product_Attribute && $attribute->get_variation() && array() !== $term_ids && array() === array_intersect( array_merge( $term_ids, $exempt[ $taxonomy ] ?? array() ), $held ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * A card's price.
	 *
	 * @param mixed $html    The product's price HTML.
	 * @param mixed $product The product.
	 * @return mixed
	 */
	public function price( mixed $html, mixed $product ): mixed {
		$key = $product instanceof WC_Product ? $this->card_of( $product->get_id() ) : null;
		if ( ! $product instanceof WC_Product || null === $key ) {
			return $html;
		}
		$variation_ids = SplitCards::card_variations( $product, $key );
		$prices        = self::display_prices( $product );
		if ( null === $prices ) {
			// Three queries for the card's variations, their terms and their meta, rather than four for each.
			_prime_post_caches( $variation_ids, true, false );
			ProductMeta::prime( $variation_ids );
		}
		$lowest_id    = null;
		$lowest_price = 0.0;
		$highest      = 0.0;
		foreach ( $variation_ids as $variation_id ) {
			if ( null === $prices ) {
				$variation = wc_get_product( $variation_id );
				if ( ! $variation instanceof WC_Product || '' === $variation->get_price() ) {
					continue;
				}
				$price = (float) wc_get_price_to_display( $variation );
			} elseif ( isset( $prices[ $variation_id ] ) && '' !== $prices[ $variation_id ] ) {
				$price = (float) $prices[ $variation_id ];
			} else {
				continue;
			}
			$highest = null === $lowest_id ? $price : max( $highest, $price );
			if ( null === $lowest_id || $price < $lowest_price ) {
				$lowest_id    = $variation_id;
				$lowest_price = $price;
			}
		}
		$lowest = null === $lowest_id ? null : wc_get_product( $lowest_id );
		if ( ! $lowest instanceof WC_Product ) {
			return $html;
		}
		if ( $lowest_price === $highest ) {
			return $lowest->get_price_html();
		}
		return wc_get_price_html_from_text() . wc_price( $lowest_price ) . $lowest->get_price_suffix();
	}

	/**
	 * Each variation's price as the shop shows it, by variation id, as
	 * WooCommerce keeps them for a variable product's price range; null
	 * while something filters a variation's price, when the card's
	 * variations are read one by one.
	 *
	 * @param WC_Product $product The card's product.
	 * @return array<int|string, mixed>|null
	 */
	private static function display_prices( WC_Product $product ): ?array {
		if ( ! $product instanceof WC_Product_Variable || '' !== RequestCache::filters_key( self::PRICE_FILTERS ) ) {
			return null;
		}
		$prices = $product->get_variation_prices( true );
		return is_array( $prices['price'] ?? null ) ? $prices['price'] : null;
	}

	/**
	 * The key of the card a loop shows now, when it is one of the
	 * product's; null otherwise.
	 *
	 * @param int $product_id A product.
	 */
	private function card_of( int $product_id ): ?string {
		$current = $this->listings->current();
		return null !== $current && $current[0] === $product_id ? $current[1] : null;
	}
}
