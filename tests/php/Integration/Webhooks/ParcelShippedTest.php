<?php
/**
 * @package Pixonaut\WooCommerce
 */

declare(strict_types=1);

namespace Pixonaut\WooCommerce\Tests\Integration\Webhooks;

use Pixonaut\WooCommerce\Emails\PartShippedEmail;
use Pixonaut\WooCommerce\Fulfilment\LineState;
use Pixonaut\WooCommerce\Fulfilment\Tracking;
use Pixonaut\WooCommerce\Schema;
use Pixonaut\WooCommerce\Tests\Support\SentMail;
use WC_Email;
use WC_Order;
use WC_Order_Item_Product;

/**
 * `parcel.shipped` ships the lines whose every copy is now in a parcel that
 * shipped, and tells the customer once per parcel while the order stays open;
 * `parcel.tracking_changed` tells them once per parcel when its tracking
 * comes or is corrected.
 */
final class ParcelShippedTest extends OrderEventsTestCase {

	private const PART_SHIPPED_SUBJECT = 'Part of your';

	private const TRACKING_SUBJECT = 'New tracking for your';

	private const COMPLETED_SUBJECT = 'complete';

	/**
	 * Lines of the nine-line order, by the parcel the printshop ships them in.
	 */
	private const PARCELS = array(
		'parcel-a' => array( 1, 2, 3, 4, 5 ),
		'parcel-b' => array( 6, 7, 8 ),
		'parcel-c' => array( 9 ),
	);

	private const PARCEL_TRACKING = array(
		'parcel-a' => array(
			'carrier' => 'PostNord',
			'number'  => 'PNAAA111SE',
			'url'     => 'https://tracking.example/PNAAA111SE',
		),
		'parcel-b' => array(
			'carrier' => 'DHL',
			'number'  => 'DHLBBB222',
		),
		'parcel-c' => array(
			'carrier' => 'UPS',
			'number'  => '1ZCCC333',
		),
	);

	/**
	 * Copies of the split line, and how many each of its two parcels holds.
	 */
	private const SPLIT_QUANTITY = 10;

	private const SPLIT_HALF = 5;

	private int $created = 0;

	private int $sequence = 0;

	public function set_up(): void {
		parent::set_up();
		// WooCommerce's mailer hooks its emails' triggers and the order table
		// once a request, when it is built. Built by an earlier test, its hooks
		// went with that test's; built now, they are there, and hooking them
		// again would send each email twice.
		$mailer = WC()->mailer();
		if ( ! has_action( 'woocommerce_order_status_completed_notification' ) ) {
			$mailer->init();
		}
		if ( ! has_action( 'woocommerce_email_order_details', array( $mailer, 'order_details' ) ) ) {
			add_action( 'woocommerce_email_order_details', array( $mailer, 'order_details' ), 10, 4 );
		}
		reset_phpmailer_instance();
		$this->created = time() * 1000;
	}

	/**
	 * @return array<string, array{list<string>}>
	 */
	public static function parcel_orders(): array {
		return array(
			'as made'        => array( array( 'parcel-a', 'parcel-b', 'parcel-c' ) ),
			'last one first' => array( array( 'parcel-c', 'parcel-a', 'parcel-b' ) ),
			'middle first'   => array( array( 'parcel-b', 'parcel-c', 'parcel-a' ) ),
		);
	}

