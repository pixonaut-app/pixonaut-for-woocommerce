<?php
/**
 * @package Pixonaut\WooCommerce
 */

declare(strict_types=1);

namespace Pixonaut\WooCommerce\Tests\Integration\Pricing;

use Pixonaut\WooCommerce\Abilities\Abilities;
use Pixonaut\WooCommerce\Abilities\Ability;
use Pixonaut\WooCommerce\Abilities\GetProductPrices;
use Pixonaut\WooCommerce\Abilities\PriceProduct;
use Pixonaut\WooCommerce\Abilities\ProductPrices;
use Pixonaut\WooCommerce\Abilities\ReadPricingSettings;
use Pixonaut\WooCommerce\Abilities\UpdatePricingSettings;
use Pixonaut\WooCommerce\Connection\Connection;
use Pixonaut\WooCommerce\Connection\StoreAccess;
use Pixonaut\WooCommerce\Connection\StoredConnection;
use Pixonaut\WooCommerce\Pricing\PriceRule;
use Pixonaut\WooCommerce\Pricing\PriceState;
use Pixonaut\WooCommerce\Pricing\ProductPricing;
use Pixonaut\WooCommerce\Settings\Settings;
use WC_Product_Simple;
use WP_Error;
use WP_REST_Request;
use WP_REST_Server;

/**
 * The abilities that read and change the pricing settings and price a
 * product by the rule, against a recorder that answers the printshop's
 * costs as Pixonaut does and an exchange rate source the test answers.
 */
final class PricingAbilitiesTest extends PricingTestCase {

	public const ABILITIES = array(
		Abilities::NAMESPACE . '/' . ReadPricingSettings::NAME,
		Abilities::NAMESPACE . '/' . UpdatePricingSettings::NAME,
		Abilities::NAMESPACE . '/' . GetProductPrices::NAME,
		Abilities::NAMESPACE . '/' . PriceProduct::NAME,
	);

	private const SECRET = 'a-store-credential';

	private const ROUTE = '/pixonaut/v1/shop-tools/';

	private const SITE = 'site-a';

	private ReadPricingSettings $read_settings;

	private UpdatePricingSettings $update_settings;

	private GetProductPrices $read;

	private PriceProduct $price;

	public function set_up(): void {
		parent::set_up();
		$settings              = $this->plugin->settings();
		$pricing               = fn (): ProductPricing => $this->pricing;
		$this->read_settings   = new ReadPricingSettings( $settings );
		$this->update_settings = new UpdatePricingSettings( $settings );
		$this->read            = new GetProductPrices( $pricing, $settings );
		$this->price           = new PriceProduct( $pricing, $settings );
	}

	public function tear_down(): void {
		$this->plugin->connection_store()->clear();
		delete_option( StoreAccess::OPTION );
		global $wp_rest_server;
		$wp_rest_server = null;
		parent::tear_down();
	}

	public function test_the_pricing_settings_read_the_rule_the_buffer_and_the_switch(): void {
		$this->store_rule( array( PriceRule::MIN => '99' ) );

		$read = $this->read_settings->execute();

		$this->assertSame(
			array(
				Settings::PRICE_MARGIN          => '50',
				Settings::PRICE_TOLERANCE       => '10',
				Settings::PRICE_STEP            => '10',
				Settings::PRICE_ENDING          => '9',
				Settings::PRICE_MIN             => '99',
				Settings::PRICE_MAX             => '',
				Settings::PRICE_CURRENCY_BUFFER => Settings::DEFAULT_CURRENCY_BUFFER,
				Settings::PRICE_AUTOMATIC       => false,
				'currency'                      => get_woocommerce_currency(),
				'prices_include_tax'            => false,
			),
			$read
		);
	}

	public function test_changing_pricing_settings_keeps_the_ones_not_named(): void {
		$this->store_rule();

		$changed = $this->update_settings->execute(
			array(
				Settings::PRICE_AUTOMATIC => true,
				Settings::PRICE_MARGIN    => '42,5',
			)
		);

		$this->assertIsArray( $changed );
		$this->assertTrue( $changed[ Settings::PRICE_AUTOMATIC ] );
		$this->assertSame( '42.5', $changed[ Settings::PRICE_MARGIN ] );
		$this->assertSame( '9', $changed[ Settings::PRICE_ENDING ], 'a setting not named is kept' );
		$this->assertTrue( $this->plugin->settings()->get( Settings::PRICE_AUTOMATIC ) );

		$off = $this->update_settings->execute( array( Settings::PRICE_AUTOMATIC => false ) );
		$this->assertIsArray( $off );
		$this->assertFalse( $off[ Settings::PRICE_AUTOMATIC ] );
		$this->assertSame( '42.5', $off[ Settings::PRICE_MARGIN ] );
	}

