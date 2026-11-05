<?php
/**
 * @package Pixonaut\WooCommerce
 */

declare(strict_types=1);

namespace Pixonaut\WooCommerce\Tests\Unit\Connection;

use Brain\Monkey\Actions;
use Brain\Monkey\Functions;
use Pixonaut\WooCommerce\Admin\Notices;
use Pixonaut\WooCommerce\Api\ApiError;
use Pixonaut\WooCommerce\Api\Client;
use Pixonaut\WooCommerce\Api\Connect;
use Pixonaut\WooCommerce\Api\Credential;
use Pixonaut\WooCommerce\Api\Deprecations;
use Pixonaut\WooCommerce\Api\Hosts;
use Pixonaut\WooCommerce\Api\Operations;
use Pixonaut\WooCommerce\Connection\Cipher;
use Pixonaut\WooCommerce\Connection\ConnectAttempts;
use Pixonaut\WooCommerce\Connection\ConnectFailed;
use Pixonaut\WooCommerce\Connection\Connection;
use Pixonaut\WooCommerce\Connection\ConnectionStore;
use Pixonaut\WooCommerce\Connection\Pkce;
use Pixonaut\WooCommerce\Templates;
use Pixonaut\WooCommerce\Tests\Support\ConnectionAnswers;
use Pixonaut\WooCommerce\Tests\Support\MemberKeys;
use Pixonaut\WooCommerce\Tests\Support\RecordingLogger;
use Pixonaut\WooCommerce\Tests\Support\ScriptedTransport;
use Pixonaut\WooCommerce\Tests\Unit\FakeOptions;
use Pixonaut\WooCommerce\Tests\Unit\FakeUserMeta;
use Pixonaut\WooCommerce\Tests\Unit\TestCase;

final class ConnectionTest extends TestCase {

	use FakeOptions;
	use FakeUserMeta;

	private const BASE_URL = 'http://pixonaut.test';

	private const HOME_URL = 'https://shop.acme.example';

	private const ADMIN_URL = 'https://shop.acme.example/wp-admin/';

	private const REST_URL = 'https://shop.acme.example/wp-json/';

	private const SETTINGS_URL = 'https://shop.acme.example/wp-admin/admin.php?page=wc-settings&tab=pixonaut';

	private const ADMIN = 1;

	private const NOW = 1_800_000_000;

	private const CODE = 'c_4f8a1d2e9b7c4a6f8e3d5b1a';

	private const SITES = array( 'a1b2c3d4-0000-4000-8000-000000000001', 'a1b2c3d4-0000-4000-8000-000000000002' );

	private const EXCHANGED = array(
		'tenantId'       => '0b1c2d3e-4f50-4a6b-8c7d-000000000003',
		'tenantName'     => 'Acme',
		'tenantSlug'     => ConnectionAnswers::TENANT_SLUG,
		'environment'    => 'sandbox',
		'connectionId'   => 'sc_0b1c2d3e-4f50-4a6b-8c7d-000000000004',
		'integrationKey' => array(
			'id'    => '0b1c2d3e-4f50-4a6b-8c7d-000000000005',
			'key'   => 'ik_test_0123456789abcdef01234567',
			'label' => 'WooCommerce · Acme Shop (shop.acme.example)',
		),
		'publishableKey' => array(
			'id'             => '0b1c2d3e-4f50-4a6b-8c7d-000000000006',
			'key'            => 'pk_test_89abcdef0123456789abcdef',
			'allowedOrigins' => array( self::HOME_URL ),
		),
		'webhook'        => array(
			'id'     => '0b1c2d3e-4f50-4a6b-8c7d-000000000007',
			'url'    => 'https://shop.acme.example/wp-json/pixonaut/v1/webhook',
			'secret' => 'whsec_0123456789abcdef0123456789abcdef',
		),
		'memberKey'      => MemberKeys::ANSWER,
		'reachable'      => true,
	);

	private ScriptedTransport $transport;

