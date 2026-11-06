<?php
/**
 * @package Pixonaut\WooCommerce
 */

declare(strict_types=1);

namespace Pixonaut\WooCommerce\Tests\Integration\Webhooks;

use Closure;
use Pixonaut\WooCommerce\Admin\Notices;
use Pixonaut\WooCommerce\Connection\Cipher;
use Pixonaut\WooCommerce\Connection\Connection;
use Pixonaut\WooCommerce\Connection\ConnectionStore;
use Pixonaut\WooCommerce\Emails\NeedsAttentionEmail;
use Pixonaut\WooCommerce\Fulfilment\AttentionAlerts;
use Pixonaut\WooCommerce\Fulfilment\LineState;
use Pixonaut\WooCommerce\Fulfilment\NeedsAttention;
use Pixonaut\WooCommerce\Milliseconds;
use Pixonaut\WooCommerce\Plugin;
use Pixonaut\WooCommerce\Tests\Support\SentMail;
use Pixonaut\WooCommerce\Vendor\Pixonaut\Sdk\Webhooks\Signature;
use Pixonaut\WooCommerce\Webhooks\EventStore;
use Pixonaut\WooCommerce\Webhooks\Processor;
use RuntimeException;
use WC_Order_Item_Product;

final class WebhookTest extends OrderEventsTestCase {

	public function test_a_bad_signature_is_refused_and_nothing_is_kept(): void {
		$envelope = $this->status_event( 'submitted', 'queued' );

		$response = $this->deliver( $envelope, null, self::SECRET . '_other' );

		$this->assertSame( 401, $response->get_status() );
		$this->assertNull( ( new EventStore() )->body( $envelope['id'] ) );
		$this->assertSame( array(), $this->pending_jobs() );
	}

	public function test_a_stale_timestamp_is_refused(): void {
		$response = $this->deliver( $this->status_event( 'submitted', 'queued' ), time() - Signature::TOLERANCE_SECONDS - 1 );

		$this->assertSame( 401, $response->get_status() );
	}

	public function test_nothing_is_accepted_while_the_store_is_not_connected(): void {
		( new ConnectionStore( Cipher::for_site() ) )->clear();

		$this->assertSame( 401, $this->deliver( $this->status_event( 'submitted', 'queued' ) )->get_status() );
	}

	public function test_a_copied_site_has_pixonaut_deliver_again_later(): void {
		$envelope = $this->status_event( 'printed', 'shipped', self::TRACKING );
		$this->copy_site();

		$response = $this->deliver( $envelope );

		$this->assertSame( 503, $response->get_status() );
		$this->assertSame( Connection::PAUSED_SITE_CHANGED, $response->get_data()['result'] );
		$this->assertNull( ( new EventStore() )->body( $envelope['id'] ) );
		$this->assertSame( array(), $this->pending_jobs() );
	}

	public function test_a_kept_event_waits_while_the_site_is_a_copy(): void {
		$item     = $this->pixonaut_line();
		$envelope = $this->status_event( 'printed', 'shipped', self::TRACKING );
		$this->deliver( $envelope );
		$copied = $this->copy_site();

		$this->run_jobs();

		$this->assertSame( 'processing', $this->order_of( $item )->get_status(), 'a copy never completes an order' );
		$this->assertFalse( ( new EventStore() )->processed( $envelope['id'] ) );
		$this->assertSame( 0, $this->attempts( $envelope['id'] ), 'waiting is no attempt' );

		remove_filter( 'home_url', $copied );
		$this->age_events( Processor::STRANDED_AFTER_SECONDS + 1 );
		do_action( Processor::MAINTENANCE_HOOK );
		$this->run_jobs();

		$this->assertTrue( ( new EventStore() )->processed( $envelope['id'] ) );
		$this->assertSame( 'completed', $this->order_of( $item )->get_status() );
	}

	public function test_a_body_that_is_no_event_is_refused(): void {
		$response = $this->deliver_raw( '{"id":"evt_1"}' );

		$this->assertSame( 400, $response->get_status() );
	}

