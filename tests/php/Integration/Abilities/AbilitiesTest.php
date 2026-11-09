<?php
/**
 * @package Pixonaut\WooCommerce
 */

declare(strict_types=1);

namespace Pixonaut\WooCommerce\Tests\Integration\Abilities;

use Pixonaut\WooCommerce\Abilities\Abilities;
use Pixonaut\WooCommerce\Abilities\AbilityError;
use Pixonaut\WooCommerce\Admin\Notices;
use Pixonaut\WooCommerce\Api\Catalogue;
use Pixonaut\WooCommerce\Api\Hosts;
use Pixonaut\WooCommerce\ApprovalMode;
use Pixonaut\WooCommerce\Catalogue\InvalidReason;
use Pixonaut\WooCommerce\Catalogue\PrintSize;
use Pixonaut\WooCommerce\Catalogue\ProductConfig;
use Pixonaut\WooCommerce\Catalogue\ProductVariants;
use Pixonaut\WooCommerce\Catalogue\Side;
use Pixonaut\WooCommerce\Connection\ConnectionStore;
use Pixonaut\WooCommerce\Connection\StoredConnection;
use Pixonaut\WooCommerce\Fulfilment\LineState;
use Pixonaut\WooCommerce\Fulfilment\OrderStatus;
use Pixonaut\WooCommerce\Layout;
use Pixonaut\WooCommerce\Plugin;
use Pixonaut\WooCommerce\Proofs\ProofUrls;
use Pixonaut\WooCommerce\Tests\Integration\ShopTools\SetVariationVariantsTest;
use Pixonaut\WooCommerce\Tests\Support\BlankFixture;
use Pixonaut\WooCommerce\Tests\Support\HttpRecorder;
use Pixonaut\WooCommerce\Tests\Support\OpenApiAssert;
use Pixonaut\WooCommerce\Tests\Support\VariantsAnswer;
use Pixonaut\WooCommerce\Vendor\Pixonaut\Sdk\Exception\ApiException;
use Pixonaut\WooCommerce\Vendor\Pixonaut\Sdk\Generated\ErrorCode;
use ReflectionProperty;
use WC_Order;
use WC_Order_Item_Product;
use WC_Product;
use WC_Product_Simple;
use WC_Product_Variable;
use WC_Product_Variation;
use WP_Ability;
use WP_Error;
use WP_Http;
use WP_REST_Request;
use WP_REST_Server;
use WP_UnitTestCase;

/**
 * The plugin's abilities as the Abilities API runs them, and so as an MCP
 * client does through the MCP Adapter, against a recorder that answers as
 * Pixonaut.
 */
final class AbilitiesTest extends WP_UnitTestCase {

	private const LIST_TEMPLATES = Abilities::NAMESPACE . '/list-templates';

	private const LIST_PRODUCTS = Abilities::NAMESPACE . '/list-products';

	private const LINK_PRODUCT = Abilities::NAMESPACE . '/link-product';

	private const UNLINK_PRODUCT = Abilities::NAMESPACE . '/unlink-product';

	private const LIST_ORDERS = Abilities::NAMESPACE . '/list-personalised-orders';

	private const CONNECTION_STATUS = Abilities::NAMESPACE . '/get-connection-status';

	private const ACT_ON_LINE = Abilities::NAMESPACE . '/act-on-order-line';

	private const CHANGE_DELIVERY = Abilities::NAMESPACE . '/change-delivery-address';

	private const ALL = array( self::LIST_TEMPLATES, self::LIST_PRODUCTS, self::LINK_PRODUCT, self::UNLINK_PRODUCT, self::LIST_ORDERS, self::ACT_ON_LINE, self::CHANGE_DELIVERY, self::CONNECTION_STATUS, ...ProductSetupAbilitiesTest::WRITES, MoveProductAttributeTest::MOVE, StoreAdminAbilitiesTest::READ_SETTINGS, ...StoreAdminAbilitiesTest::WRITES, PictureViewAbilitiesTest::GET, PictureViewAbilitiesTest::SET, SetVariationVariantsTest::ABILITY );

	private const TEMPLATE_ID = 'mini-tee@2';

	private const BLANK_ID = 'blank_tee';

	private const PLACEMENT_ID = 'front';

	/**
	 * A print size for a template-only link, in millimetres.
	 */
	private const PRINT_WIDTH_MM = 500;

	private const PRINT_HEIGHT_MM = 700;

	private const CONNECTED_AT = 1767225600;

	/**
	 * Trashed products linked before the one on sale.
	 */
	private const TRASHED_BEFORE_THE_SOLD = 20;

	/**
	 * Products sold in one size before the one in another: past a batch of
	 * the products a template's print sizes are read from (50).
	 */
	private const SOLD_BEFORE_THE_OTHER_SIZE = 51;

	private Plugin $plugin;

	private HttpRecorder $recorder;

	private VariantsAnswer $variants;

	public function set_up(): void {
		parent::set_up();
		$this->plugin   = Plugin::boot( dirname( __DIR__, 4 ) . '/pixonaut-for-woocommerce.php' );
		$this->recorder = ( new HttpRecorder( Hosts::server_api() ) )->start();
		$this->variants = ( new VariantsAnswer() )->start();
		$this->plugin->connection_store()->save( self::connection() );
		// The abilities share the plugin's variants, which keep a blank's variants for the request: each test is one.
		( new ReflectionProperty( ProductVariants::class, 'read' ) )->setValue( $this->plugin->product_variants(), array() );
	}

	public function tear_down(): void {
		$this->recorder->stop();
		$this->variants->stop();
		$this->plugin->connection_store()->clear();
		delete_option( Notices::OPTION );
		$GLOBALS['wp_rest_server'] = null;
		parent::tear_down();
	}

