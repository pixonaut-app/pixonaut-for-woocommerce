<?php
/**
 * @package Pixonaut\WooCommerce
 */

declare(strict_types=1);

namespace Pixonaut\WooCommerce\Tests\Integration\Webhooks;

use Pixonaut\WooCommerce\Admin\ConnectionScreen;
use Pixonaut\WooCommerce\Admin\Notices;
use Pixonaut\WooCommerce\Api\Deprecations;
use Pixonaut\WooCommerce\Api\HookListener;
use Pixonaut\WooCommerce\Api\Pixonaut;
use Pixonaut\WooCommerce\Api\WcLogger;
use Pixonaut\WooCommerce\Connection\Cipher;
use Pixonaut\WooCommerce\Connection\ConnectAttempts;
use Pixonaut\WooCommerce\Connection\Connection;
use Pixonaut\WooCommerce\Connection\ConnectionStore;
use Pixonaut\WooCommerce\Plugin;
use Pixonaut\WooCommerce\Tests\Support\ConnectionAnswers;
use Pixonaut\WooCommerce\Tests\Support\HttpRecorder;
use Pixonaut\WooCommerce\Tests\Support\MemberKeys;
use Pixonaut\WooCommerce\Tests\Support\OpenApiAssert;
use Pixonaut\WooCommerce\Webhooks\Endpoint;
use Pixonaut\WooCommerce\Webhooks\Processor;
use WP_UnitTestCase;

final class EndpointTest extends WP_UnitTestCase {

	/**
	 * Loopback, so the test bootstrap's host guard lets it through to the recorder.
	 */
	private const BASE_URL = 'http://127.0.0.1:9';

	private const WEBHOOK_ID = '0b1c2d3e-4f50-4a6b-8c7d-000000000007';

	private const EXCHANGED = array(
		'tenantId'       => '0b1c2d3e-4f50-4a6b-8c7d-000000000003',
		'tenantName'     => 'Acme',
		'tenantSlug'     => ConnectionAnswers::TENANT_SLUG,
		'environment'    => 'sandbox',
		'connectionId'   => 'sc_0b1c2d3e-4f50-4a6b-8c7d-000000000004',
		'integrationKey' => array(
			'id'    => '0b1c2d3e-4f50-4a6b-8c7d-000000000005',
			'key'   => 'ik_test_0123456789abcdef01234567',
			'label' => 'WooCommerce · Acme Shop (example.org)',
		),
		'publishableKey' => array(
			'id'             => '0b1c2d3e-4f50-4a6b-8c7d-000000000006',
			'key'            => 'pk_test_89abcdef0123456789abcdef',
			'allowedOrigins' => array( 'http://example.org' ),
		),
		'webhook'        => array(
			'id'     => self::WEBHOOK_ID,
			'url'    => 'http://example.org/?rest_route=/pixonaut/v1/webhook',
			'secret' => 'whsec_0123456789abcdef0123456789abcdef',
		),
		'memberKey'      => MemberKeys::ANSWER,
		'reachable'      => true,
	);

	private HttpRecorder $recorder;

	private ConnectionStore $store;

	private Connection $connection;

	private Endpoint $endpoint;

	private int $admin;

	public function set_up(): void {
		parent::set_up();
		$plugin = Plugin::boot( dirname( __DIR__, 4 ) . '/pixonaut-for-woocommerce.php' );
		// The plugin's own endpoint would call the real API host; this test's calls the recorder.
		remove_action( 'pixonaut_wc_connection_connected', array( $plugin->webhook_endpoint(), 'on_connected' ), 10 );
		$this->recorder   = ( new HttpRecorder( self::BASE_URL ) )->start();
		$this->store      = new ConnectionStore( Cipher::for_site() );
		$client           = ( new Pixonaut( $this->store, new WcLogger(), new HookListener( new Deprecations() ), null, self::BASE_URL ) )->sdk();
		$this->connection = new Connection( $this->store, new ConnectAttempts(), $client, $plugin->notices(), new WcLogger(), ConnectionScreen::settings_url() );
		$this->endpoint   = new Endpoint( $this->connection, $client, new WcLogger() );
		$this->endpoint->register();
		$admin       = self::factory()->user->create( array( 'role' => 'administrator' ) );
		$this->admin = is_int( $admin ) ? $admin : 0;
	}

	public function tear_down(): void {
		$this->recorder->stop();
		$this->store->clear();
		delete_option( Endpoint::PING_OPTION );
		delete_option( Endpoint::EVENT_TYPES_OPTION );
		delete_option( Notices::OPTION );
		parent::tear_down();
	}

	/**
	 * Connects the store through a scripted exchange; what comes after it
	 * must be scripted first.
	 */
	private function connect(): void {
		$query = (string) wp_parse_url( $this->connection->start( $this->admin ), PHP_URL_QUERY );
		parse_str( $query, $sent );
		$this->assertIsString( $sent['state'] );
		$this->connection->complete(
			$this->admin,
			array(
				'state' => $sent['state'],
				'code'  => 'c_4f8a1d2e9b7c4a6f8e3d5b1a',
			)
		);
	}

