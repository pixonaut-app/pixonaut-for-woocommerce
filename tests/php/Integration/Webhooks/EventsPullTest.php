<?php
/**
 * @package Pixonaut\WooCommerce
 */

declare(strict_types=1);

namespace Pixonaut\WooCommerce\Tests\Integration\Webhooks;

use Pixonaut\WooCommerce\Api\Deprecations;
use Pixonaut\WooCommerce\Api\HookListener;
use Pixonaut\WooCommerce\Api\Hosts;
use Pixonaut\WooCommerce\Api\Pixonaut;
use Pixonaut\WooCommerce\Api\WcLogger;
use Pixonaut\WooCommerce\Connection\Cipher;
use Pixonaut\WooCommerce\Connection\Connection;
use Pixonaut\WooCommerce\Connection\ConnectionStore;
use Pixonaut\WooCommerce\Connection\StoredConnection;
use Pixonaut\WooCommerce\Fulfilment\DesignChanged;
use Pixonaut\WooCommerce\Fulfilment\LineReader;
use Pixonaut\WooCommerce\Fulfilment\LineState;
use Pixonaut\WooCommerce\Fulfilment\OrderEventLines;
use Pixonaut\WooCommerce\Fulfilment\OrderStatusChanged;
use Pixonaut\WooCommerce\Fulfilment\Shipments;
use Pixonaut\WooCommerce\Plugin;
use Pixonaut\WooCommerce\Tests\Support\ActionSchedulerRunner;
use Pixonaut\WooCommerce\Tests\Support\ConnectionAnswers;
use Pixonaut\WooCommerce\Tests\Support\DesignFixture;
use Pixonaut\WooCommerce\Tests\Support\FulfilmentAnswers;
use Pixonaut\WooCommerce\Tests\Support\HttpRecorder;
use Pixonaut\WooCommerce\Tests\Support\RecordingLogger;
use Pixonaut\WooCommerce\Vendor\Pixonaut\Sdk\Exception\ApiException;
use Pixonaut\WooCommerce\Vendor\Pixonaut\Sdk\Generated\ErrorCode;
use Pixonaut\WooCommerce\Vendor\Pixonaut\Sdk\Webhooks\Signature;
use Pixonaut\WooCommerce\Webhooks\Endpoint;
use Pixonaut\WooCommerce\Webhooks\EventsPull;
use Pixonaut\WooCommerce\Webhooks\EventStore;
use Pixonaut\WooCommerce\Webhooks\Intake;
use Pixonaut\WooCommerce\Webhooks\Processor;
use WC_Order;
use WC_Order_Item_Product;
use WP_REST_Request;
use WP_UnitTestCase;

/**
 * The event stream pulled through the plugin's real jobs and handlers, against
 * Pixonaut answered from a script.
 */
final class EventsPullTest extends WP_UnitTestCase {

	/**
	 * Loopback, so the test bootstrap's host guard lets it through to the recorder.
	 */
	private const BASE_URL = 'http://127.0.0.1:9';

	private const SECRET = 'whsec_0123456789abcdef0123456789abcdef';

	private const WEBHOOK_ID = '0b1c2d3e-4f50-4a6b-8c7d-000000000007';

	private const CONNECTION_ID = 'sc_0b1c2d3e-4f50-4a6b-8c7d-000000000004';

	/**
	 * The connection the store connects to again in a test.
	 */
	private const OTHER_CONNECTION_ID = 'sc_0b1c2d3e-4f50-4a6b-8c7d-000000000008';

	private const ORDER_ID = 'e1f2a3b4-c5d6-4e7f-8a9b-0c1d2e3f4a5b';

	private const PRINT_ID = 'c4d5e6f7-a8b9-4c0d-9e1f-2a3b4c5d6e7f';

	/**
	 * The order a retry of ORDER_ID placed.
	 */
	private const RETRY_ID = 'a7b8c9d0-e1f2-4a3b-8c4d-5e6f7a8b9c0d';

	private const EXTERNAL_REF = 'wc:5c3a1f6e:order:1:item:1';

	private const TRACKING = array(
		'carrier' => 'PostNord',
		'number'  => 'PN123456789SE',
		'url'     => 'https://tracking.example/PN123456789SE',
	);

	/**
	 * Failed deliveries the endpoint reports when a test needs some.
	 */
	private const FAILED_DELIVERIES = 2;

	private HttpRecorder $recorder;

	private ConnectionStore $store;

	private RecordingLogger $logger;

	private int $sequence = 0;