	public function test_every_ability_is_registered_with_its_schemas_in_the_pixonaut_category(): void {
		$this->assertTrue( wp_has_ability_category( Abilities::CATEGORY ) );
		foreach ( self::ALL as $name ) {
			$ability = $this->ability( $name );
			$this->assertSame( Abilities::CATEGORY, $ability->get_category(), $name );
			$this->assertSame( 'object', $ability->get_input_schema()['type'] ?? null, $name );
			$this->assertSame( 'object', $ability->get_output_schema()['type'] ?? null, $name );
			$this->assertTrue( $ability->get_meta_item( 'show_in_rest' ), $name );
			$this->assertSame( array( 'public' => true ), $ability->get_meta_item( 'mcp' ), $name );
		}
		$this->assertTrue( $this->ability( self::LIST_TEMPLATES )->get_meta_item( 'annotations' )['readonly'] );
		$this->assertFalse( $this->ability( self::LINK_PRODUCT )->get_meta_item( 'annotations' )['readonly'] );
	}

	public function test_a_client_discovers_and_runs_the_abilities_over_rest(): void {
		wp_set_current_user( $this->user( 'administrator' ) );
		$GLOBALS['wp_rest_server'] = new WP_REST_Server();
		do_action( 'rest_api_init', $GLOBALS['wp_rest_server'] );

		$request = new WP_REST_Request( 'GET', '/wp-abilities/v1/abilities' );
		$request->set_param( 'category', Abilities::CATEGORY );
		$listed = rest_get_server()->dispatch( $request );
		$this->assertSame( 200, $listed->get_status() );
		$names = array_column( (array) $listed->get_data(), 'name' );
		sort( $names );
		$expected = self::ALL;
		sort( $expected );
		$this->assertSame( $expected, $names );

		$this->recorder->raw( 200, OpenApiAssert::example( 'GET', '/v1/connect', 200 ), 'application/json' );
		$run    = new WP_REST_Request( 'GET', '/wp-abilities/v1/abilities/' . self::CONNECTION_STATUS . '/run' );
		$answer = rest_get_server()->dispatch( $run );
		$this->assertSame( 200, $answer->get_status(), (string) wp_json_encode( $answer->get_data() ) );
		$this->assertTrue( $answer->get_data()['connected'] );
	}

	public function test_listing_templates_asks_pixonaut_and_gives_the_ids_a_product_is_linked_with(): void {
		wp_set_current_user( $this->user( 'shop_manager' ) );
		$sized = self::sized( self::PRINT_WIDTH_MM, self::PRINT_HEIGHT_MM, '50×70' );
		$sized->save_to( $this->product() );
		// A second product in the same size lists it once.
		$sized->save_to( $this->product() );
		$trashed = $this->product();
		self::sized( self::PRINT_WIDTH_MM * 2, self::PRINT_HEIGHT_MM * 2, '' )->save_to( $trashed );
		$trashed->delete();
		$this->recorder->json(
			200,
			array(
				'items'    => array(
					array(
						'id'                   => self::TEMPLATE_ID,
						'baseId'               => 'mini-tee',
						'name'                 => 'Mini tee',
						'latestVersion'        => 2,
						'ratio'                => array(
							'w' => 280,
							'h' => 360,
						),
						'ratioTolerance'       => 0,
						'fieldKeys'            => array(),
						'previewUrl'           => Hosts::server_api() . '/i/preview',
						'offersProperties'     => true,
						'backgroundSeeThrough' => true,
					),
					array(
						'id'                   => 'mini-poster@1',
						'baseId'               => 'mini-poster',
						'name'                 => 'Mini poster',
						'latestVersion'        => 1,
						'ratio'                => array(
							'w' => 5,
							'h' => 7,
						),
						'ratioTolerance'       => 0,
						'fieldKeys'            => array(),
						'previewUrl'           => '',
						'offersProperties'     => false,
						'backgroundSeeThrough' => null,
					),
				),
				'total'    => Catalogue::SEARCH_PAGE_SIZE + 1,
				'page'     => 1,
				'pageSize' => Catalogue::SEARCH_PAGE_SIZE,
			)
		);

		$answer = $this->ability( self::LIST_TEMPLATES )->execute( array( 'search' => 'tee' ) );

		$this->assertSame(
			array(
				'templates' => array(
					array(
						'id'                     => self::TEMPLATE_ID,
						'name'                   => 'Mini tee',
						'thumbnail'              => Hosts::server_api() . '/i/preview',
						'offers_properties'      => true,
						'background_see_through' => true,
						'ratio'                  => array(
							'width'  => 280.0,
							'height' => 360.0,
						),
						'print_sizes'            => array(
							array(
								'width_mm'  => (float) self::PRINT_WIDTH_MM,
								'height_mm' => (float) self::PRINT_HEIGHT_MM,
								'label'     => '50×70',
							),
						),
					),
					array(
						'id'                     => 'mini-poster@1',
						'name'                   => 'Mini poster',
						'thumbnail'              => '',
						'offers_properties'      => false,
						'background_see_through' => null,
						'ratio'                  => array(
							'width'  => 5.0,
							'height' => 7.0,
						),
						'print_sizes'            => array(),
					),
				),
				'total'     => Catalogue::SEARCH_PAGE_SIZE + 1,
				'more'      => true,
			),
			$answer
		);
		$this->assertCount( 1, $this->recorder->requests );
		$this->assertStringContainsString( '/v1/templates?status=live&q=tee', $this->recorder->requests[0]['url'] );
		$this->assertStringNotContainsString( 'ik_live_fixture', (string) wp_json_encode( $answer ) );
	}

