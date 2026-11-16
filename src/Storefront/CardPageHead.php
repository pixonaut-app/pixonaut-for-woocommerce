<?php
/**
 * What a card's page says of itself in its head.
 *
 * @package Pixonaut\WooCommerce
 */

declare(strict_types=1);

namespace Pixonaut\WooCommerce\Storefront;

use Pixonaut\WooCommerce\Catalogue\CardPages;
use WC_Product;
use WP_Post;

/**
 * A product's page at an address that names one of its cards (`CardPages`)
 * is that card's page. Its head then names the card, not the product:
 *
 * - the canonical address is the card's, whatever else the address chooses
 *   (a size), so only the card's own page is one to a search engine;
 * - the document title is the card's title, the description starts with the
 *   card's values, and the Open Graph picture is the card's.
 *
 * WordPress prints the title and the canonical link; the plugin prints the
 * description and the Open Graph tags itself. With Yoast SEO or Rank Math
 * active, theirs are changed instead and the plugin prints none. The
 * product's own address is left as it is.
 */
final class CardPageHead {

	/**
	 * Rank Math's filters of the tags naming a page's title for a social network.
	 */
	private const RANK_MATH_TITLES = array( 'rank_math/opengraph/facebook/og_title', 'rank_math/opengraph/twitter/twitter_title' );

	/**
	 * Rank Math's filters of the tags describing a page for a social network.
	 */
	private const RANK_MATH_DESCRIPTIONS = array( 'rank_math/opengraph/facebook/og_description', 'rank_math/opengraph/twitter/twitter_description' );

	/**
	 * Rank Math's filters of the tags naming a page's picture for a social network.
	 */
	private const RANK_MATH_IMAGES = array( 'rank_math/opengraph/facebook/og_image', 'rank_math/opengraph/facebook/og_image_secure_url', 'rank_math/opengraph/twitter/twitter_image' );

	/**
	 * Before the theme's and other plugins' tags in the head.
	 */
	private const HEAD_PRIORITY = 2;

	/**
	 * The size of the picture a card's page names for a social network.
	 */
	private const IMAGE_SIZE = 'full';

	/**
	 * The card the request's page is of, once looked for: the product and
	 * the card's key, or false for none.
	 *
	 * @var array{0: WC_Product, 1: string}|false|null
	 */
	private array|false|null $card = null;

	public function register(): void {
		add_filter( 'get_canonical_url', array( $this, 'canonical' ), 10, 2 );
		add_filter( 'document_title_parts', array( $this, 'title_parts' ) );
		add_action( 'wp_head', array( $this, 'print_tags' ), self::HEAD_PRIORITY );

		add_filter( 'wpseo_canonical', array( $this, 'address' ) );
		add_filter( 'wpseo_opengraph_url', array( $this, 'address' ) );
		add_filter( 'wpseo_title', array( $this, 'title' ) );
		add_filter( 'wpseo_opengraph_title', array( $this, 'title' ) );
		add_filter( 'wpseo_twitter_title', array( $this, 'title' ) );
		add_filter( 'wpseo_metadesc', array( $this, 'description' ) );
		add_filter( 'wpseo_opengraph_desc', array( $this, 'description' ) );
		add_filter( 'wpseo_twitter_description', array( $this, 'description' ) );
		add_filter( 'wpseo_frontend_presentation', array( $this, 'yoast_images' ) );

		add_filter( 'rank_math/frontend/canonical', array( $this, 'address' ) );
		add_filter( 'rank_math/opengraph/facebook/og_url', array( $this, 'address' ) );
		add_filter( 'rank_math/frontend/title', array( $this, 'title' ) );
		add_filter( 'rank_math/frontend/description', array( $this, 'description' ) );
		foreach ( self::RANK_MATH_TITLES as $filter ) {
			add_filter( $filter, array( $this, 'title' ) );
		}
		foreach ( self::RANK_MATH_DESCRIPTIONS as $filter ) {
			add_filter( $filter, array( $this, 'description' ) );
		}
		foreach ( self::RANK_MATH_IMAGES as $filter ) {
			add_filter( $filter, array( $this, 'image' ) );
		}
	}

	/**
	 * The canonical address WordPress prints for the product's page.
	 *
	 * @param mixed $url  The post's canonical address.
	 * @param mixed $post The post.
	 * @return mixed
	 */
	public function canonical( mixed $url, mixed $post = null ): mixed {
		$card = $this->card();
		return null === $card || ! $post instanceof WP_Post || $post->ID !== $card[0]->get_id() ? $url : $this->address( $url );
	}

	/**
	 * The address of the card's page, in place of the product's.
	 *
	 * @param mixed $url The page's address, as WordPress or an SEO plugin names it.
	 * @return mixed
	 */
	public function address( mixed $url ): mixed {
		$card = $this->card();
		return null === $card ? $url : CardPages::url( $card[0], $card[1], $card[0]->get_permalink() );
	}

