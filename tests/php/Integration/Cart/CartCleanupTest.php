<?php
/**
 * @package Pixonaut\WooCommerce
 */

declare(strict_types=1);

namespace Pixonaut\WooCommerce\Tests\Integration\Cart;

use Automattic\WooCommerce\StoreApi\Utilities\OrderController;
use Pixonaut\WooCommerce\Api\Hosts;
use Pixonaut\WooCommerce\Cart\CartCleanup;
use Pixonaut\WooCommerce\Cart\CartLine;
use Pixonaut\WooCommerce\Fulfilment\RetryPolicy;
use Pixonaut\WooCommerce\Refs;
use Pixonaut\WooCommerce\Storefront\PersonalisedDesigns;
use Pixonaut\WooCommerce\Tests\Support\ActionSchedulerRunner;
use Pixonaut\WooCommerce\Tests\Support\DesignFixture;
use ReflectionProperty;
use WC_Order;

final class CartCleanupTest extends PersonalisedCartTestCase {

	private const CHECKOUT = array(
		'payment_method'     => 'cod',
		'billing_first_name' => 'Alexandra',
		'billing_last_name'  => 'Lind',
		'billing_email'      => 'alexandra@example.org',
		'billing_country'    => 'SE',
		'billing_address_1'  => 'Storgatan 1',
		'billing_city'       => 'Stockholm',
		'billing_postcode'   => '11122',
	);

	/**
	 * Seconds a test allows between scheduling a job and reading when it is due.
	 */
	private const CLOCK_SLACK = 5;

	private string $cart_item_key;

	public function set_up(): void {
		parent::set_up();
		as_unschedule_all_actions( CartCleanup::ACTION );
		$this->script_commit();
		$this->assertTrue( $this->post_add_to_cart() );
		$this->cart_item_key      = (string) array_key_first( WC()->cart->get_cart() );
		$this->recorder->requests = array();
	}

	public function tear_down(): void {
		as_unschedule_all_actions( CartCleanup::ACTION );
		parent::tear_down();
	}

	public function test_removing_a_line_deletes_its_design_after_the_grace_period(): void {
		WC()->cart->remove_cart_item( $this->cart_item_key );

		$due = ActionSchedulerRunner::due_at( CartCleanup::ACTION );
		$this->assertCount( 1, $due );
		$this->assertEqualsWithDelta( time() + CartCleanup::REMOVAL_GRACE_SECONDS, $due[0], self::CLOCK_SLACK );
		$this->assertCount( 0, $this->recorder->requests, 'Nothing is deleted while the removal can be undone.' );

		$this->recorder->raw( 204, '', 'application/json' );
		$this->assertSame( 1, ActionSchedulerRunner::run_pending( CartCleanup::ACTION ) );

		$this->assertSame( array( 'DELETE' ), array_column( $this->recorder->requests, 'method' ) );
		$this->assertSame( Hosts::server_api() . '/v1/designs/' . DesignFixture::ID, $this->recorder->requests[0]['url'] );
		$this->assertSame( array(), ActionSchedulerRunner::failed( CartCleanup::ACTION ) );
	}

	public function test_a_quantity_set_to_zero_removes_the_line_and_schedules_its_delete(): void {
		WC()->cart->set_quantity( $this->cart_item_key, 0 );

		$this->assertCount( 1, ActionSchedulerRunner::pending( CartCleanup::ACTION ) );
	}

	public function test_undoing_a_removal_keeps_the_design(): void {
		WC()->cart->remove_cart_item( $this->cart_item_key );
		WC()->cart->restore_cart_item( $this->cart_item_key );

		$this->assertSame( array(), ActionSchedulerRunner::pending( CartCleanup::ACTION ) );
	}

	public function test_undoing_a_removal_cancels_a_retry_of_its_delete(): void {
		WC()->cart->remove_cart_item( $this->cart_item_key );
		$this->recorder->json( 500, array( 'error' => 'db_error' ) );
		$this->assertSame( 1, ActionSchedulerRunner::run_once( CartCleanup::ACTION ) );
		$this->assertCount( 1, ActionSchedulerRunner::pending( CartCleanup::ACTION ), 'The failed delete is retried.' );

		WC()->cart->restore_cart_item( $this->cart_item_key );

		$this->assertSame( array(), ActionSchedulerRunner::pending( CartCleanup::ACTION ) );
	}

