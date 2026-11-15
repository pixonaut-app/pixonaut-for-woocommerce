<?php
/**
 * @package Pixonaut\WooCommerce
 */

declare(strict_types=1);

namespace Pixonaut\WooCommerce\Tests\Integration\ShopTools;

use Pixonaut\WooCommerce\Abilities\Abilities;
use Pixonaut\WooCommerce\Abilities\SetVariationPrintSize;
use Pixonaut\WooCommerce\Admin\Notices;
use Pixonaut\WooCommerce\Api\Catalogue;
use Pixonaut\WooCommerce\Api\Hosts;
use Pixonaut\WooCommerce\Catalogue\InvalidReason;
use Pixonaut\WooCommerce\Catalogue\PrintSize;
use Pixonaut\WooCommerce\Catalogue\ProductConfig;
use Pixonaut\WooCommerce\Catalogue\ProductVariant;
use Pixonaut\WooCommerce\Catalogue\RowSize;
use Pixonaut\WooCommerce\Catalogue\Side;
use Pixonaut\WooCommerce\Catalogue\VariationSize;
use Pixonaut\WooCommerce\Connection\Connection;
use Pixonaut\WooCommerce\Connection\StoreAccess;
use Pixonaut\WooCommerce\Connection\StoredConnection;
use Pixonaut\WooCommerce\Plugin;
use Pixonaut\WooCommerce\Tests\Support\BlankFixture;
use Pixonaut\WooCommerce\Tests\Support\DesignFixture;
use Pixonaut\WooCommerce\Tests\Support\HttpRecorder;
use Pixonaut\WooCommerce\Tests\Support\OpenApiAssert;
use Pixonaut\WooCommerce\Tests\Support\VariantsAnswer;
use Pixonaut\WooCommerce\Tests\Support\VerdictAnswers;
use WC_Product;
use WC_Product_Attribute;
use WC_Product_Simple;
use WC_Product_Variable;
use WC_Product_Variation;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;
use WP_UnitTestCase;

/**
 * The validity the product and variation reads give, and the shop tool that
 * sets a variation's own print size, called on the shop tool route as
 * Pixonaut calls them, against a recorder that answers as Pixonaut.
 */
final class VariationPrintSizeTest extends WP_UnitTestCase {

	public const ABILITY = Abilities::NAMESPACE . '/' . SetVariationPrintSize::NAME;

	private const TOOL = SetVariationPrintSize::NAME;

	private const ROUTE = '/pixonaut/v1/shop-tools/';

	private const SECRET = 'a-store-credential';

	private const TEMPLATE_BASE_ID = 'mini-poster';

	private const TEMPLATE_VERSION = 1;

	/**
	 * The template of a variation's own setup it no longer uses.
	 */
	private const OLD_TEMPLATE_BASE_ID = 'old-poster';

	private const WIDTH_MM = 300.0;

	private const HEIGHT_MM = 400.0;

	private const LABEL = 'A3';

	/**
	 * The size the product sells before a variation is given its own.
	 */
	private const PRODUCT_SIZE_MM = 200.0;

	private const BLANK_ID = DesignFixture::BLANK_ID;

	private const SMALL_ROW = '30x40';

	private const LARGE_ROW = '40x50';

	/**
	 * The print area of each size of the poster on a blank, in millimetres.
	 */
	private const ROW_AREAS = array(
		self::SMALL_ROW => array( 300.0, 400.0 ),
		self::LARGE_ROW => array( 400.0, 500.0 ),
	);

	/**
	 * The blank's variant of each size.
	 */
	private const ROW_REFS = array(
		self::SMALL_ROW => 'v_small',
		self::LARGE_ROW => 'v_large',
	);

	/**
	 * What a valid product or variation reads as.
	 */
	private const VALID = array(
		'valid'   => true,
		'reason'  => null,
		'message' => null,
	);

	private Plugin $plugin;

	private HttpRecorder $recorder;

	public function set_up(): void {
		parent::set_up();
		$this->plugin   = Plugin::boot( dirname( __DIR__, 4 ) . '/pixonaut-for-woocommerce.php' );
		$this->recorder = ( new HttpRecorder( Hosts::server_api() ) )->start();
		global $wp_rest_server;
		$wp_rest_server = new WP_REST_Server();
		do_action( 'rest_api_init', $wp_rest_server );
		$this->plugin->connection_store()->save( self::connection() );
		// A product without a blank is checked against the printshops it may be sent to.
		BlankFixture::remember_printshops( $this->plugin->catalogue_cache() );
		update_option(
			StoreAccess::OPTION,
			array(
				'site'       => 'site-a',
				'hash'       => hash( 'sha256', self::SECRET ),
				'cut'        => false,
				'changed_at' => 0,
			),
			false
		);
		wp_set_current_user( 0 );
	}