	/**
	 * @dataProvider parcel_orders
	 *
	 * @param string[] $order The parcels, in the order their events arrive.
	 * @phpstan-param list<string> $order
	 */
	public function test_nine_lines_in_three_parcels_send_one_email_per_parcel_until_the_last_completes_the_order( array $order ): void {
		$lines = $this->nine_lines();

		foreach ( $order as $index => $parcel ) {
			$this->ship_parcel( $parcel, true, count( $order ) - 1 === $index ? 'shipped' : 'partly_shipped' );
		}

		$this->assertSame( 'completed', $this->order_of( $lines[1] )->get_status() );
		$mails = $this->mails( self::PART_SHIPPED_SUBJECT );
		$this->assertCount( 2, $mails );
		foreach ( array_slice( $order, 0, 2 ) as $index => $parcel ) {
			$this->assertSame( 'ada@example.com', $mails[ $index ]['to'] );
			$this->assertSame( self::PARCELS[ $parcel ], self::posters_in( $mails[ $index ]['body'] ), "The email for {$parcel} lists its lines." );
			$this->assertSame( 1, substr_count( $mails[ $index ]['body'], Tracking::text( self::PARCEL_TRACKING[ $parcel ] ) ), "The email for {$parcel} gives its tracking once." );
		}
		$completed = $this->mails( self::COMPLETED_SUBJECT );
		$this->assertCount( 1, $completed );
		$this->assertSame( range( 1, 9 ), self::posters_in( $completed[0]['body'] ) );
		foreach ( self::PARCEL_TRACKING as $tracking ) {
			$this->assertStringContainsString( $tracking['number'], $completed[0]['body'] );
		}
		foreach ( self::PARCELS as $parcel => $numbers ) {
			foreach ( $numbers as $number ) {
				$this->assertEquals( self::PARCEL_TRACKING[ $parcel ], Tracking::of( LineState::of( $lines[ $number ] ) ) );
			}
		}
	}

	public function test_a_parcel_reported_again_emails_once(): void {
		$lines = $this->nine_lines();

		$this->ship_parcel( 'parcel-a' );
		$this->ship_parcel( 'parcel-a' );

		$this->assertCount( 1, $this->mails( self::PART_SHIPPED_SUBJECT ) );
		$this->assertSame( 'processing', $this->order_of( $lines[1] )->get_status() );
		$this->assertSame( LineState::SHIPPED, LineState::of( $lines[5] )->state() );
		$this->assertNotSame( LineState::SHIPPED, LineState::of( $lines[6] )->state() );
	}

	public function test_a_line_split_over_two_parcels_ships_with_the_second_and_its_first_parcel_says_the_rest_comes_separately(): void {
		$item = $this->pixonaut_line( array( 'quantity' => self::SPLIT_QUANTITY ) );

		$this->deliver( $this->parcel_event( 'parcel.shipped', 'split-1', array( array( self::ITEM_ID, self::SPLIT_HALF, self::SPLIT_QUANTITY, false ) ), self::TRACKING ) );
		$this->run_jobs();

		$this->assertNotSame( LineState::SHIPPED, LineState::of( $item )->state() );
		$this->assertSame( 'processing', $this->order_of( $item )->get_status() );
		$this->assertStringContainsString( 'Pixonaut: 5 of 10 of "Personalised T-shirt" were shipped. The rest follow in another parcel. Tracking: PostNord PN123456789SE', $this->notes( $item ) );
		$mails = $this->mails( self::PART_SHIPPED_SUBJECT );
		$this->assertCount( 1, $mails );
		$this->assertStringContainsString( '× 5', $mails[0]['body'] );
		$this->assertStringContainsString( '5 of 10 in this parcel. The rest come in a separate parcel.', $mails[0]['body'] );

		// The two parcels' copies add up to the line's, whatever the order's status says.
		$this->deliver( $this->parcel_event( 'parcel.shipped', 'split-2', array( array( self::ITEM_ID, self::SPLIT_HALF, self::SPLIT_QUANTITY, false ) ), self::PARCEL_TRACKING['parcel-b'] ) );
		$this->run_jobs();

		$this->assertSame( LineState::SHIPPED, LineState::of( $item )->state() );
		$this->assertSame( 'completed', $this->order_of( $item )->get_status() );
		$this->assertCount( 1, $this->mails( self::PART_SHIPPED_SUBJECT ) );
	}

