<?php
/**
 * @package Pixonaut\WooCommerce
 */

declare(strict_types=1);

namespace Pixonaut\WooCommerce\Tests\Integration\Connection;

use Pixonaut\WooCommerce\Admin\ConnectionScreen;
use Pixonaut\WooCommerce\Admin\Notices;
use Pixonaut\WooCommerce\Api\ApiError;
use Pixonaut\WooCommerce\Api\Client;
use Pixonaut\WooCommerce\Api\Connect;
use Pixonaut\WooCommerce\Api\Credential;
use Pixonaut\WooCommerce\Api\Deprecations;
use Pixonaut\WooCommerce\Api\Hosts;
use Pixonaut\WooCommerce\Api\Operations;
use Pixonaut\WooCommerce\Api\Webhooks;
use Pixonaut\WooCommerce\Api\WcLogger;
use Pixonaut\WooCommerce\Api\WpHttpTransport;
use Pixonaut\WooCommerce\Connection\Cipher;
use Pixonaut\WooCommerce\Connection\ConnectAttempts;
use Pixonaut\WooCommerce\Connection\Connection;
use Pixonaut\WooCommerce\Connection\ConnectionStore;
use Pixonaut\WooCommerce\Connection\StoreAccess;
use Pixonaut\WooCommerce\Connection\UninstallRevoke;
use Pixonaut\WooCommerce\Connection\WorkspaceSettings;
use Pixonaut\WooCommerce\Plugin;
use Pixonaut\WooCommerce\Schema;
use Pixonaut\WooCommerce\Tests\Support\ConnectionAnswers;
use Pixonaut\WooCommerce\Tests\Support\HttpRecorder;
use Pixonaut\WooCommerce\Tests\Support\MemberKeys;
use Pixonaut\WooCommerce\Webhooks\Endpoint;
use WP_UnitTestCase;

final class ConnectionTest extends WP_UnitTestCase {

	/**
	 * Loopback, so the test bootstrap's host guard lets it through to the recorder.
	 */
	private const BASE_URL = 'http://127.0.0.1:9';

	private const CODE = 'c_4f8a1d2e9b7c4a6f8e3d5b1a';

	private const EXCHANGED = array(
		'tenantId'       => '0b1c2d3e-4f50-4a6b-8c7d-000000000003',
		'tenantName'     => 'Acme',
		'tenantSlug'     => ConnectionAnswers::TENANT_SLUG,
		'environment'    => 'live',
		'connectionId'   => 'sc_0b1c2d3e-4f50-4a6b-8c7d-000000000004',
		'integrationKey' => array(
			'id'    => '0b1c2d3e-4f50-4a6b-8c7d-000000000005',
			'key'   => 'ik_live_0123456789abcdef01234567',
			'label' => 'WooCommerce · Acme Shop (shop.acme.example)',
		),
		'publishableKey' => array(
			'id'             => '0b1c2d3e-4f50-4a6b-8c7d-000000000006',
			'key'            => 'pk_live_89abcdef0123456789abcdef',
			'allowedOrigins' => array( 'http://example.org' ),
		),
		'webhook'        => array(
			'id'     => '0b1c2d3e-4f50-4a6b-8c7d-000000000007',
			'url'    => 'http://example.org/wp-json/pixonaut/v1/webhook',
			'secret' => 'whsec_0123456789abcdef0123456789abcdef',
		),
		'memberKey'      => MemberKeys::ANSWER,
		'reachable'      => true,
	);

	private HttpRecorder $recorder;

	private ConnectionStore $store;

	private Connection $connection;

	private int $admin;