	/**
	 * The endpoint as `GET /v1/webhooks` lists it.
	 *
	 * @param array<string, mixed> $changes Fields to change.
	 *
	 * @return array<string, mixed>
	 */
	private static function endpoint( array $changes = array() ): array {
		return $changes + array(
			'id'           => self::WEBHOOK_ID,
			'tenantId'     => self::EXCHANGED['tenantId'],
			'url'          => self::EXCHANGED['webhook']['url'],
			'eventTypes'   => Endpoint::EVENT_TYPES,
			'active'       => true,
			'cursor'       => null,
			'createdAt'    => 1790000000000,
			'apiKeyId'     => self::EXCHANGED['integrationKey']['id'],
			'failing'      => false,
			'reachable'    => true,
			'failedCount'  => 0,
			'connectionId' => self::EXCHANGED['connectionId'],
		);
	}

	public function test_after_connect_the_endpoint_receives_the_consumed_event_types_and_is_tested(): void {
		$this->recorder->json( 201, self::EXCHANGED )->json( 200, self::endpoint() )->json( 200, array( 'delivered' => true ) );

		$this->connect();

		$this->assertCount( 3, $this->recorder->requests );
		[ , $narrow, $ping ] = $this->recorder->requests;
		$this->assertSame( 'PATCH', $narrow['method'] );
		$this->assertStringEndsWith( '/v1/webhooks/' . self::WEBHOOK_ID, $narrow['url'] );
		$consumed = OpenApiAssert::consumed()['webhooks'];
		$expected = array_values( array_diff( $consumed, array( 'webhook.ping' ) ) );
		$sent     = json_decode( (string) $narrow['body'], true );
		$this->assertSame( array( 'eventTypes' => $expected ), $sent );
		$this->assertNotContains( 'design.committed', $sent['eventTypes'] );
		$this->assertSame( array(), preg_grep( '/^print\./', $sent['eventTypes'] ) );
		$this->assertSame( 'POST', $ping['method'] );
		$this->assertStringEndsWith( '/v1/webhooks/' . self::WEBHOOK_ID . '/ping', $ping['url'] );
		$this->assertTrue( $this->endpoint->last_ping()['delivered'] ?? null );
	}

	public function test_a_connection_made_before_the_list_changed_is_subscribed_by_the_daily_job(): void {
		$this->recorder->json( 201, self::EXCHANGED )->json( 200, self::endpoint() )->json( 200, array( 'delivered' => true ) );
		$this->connect();
		update_option( Endpoint::EVENT_TYPES_OPTION, array( 'order.status_changed' ), false );
		$this->recorder->json( 200, self::endpoint() );
		$sent = count( $this->recorder->requests );

		do_action( Processor::MAINTENANCE_HOOK );

		$this->assertCount( $sent + 1, $this->recorder->requests );
		$this->assertSame( array( 'eventTypes' => Endpoint::EVENT_TYPES ), json_decode( (string) $this->recorder->requests[ $sent ]['body'], true ) );
		$this->assertSame( Endpoint::EVENT_TYPES, get_option( Endpoint::EVENT_TYPES_OPTION ) );

		do_action( Processor::MAINTENANCE_HOOK );

		$this->assertCount( $sent + 1, $this->recorder->requests, 'nothing to change a second time' );
	}

	public function test_the_event_types_filter_narrows_what_is_sent(): void {
		add_filter( 'pixonaut_wc_webhooks_event_types', static fn (): array => array( 'order.status_changed' ) );
		$this->recorder->json( 201, self::EXCHANGED )->json( 200, self::endpoint() )->json( 200, array( 'delivered' => true ) );

		$this->connect();

		$this->assertSame( array( 'eventTypes' => array( 'order.status_changed' ) ), json_decode( (string) $this->recorder->requests[1]['body'], true ) );
	}

	public function test_a_refused_narrowing_still_tests_the_endpoint_and_keeps_why_a_ping_failed(): void {
		$this->recorder->json( 201, self::EXCHANGED )
			->json(
				400,
				array(
					'error' => 'invalid_event_type',
					'type'  => 'order.status_changed',
				)
			)
			->json(
				200,
				array(
					'delivered' => false,
					'error'     => 'The endpoint answered 404.',
				)
			);

		$this->connect();

		$this->assertNotNull( $this->connection->current() );
		$last = $this->endpoint->last_ping();
		$this->assertNotNull( $last );
		$this->assertFalse( $last['delivered'] );
		$this->assertSame( 'The endpoint answered 404.', $last['error'] );
	}

	public function test_health_reads_the_stores_endpoint_from_the_list(): void {
		$this->recorder->json( 201, self::EXCHANGED )->json( 200, self::endpoint() )->json( 200, array( 'delivered' => true ) );
		$this->connect();
		$stored = $this->connection->current();
		$this->assertNotNull( $stored );
		$this->recorder->json(
			200,
			array(
				self::endpoint(
					array(
						'active'      => false,
						'failing'     => true,
						'failedCount' => 3,
					)
				),
			)
		);

		$this->assertSame(
			array(
				'active'       => false,
				'failing'      => true,
				'failed_count' => 3,
				'url'          => self::EXCHANGED['webhook']['url'],
			),
			$this->endpoint->health( $stored )
		);
	}

	public function test_disconnecting_forgets_the_last_ping(): void {
		$this->recorder->json( 201, self::EXCHANGED )->json( 200, self::endpoint() )->json( 200, array( 'delivered' => true ) )->raw( 204, '', '' );
		$this->connect();

		$this->connection->disconnect();

		$this->assertNull( $this->endpoint->last_ping() );
	}
}