	public function tear_down(): void {
		$this->recorder->stop();
		$this->plugin->connection_store()->clear();
		delete_option( StoreAccess::OPTION );
		delete_option( Notices::OPTION );
		global $wp_rest_server;
		$wp_rest_server = null;
		parent::tear_down();
	}

	public function test_a_valid_product_and_one_not_personalised_read_as_valid(): void {
		$linked = $this->simple( new ProductConfig( personalise: true, sides: array( self::side() ) ) );
		$plain  = $this->simple( null );

		$read   = $this->succeed( array( 'id' => $linked->get_id() ), 'get-product' );
		$listed = $this->succeed( array( 'query' => array( 'include' => array( $linked->get_id(), $plain->get_id() ) ) ), 'list-products' );

		$this->assertSame( self::VALID, $read['pixonaut_validity'] );
		$by_id = array_column( $listed['items'], 'pixonaut_validity', 'id' );
		$this->assertSame( self::VALID, $by_id[ $linked->get_id() ] );
		$this->assertSame( self::VALID, $by_id[ $plain->get_id() ] );
		$this->assertArrayNotHasKey( 'pixonaut_variant', $read, 'a product gives no blank variant' );
		$this->assertSame( array(), $this->recorder->requests, 'no call to Pixonaut' );
	}

	/**
	 * @return array<string, array{0: string}>
	 */
	public static function reasons(): array {
		return array(
			'too large'          => array( InvalidReason::TOO_LARGE ),
			'wrong ratio'        => array( InvalidReason::WRONG_RATIO ),
			'missing print size' => array( InvalidReason::MISSING_PRINT_SIZE ),
			'template not live'  => array( InvalidReason::TEMPLATE_NOT_LIVE ),
			'unknown blank'      => array( InvalidReason::UNKNOWN_BLANK ),
			'check failed'       => array( InvalidReason::CHECK_FAILED ),
		);
	}

	/**
	 * @dataProvider reasons
	 *
	 * @param string $reason Why the variation cannot be sold.
	 */
	public function test_an_invalid_variation_reads_with_its_reason_and_the_text_the_product_screen_shows( string $reason ): void {
		$product   = $this->poster();
		$invalid   = $this->variation( $product, 'Small' );
		$following = $this->variation( $product, 'Large' );
		( new ProductConfig( personalise: true, override: true, sides: array( self::side() ) ) )->with_validity( false, $reason )->save_to( $invalid );

		$listed = $this->succeed( array( 'product_id' => $product->get_id() ), 'list-product-variations' );
		$read   = $this->succeed(
			array(
				'product_id' => $product->get_id(),
				'id'         => $invalid->get_id(),
			),
			'get-product-variation'
		);

		$expected = array(
			'valid'   => false,
			'reason'  => $reason,
			'message' => InvalidReason::message( $reason, null, true ),
		);
		$this->assertSame( $expected, $read['pixonaut_validity'] );
		$this->assertArrayHasKey( 'pixonaut_variant', $read );
		$by_id = array_column( $listed['items'], 'pixonaut_validity', 'id' );
		$this->assertSame( $expected, $by_id[ $invalid->get_id() ] );
		$this->assertSame( self::VALID, $by_id[ $following->get_id() ], 'a variation following its product reads as the product' );
		$this->assertSame( self::VALID, $this->succeed( array( 'id' => $product->get_id() ), 'get-product' )['pixonaut_validity'] );
	}

