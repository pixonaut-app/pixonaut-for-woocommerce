<?php
/**
 * @package Pixonaut\WooCommerce
 */

declare(strict_types=1);

namespace Pixonaut\WooCommerce\Tests\Integration\ShopTools;

use Pixonaut\WooCommerce\Connection\StoreAccess;
use Pixonaut\WooCommerce\Connection\StoredConnection;
use Pixonaut\WooCommerce\Plugin;
use Pixonaut\WooCommerce\Storefront\QueryNames;
use Pixonaut\WooCommerce\Tests\Support\SplitPosters;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;
use WP_UnitTestCase;

/**
 * The shop tools that read and set the name each global attribute goes by
 * in the shop's addresses, called on the shop tool route as Pixonaut calls
 * them.
 */
final class QueryNameShopToolsTest extends WP_UnitTestCase {

	use SplitPosters;

	private const ROUTE = '/pixonaut/v1/shop-tools/';

	private const SECRET = 'a-store-credential';

	private const GET = 'get-attribute-query-names';

	private const SET = 'set-attribute-query-name';

	private const OK = 200;

	/**
	 * When the fixture's connection was made, as Unix time.
	 */
	private const CONNECTED_AT = 1767225600;

	private const PICTURE = 'Bild';

	private const SIZE = 'Storlek';

	private Plugin $plugin;

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
		$this->set_up_posters();
		wp_set_current_user( 0 );
	}

	public function tear_down(): void {
		$this->tear_down_posters();
		$this->plugin->connection_store()->clear();
		delete_option( StoreAccess::OPTION );
		global $wp_rest_server;
		$wp_rest_server = null;
		parent::tear_down();
	}

	public function test_it_reads_each_attributes_name_in_addresses(): void {
		QueryNames::set( $this->attributes[ self::SIZE ], 'size' );

		$this->assertSame(
			array(
				'attributes' => array(
					array(
						'attribute_id' => $this->attributes[ self::PICTURE ],
						'name'         => self::PICTURE,
						'query_name'   => 'bild',
						'own'          => '',
						'slug'         => 'bild',
					),
					array(
						'attribute_id' => $this->attributes[ self::SIZE ],
						'name'         => self::SIZE,
						'query_name'   => 'size',
						'own'          => 'size',
						'slug'         => 'storlek',
					),
				),
			),
			$this->succeed( self::GET, array() )
		);
	}

	public function test_it_sets_a_name_and_refuses_one_another_attribute_or_woocommerce_reads(): void {
		$picture = $this->attributes[ self::PICTURE ];

		$this->assertSame(
			array(
				'attribute_id' => $picture,
				'name'         => self::PICTURE,
				'query_name'   => 'motiv',
				'own'          => 'motiv',
				'slug'         => 'bild',
			),
			$this->succeed(
				self::SET,
				array(
					'attribute_id' => $picture,
					'query_name'   => 'motiv',
				)
			)
		);

		foreach ( array( 'storlek', 'orderby', 'Not A Name' ) as $refused ) {
			$answer = $this->call(
				self::SET,
				array(
					'attribute_id' => $picture,
					'query_name'   => $refused,
				)
			);
			$this->assertNotSame( self::OK, $answer->get_status(), $refused );
			$data = $answer->get_data();
			$this->assertIsArray( $data );
			$this->assertSame( QueryNames::refusal( $refused, $picture ), $data['message'] ?? null, 'says why' );
		}
		$this->assertSame( 'motiv', QueryNames::own( $picture ), 'a refused name leaves it as it was' );

		$this->assertSame(
			'bild',
			$this->succeed(
				self::SET,
				array(
					'attribute_id' => $picture,
					'query_name'   => '',
				)
			)['query_name'],
			'empty gives it back its slug'
		);
		$unknown = $this->call(
			self::SET,
			array(
				'attribute_id' => PHP_INT_MAX,
				'query_name'   => 'x',
			)
		);
		$this->assertNotSame( self::OK, $unknown->get_status() );
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
