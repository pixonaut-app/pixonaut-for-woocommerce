<?php
/**
 * @package Pixonaut\WooCommerce
 */

declare(strict_types=1);

namespace Pixonaut\WooCommerce\Tests\Integration\Fulfilment;

use Pixonaut\WooCommerce\Api\Deprecations;
use Pixonaut\WooCommerce\Api\HookListener;
use Pixonaut\WooCommerce\Api\Pixonaut;
use Pixonaut\WooCommerce\Api\WcLogger;
use Pixonaut\WooCommerce\Cart\FieldValue;
use Pixonaut\WooCommerce\Connection\Cipher;
use Pixonaut\WooCommerce\Connection\Connection;
use Pixonaut\WooCommerce\Connection\ConnectionStore;
use Pixonaut\WooCommerce\Connection\StoredConnection;
use Pixonaut\WooCommerce\Fulfilment\DesignChanged;
use Pixonaut\WooCommerce\Fulfilment\FulfilmentJobs;
use Pixonaut\WooCommerce\Fulfilment\LineState;
use Pixonaut\WooCommerce\Fulfilment\NeedsAttention;
use Pixonaut\WooCommerce\Plugin;
use Pixonaut\WooCommerce\Tests\Support\DesignFixture;
use Pixonaut\WooCommerce\Tests\Support\FulfilmentAnswers;
use Pixonaut\WooCommerce\Tests\Support\HttpRecorder;
use Pixonaut\WooCommerce\Tests\Support\OpenApiAssert;
use Pixonaut\WooCommerce\Tests\Support\RecordingLogger;
use Pixonaut\WooCommerce\Vendor\Pixonaut\Sdk\Pixonaut as Sdk;
use Pixonaut\WooCommerce\Vendor\Pixonaut\Sdk\Webhooks\Event;
use Pixonaut\WooCommerce\Vendor\Psr\Log\LoggerInterface;
use WC_Order;
use WC_Order_Item_Product;
use WP_UnitTestCase;

/**
 * `design.edited`, `design.deleted` and `design.personal_data_erased` on a
 * line holding the design, against Pixonaut answered from a script.
 */
final class DesignChangedTest extends WP_UnitTestCase {

	/**
	 * Loopback, so the test bootstrap's host guard lets it through to the recorder.
	 */
	private const BASE_URL = 'http://127.0.0.1:9';

	private const SIDE = 'front';

	private const REVISION = 1;

	private const NEWER_REVISION = 2;

	/**
	 * When the event happened, epoch milliseconds.
	 */
	private const EVENT_AT_MS = 1_800_000_123_000;

	private HttpRecorder $recorder;

	private ConnectionStore $store;

	private DesignChanged $handler;

	private Sdk $client;

	private Plugin $plugin;

	/**
	 * Order items whose fulfilment job the handler queued.
	 *
	 * @var list<int>
	 */
	private array $scheduled = array();

	private int $sequence = 0;

	public function set_up(): void {
		parent::set_up();
		$this->recorder = ( new HttpRecorder( self::BASE_URL ) )->start();
		$this->store    = new ConnectionStore( Cipher::for_site() );
		$this->store->save( self::connection() );
		$client        = ( new Pixonaut( $this->store, new WcLogger(), new HookListener( new Deprecations() ), null, self::BASE_URL ) )->sdk();
		$plugin        = Plugin::boot( dirname( __DIR__, 4 ) . '/pixonaut-for-woocommerce.php' );
		$this->client  = $client;
		$this->plugin  = $plugin;
		$this->handler = new DesignChanged(
			$plugin->connection(),
			$client,
			function ( int $item_id ): bool {
				$this->scheduled[] = $item_id;
				return true;
			},
			new WcLogger()
		);
	}

	public function tear_down(): void {
		$this->recorder->stop();
		$this->store->clear();
		parent::tear_down();
	}

	public function test_an_edit_at_the_lines_revision_changes_nothing(): void {
		$item = $this->line( LineState::COMMITTED );
		$this->recorder->json( 200, DesignFixture::design() );

		$this->handler->handle( $this->event( DesignChanged::EDITED, array( 'revision' => self::REVISION ) ) );

		$this->assertSame( self::REVISION, LineState::of( $item )->side_ref( self::SIDE, LineState::SIDE_REVISION ) );
		$this->assertStringNotContainsString( 'Pixonaut', $this->notes( $item ) );
		$this->assertSame( array(), $this->scheduled );
	}