	public function test_a_pricing_setting_that_is_no_number_or_an_ending_not_below_the_step_changes_nothing(): void {
		$this->store_rule();

		$not_a_number = $this->update_settings->execute( array( Settings::PRICE_MARGIN => 'half' ) );
		$ending       = $this->update_settings->execute( array( Settings::PRICE_STEP => '5' ) );
		$another      = $this->update_settings->execute( array( Settings::DEFAULT_LAYOUT => 'inline' ) );

		$this->assertInstanceOf( WP_Error::class, $not_a_number );
		$this->assertSame( 'pixonaut_wc_ability_setting_invalid', $not_a_number->get_error_code() );
		$this->assertInstanceOf( WP_Error::class, $ending );
		$this->assertSame( 'pixonaut_wc_ability_price_ending', $ending->get_error_code() );
		$this->assertInstanceOf( WP_Error::class, $another, 'only the pricing settings are reached' );
		$this->assertSame( 'pixonaut_wc_ability_setting_unknown', $another->get_error_code() );
		$this->assertSame( '50', $this->plugin->settings()->get( Settings::PRICE_MARGIN ) );
		$this->assertSame( '10', $this->plugin->settings()->get( Settings::PRICE_STEP ) );
	}

	public function test_a_dry_run_answers_the_prices_it_would_set_and_changes_nothing(): void {
		$this->store_rule();
		$ids = $this->variable_product(
			array(
				'v_a3' => '',
				'v_a4' => '250',
			)
		);
		$this->script_costs(
			array(
				'v_a3' => '190',
				'v_a4' => '100',
			),
			'10'
		);
		$product_id = $this->parent_of( $ids['v_a3'] )->get_id();

		$answer = $this->price->execute(
			array(
				'product_id' => $product_id,
				'dry_run'    => true,
			)
		);

		$this->assertIsArray( $answer );
		$this->assertTrue( $answer['dry_run'] );
		$this->assertSame( 2, $answer['changed'] );
		$by_id = array_column( $answer['prices'], null, 'id' );
		// 190 + 10 costs 200: a margin of 50 % targets 400, and the step nearest is 399.
		$this->assertNull( $by_id[ $ids['v_a3'] ]['before'] );
		$this->assertSame( '399', $by_id[ $ids['v_a3'] ]['price'] );
		$this->assertSame( '200', $by_id[ $ids['v_a3'] ]['cost_total'] );
		$this->assertSame( '190', $by_id[ $ids['v_a3'] ]['cost']['product'] );
		$this->assertSame( '10', $by_id[ $ids['v_a3'] ]['cost']['shipping'] );
		$this->assertTrue( $by_id[ $ids['v_a3'] ]['changed'] );
		$this->assertSame( PriceState::FOLLOWS, $by_id[ $ids['v_a3'] ]['standing'] );
		// 100 + 10 costs 110: the target is 220, the step nearest 219.
		$this->assertSame( '250', $by_id[ $ids['v_a4'] ]['before'] );
		$this->assertSame( '219', $by_id[ $ids['v_a4'] ]['price'] );

		$this->assertSame( '', $this->price( $ids['v_a3'] ), 'no price is set' );
		$this->assertSame( '250', $this->price( $ids['v_a4'] ) );
		$this->assertSame( '', $this->product( $ids['v_a3'] )->get_meta( PriceState::META_KEY, true ), 'no cost is stored' );
		$this->assertSame( '', $this->product( $product_id )->get_meta( ProductPricing::WATCH_META_KEY, true ) );
		$this->assertSame( array(), $this->logger->entries, 'nothing is logged as set' );
	}