	/**
	 * The document title WordPress writes: the card's in place of the product's.
	 *
	 * @param mixed $parts The title's parts.
	 * @return mixed
	 */
	public function title_parts( mixed $parts ): mixed {
		$card = $this->card();
		if ( null !== $card && is_array( $parts ) ) {
			$parts['title'] = CardPages::title( $card[0], $card[1] );
		}
		return $parts;
	}

	/**
	 * A title an SEO plugin wrote for the product's page, naming the card:
	 * the card's title where it names the product, and alone when the store
	 * wrote a title that does not.
	 *
	 * @param mixed $title The page's title.
	 * @return mixed
	 */
	public function title( mixed $title ): mixed {
		$card = $this->card();
		if ( null === $card || ! is_string( $title ) ) {
			return $title;
		}
		$own  = CardPages::title( $card[0], $card[1] );
		$name = $card[0]->get_name();
		return '' !== $name && str_contains( $title, $name ) ? str_replace( $name, $own, $title ) : $own;
	}

	/**
	 * A description an SEO plugin wrote for the product's page, starting
	 * with the card's values; the card's own when it wrote none.
	 *
	 * @param mixed $description The page's description.
	 * @return mixed
	 */
	public function description( mixed $description ): mixed {
		$card = $this->card();
		return null === $card ? $description : CardPages::description( $card[0], $card[1], is_string( $description ) ? $description : '' );
	}

	/**
	 * The address of the card's picture, in place of the product's; the
	 * product's for a card without one.
	 *
	 * @param mixed $url A picture's address.
	 * @return mixed
	 */
	public function image( mixed $url ): mixed {
		$image = $this->picture();
		return null === $image ? $url : $image['url'];
	}

	/**
	 * Yoast SEO's presentation of the page, with the card's picture as its
	 * Open Graph and Twitter picture.
	 *
	 * @param mixed $presentation The presentation.
	 * @return mixed
	 */
	public function yoast_images( mixed $presentation ): mixed {
		$image = $this->picture();
		if ( null === $image || ! is_object( $presentation ) ) {
			return $presentation;
		}
		$presentation->open_graph_images = array( $image ); // @phpstan-ignore property.notFound (Yoast SEO's presentation, a class the plugin does not ship.)
		$presentation->twitter_image     = $image['url']; // @phpstan-ignore property.notFound (As above.)
		return $presentation;
	}

	/**
	 * Prints the card's description and Open Graph tags, unless an SEO
	 * plugin whose tags the plugin changes prints its own.
	 */
	public function print_tags(): void {
		$card = $this->card();
		if ( null === $card || defined( 'WPSEO_VERSION' ) || defined( 'RANK_MATH_VERSION' ) ) {
			return;
		}
		$title       = CardPages::title( $card[0], $card[1] );
		$description = CardPages::description( $card[0], $card[1] );
		$image       = $this->picture();
		if ( '' !== $description ) {
			printf( '<meta name="description" content="%s" />' . "\n", esc_attr( $description ) );
		}
		$tags = array(
			'og:type'        => 'product',
			'og:title'       => $title,
			'og:description' => $description,
			'og:url'         => (string) $this->address( '' ),
			'og:image'       => null === $image ? '' : $image['url'],
		);
		foreach ( $tags as $property => $content ) {
			if ( '' !== $content ) {
				printf( '<meta property="%s" content="%s" />' . "\n", esc_attr( $property ), esc_attr( $content ) );
			}
		}
	}

	/**
	 * The card's picture, or the product's for a card without one; null
	 * when the page is no card's, or neither has a picture.
	 *
	 * @return array{url: string, width: int, height: int}|null
	 */
	private function picture(): ?array {
		$card = $this->card();
		if ( null === $card ) {
			return null;
		}
		$own   = CardPages::image( $card[0], $card[1] );
		$image = wp_get_attachment_image_src( $own > 0 ? $own : (int) $card[0]->get_image_id(), self::IMAGE_SIZE );
		if ( ! is_array( $image ) ) {
			return null;
		}
		return array(
			'url'    => (string) $image[0],
			'width'  => (int) $image[1],
			'height' => (int) $image[2],
		);
	}

	/**
	 * The card the request's page is of: a product's own page whose address
	 * names one of its cards. Null on any other page.
	 *
	 * @return array{0: WC_Product, 1: string}|null
	 */
	private function card(): ?array {
		// The conditional tags answer nothing before the main query has run.
		if ( null === $this->card && did_action( 'wp' ) > 0 ) {
			$this->card = false;
			$product    = is_product() ? wc_get_product( get_queried_object_id() ) : null;
			$key        = $product instanceof WC_Product ? CardPages::named_by( $product, OpeningChoice::requested( $product ) ) : null;
			if ( $product instanceof WC_Product && null !== $key ) {
				$this->card = array( $product, $key );
			}
		}
		return is_array( $this->card ) ? $this->card : null;
	}
}