	public function set_up(): void {
		parent::set_up();
		$this->recorder = ( new HttpRecorder( self::BASE_URL ) )->start();
		$this->store    = new ConnectionStore( Cipher::for_site() );
		$this->store->save( self::connection() );
		delete_option( EventsPull::CURSOR_OPTION );
		delete_option( EventsPull::BASELINE_OPTION );

		$plugin = Plugin::boot( dirname( __DIR__, 4 ) . '/pixonaut-for-woocommerce.php' );
		$client = ( new Pixonaut( $this->store, new WcLogger(), new HookListener( new Deprecations() ), null, self::BASE_URL ) )->sdk();
		// The plugin's own jobs stay scheduled as in production; they run against the recorder.
		foreach ( array( EventsPull::PULL_HOOK, EventsPull::BASELINE_HOOK, LineReader::ACTION, LineReader::QUEUE_ACTION, 'pixonaut_wc_events_baseline_set', 'pixonaut_wc_connection_connected', 'pixonaut_wc_connection_disconnected' ) as $hook ) {
			remove_all_actions( $hook );
		}
		$intake = new Intake( new EventStore(), $plugin->webhook_processor(), new WcLogger() );
		( new EventsPull( $this->store, $client, $intake, new WcLogger() ) )->register();
		$design       = new DesignChanged( $plugin->connection(), $client, static fn (): bool => true, new WcLogger() );
		$this->logger = new RecordingLogger();
		( new LineReader( $client, new OrderStatusChanged( new OrderEventLines( $plugin->connection(), new WcLogger() ), new Shipments() ), $design, $this->logger ) )->register();
	}

	public function tear_down(): void {
		$this->recorder->stop();
		$this->store->clear();
		parent::tear_down();
	}

	public function test_connecting_skips_the_history_then_reads_every_open_line_once(): void {
		$open    = $this->line( LineState::ORDERED );
		$shipped = $this->line( LineState::SHIPPED );
		$old     = $this->status_event( 'printed', 'shipped', self::TRACKING );
		$last    = $this->status_event( 'submitted', 'queued' );
		$this->recorder
			->json( 200, self::page( array( $old ), 'evt_looked_through' ) )
			->json( 200, self::page( array( $last ), null ) );
		$baselines = 0;
		add_action(
			'pixonaut_wc_events_baseline_set',
			static function () use ( &$baselines ): void {
				++$baselines;
			}
		);

		do_action( 'pixonaut_wc_connection_connected', self::connection()->summary() );
		ActionSchedulerRunner::run_pending( EventsPull::BASELINE_HOOK );
		$this->assertSame( 1, ActionSchedulerRunner::run_pending( LineReader::QUEUE_ACTION ) );

		$this->assertSame( self::cursor( $last['id'] ), get_option( EventsPull::CURSOR_OPTION ) );
		$this->assertSame( array(), ActionSchedulerRunner::pending( Processor::PROCESS_HOOK ), 'no event from before the connect is acted on' );
		$this->assertNull( ( new EventStore() )->body( $old['id'] ) );
		$this->assertSame( 1, $baselines );
		$this->assertStringNotContainsString( 'after=', $this->recorder->requests[0]['url'] );
		$this->assertStringContainsString( 'after=evt_looked_through', $this->recorder->requests[1]['url'] );
		$this->assertStringContainsString( 'types=' . rawurlencode( implode( ',', array_diff( Endpoint::EVENT_TYPES, Endpoint::WEBHOOK_ONLY_TYPES ) ) ), $this->recorder->requests[0]['url'] );
		$this->assertStringNotContainsString( 'template.renamed', $this->recorder->requests[0]['url'], 'the stream refuses a type only delivered to webhooks' );
		$this->assertSame( array( $open->get_id() ), self::read_items(), 'only the open line is read' );

		$this->recorder
			->json( 200, FulfilmentAnswers::order_read( self::ORDER_ID, self::PRINT_ID, self::EXTERNAL_REF, array( 'submitted', 'queued', 'printed', 'shipped' ), self::TRACKING ) )
			->json( 200, DesignFixture::design() );
		$this->assertSame( 1, ActionSchedulerRunner::run_pending( LineReader::ACTION ) );

		$line = LineState::of( $open );
		$this->assertSame( LineState::SHIPPED, $line->state(), 'the read caught the line up with its order' );
		$this->assertEquals( self::TRACKING, $line->ref( LineState::REF_TRACKING ) );
		$this->assertSame( LineState::SHIPPED, LineState::of( $shipped )->state() );
		$this->assertCount( 4, $this->recorder->requests );

		// The next pull goes on from the cursor; no new first cursor, no second read.
		$this->recorder
			->json( 200, self::page( array(), null ) )
			->json( 200, array( self::endpoint( 0 ) ) );
		do_action( EventsPull::PULL_HOOK );
		$this->assertStringContainsString( 'after=' . rawurlencode( $last['id'] ), $this->recorder->requests[4]['url'] );
		$this->assertSame( 1, $baselines );
		$this->assertSame( array(), self::read_items() );
	}

