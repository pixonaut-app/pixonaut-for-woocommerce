<?php
/**
 * @package Pixonaut\WooCommerce
 */

declare(strict_types=1);

namespace Pixonaut\WooCommerce\Tests\Integration\Pricing;

use Pixonaut\WooCommerce\Admin\PricingList;
use Pixonaut\WooCommerce\Admin\PricingPanel;
use Pixonaut\WooCommerce\Admin\ProductPanel;
use Pixonaut\WooCommerce\Catalogue\ProductConfig;
use Pixonaut\WooCommerce\Pricing\PriceRule;
use Pixonaut\WooCommerce\Pricing\ProductPricing;
use Pixonaut\WooCommerce\Settings\Settings;
use WC_Product_Simple;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;

/**
 * The Prices section of the Pixonaut tab and the products list's flag, over
 * the prices route.
 */
final class PricingPanelTest extends PricingTestCase {

	private PricingPanel $panel;

	private bool $connected = true;

	public function set_up(): void {
		parent::set_up();
		$this->panel = new PricingPanel( $this->pricing, $this->plugin->templates(), fn (): bool => $this->connected );
		$admin       = self::factory()->user->create( array( 'role' => 'administrator' ) );
		$this->assertIsInt( $admin );
		wp_set_current_user( $admin );
	}

	public function tear_down(): void {
		$_POST = array();
		$_GET  = array();
		parent::tear_down();
	}

	public function test_the_section_lists_each_variation_with_its_cost_price_and_margin(): void {
		$this->store_rule();
		$ids = $this->variable_product(
			array(
				'v_a3' => '250',
				'v_a4' => '',
			)
		);
		$this->script_costs(
			array(
				'v_a3' => '190',
				'v_a4' => null,
			),
			'10'
		);

		$answer = $this->act( $this->parent_of( $ids['v_a3'] )->get_id(), PricingPanel::REFRESH );

		$this->assertSame( 'The costs are read.', $answer['message'] );
		$html = $answer['html'];
		$this->assertStringContainsString( 'data-item="' . $ids['v_a3'] . '" data-standing="own"', $html );
		$this->assertStringContainsString( self::money( '200' ), $html );
		$this->assertStringContainsString( self::money( '190' ) . ' + ' . self::money( '10' ) . ' shipping', $html );
		$this->assertStringContainsString( self::money( '250' ), $html );
		$this->assertStringContainsString( '20.0 %', $html );
		$this->assertStringContainsString( 'Suggested: ' . self::money( '399' ), $html );
		$this->assertStringContainsString( 'The printshop publishes no price for it, so the rule cannot price it.', $html );
		$this->assertStringContainsString( 'Rule not applied yet', $html );
		$this->assertStringContainsString( 'placeholder="50"', $html, 'an empty part shows the store’s' );
		$this->assertStringNotContainsString( 'data-unread', $html );
	}

	public function test_a_section_without_costs_asks_to_be_read_and_one_without_a_blank_is_not_shown(): void {
		$id = $this->simple_product( 'v_mug', '300' );

		$html = $this->panel->html( $this->product( $id ), false );

		$this->assertStringContainsString( 'data-unread', $html );
		$this->assertStringContainsString( 'data-simple', $html );
		$this->assertStringContainsString( 'No target margin set', $html );
		$this->assertStringNotContainsString( ' open', substr( $html, 0, (int) strpos( $html, '>' ) ) );

		$plain = new WC_Product_Simple();
		$plain->save();
		( new ProductConfig( personalise: true ) )->save_to( $plain );
		$this->assertSame( '', $this->panel->html( $this->product( $plain->get_id() ), false ) );

		$this->connected = false;
		$this->assertSame( '', $this->panel->html( $this->product( $id ), false ) );
	}

	public function test_costs_pixonaut_is_still_reading_are_said_to_be_on_their_way(): void {
		$this->store_rule();
		$id = $this->simple_product( 'v_mug', '300' );
		$this->script_costs( array() );

		$answer = $this->act( $id, PricingPanel::APPLY );

		$this->assertSame( 'Pixonaut is reading the printshop’s costs. Refresh the costs in a moment.', $answer['message'] );
		$this->assertSame( '300', $this->price( $id ) );
		$this->assertStringContainsString( 'No cost is read for it yet.', $answer['html'] );
	}

	public function test_apply_stores_the_rule_as_typed_reads_the_costs_and_sets_every_price(): void {
		$id = $this->simple_product( 'v_mug', '300' );
		$this->script_costs( array( 'v_mug' => '100' ) );

		$answer = $this->act(
			$id,
			PricingPanel::APPLY,
			null,
			array(
				PriceRule::MARGIN => '60',
				PriceRule::STEP   => '5',
				PriceRule::ENDING => '0',
			)
		);

		$this->assertSame( '250', $this->price( $id ) );
		$this->assertSame( 1, $answer['changed'] );
		$this->assertSame( '1 price set.', $answer['message'] );
		$this->assertSame( array( $id => '250' ), $answer['prices'] );
		$this->assertSame( '60', ProductPricing::own_parts( $this->product( $id ) )[ PriceRule::MARGIN ] );
		$this->assertStringContainsString( 'value="60"', $answer['html'] );
		$this->assertStringContainsString( 'Follows the rule, set ', $answer['html'] );
		$this->assertStringContainsString( 'Following the pricing rule', $answer['html'] );
	}