	public function test_listed_print_sizes_are_only_those_the_store_sells_now(): void {
		wp_set_current_user( $this->user( 'shop_manager' ) );
		// More trashed products than the sizes are read from, all before the one on sale.
		for ( $trashed = 0; $trashed <= self::TRASHED_BEFORE_THE_SOLD; $trashed++ ) {
			$product = $this->product();
			self::sized( 100, 100, '' )->save_to( $product );
			$product->delete();
		}
		self::sized( 500, 700, '' )->save_to( $this->product() );
		self::sized( 200, 200, '', false )->save_to( $this->product() );
		$draft = $this->product();
		$draft->set_status( 'draft' );
		$draft->save();
		self::sized( 300, 300, '' )->save_to( $draft );

		$parent = new WC_Product_Variable();
		$parent->set_name( 'Personalised poster' );
		$parent->save();
		self::sized( 400, 400, '' )->save_to( $parent );
		// A variation following its product keeps its own configuration, unused.
		self::sized( 600, 600, '' )->save_to( $this->variation( $parent, 'publish' ) );
		self::sized( 700, 700, '', true, true )->save_to( $this->variation( $parent, 'private' ) );
		self::sized( 800, 800, '', true, true )->save_to( $this->variation( $parent, 'publish' ) );
		$this->recorder->json(
			200,
			array(
				'items'    => array(
					array(
						'id'                   => self::TEMPLATE_ID,
						'baseId'               => 'mini-tee',
						'name'                 => 'Mini tee',
						'latestVersion'        => 2,
						'ratio'                => array(
							'w' => 1,
							'h' => 1,
						),
						'ratioTolerance'       => 0,
						'fieldKeys'            => array(),
						'previewUrl'           => '',
						'offersProperties'     => true,
						'backgroundSeeThrough' => null,
					),
				),
				'total'    => 1,
				'page'     => 1,
				'pageSize' => Catalogue::SEARCH_PAGE_SIZE,
			)
		);

		$answer = $this->ability( self::LIST_TEMPLATES )->execute();

		$this->assertIsArray( $answer );
		$this->assertSame(
			array( array( 500.0, 700.0 ), array( 400.0, 400.0 ), array( 800.0, 800.0 ) ),
			array_map( static fn ( array $size ): array => array( $size['width_mm'], $size['height_mm'] ), $answer['templates'][0]['print_sizes'] )
		);
		$this->assertSame( '500 × 700 mm', $answer['templates'][0]['print_sizes'][0]['label'] );
	}

	public function test_listing_templates_of_a_store_not_connected_says_so_without_asking(): void {
		wp_set_current_user( $this->user( 'shop_manager' ) );
		$this->plugin->connection_store()->clear();

		$answer = $this->ability( self::LIST_TEMPLATES )->execute();

		$this->assertInstanceOf( WP_Error::class, $answer );
		$this->assertSame( 'pixonaut_not_connected', $answer->get_error_code() );
		$this->assertSame( 'not_connected', $answer->get_error_data()['code'] );
		$this->assertStringContainsString( 'not connected', $answer->get_error_message() );
		$this->assertSame( array(), $this->recorder->requests );
	}

	public function test_a_store_not_connected_answers_the_run_route_as_unavailable(): void {
		wp_set_current_user( $this->user( 'shop_manager' ) );
		$this->plugin->connection_store()->clear();
		$GLOBALS['wp_rest_server'] = new WP_REST_Server();
		do_action( 'rest_api_init', $GLOBALS['wp_rest_server'] );

		$answer = rest_get_server()->dispatch( new WP_REST_Request( 'GET', '/wp-abilities/v1/abilities/' . self::LIST_TEMPLATES . '/run' ) );

		$this->assertSame( WP_Http::SERVICE_UNAVAILABLE, $answer->get_status() );
		$this->assertSame( 'pixonaut_not_connected', $answer->get_data()['code'] );
		$this->assertSame( array(), $this->recorder->requests );
	}

	public function test_listing_templates_pages_by_per_page(): void {
		wp_set_current_user( $this->user( 'shop_manager' ) );
		$this->recorder->json(
			200,
			array(
				'items'    => array(),
				'total'    => 7,
				'page'     => 2,
				'pageSize' => 5,
			)
		);

		$answer = $this->ability( self::LIST_TEMPLATES )->execute(
			array(
				'page'     => 2,
				'per_page' => '5',
			)
		);

		$this->assertIsArray( $answer );
		$this->assertSame( 7, $answer['total'] );
		$this->assertFalse( $answer['more'] );
		$this->assertStringContainsString( 'page=2&pageSize=5', $this->recorder->requests[0]['url'] );
	}

	public function test_print_sizes_are_read_from_every_product_linked_to_the_template(): void {
		wp_set_current_user( $this->user( 'shop_manager' ) );
		for ( $sold = 0; $sold < self::SOLD_BEFORE_THE_OTHER_SIZE; $sold++ ) {
			self::sized( 100, 100, '' )->save_to( $this->product() );
		}
		self::sized( 500, 500, '' )->save_to( $this->product() );
		$this->recorder->json(
			200,
			array(
				'items'    => array(
					array(
						'id'                   => self::TEMPLATE_ID,
						'baseId'               => 'mini-tee',
						'name'                 => 'Mini tee',
						'latestVersion'        => 2,
						'ratio'                => array(
							'w' => 1,
							'h' => 1,
						),
						'ratioTolerance'       => 0,
						'fieldKeys'            => array(),
						'previewUrl'           => '',
						'offersProperties'     => true,
						'backgroundSeeThrough' => null,
					),
				),
				'total'    => 1,
				'page'     => 1,
				'pageSize' => Catalogue::SEARCH_PAGE_SIZE,
			)
		);

		$answer = $this->ability( self::LIST_TEMPLATES )->execute();

		$this->assertIsArray( $answer );
		$this->assertSame(
			array( array( 100.0, 100.0 ), array( 500.0, 500.0 ) ),
			array_map( static fn ( array $size ): array => array( $size['width_mm'], $size['height_mm'] ), $answer['templates'][0]['print_sizes'] )
		);
	}

