<?php
/**
 * @package Pixonaut\WooCommerce
 */

declare(strict_types=1);

namespace Pixonaut\WooCommerce\Tests\Integration\ShopTools;

use Pixonaut\WooCommerce\Abilities\Abilities;
use Pixonaut\WooCommerce\Admin\Notices;
use Pixonaut\WooCommerce\Admin\VariantNotice;
use Pixonaut\WooCommerce\Api\Hosts;
use Pixonaut\WooCommerce\Catalogue\ProductConfig;
use Pixonaut\WooCommerce\Catalogue\ProductVariant;
use Pixonaut\WooCommerce\Catalogue\ProductVariants;
use Pixonaut\WooCommerce\Catalogue\Side;
use Pixonaut\WooCommerce\Connection\Connection;
use Pixonaut\WooCommerce\Connection\StoreAccess;
use Pixonaut\WooCommerce\Connection\StoredConnection;
use Pixonaut\WooCommerce\Plugin;
use Pixonaut\WooCommerce\Tests\Support\BlankFixture;
use Pixonaut\WooCommerce\Tests\Support\HttpRecorder;
use Pixonaut\WooCommerce\Tests\Support\VariantsAnswer;
use ReflectionProperty;
use WC_Product;
use WC_Product_Attribute;
use WC_Product_Simple;
use WC_Product_Variable;
use WC_Product_Variation;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;
use WP_UnitTestCase;

/**
 * The shop tool that sets which blank variant each variation is printed
 * as, and the blank variant the variation reads give, called on the shop
 * tool route as Pixonaut calls them.
 */
final class SetVariationVariantsTest extends WP_UnitTestCase {

	public const ABILITY = Abilities::NAMESPACE . '/set-variation-variants';

	private const TOOL = 'set-variation-variants';

	private const ROUTE = '/pixonaut/v1/shop-tools/';

	private const SECRET = 'a-store-credential';

	private const BLANK_ID = 'blank_poster';

	private const OTHER_BLANK_ID = 'blank_canvas';

	private const SMALL = '16x20" / 40x50cm';

	private const LARGE = '20x28" / 50x70cm';

	private const HUGE = '24x36" / 60x90cm';

	/**
	 * What a variation that sells no variant reads as.
	 */
	private const UNMAPPED = array(
		'ref'     => null,
		'label'   => '',
		'picked'  => false,
		'problem' => ProductVariant::UNMAPPED,
	);

	private Plugin $plugin;

	private HttpRecorder $recorder;

	private VariantsAnswer $variants;

	public function set_up(): void {
		parent::set_up();
		$this->plugin           = Plugin::boot( dirname( __DIR__, 4 ) . '/pixonaut-for-woocommerce.php' );
		$this->recorder         = ( new HttpRecorder( Hosts::server_api() ) )->start();
		$this->variants         = ( new VariantsAnswer() )->start();
		$this->variants->answer = self::sizes();
		$this->forget_reads();
		global $wp_rest_server;
		$wp_rest_server = new WP_REST_Server();
		do_action( 'rest_api_init', $wp_rest_server );
		$this->plugin->connection_store()->save( self::connection() );
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
		$this->variants->stop();
		$this->plugin->connection_store()->clear();
		delete_option( StoreAccess::OPTION );
		delete_option( Notices::OPTION );
		global $wp_rest_server;
		$wp_rest_server = null;
		parent::tear_down();
	}

	public function test_it_is_a_shop_tool(): void {
		$answer = $this->call( array() );

		$this->assertSame( 422, $answer->get_status() );
		$this->assertSame( 'invalid_input', $answer->get_data()['code'] );
	}