	public function test_a_read_flags_a_line_whose_design_is_gone_and_marks_an_erased_one(): void {
		$gone   = $this->line( LineState::COMMITTED, false );
		$erased = $this->line( LineState::COMMITTED, false );
		$at     = 1_800_000_456_000;
		$this->recorder
			->json( 404, array( 'error' => 'not_found' ) )
			->json( 200, DesignFixture::design( array( 'personalDataErasedAt' => $at ) ) );

		do_action( LineReader::ACTION, $gone->get_id() );
		do_action( LineReader::ACTION, $erased->get_id() );

		$this->assertSame( LineState::NEEDS_ATTENTION, LineState::of( $gone )->state() );
		$this->assertSame( LineState::COMMITTED, LineState::of( $erased )->state() );
		$this->assertSame( $at, LineState::of( $erased )->side_ref( 'front', LineState::SIDE_ERASED_AT ) );
		$this->assertCount( 2, $this->recorder->requests, 'a line without an order reads only its design' );
	}

	public function test_a_read_that_fails_is_tried_again_and_catches_the_line_up(): void {
		$item  = $this->line( LineState::ORDERED );
		$order = FulfilmentAnswers::order_read( self::ORDER_ID, self::PRINT_ID, self::EXTERNAL_REF, array( 'submitted', 'queued', 'printed', 'shipped' ), self::TRACKING );
		$this->recorder
			->json( 200, $order )
			->json( 500, array( 'error' => 'internal' ) );

		try {
			do_action( LineReader::ACTION, $item->get_id() );
			$this->fail( 'A failed read is rethrown, so Action Scheduler records it.' );
		} catch ( ApiException $e ) {
			$this->assertSame( 500, $e->status );
		}

		$this->assertSame( LineState::SHIPPED, LineState::of( $item )->state(), 'the order read was applied before the design read failed' );
		$this->assertSame( array( $item->get_id() ), self::read_items(), 'a second read is queued' );
		$retry = \ActionScheduler::store()->fetch_action( ActionSchedulerRunner::pending( LineReader::ACTION )[0] );
		$this->assertSame( 2, $retry->get_args()['attempt'] );
		$this->assertSame( 'error', $this->logger->entries[0]['level'] );
		$this->assertSame(
			array(
				'item'    => $item->get_id(),
				'attempt' => 1,
			),
			$this->logger->entries[0]['context']
		);

		$this->recorder
			->json( 200, $order )
			->json( 200, DesignFixture::design( array( 'revision' => 2 ) ) );
		$this->assertSame( 1, ActionSchedulerRunner::run_pending( LineReader::ACTION ) );

		$this->assertSame( 2, LineState::of( $item )->side_ref( 'front', LineState::SIDE_SEEN_REVISION ), 'the retry read the design of the line shipped meanwhile' );
		$this->assertSame( array(), ActionSchedulerRunner::pending( LineReader::ACTION ) );
	}

	public function test_a_line_failing_every_read_is_given_up_on(): void {
		$item = $this->line( LineState::ORDERED );
		for ( $attempt = 1; $attempt <= LineReader::MAX_ATTEMPTS; $attempt++ ) {
			$this->recorder->json( 500, array( 'error' => 'internal' ) );
		}
		as_enqueue_async_action( LineReader::ACTION, array( 'item_id' => $item->get_id() ), LineReader::GROUP );

		$this->assertSame( LineReader::MAX_ATTEMPTS, ActionSchedulerRunner::run_pending( LineReader::ACTION ) );

		$this->assertCount( LineReader::MAX_ATTEMPTS, ActionSchedulerRunner::failed( LineReader::ACTION ) );
		$this->assertSame( array(), ActionSchedulerRunner::pending( LineReader::ACTION ) );
	}

	public function test_open_lines_are_queued_a_page_at_a_time(): void {
		$items = array();
		for ( $n = 0; $n <= LineReader::QUEUE_BATCH; $n++ ) {
			$items[] = $this->line( LineState::ORDERED )->get_id();
		}
		$this->line( LineState::SHIPPED );

		do_action( 'pixonaut_wc_events_baseline_set', self::connection()->summary() );

		$this->assertSame( 2, ActionSchedulerRunner::run_pending( LineReader::QUEUE_ACTION ), 'a full page queues the page after' );
		$queued = self::read_items();
		sort( $queued );
		$this->assertSame( $items, $queued );
	}

	public function test_a_long_history_is_paged_over_several_jobs(): void {
		for ( $page = 1; $page <= EventsPull::MAX_PAGES_PER_JOB; $page++ ) {
			$this->recorder->json( 200, self::page( array(), "evt_{$page}" ) );
		}
		$this->recorder->json( 200, self::page( array(), null ) );

		do_action( 'pixonaut_wc_connection_connected', self::connection()->summary() );
		ActionSchedulerRunner::run_pending( EventsPull::BASELINE_HOOK );

		$this->assertCount( EventsPull::MAX_PAGES_PER_JOB + 1, $this->recorder->requests );
		$this->assertStringContainsString( 'after=evt_' . EventsPull::MAX_PAGES_PER_JOB, $this->recorder->requests[ EventsPull::MAX_PAGES_PER_JOB ]['url'] );
		$this->assertSame( self::cursor( 'evt_' . EventsPull::MAX_PAGES_PER_JOB ), get_option( EventsPull::CURSOR_OPTION ) );
		$this->assertFalse( get_option( EventsPull::BASELINE_OPTION ) );
	}

