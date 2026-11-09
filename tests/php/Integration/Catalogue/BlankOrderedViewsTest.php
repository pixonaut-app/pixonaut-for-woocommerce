<?php
/**
 * @package Pixonaut\WooCommerce
 */

declare(strict_types=1);

namespace Pixonaut\WooCommerce\Tests\Integration\Catalogue;

use Pixonaut\WooCommerce\Admin\CatalogueSearch;
use Pixonaut\WooCommerce\Api\Catalogue;
use Pixonaut\WooCommerce\Catalogue\ProductConfig;
use Pixonaut\WooCommerce\Plugin;
use Pixonaut\WooCommerce\Tests\Support\BlankFixture;
use WP_REST_Request;

/**
 * Every path of the product screen that reads a blank, with the blank
 * answering what it may beside its views: each view with its own `source`
 * and `position`, the blank's `order` with a scene, folding placements.
 */
final class BlankOrderedViewsTest extends ProductPanelTestCase {

	public function test_the_blank_search_lists_a_blank_with_ordered_views(): void {
		wp_set_current_user( $this->user( 'shop_manager' ) );
		$this->recorder->json(
			200,
			array(
				'blanks'       => array( BlankFixture::with_ordered_views( $this->blank(), false ) ),
				'total'        => 1,
				'page'         => 1,
				'pageSize'     => Catalogue::SEARCH_PAGE_SIZE,
				'orderedFirst' => false,
			)
		);

		$response = $this->get( CatalogueSearch::BLANKS_ROUTE, array( 'q' => '' ) );

		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( self::BLANK_ID, $response->get_data()['results'][0]['id'] );
		$this->assertSame( self::PLACEMENT_ID, $response->get_data()['results'][0]['placements'][0]['id'] );
	}

	public function test_the_placements_route_lists_the_placements_of_a_blank_with_ordered_views(): void {
		wp_set_current_user( $this->user( 'shop_manager' ) );
		$this->recorder->json( 200, BlankFixture::with_ordered_views( $this->blank() ) );
		$this->recorder->json( 200, BlankFixture::mockups_with_ordered_views( $this->mockups() ) );

		$response = $this->get( CatalogueSearch::PLACEMENTS_ROUTE, array( 'blank' => self::BLANK_ID ) );

		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( self::PLACEMENT_ID, $response->get_data()['results'][0]['id'] );
		$this->assertSame( self::BASE_URL . '/i/thumb-front', $response->get_data()['results'][0]['thumbnail'] );
	}

	public function test_a_product_links_to_a_blank_with_ordered_views_and_sells(): void {
		$product = $this->product();
		$this->recorder->json( 200, BlankFixture::with_ordered_views( $this->blank() ) );
		$this->recorder->json( 200, $this->constraints( self::PLACEMENT_ID ) );
		$this->recorder->json( 200, array( 'verdicts' => array( array( 'legal' => true ) ) ) );
		$this->script_template();

		$this->save( $product->get_id() );

		$config = ProductConfig::stored_on( $this->reload( $product->get_id() ) );
		$this->assertTrue( $config->valid );
		$this->assertSame( self::BLANK_ID, $config->blank_id );
		$this->assertSame( 'Fixture tee', $config->blank_name );
		$this->assertSame( 'Front', $config->sides[0]->placement_name );
		$this->assertTrue( $this->reload( $product->get_id() )->is_purchasable() );
	}

	public function test_the_panel_shows_a_linked_blank_with_ordered_views(): void {
		$product = $this->product();
		$this->script_link( array( 'legal' => true ) );
		$this->save( $product->get_id() );
		// The panel reads the blank afresh, not what the save kept.
		$this->cache->flush();
		$this->recorder->json( 200, BlankFixture::with_ordered_views( $this->blank() ) );
		$this->recorder->json( 200, BlankFixture::mockups_with_ordered_views( $this->mockups() ) );
		$this->recorder->json( 200, self::printshops() );

		$panel = $this->panel->panel( $this->reload( $product->get_id() ) );

		$this->assertSame( 'Fixture tee', $panel['blank_name'] );
		// The largest print comes only from the blank Pixonaut answered, not from the stored sides.
		$this->assertSame( 330.0, $panel['placements'][0]['maxWidthMm'] ?? null );
		$this->assertSame( self::BASE_URL . '/i/thumb-front', $panel['placements'][0]['thumbnail'] );
		$this->assertSame( 'Workspace default (Fixture Print AB)', $panel['printshop']['name'] );
	}

	/**
	 * @param string                $route  Route below the namespace.
	 * @param array<string, string> $params Query parameters.
	 */
	private function get( string $route, array $params ): \WP_REST_Response {
		$request = new WP_REST_Request( 'GET', '/' . Plugin::REST_NAMESPACE . $route );
		$request->set_query_params( $params );
		return rest_get_server()->dispatch( $request );
	}
}
