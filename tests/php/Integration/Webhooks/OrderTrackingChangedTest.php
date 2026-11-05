<?php
/**
 * @package Pixonaut\WooCommerce
 */

declare(strict_types=1);

namespace Pixonaut\WooCommerce\Tests\Integration\Webhooks;

use Pixonaut\WooCommerce\Emails\TrackingChangedEmail;
use Pixonaut\WooCommerce\Fulfilment\LineDisplay;
use Pixonaut\WooCommerce\Fulfilment\LineState;
use Pixonaut\WooCommerce\Fulfilment\Tracking;
use Pixonaut\WooCommerce\Plugin;
use Pixonaut\WooCommerce\Tests\Support\SentMail;
use WC_Order_Item_Product;

/**
 * `order.tracking_added` and `order.tracking_changed` give a shipped line its
 * tracking as the printshop now has it, note it and email the customer once;
 * an event older than the line's tracking, or a repeat, changes nothing.
 */
final class OrderTrackingChangedTest extends OrderEventsTestCase {

	private const CORRECTED = array(
		'carrier' => 'PostNord',
		'number'  => 'PN987654321SE',
		'url'     => 'https://tracking.example/PN987654321SE',
	);

	private const SUBJECT = 'New tracking for your';

	/**
	 * When the last event of a test was created; each one a millisecond after it.
	 *
	 * @var int Epoch milliseconds.
	 */
	private int $created = 0;

	public function set_up(): void {
		parent::set_up();
		// WooCommerce's mailer hooks its emails' triggers once a request, when it is built.
		WC()->mailer()->init();
		reset_phpmailer_instance();
		$this->created = time() * 1000;
	}

	public function test_tracking_added_to_a_line_shipped_without_any_reaches_the_order_and_the_customer(): void {
		$item = $this->shipped_line( null );

		$this->deliver( $this->tracking_event( 'order.tracking_added', self::TRACKING ) );
		$this->run_jobs();

		$this->assertEquals( self::TRACKING, Tracking::of( LineState::of( $item ) ) );
		$this->assertStringContainsString( 'Pixonaut: tracking was added for "Personalised T-shirt": PostNord PN123456789SE (https://tracking.example/PN123456789SE)', $this->notes( $item ) );
		$this->assertStringContainsString( 'PostNord PN123456789SE', $this->displayed_tracking( $item ) );
		$mails = $this->tracking_mails();
		$this->assertCount( 1, $mails );
		$this->assertSame( 'ada@example.com', $mails[0]['to'] );
		$this->assertStringContainsString( 'Personalised T-shirt', $mails[0]['body'] );
		$this->assertStringContainsString( 'PostNord PN123456789SE', $mails[0]['body'] );
		$this->assertStringNotContainsString( 'Pixonaut', $mails[0]['body'] );
	}

	public function test_corrected_tracking_replaces_the_lines_and_is_noted_with_the_one_before(): void {
		$item = $this->shipped_line( self::TRACKING );

		$this->deliver( $this->tracking_event( 'order.tracking_changed', self::CORRECTED, self::TRACKING ) );
		$this->run_jobs();

		$this->assertEquals( self::CORRECTED, Tracking::of( LineState::of( $item ) ) );
		$this->assertStringContainsString( 'the tracking of "Personalised T-shirt" was corrected to PostNord PN987654321SE (https://tracking.example/PN987654321SE). It was PostNord PN123456789SE.', $this->notes( $item ) );
		$this->assertStringContainsString( 'PN987654321SE', $this->displayed_tracking( $item ) );
		$this->assertCount( 1, $this->tracking_mails() );
	}

	public function test_an_event_delivered_twice_changes_the_line_once(): void {
		$item  = $this->shipped_line( self::TRACKING );
		$event = $this->tracking_event( 'order.tracking_changed', self::CORRECTED, self::TRACKING );
		$again = array( 'id' => $event['id'] . 'b' ) + $event;

		$this->deliver( $event );
		$this->run_jobs();
		$this->deliver( $again );
		$this->run_jobs();

		$this->assertSame( 1, substr_count( $this->notes( $item ), 'was corrected' ) );
		$this->assertCount( 1, $this->tracking_mails() );
	}

	public function test_a_correction_delivered_before_an_older_one_is_not_undone(): void {
		$item  = $this->shipped_line( null );
		$added = $this->tracking_event( 'order.tracking_added', self::TRACKING );
		$fixed = $this->tracking_event( 'order.tracking_changed', self::CORRECTED, self::TRACKING );

		$this->deliver( $fixed );
		$this->run_jobs();
		$this->deliver( $added );
		$this->run_jobs();

		$this->assertEquals( self::CORRECTED, Tracking::of( LineState::of( $item ) ) );
		$this->assertCount( 1, $this->tracking_mails() );
	}