	public function test_a_template_list_pixonaut_refuses_names_the_cause_and_its_code(): void {
		wp_set_current_user( $this->user( 'shop_manager' ) );
		$this->recorder->json( 500, array( 'error' => ErrorCode::DB_ERROR ) );

		$answer = $this->ability( self::LIST_TEMPLATES )->execute();

		$this->assertInstanceOf( WP_Error::class, $answer );
		$this->assertSame( 'pixonaut_db_error', $answer->get_error_code() );
		$this->assertSame( ErrorCode::DB_ERROR, $answer->get_error_data()['code'] );
		$this->assertSame( 500, $answer->get_error_data()['pixonaut_status'] );
		$this->assertSame( WP_Http::SERVICE_UNAVAILABLE, $answer->get_error_data()['status'] );
		$this->assertSame( 'Pixonaut could not answer just now. Try again in a moment. (Pixonaut error code: db_error)', $answer->get_error_message() );
	}

	/**
	 * @return iterable<string, array{ApiException, string, int}>
	 */
	public static function failed_calls(): iterable {
		yield 'a key no longer taken' => array( new ApiException( 'templates.list', 401, ErrorCode::UNAUTHORIZED, false ), 'Pixonaut no longer accepts the store\'s key. Connect the store again on the Pixonaut settings page. (Pixonaut error code: unauthorized)', WP_Http::BAD_GATEWAY );
		yield 'a quota' => array( new ApiException( 'templates.list', 429, ErrorCode::RATE_LIMITED, true, 30 ), 'The store sent Pixonaut too many requests. Try again in 30 seconds. (Pixonaut error code: rate_limited)', WP_Http::SERVICE_UNAVAILABLE );
		yield 'an outage' => array( new ApiException( 'templates.list', 0, ApiException::TRANSPORT_FAILED, true ), 'Pixonaut could not be reached. Try again in a moment. (Pixonaut error code: transport_failed)', WP_Http::SERVICE_UNAVAILABLE );
		yield 'a suspended workspace' => array( new ApiException( 'templates.list', 403, ErrorCode::WORKSPACE_SUSPENDED, false ), 'The Pixonaut workspace is suspended. (Pixonaut error code: workspace_suspended)', WP_Http::BAD_GATEWAY );
		yield 'any other refusal' => array( new ApiException( 'templates.list', 422, ErrorCode::BAD_REQUEST, false ), 'Pixonaut refused the request. (Pixonaut error code: bad_request)', WP_Http::BAD_GATEWAY );
	}

	/**
	 * @dataProvider failed_calls
	 *
	 * @param ApiException $failure Why the call failed.
	 * @param string       $message What the ability says.
	 * @param int          $status  The REST status its run route answers with.
	 */
	public function test_a_failed_call_says_its_cause_with_pixonauts_code( ApiException $failure, string $message, int $status ): void {
		$error = AbilityError::from( $failure );

		$this->assertSame( 'pixonaut_' . $failure->errorCode, $error->get_error_code() );
		$this->assertSame( $message, $error->get_error_message() );
		$this->assertSame( $failure->errorCode, $error->get_error_data()['code'] );
		$this->assertSame( $status, $error->get_error_data()['status'] );
		$this->assertSame( $failure->status, $error->get_error_data()['pixonaut_status'] );
	}

	public function test_reading_abilities_are_refused_to_customers(): void {
		wp_set_current_user( $this->user( 'customer' ) );

		foreach ( array( self::LIST_TEMPLATES, self::LIST_ORDERS, self::CONNECTION_STATUS ) as $name ) {
			$this->assertInstanceOf( WP_Error::class, $this->ability( $name )->execute(), $name );
		}
		$this->assertSame( array(), $this->recorder->requests );
	}

	public function test_linking_a_product_to_a_template_without_a_blank_checks_it_with_pixonaut(): void {
		wp_set_current_user( $this->user( 'shop_manager' ) );
		$product = $this->product();
		$this->recorder->json( 200, array( 'verdicts' => array( array( 'legal' => true ) ) ) );
		$this->recorder->raw( 200, OpenApiAssert::example( 'GET', '/v1/templates/{id}', 200, array( 'status' => Catalogue::LIVE ) ), 'application/json' );

		$answer = $this->ability( self::LINK_PRODUCT )->execute(
			array(
				'product_id'  => $product->get_id(),
				'template_id' => self::TEMPLATE_ID,
				'width_mm'    => self::PRINT_WIDTH_MM,
				'height_mm'   => self::PRINT_HEIGHT_MM,
			)
		);

		$this->assertIsArray( $answer );
		$this->assertTrue( $answer['personalised'] );
		$this->assertTrue( $answer['valid'] );
		$this->assertNull( $answer['blank_id'] );
		$this->assertSame( self::TEMPLATE_ID, $answer['sides'][0]['template_id'] );
		$this->assertStringEndsWith( '/v1/templates/mini-tee%402/print-size', $this->recorder->requests[0]['url'] );
		$this->assertEquals(
			array(
				'sizes' => array(
					array(
						'widthMm'  => self::PRINT_WIDTH_MM,
						'heightMm' => self::PRINT_HEIGHT_MM,
					),
				),
			),
			json_decode( (string) $this->recorder->requests[0]['body'], true )
		);
		$this->assertStringEndsWith( '/v1/templates/mini-tee%402', $this->recorder->requests[1]['url'] );
		$saved = $this->reload( $product->get_id() );
		$this->assertTrue( ProductConfig::stored_on( $saved )->personalise );
		$this->assertTrue( $saved->is_purchasable() );
	}