	public function test_a_newer_revision_before_approval_is_kept_and_approved_again(): void {
		$item = $this->line( LineState::COMMITTED );
		$this->recorder->json( 200, DesignFixture::design( array( 'revision' => self::NEWER_REVISION ) ) );

		$this->handler->handle( $this->event( DesignChanged::EDITED, array( 'revision' => self::NEWER_REVISION ) ) );

		$line = LineState::of( $item );
		$this->assertSame( LineState::COMMITTED, $line->state() );
		$this->assertSame( self::NEWER_REVISION, $line->side_ref( self::SIDE, LineState::SIDE_REVISION ) );
		$this->assertStringContainsString( 'goes to approval again', $this->notes( $item ) );
		$this->assertSame( array( $item->get_id() ), $this->scheduled );
	}

	public function test_a_newer_revision_queues_a_job_after_one_already_running(): void {
		$item = $this->line( LineState::COMMITTED );
		$args = array( 'item_id' => $item->get_id() );
		// A job that read the line before the edit, still running.
		$running = as_enqueue_async_action( FulfilmentJobs::ACTION, $args, FulfilmentJobs::GROUP, true );
		\ActionScheduler::store()->log_execution( $running );
		$handler = $this->handler_with( $this->plugin->fulfilment_jobs()->schedule_again( ... ), new WcLogger() );
		$this->recorder
			->json( 200, DesignFixture::design( array( 'revision' => self::NEWER_REVISION ) ) )
			->json( 200, DesignFixture::design( array( 'revision' => self::NEWER_REVISION + 1 ) ) );

		$handler->handle( $this->event( DesignChanged::EDITED, array( 'revision' => self::NEWER_REVISION ) ) );
		$handler->handle( $this->event( DesignChanged::EDITED, array( 'revision' => self::NEWER_REVISION + 1 ) ) );

		$pending = as_get_scheduled_actions(
			array(
				'hook'     => FulfilmentJobs::ACTION,
				'args'     => $args,
				'group'    => FulfilmentJobs::GROUP,
				'status'   => \ActionScheduler_Store::STATUS_PENDING,
				'per_page' => -1,
			),
			'ids'
		);
		$this->assertCount( 1, $pending, 'one job after the running one; a second edit adds none while it is pending' );
	}

	public function test_a_job_that_could_not_be_queued_is_logged(): void {
		$item    = $this->line( LineState::COMMITTED );
		$logger  = new RecordingLogger();
		$handler = $this->handler_with( static fn (): bool => false, $logger );
		$this->recorder->json( 200, DesignFixture::design( array( 'revision' => self::NEWER_REVISION ) ) );

		$handler->handle( $this->event( DesignChanged::EDITED, array( 'revision' => self::NEWER_REVISION ) ) );

		$this->assertSame( self::NEWER_REVISION, LineState::of( $item )->side_ref( self::SIDE, LineState::SIDE_REVISION ), 'the edit is kept' );
		$this->assertSame( 'error', $logger->entries[0]['level'] );
		$this->assertSame( array( 'item' => $item->get_id() ), $logger->entries[0]['context'] );
	}

	public function test_a_newer_revision_on_an_unpaid_order_waits_for_payment(): void {
		$item = $this->line( LineState::COMMITTED, 'pending' );
		$this->recorder->json( 200, DesignFixture::design( array( 'revision' => self::NEWER_REVISION ) ) );

		$this->handler->handle( $this->event( DesignChanged::EDITED, array( 'revision' => self::NEWER_REVISION ) ) );

		$this->assertSame( self::NEWER_REVISION, LineState::of( $item )->side_ref( self::SIDE, LineState::SIDE_REVISION ) );
		$this->assertSame( array(), $this->scheduled );
	}

