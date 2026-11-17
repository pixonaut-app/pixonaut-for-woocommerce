<?php
/**
 * Tells a theme where an archive's index is.
 *
 * @package Pixonaut\WooCommerce
 */

declare(strict_types=1);

namespace Pixonaut\WooCommerce\Storefront;

use Pixonaut\WooCommerce\Catalogue\ArchiveIndexBuild;
use Pixonaut\WooCommerce\Catalogue\ArchiveIndexes;
use Pixonaut\WooCommerce\Schema;
use WP_Term;

/**
 * For a theme that filters archives in the browser (`ArchiveIndexes`), the
 * shop page and each product category and tag page names its index in its
 * head, `<script type="application/json" id="pixonaut-archive-index">`,
 * with the index's address and version and how many cards it shows
 * unfiltered. An archive whose index is not built yet names none, and
 * queues its build. A search names none: its results are not an archive's.
 *
 * WooCommerce's sort menu is worked out per request, through filters other
 * plugins add to; the menu it last showed on an archive is kept for the
 * indexes (`sorts()`).
 */
final class ArchiveIndexDiscovery {

	/**
	 * The element's id.
	 */
	public const ELEMENT_ID = 'pixonaut-archive-index';

	/**
	 * After the filters of the store and its other plugins, so the menu
	 * kept is the one shown.
	 */
	private const SORTS_PRIORITY = PHP_INT_MAX;

	public function register(): void {
		add_action( 'wp_head', array( $this, 'print_element' ) );
		add_filter( 'woocommerce_catalog_orderby', array( $this, 'sorts' ), self::SORTS_PRIORITY );
	}

	/**
	 * The archive the request shows; null for any other page.
	 */
	public static function archive(): ?string {
		if ( is_search() || ! function_exists( 'is_shop' ) ) {
			return null;
		}
		if ( is_shop() ) {
			return ArchiveIndexes::name( null );
		}
		if ( is_product_category() || is_product_tag() ) {
			$term = get_queried_object();
			return $term instanceof WP_Term ? ArchiveIndexes::name( $term ) : null;
		}
		return null;
	}

	/**
	 * Prints the element naming the archive's index.
	 */
	public function print_element(): void {
		$archive = ArchiveIndexes::wanted() ? self::archive() : null;
		if ( null === $archive ) {
			return;
		}
		ArchiveIndexes::visited( $archive );
		$current = ArchiveIndexes::current( $archive );
		if ( null === $current ) {
			return;
		}
		wp_print_inline_script_tag(
			(string) wp_json_encode(
				array(
					'url'     => ArchiveIndexRoute::url( $archive, $current['version'] ),
					'version' => $current['version'],
					'count'   => $current['count'],
				),
				JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP
			),
			array(
				'type' => 'application/json',
				'id'   => self::ELEMENT_ID,
			)
		);
	}

	/**
	 * Keeps the sort menu WooCommerce shows on an archive, leaving it as it is.
	 *
	 * @param mixed $options The menu's labels by `orderby` value.
	 * @return mixed
	 */
	public function sorts( mixed $options ): mixed {
		if ( ! is_array( $options ) || null === self::archive() || ! ArchiveIndexes::wanted() ) {
			return $options;
		}
		$menu = array();
		foreach ( $options as $key => $label ) {
			if ( in_array( (string) $key, ArchiveIndexBuild::SORTS, true ) && is_scalar( $label ) ) {
				$menu[ (string) $key ] = html_entity_decode( wp_strip_all_tags( (string) $label ), ENT_QUOTES, 'UTF-8' );
			}
		}
		// WooCommerce leaves these out of the menu after the filter, as it shows it.
		if ( ! wc_review_ratings_enabled() ) {
			unset( $menu['rating'] );
		}
		if ( 'menu_order' !== get_option( 'woocommerce_default_catalog_orderby', 'menu_order' ) ) {
			unset( $menu['menu_order'] );
		}
		if ( get_option( Schema::ARCHIVE_SORTS_OPTION ) !== $menu ) {
			update_option( Schema::ARCHIVE_SORTS_OPTION, $menu, false );
		}
		return $options;
	}
}
