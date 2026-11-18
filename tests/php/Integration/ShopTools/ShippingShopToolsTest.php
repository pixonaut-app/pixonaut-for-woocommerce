<?php
/**
 * @package Pixonaut\WooCommerce
 */

declare(strict_types=1);

namespace Pixonaut\WooCommerce\Tests\Integration\ShopTools;

use Pixonaut\WooCommerce\Connection\StoreAccess;
use Pixonaut\WooCommerce\Connection\StoredConnection;
use Pixonaut\WooCommerce\Plugin;
use WC_Product_Simple;
use WC_Shipping_Zone;
use WC_Tax;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;
use WP_UnitTestCase;

/**
 * The shop tools that read the store's shipping zones and change or add
 * their methods, called on the shop tool route as Pixonaut calls them, and
 * what a cart is charged for shipping afterwards.
 */
final class ShippingShopToolsTest extends WP_UnitTestCase {

	private const ROUTE = '/pixonaut/v1/shop-tools/';

	private const SECRET = 'a-store-credential';

	private const GET = 'get-shipping';

	private const SET = 'set-shipping-method';

	private const OK = 200;

	private const UNPROCESSABLE = 422;

	/**
	 * When the fixture's connection was made, as Unix time.
	 */
	private const CONNECTED_AT = 1767225600;

	private const COUNTRY = 'SE';

	/**
	 * Swedish VAT, which shipping is charged too.
	 */
	private const VAT_PERCENT = '25.0000';

	/**
	 * Swedish VAT on books.
	 */
	private const REDUCED_VAT_PERCENT = '6.0000';

	/**
	 * The flat rate as the store had it: 99 including VAT.
	 */
	private const OLD_COST = '79.20';

	private const COST_INCLUDING_VAT = '49';

	/**
	 * 49 including 25 % VAT.
	 */
	private const COST_EXCLUDING_VAT = '39.20';

	private const FREE_FROM = '799';

	/**
	 * A cart's subtotal including VAT, just under the free-shipping minimum.
	 */
	private const UNDER_MINIMUM = '798';

	private const AT_MINIMUM = '799';

	private const FLAT_RATE = 'flat_rate';

	private const FREE_SHIPPING = 'free_shipping';

	private Plugin $plugin;

	private int $zone_id;

