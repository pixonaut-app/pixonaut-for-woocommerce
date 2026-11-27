<?php
/**
 * @package Pixonaut\WooCommerce
 */

declare(strict_types=1);

namespace Pixonaut\WooCommerce\Tests\Integration\ShopTools;

use Pixonaut\WooCommerce\Abilities\SetProductValues;
use Pixonaut\WooCommerce\Api\Hosts;
use Pixonaut\WooCommerce\Api\Pixonaut;
use Pixonaut\WooCommerce\Api\ValueSetRefused;
use Pixonaut\WooCommerce\Api\ValueSets;
use Pixonaut\WooCommerce\Catalogue\CatalogueCache;
use Pixonaut\WooCommerce\Catalogue\PrintSize;
use Pixonaut\WooCommerce\Catalogue\ProductConfig;
use Pixonaut\WooCommerce\Catalogue\Side;
use Pixonaut\WooCommerce\Catalogue\SideValues;
use Pixonaut\WooCommerce\Connection\StoreAccess;
use Pixonaut\WooCommerce\Connection\StoredConnection;
use Pixonaut\WooCommerce\Plugin;
use Pixonaut\WooCommerce\Tests\Support\HttpRecorder;
use WC_Product;
use WC_Product_Simple;
use WC_Product_Variable;
use WC_Product_Variation;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;
use WP_UnitTestCase;

/**
 * `set-product-values` gives a product's or variation's sides values of
 * their own, as Pixonaut's `set_store_product_values` calls it, with a
 * scripted maker of value sets in place of Pixonaut; and the product and
 * variation reads give them back.
 */
final class ProductValuesShopToolsTest extends WP_UnitTestCase {

	private const ROUTE = '/pixonaut/v1/shop-tools/';

	private const SECRET = 'a-store-credential';

	private const TOOL = 'set-product-values';

	private const OK = 200;

	private const STORE_FAILED = 502;

	/**
	 * When the fixture's connection was made, as Unix time.
	 */
	private const CONNECTED_AT = 1767225600;

	private const TEMPLATE = 'mini-mug';

	private const VERSION = 3;

	private const SET = 'vs_3f2e9a1b7c4d5e6f8a9b0c1d';

	private const OTHER_SET = 'vs_0a1b2c3d4e5f6a7b8c9d0e1f';

	private const FARMOR = array(
		'title' => array( 'content' => 'farmor' ),
		'motif' => array( 'src' => 'asset_7c1e9a2b4d6f8e0a' ),
	);

	private const MORMOR = array( 'title' => array( 'content' => 'mormor' ) );

	private Plugin $plugin;

	private HttpRecorder $recorder;

	/**
	 * Each set the scripted maker was asked for: the template and values.
	 *
	 * @var list<array{0: string, 1: array<string, array<string, mixed>>}>
	 */
	private array $asked = array();

	/**
	 * What the scripted maker answers, in order: a set id, or what it throws.
	 *
	 * @var list<string|\Throwable>
	 */
	private array $answers = array();

