<?php
/**
 * @package Pixonaut\WooCommerce
 */

declare(strict_types=1);

namespace Pixonaut\WooCommerce\Tests\Integration\Fulfilment;

use Closure;
use Pixonaut\WooCommerce\Admin\OrderBox;
use Pixonaut\WooCommerce\Api\Client;
use Pixonaut\WooCommerce\Api\Deprecations;
use Pixonaut\WooCommerce\Api\Operations;
use Pixonaut\WooCommerce\Api\Orders;
use Pixonaut\WooCommerce\Api\PreparedRequest;
use Pixonaut\WooCommerce\Api\WcLogger;
use Pixonaut\WooCommerce\Api\WpHttpTransport;
use Pixonaut\WooCommerce\Connection\StoredConnection;
use Pixonaut\WooCommerce\Fulfilment\DeliveryChange;
use Pixonaut\WooCommerce\Fulfilment\FulfilmentJobs;
use Pixonaut\WooCommerce\Fulfilment\LineState;
use Pixonaut\WooCommerce\Fulfilment\OrderAddress;
use Pixonaut\WooCommerce\Fulfilment\OrderDeliveryChanged;
use Pixonaut\WooCommerce\Fulfilment\OrderEventLines;
use Pixonaut\WooCommerce\Plugin;
use Pixonaut\WooCommerce\Refs;
use Pixonaut\WooCommerce\Tests\Support\ActionSchedulerRunner;
use Pixonaut\WooCommerce\Tests\Support\FulfilmentAnswers;
use Pixonaut\WooCommerce\Webhooks\Event;
use WC_Order;
use WC_Order_Item_Product;

/**
 * Saving an order with a changed address sends the new one to each line's
 * printshop order, once per change, and notes what came of it.
 */
final class DeliveryChangeTest extends FulfilmentTestCase {

	private const ORDER_ID = 'o_1';

	private const PATH = '/v1/orders/' . self::ORDER_ID . '/delivery';

	/**
	 * When Pixonaut answered a change, epoch milliseconds.
	 */
	private const ANSWERED_AT_MS = 1_800_000_100_000;

	private const MS_PER_SECOND = 1000;

	/**
	 * The fingerprint and key of a later change, started in another request.
	 */
	private const LATER = 'fingerprint-of-a-later-address';

	private const LATER_KEY = 'wc:site:item:1:delivery:2';

	/**
	 * A phone number a body filter completes with how often it was asked.
	 */
	private const PHONE_PREFIX = '+4670123456';

	/**
	 * The fingerprint of the address the printshop order was placed with.
	 *
	 * @var string
	 */
	private string $placed = '';

	public function set_up(): void {
		parent::set_up();
		$client = new Client( self::BASE_URL, Operations::plugin(), $this->store, new WpHttpTransport(), new WcLogger(), new Deprecations() );
		$store  = $this->store;
		$change = new DeliveryChange( new Orders( $client ), static fn (): ?StoredConnection => $store->load(), new WcLogger() );
		// The plugin's own triggers stay; its job runs against the recorder.
		remove_all_actions( DeliveryChange::ACTION );
		add_action( DeliveryChange::ACTION, array( $change, 'run' ) );
		$this->order->set_status( 'processing' );
		$this->order->save();
		as_unschedule_all_actions( FulfilmentJobs::ACTION );
	}

	public function test_a_changed_shipping_address_is_sent_with_a_key_and_noted(): void {
		$this->ordered();
		$this->recorder->json( 200, $this->answer( DeliveryChange::ACCEPTED ) );

		$this->move_to( 'Göteborg' );
		ActionSchedulerRunner::run_pending( DeliveryChange::ACTION );

		$sent = $this->sent();
		$this->assertCount( 1, $sent );
		$this->assertSame( Refs::delivery_key( self::SITE, $this->item->get_id(), 1 ), $sent[0]['headers']['Idempotency-Key'] ?? null );
		$body = json_decode( (string) $sent[0]['body'], true );
		$this->assertSame( 'Göteborg', $body['shippingAddress']['city'] ?? null );
		$this->assertSame( 'Ada Lovelace', $body['recipient']['name'] ?? null );
		$this->assertStringContainsString( 'the printshop took the new address of "Personalised tee"', $this->notes() );
		$line = $this->line();
		$this->assertSame( DeliveryChange::ACCEPTED, DeliveryChange::of( $line )[ DeliveryChange::FIELD_STATE ] ?? null );
		$this->assertSame( 'New delivery address: taken by the printshop.', OrderBox::delivery_label( $line ) );
	}

