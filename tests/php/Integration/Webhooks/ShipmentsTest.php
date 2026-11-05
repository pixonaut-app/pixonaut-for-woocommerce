<?php
/**
 * @package Pixonaut\WooCommerce
 */

declare(strict_types=1);

namespace Pixonaut\WooCommerce\Tests\Integration\Webhooks;

use Pixonaut\WooCommerce\Emails\PartShippedEmail;
use Pixonaut\WooCommerce\Fulfilment\LineDisplay;
use Pixonaut\WooCommerce\Fulfilment\LineState;
use Pixonaut\WooCommerce\Labels;
use Pixonaut\WooCommerce\Tests\Support\AdminItemMeta;
use Pixonaut\WooCommerce\Tests\Support\SentMail;
use RuntimeException;
use WC_Email;
use WC_Order;
use WC_Order_Item_Product;

final class ShipmentsTest extends OrderEventsTestCase {

	/**
	 * The second Pixonaut order of an order with two Pixonaut lines.
	 */
	private const OTHER_ORDER_ID = 'f2a3b4c5-d6e7-4f8a-9b0c-1d2e3f4a5b6c';

	private const OTHER_EXTERNAL_REF = 'wc_site_1042_8';

	private const OTHER_TRACKING = array(
		'carrier' => 'DHL',
		'number'  => 'DHL987654321',
	);

	private const PART_SHIPPED_SUBJECT = 'Part of your';

	private const MIXED_NOTE = 'All personalised items shipped';

	public function set_up(): void {
		parent::set_up();
		// WooCommerce's mailer hooks its emails' triggers and the order table
		// once a request, when it is built; the hooks a previous test left are gone.
		WC()->mailer()->init();
		add_action( 'woocommerce_email_order_details', array( WC()->mailer(), 'order_details' ), 10, 4 );
		reset_phpmailer_instance();
	}

	public function test_a_mixed_order_is_never_completed_and_is_noted_once_its_personalised_items_shipped(): void {
		$item  = $this->pixonaut_line();
		$order = $this->order_of( $item );
		$this->add_plain_line( $order );

		$this->ship( self::TRACKING );

		$this->assertSame( LineState::SHIPPED, LineState::of( $item )->state() );
		$this->assertSame( 'processing', $this->order_of( $item )->get_status() );
		$this->assertStringContainsString( self::MIXED_NOTE, $this->notes( $item ) );
	}

	public function test_a_mixed_order_is_noted_only_once_its_last_personalised_item_ships(): void {
		$first = $this->pixonaut_line();
		$order = $this->order_of( $first );
		$this->add_plain_line( $order );
		$this->second_line( $order );

		$this->ship( self::TRACKING );
		$this->assertStringNotContainsString( self::MIXED_NOTE, $this->notes( $first ) );

		$this->ship_second();

		$this->assertSame( 1, substr_count( $this->notes( $first ), self::MIXED_NOTE ) );
		$this->assertSame( 'processing', $this->order_of( $first )->get_status() );
	}

	public function test_each_shipped_line_of_a_mixed_order_emails_the_customer_with_its_tracking(): void {
		$item = $this->pixonaut_line();
		$this->add_plain_line( $this->order_of( $item ) );

		$this->ship( self::TRACKING );

		$mails = $this->part_shipped_mails();
		$this->assertCount( 1, $mails );
		$this->assertSame( 'ada@example.com', $mails[0]['to'] );
		$this->assertStringContainsString( 'Personalised T-shirt', $mails[0]['body'] );
		$this->assertStringContainsString( 'PostNord PN123456789SE', $mails[0]['body'] );
		$this->assertStringContainsString( 'https://tracking.example/PN123456789SE', $mails[0]['body'] );
	}

	public function test_a_filter_adds_to_the_part_shipped_email(): void {
		$item = $this->pixonaut_line();
		$this->add_plain_line( $this->order_of( $item ) );
		add_filter(
			'pixonaut_wc_emails_template_args',
			static function ( array $args, WC_Email $email, bool $plain_text ): array {
				if ( PartShippedEmail::ID === $email->id ) {
					$args['additional_content'] = $plain_text ? 'Plain thanks' : 'Thanks for your order';
				}
				return $args;
			},
			10,
			3
		);

		$this->ship( self::TRACKING );

		$mails = $this->part_shipped_mails();
		$this->assertCount( 1, $mails );
		$this->assertStringContainsString( 'Thanks for your order', $mails[0]['body'] );
	}

	public function test_a_single_line_order_gets_no_part_shipped_email(): void {
		$item = $this->pixonaut_line( array( 'quantity' => 3 ) );
		add_filter( 'pixonaut_wc_fulfilment_complete_order', '__return_false' );

		$this->ship( self::TRACKING );

		$this->assertSame( array(), $this->part_shipped_mails() );
		$this->assertSame( 'processing', $this->order_of( $item )->get_status() );
	}