	public function test_a_newer_revision_before_approval_drops_the_values_of_the_one_approved(): void {
		$item  = $this->line( LineState::COMMITTED );
		$state = LineState::of( $item );
		$state->set_side_ref(
			self::SIDE,
			LineState::SIDE_FIELDS,
			array(
				array(
					'label' => 'Name',
					'type'  => FieldValue::TEXT,
					'value' => 'Anna',
				),
			)
		);
		$this->assertTrue( $state->save() );
		$this->recorder->json( 200, DesignFixture::design( array( 'revision' => self::NEWER_REVISION ) ) );

		$this->handler->handle( $this->event( DesignChanged::EDITED, array( 'revision' => self::NEWER_REVISION ) ) );

		$this->assertSame( array(), LineState::of( $item )->side_fields( self::SIDE ) );
	}

	public function test_a_newer_revision_after_approval_needs_attention(): void {
		$item = $this->line( LineState::PRINTED );
		$this->recorder->json( 200, DesignFixture::design( array( 'revision' => self::NEWER_REVISION ) ) );

		$this->handler->handle( $this->event( DesignChanged::EDITED, array( 'revision' => self::NEWER_REVISION ) ) );

		$line = LineState::of( $item );
		$this->assertSame( LineState::NEEDS_ATTENTION, $line->state() );
		$this->assertSame( self::REVISION, $line->side_ref( self::SIDE, LineState::SIDE_REVISION ), 'the print stays of the revision approved' );
		$this->assertStringContainsString( 'after it was approved', $this->notes( $item ) );
		$this->assertSame( array(), $this->scheduled );
	}

	public function test_a_newer_revision_after_the_order_is_noted_once(): void {
		$item = $this->line( LineState::ORDERED );
		$this->recorder
			->json( 200, DesignFixture::design( array( 'revision' => self::NEWER_REVISION ) ) )
			->json( 200, DesignFixture::design( array( 'revision' => self::NEWER_REVISION ) ) );

		$this->handler->handle( $this->event( DesignChanged::EDITED, array( 'revision' => self::NEWER_REVISION ) ) );
		$this->handler->handle( $this->event( DesignChanged::EDITED, array( 'revision' => self::NEWER_REVISION ) ) );

		$line = LineState::of( $item );
		$this->assertSame( LineState::ORDERED, $line->state() );
		$this->assertSame( self::REVISION, $line->side_ref( self::SIDE, LineState::SIDE_REVISION ) );
		$this->assertSame( 1, substr_count( $this->notes( $item ), 'after it was ordered' ) );
	}

	public function test_an_edit_of_a_design_since_deleted_is_a_deletion(): void {
		$item = $this->line( LineState::COMMITTED );
		$this->recorder->json( 404, array( 'error' => 'not_found' ) );

		$this->handler->handle( $this->event( DesignChanged::EDITED, array( 'revision' => self::NEWER_REVISION ) ) );

		$this->assertSame( LineState::NEEDS_ATTENTION, LineState::of( $item )->state() );
	}

	public function test_a_deleted_design_makes_an_unfinished_line_need_attention(): void {
		$item = $this->line( LineState::AWAITING_CUSTOMER );

		$this->handler->handle( $this->event( DesignChanged::DELETED ) );
		$this->handler->handle( $this->event( DesignChanged::DELETED ) );

		$line = LineState::of( $item );
		$this->assertSame( LineState::NEEDS_ATTENTION, $line->state() );
		$this->assertSame( NeedsAttention::DESIGN_DELETED, $line->reason() );
		$this->assertSame( self::EVENT_AT_MS + 1, $line->side_ref( self::SIDE, LineState::SIDE_DELETED_AT ) );
		$this->assertSame( 1, substr_count( $this->notes( $item ), 'deleted in Pixonaut' ) );
	}

	public function test_a_deleted_design_of_a_finished_line_is_noted_only(): void {
		$item = $this->line( LineState::SHIPPED );

		$this->handler->handle( $this->event( DesignChanged::DELETED ) );

		$this->assertSame( LineState::SHIPPED, LineState::of( $item )->state() );
		$this->assertStringContainsString( 'deleted in Pixonaut', $this->notes( $item ) );
	}

	public function test_an_erased_design_is_marked_and_its_line_stays(): void {
		$item = $this->line( LineState::ORDERED );

		$this->handler->handle( $this->event( DesignChanged::ERASED ) );

		$line = LineState::of( $item );
		$this->assertSame( LineState::ORDERED, $line->state() );
		$this->assertSame( self::EVENT_AT_MS + 1, $line->side_ref( self::SIDE, LineState::SIDE_ERASED_AT ) );
		$this->assertStringNotContainsString( 'Pixonaut', $this->notes( $item ) );
	}

