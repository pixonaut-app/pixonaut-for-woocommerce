<?php
/**
 * @package Pixonaut\WooCommerce
 */

declare(strict_types=1);

namespace Pixonaut\WooCommerce\Tests\Integration\Abilities;

use Pixonaut\WooCommerce\Abilities\Abilities;
use Pixonaut\WooCommerce\Api\Hosts;
use Pixonaut\WooCommerce\Connection\StoredConnection;
use Pixonaut\WooCommerce\Fulfilment\DeliveryChange;
use Pixonaut\WooCommerce\Fulfilment\FulfilmentJobs;
use Pixonaut\WooCommerce\Fulfilment\LineActions;
use Pixonaut\WooCommerce\Fulfilment\LineState;
use Pixonaut\WooCommerce\Fulfilment\NeedsAttention;
use Pixonaut\WooCommerce\Fulfilment\OrderAddress;
use Pixonaut\WooCommerce\Fulfilment\OrderStatus;
use Pixonaut\WooCommerce\Plugin;
use Pixonaut\WooCommerce\Tests\Support\FulfilmentAnswers;
use Pixonaut\WooCommerce\Tests\Support\HttpRecorder;
use WC_Order;
use WC_Order_Item_Product;
use WC_Product_Simple;
use WP_Ability;
use WP_Error;
use WP_Http;
use WP_UnitTestCase;

/**
 * Acting on a personalised line and changing its order's delivery address
 * through the abilities, as an MCP client does, against a recorder that
 * answers as Pixonaut: the same code as the order screen, checked against
 * the signed-in user's capability.
 */
final class OrderLineAbilitiesTest extends WP_UnitTestCase {

	private const ACT = Abilities::NAMESPACE . '/act-on-order-line';

	private const CHANGE_DELIVERY = Abilities::NAMESPACE . '/change-delivery-address';

	private const LIST_ORDERS = Abilities::NAMESPACE . '/list-personalised-orders';

	private const ORDER_ID = 'po_1';

	private const NEWER_ORDER_ID = 'po_2';

	private const CONNECTED_AT = 1767225600;

	private Plugin $plugin;

	private HttpRecorder $recorder;

	private WC_Order $order;

	private WC_Order_Item_Product $item;

	public function set_up(): void {
		parent::set_up();
		$this->plugin   = Plugin::boot( dirname( __DIR__, 4 ) . '/pixonaut-for-woocommerce.php' );
		$this->recorder = ( new HttpRecorder( Hosts::server_api() ) )->start();
		$this->plugin->connection_store()->save( self::connection() );
		$product = new WC_Product_Simple();
		$product->set_name( 'Personalised tee' );
		$product->set_regular_price( '25' );
		$product->save();
		$order = wc_create_order();
		$this->assertInstanceOf( WC_Order::class, $order );
		$order->add_product( $product );
		$order->set_shipping_city( 'Lund' );
		$order->save();
		$this->order = $order;
		$item        = current( $order->get_items() );
		$this->assertInstanceOf( WC_Order_Item_Product::class, $item );
		$this->item = $item;
		$line       = LineState::start( $item, array( 'front' ) );
		$line->set_side_ref( 'front', LineState::SIDE_DESIGN_ID, 'd_front' );
		$this->assertTrue( $line->save() );
	}

	public function tear_down(): void {
		$this->recorder->stop();
		$this->plugin->connection_store()->clear();
		as_unschedule_all_actions( DeliveryChange::ACTION, array( 'item_id' => $this->item->get_id() ), FulfilmentJobs::GROUP );
		parent::tear_down();
	}

	public function test_the_write_abilities_are_destructive_and_refused_to_users_who_may_not_manage_orders(): void {
		$this->flagged_failed();
		foreach ( array( self::ACT, self::CHANGE_DELIVERY ) as $name ) {
			$annotations = $this->ability( $name )->get_meta_item( 'annotations' );
			$this->assertFalse( $annotations['readonly'], $name );
			$this->assertTrue( $annotations['destructive'], $name );
		}
		foreach ( array( 'customer', 'editor', 'author' ) as $role ) {
			$this->sign_in( $role );

			$acted   = $this->ability( self::ACT )->execute( $this->act( LineActions::RETRY ) );
			$changed = $this->ability( self::CHANGE_DELIVERY )->execute(
				array(
					'order_id' => $this->order->get_id(),
					'address'  => array( 'city' => 'Malmö' ),
				)
			);

			$this->assertInstanceOf( WP_Error::class, $acted, $role );
			$this->assertSame( 'ability_invalid_permissions', $acted->get_error_code(), $role );
			$this->assertInstanceOf( WP_Error::class, $changed, $role );
			$this->assertSame( 'ability_invalid_permissions', $changed->get_error_code(), $role );
		}
		$this->assertSame( array(), $this->recorder->requests );
		$this->assertSame( LineState::NEEDS_ATTENTION, $this->line()->state() );
		$this->assertSame( 'Lund', $this->reloaded()->get_shipping_city() );
	}