	public function test_an_order_waiting_for_its_prints_takes_the_new_address_with_it(): void {
		$this->ordered( 'awaiting_prints' );
		$this->recorder->json( 200, $this->answer( DeliveryChange::CARRIED ) );

		$this->move_to( 'Göteborg' );
		ActionSchedulerRunner::run_pending( DeliveryChange::ACTION );

		$this->assertCount( 1, $this->sent() );
		$this->assertStringContainsString( 'goes to the printshop with the order', $this->notes() );
		$line = $this->line();
		$this->assertSame( DeliveryChange::CARRIED, DeliveryChange::of( $line )[ DeliveryChange::FIELD_STATE ] ?? null );
		$this->assertSame( 'New delivery address: goes to the printshop with the order.', OrderBox::delivery_label( $line ) );
	}

	public function test_a_save_that_leaves_the_address_as_it_was_sends_nothing(): void {
		$this->ordered();

		// The order ships to its shipping address, so a billing city is not part of it.
		$this->order->set_billing_city( 'Uppsala' );
		$this->order->set_customer_note( 'Gift wrap, please.' );
		$this->order->save();
		ActionSchedulerRunner::run_pending( DeliveryChange::ACTION );

		$this->assertSame( array(), $this->recorder->requests );
		$this->assertSame( '', OrderBox::delivery_label( $this->line() ) );
	}

	public function test_a_line_not_yet_ordered_gets_no_job(): void {
		$this->move_to( 'Göteborg' );

		$this->assertSame( array(), ActionSchedulerRunner::pending( DeliveryChange::ACTION ) );
	}

	public function test_a_refusal_is_noted_and_the_same_address_is_not_sent_again(): void {
		$this->ordered();
		$this->recorder->json(
			409,
			array(
				'error'  => 'order_past_address_change',
				'status' => 'queued',
				'words'  => null,
			)
		);

		$this->move_to( 'Göteborg' );
		ActionSchedulerRunner::run_pending( DeliveryChange::ACTION );
		$this->order->set_billing_city( 'Uppsala' );
		$this->order->save();
		ActionSchedulerRunner::run_pending( DeliveryChange::ACTION );

		$this->assertCount( 1, $this->sent() );
		$this->assertStringContainsString( 'its printshop order is past changing its address ("queued" in Pixonaut)', $this->notes() );
		$this->assertStringContainsString( 'not sent, Pixonaut answered "order_past_address_change"', OrderBox::delivery_label( $this->line() ) );
	}

	public function test_a_printshop_that_takes_no_changes_is_named(): void {
		$this->ordered();
		$this->recorder->json(
			409,
			array(
				'error'     => 'address_change_not_accepted',
				'printshop' => 'Fixture Print AB',
			)
		);

		$this->move_to( 'Göteborg' );
		ActionSchedulerRunner::run_pending( DeliveryChange::ACTION );

		$this->assertStringContainsString( 'its printshop, Fixture Print AB, takes no address changes through Pixonaut', $this->notes() );
	}