	public function test_a_variation_following_a_product_without_a_blank_gets_its_own_print_size(): void {
		$product   = $this->poster();
		$variation = $this->variation( $product, 'Small' );
		$this->recorder->json( 200, array( 'verdicts' => array( VerdictAnswers::LEGAL ) ) );
		$this->recorder->raw( 200, OpenApiAssert::example( 'GET', '/v1/templates/{id}', 200, array( 'status' => Catalogue::LIVE ) ), 'application/json' );

		$answer = $this->succeed(
			array(
				'variation_id' => $variation->get_id(),
				'width_mm'     => self::WIDTH_MM,
				'height_mm'    => self::HEIGHT_MM,
				'label'        => self::LABEL,
			)
		);

		$this->assertSame(
			array(
				'variation_id'      => $variation->get_id(),
				'pixonaut_validity' => self::VALID,
				'pixonaut_variant'  => null,
			),
			$answer
		);
		$this->assertStringEndsWith( '/v1/templates/' . self::TEMPLATE_BASE_ID . '%40' . self::TEMPLATE_VERSION . '/print-size', $this->recorder->requests[0]['url'] );
		$own = ProductConfig::stored_on( $this->reload( $variation ) );
		$this->assertTrue( $own->override );
		$this->assertTrue( $own->valid );
		$this->assertCount( 1, $own->sides );
		$this->assertEquals( array( new PrintSize( self::WIDTH_MM, self::HEIGHT_MM, self::LABEL ) ), $own->sides[0]->print_sizes );
		$this->assertSame( self::PRODUCT_SIZE_MM, ProductConfig::stored_on( $this->reload( $product ) )->sides[0]->print_sizes[0]->width_mm, 'the product keeps its own' );
	}

	public function test_a_size_set_on_a_variation_replaces_the_size_it_had_without_overriding(): void {
		$product   = $this->poster();
		$variation = $this->variation( $product, 'Small' );
		$kept      = new PrintSize( self::PRODUCT_SIZE_MM, self::PRODUCT_SIZE_MM, '' );
		$own       = array(
			'size'    => $kept,
			'refused' => null,
			'basis'   => VariationSize::basis( self::side(), null, null ),
		);
		( new VariationSize( array( 'front' => $own ) ) )->save_to( $variation );
		$this->recorder->json( 200, array( 'verdicts' => array( VerdictAnswers::LEGAL ) ) );
		$this->recorder->raw( 200, OpenApiAssert::example( 'GET', '/v1/templates/{id}', 200, array( 'status' => Catalogue::LIVE ) ), 'application/json' );

		$answer = $this->succeed(
			array(
				'variation_id' => $variation->get_id(),
				'width_mm'     => self::WIDTH_MM,
				'height_mm'    => self::HEIGHT_MM,
				'label'        => self::LABEL,
			)
		);

		$this->assertSame( self::VALID, $answer['pixonaut_validity'] );
		$saved = $this->reload( $variation );
		$this->assertSame( array(), VariationSize::stored_on( $saved )->sizes, 'the overriding setup has its own size' );
		$this->assertEquals( array( new PrintSize( self::WIDTH_MM, self::HEIGHT_MM, self::LABEL ) ), ProductConfig::config_for( $saved )->sides[0]->print_sizes );
	}

	public function test_a_size_pixonaut_cannot_print_is_refused_with_the_reason_and_nothing_is_saved(): void {
		$product   = $this->poster();
		$variation = $this->variation( $product, 'Small' );
		$this->recorder->json(
			200,
			array(
				'verdicts' => array(
					array(
						'legal'         => false,
						'reason'        => InvalidReason::WRONG_RATIO,
						'lowResolution' => array(),
					),
				),
			)
		);
		$this->recorder->raw( 200, OpenApiAssert::example( 'GET', '/v1/templates/{id}', 200, array( 'status' => Catalogue::LIVE ) ), 'application/json' );

		$answer = $this->call(
			array(
				'variation_id' => $variation->get_id(),
				'width_mm'     => self::WIDTH_MM,
				'height_mm'    => self::WIDTH_MM,
			)
		);

		$this->assertSame( 422, $answer->get_status() );
		$this->assertSame(
			array(
				'code'    => 'invalid_print_size',
				'message' => InvalidReason::message( InvalidReason::WRONG_RATIO, null, true ),
				'reason'  => InvalidReason::WRONG_RATIO,
			),
			$answer->get_data()
		);
		$this->assertFalse( ProductConfig::is_stored_on( $this->reload( $variation ) ), 'the variation still follows its product' );
	}