	public function test_a_retry_pending_is_not_scheduled_again(): void {
		WC()->cart->remove_cart_item( $this->cart_item_key );
		$this->recorder->json( 500, array( 'error' => 'db_error' ) );
		ActionSchedulerRunner::run_once( CartCleanup::ACTION );
		$line = CartLine::of( WC()->cart->removed_cart_contents[ $this->cart_item_key ] );
		$this->assertNotNull( $line );

		do_action( 'woocommerce_remove_cart_item_from_session', 'key', array( CartLine::KEY => $line->to_array() ) );

		$this->assertCount( 1, ActionSchedulerRunner::pending( CartCleanup::ACTION ) );
	}

	public function test_a_line_dropped_while_loading_the_cart_deletes_its_design(): void {
		$item = WC()->cart->get_cart_item( $this->cart_item_key );

		// What WooCommerce does with a line whose product can no longer be bought.
		do_action( 'woocommerce_remove_cart_item_from_session', $this->cart_item_key, $item );

		$this->assertCount( 1, ActionSchedulerRunner::pending( CartCleanup::ACTION ) );
	}

	public function test_adding_the_same_designs_again_keeps_them(): void {
		WC()->cart->remove_cart_item( $this->cart_item_key );
		$this->script_commit();

		$this->assertTrue( $this->post_add_to_cart() );

		$this->assertSame( array(), ActionSchedulerRunner::pending( CartCleanup::ACTION ) );
	}

	public function test_emptying_the_cart_deletes_its_designs_at_once(): void {
		WC()->cart->empty_cart();

		$due = ActionSchedulerRunner::due_at( CartCleanup::ACTION );
		$this->assertCount( 1, $due );
		$this->assertLessThanOrEqual( time(), $due[0] );
	}

	public function test_logging_out_keeps_the_designs_of_the_persistent_cart(): void {
		$guest   = (string) WC()->session->get_customer_id();
		$user_id = $this->user();
		self::set_session_customer( (string) $user_id );

		// What WooCommerce does when the customer logs out.
		WC()->cart->empty_cart( false );

		self::set_session_customer( $guest );
		$this->assertSame( array(), ActionSchedulerRunner::pending( CartCleanup::ACTION ) );
	}

	public function test_a_guest_session_forgotten_deletes_its_designs(): void {
		WC()->cart->empty_cart( false );

		$this->assertCount( 1, ActionSchedulerRunner::pending( CartCleanup::ACTION ) );
	}

	public function test_a_placed_order_keeps_its_designs_when_the_cart_is_emptied(): void {
		$this->classic_checkout( fn ( WC_Order $order ) => $this->recorder->json( 200, self::moved( $order ) ) );

		WC()->cart->empty_cart();

		$this->assertSame( array(), ActionSchedulerRunner::pending( CartCleanup::ACTION ) );
	}

	public function test_an_order_line_holding_the_design_stops_the_job_even_after_a_failed_move(): void {
		$this->classic_checkout( fn () => $this->recorder->json( 500, array( 'error' => 'db_error' ) ) );
		$this->recorder->requests = array();
		as_schedule_single_action( time(), CartCleanup::ACTION, self::args(), CartCleanup::GROUP );

		$this->assertSame( 1, ActionSchedulerRunner::run_pending( CartCleanup::ACTION ) );

		$this->assertSame( array(), $this->recorder->requests );
	}

	public function test_the_block_checkout_draft_does_not_keep_a_removed_line(): void {
		( new OrderController() )->create_order_from_cart();

		WC()->cart->remove_cart_item( $this->cart_item_key );

		$this->assertCount( 1, ActionSchedulerRunner::pending( CartCleanup::ACTION ) );
	}

