<?php
/**
 * @package Pixonaut\WooCommerce
 */

declare(strict_types=1);

namespace Pixonaut\WooCommerce\Tests\Integration\Cart;

use Pixonaut\WooCommerce\Api\WcLogger;
use Pixonaut\WooCommerce\ApprovalMode;
use Pixonaut\WooCommerce\Cart\AddToCart;
use Pixonaut\WooCommerce\Cart\CartLine;
use Pixonaut\WooCommerce\Cart\CorrectionTrips;
use Pixonaut\WooCommerce\Catalogue\ProductConfig;
use Pixonaut\WooCommerce\Labels;
use Pixonaut\WooCommerce\Layout;
use Pixonaut\WooCommerce\Milliseconds;
use Pixonaut\WooCommerce\Settings\Settings;
use Pixonaut\WooCommerce\Storefront\CorrectionMount;
use Pixonaut\WooCommerce\Storefront\HostedCorrection;
use Pixonaut\WooCommerce\Storefront\PersonaliseButton;
use Pixonaut\WooCommerce\Storefront\PersonalisedDesigns;
use Pixonaut\WooCommerce\Tests\Support\DesignFixture;
use WC_Product;

/**
 * A design on the Personalise button layout is corrected on Pixonaut's hosted
 * correction page: the product page's link mints a correction link returning
 * to the store with a single-use `state`, and the return saves the correction
 * on the design and on the cart line holding it. Pixonaut is answered from a
 * script at its real address.
 */
final class HostedProductCorrectionTest extends PersonalisedCartTestCase {

	private const CORRECTION_TOKEN = 'eyJhdWQiOiJjb3JyZWN0aW9uIn0.c2lnbmF0dXJl';

	private const LINK = '0123456789abcdef0123456789abcdef';

	private const PAGE_URL = 'http://fake-pixonaut.test/h/correct/0123456789abcdef0123456789abcdef';

	private const CORRECTED_PROOF_ID = 'prf_corrected0123456789abcdef';

	private HostedCorrection $hosted;

	private ?CartLine $saved_line = null;

	public function set_up(): void {
		parent::set_up();
		$this->hosted = $this->plugin->hosted_correction();
		WC()->session->set( CorrectionTrips::SESSION_KEY, null );
		WC()->session->set( PersonalisedDesigns::SESSION_KEY, null );
	}

	private function design_key(): string {
		foreach ( (array) WC()->session->get( PersonalisedDesigns::SESSION_KEY ) as $key => $item ) {
			if ( ( $item['product_id'] ?? null ) === $this->product_id ) {
				return (string) $key;
			}
		}
		return '';
	}

	/**
	 * The product page showing the kept design.
	 */
	private function design_page(): string {
		return add_query_arg( PersonalisedDesigns::ARG, $this->design_key(), (string) get_permalink( $this->product_id ) );
	}

	/**
	 * The design waiting on the product page.
	 */
	private function waiting(): CartLine {
		$line = $this->plugin->personalised_designs()->get( $this->design_key() )['line'] ?? null;
		$this->assertNotNull( $line );
		return $line;
	}

	/**
	 * Adds the personalised product, then puts it on the button layout.
	 *
	 * @param string|null $mode The product's approval mode; null follows the setting.
	 *
	 * @return string The line's key.
	 */
	private function added( ?string $mode = null ): string {
		if ( null !== $mode ) {
			$base = self::config();
			( new ProductConfig( personalise: true, blank_id: $base->blank_id, blank_name: $base->blank_name, sides: $base->sides, approval_mode: $mode ) )
				->save_to( $this->product() );
			DesignFixture::sell_variant( $this->product() );
			$_POST[ AddToCart::APPROVAL_FIELD ] = 'yes';
		}
		$this->script_commit();
		$this->assertTrue( $this->post_add_to_cart() );
		$this->plugin->settings()->set( Settings::DEFAULT_LAYOUT, Layout::BUTTON );
		$this->recorder->requests = array();
		wc_clear_notices();
		$key  = (string) array_key_first( WC()->cart->get_cart() );
		$line = CartLine::of( WC()->cart->get_cart_item( $key ) );
		$this->assertNotNull( $line );
		$this->plugin->personalised_designs()->keep( $this->product_id, 0, $line );
		$this->saved_line = $line;
		return $key;
	}