	public function test_a_link_without_a_blank_or_a_size_is_saved_unsellable_and_says_why(): void {
		wp_set_current_user( $this->user( 'shop_manager' ) );
		$product = $this->product();
		$this->recorder->raw( 200, OpenApiAssert::example( 'GET', '/v1/templates/{id}', 200, array( 'status' => Catalogue::LIVE ) ), 'application/json' );

		$answer = $this->ability( self::LINK_PRODUCT )->execute(
			array(
				'product_id'  => $product->get_id(),
				'template_id' => self::TEMPLATE_ID,
			)
		);

		$this->assertIsArray( $answer );
		$this->assertFalse( $answer['valid'] );
		$this->assertSame( InvalidReason::message( InvalidReason::MISSING_PRINT_SIZE ), $answer['invalid_reason'] );
		$this->assertFalse( $answer['purchasable'] );
	}

	public function test_linking_on_a_blank_keeps_the_products_own_overrides(): void {
		wp_set_current_user( $this->user( 'shop_manager' ) );
		$product = $this->product();
		( new ProductConfig( approval_mode: ApprovalMode::CHECKBOX, layout: Layout::BUTTON ) )->save_to( $product );
		$this->recorder->json( 200, BlankFixture::answer( array( self::PLACEMENT_ID => 'Front' ) ) );
		$this->recorder->json( 200, $this->constraints() );
		$this->recorder->json( 200, array( 'verdicts' => array( array( 'legal' => true ) ) ) );
		$this->recorder->raw( 200, OpenApiAssert::example( 'GET', '/v1/templates/{id}', 200, array( 'status' => Catalogue::LIVE ) ), 'application/json' );

		$answer = $this->ability( self::LINK_PRODUCT )->execute(
			array(
				'product_id'   => $product->get_id(),
				'template_id'  => self::TEMPLATE_ID,
				'blank_id'     => self::BLANK_ID,
				'placement_id' => self::PLACEMENT_ID,
			)
		);

		$this->assertIsArray( $answer );
		$this->assertTrue( $answer['valid'] );
		$this->assertSame( self::BLANK_ID, $answer['blank_id'] );
		$this->assertSame( self::PLACEMENT_ID, $answer['sides'][0]['placement_id'] );
		$config = ProductConfig::stored_on( $this->reload( $product->get_id() ) );
		$this->assertSame( ApprovalMode::CHECKBOX, $config->approval_mode );
		$this->assertSame( Layout::BUTTON, $config->layout );
		$this->assertGreaterThan( 0, $this->variants->reads, 'The blank variant is resolved as on the product screen.' );
		$this->assertSame( $this->reload( $product->get_id() )->is_purchasable(), $answer['purchasable'] );
		$this->assertNull( $answer['variant_issue'] );
		// The answer names the template as Pixonaut does, and says what the product sells and shows.
		$this->assertSame( 'T-shirt print, front', $answer['sides'][0]['template_name'] );
		$this->assertSame( 'v_tee', $answer['variant']['ref'] ?? null );
		$this->assertContains( $answer['picture'], array( 'pending', 'none', 'off' ) );
	}

	public function test_a_link_whose_blank_variant_is_retired_says_the_product_cannot_be_bought(): void {
		wp_set_current_user( $this->user( 'shop_manager' ) );
		$product = $this->product();
		$this->variants->answer['variants'][0]['retired'] = array( 'reason' => 'retired' );
		$this->recorder->json( 200, BlankFixture::answer( array( self::PLACEMENT_ID => 'Front' ) ) );
		$this->recorder->json( 200, $this->constraints() );
		$this->recorder->json( 200, array( 'verdicts' => array( array( 'legal' => true ) ) ) );
		$this->recorder->raw( 200, OpenApiAssert::example( 'GET', '/v1/templates/{id}', 200, array( 'status' => Catalogue::LIVE ) ), 'application/json' );

		$answer = $this->ability( self::LINK_PRODUCT )->execute(
			array(
				'product_id'   => $product->get_id(),
				'template_id'  => self::TEMPLATE_ID,
				'blank_id'     => self::BLANK_ID,
				'placement_id' => self::PLACEMENT_ID,
			)
		);

		$this->assertIsArray( $answer );
		$this->assertTrue( $answer['valid'], 'The link itself passed every check.' );
		$this->assertFalse( $answer['purchasable'] );
		$this->assertIsString( $answer['variant_issue'] );
		$this->assertStringContainsString( 'its blank variant is retired', $answer['variant_issue'] );
		$this->assertStringNotContainsString( '<a', $answer['variant_issue'] );
	}

	public function test_a_product_id_sent_as_digits_is_read_as_the_number(): void {
		wp_set_current_user( $this->user( 'shop_manager' ) );
		$product = $this->product();
		self::template_only()->save_to( $product );

		// The REST API passes a DELETE run's input as query parameters, as strings.
		$answer = $this->ability( self::UNLINK_PRODUCT )->execute( array( 'product_id' => (string) $product->get_id() ) );

		$this->assertIsArray( $answer );
		$this->assertSame( $product->get_id(), $answer['product_id'] );
		$this->assertFalse( ProductConfig::stored_on( $this->reload( $product->get_id() ) )->personalise );
	}

	public function test_a_link_pixonaut_refuses_is_saved_unsellable_and_says_why(): void {
		wp_set_current_user( $this->user( 'shop_manager' ) );
		$product = $this->product();
		$this->recorder->json( 200, array( 'verdicts' => array( array( 'legal' => true ) ) ) );
		$this->recorder->raw( 200, OpenApiAssert::example( 'GET', '/v1/templates/{id}', 200, array( 'status' => 'paused' ) ), 'application/json' );

		$answer = $this->ability( self::LINK_PRODUCT )->execute(
			array(
				'product_id'  => $product->get_id(),
				'template_id' => self::TEMPLATE_ID,
				'width_mm'    => self::PRINT_WIDTH_MM,
				'height_mm'   => self::PRINT_HEIGHT_MM,
			)
		);

		$this->assertIsArray( $answer );
		$this->assertFalse( $answer['valid'] );
		$this->assertSame( InvalidReason::message( InvalidReason::TEMPLATE_NOT_LIVE ), $answer['invalid_reason'] );
		$this->assertFalse( $this->reload( $product->get_id() )->is_purchasable() );
	}