	public function test_a_size_that_is_no_size_is_refused_before_pixonaut_is_asked(): void {
		$product   = $this->poster();
		$variation = $this->variation( $product, 'Small' );

		$answer = $this->call(
			array(
				'variation_id' => $variation->get_id(),
				'width_mm'     => 0,
				'height_mm'    => self::HEIGHT_MM,
			)
		);

		$this->assertSame( 422, $answer->get_status() );
		$this->assertSame( 'invalid_print_size', $answer->get_data()['code'] );
		$this->assertSame( InvalidReason::INVALID_PRINT_SIZE, $answer->get_data()['reason'] );
		$this->assertSame( array(), $this->recorder->requests );
		$this->assertFalse( ProductConfig::is_stored_on( $this->reload( $variation ) ) );
	}

	public function test_a_variation_following_its_product_starts_from_the_products_setup_not_its_unused_own(): void {
		$product   = $this->poster();
		$variation = $this->variation( $product, 'Small' );
		$unused    = new Side( 'front', 'Front', null, null, self::OLD_TEMPLATE_BASE_ID, self::TEMPLATE_VERSION, null, 'Old poster', array( new PrintSize( self::PRODUCT_SIZE_MM, self::PRODUCT_SIZE_MM, '' ) ) );
		( new ProductConfig( personalise: false, override: false, sides: array( $unused ) ) )->save_to( $variation );
		$this->recorder->json( 200, array( 'verdicts' => array( VerdictAnswers::LEGAL ) ) );
		$this->recorder->raw( 200, OpenApiAssert::example( 'GET', '/v1/templates/{id}', 200, array( 'status' => Catalogue::LIVE ) ), 'application/json' );

		$answer = $this->succeed(
			array(
				'variation_id' => $variation->get_id(),
				'width_mm'     => self::WIDTH_MM,
				'height_mm'    => self::HEIGHT_MM,
			)
		);

		$this->assertSame( self::VALID, $answer['pixonaut_validity'] );
		$this->assertStringContainsString( '/v1/templates/' . self::TEMPLATE_BASE_ID . '%40', $this->recorder->requests[0]['url'] );
		$own = ProductConfig::stored_on( $this->reload( $variation ) );
		$this->assertTrue( $own->override );
		$this->assertTrue( $own->personalise );
		$this->assertSame( self::TEMPLATE_BASE_ID, $own->sides[0]->template_base_id, 'the template it is sold with' );
	}

	public function test_a_size_that_could_not_be_checked_is_saved_unchecked_as_the_panel_saves_it(): void {
		$product   = $this->poster();
		$variation = $this->variation( $product, 'Small' );
		$this->recorder->fail();
		$this->recorder->fail();

		$answer = $this->succeed(
			array(
				'variation_id' => $variation->get_id(),
				'width_mm'     => self::WIDTH_MM,
				'height_mm'    => self::HEIGHT_MM,
			)
		);

		$this->assertFalse( $answer['pixonaut_validity']['valid'] );
		$own = ProductConfig::stored_on( $this->reload( $variation ) );
		$this->assertTrue( $own->override );
		$this->assertSame( array(), $own->sides[0]->print_sizes, 'nothing unchecked is sold' );
		$this->assertEquals( array( new PrintSize( self::WIDTH_MM, self::HEIGHT_MM, PrintSize::label_for( self::WIDTH_MM, self::HEIGHT_MM ) ) ), $own->sides[0]->pending_sizes, 'kept to be checked again' );
		$this->assertSame( self::PRODUCT_SIZE_MM, ProductConfig::stored_on( $this->reload( $product ) )->sides[0]->print_sizes[0]->width_mm, 'the product keeps its own' );
	}

	public function test_a_variation_with_a_second_unfinished_side_is_refused_and_keeps_it(): void {
		$unfinished = new Side( 'back', 'Back', null, null, '', null, null, null );
		$product    = $this->poster( new ProductConfig( personalise: true, sides: array( self::side(), $unfinished ) ) );
		$variation  = $this->variation( $product, 'Small' );

		$answer = $this->call(
			array(
				'variation_id' => $variation->get_id(),
				'width_mm'     => self::WIDTH_MM,
				'height_mm'    => self::HEIGHT_MM,
			)
		);

		$this->assertSame( 422, $answer->get_status() );
		$this->assertSame( 'invalid_input', $answer->get_data()['code'] );
		$this->assertFalse( ProductConfig::is_stored_on( $this->reload( $variation ) ) );
		$this->assertSame( array(), $this->recorder->requests );
	}