	public function test_a_retry_runs_as_the_order_screens_and_keeps_the_lines_bookkeeping(): void {
		$this->flagged_failed();
		$this->sign_in( 'shop_manager' );
		$this->recorder
			->json( 200, $this->read( OrderStatus::FAILED, array( 'canRetry' => true ) ) )
			->json( 201, FulfilmentAnswers::order( self::NEWER_ORDER_ID, array( 'p_front' ), $this->external_ref(), array( 'replacesOrderId' => self::ORDER_ID ) ) );

		$answer = $this->ability( self::ACT )->execute( $this->act( LineActions::RETRY ) );

		$this->assertIsArray( $answer );
		$this->assertSame( LineActions::DONE, $answer['result'] );
		$this->assertSame( LineState::ORDERED, $answer['line']['state'] );
		$this->assertSame( self::NEWER_ORDER_ID, $answer['line']['pixonaut_order_id'] );
		$this->assertSame( array(), $answer['line']['actions'], 'A line at the printshop offers nothing on a live store.' );
		$line = $this->line();
		$this->assertSame( LineState::ORDERED, $line->state() );
		$this->assertSame( array( self::ORDER_ID ), $line->replaced_order_ids() );
		$this->assertSame( 'POST', $this->recorder->requests[1]['method'] );
		$this->assertStringEndsWith( '/v1/orders/' . self::ORDER_ID . '/retry', $this->recorder->requests[1]['url'] );
		$this->assertStringContainsString( self::NEWER_ORDER_ID, $this->notes() );
	}

	public function test_listing_one_order_gives_the_actions_each_line_offers_now(): void {
		$this->flagged_failed();
		$this->sign_in( 'shop_manager' );
		$this->recorder->json( 200, $this->read( OrderStatus::FAILED, array( 'canRetry' => true ) ) );

		$answer = $this->ability( self::LIST_ORDERS )->execute( array( 'order_id' => (string) $this->order->get_id() ) );

		$this->assertSame( 1, $answer['total'] );
		$this->assertFalse( $answer['more'] );
		$this->assertSame( array( $this->order->get_id() ), array_column( $answer['orders'], 'order_id' ) );
		$this->assertSame( array( LineActions::RETRY ), $answer['orders'][0]['lines'][0]['actions'] );
		$this->assertNull( $this->ability( self::LIST_ORDERS )->execute()['orders'][0]['lines'][0]['actions'], 'Pixonaut is asked for one order only.' );
		$this->assertCount( 1, $this->recorder->requests );
	}

	public function test_an_action_the_line_does_not_offer_is_refused_and_not_sent(): void {
		$this->flagged_failed();
		$this->sign_in( 'shop_manager' );
		$this->recorder->json( 200, $this->read( OrderStatus::FAILED ) );

		$answer = $this->ability( self::ACT )->execute( $this->act( LineActions::RETRY ) );

		$this->assertInstanceOf( WP_Error::class, $answer );
		$this->assertSame( 'pixonaut_wc_line_not_available', $answer->get_error_code() );
		$this->assertSame( WP_Http::CONFLICT, $answer->get_error_data()['status'] );
		$this->assertSame( array( 'GET' ), array_column( $this->recorder->requests, 'method' ) );
		$this->assertSame( LineState::NEEDS_ATTENTION, $this->line()->state() );
	}

	public function test_settling_an_unknown_outcome_needs_the_printshops_words(): void {
		$this->flagged( OrderStatus::OUTCOME_UNKNOWN, NeedsAttention::OUTCOME_UNKNOWN );
		$this->sign_in( 'shop_manager' );
		$this->recorder->json( 200, $this->read( OrderStatus::OUTCOME_UNKNOWN, array( 'statusMoves' => array( OrderStatus::SUBMITTED, OrderStatus::FAILED ) ) ) );

		$answer = $this->ability( self::ACT )->execute( $this->act( LineActions::SETTLE_SUBMITTED ) );

		$this->assertInstanceOf( WP_Error::class, $answer );
		$this->assertSame( 'pixonaut_wc_line_reason_missing', $answer->get_error_code() );
		$this->assertSame( LineActions::REASON_MISSING, $answer->get_error_data()['code'] );
		$this->assertSame( WP_Http::BAD_REQUEST, $answer->get_error_data()['status'] );
	}