	public function test_a_ping_is_acknowledged_without_a_job(): void {
		$response = $this->deliver(
			array(
				'id'        => 'ping_0a1b2c3d4e5f6a7b8c9d0e1f',
				'type'      => 'webhook.ping',
				'payload'   => array( 'endpointId' => 'wh_1' ),
				'createdAt' => time() * 1000,
			)
		);

		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( array(), $this->pending_jobs() );
	}

	public function test_an_event_is_answered_at_once_and_handled_in_a_job(): void {
		$item = $this->pixonaut_line();

		$response = $this->deliver( $this->status_event( 'submitted', 'queued' ) );

		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( LineState::APPROVED, LineState::of( $item )->state(), 'not handled before the job runs' );
		$this->assertCount( 1, $this->pending_jobs() );

		$this->run_jobs();

		$this->assertSame( LineState::ORDERED, LineState::of( $item )->state() );
		$this->assertSame( 'queued', LineState::of( $item )->ref( LineState::REF_ORDER_STATUS ) );
	}

	public function test_a_duplicate_delivery_is_processed_once(): void {
		$this->pixonaut_line();
		$fired = 0;
		add_action(
			'pixonaut_wc_webhook_order_status_changed',
			static function () use ( &$fired ): void {
				++$fired;
			}
		);
		$envelope = $this->status_event( 'printed', 'shipped' );

		$first  = $this->deliver( $envelope );
		$second = $this->deliver( $envelope );
		$this->run_jobs();
		$third = $this->deliver( $envelope );
		$this->run_jobs();

		$this->assertSame( array( 200, 200, 200 ), array( $first->get_status(), $second->get_status(), $third->get_status() ) );
		$this->assertSame( array( 'received', 'duplicate', 'duplicate' ), array( $first->get_data()['result'], $second->get_data()['result'], $third->get_data()['result'] ) );
		$this->assertSame( 1, $fired );
	}

	/**
	 * @return iterable<string, array{list<int>}>
	 */
	public static function orders_of_delivery(): iterable {
		yield 'in order' => array( array( 0, 1, 2 ) );
		yield 'reversed' => array( array( 2, 1, 0 ) );
		yield 'shipped first' => array( array( 2, 0, 1 ) );
		yield 'printed last' => array( array( 0, 2, 1 ) );
	}

	/**
	 * @dataProvider orders_of_delivery
	 *
	 * @param int[] $order Indexes into JOURNEY, in delivery order.
	 * @phpstan-param list<int> $order
	 */
	public function test_shuffled_and_duplicate_deliveries_end_in_the_same_line_state( array $order ): void {
		$item   = $this->pixonaut_line( array( 'quantity' => 2 ) );
		$events = array();
		foreach ( self::JOURNEY as $index => $change ) {
			$events[ $index ] = $this->status_event( $change[0], $change[1], 'shipped' === $change[1] ? self::TRACKING : null );
		}

		foreach ( $order as $index ) {
			$this->deliver( $events[ $index ] );
			$this->run_jobs();
			$this->deliver( $events[ $index ] );
			$this->run_jobs();
		}

		$line = LineState::of( $item );
		$this->assertSame( LineState::SHIPPED, $line->state() );
		$this->assertSame( 'shipped', $line->ref( LineState::REF_ORDER_STATUS ) );
		$this->assertEquals( self::TRACKING, $line->ref( LineState::REF_TRACKING ) );
	}

	public function test_a_late_change_never_moves_a_line_back(): void {
		$item = $this->pixonaut_line();
		$this->deliver( $this->status_event( 'printed', 'shipped' ) );
		$this->run_jobs();

		$this->deliver( $this->status_event( 'submitted', 'queued' ) );
		$this->run_jobs();

		$this->assertSame( LineState::SHIPPED, LineState::of( $item )->state() );
		$this->assertSame( 'shipped', LineState::of( $item )->ref( LineState::REF_ORDER_STATUS ) );
	}