	public function test_pricing_the_unpriced_variations_sets_each_to_the_rules_price_for_its_cost(): void {
		$this->store_rule();
		$ids = $this->variable_product(
			array(
				'v_a2' => '',
				'v_a3' => '',
				'v_a4' => '250',
			)
		);
		$this->script_costs(
			array(
				'v_a2' => '290',
				'v_a3' => '190',
				'v_a4' => '100',
			),
			'10'
		);
		$product_id = $this->parent_of( $ids['v_a3'] )->get_id();

		$answer = $this->price->execute(
			array(
				'product_id' => $product_id,
				'only'       => PriceProduct::UNPRICED,
			)
		);

		$this->assertIsArray( $answer );
		$this->assertFalse( $answer['dry_run'] );
		$this->assertSame( 2, $answer['changed'] );
		$this->assertSame( '599', $this->price( $ids['v_a2'] ) );
		$this->assertSame( '399', $this->price( $ids['v_a3'] ) );
		$this->assertSame( '250', $this->price( $ids['v_a4'] ), 'a variation with a price is left alone' );
		$by_id = array_column( $answer['prices'], null, 'id' );
		$this->assertSame( PriceState::FOLLOWS, $by_id[ $ids['v_a3'] ]['standing'] );
		$this->assertSame( 49.9, $by_id[ $ids['v_a3'] ]['margin'] );
		$this->assertSame( PriceState::OWN, $by_id[ $ids['v_a4'] ]['standing'] );
		$this->assertFalse( $by_id[ $ids['v_a4'] ]['changed'] );
		$this->assertSame( '219', $by_id[ $ids['v_a4'] ]['suggested'] );

		$read = $this->read->execute( array( 'product_id' => $product_id ) );
		$this->assertIsArray( $read );
		$this->assertTrue( $read['rule_set'] );
		$this->assertSame( '50', $read['rule'][ PriceRule::MARGIN ] );
		$this->assertFalse( $read['automatic'] );
		$this->assertSame( array( '599', '399', '250' ), array_column( $read['prices'], 'price' ) );
		$this->assertSame( array( '300', '200', '110' ), array_column( $read['prices'], 'cost_total' ) );
		$this->assertCount( 1, $this->recorder->requests, 'reading the prices asks Pixonaut nothing' );
	}

	public function test_a_price_edited_by_hand_is_held_until_it_is_released(): void {
		$this->store_rule();
		$ids        = $this->variable_product( array( 'v_a3' => '' ) );
		$product_id = $this->parent_of( $ids['v_a3'] )->get_id();
		$this->script_costs( array( 'v_a3' => '200' ) );
		$this->price->execute( array( 'product_id' => $product_id ) );
		$this->edit_price( $ids['v_a3'], '450' );

		$this->script_costs( array( 'v_a3' => '200' ) );
		$kept = $this->price->execute( array( 'product_id' => $product_id ) );
		$this->assertIsArray( $kept );
		$this->assertSame( 0, $kept['changed'] );
		$this->assertSame( PriceState::BY_HAND, $kept['prices'][0]['standing'] );
		$this->assertSame( '450', $this->price( $ids['v_a3'] ) );

		$this->script_costs( array( 'v_a3' => '200' ) );
		$released = $this->price->execute(
			array(
				'product_id'    => $product_id,
				'variation_ids' => array( $ids['v_a3'] ),
				'release_held'  => true,
			)
		);
		$this->assertIsArray( $released );
		$this->assertSame( 1, $released['changed'] );
		$this->assertSame( '399', $this->price( $ids['v_a3'] ) );
	}