	public function test_settling_with_the_printshops_words_moves_the_order_on(): void {
		$this->flagged( OrderStatus::OUTCOME_UNKNOWN, NeedsAttention::OUTCOME_UNKNOWN );
		$this->sign_in( 'shop_manager' );
		$this->recorder
			->json( 200, $this->read( OrderStatus::OUTCOME_UNKNOWN, array( 'statusMoves' => array( OrderStatus::SUBMITTED, OrderStatus::FAILED ) ) ) )
			->json( 200, FulfilmentAnswers::order( self::ORDER_ID, array( 'p_front' ), $this->external_ref(), array( 'status' => OrderStatus::SUBMITTED ) ) );

		$answer = $this->ability( self::ACT )->execute(
			$this->act(
				LineActions::SETTLE_SUBMITTED,
				array(
					'reason'        => 'They have it.',
					'printshop_ref' => 'PS-42',
				)
			)
		);

		$this->assertIsArray( $answer );
		$this->assertSame( LineActions::DONE, $answer['result'] );
		$this->assertSame( LineState::ORDERED, $this->line()->state() );
		$sent = (array) json_decode( (string) $this->recorder->requests[1]['body'], true );
		$this->assertSame(
			array(
				'status'           => OrderStatus::SUBMITTED,
				'reason'           => 'They have it.',
				'externalOrderRef' => 'PS-42',
			),
			$sent
		);
	}

	public function test_a_refusal_carries_pixonauts_code(): void {
		$this->flagged( OrderStatus::OUTCOME_UNKNOWN, NeedsAttention::OUTCOME_UNKNOWN );
		$this->sign_in( 'shop_manager' );
		$this->recorder
			->json( 200, $this->read( OrderStatus::OUTCOME_UNKNOWN ) )
			->json(
				409,
				array(
					'error' => 'order_at_printshop',
					'cause' => 'accepted',
				)
			);

		$answer = $this->ability( self::ACT )->execute( $this->act( LineActions::CANCEL ) );

		$this->assertInstanceOf( WP_Error::class, $answer );
		$this->assertSame( 'pixonaut_order_at_printshop', $answer->get_error_code() );
		$this->assertSame( 'order_at_printshop', $answer->get_error_data()['code'] );
		$this->assertSame( WP_Http::BAD_GATEWAY, $answer->get_error_data()['status'] );
		$this->assertStringContainsString( 'order_at_printshop', $answer->get_error_message() );
		$this->assertSame( LineState::NEEDS_ATTENTION, $this->line()->state() );
	}

	public function test_a_refused_retry_says_why_and_carries_pixonauts_code(): void {
		$this->flagged_failed();
		$this->sign_in( 'shop_manager' );
		$this->recorder
			->json( 200, $this->read( OrderStatus::FAILED, array( 'canRetry' => true ) ) )
			->json(
				409,
				array(
					'error'  => 'print_not_completed',
					'status' => 'created',
				)
			);

		$answer = $this->ability( self::ACT )->execute( $this->act( LineActions::RETRY ) );

		$this->assertInstanceOf( WP_Error::class, $answer );
		$this->assertSame( 'pixonaut_print_not_completed', $answer->get_error_code() );
		$this->assertSame( 'The print is still being made. Try again once it is finished.', $answer->get_error_message() );
		$this->assertSame( LineState::NEEDS_ATTENTION, $this->line()->state() );
	}

	public function test_a_line_of_another_order_is_not_found(): void {
		$this->sign_in( 'shop_manager' );

		$answer = $this->ability( self::ACT )->execute( array( 'order_id' => $this->order->get_id() + 1 ) + $this->act( LineActions::RETRY ) );

		$this->assertInstanceOf( WP_Error::class, $answer );
		$this->assertSame( 'pixonaut_wc_line_not_found', $answer->get_error_code() );
		$this->assertSame( WP_Http::NOT_FOUND, $answer->get_error_data()['status'] );
		$this->assertSame( array(), $this->recorder->requests );
	}