	public function test_printed_keeps_the_line_ordered_with_a_note(): void {
		$item = $this->pixonaut_line();

		$this->deliver( $this->status_event( 'queued', 'printed' ) );
		$this->run_jobs();

		$this->assertSame( LineState::ORDERED, LineState::of( $item )->state() );
		$this->assertStringContainsString( 'was printed', $this->notes( $item ) );
	}

	public function test_shipped_notes_the_tracking_and_completes_a_single_line_order(): void {
		$item = $this->pixonaut_line();

		$this->deliver( $this->status_event( 'printed', 'shipped', self::TRACKING ) );
		$this->run_jobs();

		$this->assertStringContainsString( 'PostNord PN123456789SE', $this->notes( $item ) );
		$this->assertSame( 'completed', $this->order_of( $item )->get_status() );
	}

	public function test_shipped_without_tracking_still_ships(): void {
		$item = $this->pixonaut_line();

		$this->deliver( $this->status_event( 'printed', 'shipped' ) );
		$this->run_jobs();

		$this->assertSame( LineState::SHIPPED, LineState::of( $item )->state() );
		$this->assertNull( LineState::of( $item )->ref( LineState::REF_TRACKING ) );
	}

	public function test_an_order_with_a_line_not_from_pixonaut_is_not_completed(): void {
		$item  = $this->pixonaut_line();
		$order = $this->order_of( $item );
		$plain = new WC_Order_Item_Product();
		$plain->set_name( 'Plain mug' );
		$order->add_item( $plain );
		$order->save();

		$this->deliver( $this->status_event( 'printed', 'shipped' ) );
		$this->run_jobs();

		$this->assertSame( LineState::SHIPPED, LineState::of( $item )->state() );
		$this->assertSame( 'processing', $this->order_of( $item )->get_status() );
	}

	public function test_a_filter_can_keep_the_order_open(): void {
		$item = $this->pixonaut_line();
		add_filter( 'pixonaut_wc_fulfilment_complete_order', '__return_false' );

		$this->deliver( $this->status_event( 'printed', 'shipped' ) );
		$this->run_jobs();

		$this->assertSame( 'processing', $this->order_of( $item )->get_status() );
	}

	public function test_the_status_map_is_filterable(): void {
		$item = $this->pixonaut_line();
		add_filter(
			'pixonaut_wc_fulfilment_order_status_map',
			static function ( array $map ): array {
				$map['printed'] = LineState::PRINTED;
				return $map;
			}
		);

		$this->deliver( $this->status_event( 'queued', 'printed' ) );
		$this->run_jobs();

		$this->assertSame( LineState::PRINTED, LineState::of( $item )->state() );
	}

	public function test_an_echo_of_the_stores_own_change_is_ignored(): void {
		$item = $this->pixonaut_line();

		$this->deliver( $this->status_event( 'submitted', 'queued', null, array( 'via' => 'store_key' ) ) );
		$this->run_jobs();

		$this->assertSame( LineState::APPROVED, LineState::of( $item )->state() );
	}

	public function test_a_line_whose_external_ref_differs_is_not_touched(): void {
		$item = $this->pixonaut_line();

		$this->deliver( $this->status_event( 'submitted', 'queued', null, array( 'externalRef' => 'someone_else' ) ) );
		$this->run_jobs();

		$this->assertSame( LineState::APPROVED, LineState::of( $item )->state() );
	}

	public function test_an_order_taken_on_under_another_ref_moves_the_line(): void {
		$item = $this->pixonaut_line();
		$line = LineState::of( $item );
		$line->set_ref( LineState::REF_ORDER_AUTOMATION, 'Order approved designs' );
		$line->set_ref( LineState::REF_ORDER_EXTERNAL_REF, 'automation_ref' );
		$line->save();

		$this->deliver( $this->status_event( 'submitted', 'queued', null, array( 'externalRef' => 'automation_ref' ) ) );
		$this->deliver( $this->status_event( 'queued', 'printed', null, array( 'externalRef' => 'someone_else' ) ) );
		$this->run_jobs();

		$this->assertSame( LineState::ORDERED, LineState::of( $item )->state() );
		$this->assertSame( 'queued', LineState::of( $item )->ref( LineState::REF_ORDER_STATUS ) );
	}