	public function test_a_printshop_that_could_not_be_told_is_asked_again_under_the_same_key(): void {
		$this->ordered();
		$this->recorder
			->json( 503, $this->unavailable() )
			->json( 200, $this->answer( DeliveryChange::ACCEPTED ) );

		$this->move_to( 'Göteborg' );
		ActionSchedulerRunner::run_once( DeliveryChange::ACTION );

		$this->assertCount( 1, ActionSchedulerRunner::due_at( DeliveryChange::ACTION ) );
		$this->assertStringContainsString( 'being sent to Pixonaut', OrderBox::delivery_label( $this->line() ) );

		ActionSchedulerRunner::run_once( DeliveryChange::ACTION );

		$sent = $this->sent();
		$this->assertCount( 2, $sent );
		$this->assertSame( $sent[0]['headers']['Idempotency-Key'] ?? null, $sent[1]['headers']['Idempotency-Key'] ?? null );
		$this->assertSame( DeliveryChange::ACCEPTED, DeliveryChange::of( $this->line() )[ DeliveryChange::FIELD_STATE ] ?? null );
	}

	public function test_a_later_change_back_gets_a_key_of_its_own(): void {
		$this->ordered();
		$this->recorder
			->json( 200, $this->answer( DeliveryChange::ACCEPTED ) )
			->json( 200, $this->answer( DeliveryChange::ACCEPTED ) );

		$this->move_to( 'Göteborg' );
		ActionSchedulerRunner::run_pending( DeliveryChange::ACTION );
		$this->move_to( 'Stockholm' );
		ActionSchedulerRunner::run_pending( DeliveryChange::ACTION );

		$sent = $this->sent();
		$this->assertCount( 2, $sent );
		$this->assertSame( Refs::delivery_key( self::SITE, $this->item->get_id(), 2 ), $sent[1]['headers']['Idempotency-Key'] ?? null );
	}

	public function test_a_change_still_being_told_is_noted_as_such(): void {
		$this->ordered();
		$this->recorder->json( 200, $this->answer( DeliveryChange::SENDING ) );

		$this->move_to( 'Göteborg' );
		ActionSchedulerRunner::run_pending( DeliveryChange::ACTION );

		$this->assertStringContainsString( 'Pixonaut is telling the printshop the new address of "Personalised tee"', $this->notes() );
		$this->assertSame( 'New delivery address: Pixonaut is telling the printshop.', OrderBox::delivery_label( $this->line() ) );
		// The printshop order keeps the address it had until the printshop answers.
		$delivery = DeliveryChange::of( $this->line() );
		$this->assertSame( $this->placed, $delivery[ DeliveryChange::FIELD_ADDRESS ] ?? null );
		$this->assertSame( $this->fingerprint(), $delivery[ DeliveryChange::FIELD_AWAITING ] ?? null );

		// Saving the order again does not send the address Pixonaut is telling the printshop.
		$this->order->set_billing_city( 'Uppsala' );
		$this->order->save();
		ActionSchedulerRunner::run_pending( DeliveryChange::ACTION );
		$this->assertCount( 1, $this->sent() );
	}

	public function test_an_answer_to_an_earlier_change_leaves_a_later_one_on_its_way(): void {
		$this->ordered();
		$this->recorder->json( 200, $this->answer( DeliveryChange::ACCEPTED ) );
		// The address is changed again and that change started in another request while this one is sent.
		$this->while_sent(
			static fn ( array $delivery ): array => array(
				DeliveryChange::FIELD_SENDING => self::LATER,
				DeliveryChange::FIELD_KEY     => self::LATER_KEY,
			) + $delivery
		);

		$this->move_to( 'Göteborg' );
		ActionSchedulerRunner::run_once( DeliveryChange::ACTION );

		$delivery = DeliveryChange::of( $this->line() );
		$this->assertSame( self::LATER, $delivery[ DeliveryChange::FIELD_SENDING ] ?? null );
		$this->assertSame( self::LATER_KEY, $delivery[ DeliveryChange::FIELD_KEY ] ?? null );
		$this->assertSame( $this->fingerprint(), $delivery[ DeliveryChange::FIELD_ADDRESS ] ?? null );
	}