	public function test_a_lost_delivery_is_handled_from_the_pull(): void {
		$item = $this->line( LineState::ORDERED );
		update_option( EventsPull::CURSOR_OPTION, self::cursor( null ) );
		$shipped = $this->status_event( 'printed', 'shipped', self::TRACKING );
		$this->recorder
			->json( 200, self::page( array( $shipped ), null ) )
			->json( 200, array( self::endpoint( 0 ) ) );

		do_action( EventsPull::PULL_HOOK );
		ActionSchedulerRunner::run_pending( Processor::PROCESS_HOOK );

		$this->assertSame( LineState::SHIPPED, LineState::of( $item )->state() );
		$this->assertSame( self::cursor( $shipped['id'] ), get_option( EventsPull::CURSOR_OPTION ) );
		$this->assertCount( 2, $this->recorder->requests, 'nothing to deliver again: no redelivery asked' );
	}

	public function test_an_event_both_pulled_and_delivered_is_handled_once(): void {
		$this->line( LineState::ORDERED );
		update_option( EventsPull::CURSOR_OPTION, self::cursor( null ) );
		$fired = 0;
		add_action(
			'pixonaut_wc_webhook_order_status_changed',
			static function () use ( &$fired ): void {
				++$fired;
			}
		);
		$event = $this->status_event( 'printed', 'shipped', self::TRACKING );
		$this->recorder
			->json( 200, self::page( array( $event ), null ) )
			->json( 200, array( self::endpoint( 0 ) ) );

		do_action( EventsPull::PULL_HOOK );
		$delivered = $this->deliver( $event );
		ActionSchedulerRunner::run_pending( Processor::PROCESS_HOOK );
		$again = $this->deliver( $event );

		$this->assertSame( 'duplicate', $delivered->get_data()['result'] );
		$this->assertSame( 'duplicate', $again->get_data()['result'] );
		$this->assertSame( 1, $fired );
	}

	public function test_a_retried_order_pulled_hands_the_line_to_its_replacement_and_its_read_follows(): void {
		$item = $this->line( LineState::ORDERED );
		$line = LineState::of( $item );
		$line->set_ref( LineState::REF_ORDER_STATUS, 'failed' );
		$line->transition( LineState::NEEDS_ATTENTION, 'order_failed' );
		update_option( EventsPull::CURSOR_OPTION, self::cursor( null ) );
		$this->recorder
			->json( 200, self::page( array( $this->retried_event( self::RETRY_ID ), $this->status_event( 'submitted', 'queued', null, self::RETRY_ID ) ), null ) )
			->json( 200, array( self::endpoint( 0 ) ) );
		// The handler reads the new order through the plugin's own client.
		$plugin_api = ( new HttpRecorder( Hosts::server_api() ) )->start();
		$plugin_api->json( 200, FulfilmentAnswers::order_read( self::RETRY_ID, self::PRINT_ID, self::EXTERNAL_REF, array( 'submitted' ) ) );

		do_action( EventsPull::PULL_HOOK );
		ActionSchedulerRunner::run_pending( Processor::PROCESS_HOOK );
		$plugin_api->stop();

		$line = LineState::of( $item );
		$this->assertSame( LineState::ORDERED, $line->state() );
		$this->assertSame( self::RETRY_ID, $line->ref( LineState::REF_ORDER_ID ) );
		$this->assertSame( array( self::ORDER_ID ), $line->replaced_order_ids() );
		$this->assertSame( 'queued', $line->ref( LineState::REF_ORDER_STATUS ) );

		$this->recorder
			->json( 200, FulfilmentAnswers::order_read( self::RETRY_ID, self::PRINT_ID, self::EXTERNAL_REF, array( 'submitted', 'queued', 'printed', 'shipped' ), self::TRACKING ) )
			->json( 200, DesignFixture::design() );
		do_action( LineReader::ACTION, $item->get_id() );

		$this->assertStringContainsString( '/v1/orders/' . self::RETRY_ID, $this->recorder->requests[2]['url'] );
		$this->assertSame( LineState::SHIPPED, LineState::of( $item )->state() );
	}