	public function test_a_line_of_another_workspace_is_not_touched(): void {
		$item = $this->pixonaut_line( array( 'tenant' => 'another-tenant' ) );

		$this->deliver( $this->status_event( 'submitted', 'queued' ) );
		$this->run_jobs();

		$this->assertSame( LineState::APPROVED, LineState::of( $item )->state() );
	}

	public function test_an_unknown_order_id_changes_nothing(): void {
		$item = $this->pixonaut_line();

		$this->deliver( $this->status_event( 'submitted', 'queued', null, array( 'orderId' => 'ord_unknown' ) ) );
		$this->run_jobs();

		$this->assertSame( LineState::APPROVED, LineState::of( $item )->state() );
	}

	public function test_an_unknown_status_changes_nothing_and_leaves_a_note(): void {
		$item = $this->pixonaut_line();

		$this->deliver_unchecked( $this->status_event( 'queued', 'teleported' ) );
		$this->run_jobs();

		$this->assertSame( LineState::APPROVED, LineState::of( $item )->state() );
		$this->assertStringContainsString( 'teleported', $this->notes( $item ) );
	}

	public function test_an_order_failure_moves_the_line_to_needs_attention_and_tells_the_store(): void {
		$item = $this->pixonaut_line();
		reset_phpmailer_instance();

		$this->deliver( $this->failed_event( 'the printshop adapter threw instead of answering' ) );
		$this->run_jobs();

		$line = LineState::of( $item );
		$this->assertSame( LineState::NEEDS_ATTENTION, $line->state() );
		$this->assertSame( LineState::APPROVED, $line->resume_from() );
		$this->assertSame( NeedsAttention::ORDER_FAILED, $line->reason() );
		$this->assertSame( 'failed', $line->ref( LineState::REF_ORDER_STATUS ) );
		$this->assertStringContainsString( 'the printshop adapter threw instead of answering', $this->notes( $item ) );
		$notices = (array) get_option( Notices::OPTION );
		$this->assertArrayHasKey( AttentionAlerts::key( $item->get_order_id() ), $notices );
		$this->assertFalse( $notices[ AttentionAlerts::key( $item->get_order_id() ) ]['dismissible'] );
		$mails = SentMail::all();
		$this->assertCount( 1, $mails );
		$this->assertSame( get_option( 'admin_email' ), $mails[0]['to'] );
		$this->assertStringContainsString( 'needs attention', $mails[0]['subject'] );
		$this->assertStringContainsString( NeedsAttention::ORDER_FAILED, $mails[0]['body'] );
	}

	public function test_the_needs_attention_email_goes_to_the_recipients_the_store_set(): void {
		update_option( 'woocommerce_' . NeedsAttentionEmail::ID . '_settings', array( 'recipient' => 'ops@example.org' ) );

		$email = new NeedsAttentionEmail();

		$this->assertArrayHasKey( 'recipient', $email->get_form_fields() );
		$this->assertSame( 'ops@example.org', $email->get_recipient() );
	}

	public function test_an_order_failure_delivered_twice_is_noted_once(): void {
		$item  = $this->pixonaut_line();
		$event = $this->failed_event( 'refused' );

		$this->deliver( $event );
		$this->run_jobs();
		// A second event for the same failure, e.g. from reconcile.
		$this->deliver( array( 'id' => $event['id'] . '-again' ) + $event );
		$this->run_jobs();

		$this->assertSame( 1, substr_count( $this->notes( $item ), 'refused' ) );
	}