	public function set_up(): void {
		parent::set_up();
		$this->plugin   = Plugin::boot( dirname( __DIR__, 4 ) . '/pixonaut-for-woocommerce.php' );
		$this->recorder = ( new HttpRecorder( Hosts::server_api() ) )->start();
		global $wp_rest_server;
		$wp_rest_server = new WP_REST_Server();
		do_action( 'rest_api_init', $wp_rest_server );
		$this->plugin->connection_store()->save( self::connection() );
		( new CatalogueCache( $this->plugin->connection_store() ) )->flush();
		update_option(
			StoreAccess::OPTION,
			array(
				'site'       => self::connection()->site,
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
		global $wp_rest_server;
		$wp_rest_server = null;
		parent::tear_down();
	}

	public function test_it_is_a_shop_tool_and_saves_nothing_while_the_plugin_cannot_ask_for_a_set(): void {
		if ( Pixonaut::offers( ValueSets::CREATE ) ) {
			$this->markTestSkipped( 'The client knows templates.valueSets.create: move it to Pixonaut::OPERATIONS.' );
		}
		$mug = $this->mug();

		$answer = $this->call(
			array(
				'product_id' => $mug->get_id(),
				'sides'      => array(
					array(
						'key'    => 'front',
						'values' => self::FARMOR,
					),
				),
			)
		);

		$this->assertSame( self::STORE_FAILED, $answer->get_status(), (string) wp_json_encode( $answer->get_data() ) );
		$this->assertSame( 'store_error', $answer->get_data()['code'] );
		$this->assertNull( SideValues::document_on( $this->reload( $mug ) ) );
	}

	public function test_a_products_sides_take_their_values_and_every_side_is_answered(): void {
		$mug           = $this->mug();
		$this->answers = array( self::SET );

		$answer = $this->set(
			array(
				'product_id' => $mug->get_id(),
				'sides'      => array(
					array(
						'key'    => 'front',
						'values' => self::FARMOR,
					),
				),
			)
		);

		$this->assertSame( array( array( self::TEMPLATE . '@' . self::VERSION, self::FARMOR ) ), $this->asked );
		$this->assertSame( $mug->get_id(), $answer['product_id'] );
		$this->assertNull( $answer['variation_id'] );
		$this->assertSame(
			array(
				array(
					'key'          => 'front',
					'template_id'  => self::TEMPLATE . '@' . self::VERSION,
					'value_set_id' => self::SET,
					'values'       => self::FARMOR,
				),
				array(
					'key'          => 'back',
					'template_id'  => self::TEMPLATE . '@' . self::VERSION,
					'value_set_id' => null,
					'values'       => null,
				),
			),
			$answer['sides']
		);

		$read  = $this->succeed( 'get-product', array( 'id' => $mug->get_id() ) );
		$sides = $read['pixonaut_setup']['sides'];
		$this->assertNull( $read['pixonaut_setup']['blank_id'] );
		$this->assertSame( array( 'front', 'back' ), array_column( $sides, 'key' ) );
		$this->assertSame( self::SET, $sides[0]['value_set_id'] );
		$this->assertSame( self::FARMOR, $sides[0]['values'] );
		$this->assertSame( self::TEMPLATE . '@' . self::VERSION, $sides[0]['template_id'] );
		$this->assertNull( $sides[1]['value_set_id'] );
	}

	public function test_null_takes_a_sides_values_off_and_keeps_the_others(): void {
		$mug           = $this->mug();
		$this->answers = array( self::SET, self::OTHER_SET );
		$this->set(
			array(
				'product_id' => $mug->get_id(),
				'sides'      => array(
					array(
						'key'    => 'front',
						'values' => self::FARMOR,
					),
					array(
						'key'    => 'back',
						'values' => self::MORMOR,
					),
				),
			)
		);

		$answer = $this->set(
			array(
				'product_id' => $mug->get_id(),
				'sides'      => array(
					array(
						'key'    => 'front',
						'values' => null,
					),
				),
			)
		);

		$this->assertSame( array( null, self::OTHER_SET ), array_column( $answer['sides'], 'value_set_id' ) );
		$this->assertCount( 2, $this->asked );
	}

	public function test_everything_wrong_with_the_sides_is_said_at_once_and_nothing_is_asked_or_saved(): void {
		$mug = $this->mug( with_template: false );

		$refused = $this->refuse(
			array(
				'product_id' => $mug->get_id(),
				'sides'      => array(
					array(
						'key'    => 'lid',
						'values' => self::FARMOR,
					),
					array(
						'key'    => 'front',
						'values' => self::FARMOR,
					),
					array(
						'key'    => 'front',
						'values' => null,
					),
					array(
						'key'    => 'back',
						'values' => self::FARMOR,
					),
				),
			)
		);

		$message = $refused->get_error_message();
		$this->assertSame( SetProductValues::REFUSED, $refused->get_error_code() );
		$this->assertStringContainsString( '`lid`', $message );
		$this->assertStringContainsString( 'side `front` is given twice', $message );
		$this->assertStringContainsString( 'side `back` has no template', $message );
		$this->assertSame( array(), $this->asked );
		$this->assertNull( SideValues::document_on( $this->reload( $mug ) ) );
	}

	public function test_values_pixonaut_refuses_are_named_by_side_layer_and_prop_and_nothing_is_saved(): void {
		$mug           = $this->mug();
		$this->answers = array(
			self::SET,
			new ValueSetRefused(
				array(
					array(
						'layer_id' => 'title',
						'prop'     => 'colour',
						'rule'     => 'unknown_prop',
					),
					array(
						'layer_id' => 'ghost',
						'prop'     => '',
						'rule'     => 'unknown_layer',
					),
				)
			),
		);

		$refused = $this->refuse(
			array(
				'product_id' => $mug->get_id(),
				'sides'      => array(
					array(
						'key'    => 'front',
						'values' => self::FARMOR,
					),
					array(
						'key'    => 'back',
						'values' => array(
							'title' => array( 'colour' => '#ff0000' ),
							'ghost' => array( 'content' => 'x' ),
						),
					),
				),
			)
		);

		$this->assertStringContainsString( 'side `back`, layer `title`, prop `colour`: `unknown_prop`', $refused->get_error_message() );
		$this->assertStringContainsString( 'side `back`, layer `ghost`: `unknown_layer`', $refused->get_error_message() );
		$this->assertNull( SideValues::document_on( $this->reload( $mug ) ) );
	}

	public function test_a_product_without_a_setup_and_a_variation_following_its_product_are_refused(): void {
		$plain = new WC_Product_Simple();
		$plain->set_name( 'Plain mug' );
		$plain->save();
		list( $mugs, $following ) = $this->variable_mug();

		$this->assertSame( SetProductValues::REFUSED, $this->refuse( self::front_values( $plain->get_id() ) )->get_error_code() );
		$this->assertStringContainsString( 'follows its product', $this->refuse( self::front_values( $mugs->get_id(), $following->get_id() ) )->get_error_message() );
		$this->assertSame( array(), $this->asked );
	}

	public function test_a_variation_with_its_own_setup_takes_its_own_values_and_the_variation_reads_give_each_its_own(): void {
		list( $mugs, $following, $own ) = $this->variable_mug();
		$this->answers                  = array( self::SET, self::OTHER_SET );
		$this->set( self::front_values( $mugs->get_id() ) );

		$answer = $this->set( self::front_values( $mugs->get_id(), $own->get_id(), self::MORMOR ) );

		$this->assertSame( $own->get_id(), $answer['variation_id'] );
		$this->assertSame( array( self::OTHER_SET ), array_column( $answer['sides'], 'value_set_id' ) );
		$following_read = $this->succeed(
			'get-product-variation',
			array(
				'product_id' => $mugs->get_id(),
				'id'         => $following->get_id(),
			)
		)['pixonaut_setup']['sides'][0];
		$own_read       = $this->succeed(
			'get-product-variation',
			array(
				'product_id' => $mugs->get_id(),
				'id'         => $own->get_id(),
			)
		)['pixonaut_setup']['sides'][0];
		$this->assertSame( 'front', $following_read['key'] );
		$this->assertSame( self::SET, $following_read['value_set_id'] );
		$this->assertSame( self::FARMOR, $following_read['values'] );
		$this->assertSame( self::OTHER_SET, $own_read['value_set_id'] );
		$this->assertSame( self::MORMOR, $own_read['values'] );
	}

	/**
	 * Input setting the front's values.
	 *
	 * @param int                                 $product_id   The product.
	 * @param int|null                            $variation_id One of its variations; null for the product.
	 * @param array<string, array<string, mixed>> $values       The values.
	 * @return array<string, mixed>
	 */
	private static function front_values( int $product_id, ?int $variation_id = null, array $values = self::FARMOR ): array {
		return array(
			'product_id' => $product_id,
			'sides'      => array(
				array(
					'key'    => 'front',
					'values' => $values,
				),
			),
		) + ( null === $variation_id ? array() : array( 'variation_id' => $variation_id ) );
	}

	/**
	 * The ability, with the scripted maker of value sets.
	 */
	private function ability(): SetProductValues {
		$test = $this;
		return new SetProductValues(
			new class( $test ) implements ValueSets {
				public function __construct( private readonly ProductValuesShopToolsTest $test ) {
				}

				public function make( string $template_id, array $values ): string {
					return $this->test->made( $template_id, $values );
				}
			}
		);
	}

	/**
	 * The scripted maker's answer to one call.
	 *
	 * @param string                              $template_id The template version.
	 * @param array<string, array<string, mixed>> $values      The values.
	 *
	 * @throws \Throwable What the script says the maker throws.
	 */
	public function made( string $template_id, array $values ): string {
		$this->asked[] = array( $template_id, $values );
		$answer        = array_shift( $this->answers );
		if ( $answer instanceof \Throwable ) {
			throw $answer;
		}
		$this->assertIsString( $answer, 'The maker was asked for more sets than it was scripted for.' );
		return $answer;
	}

	/**
	 * @param array<string, mixed> $input The ability's input.
	 * @return array<string, mixed>
	 */
	private function set( array $input ): array {
		$answer = $this->ability()->execute( $input );
		$this->assertIsArray( $answer, $answer instanceof WP_Error ? $answer->get_error_message() : '' );
		return $answer;
	}

	/**
	 * @param array<string, mixed> $input The ability's input.
	 */
	private function refuse( array $input ): WP_Error {
		$answer = $this->ability()->execute( $input );
		$this->assertInstanceOf( WP_Error::class, $answer );
		return $answer;
	}

	private static function side( string $key, bool $with_template = true ): Side {
		return new Side( $key, ucfirst( $key ), null, null, $with_template ? self::TEMPLATE : '', $with_template ? self::VERSION : null, null, 'Mini mug', array( new PrintSize( 200, 90, 'Mug' ) ) );
	}

	/**
	 * A mug printed on its front from the template, and on its back from it
	 * too, or from none.
	 *
	 * @param bool $with_template Whether the back is on the template too.
	 */
	private function mug( bool $with_template = true ): WC_Product {
		$mug = new WC_Product_Simple();
		$mug->set_name( 'Mug' );
		$mug->save();
		( new ProductConfig( personalise: true, sides: array( self::side( 'front' ), self::side( 'back', $with_template ) ) ) )->save_to( $mug );
		return $this->reload( $mug );
	}

	/**
	 * A variable mug, a variation following it and one with a setup of its own.
	 *
	 * @return array{0: WC_Product, 1: WC_Product, 2: WC_Product}
	 */
	private function variable_mug(): array {
		$mugs = new WC_Product_Variable();
		$mugs->set_name( 'Mugs' );
		$mugs->save();
		( new ProductConfig( personalise: true, sides: array( self::side( 'front' ) ) ) )->save_to( $mugs );
		$variations = array();
		foreach ( array( false, true ) as $override ) {
			$variation = new WC_Product_Variation();
			$variation->set_parent_id( $mugs->get_id() );
			$variation->set_regular_price( '199' );
			$variation->save();
			if ( $override ) {
				( new ProductConfig( personalise: true, override: true, sides: array( self::side( 'front' ) ) ) )->save_to( $variation );
			}
			$variations[] = $this->reload( $variation );
		}
		return array( $this->reload( $mugs ), $variations[0], $variations[1] );
	}

	/**
	 * @param array<mixed> $input The tool's input.
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
	 * Calls a tool that is to answer.
	 *
	 * @param string       $tool  The tool.
	 * @param array<mixed> $input Its input.
	 * @return array<string, mixed>
	 */
	private function succeed( string $tool, array $input ): array {
		$answer = $this->call( $input, $tool );
		$this->assertSame( self::OK, $answer->get_status(), (string) wp_json_encode( $answer->get_data() ) );
		$data = $answer->get_data();
		$this->assertIsArray( $data );
		$this->assertIsArray( $data['result'] );
		return $data['result'];
	}

	private function reload( WC_Product $product ): WC_Product {
		$read = wc_get_product( $product->get_id() );
		$this->assertInstanceOf( WC_Product::class, $read );
		return $read;
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