	public function test_clear_takes_off_a_size_a_variation_following_its_product_was_given(): void {
		$product   = $this->poster();
		$variation = $this->variation( $product, 'Small' );
		$own       = array(
			'size'    => new PrintSize( self::WIDTH_MM, self::HEIGHT_MM, self::LABEL ),
			'refused' => null,
			'basis'   => 'judged',
		);
		( new VariationSize( array( 'front' => $own ) ) )->save_to( $variation );

		$answer = $this->succeed(
			array(
				'variation_id' => $variation->get_id(),
				'clear'        => true,
			)
		);

		$this->assertSame( $variation->get_id(), $answer['variation_id'] );
		$this->assertSame( self::VALID, $answer['pixonaut_validity'] );
		$this->assertSame( array(), VariationSize::stored_on( $this->reload( $variation ) )->sizes );
		$this->assertFalse( ProductConfig::is_stored_on( $this->reload( $variation ) ), 'it gets no setup of its own' );
		$this->assertSame( array(), $this->recorder->requests, 'no call to Pixonaut' );
	}

	public function test_clear_with_a_size_or_on_a_setup_without_a_blank_is_bad_input(): void {
		$product   = $this->poster();
		$variation = $this->variation( $product, 'Small' );

		$both = $this->call(
			array(
				'variation_id' => $variation->get_id(),
				'width_mm'     => self::WIDTH_MM,
				'height_mm'    => self::HEIGHT_MM,
				'clear'        => true,
			)
		);
		( new ProductConfig( personalise: true, override: true, sides: array( self::side() ) ) )->save_to( $variation );
		$no_placement = $this->call(
			array(
				'variation_id' => $variation->get_id(),
				'clear'        => true,
			)
		);

		$this->assertSame( 422, $both->get_status() );
		$this->assertSame( 'invalid_input', $both->get_data()['code'] );
		$this->assertSame( 422, $no_placement->get_status() );
		$this->assertSame( 'invalid_input', $no_placement->get_data()['code'] );
		$this->assertSame( self::PRODUCT_SIZE_MM, ProductConfig::stored_on( $this->reload( $variation ) )->sides[0]->print_sizes[0]->width_mm, 'its own setup is kept' );
		$this->assertSame( array(), $this->recorder->requests );
	}

	public function test_on_a_placement_printed_across_its_area_a_variation_takes_its_own_rows_area_alone(): void {
		list( $small, $large ) = $this->poster_on_rows();
		$variants              = $this->row_variants()->start();

		// Another row's area is refused here, once the setup's own check found a row that takes it.
		$this->script_row_check();
		$other = $this->call(
			array(
				'variation_id' => $large->get_id(),
				'width_mm'     => self::ROW_AREAS[ self::SMALL_ROW ][0],
				'height_mm'    => self::ROW_AREAS[ self::SMALL_ROW ][1],
			)
		);
		$this->assertSame( 422, $other->get_status(), (string) wp_json_encode( $other->get_data() ) );
		$this->assertSame( InvalidReason::PRINT_SIZE_NOT_AREA, $other->get_data()['reason'] );
		$this->assertFalse( ProductConfig::is_stored_on( $this->reload( $large ) ), 'nothing is saved' );

		$this->recorder->requests = array();
		$this->script_row_check();
		$answer = $this->succeed(
			array(
				'variation_id' => $large->get_id(),
				'width_mm'     => self::ROW_AREAS[ self::LARGE_ROW ][0],
				'height_mm'    => self::ROW_AREAS[ self::LARGE_ROW ][1],
			)
		);
		$variants->stop();

		$this->assertSame( self::VALID, $answer['pixonaut_validity'] );
		$asked = array_values( array_filter( $this->recorder->requests, static fn ( array $request ): bool => str_contains( $request['url'], '/print-size' ) ) );
		$this->assertCount( 1, $asked );
		$this->assertSame( array( 'sizes' ), array_keys( json_decode( (string) $asked[0]['body'], true ) ), 'the template\'s ratio alone is asked: the area is the row\'s' );
		$this->assertTrue( ProductConfig::stored_on( $this->reload( $large ) )->override );
		$this->assertFalse( ProductConfig::is_stored_on( $this->reload( $small ) ), 'only that variation' );
	}