	public function test_an_order_failure_leaves_a_shipped_line_alone(): void {
		$item = $this->pixonaut_line();
		foreach ( self::JOURNEY as list( $previous, $status ) ) {
			$this->deliver( $this->status_event( $previous, $status, 'shipped' === $status ? self::TRACKING : null ) );
		}
		$this->run_jobs();

		$this->deliver( $this->failed_event( 'late' ) );
		$this->run_jobs();

		$this->assertSame( LineState::SHIPPED, LineState::of( $item )->state() );
	}

	public function test_a_forward_change_delivered_after_an_order_failure_keeps_the_line_in_needs_attention(): void {
		$item = $this->pixonaut_line();
		$this->deliver( $this->failed_event( 'refused' ) );
		$this->run_jobs();

		$this->deliver( $this->status_event( 'submitted', 'queued' ) );
		$this->run_jobs();

		$line = LineState::of( $item );
		$this->assertSame( LineState::NEEDS_ATTENTION, $line->state() );
		$this->assertSame( 'failed', $line->ref( LineState::REF_ORDER_STATUS ) );
		$this->assertArrayHasKey( AttentionAlerts::key( $item->get_order_id() ), (array) get_option( Notices::OPTION ) );
	}

	public function test_an_unknown_outcome_moves_the_line_to_needs_attention_and_is_never_sent_again(): void {
		$item = $this->pixonaut_line();

		$this->deliver( $this->status_event( 'submitted', 'outcome_unknown' ) );
		$this->run_jobs();

		$line = LineState::of( $item );
		$this->assertSame( LineState::NEEDS_ATTENTION, $line->state() );
		$this->assertSame( NeedsAttention::OUTCOME_UNKNOWN, $line->reason() );
		$this->assertSame( 'outcome_unknown', $line->ref( LineState::REF_ORDER_STATUS ) );
		$this->assertStringContainsString( 'Check with the printshop', $this->notes( $item ) );
		$this->assertSame( array(), $this->pending_jobs() );
	}

	public function test_an_unknown_outcome_settled_in_the_studio_moves_the_line_on(): void {
		$item = $this->pixonaut_line();
		$this->deliver( $this->status_event( 'submitted', 'outcome_unknown' ) );
		$this->run_jobs();

		$this->deliver( $this->status_event( 'outcome_unknown', 'submitted', null, array( 'via' => 'member' ) ) );
		$this->run_jobs();

		$this->assertSame( LineState::ORDERED, LineState::of( $item )->state() );
		$this->assertArrayNotHasKey( AttentionAlerts::key( $item->get_order_id() ), (array) get_option( Notices::OPTION ) );
	}

	public function test_an_unknown_outcome_reported_before_it_was_settled_leaves_the_settled_line_alone(): void {
		$item     = $this->pixonaut_line();
		$reported = $this->status_event( 'submitted', 'outcome_unknown' );
		// Pixonaut created the event before the line was settled; it is delivered only after.
		$reported['createdAt'] = Milliseconds::of( time() - MINUTE_IN_SECONDS );
		$this->deliver( $this->status_event( 'submitted', 'outcome_unknown' ) );
		$this->run_jobs();
		$this->deliver( $this->status_event( 'outcome_unknown', 'submitted', null, array( 'via' => 'member' ) ) );
		$this->run_jobs();
		$this->assertSame( LineState::ORDERED, LineState::of( $item )->state() );

		$this->deliver( $reported );
		$this->run_jobs();

		$line = LineState::of( $item );
		$this->assertSame( LineState::ORDERED, $line->state() );
		$this->assertSame( 'submitted', $line->ref( LineState::REF_ORDER_STATUS ) );
	}

	public function test_a_late_unknown_outcome_leaves_a_line_that_moved_on_alone(): void {
		$item = $this->pixonaut_line();
		$this->deliver( $this->status_event( 'submitted', 'queued' ) );
		$this->run_jobs();

		$this->deliver( $this->status_event( 'submitted', 'outcome_unknown' ) );
		$this->run_jobs();

		$this->assertSame( LineState::ORDERED, LineState::of( $item )->state() );
	}

