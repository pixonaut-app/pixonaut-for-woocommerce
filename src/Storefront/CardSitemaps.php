<?php
/**
 * The shop's card pages in its sitemaps.
 *
 * @package Pixonaut\WooCommerce
 */

declare(strict_types=1);

namespace Pixonaut\WooCommerce\Storefront;

use WP_Sitemaps_Renderer;

/**
 * A sitemap lists a product's page once. Each card of a split product has a
 * page of its own (`CardPages`), which the sitemap lists too, in a sitemap
 * of the cards (`CardSitemapProvider`): WordPress's own index names it, and
 * so do Yoast SEO's and Rank Math's, which replace WordPress's sitemaps with
 * theirs. For those the plugin serves the cards' sitemap itself, a page at a
 * time, at `/?pixonaut_wc_card_sitemap=<page>`. Only the cards' pages are
 * listed, never an address choosing a size or another value on top of a
 * card's: those name the card's page as their canonical one.
 */
final class CardSitemaps {

	/**
	 * The query parameter naming a page of the cards' sitemap the plugin serves.
	 */
	public const PAGE_ARG = 'pixonaut_wc_card_sitemap';

	/**
	 * What a missing page of the sitemap answers.
	 */
	private const NOT_FOUND = 404;

	public function register(): void {
		add_action( 'init', array( $this, 'provide' ) );
		add_filter( 'wpseo_sitemap_index', array( $this, 'index' ) );
		add_filter( 'rank_math/sitemap/index', array( $this, 'index' ) );
		add_action( 'template_redirect', array( $this, 'serve' ) );
	}

	/**
	 * Adds the cards' sitemap to WordPress's own.
	 */
	public function provide(): void {
		wp_register_sitemap_provider( CardSitemapProvider::NAME, new CardSitemapProvider() );
	}

	/**
	 * The address of a page of the cards' sitemap as the plugin serves it.
	 *
	 * @param int $page The page, from 1.
	 */
	public static function url( int $page ): string {
		return add_query_arg( self::PAGE_ARG, (string) $page, home_url( '/' ) );
	}

	/**
	 * An SEO plugin's sitemap index, naming each page of the cards' sitemap
	 * after its own sitemaps.
	 *
	 * @param mixed $index The index entries other plugins added.
	 * @return mixed
	 */
	public function index( mixed $index ): mixed {
		if ( ! is_string( $index ) ) {
			return $index;
		}
		$pages = ( new CardSitemapProvider() )->get_max_num_pages();
		for ( $page = 1; $page <= $pages; $page++ ) {
			$index .= "\t<sitemap>\n\t\t<loc>" . esc_xml( self::url( $page ) ) . "</loc>\n\t</sitemap>\n";
		}
		return $index;
	}

	/**
	 * The page of the cards' sitemap a request asks the plugin for; 0 for
	 * any other request.
	 */
	public static function requested(): int {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- a public sitemap, read by its page number.
		return isset( $_GET[ self::PAGE_ARG ] ) ? absint( wp_unslash( $_GET[ self::PAGE_ARG ] ) ) : 0;
	}

	/**
	 * A page of the cards' sitemap, as XML; null for a page it does not have.
	 *
	 * @param int $page The page, from 1.
	 */
	public static function page( int $page ): ?string {
		$entries = $page > 0 ? ( new CardSitemapProvider() )->get_url_list( $page ) : array();
		if ( array() === $entries ) {
			return null;
		}
		// WordPress's stylesheet is served only by its own sitemaps, which an SEO plugin turns off.
		add_filter( 'wp_sitemaps_stylesheet_url', '__return_empty_string' );
		$xml = ( new WP_Sitemaps_Renderer() )->get_sitemap_xml( $entries );
		remove_filter( 'wp_sitemaps_stylesheet_url', '__return_empty_string' );
		return is_string( $xml ) && '' !== $xml ? $xml : null;
	}

	/**
	 * Answers a request for a page of the cards' sitemap.
	 */
	public function serve(): void {
		$page = self::requested();
		if ( 0 === $page ) {
			return;
		}
		$xml = self::page( $page );
		if ( null === $xml ) {
			status_header( self::NOT_FOUND );
			nocache_headers();
			exit;
		}
		status_header( 200 );
		header( 'Content-Type: application/xml; charset=UTF-8' );
		header( 'X-Robots-Tag: noindex, follow' );
		// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- XML escaped by WordPress's sitemap renderer.
		echo $xml;
		exit;
	}
}
