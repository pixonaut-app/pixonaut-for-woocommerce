<?php
/**
 * @package Pixonaut\WooCommerce
 */

declare(strict_types=1);

namespace Pixonaut\WooCommerce\Tests\Integration\Catalogue;

use Pixonaut\WooCommerce\Catalogue\ArchiveIndexes;
use Pixonaut\WooCommerce\Schema;
use Pixonaut\WooCommerce\Storefront\ArchiveIndexDiscovery;
use Pixonaut\WooCommerce\Storefront\ArchiveIndexRoute;
use Pixonaut\WooCommerce\Tests\Support\ActionSchedulerRunner;
use WC_Product;
use WC_Product_Simple;
use WP_REST_Request;
use WP_REST_Response;
use WP_UnitTestCase;

/**
 * A theme filtering archives in the browser gets each archive's index: the
 * first visit queues its build, the page then names it, the route serves
 * it compressed and cached, and a change to a product gives it a new
 * version. Without the theme's support nothing is built or named.
 */
final class ArchiveIndexesTest extends WP_UnitTestCase {

	private int $category;

	private WC_Product $print;

	public function set_up(): void {
		parent::set_up();
		$category = wp_insert_term( 'Tavlor', 'product_cat' );
		$this->assertIsArray( $category );
		$this->category = (int) $category['term_id'];
		$this->print    = $this->simple( 'Skog', '199' );
		$this->simple( 'Hav', '299' );
		as_unschedule_all_actions( ArchiveIndexes::ACTION );
		add_theme_support( ArchiveIndexes::THEME_SUPPORT );
	}

	public function tear_down(): void {
		remove_theme_support( ArchiveIndexes::THEME_SUPPORT );
		remove_all_filters( 'pixonaut_wc_archive_index_card' );
		remove_all_filters( 'pixonaut_wc_archive_index_variation' );
		as_unschedule_all_actions( ArchiveIndexes::ACTION );
		delete_option( Schema::ARCHIVE_INDEXES_OPTION );
		delete_option( Schema::ARCHIVE_INDEX_BODY_PREFIX . $this->archive() );
		parent::tear_down();
	}

	public function test_a_first_visit_queues_the_build_and_the_page_then_names_the_index(): void {
		$this->assertSame( '', $this->head(), 'nothing to name before the build' );
		$this->assertTrue( as_has_scheduled_action( ArchiveIndexes::ACTION ), 'the visit queued the build' );

		ActionSchedulerRunner::run_pending( ArchiveIndexes::ACTION );
		$named = $this->named();

		$current = ArchiveIndexes::current( $this->archive() );
		$this->assertNotNull( $current );
		$this->assertSame( $current['version'], $named['version'] );
		$this->assertSame( 2, $named['count'] );
		$this->assertSame( ArchiveIndexRoute::url( $this->archive(), $current['version'] ), $named['url'] );
	}

	public function test_the_route_serves_the_index_compressed_cached_and_by_version(): void {
		$version = $this->built();

		$response = $this->get( $version, 'gzip, deflate' );
		$this->assertSame( 200, $response->get_status() );
		$headers = $response->get_headers();
		$this->assertSame( 'gzip', $headers['Content-Encoding'] ?? null );
		$this->assertSame( 'public, max-age=31536000, immutable', $headers['Cache-Control'] );
		$this->assertSame( '"' . $version . '"', $headers['ETag'] );
		$index = json_decode( (string) gzdecode( (string) $response->get_data() ), true );
		$this->assertIsArray( $index );
		$this->assertSame( $version, $index['version'] );
		$this->assertSame( array( 'Hav', 'Skog' ), array_column( $index['cards'], 'title' ), 'in the store\'s default order: by name' );

		$plain = $this->get( $version, 'identity' );
		$this->assertArrayNotHasKey( 'Content-Encoding', $plain->get_headers() );
		$this->assertSame( $index, json_decode( (string) $plain->get_data(), true ) );

		$this->assertSame( 'public, max-age=60', $this->get( 'old', 'gzip' )->get_headers()['Cache-Control'], 'an older version answers the current index, briefly' );
		$this->assertSame( 304, $this->get( $version, 'gzip', '"' . $version . '"' )->get_status() );

		$request = new WP_REST_Request( 'GET', '/pixonaut/v1/archive-index/product_tag-999999' );
		$this->assertSame( 404, rest_get_server()->dispatch( $request )->get_status(), 'an archive without an index' );
	}

	public function test_a_price_change_gives_the_index_a_new_version_and_an_unchanged_build_keeps_it(): void {
		$version = $this->built();

		do_action( ArchiveIndexes::ACTION );
		$this->assertSame( $version, ArchiveIndexes::current( $this->archive() )['version'] ?? null, 'nothing changed' );

		$this->print->set_regular_price( '249' );
		$this->print->save();
		$this->assertTrue( as_has_scheduled_action( ArchiveIndexes::ACTION ), 'the change queued a build' );
		ActionSchedulerRunner::run_pending( ArchiveIndexes::ACTION );

		$changed = ArchiveIndexes::current( $this->archive() )['version'] ?? null;
		$this->assertNotSame( $version, $changed );
		$index = json_decode( (string) gzdecode( (string) ArchiveIndexes::body( $this->archive() ) ), true );
		$this->assertIsArray( $index );
		$this->assertSame( 249.0, (float) $index['cards'][1]['variations'][0]['price'] );
	}