	private ConnectionStore $store;

	private RecordingLogger $logger;

	/**
	 * `home_url()`, without its trailing slash.
	 *
	 * @var string
	 */
	private string $home_url;

	/**
	 * `admin_url()`.
	 *
	 * @var string
	 */
	private string $admin_url;

	/**
	 * Writes to the connection option, in order.
	 *
	 * @var list<mixed>
	 */
	private array $connection_writes = array();

	protected function setUp(): void {
		parent::setUp();
		$this->fake_options();
		$this->fake_user_meta();
		$this->transport = new ScriptedTransport();
		$this->store     = new ConnectionStore( new Cipher( 'auth-key-and-salt' ) );
		$this->logger    = new RecordingLogger();
		$this->home_url  = self::HOME_URL;
		$this->admin_url = self::ADMIN_URL;

		$sites = self::SITES;
		Functions\when( 'wp_generate_uuid4' )->alias(
			static function () use ( &$sites ): string {
				return (string) array_shift( $sites );
			}
		);
		Functions\when( 'wp_json_encode' )->alias( static fn ( $value ) => json_encode( $value ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode
		Functions\when( 'home_url' )->alias( fn (): string => $this->home_url . '/' );
		Functions\when( 'admin_url' )->alias( fn ( string $path = '' ): string => $this->admin_url . $path );
		Functions\when( 'rest_url' )->justReturn( self::REST_URL );
		Functions\when( 'wp_parse_url' )->alias( static fn ( string $url, int $component = -1 ): mixed => parse_url( $url, $component ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.parse_url_parse_url
		Functions\when( 'add_query_arg' )->alias( static fn ( string $key, string $value, string $url ): string => $url . ( str_contains( $url, '?' ) ? '&' : '?' ) . $key . '=' . $value );
		Functions\when( 'untrailingslashit' )->alias( static fn ( string $value ): string => rtrim( $value, '/\\' ) );
		Functions\when( 'get_bloginfo' )->justReturn( 'Acme Shop' );
		Functions\when( 'wp_strip_all_tags' )->returnArg();
		Functions\when( '__' )->returnArg();
		Functions\when( 'esc_url' )->returnArg();
		Functions\when( 'update_option' )->alias(
			function ( string $name, mixed $value ): bool {
				if ( in_array( $name, $this->failing_options, true ) ) {
					return false;
				}
				if ( ConnectionStore::OPTION === $name ) {
					$this->connection_writes[] = $value;
				}
				$this->options[ $name ] = $value;
				return true;
			}
		);
	}

	private function connection(): Connection {
		$client = new Client( self::BASE_URL, Operations::plugin(), $this->store, $this->transport, new RecordingLogger(), new Deprecations() );
		return new Connection(
			$this->store,
			new ConnectAttempts(),
			new Connect( $client ),
			new Notices( new Templates( '/nowhere/' ) ),
			$this->logger,
			self::SETTINGS_URL,
			static fn (): int => self::NOW
		);
	}

	/**
	 * Starts a connect and answers it as the studio would on approval.
	 *
	 * @param Connection $connection The connection.
	 *
	 * @return array<string, string> The return's query.
	 */
	private function approve( Connection $connection ): array {
		parse_str( (string) parse_url( $connection->start( self::ADMIN ), PHP_URL_QUERY ), $sent ); // phpcs:ignore WordPress.WP.AlternativeFunctions.parse_url_parse_url
		$this->assertIsString( $sent['state'] );
		return array(
			'state' => $sent['state'],
			'code'  => self::CODE,
		);
	}

	public function test_start_sends_the_admin_to_the_consent_screen_with_a_s256_challenge(): void {
		$url = $this->connection()->start( self::ADMIN );

		$this->assertStringStartsWith( Hosts::STUDIO . '/connect?', $url );
		parse_str( (string) parse_url( $url, PHP_URL_QUERY ), $sent ); // phpcs:ignore WordPress.WP.AlternativeFunctions.parse_url_parse_url
		$this->assertSame( 'woocommerce', $sent['client'] );
		$this->assertSame( self::HOME_URL, $sent['site_url'] );
		$this->assertSame( self::ADMIN_URL . 'admin-post.php?action=pixonaut_wc_connect_return', $sent['redirect_uri'] );
		$this->assertSame( 'S256', $sent['code_challenge_method'] );
		$this->assertSame( 'Acme Shop', $sent['site_name'] );
		$this->assertSame( self::REST_URL, $sent['rest_url'] );
		$this->assertArrayNotHasKey( 'environment', $sent );
		$attempt = $this->user_meta[ self::ADMIN ][ ConnectAttempts::META ];
		$this->assertSame( Pkce::challenge( $attempt['verifier'] ), $sent['code_challenge'] );
	}

	public function test_start_offers_only_the_environment_the_admin_chose(): void {
		$connection = $this->connection();

		parse_str( (string) parse_url( $connection->start( self::ADMIN, 'sandbox' ), PHP_URL_QUERY ), $sandbox ); // phpcs:ignore WordPress.WP.AlternativeFunctions.parse_url_parse_url
		parse_str( (string) parse_url( $connection->start( self::ADMIN, 'staging' ), PHP_URL_QUERY ), $unknown ); // phpcs:ignore WordPress.WP.AlternativeFunctions.parse_url_parse_url

		$this->assertSame( 'sandbox', $sandbox['environment'] );
		$this->assertArrayNotHasKey( 'environment', $unknown );
	}

	public function test_start_refuses_an_admin_on_another_origin_than_the_site(): void {
		$this->admin_url = 'http://shop.acme.example/wp-admin/';

		$this->assertFalse( $this->connection()->can_connect() );
		try {
			$this->connection()->start( self::ADMIN );
			$this->fail( 'Connected with wp-admin on another origin.' );
		} catch ( ConnectFailed $e ) {
			$this->assertSame( ConnectFailed::ORIGIN_MISMATCH, $e->reason );
		}
		$this->assertSame( array(), $this->user_meta );
	}

	public function test_a_changed_site_url_pauses_the_connection_without_a_write(): void {
		$connection = $this->connection();
		$this->transport->json( 201, self::EXCHANGED );
		$connection->complete( self::ADMIN, $this->approve( $connection ) );

		$this->home_url = 'https://staging.acme.example';

		$this->assertSame( Connection::PAUSED_SITE_CHANGED, $connection->paused() );
		$this->assertNull( $this->store->secret( Credential::IntegrationKey ) );
		$this->assertCount( 1, $this->connection_writes );

		$this->home_url = self::HOME_URL;
		$this->assertNull( $connection->paused() );
		$this->assertSame( self::EXCHANGED['integrationKey']['key'], $this->store->secret( Credential::IntegrationKey ) );
	}

	public function test_keys_sealed_with_another_sites_salts_pause_the_connection(): void {
		$connection = $this->connection();
		$this->transport->json( 201, self::EXCHANGED );
		$connection->complete( self::ADMIN, $this->approve( $connection ) );

		$copy = new ConnectionStore( new Cipher( 'another-sites-salt' ) );

		$this->assertSame( ConnectionStore::PAUSED_SITE_CHANGED, $copy->paused() );
		$this->assertNull( $copy->secret( Credential::IntegrationKey ) );
	}

	public function test_nothing_stored_is_not_paused(): void {
		$this->assertNull( $this->connection()->paused() );
	}

	public function test_a_changed_site_raises_the_paused_notice_and_lifts_it_when_it_is_back(): void {
		Actions\expectDone( 'pixonaut_wc_connection_paused' )->once()->with( Connection::PAUSED_SITE_CHANGED );
		$connection = $this->connection();
		$notices    = new Notices( new Templates( '/nowhere/' ) );
		$this->transport->json( 201, self::EXCHANGED );
		$connection->complete( self::ADMIN, $this->approve( $connection ) );
		$this->home_url = 'https://staging.acme.example';

		$connection->sync_site_pause();
		$connection->sync_site_pause();

		$this->assertTrue( $notices->has( Connection::PAUSED_NOTICE ) );
		$this->assertStringContainsString( 'Site URL changed', $notices->all()[ Connection::PAUSED_NOTICE ]['message'] );

		$this->home_url = self::HOME_URL;
		$connection->sync_site_pause();
		$this->assertFalse( $notices->has( Connection::PAUSED_NOTICE ) );
	}

	public function test_a_copied_site_disconnects_without_revoking_the_original_sites_keys(): void {
		$connection = $this->connection();
		$this->transport->json( 201, self::EXCHANGED );
		$connection->complete( self::ADMIN, $this->approve( $connection ) );
		$this->home_url = 'https://staging.acme.example';

		$connection->disconnect();

		$this->assertCount( 1, $this->transport->sent );
		$this->assertNull( $connection->current() );
	}

	public function test_the_www_counterpart_is_the_same_origin_with_or_without_www(): void {
		$this->assertSame( 'https://www.shop.acme.example', Connection::www_origin() );
		$this->home_url = 'http://www.shop.acme.example:8080';
		$this->assertSame( 'http://shop.acme.example:8080', Connection::www_origin() );
		$this->home_url = 'http://127.0.0.1:8080';
		$this->assertNull( Connection::www_origin() );
		$this->home_url = 'http://[::1]:8080';
		$this->assertNull( Connection::www_origin() );
	}

	public function test_the_return_stores_everything_in_one_encrypted_write(): void {
		Actions\expectDone( 'pixonaut_wc_connection_connected' )->once();
		$connection = $this->connection();
		$query      = $this->approve( $connection );
		$this->transport->json( 201, self::EXCHANGED );

		$stored = $connection->complete( self::ADMIN, $query );

		$sent = json_decode( (string) $this->transport->sent[0]['body'], true );
		$this->assertSame( self::CODE, $sent['code'] );
		$this->assertSame( self::ADMIN_URL . 'admin-post.php?action=pixonaut_wc_connect_return', $sent['redirect_uri'] );
		$this->assertArrayNotHasKey( 'Authorization', $this->transport->sent[0]['headers'] );

		$this->assertCount( 1, $this->connection_writes );
		$written = (string) json_encode( $this->options ); // phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode
		foreach ( array( 'ik_test_', 'pk_test_', 'whsec_', self::EXCHANGED['connectionId'], self::SITES[0] ) as $secret ) {
			$this->assertStringNotContainsString( $secret, $written );
		}

		$this->assertSame( self::SITES[0], $stored->site );
		$this->assertSame( self::HOME_URL, $stored->home_url );
		$loaded = $this->store->load();
		$this->assertNotNull( $loaded );
		$this->assertSame( self::EXCHANGED['integrationKey']['key'], $loaded->integration_key );
		$this->assertSame( self::EXCHANGED['webhook']['secret'], $loaded->webhook_secret );
		$this->assertSame( 'sandbox', $loaded->environment );
		$this->assertSame( self::EXCHANGED['integrationKey']['key'], $this->store->secret( Credential::IntegrationKey ) );
	}

	public function test_a_reconnect_mints_a_new_site(): void {
		$connection = $this->connection();
		$this->transport->json( 201, self::EXCHANGED )->json( 201, self::EXCHANGED );

		$first  = $connection->complete( self::ADMIN, $this->approve( $connection ) );
		$second = $connection->complete( self::ADMIN, $this->approve( $connection ) );

		$this->assertSame( self::SITES, array( $first->site, $second->site ) );
		$this->assertSame( self::SITES[1], $this->store->load()?->site );
	}

	public function test_a_state_works_once(): void {
		$connection = $this->connection();
		$query      = $this->approve( $connection );
		$this->transport->json( 201, self::EXCHANGED );
		$connection->complete( self::ADMIN, $query );

		$this->assertConnectFails( ConnectFailed::INVALID_STATE, $connection, $query );
		$this->assertSame( 0, $this->transport->remaining() );
		$this->assertCount( 1, $this->transport->sent );
	}

	public function test_a_cancelled_connect_stores_nothing(): void {
		$connection = $this->connection();
		$query      = array(
			'state' => $this->approve( $connection )['state'],
			'error' => 'access_denied',
		);

		$this->assertConnectFails( ConnectFailed::ACCESS_DENIED, $connection, $query );
		$this->assertSame( array(), $this->transport->sent );
		$this->assertNull( $this->store->load() );
	}

	public function test_a_refused_code_keeps_the_connection_there_was(): void {
		$connection = $this->connection();
		$this->transport->json( 201, self::EXCHANGED )->json( 400, array( 'error' => 'invalid_grant' ) );
		$connection->complete( self::ADMIN, $this->approve( $connection ) );

		$this->assertConnectFails( ConnectFailed::EXCHANGE_FAILED, $connection, $this->approve( $connection ) );
		$this->assertSame( self::SITES[0], $this->store->load()?->site );
	}

	public function test_a_plan_at_its_store_limit_says_so_and_keeps_the_connection_there_was(): void {
		$connection = $this->connection();
		$this->transport->json( 201, self::EXCHANGED )->json(
			403,
			array(
				'error' => 'plan_limit_reached',
				'limit' => 'connectedStores',
				'max'   => 1,
				'plan'  => 'free',
			)
		);
		$connection->complete( self::ADMIN, $this->approve( $connection ) );

		$this->assertConnectFails( ConnectFailed::PLAN_LIMIT_REACHED, $connection, $this->approve( $connection ) );
		$this->assertSame( self::SITES[0], $this->store->load()?->site );
	}

	public function test_a_refused_code_is_logged_without_the_code_or_the_verifier(): void {
		$connection = $this->connection();
		$query      = $this->approve( $connection );
		$verifier   = $this->user_meta[ self::ADMIN ][ ConnectAttempts::META ]['verifier'];
		$this->transport->json( 400, array( 'error' => 'invalid_grant' ) );

		$this->assertConnectFails( ConnectFailed::EXCHANGE_FAILED, $connection, $query );

		$this->assertCount( 1, $this->logger->entries );
		$entry = $this->logger->entries[0];
		$this->assertSame( 'warning', $entry['level'] );
		$this->assertSame( 'invalid_grant', $entry['context']['error_code'] );
		$logged = (string) json_encode( $entry ); // phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode
		$this->assertStringNotContainsString( self::CODE, $logged );
		$this->assertStringNotContainsString( $verifier, $logged );
		$this->assertStringNotContainsString( $query['state'], $logged );
	}

	public function test_an_unreachable_pixonaut_on_disconnect_is_logged(): void {
		$connection = $this->connection();
		$this->transport->json( 201, self::EXCHANGED )->fail( 'cURL error 7' );
		$connection->complete( self::ADMIN, $this->approve( $connection ) );

		$connection->disconnect();

		$this->assertCount( 1, $this->logger->entries );
		$this->assertSame( 'transport_failed', $this->logger->entries[0]['context']['error_code'] );
		$this->assertStringNotContainsString( 'ik_test_', (string) json_encode( $this->logger->entries ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode
	}

	public function test_a_401_on_any_call_pauses_the_connection(): void {
		Actions\expectDone( 'pixonaut_wc_connection_paused' )->once()->with( Connection::PAUSED_UNAUTHORIZED );
		$connection = $this->connection();
		$connection->register();
		$this->assertNotFalse( has_action( 'pixonaut_wc_api_unauthorized', array( $connection, 'on_unauthorized' ) ) );
		Actions\expectDone( 'pixonaut_wc_api_unauthorized' )->whenHappen( array( $connection, 'on_unauthorized' ) );
		$this->transport->json( 201, self::EXCHANGED )->json( 401, array( 'error' => 'unauthorized' ) );
		$connection->complete( self::ADMIN, $this->approve( $connection ) );

		try {
			( new Connect( new Client( self::BASE_URL, Operations::plugin(), $this->store, $this->transport, new RecordingLogger(), new Deprecations() ) ) )->get();
			$this->fail( 'A revoked key was accepted.' );
		} catch ( ApiError $e ) {
			$this->assertSame( 401, $e->status );
		}

		$this->assertSame( Connection::PAUSED_UNAUTHORIZED, $connection->paused() );
		$this->assertNull( $this->store->secret( Credential::IntegrationKey ) );
		$this->assertTrue( ( new Notices( new Templates( '/nowhere/' ) ) )->has( Connection::PAUSED_NOTICE ) );
	}

	public function test_a_late_401_for_an_old_connection_leaves_the_new_one(): void {
		Actions\expectDone( 'pixonaut_wc_connection_paused' )->never();
		$connection = $this->connection();
		$this->transport->json( 201, self::EXCHANGED )->json( 201, self::EXCHANGED );
		$connection->complete( self::ADMIN, $this->approve( $connection ) );
		$this->assertNotNull( $this->store->secret( Credential::IntegrationKey ) );
		$connection->complete( self::ADMIN, $this->approve( $connection ) );

		// The request that sent the first connection's key is refused only now.
		$connection->on_unauthorized();

		$this->assertSame( self::SITES[1], $this->store->load()?->site );
		$this->assertNull( $connection->paused() );
	}

	public function test_connecting_again_lifts_the_pause(): void {
		$connection = $this->connection();
		$this->transport->json( 201, self::EXCHANGED )->json( 201, self::EXCHANGED );
		$connection->complete( self::ADMIN, $this->approve( $connection ) );
		$connection->pause( Connection::PAUSED_UNAUTHORIZED );

		$connection->complete( self::ADMIN, $this->approve( $connection ) );

		$this->assertNull( $connection->paused() );
		$this->assertFalse( ( new Notices( new Templates( '/nowhere/' ) ) )->has( Connection::PAUSED_NOTICE ) );
	}

	public function test_disconnect_revokes_and_forgets(): void {
		Actions\expectDone( 'pixonaut_wc_connection_disconnected' )->once()->with( true );
		$connection = $this->connection();
		$this->transport->json( 201, self::EXCHANGED )->respond( new \Pixonaut\WooCommerce\Api\Response( 204, array(), '' ) );
		$connection->complete( self::ADMIN, $this->approve( $connection ) );

		$this->assertTrue( $connection->disconnect() );

		$this->assertSame( 'DELETE', $this->transport->sent[1]['method'] );
		$this->assertSame( 'Bearer ' . self::EXCHANGED['integrationKey']['key'], $this->transport->sent[1]['headers']['Authorization'] );
		$this->assertNull( $connection->current() );
		$this->assertArrayNotHasKey( ConnectionStore::OPTION, $this->options );
	}

	public function test_disconnect_forgets_the_keys_when_pixonaut_is_unreachable(): void {
		$connection = $this->connection();
		$this->transport->json( 201, self::EXCHANGED )->fail( 'cURL error 7' );
		$connection->complete( self::ADMIN, $this->approve( $connection ) );

		$this->assertFalse( $connection->disconnect() );
		$this->assertNull( $connection->current() );
	}

	public function test_a_paused_connection_disconnects_without_calling_pixonaut(): void {
		$connection = $this->connection();
		$this->transport->json( 201, self::EXCHANGED );
		$connection->complete( self::ADMIN, $this->approve( $connection ) );
		$connection->pause( Connection::PAUSED_UNAUTHORIZED );

		$this->assertTrue( $connection->disconnect() );
		$this->assertCount( 1, $this->transport->sent );
		$this->assertNull( $connection->current() );
	}

	public function test_a_failed_save_is_a_failed_connect(): void {
		Actions\expectDone( 'pixonaut_wc_connection_connected' )->never();
		$connection = $this->connection();
		$this->transport->json( 201, self::EXCHANGED );
		$this->failing_options[] = ConnectionStore::OPTION;

		$this->assertConnectFails( ConnectFailed::STORAGE_FAILED, $connection, $this->approve( $connection ) );
		$this->assertNull( $connection->current() );
	}

	public function test_a_failed_pause_still_stops_this_request_sending(): void {
		$connection = $this->connection();
		$this->transport->json( 201, self::EXCHANGED );
		$connection->complete( self::ADMIN, $this->approve( $connection ) );
		$this->failing_options[] = ConnectionStore::OPTION;

		$connection->pause( Connection::PAUSED_UNAUTHORIZED );

		$this->assertNull( $this->store->secret( Credential::IntegrationKey ) );
		$this->assertSame( Connection::PAUSED_UNAUTHORIZED, $connection->paused() );
	}

	public function test_a_failed_wipe_is_not_reported_as_a_disconnect(): void {
		Actions\expectDone( 'pixonaut_wc_connection_disconnected' )->never();
		$connection = $this->connection();
		$this->transport->json( 201, self::EXCHANGED )->respond( new \Pixonaut\WooCommerce\Api\Response( 204, array(), '' ) );
		$connection->complete( self::ADMIN, $this->approve( $connection ) );
		$this->failing_options[] = ConnectionStore::OPTION;

		try {
			$connection->disconnect();
			$this->fail( 'A disconnect that kept the keys was reported.' );
		} catch ( ConnectFailed $e ) {
			$this->assertSame( ConnectFailed::DISCONNECT_FAILED, $e->reason );
		}
	}

	public function test_a_connect_waits_for_another_in_progress(): void {
		$connection = $this->connection();
		$query      = $this->approve( $connection );
		$this->options[ ConnectionStore::LOCK_OPTION ] = time();

		$this->assertConnectFails( ConnectFailed::BUSY, $connection, $query );
		$this->assertSame( array(), $this->transport->sent );
		$this->assertArrayHasKey( ConnectionStore::LOCK_OPTION, $this->options );
	}

	public function test_a_disconnect_waits_for_a_connect_in_progress(): void {
		$connection = $this->connection();
		$this->transport->json( 201, self::EXCHANGED );
		$connection->complete( self::ADMIN, $this->approve( $connection ) );
		$this->assertArrayNotHasKey( ConnectionStore::LOCK_OPTION, $this->options );
		$this->options[ ConnectionStore::LOCK_OPTION ] = time();

		try {
			$connection->disconnect();
			$this->fail( 'Disconnected while a connect was in progress.' );
		} catch ( ConnectFailed $e ) {
			$this->assertSame( ConnectFailed::BUSY, $e->reason );
		}
		$this->assertCount( 1, $this->transport->sent );
		$this->assertNotNull( $connection->current() );
	}

	public function test_a_lock_left_by_a_dead_request_is_taken_over(): void {
		$connection                                    = $this->connection();
		$this->options[ ConnectionStore::LOCK_OPTION ] = time() - ConnectionStore::LOCK_TTL_SECONDS - 1;
		$this->transport->json( 201, self::EXCHANGED );

		$connection->complete( self::ADMIN, $this->approve( $connection ) );

		$this->assertArrayNotHasKey( ConnectionStore::LOCK_OPTION, $this->options );
	}

	/**
	 * @param string                $reason     Expected reason.
	 * @param Connection            $connection The connection.
	 * @param array<string, string> $query      The return's query.
	 */
	private function assertConnectFails( string $reason, Connection $connection, array $query ): void {
		try {
			$connection->complete( self::ADMIN, $query );
			$this->fail( 'The return connected the store.' );
		} catch ( ConnectFailed $e ) {
			$this->assertSame( $reason, $e->reason );
		}
	}
}