	private function product(): WC_Product {
		$product = wc_get_product( $this->product_id );
		$this->assertInstanceOf( WC_Product::class, $product );
		return $product;
	}

	private function line( string $key ): CartLine {
		$line = CartLine::of( WC()->cart->get_cart_item( $key ) );
		$this->assertNotNull( $line );
		return $line;
	}

	private function script_page(): void {
		$this->recorder->json(
			201,
			array(
				'id'        => 'shl_0123',
				'token'     => self::LINK,
				'url'       => self::PAGE_URL,
				'expiresAt' => Milliseconds::of( time() + CorrectionTrips::LIFETIME_SECONDS ),
				'name'      => null,
				'brandId'   => null,
			)
		);
	}

	private function script_corrected(): void {
		$this->recorder->json(
			200,
			array(
				'revision'      => 2,
				'edited'        => true,
				'proofId'       => self::CORRECTED_PROOF_ID,
				'printFloorDpi' => 150,
				'lowResolution' => array(),
			)
		);
	}

	/**
	 * Sends the customer to the hosted page for the design's front.
	 *
	 * @param string $key The line's key.
	 *
	 * @return string The `state` the link carries.
	 */
	private function start( string $key ): string {
		$this->script_page();
		unset( $key );
		$this->assertSame( self::PAGE_URL, $this->hosted->start_product( $this->design_key(), 'front' ) );
		$sent = end( $this->recorder->requests );
		$this->assertIsArray( $sent );
		$body = json_decode( (string) $sent['body'], true );
		$this->assertIsArray( $body );
		return (string) $body['state'];
	}

	/**
	 * @param string $type A notice type.
	 *
	 * @return list<string>
	 */
	private static function notices( string $type ): array {
		return array_values( array_map( static fn ( array $notice ): string => (string) $notice['notice'], wc_get_notices( $type ) ) );
	}

	public function test_adding_a_button_line_lands_on_its_page(): void {
		$key = $this->added();

		$this->assertSame(
			add_query_arg( PersonaliseButton::LINE_ARG, $key, $this->product()->get_permalink() ),
			apply_filters( 'woocommerce_add_to_cart_redirect', false, $this->product() )
		);
	}

	public function test_starting_mints_a_correction_link_returning_to_the_store_with_a_state_and_no_locale(): void {
		$key   = $this->added();
		$state = $this->start( $key );

		$sent = $this->recorder->requests[0] ?? null;
		$this->assertIsArray( $sent );
		$this->assertSame( 'POST', $sent['method'] );
		$this->assertStringEndsWith( '/v1/designs/' . DesignFixture::ID . '/correction-links', $sent['url'] );
		$this->assertSame(
			array(
				'returnUrl' => HostedCorrection::return_url( HostedCorrection::PRODUCT ),
				'state'     => $state,
			),
			json_decode( (string) $sent['body'], true )
		);
	}

	public function test_returning_with_a_proof_token_saves_the_correction_on_the_design_and_the_line(): void {
		$key   = $this->added();
		$state = $this->start( $key );
		$this->script_corrected();

		$this->assertSame( $this->design_page(), $this->hosted->finish_product( $state, self::CORRECTION_TOKEN ) );

		$patch = end( $this->recorder->requests );
		$this->assertIsArray( $patch );
		$this->assertSame( 'PATCH', $patch['method'] );
		$this->assertSame( array( 'proofToken' => self::CORRECTION_TOKEN ), json_decode( (string) $patch['body'], true ) );
		$this->assertSame( 2, $this->waiting()->sides[0]->revision, 'the product page shows it too' );
		$line = $this->line( $key );
		$this->assertSame( 2, $line->sides[0]->revision );
		$this->assertSame( self::CORRECTED_PROOF_ID, $line->sides[0]->proof_id );
		$this->assertSame( ApprovalMode::ADD_TO_CART, $line->consent['kind'] ?? null );
		$this->assertSame( array( $this->plugin->labels()->get( Labels::DESIGN_UPDATED ) ), self::notices( 'success' ) );
	}