	public function test_clear_puts_a_variation_with_its_own_setup_back_at_its_rows_area(): void {
		list( , $large ) = $this->poster_on_rows();
		$variants        = $this->row_variants()->start();
		$this->script_row_check();
		$this->succeed(
			array(
				'variation_id' => $large->get_id(),
				'width_mm'     => self::ROW_AREAS[ self::LARGE_ROW ][0],
				'height_mm'    => self::ROW_AREAS[ self::LARGE_ROW ][1],
				'label'        => self::LABEL,
			)
		);
		$this->assertSame( self::LABEL, ProductConfig::config_for( $this->reload( $large ) )->sides[0]->print_sizes[0]->label );

		$this->recorder->requests = array();
		$this->script_row_check( false );
		$answer = $this->succeed(
			array(
				'variation_id' => $large->get_id(),
				'clear'        => true,
			)
		);
		$variants->stop();

		$this->assertSame( self::VALID, $answer['pixonaut_validity'] );
		$own = ProductConfig::stored_on( $this->reload( $large ) );
		$this->assertTrue( $own->sides[0]->print_sizes[0]->placement_default, 'its side is sold at the placement\'s size' );
		$sold = ProductConfig::config_for( $this->reload( $large ) )->sides[0]->print_sizes[0];
		$this->assertSame( self::ROW_AREAS[ self::LARGE_ROW ], array( $sold->width_mm, $sold->height_mm ), 'which is its row\'s area' );
	}

	public function test_a_variation_whose_variant_has_no_print_area_takes_no_size(): void {
		list( , $large ) = $this->poster_on_rows();
		( new ProductVariant( self::BLANK_ID, 'v_other', 'v_other', '50x70' ) )->save_to( $large );
		$variants = $this->row_variants()->start();
		$this->script_row_check();

		$answer = $this->call(
			array(
				'variation_id' => $large->get_id(),
				'width_mm'     => self::ROW_AREAS[ self::LARGE_ROW ][0],
				'height_mm'    => self::ROW_AREAS[ self::LARGE_ROW ][1],
			)
		);
		$variants->stop();

		$this->assertSame( 422, $answer->get_status(), (string) wp_json_encode( $answer->get_data() ) );
		$this->assertSame( InvalidReason::VARIANT_SIZE_UNFIT, $answer->get_data()['reason'] );
		$this->assertFalse( ProductConfig::is_stored_on( $this->reload( $large ) ) );
	}

	public function test_clear_keeps_the_size_a_variations_attribute_value_gives_it(): void {
		$product   = $this->poster();
		$variation = $this->variation( $product, 'Small' );
		$entry     = static fn ( array $more ): array => array(
			'size'    => new PrintSize( self::WIDTH_MM, self::HEIGHT_MM, self::LABEL ),
			'refused' => null,
			'basis'   => 'judged',
		) + $more;
		( new VariationSize(
			array(
				'front' => $entry( array( 'value' => true ) ),
				'back'  => $entry( array() ),
			)
		) )->save_to( $variation );

		$this->succeed(
			array(
				'variation_id' => $variation->get_id(),
				'clear'        => true,
			)
		);

		$this->assertSame( array( 'front' ), array_keys( VariationSize::stored_on( $this->reload( $variation ) )->sizes ) );
	}

	/**
	 * @return array<string, array{0: string}>
	 */
	public static function unsettable(): array {
		return array(
			'not personalised' => array( 'plain' ),
			'not a variation'  => array( 'product' ),
		);
	}

	/**
	 * @dataProvider unsettable
	 *
	 * @param string $which What the call names.
	 */
	public function test_a_variation_not_personalised_or_a_product_is_bad_input( string $which ): void {
		$product   = $this->poster( 'plain' === $which ? new ProductConfig() : null );
		$variation = $this->variation( $product, 'Small' );

		$answer = $this->call(
			array(
				'variation_id' => 'product' === $which ? $product->get_id() : $variation->get_id(),
				'width_mm'     => self::WIDTH_MM,
				'height_mm'    => self::HEIGHT_MM,
			)
		);

		$this->assertSame( 422, $answer->get_status() );
		$this->assertSame( 'invalid_input', $answer->get_data()['code'] );
		$this->assertFalse( ProductConfig::is_stored_on( $this->reload( $variation ) ) );
	}

	/**
	 * @param array<mixed> $input Its input.
	 * @param string       $tool  The tool.
	 * @return array<string, mixed> Its result.
	 */
	private function succeed( array $input, string $tool = self::TOOL ): array {
		$answer = $this->call( $input, $tool );
		$this->assertSame( 200, $answer->get_status(), (string) wp_json_encode( $answer->get_data() ) );
		$data = $answer->get_data();
		$this->assertIsArray( $data );
		$this->assertIsArray( $data['result'] );
		return $data['result'];
	}