	public function test_a_line_split_over_parcels_without_counts_ships_when_the_order_reads_as_shipped(): void {
		$item = $this->pixonaut_line( array( 'quantity' => self::SPLIT_QUANTITY ) );

		$this->deliver( $this->parcel_event( 'parcel.shipped', 'part-1', array( array( self::ITEM_ID, null, self::SPLIT_QUANTITY, false ) ), null ) );
		$this->run_jobs();

		$this->assertNotSame( LineState::SHIPPED, LineState::of( $item )->state() );
		$this->assertStringContainsString( 'Pixonaut: part of "Personalised T-shirt" was shipped. The rest follow in another parcel.', $this->notes( $item ) );
		$mails = $this->mails( self::PART_SHIPPED_SUBJECT );
		$this->assertCount( 1, $mails );
		$this->assertStringNotContainsString( '× ', $mails[0]['body'] );
		$this->assertStringContainsString( 'Part of this item is in this parcel. The rest come in a separate parcel.', $mails[0]['body'] );

		$this->deliver( $this->parcel_event( 'parcel.shipped', 'part-2', array( array( self::ITEM_ID, null, self::SPLIT_QUANTITY, false ) ), self::TRACKING, 'shipped' ) );
		$this->run_jobs();

		$this->assertSame( LineState::SHIPPED, LineState::of( $item )->state() );
		$this->assertSame( 'completed', $this->order_of( $item )->get_status() );
		$this->assertCount( 1, $this->mails( self::PART_SHIPPED_SUBJECT ) );
	}

	public function test_a_delivered_parcel_is_noted_once(): void {
		$lines = $this->nine_lines();
		$this->ship_parcel( 'parcel-b' );
		$delivered = $this->parcel_event( 'parcel.delivered', 'parcel-b', $this->items_of( 'parcel-b' ), self::PARCEL_TRACKING['parcel-b'] );

		$this->deliver( $delivered );
		$this->run_jobs();
		$this->deliver( $this->parcel_event( 'parcel.delivered', 'parcel-b', $this->items_of( 'parcel-b' ), self::PARCEL_TRACKING['parcel-b'] ) );
		$this->run_jobs();

		$this->assertSame( 1, substr_count( $this->notes( $lines[6] ), 'Pixonaut: the parcel with "Poster 6", "Poster 7", "Poster 8" was delivered.' ) );
		$this->assertSame( 'processing', $this->order_of( $lines[6] )->get_status() );
	}

	public function test_the_last_parcel_of_a_split_line_says_the_rest_went_before(): void {
		$item = $this->pixonaut_line( array( 'quantity' => self::SPLIT_QUANTITY ) );
		$this->second_plain_line( $item );

		$this->deliver( $this->parcel_event( 'parcel.shipped', 'split-1', array( array( self::ITEM_ID, self::SPLIT_HALF, self::SPLIT_QUANTITY, false ) ), self::TRACKING ) );
		$this->run_jobs();
		$this->deliver( $this->parcel_event( 'parcel.shipped', 'split-2', array( array( self::ITEM_ID, self::SPLIT_HALF, self::SPLIT_QUANTITY, false ) ), self::PARCEL_TRACKING['parcel-b'] ) );
		$this->run_jobs();

		$this->assertSame( LineState::SHIPPED, LineState::of( $item )->state() );
		$mails = $this->mails( self::PART_SHIPPED_SUBJECT );
		$this->assertCount( 2, $mails );
		$this->assertStringContainsString( '5 of 10 in this parcel. The rest come in a separate parcel.', $mails[0]['body'] );
		$this->assertStringContainsString( '5 of 10 in this parcel. The rest were sent in a separate parcel.', $mails[1]['body'] );
		$this->assertSame( 1, substr_count( $this->notes( $item ), 'The rest follow in another parcel.' ) );
	}

	public function test_a_parcel_event_while_another_request_handles_the_order_is_retried(): void {
		$lines = $this->nine_lines();
		$lock  = Schema::PARCEL_LOCK_OPTION_PREFIX . $lines[1]->get_order_id();
		add_option( $lock, time(), '', false );

		$this->ship_parcel( 'parcel-a' );

		$this->assertSame( array(), $this->mails( self::PART_SHIPPED_SUBJECT ) );
		$this->assertNotSame( LineState::SHIPPED, LineState::of( $lines[1] )->state() );

		delete_option( $lock );
		$this->run_jobs();

		$this->assertCount( 1, $this->mails( self::PART_SHIPPED_SUBJECT ) );
		$this->assertSame( LineState::SHIPPED, LineState::of( $lines[1] )->state() );
		$this->assertFalse( get_option( $lock ) );
	}

