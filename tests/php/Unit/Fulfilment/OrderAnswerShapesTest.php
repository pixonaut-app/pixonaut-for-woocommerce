<?php
/**
 * @package Pixonaut\WooCommerce
 */

declare(strict_types=1);

namespace Pixonaut\WooCommerce\Tests\Unit\Fulfilment;

use Brain\Monkey\Functions;
use Pixonaut\WooCommerce\Api\Answers\Order;
use Pixonaut\WooCommerce\Fulfilment\AdoptedOrder;
use Pixonaut\WooCommerce\Fulfilment\DeliveryChange;
use Pixonaut\WooCommerce\Fulfilment\OrderAddress;
use Pixonaut\WooCommerce\Fulfilment\OrderHold;
use Pixonaut\WooCommerce\Fulfilment\OrderStatusChanged;
use Pixonaut\WooCommerce\Fulfilment\Tracking;
use Pixonaut\WooCommerce\Tests\Support\FulfilmentAnswers;
use Pixonaut\WooCommerce\Tests\Unit\TestCase;

/**
 * What the store keeps of an order Pixonaut answered, or hands on as an
 * event, has the shape it had when the answer was read as an array.
 */
final class OrderAnswerShapesTest extends TestCase {

	private const REF = 'wc:site-1:order:7:item:42';

	private const HOLD = array(
		'reason'    => 'monthly_order_limit',
		'limit'     => 25,
		'periodEnd' => '2027-01-01T00:00:00Z',
	);

	/**
	 * `periodEnd` as a Unix time.
	 */
	private const PERIOD_END = 1_798_761_600;

	private const RECIPIENT = array(
		'name'  => 'Ada Lovelace',
		'email' => 'ada@example.org',
	);

	private const ADDRESS = array(
		'company'    => 'Analytical Engines',
		'line1'      => 'Storgatan 1',
		'postalCode' => '111 22',
		'city'       => 'Stockholm',
		'country'    => 'SE',
	);

	private const SAVED_ADDRESS = array(
		'line1'   => 'Lagergatan 9',
		'city'    => 'Göteborg',
		'country' => 'SE',
	);

	/**
	 * When the change was answered, epoch milliseconds.
	 */
	private const CHANGED_AT = 1_800_000_000_500;

	private const MS_PER_SECOND = 1000;