	private int $flat_rate_id;

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
		update_option( 'woocommerce_default_country', self::COUNTRY );
		update_option( 'woocommerce_calc_taxes', 'yes' );
		update_option( 'woocommerce_prices_include_tax', 'yes' );
		update_option( 'woocommerce_tax_display_cart', 'incl' );
		WC_Tax::_insert_tax_rate(
			array(
				'tax_rate_country'  => self::COUNTRY,
				'tax_rate'          => self::VAT_PERCENT,
				'tax_rate_name'     => 'Moms',
				'tax_rate_priority' => 1,
				'tax_rate_shipping' => 1,
				'tax_rate_order'    => 1,
				'tax_rate_class'    => '',
			)
		);
		$zone = new WC_Shipping_Zone();
		$zone->set_zone_name( 'Sverige' );
		$zone->add_location( self::COUNTRY, 'country' );
		$zone->save();
		$this->zone_id      = (int) $zone->get_id();
		$this->flat_rate_id = (int) $zone->add_shipping_method( self::FLAT_RATE );
		update_option(
			'woocommerce_flat_rate_' . $this->flat_rate_id . '_settings',
			array(
				'title'      => 'Frakt',
				'tax_status' => 'taxable',
				'cost'       => self::OLD_COST,
			)
		);
		wp_set_current_user( 0 );
	}

	public function tear_down(): void {
		if ( null !== WC()->cart ) {
			WC()->cart->empty_cart();
		}
		$this->plugin->connection_store()->clear();
		delete_option( StoreAccess::OPTION );
		global $wp_rest_server;
		$wp_rest_server = null;
		parent::tear_down();
	}

	public function test_it_reads_each_zone_with_its_methods_and_a_cost_including_tax(): void {
		$read = $this->succeed( self::GET, array() );

		$this->assertSame( get_woocommerce_currency(), $read['currency'] );
		$this->assertTrue( $read['prices_include_tax'] );
		$this->assertSame( array( $this->zone_id, 0 ), array_column( $read['zones'], 'zone_id' ), 'the zone for the rest comes last' );
		$sverige = $read['zones'][0];
		$this->assertSame( 'Sverige', $sverige['name'] );
		$this->assertSame(
			array(
				array(
					'code' => self::COUNTRY,
					'type' => 'country',
				),
			),
			$sverige['locations']
		);
		$this->assertSame(
			array(
				'instance_id'        => $this->flat_rate_id,
				'kind'               => self::FLAT_RATE,
				'title'              => 'Frakt',
				'enabled'            => true,
				'order'              => 1,
				'cost'               => self::OLD_COST,
				'cost_including_tax' => '99.00',
				'tax_status'         => 'taxable',
				'min_amount'         => null,
				'requires'           => null,
				'ignore_discounts'   => null,
			),
			$sverige['methods'][0]
		);
	}

	public function test_a_flat_rate_given_including_tax_is_kept_excluding_it_and_charged_including_it(): void {
		$set = $this->succeed(
			self::SET,
			array(
				'zone_id'           => $this->zone_id,
				'instance_id'       => $this->flat_rate_id,
				'cost'              => self::COST_INCLUDING_VAT,
				'cost_includes_tax' => true,
			)
		);

		$this->assertSame( self::COST_EXCLUDING_VAT, $set['cost'] );
		$this->assertSame( '49.00', $set['cost_including_tax'] );
		$this->assertSame( 'Frakt', $set['title'], 'a setting not given keeps its value' );

		$rates = $this->cart_rates( self::UNDER_MINIMUM );
		$this->assertArrayHasKey( self::FLAT_RATE . ':' . $this->flat_rate_id, $rates );
		$this->assertEqualsWithDelta( 49.0, (float) WC()->cart->get_shipping_total() + (float) WC()->cart->get_shipping_tax(), 0.001 );
	}

	public function test_a_flat_rate_given_excluding_tax_or_not_taxed_is_kept_as_given(): void {
		$set = $this->succeed(
			self::SET,
			array(
				'zone_id'     => $this->zone_id,
				'instance_id' => $this->flat_rate_id,
				'cost'        => '40',
				'tax_status'  => 'none',
				'title'       => 'Posten',
			)
		);

		$this->assertSame( '40', $set['cost'] );
		$this->assertSame( '40', $set['cost_including_tax'] );
		$this->assertSame( 'none', $set['tax_status'] );
		$this->assertSame( 'Posten', $set['title'] );
		$this->cart_rates( self::UNDER_MINIMUM );
		$this->assertEqualsWithDelta( 40.0, (float) WC()->cart->get_shipping_total() + (float) WC()->cart->get_shipping_tax(), 0.001 );
	}

	public function test_free_shipping_added_with_a_minimum_is_offered_from_the_carts_subtotal_as_woocommerce_counts_it(): void {
		$added = $this->succeed(
			self::SET,
			array(
				'zone_id'    => $this->zone_id,
				'kind'       => self::FREE_SHIPPING,
				'title'      => 'Fri frakt',
				'min_amount' => self::FREE_FROM,
			)
		);

		$this->assertSame( self::FREE_SHIPPING, $added['kind'] );
		$this->assertTrue( $added['enabled'] );
		$this->assertSame( self::FREE_FROM, $added['min_amount'] );
		$this->assertSame( 'min_amount', $added['requires'], 'a minimum is required once given' );
		$this->assertFalse( $added['ignore_discounts'] );
		$this->assertNull( $added['cost'] );
		$free = self::FREE_SHIPPING . ':' . $added['instance_id'];

		$this->assertArrayNotHasKey( $free, $this->cart_rates( self::UNDER_MINIMUM ), 'under the minimum including VAT' );
		$this->assertArrayHasKey( $free, $this->cart_rates( self::AT_MINIMUM ), 'at the minimum including VAT' );

		$read = $this->succeed( self::GET, array() );
		$this->assertSame( array( self::FLAT_RATE, self::FREE_SHIPPING ), array_column( $read['zones'][0]['methods'], 'kind' ) );
	}

	public function test_a_method_switched_off_leaves_checkout_and_is_still_read(): void {
		$set = $this->succeed(
			self::SET,
			array(
				'zone_id'     => $this->zone_id,
				'instance_id' => $this->flat_rate_id,
				'enabled'     => false,
			)
		);

		$this->assertFalse( $set['enabled'] );
		$this->assertArrayNotHasKey( self::FLAT_RATE . ':' . $this->flat_rate_id, $this->cart_rates( self::UNDER_MINIMUM ) );
		$this->assertFalse( $this->succeed( self::GET, array() )['zones'][0]['methods'][0]['enabled'] );
	}

	public function test_shipping_taxed_as_the_carts_items_counts_including_tax_only_while_every_taxable_product_has_the_same_rate(): void {
		update_option( 'woocommerce_shipping_tax_class', 'inherit' );
		$reduced = $this->reduced_rate_class();
		$this->product_in_class( '' );
		$same = $this->product_in_class( $reduced );
		$same->set_tax_status( 'none' );
		$same->save();

		$this->assertSame( '99.00', $this->succeed( self::GET, array() )['zones'][0]['methods'][0]['cost_including_tax'], 'an untaxed product counts for nothing' );

		$this->product_in_class( $reduced );

		$this->assertNull( $this->succeed( self::GET, array() )['zones'][0]['methods'][0]['cost_including_tax'], 'the tax depends on the cart' );
		$refused = $this->call(
			self::SET,
			array(
				'zone_id'           => $this->zone_id,
				'instance_id'       => $this->flat_rate_id,
				'cost'              => self::COST_INCLUDING_VAT,
				'cost_includes_tax' => true,
			)
		);
		$this->assertSame( self::UNPROCESSABLE, $refused->get_status() );
		$this->assertSame( self::OLD_COST, $this->succeed( self::GET, array() )['zones'][0]['methods'][0]['cost'] );
		$this->assertSame(
			self::COST_EXCLUDING_VAT,
			$this->succeed(
				self::SET,
				array(
					'zone_id'     => $this->zone_id,
					'instance_id' => $this->flat_rate_id,
					'cost'        => self::COST_EXCLUDING_VAT,
				)
			)['cost'],
			'a cost excluding tax is kept as given'
		);
	}

	public function test_a_setting_the_method_does_not_have_or_a_method_that_is_not_there_changes_nothing(): void {
		$minimum_on_flat_rate = $this->call(
			self::SET,
			array(
				'zone_id'     => $this->zone_id,
				'instance_id' => $this->flat_rate_id,
				'min_amount'  => self::FREE_FROM,
			)
		);
		$other_kind           = $this->call(
			self::SET,
			array(
				'zone_id'     => $this->zone_id,
				'instance_id' => $this->flat_rate_id,
				'kind'        => self::FREE_SHIPPING,
			)
		);
		$formula              = $this->call(
			self::SET,
			array(
				'zone_id'     => $this->zone_id,
				'instance_id' => $this->flat_rate_id,
				'cost'        => '10 + [qty]',
			)
		);
		$unknown              = $this->call(
			self::SET,
			array(
				'zone_id'     => $this->zone_id,
				'instance_id' => PHP_INT_MAX,
				'cost'        => '1',
			)
		);

		foreach ( array( $minimum_on_flat_rate, $other_kind, $formula, $unknown ) as $answer ) {
			$this->assertSame( self::UNPROCESSABLE, $answer->get_status(), (string) wp_json_encode( $answer->get_data() ) );
			$this->assertSame( 'invalid_input', $answer->get_data()['code'] );
		}
		$this->assertSame( self::OLD_COST, $this->succeed( self::GET, array() )['zones'][0]['methods'][0]['cost'] );
	}

	/**
	 * A tax class with a lower Swedish rate, shipping included, by slug.
	 */
	private function reduced_rate_class(): string {
		$class = WC_Tax::create_tax_class( 'Nedsatt moms' );
		$this->assertIsArray( $class );
		WC_Tax::_insert_tax_rate(
			array(
				'tax_rate_country'  => self::COUNTRY,
				'tax_rate'          => self::REDUCED_VAT_PERCENT,
				'tax_rate_name'     => 'Moms',
				'tax_rate_priority' => 1,
				'tax_rate_shipping' => 1,
				'tax_rate_order'    => 1,
				'tax_rate_class'    => $class['slug'],
			)
		);
		return $class['slug'];
	}

	/**
	 * A product in a tax class.
	 *
	 * @param string $tax_class The class's slug, empty for standard.
	 */
	private function product_in_class( string $tax_class ): WC_Product_Simple {
		$product = new WC_Product_Simple();
		$product->set_name( 'Bok' );
		$product->set_regular_price( self::UNDER_MINIMUM );
		$product->set_tax_class( $tax_class );
		$product->save();
		return $product;
	}

	/**
	 * The shipping rates a Swedish cart of one product at this price is offered.
	 *
	 * @param string $price The product's price, including VAT.
	 * @return array<string, mixed>
	 */
	private function cart_rates( string $price ): array {
		$product = new WC_Product_Simple();
		$product->set_name( 'Poster' );
		$product->set_regular_price( $price );
		$product->save();
		wc_load_cart();
		WC()->cart->empty_cart();
		WC()->customer->set_billing_country( self::COUNTRY );
		WC()->customer->set_shipping_country( self::COUNTRY );
		WC()->cart->add_to_cart( $product->get_id() );
		WC()->cart->calculate_totals();
		$packages = WC()->shipping()->get_packages();
		$this->assertNotEmpty( $packages );
		return $packages[0]['rates'];
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