	public function test_an_empty_last_page_keeps_the_cursor(): void {
		update_option( EventsPull::CURSOR_OPTION, self::cursor( 'evt_kept' ) );
		$this->recorder
			->json( 200, self::page( array(), null ) )
			->json( 200, array( self::endpoint( 0 ) ) );

		do_action( EventsPull::PULL_HOOK );

		$this->assertSame( self::cursor( 'evt_kept' ), get_option( EventsPull::CURSOR_OPTION ) );
	}

	public function test_a_page_with_an_event_that_is_no_envelope_keeps_the_cursor_until_it_is_sent_whole(): void {
		$item = $this->line( LineState::ORDERED );
		update_option( EventsPull::CURSOR_OPTION, self::cursor( 'evt_kept' ) );
		$printed   = $this->status_event( 'ordered', 'printed' );
		$shipped   = $this->status_event( 'printed', 'shipped', self::TRACKING );
		$malformed = $shipped;
		unset( $malformed['createdAt'] );
		$this->recorder->off_spec( 200, self::page( array( $printed, $malformed ), null ) );

		do_action( EventsPull::PULL_HOOK );
		ActionSchedulerRunner::run_pending( Processor::PROCESS_HOOK );

		$this->assertSame( self::cursor( 'evt_kept' ), get_option( EventsPull::CURSOR_OPTION ) );
		$this->assertNull( ( new EventStore() )->body( $printed['id'] ) );
		$this->assertNull( ( new EventStore() )->body( $shipped['id'] ) );
		$this->assertSame( LineState::ORDERED, LineState::of( $item )->state() );

		$this->recorder
			->json( 200, self::page( array( $printed, $shipped ), null ) )
			->json( 200, array( self::endpoint( 0 ) ) );

		do_action( EventsPull::PULL_HOOK );
		ActionSchedulerRunner::run_pending( Processor::PROCESS_HOOK );

		$this->assertStringContainsString( 'after=evt_kept', $this->recorder->requests[1]['url'] );
		$this->assertSame( self::cursor( $shipped['id'] ), get_option( EventsPull::CURSOR_OPTION ) );
		$this->assertSame( LineState::SHIPPED, LineState::of( $item )->state() );
	}

	public function test_failed_deliveries_are_asked_for_again(): void {
		update_option( EventsPull::CURSOR_OPTION, self::cursor( null ) );
		$this->recorder
			->json( 200, self::page( array(), null ) )
			->json( 200, array( self::endpoint( self::FAILED_DELIVERIES ) ) )
			->json( 200, ConnectionAnswers::connection( true ) )
			->json( 202, array( 'requeued' => self::FAILED_DELIVERIES ) );

		do_action( EventsPull::PULL_HOOK );

		$this->assertCount( 4, $this->recorder->requests );
		$this->assertSame( 'POST', $this->recorder->requests[3]['method'] );
		$this->assertStringEndsWith( '/v1/webhooks/' . self::WEBHOOK_ID . '/deliveries/redeliver-failed', $this->recorder->requests[3]['url'] );
	}

	public function test_an_unreachable_store_reads_its_events_and_asks_for_no_delivery_again(): void {
		$item = $this->line( LineState::ORDERED );
		update_option( EventsPull::CURSOR_OPTION, self::cursor( null ) );
		$shipped = $this->status_event( 'printed', 'shipped', self::TRACKING );
		$this->recorder
			->json( 200, self::page( array( $shipped ), null ) )
			->json( 200, array( self::endpoint( self::FAILED_DELIVERIES ) ) )
			->json( 200, ConnectionAnswers::connection( false ) );

		do_action( EventsPull::PULL_HOOK );
		ActionSchedulerRunner::run_pending( Processor::PROCESS_HOOK );

		$this->assertSame( LineState::SHIPPED, LineState::of( $item )->state() );
		$this->assertCount( 3, $this->recorder->requests, 'no redelivery asked' );
		$this->assertStringEndsWith( '/v1/connect', $this->recorder->requests[2]['url'] );
	}

	public function test_an_address_that_stopped_answering_after_the_read_logs_nothing(): void {
		$this->assertSame( array(), $this->logged_after_a_redeliver_refused( ErrorCode::ENDPOINT_UNREACHABLE ) );
	}

	public function test_another_refused_redelivery_is_logged(): void {
		$logged = $this->logged_after_a_redeliver_refused( 'endpoint_inactive' );

		$this->assertCount( 1, $logged );
		$this->assertSame( 'warning', $logged[0]['level'] );
	}