	public function test_a_state_is_used_once(): void {
		$key   = $this->added();
		$state = $this->start( $key );
		$this->script_corrected();
		$this->hosted->finish_product( $state, self::CORRECTION_TOKEN );
		$sent = count( $this->recorder->requests );

		$this->hosted->finish_product( $state, self::CORRECTION_TOKEN );

		$this->assertCount( $sent, $this->recorder->requests );
		$this->assertSame( array( $this->plugin->labels()->get( Labels::CORRECTION_VISIT_UNKNOWN ) ), self::errors() );
	}

	public function test_a_trip_is_open_until_its_lifetime_ends(): void {
		$key          = $this->added();
		$now          = time();
		$this->hosted = $this->hosted_at( $now );
		$state        = $this->start( $key );
		$this->hosted = $this->hosted_at( $now + CorrectionTrips::LIFETIME_SECONDS - 1 );
		$this->script_corrected();

		$this->hosted->finish_product( $state, self::CORRECTION_TOKEN );

		$this->assertSame( 2, $this->line( $key )->sides[0]->revision );
	}

	public function test_an_expired_trip_is_unknown_and_changes_nothing(): void {
		$key          = $this->added();
		$before       = $this->line( $key );
		$now          = time();
		$this->hosted = $this->hosted_at( $now );
		$state        = $this->start( $key );
		$sent         = count( $this->recorder->requests );
		$this->hosted = $this->hosted_at( $now + CorrectionTrips::LIFETIME_SECONDS );

		$this->assertSame( wc_get_page_permalink( 'shop' ), $this->hosted->finish_product( $state, self::CORRECTION_TOKEN ) );

		$this->assertCount( $sent, $this->recorder->requests );
		$this->assertEquals( $before, $this->line( $key ) );
		$this->assertSame( array( $this->plugin->labels()->get( Labels::CORRECTION_VISIT_UNKNOWN ) ), self::errors() );
	}

	/**
	 * The handler with its trips on a clock stopped at a time.
	 *
	 * @param int $now Unix time.
	 */
	private function hosted_at( int $now ): HostedCorrection {
		return new HostedCorrection(
			$this->plugin->cart_edit(),
			$this->plugin->order_edit(),
			new CorrectionMount( $this->plugin->connection(), $this->plugin->sdk(), new WcLogger(), $this->plugin->settings() ),
			new CorrectionTrips( static fn (): int => $now ),
			$this->plugin->labels(),
			$this->plugin->personalised_designs()
		);
	}

	public function test_a_state_this_session_did_not_open_is_unknown(): void {
		$this->added();

		$this->assertSame( wc_get_page_permalink( 'shop' ), $this->hosted->finish_product( 'not-a-state', self::CORRECTION_TOKEN ) );

		$this->assertSame( array(), $this->recorder->requests );
		$this->assertSame( array( $this->plugin->labels()->get( Labels::CORRECTION_VISIT_UNKNOWN ) ), self::errors() );
	}

	public function test_cancelling_returns_to_the_product_and_changes_nothing(): void {
		$key    = $this->added();
		$before = $this->line( $key );
		$state  = $this->start( $key );

		$this->assertSame( $this->design_page(), $this->hosted->finish_product( $state, null ) );

		$this->assertCount( 1, $this->recorder->requests );
		$this->assertEquals( $before, $this->line( $key ) );
		$this->assertSame( array(), self::errors() );
	}

	public function test_the_hosted_approval_stands_in_for_the_approval_box(): void {
		$key   = $this->added( ApprovalMode::CHECKBOX );
		$state = $this->start( $key );
		$this->script_corrected();

		$this->hosted->finish_product( $state, self::CORRECTION_TOKEN );

		$this->assertSame( ApprovalMode::CHECKBOX, $this->line( $key )->consent['kind'] ?? null );
	}

