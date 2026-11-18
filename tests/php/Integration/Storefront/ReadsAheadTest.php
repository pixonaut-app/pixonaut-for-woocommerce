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
use WP_REST_Response;
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
		$this->assertSame( '{"targets":[],"parts":[]}', $response->get_data() );
		$this->assertSame( 'public, no-cache', $response->get_headers()['Cache-Control'] );
		$this->assertSame( '"' . md5( '{"targets":[],"parts":[]}' ) . '"', $response->get_headers()['ETag'] );
		$this->assertArrayNotHasKey( 'Content-Encoding', $response->get_headers() );
	}

	public function test_the_answer_is_compressed_for_a_browser_that_takes_gzip(): void {
		$product  = StorefrontTest::product( StorefrontTest::personalised() )->get_id();
		$response = $this->handle( $product, array( 'accept_encoding' => 'br, gzip, deflate' ) );

		$this->assertSame( 'gzip', $response->get_headers()['Content-Encoding'] );
		$this->assertSame( '{"targets":[],"parts":[]}', gzdecode( (string) $response->get_data() ) );
		$this->assertSame( (string) strlen( (string) $response->get_data() ), $response->get_headers()['Content-Length'] );
		$this->assertArrayNotHasKey( 'Content-Encoding', $this->handle( $product, array( 'accept_encoding' => 'gzip;q=0, br' ) )->get_headers() );
	}

	public function test_a_browser_holding_the_answer_is_answered_not_modified(): void {
		$product = StorefrontTest::product( StorefrontTest::personalised() )->get_id();
		$etag    = $this->handle( $product )->get_headers()['ETag'];

		$held = $this->handle( $product, array( 'if_none_match' => '"other", ' . $etag ) );

		$this->assertSame( 304, $held->get_status() );
		$this->assertNull( $held->get_data() );
		$this->assertSame( $etag, $held->get_headers()['ETag'] );
		$this->assertSame( 200, $this->handle( $product, array( 'if_none_match' => '"other"' ) )->get_status() );
	}

	public function test_each_of_pixonauts_answers_is_sent_once_and_named_by_each_target_reading_it(): void {
		$template = '{"id":"mini-tee@2"}';
		$mockups  = '{"views":[{"id":"front"}]}';
		$variants = '{"axes":["Size"]}';
		$poster   = array( 'templateId' => 'mini-poster@1' ) + self::HOODIE;
		$answer   = ReadsAhead::answer(
			array(
				'tee'    => self::TEE + array( 'locale' => 'sv' ),
				'hoodie' => self::HOODIE,
				'poster' => $poster,
				'failed' => array( 'blankId' => 'blank_failed' ) + self::TEE,
			),
			array(
				'tee'    => array( 'template' => $template ),
				'hoodie' => array(
					'template'  => $template,
					'placement' => '{"ratio":1}',
					'blank'     => array(
						'mockups'  => $mockups,
						'variants' => $variants,
					),
				),
				'poster' => array(
					'template' => '{"id":"mini-poster@1"}',
					'blank'    => array(
						'mockups'  => $mockups,
						'variants' => $variants,
					),
				),
			)
		);

		$this->assertSame(
			array(
				'targets' => array(
					self::TEE + array( 'reads' => array( 'template' => 0 ) ),
					self::HOODIE + array(
						'reads' => array(
							'template'  => 0,
							'placement' => 1,
							'blank'     => array(
								'mockups'  => 2,
								'variants' => 3,
							),
						),
					),
					$poster + array(
						'reads' => array(
							'template' => 4,
							'blank'    => array(
								'mockups'  => 2,
								'variants' => 3,
							),
						),
					),
				),
				'parts'   => array(
					array( 'id' => 'mini-tee@2' ),
					array( 'ratio' => 1 ),
					array( 'views' => array( array( 'id' => 'front' ) ) ),
					array( 'axes' => array( 'Size' ) ),
					array( 'id' => 'mini-poster@1' ),
				),
			),
			json_decode( $answer, true )
		);
	}

	public function test_the_answer_is_sent_as_it_is_with_its_own_cache_control(): void {
		$request = new WP_REST_Request( 'GET', '/' . Plugin::REST_NAMESPACE . ReadsAhead::ROUTE );
		$result  = new WP_REST_Response( '{"targets":[],"parts":[]}', 200, array( 'Cache-Control' => 'public, no-cache' ) );
		$route   = new ReadsAhead( $this->plugin->inline_products(), $this->plugin->server_reads() );

		ob_start();
		$served = $route->serve( false, $result, $request );
		$this->assertSame( '{"targets":[],"parts":[]}', ob_get_clean() );
		$this->assertTrue( $served );
		$this->assertFalse( $route->serve( false, new WP_REST_Response( array( 'error' => 'not_found' ), 404 ), $request ), 'a 404 is encoded as JSON' );
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

	/**
	 * @param int                   $product The product asked for.
	 * @param array<string, string> $headers The request's headers.
	 */
	private function handle( int $product, array $headers = array() ): WP_REST_Response {
		$request = new WP_REST_Request( 'GET', '/' . Plugin::REST_NAMESPACE . ReadsAhead::ROUTE );
		$request->set_param( 'product', $product );
		$request->set_headers( $headers );
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