	public function test_an_answer_older_than_the_outcome_the_line_holds_changes_nothing(): void {
		$this->ordered();
		$this->recorder->json( 200, $this->answer( DeliveryChange::ACCEPTED ) );
		// `order.delivery_changed` brought a later outcome while the answer was on its way.
		$this->while_sent(
			static fn ( array $delivery ): array => array(
				DeliveryChange::FIELD_STATE => DeliveryChange::REFUSED,
				DeliveryChange::FIELD_AT    => PHP_INT_MAX,
			) + $delivery
		);

		$this->move_to( 'Göteborg' );
		ActionSchedulerRunner::run_once( DeliveryChange::ACTION );

		$delivery = DeliveryChange::of( $this->line() );
		$this->assertSame( $this->placed, $delivery[ DeliveryChange::FIELD_ADDRESS ] ?? null );
		$this->assertSame( DeliveryChange::REFUSED, $delivery[ DeliveryChange::FIELD_STATE ] ?? null );
		$this->assertArrayNotHasKey( DeliveryChange::FIELD_SENDING, $delivery );
	}

	public function test_a_change_whose_retries_ran_out_is_not_known_and_sent_again_when_the_order_is_saved(): void {
		add_filter( 'pixonaut_wc_fulfilment_retry_schedule', static fn (): array => array() );
		$this->ordered();
		$this->recorder
			->json( 503, $this->unavailable() )
			->json( 200, $this->answer( DeliveryChange::ACCEPTED ) );

		$this->move_to( 'Göteborg' );
		ActionSchedulerRunner::run_pending( DeliveryChange::ACTION );

		$this->assertStringContainsString( 'it is not known whether the new address of "Personalised tee" reached the printshop: Pixonaut answered "address_change_unavailable". Update the order to send it again.', $this->notes() );
		$this->assertSame( 'New delivery address: not known whether it reached the printshop (Pixonaut answered "address_change_unavailable"). Update the order to send it again.', OrderBox::delivery_label( $this->line() ) );
		$delivery = DeliveryChange::of( $this->line() );
		$this->assertSame( $this->fingerprint(), $delivery[ DeliveryChange::FIELD_SENDING ] ?? null );
		$this->assertArrayNotHasKey( DeliveryChange::FIELD_REFUSED, $delivery );
		$this->assertSame( array(), ActionSchedulerRunner::pending( DeliveryChange::ACTION ) );

		$this->save_on_screen();
		$this->assertCount( 1, ActionSchedulerRunner::pending( DeliveryChange::ACTION ) );
		ActionSchedulerRunner::run_pending( DeliveryChange::ACTION );

		$sent = $this->sent();
		$this->assertCount( 2, $sent );
		$this->assertSame( $sent[0]['headers']['Idempotency-Key'] ?? null, $sent[1]['headers']['Idempotency-Key'] ?? null );
		$this->assertSame( 'New delivery address: taken by the printshop.', OrderBox::delivery_label( $this->line() ) );
	}

	public function test_an_answer_after_the_orders_personal_data_was_erased_writes_nothing(): void {
		$this->ordered();
		$this->recorder->json( 200, $this->answer( DeliveryChange::ACCEPTED ) );
		$item_id = $this->item->get_id();
		add_action(
			'pixonaut_wc_api_before_request',
			static function () use ( $item_id ): void {
				$line = LineState::of( new WC_Order_Item_Product( $item_id ) );
				$line->set_ref( LineState::REF_ORDER_ERASED_AT, time() );
				$line->set_ref( LineState::REF_DELIVERY, null );
				$line->save();
			}
		);

		$this->move_to( 'Göteborg' );
		ActionSchedulerRunner::run_pending( DeliveryChange::ACTION );

		$this->assertCount( 1, $this->sent() );
		$this->assertNull( $this->line()->ref( LineState::REF_DELIVERY ) );
	}

	public function test_a_new_address_goes_at_once_in_place_of_a_delayed_retry(): void {
		$this->ordered();
		as_schedule_single_action( time() + HOUR_IN_SECONDS, DeliveryChange::ACTION, array( 'item_id' => $this->item->get_id() ), FulfilmentJobs::GROUP );

		$this->move_to( 'Göteborg' );

		$due = ActionSchedulerRunner::due_at( DeliveryChange::ACTION );
		$this->assertCount( 1, $due );
		$this->assertLessThanOrEqual( time(), $due[0] );
	}