	/**
	 * @param array<mixed> $input Its input.
	 * @param string       $tool  The tool.
	 */
	private function call( array $input, string $tool = self::TOOL ): WP_REST_Response {
		$request = new WP_REST_Request( 'POST', self::ROUTE . $tool );
		$request->set_header( 'Content-Type', 'application/json' );
		$request->set_header( 'Authorization', 'Bearer ' . self::SECRET );
		$request->set_body( (string) wp_json_encode( (object) $input ) );
		return rest_do_request( $request );
	}

	/**
	 * A template-only side, sold in one size.
	 */
	private static function side(): Side {
		return new Side( 'front', 'Front', null, null, self::TEMPLATE_BASE_ID, self::TEMPLATE_VERSION, null, 'Mini poster', array( new PrintSize( self::PRODUCT_SIZE_MM, self::PRODUCT_SIZE_MM, '' ) ) );
	}

	/**
	 * @param ProductConfig|null $config Its configuration; null for none.
	 */
	private function simple( ?ProductConfig $config ): WC_Product_Simple {
		$product = new WC_Product_Simple();
		$product->set_name( 'Print' );
		$product->set_regular_price( '25' );
		$product->save();
		$config?->save_to( $product );
		return $product;
	}

	/**
	 * A variable poster personalised without a blank.
	 *
	 * @param ProductConfig|null $config Its configuration; null for a template-only one, personalised.
	 */
	private function poster( ?ProductConfig $config = null ): WC_Product_Variable {
		$product   = new WC_Product_Variable();
		$attribute = new WC_Product_Attribute();
		$attribute->set_name( 'Size' );
		$attribute->set_options( array( 'Small', 'Large' ) );
		$attribute->set_variation( true );
		$product->set_name( 'Poster' );
		$product->set_attributes( array( $attribute ) );
		$product->save();
		( $config ?? new ProductConfig( personalise: true, sides: array( self::side() ) ) )->save_to( $product );
		return $product;
	}

	/**
	 * A variable poster on a blank whose front is printed across its whole
	 * area, 300 × 400 mm in one size and 400 × 500 mm in the other, sold at
	 * the placement's size, each variation paired with its size's variant.
	 *
	 * @return array{WC_Product_Variation, WC_Product_Variation} The small and the large variation.
	 */
	private function poster_on_rows(): array {
		$rows = array();
		foreach ( self::ROW_AREAS as $size => list( $width, $height ) ) {
			$rows[] = new RowSize( array( 'Size' => $size ), array( self::ROW_REFS[ $size ] ), $width, $height, true, new PrintSize( $width, $height, PrintSize::label_for( $width, $height ) ) );
		}
		$default = new PrintSize( self::ROW_AREAS[ self::LARGE_ROW ][0], self::ROW_AREAS[ self::LARGE_ROW ][1], '', true );
		$side    = ( new Side( DesignFixture::PLACEMENT_ID, 'Front', DesignFixture::PLACEMENT_ID, 'Front', self::TEMPLATE_BASE_ID, self::TEMPLATE_VERSION, null, 'Mini poster', array( $default ) ) )->with_row_sizes( $rows );
		$product = $this->poster( new ProductConfig( personalise: true, blank_id: self::BLANK_ID, blank_name: 'Poster', sides: array( $side ) ) );
		$made    = array();
		foreach ( array_keys( self::ROW_AREAS ) as $size ) {
			$variation = $this->variation( $product, self::SMALL_ROW === $size ? 'Small' : 'Large' );
			( new ProductVariant( self::BLANK_ID, self::ROW_REFS[ $size ], self::ROW_REFS[ $size ], $size ) )->save_to( $variation );
			$made[] = $variation;
		}
		return array( $made[0], $made[1] );
	}

	/**
	 * Answers every read of the blank's variants: one per size.
	 */
	private function row_variants(): VariantsAnswer {
		$rows = array();
		foreach ( self::ROW_REFS as $size => $ref ) {
			$rows[] = array(
				'ref'     => $ref,
				'options' => array( 'Size' => $size ),
			);
		}
		$variants                        = new VariantsAnswer();
		$variants->answer                = BlankFixture::variants( $rows, array( 'Size' ) );
		$variants->answer['areasVaryBy'] = array( 'Size' );
		return $variants;
	}