	public function set_up(): void {
		parent::set_up();
		// Over the e2e support plugin's plain permalinks, which share this site.
		add_filter( 'pre_option_permalink_structure', static fn (): string => '/%postname%/', PHP_INT_MAX );
		$this->recorder = ( new HttpRecorder( self::BASE_URL ) )->start();
		$this->store    = new ConnectionStore( Cipher::for_site() );
		$client         = new Client( self::BASE_URL, Operations::plugin(), $this->store, new WpHttpTransport(), new WcLogger(), new Deprecations() );
		$plugin         = Plugin::boot( dirname( __DIR__, 4 ) . '/pixonaut-for-woocommerce.php' );
		// The plugin's own endpoint would call the real API host after a connect.
		remove_action( 'pixonaut_wc_connection_connected', array( $plugin->webhook_endpoint(), 'on_connected' ), 10 );
		// Not registered: the plugin's own connection already pauses on the same option.
		$this->connection = new Connection( $this->store, new ConnectAttempts(), new Connect( $client ), $plugin->notices(), new WcLogger(), ConnectionScreen::settings_url() );
		$admin            = self::factory()->user->create( array( 'role' => 'administrator' ) );
		$this->admin      = is_int( $admin ) ? $admin : 0;
	}

	public function tear_down(): void {
		$this->recorder->stop();
		$this->store->clear();
		// The plugin's store outlives this test: a 401 it answered must not pause the next one's.
		Plugin::boot( dirname( __DIR__, 4 ) . '/pixonaut-for-woocommerce.php' )->connection_store()->clear();
		delete_option( Notices::OPTION );
		parent::tear_down();
	}

	/**
	 * Starts a connect and answers it as the studio would on approval.
	 *
	 * @return array<string, string> The return's query.
	 */
	private function approve(): array {
		$query = (string) wp_parse_url( $this->connection->start( $this->admin ), PHP_URL_QUERY );
		parse_str( $query, $sent );
		$this->assertIsString( $sent['state'] );
		return array(
			'state' => $sent['state'],
			'code'  => self::CODE,
		);
	}

	public function test_a_connect_with_the_switch_on_hands_over_the_credential_once_connected(): void {
		$client     = new Client( self::BASE_URL, Operations::plugin(), $this->store, new WpHttpTransport(), new WcLogger(), new Deprecations() );
		$access     = new StoreAccess( $this->store, new Connect( $client ), new WcLogger() );
		$connection = new Connection( $this->store, new ConnectAttempts(), new Connect( $client ), Plugin::boot( dirname( __DIR__, 4 ) . '/pixonaut-for-woocommerce.php' )->notices(), new WcLogger(), ConnectionScreen::settings_url(), null, $access );
		parse_str( (string) wp_parse_url( $connection->start( $this->admin, '', true ), PHP_URL_QUERY ), $sent );
		$this->assertIsString( $sent['state'] );
		$this->recorder->json( 201, self::EXCHANGED )->raw( 204, '', 'application/json' );

		$connection->complete(
			$this->admin,
			array(
				'state' => $sent['state'],
				'code'  => self::CODE,
			)
		);

		$this->assertStringEndsWith( '/v1/connect/store-access', $this->recorder->requests[1]['url'] );
		$this->assertTrue( $access->is_on() );
		delete_option( StoreAccess::OPTION );
	}

	public function test_a_connect_with_the_switch_off_leaves_it_off(): void {
		$client     = new Client( self::BASE_URL, Operations::plugin(), $this->store, new WpHttpTransport(), new WcLogger(), new Deprecations() );
		$access     = new StoreAccess( $this->store, new Connect( $client ), new WcLogger() );
		$connection = new Connection( $this->store, new ConnectAttempts(), new Connect( $client ), Plugin::boot( dirname( __DIR__, 4 ) . '/pixonaut-for-woocommerce.php' )->notices(), new WcLogger(), ConnectionScreen::settings_url(), null, $access );
		parse_str( (string) wp_parse_url( $connection->start( $this->admin ), PHP_URL_QUERY ), $sent );
		$this->assertIsString( $sent['state'] );
		$this->recorder->json( 201, self::EXCHANGED );

		$connection->complete(
			$this->admin,
			array(
				'state' => $sent['state'],
				'code'  => self::CODE,
			)
		);

		$this->assertCount( 1, $this->recorder->requests );
		$this->assertFalse( $access->is_on() );
	}

