<?php
/**
 * @package Pixonaut\WooCommerce
 */

declare(strict_types=1);

namespace Pixonaut\WooCommerce\Tests\Integration\Pricing;

use Pixonaut\WooCommerce\Admin\MarginFloorList;
use Pixonaut\WooCommerce\Catalogue\ProductConfig;
use Pixonaut\WooCommerce\Pricing\ExchangeRates;
use Pixonaut\WooCommerce\Pricing\MarginFloor;
use Pixonaut\WooCommerce\Pricing\PriceRule;
use Pixonaut\WooCommerce\Settings\Settings;
use Pixonaut\WooCommerce\Tests\Support\FreshRequest;
use Pixonaut\WooCommerce\Tests\Support\SentMail;

/**
 * A price whose margin falls below the store's lowest margin, whatever it
 * follows, is marked, shown on the products list and named in one daily
 * email, once per drop.
 */
final class MarginFloorTest extends PricingTestCase {

	private const SUBJECT = 'Prices below your lowest margin';

	private MarginFloor $floor;

	public function set_up(): void {
		parent::set_up();
		// WooCommerce's mailer hooks its emails' triggers once a request, when it is built.
		WC()->mailer()->init();
		reset_phpmailer_instance();
		FreshRequest::begin( $this->plugin );
		$this->floor = $this->plugin->margin_floor();
	}

	public function test_a_cost_rise_pushes_a_pinned_price_under_the_floor_and_one_email_names_it_once(): void {
		$this->store_rule(
			array(
				PriceRule::MIN => '399',
				PriceRule::MAX => '399',
			)
		);
		$this->plugin->settings()->set( Settings::PRICE_MARGIN_FLOOR, '30' );
		$ids     = $this->variable_product(
			array(
				'v_a3' => '',
				'v_a4' => '',
			)
		);
		$product = fn () => $this->parent_of( $ids['v_a3'] );
		$this->script_costs(
			array(
				'v_a3' => '200',
				'v_a4' => '100',
			)
		);
		$this->pricing->refresh( $product() );
		$this->pricing->apply( $product() );
		$this->assertSame( '399', $this->price( $ids['v_a3'] ) );
		$this->assertSame( array(), $this->floor->low(), '49.9 % and 74.9 % are above the floor' );

		// 24.8 %: the pinned price does not move, so its margin falls under the floor.
		$this->script_costs(
			array(
				'v_a3' => '300',
				'v_a4' => '100',
			)
		);
		$this->pricing->refresh( $product() );

		$this->assertSame( '399', $this->price( $ids['v_a3'] ) );
		$this->assertSame( array( $ids['v_a3'] => $product()->get_id() ), $this->floor->low( MarginFloor::NEW ) );

		$this->floor->report();

		$mails = $this->floor_mails();
		$this->assertCount( 1, $mails );
		$this->assertSame( get_option( 'admin_email' ), $mails[0]['to'] );
		$this->assertStringContainsString( 'Poster', $mails[0]['body'] );
		$this->assertStringContainsString( 'v_a3', $mails[0]['body'] );
		$this->assertStringNotContainsString( 'v_a4', $mails[0]['body'] );
		$this->assertStringContainsString( '24.8 %', $mails[0]['body'] );
		$this->assertStringContainsString( 'post.php?post=' . $product()->get_id() . '&amp;action=edit', $mails[0]['body'] );
		$this->assertSame( array( $ids['v_a3'] => $product()->get_id() ), $this->floor->low( MarginFloor::REPORTED ) );

		$this->floor->report();
		$this->pricing->refresh( $this->script_and( $product(), '300' ) );
		$this->floor->report();

		$this->assertCount( 1, $this->floor_mails(), 'a price already named is not named again while it stays below' );

		// Back at 49.9 %, then down again.
		$this->pricing->refresh( $this->script_and( $product(), '200' ) );
		$this->assertSame( array(), $this->floor->low() );
		$this->pricing->refresh( $this->script_and( $product(), '300' ) );
		$this->floor->report();

		$this->assertCount( 2, $this->floor_mails(), 'a price that recovered and fell again is named again' );
	}