	public function test_tracking_set_back_by_a_later_correction_is_not_undone_by_the_one_between(): void {
		$item  = $this->shipped_line( self::TRACKING );
		$wrong = $this->tracking_event( 'order.tracking_changed', self::CORRECTED, self::TRACKING );
		$back  = $this->tracking_event( 'order.tracking_changed', self::TRACKING, self::CORRECTED );

		$this->deliver( $back );
		$this->run_jobs();
		$this->deliver( $wrong );
		$this->run_jobs();

		$this->assertEquals( self::TRACKING, Tracking::of( LineState::of( $item ) ) );
		$this->assertSame( array(), $this->tracking_mails() );
	}

	public function test_handling_the_event_again_emails_the_customer_a_run_that_stopped_did_not(): void {
		$item  = $this->shipped_line( null );
		$event = $this->tracking_event( 'order.tracking_added', self::TRACKING );
		// The run saved the line and noted it, then stopped before the email.
		$line = LineState::of( $item );
		Tracking::set( $line, Tracking::from_payload( self::TRACKING ) ?? array( 'number' => '' ), $event['createdAt'] );
		$line->set_ref( LineState::REF_TRACKING_NOTICE, true );
		$this->assertTrue( $line->save() );

		$this->deliver( $event );
		$this->run_jobs();
		$this->deliver( array( 'id' => $event['id'] . 'b' ) + $event );
		$this->run_jobs();

		$this->assertCount( 1, $this->tracking_mails() );
		$this->assertNull( LineState::of( $item )->ref( LineState::REF_TRACKING_NOTICE ) );
	}

	public function test_a_read_of_the_order_keeps_tracking_newer_than_its_own_and_takes_newer_tracking(): void {
		$item  = $this->pixonaut_line();
		$fixed = $this->tracking_event( 'order.tracking_changed', self::CORRECTED, self::TRACKING );
		$this->deliver( $fixed );
		$this->run_jobs();

		$this->apply_read( $item, self::TRACKING, $fixed['createdAt'] - 1 );

		$this->assertSame( LineState::SHIPPED, LineState::of( $item )->state() );
		$this->assertEquals( self::CORRECTED, Tracking::of( LineState::of( $item ) ) );

		$other          = $this->pixonaut_line();
		$late           = $this->tracking_event( 'order.tracking_added', self::TRACKING );
		$this->created += 1;
		$this->apply_read( $other, self::CORRECTED, $this->created );
		$this->deliver( $late );
		$this->run_jobs();

		$this->assertEquals( self::CORRECTED, Tracking::of( LineState::of( $other ) ) );
	}

	public function test_tracking_delivered_before_the_shipment_is_kept_for_it_and_tells_nobody_until_then(): void {
		$item    = $this->pixonaut_line();
		$shipped = $this->status_at( self::TRACKING );
		$fixed   = $this->tracking_event( 'order.tracking_changed', self::CORRECTED, self::TRACKING );

		$this->deliver( $fixed );
		$this->run_jobs();

		$this->assertEquals( self::CORRECTED, Tracking::of( LineState::of( $item ) ) );
		$this->assertSame( '', $this->displayed_tracking( $item ) );
		$this->assertStringNotContainsString( 'Pixonaut', $this->notes( $item ) );
		$this->assertSame( array(), $this->tracking_mails() );

		$this->deliver( $shipped );
		$this->run_jobs();

		$this->assertSame( LineState::SHIPPED, LineState::of( $item )->state() );
		$this->assertEquals( self::CORRECTED, Tracking::of( LineState::of( $item ) ) );
		$this->assertStringContainsString( 'was shipped. Tracking: PostNord PN987654321SE', $this->notes( $item ) );
		$this->assertSame( array(), $this->tracking_mails() );
	}

	public function test_lines_of_one_order_get_one_email(): void {
		$first  = $this->shipped_line( null );
		$second = $this->pixonaut_line( array( 'order' => $this->order_of( $first ) ) );
		$this->ship( $second, null );

		$this->deliver( $this->tracking_event( 'order.tracking_added', self::TRACKING ) );
		$this->run_jobs();

		$this->assertEquals( self::TRACKING, Tracking::of( LineState::of( $second ) ) );
		$this->assertCount( 1, $this->tracking_mails() );
	}