	/**
	 * What a pull logs when the read says reachable and redelivering is refused.
	 *
	 * @param string $code The refusal's code.
	 * @return array<int, array<string, mixed>>
	 */
	private function logged_after_a_redeliver_refused( string $code ): array {
		update_option( EventsPull::CURSOR_OPTION, self::cursor( null ) );
		$this->recorder
			->json( 200, self::page( array(), null ) )
			->json( 200, array( self::endpoint( self::FAILED_DELIVERIES ) ) )
			->json( 200, ConnectionAnswers::connection( true ) )
			->json( 409, array( 'error' => $code ) );
		$client = ( new Pixonaut( $this->store, new WcLogger(), new HookListener( new Deprecations() ), null, self::BASE_URL ) )->sdk();
		$logger = new RecordingLogger();
		$intake = new Intake( new EventStore(), Plugin::boot( dirname( __DIR__, 4 ) . '/pixonaut-for-woocommerce.php' )->webhook_processor(), new WcLogger() );

		( new EventsPull( $this->store, $client, $intake, $logger ) )->pull();

		$this->assertCount( 4, $this->recorder->requests );
		return $logger->entries;
	}

	public function test_no_delivery_is_asked_for_again_when_the_connection_could_not_be_read(): void {
		update_option( EventsPull::CURSOR_OPTION, self::cursor( null ) );
		$this->recorder
			->json( 200, self::page( array(), null ) )
			->json( 200, array( self::endpoint( self::FAILED_DELIVERIES ) ) )
			->json( 403, array( 'error' => 'forbidden' ) );

		do_action( EventsPull::PULL_HOOK );

		$this->assertCount( 3, $this->recorder->requests, 'no redelivery asked' );
	}

	public function test_a_cursor_pixonaut_does_not_know_sets_a_new_first_cursor(): void {
		update_option( EventsPull::CURSOR_OPTION, self::cursor( 'evt_gone' ) );
		$this->recorder->json( 400, array( 'error' => 'unknown_event' ) );

		do_action( EventsPull::PULL_HOOK );

		$this->assertFalse( get_option( EventsPull::CURSOR_OPTION ) );
		$this->assertCount( 1, ActionSchedulerRunner::pending( EventsPull::BASELINE_HOOK ) );
	}

	public function test_the_baseline_starts_over_when_its_stored_point_is_unknown(): void {
		update_option(
			EventsPull::BASELINE_OPTION,
			array(
				'after'      => 'evt_gone',
				'connection' => self::CONNECTION_ID,
			)
		);
		$this->recorder
			->json( 400, array( 'error' => 'unknown_event' ) )
			->json( 200, self::page( array(), null ) );

		do_action( EventsPull::BASELINE_HOOK );

		$this->assertFalse( get_option( EventsPull::BASELINE_OPTION ) );
		$this->assertCount( 1, ActionSchedulerRunner::pending( EventsPull::BASELINE_HOOK ) );

		ActionSchedulerRunner::run_pending( EventsPull::BASELINE_HOOK );

		$this->assertStringNotContainsString( 'after=', $this->recorder->requests[1]['url'], 'paged again from the stream\'s start' );
		$this->assertSame( self::cursor( null ), get_option( EventsPull::CURSOR_OPTION ) );
	}

	public function test_a_pull_running_across_a_reconnect_leaves_no_cursor_for_the_new_connection(): void {
		$this->line( LineState::ORDERED );
		update_option( EventsPull::CURSOR_OPTION, self::cursor( null ) );
		$this->recorder
			->json( 200, self::page( array( $this->status_event( 'printed', 'shipped', self::TRACKING ) ), null ) )
			->json( 200, self::page( array(), null ) );
		$baselines = 0;
		add_action(
			'pixonaut_wc_events_baseline_set',
			static function () use ( &$baselines ): void {
				++$baselines;
			}
		);
		$this->on_first_answer(
			function (): void {
				$this->store->save( self::connection( self::OTHER_CONNECTION_ID ) );
				do_action( 'pixonaut_wc_connection_connected', self::connection( self::OTHER_CONNECTION_ID )->summary() );
			}
		);

		do_action( EventsPull::PULL_HOOK );

		$this->assertFalse( get_option( EventsPull::CURSOR_OPTION ), 'the old pull wrote no cursor' );
		$this->assertCount( 1, $this->recorder->requests, 'the old pull stopped: no redelivery asked' );

		ActionSchedulerRunner::run_pending( EventsPull::BASELINE_HOOK );

		$this->assertSame(
			array(
				'after'      => null,
				'connection' => self::OTHER_CONNECTION_ID,
			),
			get_option( EventsPull::CURSOR_OPTION )
		);
		$this->assertSame( 1, $baselines, 'the new connection\'s baseline ran' );
	}

	public function test_a_baseline_running_across_a_disconnect_sets_nothing(): void {
		$this->recorder->json( 200, self::page( array(), null ) );
		$baselines = 0;
		add_action(
			'pixonaut_wc_events_baseline_set',
			static function () use ( &$baselines ): void {
				++$baselines;
			}
		);
		$this->on_first_answer(
			function (): void {
				$this->store->clear();
				do_action( 'pixonaut_wc_connection_disconnected', true );
			}
		);

		do_action( EventsPull::BASELINE_HOOK );

		$this->assertFalse( get_option( EventsPull::CURSOR_OPTION ) );
		$this->assertSame( 0, $baselines );
	}