	public function test_it_picks_each_variations_variant_and_the_warning_clears_once_each_sells_one(): void {
		$product = $this->poster();
		$small   = $this->variation( $product, 'Small' );
		$large   = $this->variation( $product, 'Large' );
		$this->plugin->product_variants()->refresh( $this->reload( $product ) );
		$this->assertArrayHasKey( VariantNotice::key( $product->get_id() ), $this->plugin->notices()->all(), 'no variation matches by options' );

		$first = $this->succeed(
			array(
				'product_id' => $product->get_id(),
				'variations' => array( self::row( $small, array( 'Size' => self::SMALL ) ) ),
			)
		);

		$this->assertSame( 'v_small', ProductVariant::stored_on( $this->reload( $small ) )?->pick );
		$this->assertNotNull( $first['variant_issue'], 'the large one still sells none' );
		$this->assertSame(
			array(
				array(
					'variation_id' => $small->get_id(),
					'variant'      => array(
						'ref'     => 'v_small',
						'label'   => 'Size: ' . self::SMALL,
						'picked'  => true,
						'problem' => null,
					),
				),
				array(
					'variation_id' => $large->get_id(),
					'variant'      => self::UNMAPPED,
				),
			),
			$first['variations']
		);
		// WooCommerce gives a variable product its variations' prices at shutdown.
		WC_Product_Variable::sync( $product->get_id() );

		$second = $this->succeed(
			array(
				'product_id' => $product->get_id(),
				'variations' => array( self::row( $large, array( ' size ' => '20X28"  / 50x70CM' ) ) ),
			)
		);

		$this->assertSame( 'v_large', ProductVariant::stored_on( $this->reload( $large ) )?->pick, 'case and spacing aside' );
		$this->assertNull( $second['variant_issue'] );
		$this->assertTrue( $second['purchasable'] );
		$this->assertSame( $product->get_id(), $second['product_id'] );
		$this->assertArrayNotHasKey( VariantNotice::key( $product->get_id() ), $this->plugin->notices()->all() );
	}

	public function test_a_variation_with_its_own_setup_on_a_blank_is_set_on_that_blank(): void {
		$product = $this->poster( null );
		$own     = $this->variation( $product, 'Small' );
		$plain   = $this->variation( $product, 'Large' );
		( new ProductConfig( override: true, personalise: true, blank_id: self::OTHER_BLANK_ID, sides: array( self::side() ) ) )->save_to( $own );

		$answer = $this->succeed(
			array(
				'product_id' => $product->get_id(),
				'variations' => array( self::row( $own, array( 'Size' => self::LARGE ) ) ),
			)
		);

		$stored = ProductVariant::stored_on( $this->reload( $own ) );
		$this->assertSame( array( self::OTHER_BLANK_ID, 'v_large' ), array( $stored?->blank_id, $stored?->pick ) );
		$this->assertSame( 'v_large', $answer['variations'][0]['variant']['ref'] ?? null );
		$this->assertNull( $answer['variations'][1]['variant'], 'the other is not personalised on a blank' );
		$this->assertSame( $plain->get_id(), $answer['variations'][1]['variation_id'] );
	}

	public function test_the_names_in_the_sites_language_are_taken_too(): void {
		$swedish                           = static fn (): string => 'sv_SE';
		add_filter( 'pre_option_WPLANG', $swedish );
		$this->variants->in_language['sv'] = self::sizes( 'Storlek' );
		$product                           = $this->poster();
		$small                             = $this->variation( $product, 'Small' );
		$large                             = $this->variation( $product, 'Large' );

		try {
			$this->succeed(
				array(
					'product_id' => $product->get_id(),
					'variations' => array(
						self::row( $small, array( 'Storlek' => self::SMALL ) ),
						self::row( $large, array( 'Size' => self::LARGE ) ),
					),
				)
			);
		} finally {
			remove_filter( 'pre_option_WPLANG', $swedish );
		}

		$this->assertSame( 'v_small', ProductVariant::stored_on( $this->reload( $small ) )?->pick );
		$this->assertSame( 'v_large', ProductVariant::stored_on( $this->reload( $large ) )?->pick );
	}

	/**
	 * @return array<string, array{0: string, 1: string}>
	 */
	public static function refusals(): array {
		return array(
			'not a variable product'   => array( 'simple', 'No variable product' ),
			'variation of another'     => array( 'foreign', 'is not a variation of product' ),
			'not on a blank'           => array( 'no_blank', 'is not personalised on a blank' ),
			'options naming none'      => array( 'none', 'name no variant' ),
			'an axis left out'         => array( 'missing_axis', 'name no variant' ),
			'an axis too many'         => array( 'extra_axis', 'name no variant' ),
			'an axis named twice'      => array( 'axis_twice', 'name no variant' ),
			'a numeric axis'           => array( 'numeric_axis', 'name no variant' ),
			'options naming several'   => array( 'several', 'name several variants' ),
			'a retired variant'        => array( 'retired', 'that is retired' ),
			'a variation listed twice' => array( 'twice', 'is listed twice' ),
			'input not fitting'        => array( 'schema', 'variations' ),
		);
	}