	public function test_an_item_of_another_order_is_not_found_and_left_as_it_was(): void {
		$this->flagged_failed();
		$other = wc_create_order();
		$this->assertInstanceOf( WC_Order::class, $other );
		$product = new WC_Product_Simple();
		$product->set_name( 'Personalised mug' );
		$product->save();
		$other->add_product( $product );
		$other->save();
		$theirs = current( $other->get_items() );
		$this->assertInstanceOf( WC_Order_Item_Product::class, $theirs );
		$line = LineState::start( $theirs, array( 'front' ) );
		$line->set_ref( LineState::REF_ORDER_ID, self::NEWER_ORDER_ID );
		$line->set_ref( LineState::REF_ORDER_STATUS, OrderStatus::FAILED );
		$this->assertTrue( $line->transition( LineState::ORDERED ) );
		$this->assertTrue( NeedsAttention::flag( LineState::of( $theirs ), $other, NeedsAttention::ORDER_FAILED, 'Flagged.' ) );
		$this->sign_in( 'shop_manager' );

		$answer = $this->ability( self::ACT )->execute( array( 'item_id' => $theirs->get_id() ) + $this->act( LineActions::RETRY ) );

		$this->assertInstanceOf( WP_Error::class, $answer );
		$this->assertSame( 'pixonaut_wc_line_not_found', $answer->get_error_code() );
		$this->assertSame( array(), $this->recorder->requests );
		$this->assertSame( LineState::NEEDS_ATTENTION, LineState::of( $theirs )->state() );
		$this->assertSame( self::NEWER_ORDER_ID, LineState::of( $theirs )->ref( LineState::REF_ORDER_ID ) );
	}

	public function test_changing_the_delivery_address_saves_it_and_sends_it_for_each_ordered_line(): void {
		$this->ordered();
		$this->sign_in( 'shop_manager' );

		$answer = $this->ability( self::CHANGE_DELIVERY )->execute(
			array(
				'order_id' => $this->order->get_id(),
				'address'  => array(
					'city'      => 'Malmö',
					'address_1' => 'Storgatan 1',
				),
			)
		);

		$this->assertIsArray( $answer );
		$this->assertSame( array( $this->item->get_id() ), $answer['sending'] );
		$this->assertSame( array( $this->item->get_id() ), array_column( $answer['lines'], 'item_id' ) );
		$order = $this->reloaded();
		$this->assertSame( 'Malmö', $order->get_shipping_city() );
		$this->assertSame( 'Storgatan 1', $order->get_shipping_address_1() );
		$this->assertTrue( as_has_scheduled_action( DeliveryChange::ACTION, array( 'item_id' => $this->item->get_id() ), FulfilmentJobs::GROUP ) );
		$this->assertSame( array(), $this->recorder->requests, 'The change is sent by its job.' );
	}

	public function test_a_delivery_change_without_fields_sends_again_one_never_answered(): void {
		$line = $this->ordered();
		$line->set_ref( LineState::REF_DELIVERY, array( DeliveryChange::FIELD_SENDING => 'fingerprint' ) );
		$this->assertTrue( $line->save() );
		$this->sign_in( 'shop_manager' );

		$answer = $this->ability( self::CHANGE_DELIVERY )->execute( array( 'order_id' => $this->order->get_id() ) );

		$this->assertIsArray( $answer );
		$this->assertSame( 'Lund', $this->reloaded()->get_shipping_city() );
		$this->assertTrue( as_has_scheduled_action( DeliveryChange::ACTION, array( 'item_id' => $this->item->get_id() ), FulfilmentJobs::GROUP ) );
	}

	public function test_a_change_that_cannot_be_scheduled_says_so_and_keeps_the_saved_address(): void {
		$this->ordered();
		$this->sign_in( 'shop_manager' );
		$refuse = static fn (): int => 0;
		add_filter( 'pre_as_enqueue_async_action', $refuse );

		try {
			$answer = $this->ability( self::CHANGE_DELIVERY )->execute(
				array(
					'order_id' => $this->order->get_id(),
					'address'  => array( 'city' => 'Malmö' ),
				)
			);
		} finally {
			remove_filter( 'pre_as_enqueue_async_action', $refuse );
		}

		$this->assertInstanceOf( WP_Error::class, $answer );
		$this->assertSame( 'pixonaut_wc_delivery_not_scheduled', $answer->get_error_code() );
		$data = $answer->get_error_data();
		$this->assertTrue( $data['saved'] );
		$this->assertSame( array( $this->item->get_id() ), $data['item_ids'] );
		$this->assertSame( WP_Http::INTERNAL_SERVER_ERROR, $data['status'] );
		$this->assertSame( 'Malmö', $this->reloaded()->get_shipping_city() );
		$this->assertFalse( as_has_scheduled_action( DeliveryChange::ACTION, array( 'item_id' => $this->item->get_id() ), FulfilmentJobs::GROUP ) );
	}