	public function test_unlinking_turns_personalisation_off_and_keeps_the_link(): void {
		wp_set_current_user( $this->user( 'shop_manager' ) );
		$product = $this->product();
		self::template_only()->save_to( $product );

		$answer = $this->ability( self::UNLINK_PRODUCT )->execute( array( 'product_id' => $product->get_id() ) );

		$this->assertIsArray( $answer );
		$this->assertFalse( $answer['personalised'] );
		$config = ProductConfig::stored_on( $this->reload( $product->get_id() ) );
		$this->assertFalse( $config->personalise );
		$this->assertSame( 'mini-tee', $config->sides[0]->template_base_id );
		$this->assertSame( array(), $this->recorder->requests );
	}

	public function test_writes_are_refused_to_a_user_who_may_not_edit_the_product(): void {
		$product = $this->product();
		self::template_only()->save_to( $product );

		foreach ( array( 'customer', 'contributor' ) as $role ) {
			wp_set_current_user( $this->user( $role ) );
			$link = $this->ability( self::LINK_PRODUCT )->execute(
				array(
					'product_id'  => $product->get_id(),
					'template_id' => self::TEMPLATE_ID,
				)
			);
			$this->assertInstanceOf( WP_Error::class, $link, $role );
			$this->assertInstanceOf( WP_Error::class, $this->ability( self::UNLINK_PRODUCT )->execute( array( 'product_id' => $product->get_id() ) ), $role );
		}
		$this->assertTrue( ProductConfig::stored_on( $this->reload( $product->get_id() ) )->personalise );
		$this->assertSame( array(), $this->recorder->requests );
	}

	public function test_linking_refuses_what_is_not_a_simple_or_variable_product(): void {
		wp_set_current_user( $this->user( 'administrator' ) );
		$post_id = self::factory()->post->create();

		$answer = $this->ability( self::LINK_PRODUCT )->execute(
			array(
				'product_id'  => $post_id,
				'template_id' => self::TEMPLATE_ID,
			)
		);

		$this->assertInstanceOf( WP_Error::class, $answer );
		$this->assertSame( 'pixonaut_wc_ability_unknown_product', $answer->get_error_code() );
	}

	public function test_personalised_orders_list_each_lines_proofs_and_production_newest_first(): void {
		wp_set_current_user( $this->user( 'shop_manager' ) );
		$older = $this->personalised_order( LineState::AWAITING_CUSTOMER );
		$this->plain_order();
		$newer = $this->personalised_order( LineState::ORDERED, OrderStatus::PRINTED );

		$answer = $this->ability( self::LIST_ORDERS )->execute();

		$this->assertIsArray( $answer );
		$this->assertSame( 2, $answer['total'] );
		$this->assertFalse( $answer['more'] );
		$this->assertSame( array( $newer->get_id(), $older->get_id() ), array_column( $answer['orders'], 'order_id' ) );
		$line = $answer['orders'][0]['lines'][0];
		$this->assertSame( LineState::ORDERED, $line['state'] );
		$this->assertSame( OrderStatus::PRINTED, $line['production_status'] );
		$this->assertSame( 'po_' . $newer->get_id(), $line['pixonaut_order_id'] );
		$this->assertSame( 'front', $line['sides'][0]['key'] );
		$this->assertSame( 'd_front', $line['sides'][0]['design_id'] );
		$this->assertSame( $this->plugin->proof_urls()->url( 'd_front', 'proof_front', ProofUrls::LARGEST_WIDTH ), $line['sides'][0]['proof_url'] );
		$this->assertTrue( $line['sides'][0]['approved'] );
		$this->assertSame( array(), $this->recorder->requests, 'The lines are read as the store keeps them.' );
	}

	public function test_personalised_orders_filter_by_line_state_a_page_at_a_time(): void {
		wp_set_current_user( $this->user( 'shop_manager' ) );
		$older = $this->personalised_order( LineState::AWAITING_CUSTOMER );
		$this->personalised_order( LineState::ORDERED, OrderStatus::SUBMITTED );
		$newer = $this->personalised_order( LineState::AWAITING_CUSTOMER );
		$ask   = fn ( array $input ): array => $this->ability( self::LIST_ORDERS )->execute( $input + array( 'state' => LineState::AWAITING_CUSTOMER ) );

		$first = $ask( array( 'per_page' => 1 ) );
		$this->assertSame( array( $newer->get_id() ), array_column( $first['orders'], 'order_id' ) );
		$this->assertSame( 2, $first['total'] );
		$this->assertTrue( $first['more'] );
		$this->assertSame( $newer->get_id(), $first['next_before'] );

		// The ordered order in between is passed over; its id comes as digits, as over REST.
		$second = $ask(
			array(
				'per_page' => '1',
				'before'   => (string) $first['next_before'],
			)
		);
		$this->assertSame( array( $older->get_id() ), array_column( $second['orders'], 'order_id' ) );
		$this->assertFalse( $second['more'] );
		$this->assertNull( $second['next_before'] );
	}

	public function test_a_line_this_version_cannot_read_is_listed_as_needing_attention(): void {
		wp_set_current_user( $this->user( 'shop_manager' ) );
		$order = $this->personalised_order( LineState::ORDERED, OrderStatus::SUBMITTED );
		$item  = current( $order->get_items() );
		$this->assertInstanceOf( WC_Order_Item_Product::class, $item );
		// A newer plugin version's document, which this one reads as needing attention.
		$newer = array(
			'v'     => LineState::SCHEMA_VERSION + 1,
			'state' => LineState::ORDERED,
		);
		wc_update_order_item_meta( $item->get_id(), LineState::META_KEY, (string) wp_json_encode( $newer ) );

		$listed = $this->ability( self::LIST_ORDERS )->execute( array( 'state' => LineState::NEEDS_ATTENTION ) );

		$this->assertSame( array( $order->get_id() ), array_column( $listed['orders'], 'order_id' ) );
		$this->assertSame( 1, $listed['total'] );
		$this->assertSame( LineState::NEEDS_ATTENTION, $listed['orders'][0]['lines'][0]['state'] );
		$ordered = $this->ability( self::LIST_ORDERS )->execute( array( 'state' => LineState::ORDERED ) );
		$this->assertSame( array(), $ordered['orders'] );
		$this->assertSame( 0, $ordered['total'] );
	}