	/**
	 * @dataProvider refusals
	 *
	 * @param string $wrong   What is wrong with the call.
	 * @param string $message What the refusal says.
	 */
	public function test_a_call_that_does_not_fit_is_refused_and_writes_nothing( string $wrong, string $message ): void {
		$this->variants->answer = BlankFixture::variants(
			array(
				array(
					'ref'     => 'v_small',
					'options' => array( 'Size' => self::SMALL ),
				),
				array(
					'ref'     => 'v_large',
					'options' => array( 'Size' => self::LARGE ),
				),
				array(
					'ref'     => 'v_large_too',
					'options' => array( 'Size' => self::LARGE ),
				),
				array(
					'ref'     => 'v_huge',
					'options' => array( 'Size' => self::HUGE ),
					'retired' => array( 'reason' => 'retired' ),
				),
			),
			array( 'Size' )
		);
		$product                = 'no_blank' === $wrong ? $this->poster( null ) : $this->poster();
		$good                   = $this->variation( $product, 'Small' );
		$other                  = $this->variation( $this->poster(), 'Small' );
		$simple                 = new WC_Product_Simple();
		$simple->save();
		$first   = self::row( $good, array( 'Size' => self::SMALL ) );
		$options = array(
			'none'         => array( 'Size' => 'A0' ),
			'missing_axis' => array(),
			'extra_axis'   => array(
				'Size'   => self::SMALL,
				'Colour' => 'White',
			),
			'axis_twice'   => array(
				'Size'   => 'A0',
				' size ' => self::SMALL,
			),
			'numeric_axis' => array(
				'Size' => self::SMALL,
				'12'   => 'extra',
			),
			'several'      => array( 'Size' => self::LARGE ),
			'retired'      => array( 'Size' => self::HUGE ),
		);
		$input   = match ( $wrong ) {
			'simple'   => array(
				'product_id' => $simple->get_id(),
				'variations' => array( $first ),
			),
			'foreign'  => array(
				'product_id' => $product->get_id(),
				'variations' => array( $first, self::row( $other, array( 'Size' => self::SMALL ) ) ),
			),
			'twice'    => array(
				'product_id' => $product->get_id(),
				'variations' => array( $first, $first ),
			),
			'schema'   => array(
				'product_id' => $product->get_id(),
				'variations' => array(),
			),
			'no_blank' => array(
				'product_id' => $product->get_id(),
				'variations' => array( $first ),
			),
			default    => array(
				'product_id' => $product->get_id(),
				'variations' => array( $first, self::row( $this->variation( $product, 'Large' ), $options[ $wrong ] ) ),
			),
		};

		$answer = $this->call( $input );

		$this->assertSame( 422, $answer->get_status(), (string) wp_json_encode( $answer->get_data() ) );
		$this->assertSame( 'invalid_input', $answer->get_data()['code'] );
		$this->assertStringContainsString( $message, $answer->get_data()['message'] );
		$this->assertNull( ProductVariant::stored_on( $this->reload( $good ) )?->pick, 'the variation that fits is not written either' );
	}

	public function test_a_blank_whose_variants_cannot_be_read_is_a_store_error_and_writes_nothing(): void {
		$product               = $this->poster();
		$small                 = $this->variation( $product, 'Small' );
		$stored_before         = ProductVariant::document_on( $this->reload( $small ) );
		$this->variants->error = new WP_Error( 'http_request_failed', 'Connection timed out' );
		$this->forget_reads();

		$answer = $this->call(
			array(
				'product_id' => $product->get_id(),
				'variations' => array( self::row( $small, array( 'Size' => self::SMALL ) ) ),
			)
		);

		$this->assertSame( 502, $answer->get_status() );
		$this->assertSame( 'store_error', $answer->get_data()['code'] );
		$this->assertSame( $stored_before, ProductVariant::document_on( $this->reload( $small ) ) );
	}