	public function test_a_cursor_of_another_connection_counts_as_none(): void {
		update_option(
			EventsPull::CURSOR_OPTION,
			array(
				'after'      => 'evt_kept',
				'connection' => self::OTHER_CONNECTION_ID,
			)
		);

		do_action( EventsPull::PULL_HOOK );

		$this->assertSame( array(), $this->recorder->requests );
		$this->assertCount( 1, ActionSchedulerRunner::pending( EventsPull::BASELINE_HOOK ) );
	}

	public function test_nothing_is_pulled_while_the_connection_is_paused(): void {
		update_option( EventsPull::CURSOR_OPTION, self::cursor( 'evt_kept' ) );
		$this->store->pause( 'site_url_changed' );

		do_action( EventsPull::PULL_HOOK );

		$this->assertSame( array(), $this->recorder->requests );
		$this->assertSame( self::cursor( 'evt_kept' ), get_option( EventsPull::CURSOR_OPTION ) );
	}

	public function test_a_pull_before_the_first_cursor_queues_it(): void {
		do_action( EventsPull::PULL_HOOK );

		$this->assertSame( array(), $this->recorder->requests );
		$this->assertCount( 1, ActionSchedulerRunner::pending( EventsPull::BASELINE_HOOK ) );
	}

	public function test_disconnecting_forgets_the_cursor_and_stops_pulling(): void {
		update_option( EventsPull::CURSOR_OPTION, self::cursor( 'evt_kept' ) );
		do_action( 'action_scheduler_init' );
		$this->assertCount( 1, ActionSchedulerRunner::pending( EventsPull::PULL_HOOK ) );

		do_action( 'pixonaut_wc_connection_disconnected', true );

		$this->assertFalse( get_option( EventsPull::CURSOR_OPTION ) );
		$this->assertSame( array(), ActionSchedulerRunner::pending( EventsPull::PULL_HOOK ) );
	}

	/**
	 * A processing order with one Pixonaut line at $state, ordered at ORDER_ID.
	 *
	 * @param string $state   The line's state.
	 * @param bool   $ordered Whether the line holds its printshop order.
	 */
	private function line( string $state, bool $ordered = true ): WC_Order_Item_Product {
		$order = new WC_Order();
		$item  = new WC_Order_Item_Product();
		$item->set_name( 'Personalised T-shirt' );
		$order->add_item( $item );
		$order->set_status( 'processing' );
		$order->save();

		$line = LineState::start( $item, array( 'front' ) );
		$line->set_ref( LineState::REF_TENANT_ID, FulfilmentAnswers::TENANT_ID );
		$line->set_ref( LineState::REF_EXTERNAL_REF, self::EXTERNAL_REF );
		if ( $ordered ) {
			$line->set_ref( LineState::REF_ORDER_ID, self::ORDER_ID );
			$line->set_ref( LineState::REF_ORDER_STATUS, 'submitted' );
		}
		$line->set_side_ref( 'front', LineState::SIDE_DESIGN_ID, DesignFixture::ID );
		$line->set_side_ref( 'front', LineState::SIDE_REVISION, 1 );
		$line->save();
		if ( LineState::COMMITTED !== $state ) {
			$line->transition( $state );
		}
		return $item;
	}

	/**
	 * An `order.status_changed` envelope.
	 *
	 * @param string                     $previous Previous status.
	 * @param string                     $status   New status.
	 * @param array<string, string>|null $tracking Tracking, for `shipped`.
	 * @param string                     $order_id The order.
	 * @return array{id: string, type: string, payload: array<string, mixed>, createdAt: int}
	 */
	private function status_event( string $previous, string $status, ?array $tracking = null, string $order_id = self::ORDER_ID ): array {
		++$this->sequence;
		$created = time() * 1000 + $this->sequence;
		$payload = array(
			'orderId'        => $order_id,
			'printId'        => self::PRINT_ID,
			'externalRef'    => self::EXTERNAL_REF,
			'previousStatus' => $previous,
			'status'         => $status,
			'via'            => 'integration',
		);
		if ( null !== $tracking ) {
			$payload['tracking'] = $tracking;
		}
		return array(
			'id'        => "{$created}-9a8b7c6d-5e4f-4a3b-8c2d-1e0f9a8b7c" . str_pad( (string) $this->sequence, 2, '0', STR_PAD_LEFT ),
			'type'      => 'order.status_changed',
			'payload'   => $payload,
			'createdAt' => $created,
		);
	}