	public function test_reading_the_costs_reports_the_prices_it_changed_by_itself(): void {
		$this->store_rule();
		$this->plugin->settings()->set( Settings::PRICE_AUTOMATIC, true );
		$id = $this->simple_product( 'v_mug', '' );
		$this->script_costs( array( 'v_mug' => '200' ) );
		$this->act( $id, PricingPanel::APPLY );
		$this->script_costs( array( 'v_mug' => '155' ) );

		$answer = $this->act( $id, PricingPanel::REFRESH );

		$this->assertSame( 1, $answer['changed'], 'the product form is told to show the new price' );
		$this->assertSame( '1 price set.', $answer['message'] );
		$this->assertSame( array( $id => '309' ), $answer['prices'] );
	}

	public function test_a_price_edited_by_hand_follows_the_rule_again_on_request(): void {
		$this->store_rule();
		$id = $this->simple_product( 'v_mug', '' );
		$this->script_costs( array( 'v_mug' => '100' ) );
		$this->act( $id, PricingPanel::APPLY );
		$this->edit_price( $id, '300' );

		$kept = $this->panel->html( $this->product( $id ), true );
		$this->assertStringContainsString( 'Edited by hand: kept', $kept );
		$this->assertStringContainsString( 'data-action="follow" data-item="' . $id . '"', $kept );

		$answer = $this->act( $id, PricingPanel::FOLLOW, array( $id ) );

		$this->assertSame( '199', $this->price( $id ) );
		$this->assertSame( 1, $answer['changed'] );
		$this->assertCount( 1, $this->recorder->requests, 'giving a price back reads no costs' );
	}

	public function test_an_action_says_so_when_pixonaut_cannot_be_asked_or_the_product_has_no_blank(): void {
		$id = $this->simple_product( 'v_mug', '300' );
		$this->recorder->json( 422, array( 'error' => 'unknown_region' ) );
		$nowhere = $this->panel->act( self::request( $id, PricingPanel::REFRESH ) );
		$this->assertInstanceOf( WP_Error::class, $nowhere );
		$this->assertSame( 409, $nowhere->get_error_data()['status'] );
		$this->assertStringContainsString( 'no printshop costs for your shop’s country (' . WC()->countries->get_base_country() . ')', $nowhere->get_error_message() );

		// More failures than the client tries.
		$this->recorder->fail();
		$this->recorder->fail();
		$this->recorder->fail();

		$refused = $this->panel->act( self::request( $id, PricingPanel::REFRESH ) );

		$this->assertInstanceOf( WP_Error::class, $refused );
		$this->assertSame( 502, $refused->get_error_data()['status'] );
		$this->assertStringContainsString( 'Pixonaut could not be reached', $refused->get_error_message() );

		$plain = new WC_Product_Simple();
		$plain->save();
		$missing = $this->panel->act( self::request( $plain->get_id(), PricingPanel::REFRESH ) );
		$this->assertInstanceOf( WP_Error::class, $missing );
		$this->assertSame( 404, $missing->get_error_data()['status'] );
	}

	public function test_saving_the_product_stores_its_parts_of_the_rule_and_keeps_a_price_changed_in_the_form(): void {
		$this->store_rule();
		$id = $this->simple_product( 'v_mug', '' );
		$this->script_costs( array( 'v_mug' => '100' ) );
		$this->act( $id, PricingPanel::APPLY );
		// The cost leaves the tolerance, so the price is flagged.
		$this->script_costs( array( 'v_mug' => '150' ) );
		$this->act( $id, PricingPanel::REFRESH );
		$this->assertSame( 1, ProductPricing::flagged( $this->product( $id ) ) );

		// The store manager types another price and a lowest price, and updates the product.
		$this->edit_price( $id, '280' );
		$_POST[ ProductPanel::NONCE_FIELD ] = wp_create_nonce( ProductPanel::NONCE_ACTION );
		$_POST[ PricingPanel::FIELD ]       = array(
			PriceRule::MIN    => '250,50',
			PriceRule::MARGIN => 'lots',
		);
		$this->panel->save( $id );

		$this->assertSame( '250.50', ProductPricing::own_parts( $this->product( $id ) )[ PriceRule::MIN ] );
		$this->assertSame( '', ProductPricing::own_parts( $this->product( $id ) )[ PriceRule::MARGIN ] );
		$this->assertSame( 0, ProductPricing::flagged( $this->product( $id ) ), 'a price edited by hand is no longer flagged' );
		$this->assertSame( '280', $this->price( $id ) );
	}

	public function test_saving_without_the_panels_nonce_stores_nothing(): void {
		$id                           = $this->simple_product( 'v_mug', '300' );
		$_POST[ PricingPanel::FIELD ] = array( PriceRule::MARGIN => '60' );

		$this->panel->save( $id );

		$this->assertSame( '', ProductPricing::own_parts( $this->product( $id ) )[ PriceRule::MARGIN ] );
	}