	public function test_the_return_stores_the_connection_in_one_write_never_in_the_clear(): void {
		$writes = 0;
		$count  = static function () use ( &$writes ): void {
			++$writes;
		};
		add_action( 'add_option_' . ConnectionStore::OPTION, $count );
		add_action( 'update_option_' . ConnectionStore::OPTION, $count );
		$query = $this->approve();
		$this->recorder->json( 201, self::EXCHANGED );

		$this->connection->complete( $this->admin, $query );

		$this->assertSame( 1, $writes );
		global $wpdb;
		$options = implode( "\n", $wpdb->get_col( "SELECT option_value FROM {$wpdb->options}" ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$meta    = implode( "\n", $wpdb->get_col( "SELECT meta_value FROM {$wpdb->usermeta}" ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		foreach ( array( self::EXCHANGED['integrationKey']['key'], self::EXCHANGED['publishableKey']['key'], self::EXCHANGED['webhook']['secret'] ) as $secret ) {
			$this->assertStringNotContainsString( $secret, $options );
			$this->assertStringNotContainsString( $secret, $meta );
		}
		$this->assertSame( '', get_user_meta( $this->admin, Schema::CONNECT_ATTEMPT_META, true ) );
		$this->assertSame( self::EXCHANGED['integrationKey']['key'], $this->store->secret( Credential::IntegrationKey ) );
		$this->assertSame( untrailingslashit( home_url() ), $this->store->load()?->home_url );
	}

	public function test_the_connection_keeps_the_workspaces_slug_for_the_studio_links(): void {
		$query = $this->approve();
		$this->recorder->json( 201, self::EXCHANGED );

		$this->connection->complete( $this->admin, $query );

		$this->assertSame( ConnectionAnswers::TENANT_SLUG, $this->store->load()?->tenant_slug );
		$this->assertSame( ConnectionAnswers::TENANT_SLUG, WorkspaceSettings::tenant_slug() );
	}

	public function test_a_site_name_with_an_ampersand_is_sent_as_text(): void {
		update_option( 'blogname', 'Acme & Co' );

		parse_str( (string) wp_parse_url( $this->connection->start( $this->admin ), PHP_URL_QUERY ), $sent );

		$this->assertSame( 'Acme & Co', $sent['site_name'] );
	}

	public function test_a_401_on_any_call_pauses_the_connection_with_a_notice(): void {
		$this->recorder->json( 201, self::EXCHANGED );
		$this->connection->complete( $this->admin, $this->approve() );
		$this->recorder->json( 401, array( 'error' => 'unauthorized' ) );
		// The plugin's store hands out the key, as in production: its connection pauses the one whose key was refused.
		$plugin_store = Plugin::boot( dirname( __DIR__, 4 ) . '/pixonaut-for-woocommerce.php' )->connection_store();
		$client       = new Client( self::BASE_URL, Operations::plugin(), $plugin_store, new WpHttpTransport(), new WcLogger(), new Deprecations() );

		try {
			( new Connect( $client ) )->get();
			$this->fail( 'A revoked key was accepted.' );
		} catch ( ApiError $e ) {
			$this->assertSame( 401, $e->status );
		}

		$this->assertSame( Connection::PAUSED_UNAUTHORIZED, $this->store->paused() );
		$this->assertNull( $this->store->secret( Credential::IntegrationKey ) );
		$this->assertTrue( Plugin::boot( dirname( __DIR__, 4 ) . '/pixonaut-for-woocommerce.php' )->notices()->has( Connection::PAUSED_NOTICE ) );

		// Paused: nothing more reaches Pixonaut.
		try {
			( new Connect( $client ) )->get();
			$this->fail( 'A paused connection sent a request.' );
		} catch ( ApiError $e ) {
			$this->assertSame( 0, $e->status );
		}
		$this->assertCount( 2, $this->recorder->requests );
	}

	public function test_a_copied_site_makes_no_call_until_it_is_connected(): void {
		$this->recorder->json( 201, self::EXCHANGED );
		$this->connection->complete( $this->admin, $this->approve() );
		$plugin = Plugin::boot( dirname( __DIR__, 4 ) . '/pixonaut-for-woocommerce.php' );
		// A clone of the database, served from another address.
		add_filter( 'home_url', static fn ( string $url ): string => (string) preg_replace( '#^https?://[^/]+#', 'https://staging.example.test', $url ) );
		$client = new Client( self::BASE_URL, Operations::plugin(), $this->store, new WpHttpTransport(), new WcLogger(), new Deprecations() );

		try {
			( new Connect( $client ) )->get();
			$this->fail( 'A copied site sent a request.' );
		} catch ( ApiError $e ) {
			$this->assertSame( 'not_connected', $e->error_code );
		}
		$card = ( new ConnectionScreen( $this->connection, new Connect( $client ), $plugin->webhook_endpoint(), new Deprecations(), $plugin->templates(), ConnectionScreen::settings_url() ) )->card();
		$this->assertSame( 'paused', $card['state'] );
		$this->assertSame( Connection::PAUSED_SITE_CHANGED, $card['paused_reason'] );
		// Any request reading the option finds it paused, as the fulfiller and the storefront do.
		$this->assertSame( Connection::PAUSED_SITE_CHANGED, ( new ConnectionStore( Cipher::for_site() ) )->paused() );
		$this->connection->sync_site_pause();
		$this->assertTrue( $plugin->notices()->has( Connection::PAUSED_NOTICE ) );

		$this->connection->disconnect();

		$this->assertCount( 1, $this->recorder->requests );
	}

	public function test_clearing_the_store_lifts_the_pause_this_request_made(): void {
		$this->recorder->json( 201, self::EXCHANGED );
		$this->connection->complete( $this->admin, $this->approve() );
		$this->store->pause( Connection::PAUSED_UNAUTHORIZED );
		$this->assertSame( Connection::PAUSED_UNAUTHORIZED, $this->store->paused() );

		$this->assertTrue( $this->store->clear() );

		$this->assertNull( $this->store->paused() );
	}

	public function test_the_card_says_a_store_pixonaut_cannot_reach_gets_no_webhooks_and_how_to_change_the_address(): void {
		$card = $this->card_of_a_store_that_reads( false );

		$this->assertFalse( $card['webhook_reachable'] );
		$this->assertSame( self::EXCHANGED['webhook']['url'], $card['webhook']['url'] ?? null );
		$html = $this->rendered( $card );
		$this->assertStringContainsString( 'data-state="unreachable"', $html );
		$this->assertStringContainsString( 'pixonaut-webhook-unreachable', $html );
		$this->assertStringContainsString( 'under Webhooks in the Pixonaut studio', $html );
		$this->assertStringContainsString( self::EXCHANGED['webhook']['url'], $html );
		$this->assertStringContainsString( ConnectionScreen::TUNNEL_DOCS_ANCHOR, $html );
		$this->assertStringNotContainsString( 'data-state="failing"', $html, 'not a failure to retry' );
	}

	public function test_the_card_of_an_unreachable_store_whose_endpoint_is_off_asks_for_both(): void {
		$html = $this->rendered( $this->card_of_a_store_that_reads( false, false ) );

		$this->assertStringContainsString( 'data-state="inactive"', $html );
		$this->assertStringContainsString( 'pixonaut-webhook-inactive', $html );
		$this->assertStringContainsString( 'pixonaut-webhook-unreachable', $html );
	}

	public function test_the_card_says_nothing_of_reach_for_a_store_pixonaut_reaches(): void {
		$card = $this->card_of_a_store_that_reads( true );

		$this->assertTrue( $card['webhook_reachable'] );
		$this->assertStringNotContainsString( 'pixonaut-webhook-unreachable', $this->rendered( $card ) );
	}

	/**
	 * The card of a connected store, with Pixonaut's answers to its reads.
	 *
	 * @param bool $reachable Whether `GET /v1/connect` reads the store as reachable.
	 * @param bool $active    Whether the endpoint is switched on.
	 * @return array<string, mixed>
	 */
	private function card_of_a_store_that_reads( bool $reachable, bool $active = true ): array {
		$this->recorder->json( 201, self::EXCHANGED );
		$this->connection->complete( $this->admin, $this->approve() );
		$this->recorder->json( 200, ConnectionAnswers::connection( $reachable ) );
		$this->recorder->json(
			200,
			array(
				array(
					'id'           => self::EXCHANGED['webhook']['id'],
					'tenantId'     => self::EXCHANGED['tenantId'],
					'url'          => self::EXCHANGED['webhook']['url'],
					'eventTypes'   => array(),
					'active'       => $active,
					'cursor'       => null,
					'createdAt'    => 1_800_000_000_000,
					'apiKeyId'     => null,
					'failing'      => false,
					'reachable'    => true,
					'failedCount'  => 0,
					'connectionId' => self::EXCHANGED['connectionId'],
				),
			)
		);
		$plugin = Plugin::boot( dirname( __DIR__, 4 ) . '/pixonaut-for-woocommerce.php' );
		$client = new Client( self::BASE_URL, Operations::plugin(), $this->store, new WpHttpTransport(), new WcLogger(), new Deprecations() );
		$screen = new ConnectionScreen( $this->connection, new Connect( $client ), new Endpoint( $this->connection, new Webhooks( $client ), new WcLogger() ), new Deprecations(), $plugin->templates(), ConnectionScreen::settings_url() );
		return $screen->card();
	}

	/**
	 * The card as the Connection tab renders it.
	 *
	 * @param array<string, mixed> $card What the card shows.
	 */
	private function rendered( array $card ): string {
		ob_start();
		Plugin::boot( dirname( __DIR__, 4 ) . '/pixonaut-for-woocommerce.php' )->templates()->render( 'admin/connection-card.php', $card );
		return (string) ob_get_clean();
	}

	public function test_a_401_on_the_webhook_health_shows_the_card_paused(): void {
		$this->recorder->json( 201, self::EXCHANGED );
		$this->connection->complete( $this->admin, $this->approve() );
		$this->recorder->json(
			200,
			array(
				'allowedOrigins'    => self::EXCHANGED['publishableKey']['allowedOrigins'],
				'connectionId'      => self::EXCHANGED['connectionId'],
				'environment'       => self::EXCHANGED['environment'],
				'name'              => self::EXCHANGED['integrationKey']['label'],
				'siteUrl'           => untrailingslashit( home_url() ),
				'tenantId'          => self::EXCHANGED['tenantId'],
				'tenantName'        => self::EXCHANGED['tenantName'],
				'tenantSlug'        => ConnectionAnswers::TENANT_SLUG,
				'webhookEndpointId' => self::EXCHANGED['webhook']['id'],
			) + ConnectionAnswers::WORKSPACE_SETTINGS
		);
		$this->recorder->json( 401, array( 'error' => 'unauthorized' ) );
		$plugin = Plugin::boot( dirname( __DIR__, 4 ) . '/pixonaut-for-woocommerce.php' );
		// A store that hands out the key and a connection that pauses on its refusal, as the plugin's own do.
		$store      = new ConnectionStore( Cipher::for_site() );
		$client     = new Client( self::BASE_URL, Operations::plugin(), $store, new WpHttpTransport(), new WcLogger(), new Deprecations() );
		$connection = new Connection( $store, new ConnectAttempts(), new Connect( $client ), $plugin->notices(), new WcLogger(), ConnectionScreen::settings_url() );
		add_action( 'pixonaut_wc_api_unauthorized', array( $connection, 'on_unauthorized' ), 10, 0 );
		$endpoint = new Endpoint( $connection, new Webhooks( $client ), new WcLogger() );

		$card = ( new ConnectionScreen( $connection, new Connect( $client ), $endpoint, new Deprecations(), $plugin->templates(), ConnectionScreen::settings_url() ) )->card();

		$this->assertCount( 3, $this->recorder->requests );
		$this->assertSame( 'paused', $card['state'] );
		$this->assertSame( Connection::PAUSED_UNAUTHORIZED, $card['paused_reason'] );
		$this->assertNull( $card['webhook'] );
		$this->assertSame( '', $card['ping_url'] );
		$this->assertSame( '', $card['www_origin'] );
	}

	public function test_disconnect_revokes_the_keys_and_forgets_them(): void {
		$this->recorder->json( 201, self::EXCHANGED );
		$this->connection->complete( $this->admin, $this->approve() );
		$this->recorder->raw( 204, '', '' );
		$revoked = null;
		add_action(
			'pixonaut_wc_connection_disconnected',
			static function ( bool $result ) use ( &$revoked ): void {
				$revoked = $result;
			}
		);

		$this->assertTrue( $this->connection->disconnect() );

		$this->assertTrue( $revoked );
		$this->assertSame( 'DELETE', $this->recorder->requests[1]['method'] );
		$this->assertSame( 'Bearer ' . self::EXCHANGED['integrationKey']['key'], $this->recorder->requests[1]['headers']['Authorization'] );
		$this->assertFalse( get_option( ConnectionStore::OPTION ) );
	}

	public function test_uninstall_removes_the_connection_and_connects_in_progress(): void {
		$this->recorder->json( 201, self::EXCHANGED );
		$this->connection->complete( $this->admin, $this->approve() );
		$this->connection->start( $this->admin );
		$this->store->lock();

		if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
			define( 'WP_UNINSTALL_PLUGIN', 'pixonaut-for-woocommerce/pixonaut-for-woocommerce.php' );
		}
		require dirname( __DIR__, 4 ) . '/uninstall.php';

		$this->assertFalse( get_option( ConnectionStore::OPTION ) );
		$this->assertFalse( get_option( ConnectionStore::LOCK_OPTION ) );
		$this->assertSame( '', get_user_meta( $this->admin, Schema::CONNECT_ATTEMPT_META, true ) );
	}

	public function test_uninstall_revokes_the_connection_at_pixonaut_with_a_short_timeout(): void {
		$this->recorder->json( 201, self::EXCHANGED );
		$this->connection->complete( $this->admin, $this->approve() );
		$this->recorder->raw( 204, '', 'application/json' );

		$this->uninstall();

		$revoke = end( $this->recorder->requests );
		$this->assertIsArray( $revoke );
		$this->assertSame( 'DELETE', $revoke['method'] );
		$this->assertSame( self::BASE_URL . '/v1/connect', $revoke['url'] );
		$this->assertSame( 'Bearer ' . self::EXCHANGED['integrationKey']['key'], $revoke['headers']['Authorization'] );
		$this->assertSame( (float) UninstallRevoke::TIMEOUT_SECONDS, $revoke['timeout'] );
		$this->assertFalse( get_option( ConnectionStore::OPTION ) );
	}

	public function test_uninstall_completes_when_pixonaut_times_out(): void {
		$this->recorder->json( 201, self::EXCHANGED );
		$this->connection->complete( $this->admin, $this->approve() );
		$this->recorder->fail();

		$this->uninstall();

		$this->assertSame( 'DELETE', $this->recorder->requests[1]['method'] );
		$this->assertFalse( get_option( ConnectionStore::OPTION ) );
	}

	public function test_uninstall_sends_nothing_for_a_paused_connection(): void {
		$this->recorder->json( 201, self::EXCHANGED );
		$this->connection->complete( $this->admin, $this->approve() );
		$this->store->pause( Connection::PAUSED_UNAUTHORIZED );

		$this->uninstall();

		$this->assertCount( 1, $this->recorder->requests );
	}

	public function test_a_revoke_waits_no_longer_than_the_uninstall_has_left(): void {
		$this->recorder->json( 201, self::EXCHANGED );
		$this->connection->complete( $this->admin, $this->approve() );
		$this->recorder->raw( 204, '', 'application/json' );
		Hosts::read_constants_with( static fn ( string $name ): string => Hosts::SERVER_API_CONSTANT === $name ? self::BASE_URL : '' );
		$left = UninstallRevoke::TIMEOUT_SECONDS - 3;

		try {
			$this->assertFalse( UninstallRevoke::run( time() ), 'Nothing is sent once the budget is spent.' );
			$this->assertCount( 1, $this->recorder->requests );
			$this->assertTrue( UninstallRevoke::run( time() + $left ) );
		} finally {
			Hosts::read_constants_with( null );
		}

		$this->assertSame( (float) $left, $this->recorder->requests[1]['timeout'] );
	}

	/**
	 * Deletes the plugin, its API address this test's recorder.
	 */
	private function uninstall(): void {
		Hosts::read_constants_with( static fn ( string $name ): string => Hosts::SERVER_API_CONSTANT === $name ? self::BASE_URL : '' );
		if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
			define( 'WP_UNINSTALL_PLUGIN', 'pixonaut-for-woocommerce/pixonaut-for-woocommerce.php' );
		}
		try {
			require dirname( __DIR__, 4 ) . '/uninstall.php';
		} finally {
			Hosts::read_constants_with( null );
		}
	}
}
