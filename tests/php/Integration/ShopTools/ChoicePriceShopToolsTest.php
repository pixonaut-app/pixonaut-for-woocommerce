<?php
/**
 * @package Pixonaut\WooCommerce
 */

declare(strict_types=1);

namespace Pixonaut\WooCommerce\Tests\Integration\ShopTools;

use Pixonaut\WooCommerce\Connection\StoreAccess;
use Pixonaut\WooCommerce\Connection\StoredConnection;
use Pixonaut\WooCommerce\Plugin;
use Pixonaut\WooCommerce\Storefront\ChoicePrices;
use Pixonaut\WooCommerce\Tests\Support\SplitPosters;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;
use WP_UnitTestCase;

/**
 * The shop tools that read and set the price each global attribute's
 * choices show, called on the shop tool route as Pixonaut calls them.
 */
final class ChoicePriceShopToolsTest extends WP_UnitTestCase {

	use SplitPosters;

	private const ROUTE = '/pixonaut/v1/shop-tools/';

	private const SECRET = 'a-store-credential';

	private const GET = 'get-attribute-choice-prices';

	private const SET = 'set-attribute-choice-price';

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
		delete_option( ChoicePrices::OPTION );
		$this->plugin->connection_store()->clear();
		delete_option( StoreAccess::OPTION );
		global $wp_rest_server;
		$wp_rest_server = null;
		parent::tear_down();
	}

	public function test_it_reads_the_price_each_attributes_choices_show(): void {
		ChoicePrices::set_choice( $this->attributes[ self::SIZE ], ChoicePrices::FROM );

		$this->assertSame(
			array(
				'attributes' => array(
					array(
						'attribute_id' => $this->attributes[ self::PICTURE ],
						'name'         => self::PICTURE,
						'slug'         => 'bild',
						'choice_price' => ChoicePrices::NONE,
					),
					array(
						'attribute_id' => $this->attributes[ self::SIZE ],
						'name'         => self::SIZE,
						'slug'         => 'storlek',
						'choice_price' => ChoicePrices::FROM,
					),
				),
			),
			$this->succeed( self::GET, array() )
		);
	}

	public function test_it_sets_the_price_and_refuses_an_unknown_one_or_attribute(): void {
		$picture = $this->attributes[ self::PICTURE ];

		$this->assertSame(
			array(
				'attribute_id' => $picture,
				'name'         => self::PICTURE,
				'slug'         => 'bild',
				'choice_price' => ChoicePrices::ADD_ON,
			),
			$this->succeed(
				self::SET,
				array(
					'attribute_id' => $picture,
					'choice_price' => ChoicePrices::ADD_ON,
				)
			)
		);
		$this->assertSame( ChoicePrices::ADD_ON, ChoicePrices::choice( $picture ) );

		$refused = $this->call(
			self::SET,
			array(
				'attribute_id' => $picture,
				'choice_price' => 'plus',
			)
		);
		$this->assertNotSame( self::OK, $refused->get_status() );
		$this->assertSame( ChoicePrices::ADD_ON, ChoicePrices::choice( $picture ), 'a refused price leaves it as it was' );

		$unknown = $this->call(
			self::SET,
			array(
				'attribute_id' => PHP_INT_MAX,
				'choice_price' => ChoicePrices::NONE,
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
