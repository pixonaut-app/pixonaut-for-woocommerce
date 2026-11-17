<?php
/**
 * @package Pixonaut\WooCommerce
 */

declare(strict_types=1);

namespace Pixonaut\WooCommerce\Tests\Integration\Storefront;

use Pixonaut\WooCommerce\Plugin;
use Pixonaut\WooCommerce\Storefront\InlineDesigner;
use Pixonaut\WooCommerce\Storefront\ReadsAhead;
use WP_REST_Request;
use WP_UnitTestCase;

final class ReadsAheadTest extends WP_UnitTestCase {

	private const TEE = array(
		'templateId'  => 'mini-tee@2',
		'blankId'     => 'blank_tee',
		'placementId' => 'front',
	);

	private const HOODIE = array( 'blankId' => 'blank_hoodie' ) + self::TEE;

	private Plugin $plugin;

	public function set_up(): void {
		parent::set_up();
		$this->plugin = Plugin::boot( dirname( __DIR__, 4 ) . '/pixonaut-for-woocommerce.php' );
	}

	public function test_the_route_is_public_and_read_only(): void {
		$routes = rest_get_server()->get_routes( Plugin::REST_NAMESPACE );
		$route  = $routes[ '/' . Plugin::REST_NAMESPACE . ReadsAhead::ROUTE ][0] ?? null;

		$this->assertIsArray( $route );
		$this->assertSame( '__return_true', $route['permission_callback'] );
		$this->assertSame( array( 'GET' => true ), $route['methods'] );
	}

	public function test_a_product_without_a_designer_is_not_found(): void {
		$this->assertSame( 404, $this->handle( StorefrontTest::product( null )->get_id() )->get_status() );
	}

	public function test_a_published_product_with_a_designer_is_answered(): void {
		$response = $this->handle( StorefrontTest::product( StorefrontTest::personalised() )->get_id() );

		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( array( 'reads' => array() ), $response->get_data() );
	}

	public function test_a_product_whose_page_a_shopper_cannot_open_is_not_found(): void {
		$draft = StorefrontTest::product( StorefrontTest::personalised() );
		wp_update_post(
			array(
				'ID'          => $draft->get_id(),
				'post_status' => 'draft',
			)
		);
		$locked = StorefrontTest::product( StorefrontTest::personalised() );
		wp_update_post(
			array(
				'ID'            => $locked->get_id(),
				'post_password' => 'secret',
			)
		);

		$this->assertSame( 404, $this->handle( $draft->get_id() )->get_status() );
		$this->assertSame( 404, $this->handle( $locked->get_id() )->get_status() );
	}

	public function test_the_targets_are_the_variations_sides_the_page_printed_no_reads_for_each_once_in_the_sides_locale(): void {
		$designer = $this->designer(
			array(
				11 => array( 'front' => self::TEE + array( 'printSizes' => array( 1 ) ) ),
				12 => array( 'front' => self::HOODIE + array( 'formGroups' => array( 'groups' => array() ) ) ),
				13 => array( 'front' => self::HOODIE ),
				14 => array( 'front' => array( 'templateId' => 'mini-poster@1' ) + self::TEE ),
			)
		);

		$this->assertSame(
			array(
				'mini-tee@2|blank_hoodie|front' => self::HOODIE + array( 'locale' => 'sv' ),
				'mini-poster@1|blank_tee|front' => array( 'templateId' => 'mini-poster@1' ) + self::TEE + array( 'locale' => 'sv' ),
			),
			ReadsAhead::targets( $designer, array( ReadsAhead::key( self::TEE ) ) )
		);
		$this->assertArrayHasKey( ReadsAhead::key( self::TEE ), ReadsAhead::targets( $designer ), 'the opening blank too, for a page that printed none' );
	}

	public function test_no_more_than_the_most_targets_are_read_ahead(): void {
		$variations = array();
		foreach ( range( 1, ReadsAhead::MAX_TARGETS + 2 ) as $index ) {
			$variations[ $index ] = array( 'front' => array( 'blankId' => "blank_{$index}" ) + self::TEE );
		}

		$this->assertCount( ReadsAhead::MAX_TARGETS, ReadsAhead::targets( $this->designer( $variations ) ) );
	}

	private function handle( int $product ): \WP_REST_Response {
		$request = new WP_REST_Request( 'GET', '/' . Plugin::REST_NAMESPACE . ReadsAhead::ROUTE );
		$request->set_param( 'product', $product );
		return ( new ReadsAhead( $this->plugin->inline_products(), $this->plugin->server_reads() ) )->handle( $request );
	}

	/**
	 * A designer mounted on the tee, in Swedish, with variations on the
	 * sides given.
	 *
	 * @param array<int, array<string, array<string, mixed>>> $sides Each variation's sides, by id.
	 */
	private function designer( array $sides ): InlineDesigner {
		$variations = array();
		foreach ( $sides as $id => $by_side ) {
			$variations[ $id ] = array(
				'state' => 'personalised',
				'sides' => (object) $by_side,
			);
		}
		return new InlineDesigner(
			StorefrontTest::product( StorefrontTest::personalised() ),
			StorefrontTest::personalised(),
			array( 'front' => self::TEE + array( 'locale' => 'sv' ) ),
			variations: $variations,
		);
	}
}