	public function test_a_delivery_change_without_fields_for_an_address_the_printshop_has_sends_nothing(): void {
		$line = $this->ordered();
		$line->set_ref( LineState::REF_DELIVERY, array( DeliveryChange::FIELD_ADDRESS => OrderAddress::fingerprint( OrderAddress::of( $this->order, $this->item ) ) ) );
		$this->assertTrue( $line->save() );
		$this->sign_in( 'shop_manager' );

		$answer = $this->ability( self::CHANGE_DELIVERY )->execute( array( 'order_id' => $this->order->get_id() ) );

		$this->assertIsArray( $answer );
		$this->assertSame( array(), $answer['sending'] );
		$this->assertFalse( as_has_scheduled_action( DeliveryChange::ACTION, array( 'item_id' => $this->item->get_id() ), FulfilmentJobs::GROUP ) );
	}

	public function test_an_order_with_no_line_ordered_yet_is_refused_and_left_as_it_was(): void {
		$this->sign_in( 'shop_manager' );

		$answer = $this->ability( self::CHANGE_DELIVERY )->execute(
			array(
				'order_id' => $this->order->get_id(),
				'address'  => array( 'city' => 'Malmö' ),
			)
		);

		$this->assertInstanceOf( WP_Error::class, $answer );
		$this->assertSame( 'pixonaut_wc_delivery_not_sendable', $answer->get_error_code() );
		$this->assertSame( WP_Http::CONFLICT, $answer->get_error_data()['status'] );
		$this->assertSame( 'Lund', $this->reloaded()->get_shipping_city() );
		$this->assertFalse( as_has_scheduled_action( DeliveryChange::ACTION, array( 'item_id' => $this->item->get_id() ), FulfilmentJobs::GROUP ) );
	}

	/**
	 * The ability's input acting on the line.
	 *
	 * @param string               $action The action.
	 * @param array<string, mixed> $more   Further input.
	 * @return array<string, mixed>
	 */
	private function act( string $action, array $more = array() ): array {
		return array(
			'order_id' => $this->order->get_id(),
			'item_id'  => $this->item->get_id(),
			'action'   => $action,
		) + $more;
	}

	/**
	 * The line holding its printshop order.
	 *
	 * @param string $status Pixonaut's order status.
	 */
	private function ordered( string $status = OrderStatus::SUBMITTED ): LineState {
		$line = $this->line();
		$line->set_ref( LineState::REF_ORDER_ID, self::ORDER_ID );
		$line->set_ref( LineState::REF_ORDER_STATUS, $status );
		$line->set_side_ref( 'front', LineState::SIDE_PROOF_ID, 'prf_1' );
		$this->assertTrue( $line->transition( LineState::ORDERED ) );
		return $this->line();
	}

	/**
	 * The line in `needs_attention` after its printshop order reached $status.
	 *
	 * @param string $status Pixonaut's order status.
	 * @param string $reason The line's reason.
	 */
	private function flagged( string $status, string $reason ): void {
		$this->assertTrue( NeedsAttention::flag( $this->ordered( $status ), $this->order, $reason, 'Flagged.' ) );
	}

	private function flagged_failed(): void {
		$this->flagged( OrderStatus::FAILED, NeedsAttention::ORDER_FAILED );
	}

	/**
	 * Pixonaut's answer to reading the line's order.
	 *
	 * @param string               $status    The order's status.
	 * @param array<string, mixed> $overrides Further fields.
	 * @return array<string, mixed>
	 */
	private function read( string $status, array $overrides = array() ): array {
		return FulfilmentAnswers::read_order( self::ORDER_ID, $this->external_ref(), array( 'status' => $status ) + $overrides );
	}

	private function external_ref(): string {
		return 'wc:site_fixture:order:' . $this->order->get_id() . ':item:' . $this->item->get_id();
	}

	private function line(): LineState {
		return LineState::of( $this->item );
	}

	private function reloaded(): WC_Order {
		$order = wc_get_order( $this->order->get_id() );
		$this->assertInstanceOf( WC_Order::class, $order );
		return $order;
	}

	private function notes(): string {
		$notes = wc_get_order_notes( array( 'order_id' => $this->order->get_id() ) );
		return implode( "\n", array_map( static fn ( $note ): string => (string) $note->content, $notes ) );
	}

	private function ability( string $name ): WP_Ability {
		$ability = wp_get_ability( $name );
		$this->assertInstanceOf( WP_Ability::class, $ability, $name );
		return $ability;
	}

	private function sign_in( string $role ): void {
		$user = self::factory()->user->create( array( 'role' => $role ) );
		$this->assertIsInt( $user );
		wp_set_current_user( $user );
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