	public function test_an_unknown_event_type_is_acknowledged_and_changes_nothing(): void {
		$item  = $this->pixonaut_line();
		$fired = array();
		add_action(
			'pixonaut_wc_webhook_widget_spun',
			static function ( array $payload, $event, bool $handled ) use ( &$fired ): void {
				$fired[] = $handled;
			},
			10,
			3
		);

		$response = $this->deliver_unchecked(
			array(
				'id'        => 'evt_unknown_type',
				'type'      => 'widget.spun',
				'payload'   => array( 'orderId' => self::ORDER_ID ),
				'createdAt' => time() * 1000,
			)
		);
		$this->run_jobs();

		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( LineState::APPROVED, LineState::of( $item )->state() );
		$this->assertSame( array( false ), $fired );
	}

	public function test_a_site_can_take_an_event_over(): void {
		$item    = $this->pixonaut_line();
		$handled = null;
		add_filter( 'pixonaut_wc_webhook_handle', '__return_false' );
		add_action(
			'pixonaut_wc_webhook_order_status_changed',
			static function ( array $payload, $event, bool $by_plugin ) use ( &$handled ): void {
				$handled = $by_plugin;
			},
			10,
			3
		);

		$this->deliver( $this->status_event( 'submitted', 'queued' ) );
		$this->run_jobs();

		$this->assertSame( LineState::APPROVED, LineState::of( $item )->state() );
		$this->assertFalse( $handled );
	}

	public function test_a_job_action_scheduler_refuses_is_retried_by_pixonaut(): void {
		$envelope = $this->status_event( 'submitted', 'queued' );
		add_filter( 'pre_as_enqueue_async_action', static fn (): int => 0 );

		$response = $this->deliver( $envelope );

		$this->assertSame( 503, $response->get_status() );
		$this->assertNull( ( new EventStore() )->body( $envelope['id'] ), 'forgotten, so the retry is taken as new' );
	}

	public function test_events_are_kept_thirty_days(): void {
		$store = new EventStore();
		$now   = time();
		$this->deliver( $this->status_event( 'submitted', 'queued' ) );
		$kept = $this->status_event( 'queued', 'printed' );
		$this->deliver( $kept );
		global $wpdb;
		$wpdb->query( $wpdb->prepare( 'UPDATE ' . EventStore::table() . ' SET received_at = %d WHERE event_id <> %s', $now - EventStore::RETENTION_SECONDS - 1, $kept['id'] ) ); // phpcs:ignore WordPress.DB

		$this->assertSame( 1, $store->prune( $now ) );
		$this->assertNotNull( $store->body( $kept['id'] ) );
	}

	public function test_maintenance_is_scheduled_daily(): void {
		as_unschedule_all_actions( Processor::MAINTENANCE_HOOK, array(), Processor::GROUP );

		Plugin::boot( dirname( __DIR__, 4 ) . '/pixonaut-for-woocommerce.php' )->webhook_processor()->schedule_maintenance();

		$this->assertNotFalse( as_next_scheduled_action( Processor::MAINTENANCE_HOOK, null, Processor::GROUP ) );
	}

	public function test_the_maintenance_job_drops_old_events(): void {
		$envelope = $this->status_event( 'submitted', 'queued' );
		$this->deliver( $envelope );
		$this->age_events( EventStore::RETENTION_SECONDS + 1 );

		do_action( Processor::MAINTENANCE_HOOK );

		$this->assertNull( ( new EventStore() )->body( $envelope['id'] ) );
	}