	public function test_a_retry_sends_the_request_as_first_sent(): void {
		$this->ordered();
		$this->recorder
			->json( 503, $this->unavailable() )
			->json( 200, $this->answer( DeliveryChange::ACCEPTED ) );
		$prepared = 0;
		// A body filter that answers differently each time it is asked.
		add_filter(
			'pixonaut_wc_api_request_body',
			static function ( array $body, string $operation ) use ( &$prepared ): array {
				if ( Operations::CHANGE_ORDER_DELIVERY === $operation ) {
					++$prepared;
					$body['recipient']['phone'] = self::PHONE_PREFIX . $prepared;
				}
				return $body;
			},
			10,
			2
		);

		$this->move_to( 'Göteborg' );
		ActionSchedulerRunner::run_once( DeliveryChange::ACTION );
		ActionSchedulerRunner::run_once( DeliveryChange::ACTION );

		$sent = $this->sent();
		$this->assertCount( 2, $sent );
		$this->assertSame( $sent[0]['body'], $sent[1]['body'] );
		$this->assertArrayNotHasKey( DeliveryChange::FIELD_REQUEST, DeliveryChange::of( $this->line() ) );
	}

	public function test_an_address_saved_while_not_connected_is_noted_and_sent_once_the_order_is_saved_connected(): void {
		$this->ordered();
		$this->store->clear();

		$this->move_to( 'Göteborg' );
		ActionSchedulerRunner::run_pending( DeliveryChange::ACTION );

		$this->assertSame( array(), $this->recorder->requests );
		$this->assertStringContainsString( 'the new address of "Personalised tee" was not sent: the store is not connected to the Pixonaut workspace the order was placed under', $this->notes() );

		$this->store->save( self::connection() );
		$this->recorder->json( 200, $this->answer( DeliveryChange::ACCEPTED ) );
		$this->save_on_screen();
		ActionSchedulerRunner::run_pending( DeliveryChange::ACTION );

		$this->assertCount( 1, $this->sent() );
		$this->assertSame( $this->fingerprint(), DeliveryChange::of( $this->line() )[ DeliveryChange::FIELD_ADDRESS ] ?? null );
	}

	public function test_after_a_change_made_in_pixonaut_only_a_change_in_woocommerce_is_sent(): void {
		$this->ordered();
		$this->recorder->json( 200, $this->answer( DeliveryChange::ACCEPTED ) );
		$plugin = Plugin::boot( dirname( __DIR__, 4 ) . '/pixonaut-for-woocommerce.php' );
		$event  = new Event(
			'evt_1',
			OrderDeliveryChanged::TYPE,
			time() * self::MS_PER_SECOND,
			array(
				'orderId'     => self::ORDER_ID,
				'printIds'    => array( 'p_front' ),
				'externalRef' => Refs::order_item_ref( self::SITE, $this->order->get_id(), $this->item->get_id() ),
				'by'          => 'member',
				'outcome'     => DeliveryChange::ACCEPTED,
				'words'       => null,
			)
		);
		( new OrderDeliveryChanged( new OrderEventLines( $plugin->connection(), new WcLogger() ) ) )->handle( $event );

		// The order ships to its shipping address, so a billing city is not part of it.
		$this->order->set_billing_city( 'Uppsala' );
		$this->order->save();
		ActionSchedulerRunner::run_pending( DeliveryChange::ACTION );
		$this->assertSame( array(), $this->sent() );

		$this->move_to( 'Göteborg' );
		ActionSchedulerRunner::run_pending( DeliveryChange::ACTION );
		$this->assertCount( 1, $this->sent() );
	}