	public function test_tracking_changed_back_before_an_older_change_arrives_keeps_the_newest(): void {
		$lines = $this->nine_lines();
		$this->ship_parcel( 'parcel-a' );
		$older = $this->parcel_event( 'parcel.tracking_changed', 'parcel-a', $this->items_of( 'parcel-a' ), self::PARCEL_TRACKING['parcel-b'] );
		$back  = $this->parcel_event( 'parcel.tracking_changed', 'parcel-a', $this->items_of( 'parcel-a' ), self::PARCEL_TRACKING['parcel-a'] );

		$this->deliver( $back );
		$this->run_jobs();
		$this->deliver( $older );
		$this->run_jobs();

		$this->assertSame( array(), $this->mails( self::TRACKING_SUBJECT ) );
		$this->assertEquals( self::PARCEL_TRACKING['parcel-a'], Tracking::of( LineState::of( $lines[1] ) ) );
	}

	public function test_a_single_line_order_shipped_whole_gets_no_part_shipped_email(): void {
		$item = $this->pixonaut_line( array( 'quantity' => 3 ) );
		add_filter( 'pixonaut_wc_fulfilment_complete_order', '__return_false' );

		$this->deliver( $this->parcel_event( 'parcel.shipped', 'whole', array( array( self::ITEM_ID, 3, 3, true ) ), self::TRACKING ) );
		$this->run_jobs();

		$this->assertSame( LineState::SHIPPED, LineState::of( $item )->state() );
		$this->assertSame( array(), $this->mails( self::PART_SHIPPED_SUBJECT ) );
	}

	public function test_a_filter_adds_to_the_part_shipped_email(): void {
		$lines = $this->nine_lines();
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

		$this->ship_parcel( 'parcel-a' );

		$mails = $this->mails( self::PART_SHIPPED_SUBJECT );
		$this->assertCount( 1, $mails );
		$this->assertStringContainsString( 'Thanks for your order', $mails[0]['body'] );
		$this->assertSame( 'processing', $this->order_of( $lines[1] )->get_status() );
	}

	public function test_the_part_shipped_email_can_be_turned_off(): void {
		update_option( 'woocommerce_' . PartShippedEmail::ID . '_settings', array( 'enabled' => 'no' ) );
		WC()->mailer()->init();
		$this->nine_lines();

		$this->ship_parcel( 'parcel-a' );

		$this->assertSame( array(), $this->mails( self::PART_SHIPPED_SUBJECT ) );
	}

	public function test_a_line_keeping_no_item_id_holds_the_orders_one_item(): void {
		$item = $this->pixonaut_line( array( 'item_id' => null ) );
		$this->second_plain_line( $item );

		$event = $this->parcel_event( 'parcel.shipped', 'alone', array( array( self::ITEM_ID, 1, 1, true ) ), self::TRACKING );
		// Its order was placed for the line alone, under the line's ref.
		$event['payload']['externalRef'] = self::EXTERNAL_REF;
		$this->deliver( $event );
		$this->run_jobs();

		$this->assertSame( LineState::SHIPPED, LineState::of( $item )->state() );
		$this->assertCount( 1, $this->mails( self::PART_SHIPPED_SUBJECT ) );
	}

	public function test_a_parcel_of_another_workspaces_line_changes_nothing(): void {
		$item = $this->pixonaut_line( array( 'tenant' => '0b1c2d3e-4f50-4a6b-8c7d-000000000099' ) );
		$this->second_plain_line( $item );

		$this->deliver( $this->parcel_event( 'parcel.shipped', 'foreign', array( array( self::ITEM_ID, 1, 1, true ) ), self::TRACKING ) );
		$this->run_jobs();

		$this->assertNotSame( LineState::SHIPPED, LineState::of( $item )->state() );
		$this->assertSame( array(), $this->mails( self::PART_SHIPPED_SUBJECT ) );
	}

	public function test_the_shipped_note_gives_the_parcels_tracking_and_the_items_own_event_adds_nothing(): void {
		$item = $this->pixonaut_line();

		$this->deliver( $this->parcel_event( 'parcel.shipped', 'whole', array( array( self::ITEM_ID, 1, 1, true ) ), self::TRACKING, 'shipped' ) );
		$this->run_jobs();
		$this->deliver( $this->status_event( 'printed', 'shipped' ) );
		$this->run_jobs();

		$this->assertSame( 1, substr_count( $this->notes( $item ), 'was shipped. Tracking: PostNord PN123456789SE' ) );
		$this->assertSame( 1, substr_count( $this->notes( $item ), 'PN123456789SE (' ) );
		$completed = $this->mails( self::COMPLETED_SUBJECT );
		$this->assertCount( 1, $completed );
		$this->assertStringContainsString( 'PN123456789SE', $completed[0]['body'] );
		$this->assertSame( array(), $this->mails( self::TRACKING_SUBJECT ) );
	}

