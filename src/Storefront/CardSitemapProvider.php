<?php
/**
 * WordPress's sitemap of the shop's card pages.
 *
 * @package Pixonaut\WooCommerce
 */

declare(strict_types=1);

namespace Pixonaut\WooCommerce\Storefront;

use Pixonaut\WooCommerce\Catalogue\CardPages;
use WP_Sitemaps_Provider;

/**
 * Lists every card's page (`CardPages`) in WordPress's own sitemap, beside
 * the products' pages its post provider lists.
 */
final class CardSitemapProvider extends WP_Sitemaps_Provider {

	/**
	 * The provider's name in the sitemap's address,
	 * `wp-sitemap-pixonautcards-1.xml`: WordPress takes lowercase letters only.
	 */
	public const NAME = 'pixonautcards';

	public function __construct() {
		$this->name        = self::NAME;
		$this->object_type = self::NAME;
	}

	/**
	 * A page of the sitemap.
	 *
	 * @param int    $page_num       The page, from 1.
	 * @param string $object_subtype Unused: the cards have no subtypes.
	 * @return array<int, array{loc: string, lastmod?: string}>
	 */
	public function get_url_list( $page_num, $object_subtype = '' ) {
		unset( $object_subtype );
		$per_page = wp_sitemaps_get_max_urls( self::NAME );
		$entries  = array();
		foreach ( CardPages::listed( $per_page, ( max( 1, (int) $page_num ) - 1 ) * $per_page ) as $page ) {
			$entries[] = '' === $page['lastmod'] ? array( 'loc' => $page['loc'] ) : $page;
		}
		return $entries;
	}

	/**
	 * How many pages the sitemap has.
	 *
	 * @param string $object_subtype Unused: the cards have no subtypes.
	 * @return int
	 */
	public function get_max_num_pages( $object_subtype = '' ) {
		unset( $object_subtype );
		return (int) ceil( CardPages::count() / wp_sitemaps_get_max_urls( self::NAME ) );
	}
}