	public function test_an_address_without_a_street_is_not_sent(): void {
		$this->ordered();

		$this->order->set_shipping_address_1( '' );
		$this->order->set_billing_address_1( '' );
		$this->order->save();
		ActionSchedulerRunner::run_pending( DeliveryChange::ACTION );

		$this->assertSame( array(), $this->recorder->requests );
		$this->assertStringContainsString( 'it needs a name, a street, a city and a country', $this->notes() );
	}

	/**
	 * The line holding its printshop order, placed with the order's address.
	 *
	 * @param string $status The order's status in Pixonaut.
	 */
	private function ordered( string $status = 'submitted' ): void {
		$line = $this->line();
		$line->set_ref( LineState::REF_ORDER_ID, self::ORDER_ID );
		$line->set_ref( LineState::REF_ORDER_STATUS, $status );
		$this->placed = $this->fingerprint();
		$line->set_ref( LineState::REF_DELIVERY, array( DeliveryChange::FIELD_ADDRESS => $this->placed ) );
		$this->assertTrue( $line->transition( LineState::ORDERED ) );
	}

	/**
	 * Saves the order shipping to another city.
	 *
	 * @param string $city The city.
	 */
	private function move_to( string $city ): void {
		$this->order->set_shipping_city( $city );
		$this->order->save();
	}

	/**
	 * The fingerprint of the order's address as it is saved now.
	 */
	private function fingerprint(): string {
		$order = wc_get_order( $this->order->get_id() );
		$this->assertInstanceOf( WC_Order::class, $order );
		return OrderAddress::fingerprint( OrderAddress::of( $order, $this->item ) );
	}

	/**
	 * Writes the line's `delivery` ref, as another request would, while the
	 * first change is sent.
	 *
	 * @param Closure $change `fn(array $delivery): array`, the ref as the other request leaves it.
	 */
	private function while_sent( Closure $change ): void {
		$item_id = $this->item->get_id();
		$done    = false;
		add_action(
			'pixonaut_wc_api_before_request',
			static function ( PreparedRequest $request ) use ( $item_id, $change, &$done ): void {
				if ( $done || Operations::CHANGE_ORDER_DELIVERY !== $request->operation ) {
					return;
				}
				$done = true;
				$line = LineState::of( new WC_Order_Item_Product( $item_id ) );
				$line->set_ref( LineState::REF_DELIVERY, $change( DeliveryChange::of( $line ) ) );
				$line->save();
			}
		);
	}

	/**
	 * Saves the order on its screen.
	 */
	private function save_on_screen(): void {
		do_action( 'woocommerce_process_shop_order_meta', $this->order->get_id(), get_post( $this->order->get_id() ) );
	}

	/**
	 * Pixonaut's answer that the printshop could not be told, worth asking again.
	 *
	 * @return array<string, mixed>
	 */
	private function unavailable(): array {
		return array(
			'error'     => 'address_change_unavailable',
			'reason'    => 'The printshop did not answer.',
			'retryable' => true,
		);
	}

	/**
	 * Pixonaut's answer to the change: the order, its `delivery` in $state.
	 *
	 * @param string $state The change's `delivery.state`.
	 *
	 * @return array<string, mixed>
	 */
	private function answer( string $state ): array {
		return FulfilmentAnswers::order(
			self::ORDER_ID,
			array( 'p_front' ),
			Refs::order_item_ref( self::SITE, $this->order->get_id(), $this->item->get_id() ),
			array(
				'delivery' => array(
					'state' => $state,
					'words' => null,
					'at'    => self::ANSWERED_AT_MS,
				),
			)
		);
	}

	/**
	 * @return list<array<string, mixed>> The changes sent.
	 */
	private function sent(): array {
		return array_values( array_filter( $this->recorder->requests, static fn ( array $request ): bool => 'PATCH' === $request['method'] && self::BASE_URL . self::PATH === $request['url'] ) );
	}

	/**
	 * The order's notes as one string.
	 */
	private function notes(): string {
		$notes = wc_get_order_notes( array( 'order_id' => $this->order->get_id() ) );
		return implode( "\n", array_map( static fn ( $note ): string => (string) $note->content, $notes ) );
	}
}