	public function test_a_state_held_deeper_in_a_line_does_not_list_it_in_that_state(): void {
		wp_set_current_user( $this->user( 'shop_manager' ) );
		$order = $this->personalised_order( LineState::ORDERED, OrderStatus::SUBMITTED );
		$item  = current( $order->get_items() );
		$this->assertInstanceOf( WC_Order_Item_Product::class, $item );
		// A retry's ref holds the state its line was in.
		$line = LineState::of( $item );
		$line->set_ref( LineState::REF_RETRY, array( 'state' => LineState::APPROVED ) );
		$this->assertTrue( $line->save() );

		$ordered  = $this->ability( self::LIST_ORDERS )->execute( array( 'state' => LineState::ORDERED ) );
		$approved = $this->ability( self::LIST_ORDERS )->execute( array( 'state' => LineState::APPROVED ) );

		$this->assertSame( array( $order->get_id() ), array_column( $ordered['orders'], 'order_id' ) );
		$this->assertSame( 1, $ordered['total'] );
		$this->assertSame( array(), $approved['orders'] );
		$this->assertSame( 0, $approved['total'] );
	}

	public function test_a_cut_off_line_is_listed_as_needing_attention_not_in_its_state(): void {
		wp_set_current_user( $this->user( 'shop_manager' ) );
		$order = $this->personalised_order( LineState::ORDERED, OrderStatus::SUBMITTED );
		$item  = current( $order->get_items() );
		$this->assertInstanceOf( WC_Order_Item_Product::class, $item );
		$raw = (string) wc_get_order_item_meta( $item->get_id(), LineState::META_KEY );
		$this->assertStringStartsWith( '{"v":' . LineState::SCHEMA_VERSION . ',"state":"ordered",', $raw );
		wc_update_order_item_meta( $item->get_id(), LineState::META_KEY, substr( $raw, 0, intdiv( strlen( $raw ), 2 ) ) );

		$ordered   = $this->ability( self::LIST_ORDERS )->execute( array( 'state' => LineState::ORDERED ) );
		$attention = $this->ability( self::LIST_ORDERS )->execute( array( 'state' => LineState::NEEDS_ATTENTION ) );

		$this->assertSame( array(), $ordered['orders'] );
		$this->assertSame( 0, $ordered['total'] );
		$this->assertSame( array( $order->get_id() ), array_column( $attention['orders'], 'order_id' ) );
		$this->assertSame( 1, $attention['total'] );
		$this->assertSame( LineState::NEEDS_ATTENTION, $attention['orders'][0]['lines'][0]['state'] );
	}

	public function test_a_page_of_lines_needing_attention_is_full_however_many_orders_lie_between(): void {
		wp_set_current_user( $this->user( 'shop_manager' ) );
		$oldest = $this->personalised_order( LineState::NEEDS_ATTENTION );
		$older  = $this->personalised_order( LineState::NEEDS_ATTENTION );
		$this->personalised_order( LineState::ORDERED, OrderStatus::SUBMITTED );
		$this->personalised_order( LineState::SHIPPED, OrderStatus::SHIPPED );
		// A line whose stored state this version does not know reads as needing attention.
		$unknown = $this->personalised_order( LineState::ORDERED, OrderStatus::SUBMITTED );
		$item    = current( $unknown->get_items() );
		$this->assertInstanceOf( WC_Order_Item_Product::class, $item );
		$raw = (string) wc_get_order_item_meta( $item->get_id(), LineState::META_KEY );
		wc_update_order_item_meta( $item->get_id(), LineState::META_KEY, str_replace( '"state":"ordered"', '"state":"in_the_post"', $raw ) );
		$this->personalised_order( LineState::AWAITING_CUSTOMER );

		$first  = $this->ability( self::LIST_ORDERS )->execute(
			array(
				'state'    => LineState::NEEDS_ATTENTION,
				'per_page' => 2,
			)
		);
		$second = $this->ability( self::LIST_ORDERS )->execute(
			array(
				'state'    => LineState::NEEDS_ATTENTION,
				'per_page' => 2,
				'before'   => $first['next_before'],
			)
		);

		$this->assertSame( array( $unknown->get_id(), $older->get_id() ), array_column( $first['orders'], 'order_id' ) );
		$this->assertSame( 3, $first['total'] );
		$this->assertTrue( $first['more'] );
		$this->assertSame( array( $oldest->get_id() ), array_column( $second['orders'], 'order_id' ) );
		$this->assertFalse( $second['more'] );
	}

	public function test_the_connection_status_names_the_workspace_and_never_a_key(): void {
		wp_set_current_user( $this->user( 'shop_manager' ) );
		$this->plugin->connection_store()->pause( ConnectionStore::PAUSED_SITE_CHANGED );

		$answer = $this->ability( self::CONNECTION_STATUS )->execute();

		$this->assertSame(
			array(
				'connected'    => true,
				'paused'       => ConnectionStore::PAUSED_SITE_CHANGED,
				'workspace'    => 'Fixture Prints',
				'workspace_id' => 'tenant_fixture',
				'environment'  => StoredConnection::LIVE,
				'site'         => 'site_fixture',
				'connected_at' => gmdate( DATE_ATOM, self::CONNECTED_AT ),
			),
			array_slice( $answer, 0, 7 )
		);
		$this->assertNull( $answer['workspace_settings'], 'Pixonaut is not asked while the connection is paused.' );
		$this->assertSame( array(), $this->recorder->requests );
		$this->assertStringNotContainsString( 'fixture_main', (string) wp_json_encode( $answer ) );

		$this->plugin->connection_store()->clear();
		$this->assertFalse( $this->ability( self::CONNECTION_STATUS )->execute()['connected'] );
	}

