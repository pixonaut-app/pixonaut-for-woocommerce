<?php
/**
 * @package Pixonaut\WooCommerce
 */

declare(strict_types=1);

namespace Pixonaut\WooCommerce\Tests\Integration\ShopTools;

use Pixonaut\WooCommerce\Api\Hosts;
use Pixonaut\WooCommerce\Catalogue\CatalogueCache;
use Pixonaut\WooCommerce\Catalogue\FormGroups;
use Pixonaut\WooCommerce\Catalogue\PrintSize;
use Pixonaut\WooCommerce\Catalogue\ProductConfig;
use Pixonaut\WooCommerce\Catalogue\Side;
use Pixonaut\WooCommerce\Connection\StoreAccess;
use Pixonaut\WooCommerce\Connection\StoredConnection;
use Pixonaut\WooCommerce\GroupNumbers;
use Pixonaut\WooCommerce\OptionGroups;
use Pixonaut\WooCommerce\Plugin;
use Pixonaut\WooCommerce\Tests\Support\HttpRecorder;
use Pixonaut\WooCommerce\Tests\Support\OpenApiAssert;
use WC_Product;
use WC_Product_Attribute;
use WC_Product_Variable;
use WC_Product_Variation;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;
use WP_UnitTestCase;

/**
 * The shop tools that read and arrange a product's designer form, called
 * on the shop tool route as Pixonaut calls them, against a recorder that
 * answers the template as Pixonaut.
 */
final class ProductFormShopToolsTest extends WP_UnitTestCase {

	private const ROUTE = '/pixonaut/v1/shop-tools/';

	private const SECRET = 'a-store-credential';

	private const GET = 'get-product-form';

	private const SET = 'set-product-form';

	private const OK = 200;

	private const UNPROCESSABLE = 422;

	private const STORE_FAILED = 502;

	/**
	 * When the fixture's connection was made, as Unix time.
	 */
	private const CONNECTED_AT = 1767225600;

	private const TEMPLATE_ID = 'mini-tee@2';

	/**
	 * The template's groups and fields, as the spec's example of
	 * `GET /v1/templates/{id}` has them.
	 */
	private const MOTIF_GROUP = 'upload-motif';

	private const TEXT_GROUP = 'slogan-text';

	private const SLOGAN = 'slogan:content';

	/**
	 * The poster's options, as the arrangement names them.
	 */
	private const SIZE = 'posterstorlek';

	private const FRAMING = 'inramning';

	private const FRAME_COLOUR = 'ramfarg';