	public function test_lines_shipped_at_different_times_email_until_the_last_completes_the_order(): void {
		$first  = $this->pixonaut_line();
		$second = $this->second_line( $this->order_of( $first ) );

		$this->ship( self::TRACKING );
		$this->assertSame( 'processing', $this->order_of( $first )->get_status() );
		$this->assertCount( 1, $this->part_shipped_mails() );

		$this->ship_second();

		$this->assertSame( LineState::SHIPPED, LineState::of( $second )->state() );
		$this->assertSame( 'completed', $this->order_of( $first )->get_status() );
		$this->assertCount( 1, $this->part_shipped_mails() );
		$this->assertStringNotContainsString( self::MIXED_NOTE, $this->notes( $first ) );
	}

	public function test_the_completed_email_carries_the_tracking_and_no_line_state(): void {
		$this->pixonaut_line();

		$this->ship( self::TRACKING );

		$completed = array_values( array_filter( SentMail::all(), static fn ( array $mail ): bool => str_contains( $mail['subject'], 'complete' ) ) );
		$this->assertCount( 1, $completed );
		$this->assertStringContainsString( 'PostNord PN123456789SE', $completed[0]['body'] );
		$this->assertStringNotContainsString( 'Status:', $completed[0]['body'] );
		$this->assertStringNotContainsString( 'Shipped', $completed[0]['body'] );
	}

	public function test_a_shipped_event_delivered_again_emails_once(): void {
		$item = $this->pixonaut_line();
		$this->add_plain_line( $this->order_of( $item ) );
		$event = $this->status_event( 'printed', 'shipped', self::TRACKING );

		$this->deliver( $event );
		$this->run_jobs();
		$this->deliver( $event );
		$this->run_jobs();

		$this->assertCount( 1, $this->part_shipped_mails() );
		$this->assertSame( 1, substr_count( $this->notes( $item ), self::MIXED_NOTE ) );
	}

	public function test_a_shipped_event_tried_again_notes_and_emails_once(): void {
		$item = $this->pixonaut_line();
		$this->add_plain_line( $this->order_of( $item ) );
		$failures = 1;
		add_filter(
			'woocommerce_new_order_note_data',
			static function ( array $note ) use ( &$failures ): array {
				if ( str_contains( (string) $note['comment_content'], self::MIXED_NOTE ) && $failures-- > 0 ) {
					throw new RuntimeException( 'Not now.' );
				}
				return $note;
			}
		);

		$this->ship( self::TRACKING );
		$this->assertStringNotContainsString( self::MIXED_NOTE, $this->notes( $item ) );
		$this->assertSame( array(), $this->part_shipped_mails() );

		$this->run_jobs();

		$this->assertSame( 1, substr_count( $this->notes( $item ), self::MIXED_NOTE ) );
		$this->assertCount( 1, $this->part_shipped_mails() );
		$this->assertTrue( LineState::of( $item )->ref( LineState::REF_SHIPMENT_HANDLED ) );
	}

	public function test_a_shipped_line_reported_shipped_again_emails_once(): void {
		$item = $this->pixonaut_line();
		$this->add_plain_line( $this->order_of( $item ) );

		$this->ship( self::TRACKING );
		$this->ship( self::TRACKING );

		$this->assertCount( 1, $this->part_shipped_mails() );
		$this->assertSame( 1, substr_count( $this->notes( $item ), self::MIXED_NOTE ) );
	}

	public function test_a_mixed_order_is_not_completed_even_when_the_filter_says_so(): void {
		$item = $this->pixonaut_line();
		$this->add_plain_line( $this->order_of( $item ) );
		add_filter( 'pixonaut_wc_fulfilment_complete_order', '__return_true' );

		$this->ship( self::TRACKING );

		$this->assertSame( 'processing', $this->order_of( $item )->get_status() );
	}

	public function test_a_shipment_to_an_order_the_store_completed_tells_no_one(): void {
		$item = $this->pixonaut_line();
		$this->add_plain_line( $this->order_of( $item ) );
		$this->order_of( $item )->update_status( 'completed' );

		$this->ship( self::TRACKING );

		$this->assertSame( LineState::SHIPPED, LineState::of( $item )->state() );
		$this->assertSame( array(), $this->part_shipped_mails() );
		$this->assertStringNotContainsString( self::MIXED_NOTE, $this->notes( $item ) );
	}

	public function test_the_part_shipped_email_can_be_turned_off(): void {
		update_option( 'woocommerce_' . PartShippedEmail::ID . '_settings', array( 'enabled' => 'no' ) );
		WC()->mailer()->init();
		$item = $this->pixonaut_line();
		$this->add_plain_line( $this->order_of( $item ) );

		$this->ship( self::TRACKING );

		$this->assertSame( array(), $this->part_shipped_mails() );
	}

