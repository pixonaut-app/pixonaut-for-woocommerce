<?php
/**
 * @package Pixonaut\WooCommerce
 */

declare(strict_types=1);

namespace Pixonaut\WooCommerce\Tests\Integration\ShopTools;

use Pixonaut\WooCommerce\Catalogue\BlankOptionLinks;
use Pixonaut\WooCommerce\Connection\StoreAccess;
use Pixonaut\WooCommerce\Connection\StoredConnection;
use Pixonaut\WooCommerce\Plugin;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;
use WP_UnitTestCase;

/**
 * The shop tools that read and set the blank option each global attribute
 * stands for, called on the shop tool route as Pixonaut calls them.
 */
final class BlankOptionShopToolsTest extends WP_UnitTestCase {

	private const ROUTE = '/pixonaut/v1/shop-tools/';

	private const SECRET = 'a-store-credential';

	private const GET = 'get-attribute-blank-options';

	private const SET = 'set-attribute-blank-option';

	private const OK = 200;

	/**
	 * When the fixture's connection was made, as Unix time.
	 */
	private const CONNECTED_AT = 1767225600;

	private const TAXONOMY = 'pa_sidor';

	private Plugin $plugin;

	private int $attribute = 0;

	public function set_up(): void {
		parent::set_up();
		$this->plugin = Plugin::boot( dirname( __DIR__, 4 ) . '/pixonaut-for-woocommerce.php' );
		global $wp_rest_server;
		$wp_rest_server = new WP_REST_Server();
		do_action( 'rest_api_init', $wp_rest_server );
		$this->plugin->connection_store()->save( self::connection() );
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
		$attribute = wc_create_attribute(
			array(
				'name' => 'Sidor',
				'slug' => 'sidor',
			)
		);
		$this->assertIsInt( $attribute );
		$this->attribute = $attribute;
		register_taxonomy( self::TAXONOMY, array( 'product', 'product_variation' ) );
		$GLOBALS['wc_product_attributes'][ self::TAXONOMY ] = current( wp_list_filter( wc_get_attribute_taxonomies(), array( 'attribute_id' => $this->attribute ) ) );
		$this->assertIsArray( wp_insert_term( '44 sidor', self::TAXONOMY, array( 'slug' => '44-sidor' ) ) );
		wp_set_current_user( 0 );
	}

	public function tear_down(): void {
		unregister_taxonomy( self::TAXONOMY );
		unset( $GLOBALS['wc_product_attributes'][ self::TAXONOMY ] );
		delete_transient( 'wc_attribute_taxonomies' );
		delete_option( BlankOptionLinks::OPTION );
		$this->plugin->connection_store()->clear();
		delete_option( StoreAccess::OPTION );
		global $wp_rest_server;
		$wp_rest_server = null;
		parent::tear_down();
	}

	public function test_pixonaut_links_an_attribute_reads_it_and_unlinks_it(): void {
		$linked = $this->succeed(
			self::SET,
			array(
				'attribute_id' => $this->attribute,
				'option'       => BlankOptionLinks::PAGE_COUNT,
			)
		);
		$this->assertSame( BlankOptionLinks::PAGE_COUNT, $linked['option'] );
		$this->assertSame( '44', $linked['values'][0]['stands_for'] ?? null );

		$read = $this->succeed( self::GET, array( 'attribute_id' => $this->attribute ) );
		$this->assertSame( array( $linked ), $read['attributes'] );

		$unlinked = $this->succeed(
			self::SET,
			array(
				'attribute_id' => $this->attribute,
				'option'       => '',
			)
		);
		$this->assertNull( $unlinked['option'] );
		$this->assertNull( BlankOptionLinks::option_of( self::TAXONOMY ) );
	}

	public function test_an_option_no_blank_has_is_refused_as_input(): void {
		$answer = $this->call(
			self::SET,
			array(
				'attribute_id' => $this->attribute,
				'option'       => 'Color',
			)
		);

		$this->assertSame( 422, $answer->get_status() );
		$this->assertSame( 'invalid_input', $answer->get_data()['code'] ?? null );
		$this->assertNull( BlankOptionLinks::option_of( self::TAXONOMY ) );
	}

	/**
	 * Calls a tool as Pixonaut does.
	 *
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