	public function test_an_event_the_database_refuses_is_retried_by_pixonaut(): void {
		$item     = $this->pixonaut_line();
		$envelope = $this->status_event( 'submitted', 'queued' );
		$refuse   = static fn ( string $query ): string => str_starts_with( $query, 'INSERT IGNORE INTO `' . EventStore::table() . '`' ) ? '' : $query;
		add_filter( 'query', $refuse );

		$refused = $this->deliver( $envelope );
		remove_filter( 'query', $refuse );

		$this->assertSame( 503, $refused->get_status() );
		$this->assertSame( array(), $this->pending_jobs() );

		$retried = $this->deliver( $envelope );
		$this->run_jobs();

		$this->assertSame( 'received', $retried->get_data()['result'] );
		$this->assertSame( LineState::ORDERED, LineState::of( $item )->state() );
	}

	public function test_an_event_whose_handling_throws_is_tried_again(): void {
		$item     = $this->pixonaut_line();
		$failures = 1;
		add_action(
			'pixonaut_wc_webhook_order_status_changed',
			static function () use ( &$failures ): void {
				if ( $failures-- > 0 ) {
					throw new RuntimeException( 'Not now.' );
				}
			}
		);
		$envelope = $this->status_event( 'submitted', 'queued' );

		$this->deliver( $envelope );
		$this->run_jobs();

		$this->assertFalse( ( new EventStore() )->processed( $envelope['id'] ) );
		$this->assertCount( 1, $this->pending_jobs(), 'tried again later' );

		$this->run_jobs();

		$this->assertTrue( ( new EventStore() )->processed( $envelope['id'] ) );
		$this->assertSame( LineState::ORDERED, LineState::of( $item )->state() );
		$this->assertSame( array(), $this->pending_jobs() );
	}

	public function test_an_event_is_given_up_after_its_last_attempt(): void {
		$this->pixonaut_line();
		$attempts = 0;
		add_action(
			'pixonaut_wc_webhook_order_status_changed',
			static function () use ( &$attempts ): void {
				++$attempts;
				throw new RuntimeException( 'Never.' );
			}
		);
		$envelope = $this->status_event( 'submitted', 'queued' );
		$this->deliver( $envelope );

		for ( $run = 0; $run <= Processor::MAX_ATTEMPTS; $run++ ) {
			$this->run_jobs();
		}
		$this->age_events( Processor::STRANDED_AFTER_SECONDS + 1 );
		do_action( Processor::MAINTENANCE_HOOK );

		$this->assertSame( Processor::MAX_ATTEMPTS, $attempts );
		$this->assertSame( array(), $this->pending_jobs(), 'neither tried again nor queued by maintenance' );
		$this->assertFalse( ( new EventStore() )->processed( $envelope['id'] ) );
	}

	public function test_a_redelivery_queues_an_event_left_without_a_job(): void {
		$item     = $this->pixonaut_line();
		$envelope = $this->status_event( 'submitted', 'queued' );
		$this->deliver( $envelope );
		$this->lose_jobs();

		$response = $this->deliver( $envelope );

		$this->assertSame( 'duplicate', $response->get_data()['result'] );
		$this->assertCount( 1, $this->pending_jobs() );
		$this->run_jobs();
		$this->assertSame( LineState::ORDERED, LineState::of( $item )->state() );
	}

	public function test_the_maintenance_job_queues_events_left_without_a_job(): void {
		$item     = $this->pixonaut_line();
		$stranded = $this->status_event( 'submitted', 'queued' );
		$this->deliver( $stranded );
		$this->lose_jobs();
		$this->age_events( Processor::STRANDED_AFTER_SECONDS + 1 );
		$recent = $this->status_event( 'queued', 'printed' );
		$this->deliver( $recent );
		$this->lose_jobs();

		do_action( Processor::MAINTENANCE_HOOK );

		$this->assertCount( 1, $this->pending_jobs(), 'only the event older than STRANDED_AFTER_SECONDS' );
		$this->run_jobs();
		$this->assertTrue( ( new EventStore() )->processed( $stranded['id'] ) );
		$this->assertFalse( ( new EventStore() )->processed( $recent['id'] ) );
		$this->assertSame( LineState::ORDERED, LineState::of( $item )->state() );
	}