	/**
	 * An `order.submitted` envelope for $order_id, a retry of ORDER_ID.
	 *
	 * @param string $order_id The order that replaced ORDER_ID.
	 * @return array{id: string, type: string, payload: array<string, mixed>, createdAt: int}
	 */
	private function retried_event( string $order_id ): array {
		++$this->sequence;
		$created = time() * 1000 + $this->sequence;
		return array(
			'id'        => "{$created}-9a8b7c6d-5e4f-4a3b-8c2d-1e0f9a8b7c" . str_pad( (string) $this->sequence, 2, '0', STR_PAD_LEFT ),
			'type'      => 'order.submitted',
			'payload'   => array(
				'orderId'          => $order_id,
				'printId'          => self::PRINT_ID,
				'printIds'         => array( self::PRINT_ID ),
				'adapter'          => 'custom',
				'quantity'         => 1,
				'externalRef'      => self::EXTERNAL_REF,
				'externalOrderRef' => null,
				'replacesOrderId'  => self::ORDER_ID,
			),
			'createdAt' => $created,
		);
	}

	/**
	 * `200` of `GET /v1/events`.
	 *
	 * @param list<array<string, mixed>> $events      The page's events.
	 * @param string|null                $next_cursor Its `nextCursor`.
	 * @return array<string, mixed>
	 */
	private static function page( array $events, ?string $next_cursor ): array {
		return array(
			'events'     => $events,
			'nextCursor' => $next_cursor,
		);
	}

	/**
	 * The store's endpoint in `GET /v1/webhooks`.
	 *
	 * @param int $failed Its deliveries failed for good.
	 * @return array<string, mixed>
	 */
	private static function endpoint( int $failed ): array {
		return array(
			'id'           => self::WEBHOOK_ID,
			'tenantId'     => FulfilmentAnswers::TENANT_ID,
			'url'          => 'http://example.org/wp-json/pixonaut/v1/webhook',
			'eventTypes'   => Endpoint::EVENT_TYPES,
			'active'       => true,
			'cursor'       => null,
			'createdAt'    => 1_800_000_000_000,
			'apiKeyId'     => null,
			'failing'      => $failed > 0,
			'reachable'    => true,
			'failedCount'  => $failed,
			'connectionId' => self::CONNECTION_ID,
		);
	}

	/**
	 * Delivers an envelope signed as Pixonaut signs it.
	 *
	 * @param array<string, mixed> $envelope The event.
	 */
	private function deliver( array $envelope ): \WP_REST_Response {
		$body      = (string) wp_json_encode( $envelope );
		$timestamp = (string) time();
		$request   = new WP_REST_Request( 'POST', '/pixonaut/v1/webhook' );
		$request->set_header( 'content-type', 'application/json' );
		$request->set_header( Signature::TIMESTAMP_HEADER, $timestamp );
		$request->set_header( Signature::SIGNATURE_HEADER, 'v1=' . hash_hmac( 'sha256', "{$timestamp}.{$body}", self::SECRET ) );
		$request->set_body( $body );
		return rest_do_request( $request );
	}

	/**
	 * @return list<int> The order items a read is queued for.
	 */
	private static function read_items(): array {
		$items = array();
		foreach ( ActionSchedulerRunner::pending( LineReader::ACTION ) as $id ) {
			$args    = \ActionScheduler::store()->fetch_action( $id )->get_args();
			$items[] = (int) $args['item_id'];
		}
		return $items;
	}

	/**
	 * Runs $change once, right after Pixonaut's next answer reaches the client.
	 *
	 * @param callable $change What happens meanwhile.
	 */
	private function on_first_answer( callable $change ): void {
		$ran = false;
		add_action(
			'pixonaut_wc_api_after_request',
			static function () use ( $change, &$ran ): void {
				if ( ! $ran ) {
					$ran = true;
					$change();
				}
			},
			10,
			0
		);
	}

	/**
	 * The cursor option as stored for the test's connection.
	 *
	 * @param string|null $after The id of the last event pulled.
	 * @return array{after: string|null, connection: string}
	 */
	private static function cursor( ?string $after ): array {
		return array(
			'after'      => $after,
			'connection' => self::CONNECTION_ID,
		);
	}

	/**
	 * @param string $connection_id Pixonaut's id for the connection.
	 */
	private static function connection( string $connection_id = self::CONNECTION_ID ): StoredConnection {
		return new StoredConnection(
			'5c3a1f6e-8b2d-4e7a-9c1b-000000000001',
			Connection::site_url(),
			time(),
			FulfilmentAnswers::TENANT_ID,
			'Acme',
			StoredConnection::LIVE,
			$connection_id,
			'ik_live_0123456789abcdef01234567',
			'0b1c2d3e-4f50-4a6b-8c7d-000000000005',
			'WooCommerce · Acme Shop',
			'pk_live_89abcdef0123456789abcdef',
			'0b1c2d3e-4f50-4a6b-8c7d-000000000006',
			array( 'http://example.org' ),
			self::WEBHOOK_ID,
			'http://example.org/wp-json/pixonaut/v1/webhook',
			self::SECRET
		);
	}
}