	public function test_the_variation_reads_give_the_variant_each_sells_from_what_is_stored(): void {
		$product = $this->poster();
		$picked  = $this->variation( $product, 'Small' );
		$matched = $this->variation( $product, 'Medium' );
		$none    = $this->variation( $product, 'Mini' );
		$retired = $this->variation( $product, 'Huge' );
		$plain   = $this->variation( $product, 'Tiny' );
		( new ProductConfig( override: true, personalise: false, blank_id: self::BLANK_ID, sides: array( self::side() ) ) )->save_to( $plain );
		$this->variants->answer = BlankFixture::variants(
			array(
				array(
					'ref'     => 'v_small',
					'options' => array( 'Size' => self::SMALL ),
				),
				array(
					'ref'     => 'v_medium',
					'options' => array( 'Size' => 'Medium' ),
				),
				array(
					'ref'     => 'v_huge',
					'options' => array( 'Size' => self::HUGE ),
					'retired' => array( 'reason' => 'retired' ),
				),
			),
			array( 'Size' )
		);
		$variants               = $this->plugin->product_variants();
		$variants->refresh( $this->reload( $product ) );
		$variants->pick( $this->reload( $picked ), 'v_small' );
		$variants->pick( $this->reload( $retired ), 'v_huge' );
		$reads = $this->variants->reads;

		$listed = $this->succeed( array( 'product_id' => $product->get_id() ), 'list-product-variations' );
		$read   = $this->succeed(
			array(
				'product_id' => $product->get_id(),
				'id'         => $picked->get_id(),
			),
			'get-product-variation'
		);

		$this->assertSame( $reads, $this->variants->reads, 'no call to Pixonaut' );
		$by_id = array_column( $listed['items'], 'pixonaut_variant', 'id' );
		$this->assertSame(
			array(
				'ref'     => 'v_small',
				'label'   => 'Size: ' . self::SMALL,
				'picked'  => true,
				'problem' => null,
			),
			$by_id[ $picked->get_id() ]
		);
		$this->assertSame( $by_id[ $picked->get_id() ], $read['pixonaut_variant'] );
		$this->assertSame(
			array(
				'ref'     => 'v_medium',
				'label'   => 'Size: Medium',
				'picked'  => false,
				'problem' => null,
			),
			$by_id[ $matched->get_id() ]
		);
		$this->assertSame( self::UNMAPPED, $by_id[ $none->get_id() ] );
		$this->assertSame(
			array(
				'ref'     => 'v_huge',
				'label'   => 'Size: ' . self::HUGE,
				'picked'  => true,
				'problem' => ProductVariant::RETIRED,
			),
			$by_id[ $retired->get_id() ]
		);
		$this->assertArrayHasKey( $plain->get_id(), $by_id );
		$this->assertNull( $by_id[ $plain->get_id() ], 'not personalised' );
	}

	public function test_a_pick_the_blank_no_longer_has_reads_as_none(): void {
		$product = $this->poster();
		$small   = $this->variation( $product, 'Small' );
		$this->plugin->product_variants()->pick( $small, 'v_gone' );

		$read = $this->succeed(
			array(
				'product_id' => $product->get_id(),
				'id'         => $small->get_id(),
			),
			'get-product-variation'
		);

		$this->assertSame( self::UNMAPPED, $read['pixonaut_variant'] );
	}

	/**
	 * Forgets the blank variants read so far, as a new request would.
	 */
	private function forget_reads(): void {
		( new ReflectionProperty( ProductVariants::class, 'read' ) )->setValue( $this->plugin->product_variants(), array() );
		( new ReflectionProperty( ProductVariants::class, 'failed' ) )->setValue( $this->plugin->product_variants(), array() );
	}

	/**
	 * Calls a tool and asserts it answered.
	 *
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
	 * @param WC_Product               $variation The variation.
	 * @param array<array-key, string> $options The variant's options.
	 * @return array<string, mixed>
	 */
	private static function row( WC_Product $variation, array $options ): array {
		return array(
			'variation_id' => $variation->get_id(),
			'options'      => (object) $options,
		);
	}

	/**
	 * A blank sold in two sizes, as named in a language.
	 *
	 * @param string $axis The axis's name.
	 * @return array<string, mixed>
	 */
	private static function sizes( string $axis = 'Size' ): array {
		return BlankFixture::variants(
			array(
				array(
					'ref'     => 'v_small',
					'options' => array( $axis => self::SMALL ),
				),
				array(
					'ref'     => 'v_large',
					'options' => array( $axis => self::LARGE ),
				),
			),
			array( $axis )
		);
	}

	private static function side(): Side {
		return new Side( 'front', 'Front', 'front', 'Front', 'mini-poster', 1, null, 'Mini poster' );
	}

	/**
	 * A variable poster sold in sizes whose names are not the blank's.
	 *
	 * @param string|null $blank_id The blank it is personalised on; null for none.
	 */
	private function poster( ?string $blank_id = self::BLANK_ID ): WC_Product_Variable {
		$product   = new WC_Product_Variable();
		$attribute = new WC_Product_Attribute();
		$attribute->set_name( 'Size' );
		$attribute->set_options( array( 'Small', 'Medium', 'Large', 'Huge', 'Mini', 'Tiny' ) );
		$attribute->set_variation( true );
		$product->set_name( 'Poster' );
		$product->set_attributes( array( $attribute ) );
		$product->save();
		if ( null !== $blank_id ) {
			( new ProductConfig( personalise: true, blank_id: $blank_id, sides: array( self::side() ) ) )->save_to( $product );
		}
		return $product;
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