	public function test_a_change_applies_over_a_stale_cached_line(): void {
		$item = $this->pixonaut_line();
		LineState::of( $item );
		// Another request rewrites the line behind this one's object cache.
		global $wpdb;
		$raw  = (string) get_metadata( 'order_item', $item->get_id(), LineState::META_KEY, true );
		$data = json_decode( $raw, true );
		$this->assertIsArray( $data );
		$data['changedAt'] = (int) $data['changedAt'] + 1;
		// phpcs:disable WordPress.DB
		$wpdb->update(
			$wpdb->prefix . 'woocommerce_order_itemmeta',
			array( 'meta_value' => wp_json_encode( $data ) ),
			array(
				'order_item_id' => $item->get_id(),
				'meta_key'      => LineState::META_KEY,
			)
		);
		// phpcs:enable WordPress.DB

		$this->deliver( $this->status_event( 'submitted', 'queued' ) );
		$this->run_jobs();

		$this->assertSame( LineState::ORDERED, LineState::of( $item )->state() );
	}

	public function test_a_shipped_event_tried_again_completes_the_order_once(): void {
		$item     = $this->pixonaut_line();
		$failures = 1;
		add_filter(
			'pixonaut_wc_fulfilment_complete_order',
			static function ( bool $complete ) use ( &$failures ): bool {
				if ( $failures-- > 0 ) {
					throw new RuntimeException( 'Not now.' );
				}
				return $complete;
			}
		);

		$this->deliver( $this->status_event( 'printed', 'shipped' ) );
		$this->run_jobs();
		$this->assertSame( 'processing', $this->order_of( $item )->get_status() );

		$this->run_jobs();

		$this->assertSame( 'completed', $this->order_of( $item )->get_status() );
		$this->assertSame( 1, substr_count( $this->notes( $item ), 'was shipped' ) );
	}

	public function test_a_line_the_index_wrongly_points_at_is_not_touched(): void {
		$item = $this->pixonaut_line();
		$line = LineState::of( $item );
		$line->set_ref( LineState::REF_ORDER_ID, 'ord_other' );
		$line->save();
		update_metadata( 'order_item', $item->get_id(), LineState::ORDER_ID_META_KEY, self::ORDER_ID );

		$this->deliver( $this->status_event( 'submitted', 'queued' ) );
		$this->run_jobs();

		$this->assertSame( LineState::APPROVED, LineState::of( $item )->state() );
	}

	/**
	 * Serves the site from another address, as a copy of its database is.
	 *
	 * @return Closure The `home_url` filter, to remove.
	 */
	private function copy_site(): Closure {
		$copied = static fn ( string $url ): string => (string) preg_replace( '#^https?://[^/]+#', 'https://staging.example.test', $url );
		add_filter( 'home_url', $copied );
		$this->assertSame( Connection::PAUSED_SITE_CHANGED, ( new ConnectionStore( Cipher::for_site() ) )->paused() );
		return $copied;
	}

	/**
	 * @param string $event_id Event id.
	 * @return int Attempts made at handling the event.
	 */
	private function attempts( string $event_id ): int {
		global $wpdb;
		return (int) $wpdb->get_var( $wpdb->prepare( 'SELECT attempts FROM ' . EventStore::table() . ' WHERE event_id = %s', $event_id ) ); // phpcs:ignore WordPress.DB
	}

	/**
	 * Cancels every queued webhook job, as if Action Scheduler lost them.
	 */
	private function lose_jobs(): void {
		as_unschedule_all_actions( Processor::PROCESS_HOOK );
		$this->assertSame( array(), $this->pending_jobs() );
	}

	/**
	 * Moves every kept event's arrival back.
	 *
	 * @param int $seconds How far.
	 */
	private function age_events( int $seconds ): void {
		global $wpdb;
		$wpdb->query( $wpdb->prepare( 'UPDATE ' . EventStore::table() . ' SET received_at = received_at - %d', $seconds ) ); // phpcs:ignore WordPress.DB
	}
}