	public function test_a_line_of_another_workspace_is_left_alone(): void {
		$item = $this->line( LineState::COMMITTED, 'processing', 'tnt_other' );

		$this->handler->handle( $this->event( DesignChanged::DELETED ) );

		$this->assertSame( LineState::COMMITTED, LineState::of( $item )->state() );
	}

	public function test_another_design_is_not_matched(): void {
		$item = $this->line( LineState::COMMITTED );

		$this->handler->handle( $this->event( DesignChanged::DELETED, array( 'designId' => substr( DesignFixture::ID, 0, -1 ) ) ) );

		$this->assertSame( LineState::COMMITTED, LineState::of( $item )->state() );
	}

	/**
	 * A line at $state holding DesignFixture::ID on its side, at REVISION.
	 *
	 * @param string $state  The line's state.
	 * @param string $status The order's status.
	 * @param string $tenant The workspace the line was made under.
	 */
	private function line( string $state, string $status = 'processing', string $tenant = FulfilmentAnswers::TENANT_ID ): WC_Order_Item_Product {
		$order = new WC_Order();
		$item  = new WC_Order_Item_Product();
		$item->set_name( 'Personalised T-shirt' );
		$order->add_item( $item );
		$order->set_status( $status );
		$order->save();

		$line = LineState::start( $item, array( self::SIDE ) );
		$line->set_ref( LineState::REF_TENANT_ID, $tenant );
		$line->set_side_ref( self::SIDE, LineState::SIDE_DESIGN_ID, DesignFixture::ID );
		$line->set_side_ref( self::SIDE, LineState::SIDE_REVISION, self::REVISION );
		$line->save();
		if ( LineState::COMMITTED !== $state ) {
			$line->transition( $state );
		}
		return $item;
	}

	/**
	 * An event the spec describes, for DesignFixture::ID.
	 *
	 * @param string               $type    Its type.
	 * @param array<string, mixed> $payload Payload fields beside `designId`.
	 */
	private function event( string $type, array $payload = array() ): Event {
		++$this->sequence;
		$body = (string) wp_json_encode(
			array(
				'id'        => "evt_design_{$this->sequence}",
				'type'      => $type,
				'payload'   => array_merge( array( 'designId' => DesignFixture::ID ), $payload ),
				'createdAt' => self::EVENT_AT_MS + $this->sequence,
			)
		);
		OpenApiAssert::webhook( $type, $body );
		return Event::fromJson( $body );
	}

	/**
	 * The order's notes as one string.
	 *
	 * @param WC_Order_Item_Product $item A line of the order.
	 */
	private function notes( WC_Order_Item_Product $item ): string {
		$notes = wc_get_order_notes( array( 'order_id' => $item->get_order_id() ) );
		return implode( "\n", array_map( static fn ( $note ): string => (string) $note->content, $notes ) );
	}

	/**
	 * The handler with another way of queueing the line's job.
	 *
	 * @param \Closure        $schedule `fn(int $item_id): bool`.
	 * @param LoggerInterface $logger   Where a job not queued is logged.
	 */
	private function handler_with( \Closure $schedule, LoggerInterface $logger ): DesignChanged {
		return new DesignChanged( $this->plugin->connection(), $this->client, $schedule, $logger );
	}

	private static function connection(): StoredConnection {
		return new StoredConnection(
			'5c3a1f6e-8b2d-4e7a-9c1b-000000000001',
			Connection::site_url(),
			time(),
			FulfilmentAnswers::TENANT_ID,
			'Acme',
			StoredConnection::LIVE,
			'sc_0b1c2d3e-4f50-4a6b-8c7d-000000000004',
			'ik_live_0123456789abcdef01234567',
			'0b1c2d3e-4f50-4a6b-8c7d-000000000005',
			'WooCommerce · Acme Shop',
			'pk_live_89abcdef0123456789abcdef',
			'0b1c2d3e-4f50-4a6b-8c7d-000000000006',
			array( 'http://example.org' ),
			'0b1c2d3e-4f50-4a6b-8c7d-000000000007',
			'http://example.org/wp-json/pixonaut/v1/webhook',
			'whsec_0123456789abcdef0123456789abcdef'
		);
	}
}