	private function ability( string $name ): WP_Ability {
		$ability = wp_get_ability( $name );
		$this->assertInstanceOf( WP_Ability::class, $ability, $name );
		return $ability;
	}

	/**
	 * A paid order whose one line is tracked in $state, with a front side
	 * whose proof the customer approved.
	 *
	 * @param string      $state  The line's state.
	 * @param string|null $status The Pixonaut order's status; null before it is ordered.
	 */
	private function personalised_order( string $state, ?string $status = null ): WC_Order {
		$order = wc_create_order();
		$this->assertInstanceOf( WC_Order::class, $order );
		$order->add_product( $this->product() );
		$order->save();
		$item = current( $order->get_items() );
		$this->assertInstanceOf( WC_Order_Item_Product::class, $item );
		$line = LineState::start( $item, array( 'front' ) );
		$line->set_side_ref( 'front', LineState::SIDE_DESIGN_ID, 'd_front' );
		$line->set_side_ref( 'front', LineState::SIDE_PROOF_ID, 'proof_front' );
		$line->set_side_ref( 'front', LineState::SIDE_APPROVED, true );
		$this->assertTrue( $line->save() );
		if ( LineState::COMMITTED !== $state ) {
			$this->assertTrue( LineState::of( $item )->transition( $state ) );
		}
		if ( null !== $status ) {
			$line = LineState::of( $item );
			$line->replace_order( 'po_' . $order->get_id() );
			$line->set_ref( LineState::REF_ORDER_STATUS, $status );
			$this->assertTrue( $line->save() );
		}
		return $order;
	}

	private function plain_order(): void {
		$order = wc_create_order();
		$this->assertInstanceOf( WC_Order::class, $order );
		$order->add_product( $this->product() );
		$order->save();
	}

	/**
	 * A template-only link sold in one print size.
	 *
	 * @param float  $width_mm    Width in millimetres.
	 * @param float  $height_mm   Height in millimetres.
	 * @param string $label       The size's label; empty for none.
	 * @param bool   $personalise Whether the link personalises the product.
	 * @param bool   $override    Whether a variation's own configuration applies.
	 */
	private static function sized( float $width_mm, float $height_mm, string $label, bool $personalise = true, bool $override = false ): ProductConfig {
		return new ProductConfig(
			personalise: $personalise,
			override: $override,
			sides: array( new Side( 'design', 'Mini tee', null, null, 'mini-tee', 2, null, 'Mini tee', array( new PrintSize( $width_mm, $height_mm, $label ) ) ) ),
		);
	}

	private static function template_only(): ProductConfig {
		return new ProductConfig(
			personalise: true,
			sides: array( new Side( 'design', 'Mini tee', null, null, 'mini-tee', 2, null, 'Mini tee' ) ),
		);
	}

	/**
	 * A `GET /v1/templates/{id}/constraints` answer on the front.
	 *
	 * @return array<string, mixed>
	 */
	private function constraints(): array {
		return array(
			'templateId'     => self::TEMPLATE_ID,
			'ratio'          => array(
				'w' => 280,
				'h' => 360,
			),
			'ratioTolerance' => 0,
			'fit'            => 'contain',
			'placement'      => array(
				'blankId'            => self::BLANK_ID,
				'placementId'        => self::PLACEMENT_ID,
				'maxWidthMm'         => 330,
				'maxHeightMm'        => 420,
				'defaultWidthMm'     => 300,
				'defaultHeightMm'    => 360,
				'printWidthMm'       => 280,
				'printHeightMm'      => 360,
				'bleedMm'            => 3,
				'safeAreaMm'         => 5,
				'cmykPreviewProfile' => 'ISOcoated_v2_eci',
				'coverage'           => 'within',
				'sizes'              => array(
					'fits'    => array(),
					'misfits' => array(),
				),
			),
		);
	}

	private function product(): WC_Product_Simple {
		$product = new WC_Product_Simple();
		$product->set_name( 'Personalised tee' );
		$product->set_regular_price( '25' );
		$product->save();
		return $product;
	}

	/**
	 * @param WC_Product_Variable $product The variable product.
	 * @param string              $status  `publish` for an enabled variation, `private` for a disabled one.
	 */
	private function variation( WC_Product_Variable $product, string $status ): WC_Product_Variation {
		$variation = new WC_Product_Variation();
		$variation->set_parent_id( $product->get_id() );
		$variation->set_regular_price( '25' );
		$variation->set_status( $status );
		$variation->save();
		return $variation;
	}

	private function reload( int $product_id ): WC_Product {
		$product = wc_get_product( $product_id );
		$this->assertInstanceOf( WC_Product::class, $product );
		return $product;
	}

	private function user( string $role ): int {
		$id = self::factory()->user->create( array( 'role' => $role ) );
		return is_int( $id ) ? $id : 0;
	}

	private static function connection(): StoredConnection {
		return new StoredConnection(
			'site_fixture',
			home_url(),
			self::CONNECTED_AT,
			'tenant_fixture',
			'Fixture Prints',
			StoredConnection::LIVE,
			'conn_main',
			'ik_live_fixture_main',
			'ik_id',
			'WooCommerce · example.org',
			'pk_live_fixture_main',
			'pk_id',
			array( 'http://example.org' ),
			'wh_fixture_main',
			'http://example.org/wp-json/pixonaut/v1/webhook',
			'whsec_fixture_main'
		);
	}
}