	public function test_without_a_floor_nothing_is_marked_or_sent(): void {
		$this->store_rule();
		$ids = $this->variable_product( array( 'v_a3' => '250' ) );
		$this->script_costs( array( 'v_a3' => '200' ) );

		$this->pricing->refresh( $this->parent_of( $ids['v_a3'] ) );
		$this->floor->report();

		$this->assertSame( array(), $this->floor->low() );
		$this->assertSame( array(), $this->floor_mails() );
	}

	public function test_a_price_set_by_hand_is_checked_and_listed_on_the_products_list(): void {
		$this->plugin->settings()->set( Settings::PRICE_MARGIN_FLOOR, '30' );
		$id = $this->simple_product( 'v_a3', '500' );
		$this->script_costs( array( 'v_a3' => '200' ) );
		$this->pricing->refresh( $this->product( $id ) );
		$list = new MarginFloorList( $this->floor, $this->pricing );
		$this->assertSame( '', $list->notice_html() );

		$this->edit_price( $id, '250' );

		$this->assertSame( array( $id => $id ), $this->floor->low( MarginFloor::NEW ) );
		$this->assertStringContainsString( '1 price has a margin below your lowest margin of 30.0 %.', $list->notice_html() );
		$this->assertStringContainsString( 'Mug: ', $list->column_html( $id ) );
		$this->assertStringContainsString( '(margin 20.0 %)', $list->column_html( $id ) );
		$this->assertStringContainsString( 'Below lowest margin', (string) ( $list->views( array() )[ MarginFloorList::QUERY_ARG ] ?? '' ) );
		set_current_screen( 'edit-product' );
		$_GET[ MarginFloorList::QUERY_ARG ] = '1';
		$vars                               = $list->request( array( 'post_type' => 'product' ) );
		unset( $_GET[ MarginFloorList::QUERY_ARG ] );
		set_current_screen( 'front' );
		$this->assertSame( array( $id ), is_array( $vars ) ? $vars['post__in'] : null );

		$this->edit_price( $id, '400' );

		$this->assertSame( array(), $this->floor->low(), '50 %: back above the floor' );
		$this->assertSame( '', $list->column_html( $id ) );
	}

	public function test_setting_a_floor_checks_the_prices_there_are_and_taking_it_away_clears_them(): void {
		$id = $this->simple_product( 'v_a3', '250' );
		$this->script_costs( array( 'v_a3' => '200' ) );
		$this->pricing->refresh( $this->product( $id ) );

		$this->plugin->settings()->set( Settings::PRICE_MARGIN_FLOOR, '30' );
		$this->assertTrue( as_has_scheduled_action( MarginFloor::SWEEP_HOOK, array( 0 ), MarginFloor::GROUP ) );
		$this->floor->sweep( 0 );

		$this->assertSame( array( $id => $id ), $this->floor->low() );

		$this->plugin->settings()->set( Settings::PRICE_MARGIN_FLOOR, '' );

		$this->assertSame( array(), $this->floor->low() );
	}

	public function test_a_cost_without_an_exchange_rate_keeps_the_mark_until_it_is_known_again(): void {
		update_option( 'woocommerce_currency', 'SEK' );
		// One euro is 11.50 kronor.
		$this->rates = array( 'SEK' => '11.50' );
		$this->plugin->settings()->set( Settings::PRICE_CURRENCY_BUFFER, '0' );
		$this->plugin->settings()->set( Settings::PRICE_MARGIN_FLOOR, '30' );
		$id = $this->simple_product( 'v_a3', '250' );
		$this->script_costs( array( 'v_a3' => '20' ), null, 'EUR' );

		// 230 SEK against 250: 8 %.
		$this->pricing->refresh( $this->product( $id ) );
		$this->assertSame( array( $id => $id ), $this->floor->low( MarginFloor::NEW ) );

		// The rates are gone: the cost cannot be told in kronor, which is no recovery.
		delete_option( ExchangeRates::OPTION );
		$this->floor->check( $id );

		$this->assertSame( array( $id => $id ), $this->floor->low( MarginFloor::NEW ) );
		$this->floor->report();
		$this->assertSame( array(), $this->floor_mails(), 'a price whose margin is not known is not named' );
		$this->assertSame( array( $id => $id ), $this->floor->low( MarginFloor::NEW ) );
	}