	public function test_a_parcel_after_the_event_that_completed_the_order_tells_its_tracking_once(): void {
		$item = $this->pixonaut_line();
		$this->deliver( $this->status_event( 'printed', 'shipped' ) );
		$this->run_jobs();
		$this->assertSame( 'completed', $this->order_of( $item )->get_status() );
		$this->assertStringNotContainsString( 'PN123456789SE', $this->mails( self::COMPLETED_SUBJECT )[0]['body'] );

		$this->deliver( $this->parcel_event( 'parcel.shipped', 'late', array( array( self::ITEM_ID, 1, 1, true ) ), self::TRACKING, 'shipped' ) );
		$this->run_jobs();
		$this->deliver( $this->parcel_event( 'parcel.shipped', 'late', array( array( self::ITEM_ID, 1, 1, true ) ), self::TRACKING, 'shipped' ) );
		$this->run_jobs();

		$mails = $this->mails( self::TRACKING_SUBJECT );
		$this->assertCount( 1, $mails );
		$this->assertStringContainsString( 'PN123456789SE', $mails[0]['body'] );
		$this->assertSame( array(), $this->mails( self::PART_SHIPPED_SUBJECT ) );
		$this->assertSame( 1, substr_count( $this->notes( $item ), 'Pixonaut: tracking was added for the parcel with "Personalised T-shirt": PostNord PN123456789SE' ) );
		$this->assertEquals( self::TRACKING, Tracking::of( LineState::of( $item ) ) );
	}

	public function test_a_parcel_after_its_items_event_gives_its_tracking_in_the_part_shipped_email(): void {
		$item = $this->pixonaut_line();
		$this->second_plain_line( $item );
		$this->deliver( $this->status_event( 'printed', 'shipped' ) );
		$this->run_jobs();

		$this->deliver( $this->parcel_event( 'parcel.shipped', 'late', array( array( self::ITEM_ID, 1, 1, true ) ), self::TRACKING ) );
		$this->run_jobs();

		$mails = $this->mails( self::PART_SHIPPED_SUBJECT );
		$this->assertCount( 1, $mails );
		$this->assertStringContainsString( 'PN123456789SE', $mails[0]['body'] );
		$this->assertSame( array(), $this->mails( self::TRACKING_SUBJECT ) );
	}

	public function test_a_split_lines_last_parcel_gives_the_shipped_note_its_tracking(): void {
		$item  = $this->pixonaut_line( array( 'quantity' => self::SPLIT_QUANTITY ) );
		$items = array( array( self::ITEM_ID, self::SPLIT_HALF, self::SPLIT_QUANTITY, false ) );
		$this->deliver( $this->parcel_event( 'parcel.shipped', 'split-1', $items, null ) );
		$this->run_jobs();
		$this->deliver( $this->parcel_event( 'parcel.tracking_changed', 'split-1', $items, self::TRACKING ) );
		$this->run_jobs();

		$this->deliver( $this->parcel_event( 'parcel.shipped', 'split-2', $items, self::PARCEL_TRACKING['parcel-b'], 'shipped' ) );
		$this->run_jobs();

		$this->assertStringContainsString( 'was shipped. Tracking: DHL DHLBBB222', $this->notes( $item ) );
	}