	public function test_a_refused_correction_leaves_the_line_and_says_why(): void {
		$key    = $this->added();
		$before = $this->line( $key );
		$state  = $this->start( $key );
		$this->recorder->json( 409, array( 'error' => 'revision_changed' ) );

		$this->hosted->finish_product( $state, self::CORRECTION_TOKEN );

		$this->assertEquals( $before, $this->line( $key ) );
		$this->assertSame( array( $this->plugin->labels()->get( Labels::DESIGN_CHANGED_ELSEWHERE ) ), self::errors() );
		// Nothing is kept to try again: editing opens the hosted page anew.
		$this->start( $key );
	}

	public function test_a_correction_pixonaut_could_not_save_is_saved_by_the_next_change_link(): void {
		$key   = $this->added();
		$state = $this->start( $key );
		$this->recorder->json( 500, array( 'error' => 'db_error' ) );

		$this->assertSame( $this->design_page(), $this->hosted->finish_product( $state, self::CORRECTION_TOKEN ) );

		$this->assertSame( 1, $this->line( $key )->sides[0]->revision );
		$this->assertSame( array( $this->plugin->labels()->get( Labels::DESIGN_NOT_SAVED ) ), self::errors() );

		wc_clear_notices();
		$this->recorder->requests = array();
		$this->script_corrected();

		$this->assertSame( $this->design_page(), $this->hosted->start_product( $this->design_key(), 'front' ) );

		$this->assertCount( 1, $this->recorder->requests );
		$patch = $this->recorder->requests[0];
		$this->assertSame( 'PATCH', $patch['method'] );
		$this->assertSame( array( 'proofToken' => self::CORRECTION_TOKEN ), json_decode( (string) $patch['body'], true ) );
		$this->assertSame( 2, $this->line( $key )->sides[0]->revision );
		$this->assertSame( array( $this->plugin->labels()->get( Labels::DESIGN_UPDATED ) ), self::notices( 'success' ) );
		// Saved once: the next edit opens the hosted page.
		$this->start( $key );
	}

	public function test_a_refused_link_returns_to_the_product_with_personalisation_unavailable(): void {
		$key = $this->added();
		$this->recorder->json( 409, array( 'error' => 'not_editable' ) );

		$this->assertSame( $this->design_page(), $this->hosted->start_product( $this->design_key(), 'front' ) );

		$this->assertSame( array( $this->plugin->labels()->get( Labels::PERSONALISATION_UNAVAILABLE ) ), self::errors() );
		$this->assertSame( array(), WC()->session->get( CorrectionTrips::SESSION_KEY ) );
	}

	public function test_a_design_no_line_holds_takes_the_correction_alone(): void {
		$key = $this->added();
		WC()->cart->remove_cart_item( $key );
		$this->plugin->personalised_designs()->keep( $this->product_id, 0, $this->kept_line() );
		$this->assertTrue( WC()->cart->is_empty() );
		$state = $this->start( '' );
		$this->script_corrected();

		$this->assertSame( $this->design_page(), $this->hosted->finish_product( $state, self::CORRECTION_TOKEN ) );

		$this->assertSame( 2, $this->waiting()->sides[0]->revision );
		$this->assertSame( self::CORRECTED_PROOF_ID, $this->waiting()->sides[0]->proof_id );
		$this->assertTrue( WC()->cart->is_empty() );
	}

	public function test_a_side_or_design_it_does_not_hold_is_not_sent_anywhere(): void {
		$this->added();

		$this->assertSame( wc_get_page_permalink( 'shop' ), $this->hosted->start_product( 'not:a-design', 'front' ) );
		$this->assertSame( wc_get_page_permalink( 'shop' ), $this->hosted->start_product( $this->design_key(), 'back' ) );

		$this->assertSame( array(), $this->recorder->requests );
		$this->assertCount( 2, self::errors() );
	}

	/**
	 * A copy of the line the cart held, with a design of its own.
	 */
	private function kept_line(): CartLine {
		$line = $this->saved_line;
		$this->assertNotNull( $line );
		return $line;
	}
}