	public function test_an_expired_session_deletes_its_designs_before_woocommerce_drops_it(): void {
		$item = WC()->cart->get_cart_item( $this->cart_item_key );
		self::store_session( 'guest-expired', $item, time() - 1 );
		self::store_session( 'guest-alive', $item, time() + HOUR_IN_SECONDS );

		do_action( 'woocommerce_cleanup_sessions' );

		$this->assertCount( 1, ActionSchedulerRunner::pending( CartCleanup::ACTION ) );
		$this->assertNotContains( 'guest-expired', self::session_keys(), 'WooCommerce still drops the expired session.' );
		$this->assertContains( 'guest-alive', self::session_keys() );
	}

	public function test_an_expired_session_deletes_a_design_that_waited_on_its_product_page(): void {
		global $wpdb;
		$item                         = WC()->cart->get_cart_item( $this->cart_item_key );
		$data                         = $item[ CartLine::KEY ];
		$data['sides'][0]['designId'] = 'design-waiting';
		$wpdb->insert( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
			$wpdb->prefix . 'woocommerce_sessions',
			array(
				'session_key'    => 'guest-waiting',
				'session_value'  => maybe_serialize( array( PersonalisedDesigns::SESSION_KEY => maybe_serialize( array( '1:0' => array( CartLine::KEY => $data ) ) ) ) ),
				'session_expiry' => time() - 1,
			)
		);

		do_action( 'woocommerce_cleanup_sessions' );

		$this->assertCount( 1, ActionSchedulerRunner::pending( CartCleanup::ACTION ) );
	}

	public function test_an_expired_backlog_is_swept_in_batches(): void {
		$item     = WC()->cart->get_cart_item( $this->cart_item_key );
		$sessions = CartCleanup::SWEEP_BATCH + 1;
		for ( $n = 0; $n < $sessions; ++$n ) {
			$data                         = $item[ CartLine::KEY ];
			$data['sides'][0]['designId'] = 'design-' . $n;
			self::store_session( 'guest-' . $n, array( CartLine::KEY => $data ) + $item, time() - 1 );
		}

		do_action( 'woocommerce_cleanup_sessions' );

		$this->assertCount( $sessions, ActionSchedulerRunner::pending( CartCleanup::ACTION ) );
	}

	public function test_an_expired_session_keeps_the_designs_of_its_users_persistent_cart(): void {
		$user_id = $this->user();
		$item    = WC()->cart->get_cart_item( $this->cart_item_key );
		update_user_meta( $user_id, '_woocommerce_persistent_cart_' . get_current_blog_id(), array( 'cart' => array( $this->cart_item_key => $item ) ) );
		self::store_session( (string) $user_id, $item, time() - 1 );

		do_action( 'woocommerce_cleanup_sessions' );

		$this->assertSame( array(), ActionSchedulerRunner::pending( CartCleanup::ACTION ) );
	}

	/**
	 * @return iterable<string, array{0: int, 1: array<string, string>}>
	 */
	public static function done_answers(): iterable {
		yield 'design_approved' => array( 409, array( 'error' => 'design_approved' ) );
		yield 'design_printed' => array( 409, array( 'error' => 'design_printed' ) );
		yield 'not_found' => array( 404, array( 'error' => 'not_found' ) );
		yield 'forbidden' => array( 403, array( 'error' => 'forbidden' ) );
	}

	/**
	 * @dataProvider done_answers
	 *
	 * @param int                   $status Status Pixonaut answers.
	 * @param array<string, string> $body   Its body.
	 */
	public function test_a_refusal_ends_the_job( int $status, array $body ): void {
		as_schedule_single_action( time(), CartCleanup::ACTION, self::args(), CartCleanup::GROUP );
		$this->recorder->json( $status, $body );

		$this->assertSame( 1, ActionSchedulerRunner::run_pending( CartCleanup::ACTION ) );

		$this->assertCount( 1, $this->recorder->requests );
		$this->assertSame( array(), ActionSchedulerRunner::failed( CartCleanup::ACTION ) );
	}