	public function test_tracking_for_a_parcel_shipped_without_any_emails_its_lines_once(): void {
		$lines = $this->nine_lines();
		$this->ship_parcel( 'parcel-b', false );
		$this->assertStringNotContainsString( 'DHLBBB222', $this->mails( self::PART_SHIPPED_SUBJECT )[0]['body'] );

		$changed = $this->parcel_event( 'parcel.tracking_changed', 'parcel-b', $this->items_of( 'parcel-b' ), self::PARCEL_TRACKING['parcel-b'] );
		$this->deliver( $changed );
		$this->run_jobs();
		$this->deliver( $this->parcel_event( 'parcel.tracking_changed', 'parcel-b', $this->items_of( 'parcel-b' ), self::PARCEL_TRACKING['parcel-b'] ) );
		$this->run_jobs();

		$mails = $this->mails( self::TRACKING_SUBJECT );
		$this->assertCount( 1, $mails );
		$this->assertSame( array( 6, 7, 8 ), self::posters_in( $mails[0]['body'] ) );
		$this->assertSame( 1, substr_count( $mails[0]['body'], 'DHLBBB222' ) );
		$this->assertStringContainsString( 'Pixonaut: tracking was added for the parcel with "Poster 6", "Poster 7", "Poster 8": DHL DHLBBB222', $this->notes( $lines[6] ) );
		$this->assertEquals( self::PARCEL_TRACKING['parcel-b'], Tracking::of( LineState::of( $lines[7] ) ) );
	}

	public function test_corrected_tracking_is_noted_and_an_older_correction_changes_nothing(): void {
		$lines = $this->nine_lines();
		$this->ship_parcel( 'parcel-a' );
		$older = $this->parcel_event( 'parcel.tracking_changed', 'parcel-a', $this->items_of( 'parcel-a' ), self::PARCEL_TRACKING['parcel-c'] );
		$newer = $this->parcel_event( 'parcel.tracking_changed', 'parcel-a', $this->items_of( 'parcel-a' ), self::PARCEL_TRACKING['parcel-b'] );

		$this->deliver( $newer );
		$this->run_jobs();
		$this->deliver( $older );
		$this->run_jobs();

		$this->assertCount( 1, $this->mails( self::TRACKING_SUBJECT ) );
		$this->assertStringContainsString( 'was corrected to DHL DHLBBB222. It was PostNord PNAAA111SE.', $this->notes( $lines[1] ) );
		$this->assertEquals( self::PARCEL_TRACKING['parcel-b'], Tracking::of( LineState::of( $lines[1] ) ) );
	}

	public function test_tracking_before_its_parcel_shipped_tells_no_one_until_it_ships_with_it(): void {
		$lines = $this->nine_lines();

		$early = $this->parcel_event( 'parcel.tracking_changed', 'parcel-b', $this->items_of( 'parcel-b' ), self::PARCEL_TRACKING['parcel-b'] );
		$this->deliver( $early );
		$this->run_jobs();
		$this->assertSame( array(), $this->mails( self::TRACKING_SUBJECT ) );
		$this->assertSame( array(), $this->mails( self::PART_SHIPPED_SUBJECT ) );

		$this->ship_parcel( 'parcel-b', false );

		$this->assertSame( array(), $this->mails( self::TRACKING_SUBJECT ) );
		$mails = $this->mails( self::PART_SHIPPED_SUBJECT );
		$this->assertCount( 1, $mails );
		$this->assertStringContainsString( 'DHLBBB222', $mails[0]['body'] );
	}

	/**
	 * A processing order of nine Pixonaut lines, "Poster 1" to "Poster 9",
	 * all held by ORDER_ID, each its own item.
	 *
	 * @return array<int, WC_Order_Item_Product> By number.
	 */
	private function nine_lines(): array {
		$order = new WC_Order();
		$lines = array();
		foreach ( range( 1, 9 ) as $number ) {
			$item = $this->pixonaut_line(
				array(
					'order'        => $order,
					'item_id'      => self::item_of( $number ),
					'external_ref' => self::ref_of( $number ),
				)
			);
			$item->set_name( "Poster {$number}" );
			$item->save();
			$lines[ $number ] = $item;
		}
		return $lines;
	}

	/**
	 * Delivers and handles `parcel.shipped` for one of PARCELS.
	 *
	 * @param string $parcel        A key of PARCELS.
	 * @param bool   $with_tracking Whether it ships with its tracking.
	 * @param string $order_status  The order's status after it.
	 */
	private function ship_parcel( string $parcel, bool $with_tracking = true, string $order_status = 'partly_shipped' ): void {
		$this->deliver( $this->parcel_event( 'parcel.shipped', $parcel, $this->items_of( $parcel ), $with_tracking ? self::PARCEL_TRACKING[ $parcel ] : null, $order_status ) );
		$this->run_jobs();
	}