	public function test_the_email_can_be_turned_off(): void {
		update_option( 'woocommerce_' . TrackingChangedEmail::ID . '_settings', array( 'enabled' => 'no' ) );
		WC()->mailer()->init();
		$item = $this->shipped_line( null );

		$this->deliver( $this->tracking_event( 'order.tracking_added', self::TRACKING ) );
		$this->run_jobs();

		$this->assertEquals( self::TRACKING, Tracking::of( LineState::of( $item ) ) );
		$this->assertSame( array(), $this->tracking_mails() );
	}

	/**
	 * A line shipped at ORDER_ID, with or without tracking, its emails sent.
	 *
	 * @param array<string, string>|null $tracking The shipment's tracking.
	 */
	private function shipped_line( ?array $tracking ): WC_Order_Item_Product {
		$item = $this->pixonaut_line();
		$this->ship( $item, $tracking );
		return $item;
	}

	/**
	 * Ships ORDER_ID, then forgets the mail it sent.
	 *
	 * @param WC_Order_Item_Product      $item     A line holding ORDER_ID.
	 * @param array<string, string>|null $tracking The shipment's tracking.
	 */
	private function ship( WC_Order_Item_Product $item, ?array $tracking ): void {
		$this->deliver( $this->status_at( $tracking ) );
		$this->run_jobs();
		$this->assertSame( LineState::SHIPPED, LineState::of( $item )->state() );
		reset_phpmailer_instance();
	}

	/**
	 * A move of ORDER_ID to `shipped`, created now.
	 *
	 * @param array<string, string>|null $tracking The shipment's tracking.
	 * @return array{id: string, type: string, payload: array<string, mixed>, createdAt: int}
	 */
	private function status_at( ?array $tracking ): array {
		$event              = $this->status_event( 'printed', 'shipped', $tracking );
		$event['createdAt'] = ++$this->created;
		return $event;
	}

	/**
	 * An `order.tracking_added` or `order.tracking_changed` envelope for ORDER_ID, created now.
	 *
	 * @param string                     $type     The event type.
	 * @param array<string, string>      $tracking The order's tracking.
	 * @param array<string, string>|null $previous Its tracking before, for `order.tracking_changed`.
	 * @return array{id: string, type: string, payload: array<string, mixed>, createdAt: int}
	 */
	private function tracking_event( string $type, array $tracking, ?array $previous = null ): array {
		$created = ++$this->created;
		$payload = array(
			'orderId'     => self::ORDER_ID,
			'printId'     => self::PRINT_ID,
			'printIds'    => array( self::PRINT_ID ),
			'externalRef' => self::EXTERNAL_REF,
			'tracking'    => $tracking,
			'via'         => 'link',
		);
		if ( null !== $previous ) {
			$payload['previousTracking'] = $previous;
		}
		return array(
			'id'        => "{$created}-7a9b1c3d-5e6f-4a8b-9c2d-4e6f8a0b2c3d",
			'type'      => $type,
			'payload'   => $payload,
			'createdAt' => $created,
		);
	}

	/**
	 * Applies a read of ORDER_ID answering it shipped, as the order screen does.
	 *
	 * @param WC_Order_Item_Product $item     A line holding ORDER_ID, not shipped yet.
	 * @param array<string, string> $tracking The order's tracking.
	 * @param int                   $set_at   When its history says the tracking was last set, epoch milliseconds.
	 */
	private function apply_read( WC_Order_Item_Product $item, array $tracking, int $set_at ): void {
		$order = array(
			'status'      => 'shipped',
			'externalRef' => self::EXTERNAL_REF,
			'tracking'    => $tracking,
			'history'     => array(
				array(
					'type'   => 'order.status_changed',
					'status' => 'printed',
					'at'     => $set_at - 1,
				),
				array(
					'type'   => 'order.tracking_changed',
					'status' => 'shipped',
					'at'     => $set_at,
				),
			),
		);
		Plugin::boot( dirname( __DIR__, 4 ) . '/pixonaut-for-woocommerce.php' )->order_status_changed()->apply_read( LineState::of( $item ), $order );
	}

	/**
	 * The tracking row the customer sees under the line; '' when there is none.
	 *
	 * @param WC_Order_Item_Product $item The line.
	 */
	private function displayed_tracking( WC_Order_Item_Product $item ): string {
		$rows = ( new WC_Order_Item_Product( $item->get_id() ) )->get_formatted_meta_data();
		return isset( $rows[ LineDisplay::TRACKING_ROW ] ) ? (string) $rows[ LineDisplay::TRACKING_ROW ]->display_value : '';
	}

	/**
	 * @return list<array{to: string, subject: string, body: string}>
	 */
	private function tracking_mails(): array {
		return array_values( array_filter( SentMail::all(), static fn ( array $mail ): bool => str_starts_with( $mail['subject'], self::SUBJECT ) ) );
	}
}