	public function test_the_daily_job_checks_every_price_first_and_names_one_whose_margin_fell_with_no_hook(): void {
		$this->plugin->settings()->set( Settings::PRICE_MARGIN_FLOOR, '30' );
		$id = $this->simple_product( 'v_a3', '500' );
		$this->script_costs( array( 'v_a3' => '200' ) );
		$this->pricing->refresh( $this->product( $id ) );
		$this->assertSame( array(), $this->floor->low() );

		// Written past WooCommerce, as an import or another plugin may: no hook sees it.
		update_metadata( 'post', $id, '_regular_price', '250' );
		$this->floor->report();

		$this->assertSame( array( $id => $id ), $this->floor->low( MarginFloor::REPORTED ) );
		$this->assertCount( 1, $this->floor_mails() );
	}

	public function test_variations_of_a_product_taken_off_its_blank_lose_their_marks(): void {
		$this->plugin->settings()->set( Settings::PRICE_MARGIN_FLOOR, '30' );
		$ids = $this->variable_product(
			array(
				'v_a3' => '250',
				'v_a4' => '250',
			)
		);
		$this->pricing->refresh( $this->script_and( $this->parent_of( $ids['v_a3'] ), '200' ) );
		$this->assertSame( array( $ids['v_a3'] ), array_keys( $this->floor->low() ) );

		delete_post_meta( $this->parent_of( $ids['v_a3'] )->get_id(), ProductConfig::META_KEY );

		$this->assertSame( array(), $this->floor->low() );
	}

	public function test_one_email_names_at_most_its_limit_and_the_rest_the_next_day(): void {
		$this->plugin->settings()->set( Settings::PRICE_MARGIN_FLOOR, '30' );
		$floor = new MarginFloor( $this->pricing, $this->plugin->settings(), 1 );
		$ids   = $this->variable_product(
			array(
				'v_a3' => '250',
				'v_a4' => '120',
			)
		);
		$this->pricing->refresh( $this->script_and( $this->parent_of( $ids['v_a3'] ), '200' ) );
		$this->assertCount( 2, $this->floor->low( MarginFloor::NEW ) );

		$floor->report();

		$mails = $this->floor_mails();
		$this->assertCount( 1, $mails );
		$this->assertStringContainsString( '1 more price fell below your lowest margin and will be named in a later email.', $mails[0]['body'] );
		$this->assertStringContainsString( MarginFloorList::QUERY_ARG, $mails[0]['body'] );
		$this->assertCount( 1, $floor->low( MarginFloor::NEW ) );

		$floor->report();

		$this->assertCount( 2, $this->floor_mails() );
		$this->assertStringNotContainsString( 'more price', $this->floor_mails()[1]['body'] );
		$this->assertSame( array(), $floor->low( MarginFloor::NEW ) );
	}

	/**
	 * Scripts the next read of the fixture's costs, with one of them moved.
	 *
	 * @param \WC_Product $product The product read.
	 * @param string      $cost    The cost of `v_a3`.
	 */
	private function script_and( \WC_Product $product, string $cost ): \WC_Product {
		$this->script_costs(
			array(
				'v_a3' => $cost,
				'v_a4' => '100',
			)
		);
		return $product;
	}

	/**
	 * @return list<array{to: string, subject: string, body: string}>
	 */
	private function floor_mails(): array {
		return array_values( array_filter( SentMail::all(), static fn ( array $mail ): bool => str_contains( $mail['subject'], self::SUBJECT ) ) );
	}
}