	/**
	 * Scripts what a check of a variation's setup on the poster's blank
	 * reads: the blank, the template's constraints on its front, the verdict
	 * of a size asked about, and the template.
	 *
	 * @param bool $entered Whether the setup checked is sold at a size entered, in place of the placement's.
	 */
	private function script_row_check( bool $entered = true ): void {
		$blank                              = BlankFixture::answer();
		$blank['areasVaryBy']               = array( 'Size' );
		$blank['placements'][0]['coverage'] = 'full';
		$blank['placements'][0]['areas']    = array();
		foreach ( self::ROW_AREAS as $size => list( $width, $height ) ) {
			$blank['placements'][0]['areas'][] = array(
				'row'           => array( 'Size' => $size ),
				'widthMm'       => $width,
				'heightMm'      => $height,
				'usualWidthMm'  => $width,
				'usualHeightMm' => $height,
			);
		}
		$this->recorder->json( 200, $blank );
		$this->recorder->json(
			200,
			array(
				'templateId'     => self::TEMPLATE_BASE_ID . '@' . self::TEMPLATE_VERSION,
				'ratio'          => array(
					'w' => 3,
					'h' => 4,
				),
				'ratioTolerance' => 0.1,
				'fit'            => 'contain',
				'placement'      => array(
					'blankId'            => self::BLANK_ID,
					'placementId'        => DesignFixture::PLACEMENT_ID,
					'maxWidthMm'         => self::ROW_AREAS[ self::LARGE_ROW ][0],
					'maxHeightMm'        => self::ROW_AREAS[ self::LARGE_ROW ][1],
					'defaultWidthMm'     => self::ROW_AREAS[ self::LARGE_ROW ][0],
					'defaultHeightMm'    => self::ROW_AREAS[ self::LARGE_ROW ][1],
					'printWidthMm'       => self::ROW_AREAS[ self::LARGE_ROW ][0],
					'printHeightMm'      => self::ROW_AREAS[ self::LARGE_ROW ][1],
					'bleedMm'            => 3,
					'safeAreaMm'         => 5,
					'cmykPreviewProfile' => 'ISOcoated_v2_eci',
					'coverage'           => 'full',
					'sizes'              => array(
						'fits'    => array_keys( self::ROW_AREAS ),
						'misfits' => array(),
					),
				),
			)
		);
		// A size entered is asked about alone; the placement's default as each row's own area.
		$this->recorder->json( 200, array( 'verdicts' => array_fill( 0, $entered ? 1 : count( self::ROW_AREAS ), VerdictAnswers::LEGAL ) ) );
		$this->recorder->raw( 200, OpenApiAssert::example( 'GET', '/v1/templates/{id}', 200, array( 'status' => Catalogue::LIVE ) ), 'application/json' );
	}

	/**
	 * @param WC_Product_Variable $product The variable product.
	 * @param string              $size    Its size.
	 */
	private function variation( WC_Product_Variable $product, string $size ): WC_Product_Variation {
		$variation = new WC_Product_Variation();
		$variation->set_parent_id( $product->get_id() );
		$variation->set_attributes( array( 'size' => $size ) );
		$variation->set_regular_price( '25' );
		$variation->save();
		return $variation;
	}

	private function reload( WC_Product $product ): WC_Product {
		wp_cache_delete( (string) $product->get_id(), 'post_meta' );
		$reloaded = wc_get_product( $product->get_id() );
		$this->assertInstanceOf( WC_Product::class, $reloaded );
		return $reloaded;
	}

	private static function connection(): StoredConnection {
		return StoredConnection::from_array(
			array(
				'site'                  => 'site-a',
				'home_url'              => Connection::site_url(),
				'connected_at'          => 1790000000,
				'tenant_id'             => 'tenant',
				'tenant_name'           => 'Acme',
				'environment'           => 'live',
				'connection_id'         => 'sc_1',
				'integration_key_id'    => 'key-id',
				'integration_key'       => 'ik_live_0123456789abcdef01234567',
				'integration_key_label' => 'Shop',
				'publishable_key_id'    => 'pub-id',
				'publishable_key'       => 'pk_live_89abcdef0123456789abcdef',
				'allowed_origins'       => array( 'http://example.org' ),
				'webhook_id'            => 'hook',
				'webhook_url'           => 'http://example.org/hook',
				'webhook_secret'        => 'whsec_0123456789abcdef0123456789abcdef',
			)
		);
	}
}