	public function test_the_products_list_flags_a_price_whose_margin_left_the_tolerance_and_applies_the_suggestion(): void {
		$this->store_rule();
		$ids       = $this->variable_product(
			array(
				'v_a3' => '',
				'v_a4' => '',
			)
		);
		$parent_id = $this->parent_of( $ids['v_a3'] )->get_id();
		$this->script_costs(
			array(
				'v_a3' => '200',
				'v_a4' => '100',
			)
		);
		$this->act( $parent_id, PricingPanel::APPLY );
		$list = new PricingList( $this->pricing );
		$this->assertSame( '', $list->flag_html( $this->product( $parent_id ) ), 'nothing to flag while the margins hold' );

		$this->script_costs(
			array(
				'v_a3' => '155',
				'v_a4' => null,
			)
		);
		$this->act( $parent_id, PricingPanel::REFRESH );

		ob_start();
		$list->column( 'name', $parent_id );
		$list->column( 'price', $parent_id );
		$flag = (string) ob_get_clean();
		$this->assertStringContainsString( 'Margin off target', $flag );
		$this->assertStringContainsString( 'v_a3: ' . self::money( '399' ) . ' → ' . self::money( '309' ) . ' suggested', $flag );
		$this->assertStringContainsString( 'v_a4: The printshop publishes no price for it', $flag );
		$this->assertStringContainsString( 'action=' . PricingList::BULK_ACTION, $flag );
		$this->assertSame( 1, substr_count( $flag, 'pixonaut-price-flag"' ), 'only the name column carries the flag' );

		$redirect = $list->handle_bulk( 'http://example.org/wp-admin/edit.php?post_type=product', PricingList::BULK_ACTION, array( $parent_id ) );

		$this->assertSame( '309', $this->price( $ids['v_a3'] ) );
		$this->assertSame( '199', $this->price( $ids['v_a4'] ), 'a price the rule cannot set stays' );
		$this->assertStringContainsString( PricingList::DONE_ARG . '=1', (string) $redirect );
		$this->assertStringNotContainsString( 'suggested', $list->flag_html( $this->product( $parent_id ) ) );
		$this->assertStringContainsString( 'publishes no price', $list->flag_html( $this->product( $parent_id ) ) );
		$this->assertStringContainsString( 'Price not set by the pricing rule', $list->flag_html( $this->product( $parent_id ) ) );
		$this->assertStringNotContainsString( 'action=' . PricingList::BULK_ACTION, $list->flag_html( $this->product( $parent_id ) ), 'nothing to apply' );
		$this->assertSame( 'http://x.test/', $list->handle_bulk( 'http://x.test/', 'trash', array( $parent_id ) ) );
	}

	public function test_the_products_list_says_how_many_prices_were_set(): void {
		set_current_screen( 'edit-product' );
		$list                          = new PricingList( $this->pricing );
		$_GET[ PricingList::DONE_ARG ] = '2';

		ob_start();
		$list->bulk_notice();
		$notice = (string) ob_get_clean();

		$this->assertStringContainsString( '2 prices were set to the prices the pricing rule suggests.', $notice );
		$this->assertArrayHasKey( PricingList::BULK_ACTION, (array) $list->bulk_actions( array() ) );
		set_current_screen( 'front' );
	}

	/**
	 * Asks the prices route.
	 *
	 * @param int                        $product_id The product.
	 * @param string                     $action     A `PricingPanel` action.
	 * @param list<int>|null             $ids        The prices it names.
	 * @param array<string, string>|null $rule       The rule as typed.
	 * @return array{html: string, message: string, changed: int, prices: array<int, string>}
	 */
	private function act( int $product_id, string $action, ?array $ids = null, ?array $rule = null ): array {
		$response = $this->panel->act( self::request( $product_id, $action, $ids, $rule ) );
		$this->assertInstanceOf( WP_REST_Response::class, $response );
		return $response->get_data();
	}

	/**
	 * @param int                        $product_id The product.
	 * @param string                     $action     A `PricingPanel` action.
	 * @param list<int>|null             $ids        The prices it names.
	 * @param array<string, string>|null $rule       The rule as typed.
	 * @return WP_REST_Request<array<string, mixed>>
	 */
	private static function request( int $product_id, string $action, ?array $ids = null, ?array $rule = null ): WP_REST_Request {
		$request = new WP_REST_Request( 'POST', '/pixonaut/v1/products/' . $product_id . '/prices' );
		$request->set_param( 'product', $product_id );
		$request->set_param( 'action', $action );
		if ( null !== $ids ) {
			$request->set_param( 'ids', $ids );
		}
		if ( null !== $rule ) {
			$request->set_param( 'rule', $rule );
		}
		return $request;
	}

	/**
	 * An amount as the section shows it.
	 *
	 * @param string $amount A decimal amount.
	 */
	private static function money( string $amount ): string {
		return PricingPanel::money( self::amount( $amount ) );
	}
}