	public function test_a_line_shows_the_customer_its_tracking_once_shipped_and_no_state(): void {
		$item = $this->pixonaut_line();

		$this->assertSame( array(), $this->displayed( $item ) );

		$this->ship( self::TRACKING );

		$rows = $this->displayed( $item );
		$this->assertSame( array( LineDisplay::TRACKING_ROW ), array_keys( $rows ) );
		$this->assertSame( 'Tracking', $rows[ LineDisplay::TRACKING_ROW ]->display_key );
		$this->assertStringContainsString( 'PostNord PN123456789SE', $rows[ LineDisplay::TRACKING_ROW ]->display_value );
		$this->assertStringContainsString( '<a href="https://tracking.example/PN123456789SE"', $rows[ LineDisplay::TRACKING_ROW ]->display_value );
	}

	public function test_the_admin_order_screen_shows_a_lines_state_and_once_shipped_its_tracking(): void {
		$item = $this->pixonaut_line();

		$this->assertStringContainsString( '<th>Status:</th>', AdminItemMeta::html( $item ) );
		$this->assertStringContainsString( '<td>Design approved</td>', AdminItemMeta::html( $item ) );

		$this->ship( self::TRACKING );

		$admin = AdminItemMeta::html( new WC_Order_Item_Product( $item->get_id() ) );
		$this->assertStringContainsString( '<td>Shipped</td>', $admin );
		$this->assertStringContainsString( 'PostNord PN123456789SE', $admin );
	}

	public function test_a_filter_changes_a_lines_displayed_rows(): void {
		$item = $this->pixonaut_line();
		$this->ship( self::TRACKING );
		add_filter(
			'pixonaut_wc_fulfilment_line_display',
			static function ( array $rows, WC_Order_Item_Product $line_item, LineState $line ) use ( $item ): array {
				if ( $line_item->get_id() === $item->get_id() && LineState::SHIPPED === $line->state() ) {
					unset( $rows[ LineDisplay::TRACKING_ROW ] );
				}
				return $rows;
			},
			10,
			3
		);

		$this->assertArrayNotHasKey( LineDisplay::TRACKING_ROW, $this->displayed( $item ) );
	}

	public function test_the_displayed_texts_come_from_label_keys(): void {
		$item = $this->pixonaut_line();
		$this->ship( self::TRACKING );
		add_filter(
			'pixonaut_wc_storefront_label',
			static function ( string $text, string $key ): string {
				return Labels::line_state_key( LineState::SHIPPED ) === $key ? 'On its way' : $text;
			},
			10,
			2
		);

		$this->assertStringContainsString( '<td>On its way</td>', AdminItemMeta::html( new WC_Order_Item_Product( $item->get_id() ) ) );
	}

	public function test_a_line_not_from_pixonaut_shows_nothing_more(): void {
		$order = new WC_Order();
		$plain = $this->add_plain_line( $order );

		$this->assertSame( array(), $plain->get_formatted_meta_data() );
	}

	/**
	 * Ships ORDER_ID, as the printshop reports it.
	 *
	 * @param array<string, string> $tracking The tracking.
	 */
	private function ship( array $tracking ): void {
		$this->deliver( $this->status_event( 'printed', 'shipped', $tracking ) );
		$this->run_jobs();
	}

	/**
	 * Ships OTHER_ORDER_ID.
	 */
	private function ship_second(): void {
		$this->deliver(
			$this->status_event(
				'printed',
				'shipped',
				self::OTHER_TRACKING,
				array(
					'orderId'     => self::OTHER_ORDER_ID,
					'externalRef' => self::OTHER_EXTERNAL_REF,
				)
			)
		);
		$this->run_jobs();
	}

	/**
	 * Adds a Pixonaut line ordered at OTHER_ORDER_ID.
	 *
	 * @param WC_Order $order The order.
	 */
	private function second_line( WC_Order $order ): WC_Order_Item_Product {
		return $this->pixonaut_line(
			array(
				'order'        => $order,
				'order_id'     => self::OTHER_ORDER_ID,
				'external_ref' => self::OTHER_EXTERNAL_REF,
			)
		);
	}

	/**
	 * Adds a line the plugin does not fulfil.
	 *
	 * @param WC_Order $order The order.
	 */
	private function add_plain_line( WC_Order $order ): WC_Order_Item_Product {
		$plain = new WC_Order_Item_Product();
		$plain->set_name( 'Plain mug' );
		$order->add_item( $plain );
		$order->save();
		return $plain;
	}

	/**
	 * The line's displayed meta, as read afresh.
	 *
	 * @param WC_Order_Item_Product $item The line.
	 * @return array<string|int, \stdClass>
	 */
	private function displayed( WC_Order_Item_Product $item ): array {
		$fresh = new WC_Order_Item_Product( $item->get_id() );
		return $fresh->get_formatted_meta_data();
	}

	/**
	 * @return list<array{to: string, subject: string, body: string}>
	 */
	private function part_shipped_mails(): array {
		return array_values( array_filter( SentMail::all(), static fn ( array $mail ): bool => str_starts_with( $mail['subject'], self::PART_SHIPPED_SUBJECT ) ) );
	}
}