	/**
	 * The reference product's arrangement: a group of its own first, then
	 * the template's groups, then size and framing in one group, then the
	 * frame's colour.
	 */
	private const ARRANGEMENT = array(
		'groups' => array(
			array(
				'id'    => 'wc-personalisering',
				'title' => 'Personalisering',
				'items' => array(
					array(
						'field' => self::SLOGAN,
						'label' => 'Namn',
					),
				),
			),
			array(
				'id'    => self::MOTIF_GROUP,
				'items' => array(),
			),
			array(
				'id'    => self::TEXT_GROUP,
				'items' => array(),
			),
			array(
				'id'    => 'wc-storlek',
				'title' => 'Storlek och ram',
				'items' => array(
					array( 'attribute' => self::SIZE ),
					array( 'attribute' => self::FRAMING ),
				),
			),
			array(
				'id'    => 'wc-ramfarg',
				'title' => 'Ramfärg',
				'items' => array( array( 'attribute' => self::FRAME_COLOUR ) ),
			),
		),
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

	public function test_it_reads_the_arrangement_with_what_it_may_name(): void {
		$poster = $this->poster( self::ARRANGEMENT );
		$this->answer_template();

		$read = $this->succeed( self::GET, array( 'product_id' => $poster->get_id() ) );

		$this->assertSame( $poster->get_id(), $read['product_id'] );
		$this->assertSame( array( self::SIZE, self::FRAMING, self::FRAME_COLOUR ), array_column( $read['attributes'], 'slug' ) );
		$this->assertNull( $read['option_groups'] );
		$this->assertNull( $read['group_numbers'] );
		$side = $read['sides'][0];
		$this->assertSame( 'front', $side['key'] );
		$this->assertSame( self::TEMPLATE_ID, $side['template_id'] );
		$this->assertSame( self::ARRANGEMENT['groups'], $side['form_groups']['groups'] );
		$this->assertSame( array( self::MOTIF_GROUP, self::TEXT_GROUP ), array_column( $side['template_groups'], 'id' ) );
		$this->assertContains( self::SLOGAN, array_column( $side['template_groups'][1]['fields'], 'field' ) );
	}

	public function test_a_side_never_arranged_reads_null_and_a_template_pixonaut_cannot_give_reads_null(): void {
		$poster = $this->poster( null );
		$this->recorder->fail();

		$read = $this->succeed( self::GET, array( 'product_id' => $poster->get_id() ) );

		$this->assertNull( $read['sides'][0]['form_groups'] );
		$this->assertNull( $read['sides'][0]['template_groups'] );
	}

	public function test_one_products_arrangement_is_written_to_another_keeping_its_sides_and_variations(): void {
		$reference = $this->poster( self::ARRANGEMENT );
		$other     = $this->poster( null );
		$variation = $this->own_setup_variation( $other );
		$before    = ProductConfig::stored_on( $other );
		$own       = ProductConfig::document_on( $variation );
		$this->answer_template();

		$read = $this->succeed( self::GET, array( 'product_id' => $reference->get_id() ) );
		$set  = $this->succeed(
			self::SET,
			array(
				'product_id'    => $other->get_id(),
				'sides'         => array(
					array(
						'key'         => 'front',
						'form_groups' => $read['sides'][0]['form_groups'],
					),
				),
				'option_groups' => OptionGroups::FIRST,
				'group_numbers' => GroupNumbers::OFF,
			)
		);

		$this->assertSame( self::ARRANGEMENT['groups'], $set['sides'][0]['form_groups']['groups'] );
		$this->assertSame( OptionGroups::FIRST, $set['option_groups'] );
		$this->assertSame( GroupNumbers::OFF, $set['group_numbers'] );
		$after = ProductConfig::stored_on( $this->reload( $other ) );
		$this->assertNotNull( $after->sides[0]->form_groups );
		$this->assertSame( self::ARRANGEMENT['groups'], $after->sides[0]->form_groups->groups );
		$this->assertEquals( $before->sides[0]->with_form_groups( $after->sides[0]->form_groups ), $after->sides[0], 'the side keeps its template, placement and print sizes' );
		$this->assertSame( $before->blank_id, $after->blank_id );
		$this->assertSame( $before->valid, $after->valid );
		$this->assertSame( $own, ProductConfig::document_on( $this->reload( $variation ) ), 'a variation\'s own setup is kept' );
		$this->assertCount( 1, $this->recorder->requests, 'the template is read once, and nothing is checked again' );
	}

	public function test_an_empty_choice_follows_the_store_again_and_a_null_arrangement_takes_it_off(): void {
		$poster = $this->poster( self::ARRANGEMENT );
		ProductConfig::stored_on( $poster )->with_option_groups( OptionGroups::LAST )->save_to( $poster );
		$this->answer_template();

		$set = $this->succeed(
			self::SET,
			array(
				'product_id'    => $poster->get_id(),
				'sides'         => array(
					array(
						'key'         => 'front',
						'form_groups' => null,
					),
				),
				'option_groups' => '',
			)
		);

		$this->assertNull( $set['option_groups'] );
		$this->assertNull( $set['sides'][0]['form_groups'] );
		$this->assertNull( ProductConfig::stored_on( $this->reload( $poster ) )->sides[0]->form_groups );
	}

	public function test_what_the_product_or_template_lacks_is_refused_each_named_and_nothing_saved(): void {
		$poster = $this->poster( null );
		$before = ProductConfig::document_on( $poster );
		$this->answer_template();

		$refused = $this->call(
			self::SET,
			array(
				'product_id'    => $poster->get_id(),
				'sides'         => array(
					array(
						'key'         => 'front',
						'form_groups' => array(
							'groups' => array(
								array(
									'id'    => 'not-the-templates',
									'items' => array( array( 'field' => 'motif:nothing' ) ),
								),
								array(
									'id'    => 'wc-mine',
									'title' => 'Mine',
									'items' => array( array( 'attribute' => 'farg' ) ),
								),
							),
						),
					),
					array(
						'key'         => 'back',
						'form_groups' => null,
					),
				),
				'option_groups' => OptionGroups::FIRST,
			)
		);

		$this->assertSame( self::UNPROCESSABLE, $refused->get_status() );
		$message = (string) $refused->get_data()['message'];
		$this->assertStringContainsString( '`not-the-templates`', $message );
		$this->assertStringContainsString( '`motif:nothing`', $message );
		$this->assertStringContainsString( '`farg`', $message );
		$this->assertStringContainsString( '`back`', $message );
		$this->assertSame( $before, ProductConfig::document_on( $this->reload( $poster ) ) );
	}

	public function test_an_arrangement_pixonaut_cannot_check_is_not_saved(): void {
		$poster = $this->poster( null );
		$before = ProductConfig::document_on( $poster );
		$this->recorder->fail();

		$refused = $this->call(
			self::SET,
			array(
				'product_id' => $poster->get_id(),
				'sides'      => array(
					array(
						'key'         => 'front',
						'form_groups' => self::ARRANGEMENT,
					),
				),
			)
		);

		$this->assertSame( self::STORE_FAILED, $refused->get_status() );
		$this->assertSame( $before, ProductConfig::document_on( $this->reload( $poster ) ) );
	}

	public function test_a_variation_is_refused_for_it_follows_its_product(): void {
		$variation = $this->own_setup_variation( $this->poster( null ) );

		$refused = $this->call(
			self::SET,
			array(
				'product_id'    => $variation->get_id(),
				'group_numbers' => GroupNumbers::ON,
			)
		);

		$this->assertSame( self::UNPROCESSABLE, $refused->get_status() );
	}

	/**
	 * Scripts the template's read, which the store keeps.
	 */
	private function answer_template(): void {
		$this->recorder->raw( self::OK, OpenApiAssert::example( 'GET', '/v1/templates/{id}', self::OK ), 'application/json' );
	}

	/**
	 * A poster varying by size, framing and frame colour, on one side of
	 * the template with two print sizes, arranged as given.
	 *
	 * @param array<string, mixed>|null $arrangement The front's arrangement; null for none.
	 */
	private function poster( ?array $arrangement ): WC_Product_Variable {
		$attributes = array();
		foreach ( array( 'Posterstorlek', 'Inramning', 'Ramfärg' ) as $name ) {
			$attribute = new WC_Product_Attribute();
			$attribute->set_name( $name );
			$attribute->set_options( array( 'A', 'B' ) );
			$attribute->set_visible( true );
			$attribute->set_variation( true );
			$attributes[] = $attribute;
		}
		$poster = new WC_Product_Variable();
		$poster->set_name( 'Poster' );
		$poster->set_attributes( $attributes );
		$poster->save();
		$sizes = array( new PrintSize( 300, 400, '30×40 cm', true ), new PrintSize( 500, 700, '50×70 cm' ) );
		$side  = new Side( 'front', 'Front', null, null, 'mini-tee', 2, null, 'Mini tee', $sizes, null, 1, form_groups: FormGroups::from_array( $arrangement ) );
		( new ProductConfig( personalise: true, sides: array( $side ) ) )->save_to( $poster );
		return $poster;
	}

	/**
	 * A variation of the poster with a setup of its own.
	 *
	 * @param WC_Product_Variable $poster The poster.
	 */
	private function own_setup_variation( WC_Product_Variable $poster ): WC_Product_Variation {
		$variation = new WC_Product_Variation();
		$variation->set_parent_id( $poster->get_id() );
		$variation->set_attributes( array( 'posterstorlek' => 'A' ) );
		$variation->set_regular_price( '199' );
		$variation->save();
		$side = new Side( 'front', 'Front', null, null, 'mini-tee', 2, null, 'Mini tee', array( new PrintSize( 210, 297, 'A4' ) ) );
		( new ProductConfig( personalise: true, override: true, sides: array( $side ) ) )->save_to( $variation );
		return $variation;
	}

	/**
	 * @param string       $tool  The tool.
	 * @param array<mixed> $input Its input.
	 */
	private function call( string $tool, array $input ): WP_REST_Response {
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
		$answer = $this->call( $tool, $input );
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