	/**
	 * The items of one of PARCELS, each whole.
	 *
	 * @param string $parcel A key of PARCELS.
	 * @return list<array{0: string, 1: int|null, 2: int, 3: bool}>
	 */
	private function items_of( string $parcel ): array {
		return array_map( static fn ( int $number ): array => array( self::item_of( $number ), 1, 1, true ), self::PARCELS[ $parcel ] );
	}

	/**
	 * A `parcel.*` envelope for a parcel of ORDER_ID, each created a
	 * millisecond after the last.
	 *
	 * @param string                                                $type         `parcel.shipped`, `parcel.tracking_changed` or `parcel.delivered`.
	 * @param string                                                $id           The parcel's id.
	 * @param array<array{0: string, 1: int|null, 2: int, 3: bool}> $items        Each item's id, copies in the parcel (null: not said), copies ordered, and whether the parcel holds them all.
	 * @param array<string, string>|null                            $tracking     The parcel's tracking.
	 * @param string                                                $order_status For `parcel.shipped`, the order's status after it.
	 * @return array{id: string, type: string, payload: array<string, mixed>, createdAt: int}
	 */
	private function parcel_event( string $type, string $id, array $items, ?array $tracking, string $order_status = 'partly_shipped' ): array {
		++$this->sequence;
		$created = ++$this->created;
		$parcel  = array(
			'id'               => $id,
			'printshopOrderId' => '3c2b1a09-8f7e-4d6c-9b5a-4f3e2d1c0b9a',
			'status'           => 'parcel.delivered' === $type ? 'delivered' : 'shipped',
			'tracking'         => $tracking,
			'shippedAt'        => gmdate( 'Y-m-d\TH:i:s\Z' ),
			'deliveredAt'      => 'parcel.delivered' === $type ? gmdate( 'Y-m-d\TH:i:s\Z' ) : null,
			'items'            => array_map(
				static fn ( array $item ): array => array(
					'itemId'      => $item[0],
					'externalRef' => null,
					'quantity'    => $item[1],
					'ofQuantity'  => $item[2],
					'whole'       => $item[3],
				),
				array_values( $items )
			),
		);
		$payload = array(
			'orderId'     => self::ORDER_ID,
			'externalRef' => self::ORDER_EXTERNAL_REF,
			'parcel'      => $parcel,
			'via'         => 'integration',
		);
		if ( 'parcel.shipped' === $type ) {
			$payload['orderStatus'] = $order_status;
		} elseif ( 'parcel.tracking_changed' === $type ) {
			$payload['previousTracking'] = null;
		}
		return array(
			'id'        => "{$created}-5c6d7e8f-9a0b-4c1d-8e2f-3a4b5c6d7e" . str_pad( (string) $this->sequence, 2, '0', STR_PAD_LEFT ),
			'type'      => $type,
			'payload'   => $payload,
			'createdAt' => $created,
		);
	}

	/**
	 * Adds a line the plugin does not fulfil to the order of $item.
	 *
	 * @param WC_Order_Item_Product $item A line of the order.
	 */
	private function second_plain_line( WC_Order_Item_Product $item ): void {
		$order = $this->order_of( $item );
		$plain = new WC_Order_Item_Product();
		$plain->set_name( 'Plain mug' );
		$order->add_item( $plain );
		$order->save();
	}

	private static function item_of( int $number ): string {
		return 'oi_' . $number;
	}

	private static function ref_of( int $number ): string {
		return 'wc_site_1042_' . $number;
	}

	/**
	 * The poster numbers an email names, in order.
	 *
	 * @param string $body The email's body.
	 * @return list<int>
	 */
	private static function posters_in( string $body ): array {
		preg_match_all( '/Poster (\d)/', $body, $matches );
		return array_values( array_unique( array_map( 'intval', $matches[1] ) ) );
	}

	/**
	 * @param string $subject What the subject starts with, or holds for the completed email.
	 * @return list<array{to: string, subject: string, body: string}>
	 */
	private function mails( string $subject ): array {
		return array_values(
			array_filter(
				SentMail::all(),
				static fn ( array $mail ): bool => self::COMPLETED_SUBJECT === $subject ? str_contains( $mail['subject'], $subject ) : str_starts_with( $mail['subject'], $subject )
			)
		);
	}
}