	public function test_pricing_some_variations_sets_no_other_price_though_the_store_changes_prices_by_itself(): void {
		$this->store_rule();
		$this->plugin->settings()->set( Settings::PRICE_AUTOMATIC, true );
		$ids        = $this->variable_product(
			array(
				'v_a3' => '',
				'v_a4' => '',
			)
		);
		$product_id = $this->parent_of( $ids['v_a3'] )->get_id();
		$this->script_costs(
			array(
				'v_a3' => '200',
				'v_a4' => '100',
			)
		);
		$this->price->execute(
			array(
				'product_id'    => $product_id,
				'variation_ids' => array( $ids['v_a3'] ),
			)
		);
		$this->assertSame( '399', $this->price( $ids['v_a3'] ) );
		$this->edit_price( $ids['v_a4'], '' );

		// The cost of the priced variation leaves the tolerance while the other is priced.
		$costs = array(
			'v_a3' => '300',
			'v_a4' => '100',
		);
		$this->script_costs( $costs );
		$tried = $this->price->execute(
			array(
				'product_id' => $product_id,
				'only'       => PriceProduct::UNPRICED,
				'dry_run'    => true,
			)
		);
		$this->script_costs( $costs );
		$priced = $this->price->execute(
			array(
				'product_id' => $product_id,
				'only'       => PriceProduct::UNPRICED,
			)
		);

		$this->assertIsArray( $tried );
		$this->assertIsArray( $priced );
		$this->assertSame( '399', $this->price( $ids['v_a3'] ), 'a price not chosen is not set' );
		$this->assertSame( '199', $this->price( $ids['v_a4'] ) );
		$this->assertSame( 1, $priced['changed'] );
		$this->assertSame( array_column( $tried['prices'], 'price', 'id' ), array_column( $priced['prices'], 'price', 'id' ), 'the dry run answered what the pricing did' );
		$by_id = array_column( $priced['prices'], null, 'id' );
		$this->assertSame( '599', $by_id[ $ids['v_a3'] ]['suggested'], 'its new price is suggested' );
		$this->assertSame( PriceState::FLAG_MARGIN, $by_id[ $ids['v_a3'] ]['flag'] );
	}

	public function test_costs_that_cannot_be_read_store_no_rule_given_with_the_pricing(): void {
		$this->store_rule();
		$ids        = $this->variable_product( array( 'v_a3' => '120' ) );
		$product_id = $this->parent_of( $ids['v_a3'] )->get_id();
		$this->recorder->json( 500, array( 'error' => 'db_error' ) );

		$answer = $this->price->execute(
			array(
				'product_id' => $product_id,
				'rule'       => array( PriceRule::MARGIN => '60' ),
			)
		);

		$this->assertInstanceOf( WP_Error::class, $answer );
		$this->assertSame( '', ProductPricing::own_parts( $this->product( $product_id ) )[ PriceRule::MARGIN ] );
		$this->assertSame( '120', $this->price( $ids['v_a3'] ) );
	}

	public function test_an_answer_lists_a_page_of_the_prices_and_counts_them_all(): void {
		$this->store_rule();
		$ids        = $this->variable_product(
			array(
				'v_a2' => '',
				'v_a3' => '',
				'v_a4' => '',
			)
		);
		$product_id = $this->parent_of( $ids['v_a3'] )->get_id();
		$this->script_costs(
			array(
				'v_a2' => '300',
				'v_a3' => '200',
				'v_a4' => '100',
			)
		);

		$priced = $this->price->execute(
			array(
				'product_id' => $product_id,
				'per_page'   => 2,
			)
		);
		$second = $this->read->execute(
			array(
				'product_id' => $product_id,
				'per_page'   => 2,
				'page'       => 2,
			)
		);

		$this->assertIsArray( $priced );
		$this->assertIsArray( $second );
		$this->assertSame( 3, $priced['changed'], 'every price is set, whichever page is listed' );
		$this->assertSame( array( $ids['v_a2'], $ids['v_a3'] ), array_column( $priced['prices'], 'id' ) );
		$this->assertSame( array( 3, 1, 2 ), array( $priced['total'], $priced['page'], $priced['pages'] ) );
		$this->assertSame( array( $ids['v_a4'] ), array_column( $second['prices'], 'id' ) );
		$this->assertSame( '199', $second['prices'][0]['price'] );
		$this->assertSame( array( 3, 2, 2 ), array( $second['total'], $second['page'], $second['pages'] ) );
	}

	public function test_a_rule_given_is_the_products_own_and_a_dry_run_does_not_store_it(): void {
		$this->store_rule();
		$ids        = $this->variable_product( array( 'v_a3' => '' ) );
		$product_id = $this->parent_of( $ids['v_a3'] )->get_id();
		$input      = array(
			'product_id' => $product_id,
			'rule'       => array( PriceRule::MARGIN => '60' ),
		);

		$this->script_costs( array( 'v_a3' => '200' ) );
		$tried = $this->price->execute( $input + array( 'dry_run' => true ) );
		$this->assertIsArray( $tried );
		// A margin of 60 % on 200 targets 500, and the step nearest is 499.
		$this->assertSame( '499', $tried['prices'][0]['price'] );
		$this->assertSame( '', ProductPricing::own_parts( $this->product( $product_id ) )[ PriceRule::MARGIN ] );

		$this->script_costs( array( 'v_a3' => '200' ) );
		$set = $this->price->execute( $input );
		$this->assertIsArray( $set );
		$this->assertSame( '499', $this->price( $ids['v_a3'] ) );
		$this->assertSame( '60', $set['own_rule'][ PriceRule::MARGIN ] );
		$this->assertSame( '60', ProductPricing::own_parts( $this->product( $product_id ) )[ PriceRule::MARGIN ] );
	}