	protected function setUp(): void {
		parent::setUp();
		Functions\when( 'wp_json_encode' )->alias( static fn ( $value ) => json_encode( $value ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode -- no WordPress in the unit suite.
		Functions\when( 'sanitize_text_field' )->alias( static fn ( string $text ): string => trim( $text ) );
		Functions\when( 'esc_url_raw' )->returnArg();
		Functions\when( 'wp_generate_uuid4' )->justReturn( '00000000-0000-4000-8000-000000000000' );
	}

	public function test_a_hold_is_stored_as_the_line_stored_it_from_the_answer_as_an_array(): void {
		$order = self::order( array( 'hold' => self::HOLD ) );

		$stored = OrderHold::of( $order->hold );

		$this->assertSame(
			array(
				'reason'    => 'monthly_order_limit',
				'limit'     => 25,
				'periodEnd' => self::PERIOD_END,
			),
			$stored
		);
		$this->assertSame( OrderHold::of( self::HOLD ), $stored );
		$this->assertSame( OrderHold::of( null ), OrderHold::of( self::order()->hold ) );
	}

	public function test_tracking_is_stored_and_handed_on_as_pixonaut_sent_it(): void {
		$tracking = array(
			'number'  => 'PN123456789SE',
			'carrier' => 'PostNord',
			'url'     => 'https://tracking.example/PN123456789SE',
		);
		$answered = self::order( array( 'tracking' => $tracking ) )->tracking;

		$this->assertSame( $tracking, Tracking::as_sent( $answered ) );
		$this->assertSame( Tracking::from_payload( $tracking ), Tracking::from_answer( $answered ) );
		$this->assertSame( array( 'number' => 'PN1' ), Tracking::as_sent( self::order( array( 'tracking' => array( 'number' => 'PN1' ) ) )->tracking ) );
		$this->assertNull( Tracking::from_answer( null ) );
	}

	public function test_the_event_of_a_move_the_store_made_carries_the_answer_as_its_payload(): void {
		$tracking = array(
			'number'  => 'PN1',
			'carrier' => 'PostNord',
		);
		$moved    = self::order(
			array(
				'status'   => 'shipped',
				'tracking' => $tracking,
			)
		);

		$event = OrderStatusChanged::store_move( 'o_1', 'printed', $moved );

		$this->assertSame( OrderStatusChanged::TYPE, $event->type );
		$this->assertSame( 'store_00000000-0000-4000-8000-000000000000', $event->id );
		$this->assertSame(
			array(
				'orderId'        => 'o_1',
				'externalRef'    => self::REF,
				'previousStatus' => 'printed',
				'status'         => 'shipped',
				'tracking'       => $tracking,
				'via'            => 'store_key',
			),
			$event->payload
		);
		$this->assertNull( OrderStatusChanged::store_move( 'o_1', '', self::order() )->payload['tracking'] );
	}

	public function test_an_adopted_orders_address_has_the_fingerprint_of_the_address_as_sent(): void {
		$order = self::order(
			array(
				'recipient'       => self::RECIPIENT,
				'shippingAddress' => self::ADDRESS,
			)
		);

		$this->assertSame(
			OrderAddress::fingerprint(
				array(
					'recipient'       => self::RECIPIENT,
					'shippingAddress' => self::ADDRESS,
				)
			),
			AdoptedOrder::address( $order )
		);
	}

	public function test_an_order_shipping_to_a_saved_address_has_the_fingerprint_of_that_address(): void {
		$order = self::order(
			array(
				'recipient'       => self::RECIPIENT,
				'shippingAddress' => self::ADDRESS,
				'shipTo'          => array(
					'savedAddressId' => 'sa_1',
					'name'           => 'Warehouse',
					'address'        => self::SAVED_ADDRESS,
				),
			)
		);

		$this->assertSame(
			OrderAddress::fingerprint(
				array(
					'recipient'       => self::RECIPIENT,
					'shippingAddress' => self::SAVED_ADDRESS,
				)
			),
			AdoptedOrder::address( $order )
		);
		$this->assertSame( OrderAddress::fingerprint( array() ), AdoptedOrder::address( self::order() ) );
	}

	public function test_a_delivery_change_keeps_what_the_printshop_made_of_it(): void {
		$order = self::order(
			array(
				'delivery' => array(
					'state' => DeliveryChange::REFUSED,
					'words' => ' Already packed. ',
					'at'    => self::CHANGED_AT,
				),
			)
		);

		$this->assertSame(
			array(
				DeliveryChange::FIELD_STATE    => DeliveryChange::REFUSED,
				DeliveryChange::FIELD_WORDS    => 'Already packed.',
				DeliveryChange::FIELD_AT       => self::CHANGED_AT,
				DeliveryChange::FIELD_REFUSED  => 'fingerprint',
				DeliveryChange::FIELD_AWAITING => null,
			),
			DeliveryChange::outcome( $order->delivery, 'fingerprint' )
		);
	}

	public function test_a_delivery_change_answered_without_a_delivery_is_still_being_sent(): void {
		$before  = time() * self::MS_PER_SECOND;
		$outcome = DeliveryChange::outcome( null, 'fingerprint' );

		$this->assertSame( DeliveryChange::SENDING, $outcome[ DeliveryChange::FIELD_STATE ] );
		$this->assertNull( $outcome[ DeliveryChange::FIELD_WORDS ] );
		$this->assertGreaterThanOrEqual( $before, $outcome[ DeliveryChange::FIELD_AT ] );
		$this->assertNull( $outcome[ DeliveryChange::FIELD_REFUSED ] );
		$this->assertSame( 'fingerprint', $outcome[ DeliveryChange::FIELD_AWAITING ] );
	}

	/**
	 * @param array<string, mixed> $overrides Fields to replace.
	 */
	private static function order( array $overrides = array() ): Order {
		return Order::from_answer( FulfilmentAnswers::order( 'o_1', array( 'p_front' ), self::REF, $overrides ) );
	}
}