	public function test_a_server_error_is_retried_on_the_backoff_until_it_gives_up(): void {
		as_schedule_single_action( time(), CartCleanup::ACTION, self::args(), CartCleanup::GROUP );
		$this->recorder->json( 500, array( 'error' => 'db_error' ) );

		$this->assertSame( 1, ActionSchedulerRunner::run_once( CartCleanup::ACTION ) );

		$due = ActionSchedulerRunner::due_at( CartCleanup::ACTION );
		$this->assertCount( 1, $due );
		$this->assertEqualsWithDelta( time() + RetryPolicy::SCHEDULE[0], $due[0], self::CLOCK_SLACK );

		foreach ( RetryPolicy::SCHEDULE as $wait ) {
			unset( $wait );
			$this->recorder->json( 503, array( 'error' => 'lock_timeout' ) );
		}
		ActionSchedulerRunner::run_pending( CartCleanup::ACTION );

		$this->assertCount( count( RetryPolicy::SCHEDULE ) + 1, $this->recorder->requests );
		$this->assertSame( array(), ActionSchedulerRunner::pending( CartCleanup::ACTION ) );
	}

	public function test_a_paused_connection_waits_and_sends_nothing(): void {
		$this->plugin->connection_store()->pause( 'unauthorized' );
		as_schedule_single_action( time(), CartCleanup::ACTION, self::args(), CartCleanup::GROUP );

		ActionSchedulerRunner::run_once( CartCleanup::ACTION );

		$this->assertSame( array(), $this->recorder->requests );
		$this->assertCount( 1, ActionSchedulerRunner::pending( CartCleanup::ACTION ) );
	}

	public function test_a_design_of_another_workspace_is_left_alone(): void {
		as_schedule_single_action( time(), CartCleanup::ACTION, self::args( 'tnt_other' ), CartCleanup::GROUP );

		ActionSchedulerRunner::run_pending( CartCleanup::ACTION );

		$this->assertSame( array(), $this->recorder->requests );
	}

	/**
	 * Places the cart through the classic checkout.
	 *
	 * @param callable $answer_move `fn(WC_Order)`: scripts the answer to the ref move.
	 */
	private function classic_checkout( callable $answer_move ): WC_Order {
		// Item ids are known only once the order is saved.
		add_action( 'woocommerce_checkout_order_created', $answer_move, 1 );
		$order_id = WC()->checkout()->create_order( self::CHECKOUT );
		$this->assertIsInt( $order_id );
		$order = wc_get_order( $order_id );
		$this->assertInstanceOf( WC_Order::class, $order );
		return $order;
	}

	/**
	 * @param WC_Order $order The placed order.
	 *
	 * @return array<string, string> The answer to its one line's ref move.
	 */
	private static function moved( WC_Order $order ): array {
		return array(
			'designId'    => DesignFixture::ID,
			'externalRef' => Refs::order_item_ref( self::SITE, $order->get_id(), (int) array_key_first( $order->get_items() ) ),
		);
	}

	/**
	 * @param string $tenant_id The workspace the design was committed to.
	 *
	 * @return array{design_id: string, tenant_id: string, failures: int}
	 */
	private static function args( string $tenant_id = self::TENANT ): array {
		return array(
			'design_id' => DesignFixture::ID,
			'tenant_id' => $tenant_id,
			'failures'  => 0,
		);
	}

	private function user(): int {
		$id = self::factory()->user->create();
		if ( ! is_int( $id ) ) {
			$this->fail( 'Could not create a user.' );
		}
		return $id;
	}

	private static function set_session_customer( string $customer_id ): void {
		( new ReflectionProperty( WC()->session, '_customer_id' ) )->setValue( WC()->session, $customer_id );
	}

	/**
	 * @return list<string> The stored sessions' customer ids.
	 */
	private static function session_keys(): array {
		global $wpdb;
		return array_values( $wpdb->get_col( "SELECT session_key FROM {$wpdb->prefix}woocommerce_sessions ORDER BY session_key" ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
	}

	/**
	 * Stores a session holding one cart item, as WooCommerce saves one.
	 *
	 * @param string               $key     The session's customer id.
	 * @param array<string, mixed> $item    The cart item.
	 * @param int                  $expires Unix time it expires at.
	 */
	private static function store_session( string $key, array $item, int $expires ): void {
		global $wpdb;
		$wpdb->insert( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
			$wpdb->prefix . 'woocommerce_sessions',
			array(
				'session_key'    => $key,
				'session_value'  => maybe_serialize( array( 'cart' => maybe_serialize( array( 'item' => $item ) ) ) ),
				'session_expiry' => $expires,
			)
		);
	}
}