	public function test_a_cost_in_another_currency_is_priced_at_the_days_rate_with_the_buffer(): void {
		update_option( 'woocommerce_currency', 'SEK' );
		$this->store_rule();
		$this->rates = array( 'SEK' => '11.5' );
		$id          = $this->simple_product( 'v_mug', '' );
		$this->script_costs( array( 'v_mug' => '16.71' ), '3', 'EUR' );

		$answer = $this->price->execute( array( 'product_id' => $id ) );

		$this->assertIsArray( $answer );
		// 19.71 EUR is 226.665 SEK; with 3 % it costs 233.465. A margin of 50 % targets 466.93.
		$this->assertSame( '469', $this->price( $id ) );
		$this->assertSame( '233.465', $answer['prices'][0]['cost_total'] );
		$this->assertSame( 'EUR', $answer['prices'][0]['cost']['currency'] );
		$this->assertSame( $this->rates_date, $answer['prices'][0]['rate_date'] );
		$this->assertSame( Settings::DEFAULT_CURRENCY_BUFFER, $answer['prices'][0]['currency_buffer'] );
		$this->assertSame( 1, $this->rate_reads, 'the fake rate source was asked, and no other' );
	}

	public function test_a_variation_without_a_cost_keeps_its_price_and_says_why(): void {
		$this->store_rule();
		$ids = $this->variable_product(
			array(
				'v_a3' => '',
				'v_a4' => '',
			)
		);
		$this->script_costs(
			array(
				'v_a3' => '200',
				'v_a4' => null,
			)
		);

		$answer = $this->price->execute( array( 'product_id' => $this->parent_of( $ids['v_a3'] )->get_id() ) );

		$this->assertIsArray( $answer );
		$this->assertSame( 1, $answer['changed'] );
		$by_id = array_column( $answer['prices'], null, 'id' );
		$this->assertNull( $by_id[ $ids['v_a4'] ]['price'] );
		$this->assertSame( PriceState::FLAG_NO_COST, $by_id[ $ids['v_a4'] ]['flag'] );
		$this->assertNotEmpty( $by_id[ $ids['v_a4'] ]['flag_message'] );
	}

	public function test_pricing_is_refused_without_a_margin_for_a_product_off_a_blank_and_for_another_products_variation(): void {
		$ids        = $this->variable_product( array( 'v_a3' => '' ) );
		$product_id = $this->parent_of( $ids['v_a3'] )->get_id();
		$plain      = new WC_Product_Simple();
		$plain->save();

		$no_rule = $this->price->execute( array( 'product_id' => $product_id ) );
		$this->store_rule();
		$off_blank = $this->price->execute( array( 'product_id' => $plain->get_id() ) );
		$variation = $this->read->execute( array( 'product_id' => $ids['v_a3'] ) );
		$stranger  = $this->price->execute(
			array(
				'product_id'    => $product_id,
				'variation_ids' => array( $plain->get_id() ),
			)
		);

		$this->assertInstanceOf( WP_Error::class, $no_rule );
		$this->assertSame( ProductPrices::NO_RULE, $no_rule->get_error_code() );
		$this->assertInstanceOf( WP_Error::class, $off_blank );
		$this->assertSame( ProductPrices::NOT_PRICED, $off_blank->get_error_code() );
		$this->assertInstanceOf( WP_Error::class, $variation );
		$this->assertSame( ProductPrices::NOT_PRICED, $variation->get_error_code() );
		$this->assertInstanceOf( WP_Error::class, $stranger );
		$this->assertSame( 'pixonaut_wc_ability_unknown_variation', $stranger->get_error_code() );
		$this->assertSame( array(), $this->recorder->requests, 'a refused call asks Pixonaut nothing' );
	}