	public function test_a_theme_adds_its_own_fields_to_cards_and_variations(): void {
		add_filter(
			'pixonaut_wc_archive_index_card',
			static function ( array $card, WC_Product $product ): array {
				$card['sku'] = $product->get_sku();
				return $card;
			},
			10,
			2
		);
		$this->print->set_sku( 'SKOG-1' );
		$this->print->save();

		$this->built();
		$index = json_decode( (string) gzdecode( (string) ArchiveIndexes::body( $this->archive() ) ), true );
		$this->assertIsArray( $index );
		$this->assertSame( 'SKOG-1', $index['cards'][1]['sku'] );
	}

	public function test_without_the_themes_support_nothing_is_built_or_named(): void {
		remove_theme_support( ArchiveIndexes::THEME_SUPPORT );

		$this->assertSame( '', $this->head() );
		$this->assertFalse( as_has_scheduled_action( ArchiveIndexes::ACTION ) );
		do_action( ArchiveIndexes::ACTION );
		$this->assertNull( ArchiveIndexes::current( $this->archive() ) );
	}

	public function test_the_sort_menu_woocommerce_shows_is_kept_for_the_index(): void {
		$this->go_to( $this->page() );
		update_option( 'woocommerce_enable_review_rating', 'no' );
		$options = array(
			'menu_order' => 'Standard',
			'popularity' => 'Popularitet',
			'rating'     => 'Betyg',
			'price'      => 'Pris: lägst först',
		);
		$this->assertSame( $options, ( new ArchiveIndexDiscovery() )->sorts( $options ), 'the menu as it was' );
		$this->assertSame(
			array(
				'menu_order' => 'Standard',
				'popularity' => 'Popularitet',
				'price'      => 'Pris: lägst först',
			),
			get_option( Schema::ARCHIVE_SORTS_OPTION ),
			'without ratings WooCommerce leaves rating out'
		);
		delete_option( 'woocommerce_enable_review_rating' );
		delete_option( Schema::ARCHIVE_SORTS_OPTION );
	}

	/**
	 * Builds the category's index after a visit, and answers its version.
	 */
	private function built(): string {
		$this->head();
		do_action( ArchiveIndexes::ACTION );
		as_unschedule_all_actions( ArchiveIndexes::ACTION );
		$current = ArchiveIndexes::current( $this->archive() );
		$this->assertNotNull( $current );
		return $current['version'];
	}

	/**
	 * Asks the route for the category's index.
	 *
	 * @param string      $version   The version asked for.
	 * @param string      $encodings The request's `Accept-Encoding`.
	 * @param string|null $etag      The request's `If-None-Match`.
	 */
	private function get( string $version, string $encodings, ?string $etag = null ): WP_REST_Response {
		$request = new WP_REST_Request( 'GET', '/pixonaut/v1/archive-index/' . $this->archive() );
		$request->set_query_params( array( 'v' => $version ) );
		$request->set_header( 'Accept-Encoding', $encodings );
		if ( null !== $etag ) {
			$request->set_header( 'If-None-Match', $etag );
		}
		$response = rest_get_server()->dispatch( $request );
		$this->assertInstanceOf( WP_REST_Response::class, $response );
		return $response;
	}

	/**
	 * What the category page's head names.
	 *
	 * @return array{url: string, version: string, count: int}
	 */
	private function named(): array {
		$head = $this->head();
		$this->assertSame( 1, preg_match( '#<script id="pixonaut-archive-index" type="application/json">(.*?)</script>#s', $head, $found ), $head );
		$named = json_decode( $found[1] ?? '', true );
		$this->assertIsArray( $named );
		$this->assertIsString( $named['url'] ?? null );
		$this->assertIsString( $named['version'] ?? null );
		$this->assertIsInt( $named['count'] ?? null );
		return array(
			'url'     => $named['url'],
			'version' => $named['version'],
			'count'   => $named['count'],
		);
	}

	/**
	 * The category page's head, as the plugin prints its part of it.
	 */
	private function head(): string {
		$this->go_to( $this->page() );
		ob_start();
		( new ArchiveIndexDiscovery() )->print_element();
		return (string) ob_get_clean();
	}

	private function page(): string {
		$page = get_term_link( $this->category, 'product_cat' );
		$this->assertIsString( $page );
		return $page;
	}

	private function archive(): string {
		return 'product_cat-' . $this->category;
	}

	private function simple( string $name, string $price ): WC_Product {
		$product = new WC_Product_Simple();
		$product->set_name( $name );
		$product->set_status( 'publish' );
		$product->set_category_ids( array( $this->category ) );
		$product->set_regular_price( $price );
		$product->save();
		return $product;
	}
}
