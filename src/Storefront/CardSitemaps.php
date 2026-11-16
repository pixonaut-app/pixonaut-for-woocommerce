<?php
/**
 * The shop's card pages in its sitemaps.
 *
 * @package Pixonaut\WooCommerce
 */

declare(strict_types=1);

namespace Pixonaut\WooCommerce\Storefront;

use Pixonaut\WooCommerce\Catalogue\CardPages;

/**
 * A sitemap lists a product's page once. Each card of a split product has a
 * page of its own (`CardPages`), which the sitemap lists too: WordPress's
 * own in a sitemap of the cards, and Yoast SEO's and Rank Math's at the end
 * of their first sitemap of products, which is where they take more
 * addresses. Only the cards' pages are listed, never an address choosing a
 * size or another value on top of a card's: those name the card's page as
 * their canonical one.
 */
final class CardSitemaps {

	/**
	 * The most addresses a sitemap may hold, by the sitemap protocol: what
	 * the cards added to an SEO plugin's sitemap of products stay under.
	 */
	private const MOST_ADDRESSES = 50000;

	public function register(): void {
		add_action( 'init', array( $this, 'provide' ) );
		add_filter( 'wpseo_sitemap_product_content', array( $this, 'content' ) );
		add_filter( 'rank_math/sitemap/product_content', array( $this, 'content' ) );
	}

	/**
	 * Adds the cards' sitemap to WordPress's own.
	 */
	public function provide(): void {
		wp_register_sitemap_provider( CardSitemapProvider::NAME, new CardSitemapProvider() );
	}

	/**
	 * The cards' pages, as entries after the products' in an SEO plugin's
	 * sitemap.
	 *
	 * @param mixed $content The entries other plugins added.
	 * @return mixed
	 */
	public function content( mixed $content ): mixed {
		if ( ! is_string( $content ) ) {
			return $content;
		}
		foreach ( CardPages::all( self::MOST_ADDRESSES ) as $page ) {
			$content .= "\t<url>\n\t\t<loc>" . esc_xml( esc_url( $page['loc'] ) ) . "</loc>\n";
			if ( '' !== $page['lastmod'] ) {
				$content .= "\t\t<lastmod>" . esc_xml( $page['lastmod'] ) . "</lastmod>\n";
			}
			$content .= "\t</url>\n";
		}
		return $content;
	}
}