	public function test_costs_pixonaut_cannot_give_refuse_the_call_and_keep_the_prices(): void {
		$this->store_rule();
		$ids = $this->variable_product( array( 'v_a3' => '120' ) );
		$this->recorder->json( 500, array( 'error' => 'db_error' ) );

		$answer = $this->price->execute( array( 'product_id' => $this->parent_of( $ids['v_a3'] )->get_id() ) );

		$this->assertInstanceOf( WP_Error::class, $answer );
		$this->assertSame( 'pixonaut_wc_costs_unread', $answer->get_error_code() );
		$this->assertSame( '120', $this->price( $ids['v_a3'] ) );
	}

	public function test_each_ability_checks_the_users_capability(): void {
		$ids        = $this->variable_product( array( 'v_a3' => '' ) );
		$product_id = $this->parent_of( $ids['v_a3'] )->get_id();
		$input      = array( 'product_id' => $product_id );
		$may        = static fn ( Ability $ability, array $input ): bool => (bool) call_user_func( $ability->definition()['permission_callback'], $input );

		wp_set_current_user( $this->user( 'subscriber' ) );
		$this->assertFalse( $may( $this->read_settings, array() ) );
		$this->assertFalse( $may( $this->update_settings, array() ) );
		$this->assertFalse( $may( $this->read, $input ) );
		$this->assertFalse( $may( $this->price, $input ) );

		wp_set_current_user( $this->user( 'administrator' ) );
		$this->assertTrue( $may( $this->read_settings, array() ) );
		$this->assertTrue( $may( $this->update_settings, array() ) );
		$this->assertTrue( $may( $this->read, $input ) );
		$this->assertTrue( $may( $this->price, $input ) );
	}

	public function test_pixonaut_reaches_them_as_shop_tools(): void {
		global $wp_rest_server;
		$wp_rest_server = new WP_REST_Server();
		do_action( 'rest_api_init', $wp_rest_server );
		$this->plugin->connection_store()->save(
			StoredConnection::from_array(
				array(
					'site'                  => self::SITE,
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
			)
		);
		update_option(
			StoreAccess::OPTION,
			array(
				'site'       => self::SITE,
				'hash'       => hash( 'sha256', self::SECRET ),
				'cut'        => false,
				'changed_at' => 0,
			),
			false
		);
		wp_set_current_user( 0 );
		$this->store_rule();
		$ids = $this->variable_product( array( 'v_a3' => '120' ) );

		$settings = $this->call( ReadPricingSettings::NAME, array() );
		$switched = $this->call( UpdatePricingSettings::NAME, array( Settings::PRICE_AUTOMATIC => true ) );
		$prices   = $this->call( GetProductPrices::NAME, array( 'product_id' => $this->parent_of( $ids['v_a3'] )->get_id() ) );
		$refused  = $this->call( PriceProduct::NAME, array( 'product_id' => $ids['v_a3'] ) );

		$this->assertSame( '50', $settings['result'][ Settings::PRICE_MARGIN ] ?? null, (string) wp_json_encode( $settings ) );
		$this->assertTrue( $switched['result'][ Settings::PRICE_AUTOMATIC ] ?? null );
		$this->assertSame( '120', $prices['result']['prices'][0]['price'] ?? null, (string) wp_json_encode( $prices ) );
		$this->assertSame( 'invalid_input', $refused['code'] ?? null, 'a variation is priced through its product' );
	}

	/**
	 * @param string $role A role.
	 * @return int A new user with it.
	 */
	private function user( string $role ): int {
		$id = self::factory()->user->create( array( 'role' => $role ) );
		$this->assertIsInt( $id );
		return $id;
	}

	/**
	 * Calls a shop tool as Pixonaut does.
	 *
	 * @param string       $tool  The tool.
	 * @param array<mixed> $input Its input.
	 * @return array<string, mixed> The answer's body.
	 */
	private function call( string $tool, array $input ): array {
		$request = new WP_REST_Request( 'POST', self::ROUTE . $tool );
		$request->set_header( 'Content-Type', 'application/json' );
		$request->set_header( 'Authorization', 'Bearer ' . self::SECRET );
		$request->set_body( (string) wp_json_encode( (object) $input ) );
		$data = rest_do_request( $request )->get_data();
		$this->assertIsArray( $data );
		return $data;
	}
}
