<?php
/**
 * @package Pixonaut\WooCommerce
 */

declare(strict_types=1);

namespace Pixonaut\WooCommerce\Tests\Integration\Admin;

use Pixonaut\WooCommerce\Admin\DeveloperTools;
use Pixonaut\WooCommerce\Api\Deprecations;
use Pixonaut\WooCommerce\Api\HookListener;
use Pixonaut\WooCommerce\Api\Hosts;
use Pixonaut\WooCommerce\Api\Pixonaut;
use Pixonaut\WooCommerce\Api\RequestLog;
use Pixonaut\WooCommerce\Api\WcLogger;
use Pixonaut\WooCommerce\Catalogue\CatalogueCache;
use Pixonaut\WooCommerce\Connection\StoredConnection;
use Pixonaut\WooCommerce\Migrations\MigrationRunner;
use Pixonaut\WooCommerce\Plugin;
use Pixonaut\WooCommerce\Settings\Settings;
use Pixonaut\WooCommerce\Storefront\DesignerLinkWithdrawals;
use Pixonaut\WooCommerce\Storefront\KeptDesignerLinks;
use Pixonaut\WooCommerce\Storefront\Sdk;
use Pixonaut\WooCommerce\Tests\Support\ConnectionAnswers;
use Pixonaut\WooCommerce\Tests\Support\HttpRecorder;
use Pixonaut\WooCommerce\Tests\Support\MemberKeys;
use Pixonaut\WooCommerce\Vendor\Pixonaut\Sdk\Exception\ApiException;
use Pixonaut\WooCommerce\Vendor\Pixonaut\Sdk\Http\PreparedRequest;
use Pixonaut\WooCommerce\Vendor\Pixonaut\Sdk\Http\Result;
use Pixonaut\WooCommerce\Webhooks\Endpoint;
use Pixonaut\WooCommerce\Webhooks\EventsPull;
use RuntimeException;
use WP_UnitTestCase;
use WPDieException;

final class DeveloperToolsTest extends WP_UnitTestCase {

	/**
	 * A local Pixonaut, loopback so the test bootstrap's host guard lets it
	 * through to the recorder.
	 */
	private const LOCAL_PIXONAUT = 'http://127.0.0.1:8787';

	private const CONSTANT_HOST = 'http://localhost:9999';

	private const INTEGRATION_KEY = 'ik_live_0123456789abcdef01234567';

	private const WEBHOOK_SECRET = 'whsec_0123456789abcdef0123456789abcdef';

	private const CONNECTED = array(
		'tenantId'       => '0b1c2d3e-4f50-4a6b-8c7d-000000000003',
		'tenantName'     => 'Acme',
		'tenantSlug'     => ConnectionAnswers::TENANT_SLUG,
		'environment'    => 'live',
		'connectionId'   => 'sc_0b1c2d3e-4f50-4a6b-8c7d-000000000004',
		'integrationKey' => array(
			'id'    => '0b1c2d3e-4f50-4a6b-8c7d-000000000005',
			'key'   => self::INTEGRATION_KEY,
			'label' => 'WooCommerce · Acme Shop',
		),
		'publishableKey' => array(
			'id'             => '0b1c2d3e-4f50-4a6b-8c7d-000000000006',
			'key'            => 'pk_live_89abcdef0123456789abcdef',
			'allowedOrigins' => array( 'http://example.org' ),
		),
		'webhook'        => array(
			'id'     => '0b1c2d3e-4f50-4a6b-8c7d-000000000007',
			'url'    => 'http://example.org/wp-json/pixonaut/v1/webhook',
			'secret' => self::WEBHOOK_SECRET,
		),
		'memberKey'      => MemberKeys::ANSWER,
		'reachable'      => true,
	);

	private const ACTIONS = array(
		DeveloperTools::SAVE_ACTION    => 'handle_save',
		DeveloperTools::PULL_ACTION    => 'handle_pull',
		DeveloperTools::PING_ACTION    => 'handle_ping',
		DeveloperTools::RECHECK_ACTION => 'handle_recheck',
		DeveloperTools::CLEAR_ACTION   => 'handle_clear',
	);

	private Plugin $plugin;

	private DeveloperTools $tools;

	public function set_up(): void {
		parent::set_up();
		$this->plugin = Plugin::boot( dirname( __DIR__, 4 ) . '/pixonaut-for-woocommerce.php' );
		$this->tools  = new DeveloperTools(
			$this->plugin->settings(),
			$this->plugin->connection(),
			$this->plugin->webhook_endpoint(),
			$this->plugin->events_pull(),
			$this->plugin->product_recheck(),
			$this->plugin->catalogue_cache(),
			$this->plugin->designer_links(),
			$this->plugin->request_log(),
			$this->plugin->templates(),
			$this->plugin->file()
		);
		// As on a site without the constants the tests' own site sets.
		Hosts::read_constants_with( static fn (): string => '' );
		// Turns the handlers' redirect, which exits, into an exception.
		add_filter(
			'wp_redirect',
			static function ( string $location ): string {
				throw new RuntimeException( $location ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- a test's redirect.
			}
		);
	}

	public function tear_down(): void {
		Hosts::read_constants_with( null );
		$this->plugin->connection_store()->clear();
		$_POST    = array();
		$_GET     = array();
		$_REQUEST = array();
		parent::tear_down();
	}

	public function test_a_base_url_override_moves_every_api_call_and_a_reset_restores_it(): void {
		$this->connect();
		$this->as_admin();
		$this->post(
			DeveloperTools::SAVE_ACTION,
			array(
				DeveloperTools::FIELDS_ARG => array(
					Settings::API_BASE    => self::LOCAL_PIXONAUT . '/',
					Settings::STUDIO_BASE => 'http://localhost:3000',
				),
			)
		);

		$this->assertSame( self::LOCAL_PIXONAUT, Hosts::api() );
		$this->assertSame( self::LOCAL_PIXONAUT, Hosts::server_api(), 'The server reaches the API where the browser does, unless told otherwise.' );
		$this->assertSame( 'http://localhost:3000', Hosts::studio() );
		$this->assertSame( array( self::LOCAL_PIXONAUT, 'http://localhost:3000' ), Hosts::custom() );

		$recorder = ( new HttpRecorder( self::LOCAL_PIXONAUT ) )->start();
		$recorder->json( 200, $this->connect_answer() );
		$client = ( new Plugin( $this->plugin->file(), new MigrationRunner( array() ) ) )->sdk();
		$client->connect()->get();
		$recorder->stop();
		$this->assertCount( 1, $recorder->requests );
		$this->assertStringStartsWith( self::LOCAL_PIXONAUT . '/v1/connect', $recorder->requests[0]['url'] );

		$this->post( DeveloperTools::SAVE_ACTION, array( DeveloperTools::RESET_ARG => Settings::API_BASE ) );

		$this->assertSame( Hosts::API, Hosts::api() );
		$this->assertSame( Hosts::API, Hosts::server_api() );
		$this->assertSame( 'http://localhost:3000', Hosts::studio(), 'A reset resets only its own address.' );
	}

	public function test_a_constant_wins_over_the_override_and_the_form_leaves_it_alone(): void {
		$this->as_admin();
		$this->plugin->settings()->set( Settings::API_BASE, self::LOCAL_PIXONAUT );
		Hosts::read_constants_with( static fn ( string $name ): string => Hosts::API_CONSTANT === $name ? self::CONSTANT_HOST . '/' : '' );

		$this->assertSame( self::CONSTANT_HOST, Hosts::api() );
		$this->assertSame( Hosts::API_CONSTANT, Hosts::locked_by( Settings::API_BASE ) );
		$this->assertNull( Hosts::locked_by( Settings::STUDIO_BASE ) );

		$this->post( DeveloperTools::SAVE_ACTION, array( DeveloperTools::FIELDS_ARG => array( Settings::API_BASE => 'http://localhost:1234' ) ) );
		$this->post( DeveloperTools::SAVE_ACTION, array( DeveloperTools::RESET_ARG => Settings::API_BASE ) );

		$this->assertSame( self::LOCAL_PIXONAUT, $this->plugin->settings()->get( Settings::API_BASE ) );
		$this->assertSame( self::CONSTANT_HOST, Hosts::api() );
		$section = $this->tools->section();
		$this->assertSame( Hosts::API_CONSTANT, $section['hosts'][ Settings::API_BASE ]['locked_by'] );
		$this->assertSame( self::CONSTANT_HOST, $section['effective'][ Settings::API_BASE ] );
	}

	public function test_a_store_pointed_at_staging_loads_the_pinned_sdk_from_its_cdn(): void {
		$this->as_admin();
		$this->post(
			DeveloperTools::SAVE_ACTION,
			array(
				DeveloperTools::FIELDS_ARG => array(
					Settings::API_BASE    => 'https://api.staging.example.test',
					Settings::STUDIO_BASE => 'https://studio.staging.example.test',
					Settings::CDN_BASE    => 'https://cdn.staging.example.test/',
				),
			)
		);

		$this->assertSame( 'https://api.staging.example.test', Hosts::api() );
		$this->assertSame( 'https://studio.staging.example.test', Hosts::studio() );
		$this->assertSame( 'https://cdn.staging.example.test', Hosts::cdn() );
		$section = $this->tools->section();
		$this->assertSame( 'https://cdn.staging.example.test', $section['effective'][ Settings::CDN_BASE ] );
		$this->assertSame( 'https://cdn.staging.example.test/sdk/' . Sdk::VERSION . '/pixonaut.iife.js', $section['pinned_sdk'] );
		$this->assertNotContains( 'https://cdn.staging.example.test', Hosts::custom(), 'The CDN is sent no key.' );
	}

	public function test_a_constant_points_the_sdk_at_a_staging_cdn(): void {
		Hosts::read_constants_with( static fn ( string $name ): string => Hosts::CDN_CONSTANT === $name ? 'https://cdn.staging.example.test/' : '' );

		$this->assertSame( 'https://cdn.staging.example.test', Hosts::cdn() );
		$this->assertSame( Hosts::CDN_CONSTANT, Hosts::locked_by( Settings::CDN_BASE ) );
		$this->assertSame( Hosts::CDN_CONSTANT, $this->tools->section()['hosts'][ Settings::CDN_BASE ]['locked_by'] );
	}

	public function test_an_address_that_is_not_http_is_refused(): void {
		$this->as_admin();

		$location = $this->post( DeveloperTools::SAVE_ACTION, array( DeveloperTools::FIELDS_ARG => array( Settings::API_BASE => 'javascript:alert(1)' ) ) );

		$this->assertStringContainsString( DeveloperTools::RESULT_ARG . '=' . DeveloperTools::INVALID_URL, $location );
		$this->assertSame( '', $this->plugin->settings()->get( Settings::API_BASE ) );
		$this->assertSame( Hosts::API, Hosts::api() );
	}

	public function test_every_action_needs_a_user_who_may_manage_woocommerce(): void {
		$this->connect();
		$this->as( 'customer' );

		foreach ( self::ACTIONS as $action => $handler ) {
			$_REQUEST['_wpnonce'] = wp_create_nonce( $action );
			$_POST                = array( DeveloperTools::FIELDS_ARG => array( Settings::API_BASE => self::LOCAL_PIXONAUT ) );
			try {
				$this->tools->{$handler}();
				$this->fail( $action . ' ran for a customer.' );
			} catch ( WPDieException $e ) {
				$this->assertStringContainsString( 'not allowed', $e->getMessage(), $action );
			}
		}
		$this->assertSame( '', $this->plugin->settings()->get( Settings::API_BASE ) );
	}

	public function test_every_action_needs_its_nonce(): void {
		$this->as_admin();

		foreach ( self::ACTIONS as $action => $handler ) {
			$_REQUEST['_wpnonce'] = wp_create_nonce( 'another_action' );
			try {
				$this->tools->{$handler}();
				$this->fail( $action . ' ran without its nonce.' );
			} catch ( WPDieException $e ) {
				$this->assertNotEmpty( $e->getMessage(), $action );
			}
		}
	}

	public function test_the_actions_run_for_a_store_manager(): void {
		$this->connect();
		$this->as_admin();
		update_option( DesignerLinkWithdrawals::WITHDRAWALS_OPTION, array( 'shl_waiting' ) );
		$product = self::factory()->post->create( array( 'post_type' => 'product' ) );
		update_post_meta(
			is_int( $product ) ? $product : 0,
			KeptDesignerLinks::META,
			array(
				'en' => array(
					'id'         => 'shl_kept',
					'url'        => 'https://studio.pixonaut.app/d/link_1',
					'hash'       => 'h',
					'name'       => 'Tee',
					'template'   => 'tpl_1',
					'connection' => self::CONNECTED['connectionId'],
				),
			)
		);
		update_option( CatalogueCache::GENERATION_OPTION, 4 );

		$cleared = $this->post( DeveloperTools::CLEAR_ACTION );
		$queued  = $this->post( DeveloperTools::RECHECK_ACTION );

		$this->assertStringContainsString( DeveloperTools::RESULT_ARG . '=' . DeveloperTools::CLEARED, $cleared );
		$this->assertStringEndsWith( '#' . DeveloperTools::ANCHOR, $cleared );
		$this->assertSame( 5, (int) get_option( CatalogueCache::GENERATION_OPTION ) );
		$this->assertSame( '', get_post_meta( is_int( $product ) ? $product : 0, KeptDesignerLinks::META, true ) );
		$this->assertSame( array( 'shl_waiting', 'shl_kept' ), DesignerLinkWithdrawals::pending_withdrawals(), 'A cleared link is withdrawn, and one already waiting still is.' );
		$this->assertNotFalse( as_next_scheduled_action( DesignerLinkWithdrawals::WITHDRAW_ACTION, null, DesignerLinkWithdrawals::GROUP ) );
		$this->assertStringContainsString( DeveloperTools::RESULT_ARG . '=' . DeveloperTools::RECHECK_QUEUED, $queued );
	}

	public function test_a_pull_or_recheck_that_did_not_happen_says_so(): void {
		$this->connect();
		$this->as_admin();

		$this->assertStringContainsString( DeveloperTools::RESULT_ARG . '=' . DeveloperTools::PULL_FAILED, $this->post( DeveloperTools::PULL_ACTION ), 'No first cursor yet.' );

		update_option(
			EventsPull::CURSOR_OPTION,
			array(
				'after'      => null,
				'connection' => self::CONNECTED['connectionId'],
			)
		);
		// Pixonaut is not reachable from the tests.
		$this->assertStringContainsString( DeveloperTools::RESULT_ARG . '=' . DeveloperTools::PULL_FAILED, $this->post( DeveloperTools::PULL_ACTION ), 'Pixonaut not reached.' );

		add_filter( 'pre_as_enqueue_async_action', static fn (): int => 0 );
		$this->assertStringContainsString( DeveloperTools::RESULT_ARG . '=' . DeveloperTools::RECHECK_FAILED, $this->post( DeveloperTools::RECHECK_ACTION ) );
	}

	public function test_only_an_administrator_changes_where_the_key_and_scripts_go(): void {
		$this->as( 'shop_manager' );
		$this->post(
			DeveloperTools::SAVE_ACTION,
			array(
				DeveloperTools::FIELDS_ARG => array(
					Settings::API_BASE    => self::LOCAL_PIXONAUT,
					Settings::SDK_URL     => 'http://localhost:5173/pixonaut.iife.js',
					Settings::REQUEST_LOG => '1',
				),
			)
		);
		$this->assertSame( '', $this->plugin->settings()->get( Settings::API_BASE ) );
		$this->assertSame( '', $this->plugin->settings()->get( Settings::SDK_URL ) );
		$this->assertTrue( $this->plugin->settings()->get( Settings::REQUEST_LOG ), 'A store manager may still debug.' );
		$this->assertFalse( $this->tools->section()['sdk_url_editable'] );

		$this->as_admin();
		// As with DISALLOW_UNFILTERED_HTML, or on a multisite.
		add_filter( 'map_meta_cap', static fn ( array $caps, string $cap ): array => 'unfiltered_html' === $cap ? array( 'do_not_allow' ) : $caps, 10, 2 );
		$this->post(
			DeveloperTools::SAVE_ACTION,
			array(
				DeveloperTools::FIELDS_ARG => array(
					Settings::API_BASE => self::LOCAL_PIXONAUT,
					Settings::SDK_URL  => 'http://localhost:5173/pixonaut.iife.js',
				),
			)
		);
		$this->assertSame( self::LOCAL_PIXONAUT, $this->plugin->settings()->get( Settings::API_BASE ) );
		$this->assertSame( '', $this->plugin->settings()->get( Settings::SDK_URL ) );
	}

	public function test_an_address_keeps_its_percent_encoding(): void {
		$this->as_admin();

		$this->post( DeveloperTools::SAVE_ACTION, array( DeveloperTools::FIELDS_ARG => array( Settings::SDK_URL => 'http://localhost:5173/build%20one/pixonaut.iife.js' ) ) );

		$this->assertSame( 'http://localhost:5173/build%20one/pixonaut.iife.js', $this->plugin->settings()->get( Settings::SDK_URL ) );
	}

	public function test_a_failed_health_read_is_not_repeated_in_the_same_request(): void {
		$this->connect();
		$recorder = ( new HttpRecorder( self::LOCAL_PIXONAUT ) )->start();
		$recorder->fail();
		$client   = ( new Pixonaut( $this->plugin->connection_store(), new WcLogger(), new HookListener( new Deprecations() ), null, self::LOCAL_PIXONAUT ) )->sdk();
		$endpoint = new Endpoint( $this->plugin->connection(), $client, new WcLogger() );
		$stored   = $this->plugin->connection()->current();
		$this->assertNotNull( $stored );

		foreach ( array( 1, 2 ) as $read ) {
			try {
				$endpoint->health( $stored );
				$this->fail( 'An unreachable Pixonaut gave a health.' );
			} catch ( ApiException $e ) {
				$this->assertSame( 0, $e->status, (string) $read );
			}
		}
		$recorder->stop();
		$this->assertCount( 1, $recorder->requests );
	}

	public function test_actions_that_need_pixonaut_refuse_while_not_connected(): void {
		$this->as_admin();

		foreach ( array( DeveloperTools::PULL_ACTION, DeveloperTools::PING_ACTION, DeveloperTools::RECHECK_ACTION ) as $action ) {
			$this->assertStringContainsString( DeveloperTools::RESULT_ARG . '=' . DeveloperTools::NOT_CONNECTED, $this->post( $action ), $action );
		}
	}

	public function test_the_request_log_records_requests_without_keys_or_tokens(): void {
		$this->connect();
		$this->as_admin();
		$this->post( DeveloperTools::SAVE_ACTION, array( DeveloperTools::FIELDS_ARG => array( Settings::REQUEST_LOG => '1' ) ) );
		$this->assertTrue( $this->plugin->request_log()->enabled() );
		$this->plugin->settings()->set( Settings::API_BASE, self::LOCAL_PIXONAUT );

		$recorder = ( new HttpRecorder( self::LOCAL_PIXONAUT ) )->start();
		$recorder->json( 200, $this->connect_answer(), array( RequestLog::REQUEST_ID_HEADER => 'req_123' ) );
		$client = ( new Plugin( $this->plugin->file(), new MigrationRunner( array() ) ) )->sdk();
		$client->connect()->get();
		$recorder->stop();
		// A path carrying a link token, as a designer link's would.
		$request = new PreparedRequest( 'shareLinks.delete', '/v1/share-links/link_secret123?token=shl_abc', null, null );
		$this->plugin->request_log()->before( $request );
		$this->plugin->request_log()->after( $request, new Result( 'shareLinks.delete', 200, array(), '{}' ) );

		$entries = $this->plugin->request_log()->entries();
		$this->assertCount( 2, $entries );
		$this->assertSame( '/v1/share-links/link_[redacted]?token=shl_[redacted]', $entries[0]['path'] );
		$this->assertSame( 'GET', $entries[1]['method'] );
		$this->assertSame( 'connectedStore.get', $entries[1]['operation'] );
		$this->assertSame( 200, $entries[1]['status'] );
		$this->assertSame( 'req_123', $entries[1]['request_id'] );
		$this->assertIsInt( $entries[1]['duration_ms'] );
		$stored = (string) wp_json_encode( get_option( RequestLog::OPTION ) );
		foreach ( array( self::INTEGRATION_KEY, self::WEBHOOK_SECRET, 'secret123', 'shl_abc', 'Bearer' ) as $secret ) {
			$this->assertStringNotContainsString( $secret, $stored );
		}
	}

	public function test_the_request_log_keeps_the_last_entries_only_while_it_is_on(): void {
		$log     = $this->plugin->request_log();
		$request = new PreparedRequest( 'connectedStore.get', '/v1/connect' );
		$log->before( $request );
		$log->after( $request, new Result( 'connectedStore.get', 200, array(), '{}' ) );
		$this->assertSame( array(), $log->entries(), 'The log is off by default.' );

		$this->plugin->settings()->set( Settings::REQUEST_LOG, true );
		for ( $sent = 0; $sent <= RequestLog::MAX_ENTRIES; $sent++ ) {
			$request = new PreparedRequest( 'connectedStore.get', '/v1/connect?n=' . $sent );
			$log->before( $request );
			$log->after( $request, null );
		}

		$entries = $log->entries();
		$this->assertCount( RequestLog::MAX_ENTRIES, $entries );
		$this->assertSame( '/v1/connect?n=' . RequestLog::MAX_ENTRIES, $entries[0]['path'] );
		$this->assertSame( 0, $entries[0]['status'] );
	}

	public function test_the_section_shows_the_connection_without_its_key(): void {
		$this->connect();

		$connection = $this->tools->section()['connection'];

		$this->assertIsArray( $connection );
		$this->assertSame( 'Acme', $connection['workspace'] );
		$this->assertSame( substr( self::INTEGRATION_KEY, 0, DeveloperTools::KEY_PREFIX_LENGTH ), $connection['key_prefix'] );
		$this->assertSame( 'live', $connection['mode'] );
		$this->assertSame( self::CONNECTED['webhook']['url'], $connection['webhook_url'] );
		$this->assertNull( $connection['webhook'], 'Pixonaut could not be reached to say.' );
		ob_start();
		$this->tools->render();
		$html = (string) ob_get_clean();
		$this->assertStringNotContainsString( self::INTEGRATION_KEY, $html );
		$this->assertStringContainsString( 'id="' . DeveloperTools::ANCHOR . '"', $html );
		$this->assertStringContainsString( 'form="' . DeveloperTools::FORM_ID . '"', $html );
	}

	public function test_the_admin_bar_says_when_pixonaut_is_elsewhere(): void {
		require_once ABSPATH . 'wp-includes/class-wp-admin-bar.php';
		$this->as_admin();
		$bar = new \WP_Admin_Bar();
		$this->tools->admin_bar( $bar );
		$this->assertNull( $bar->get_node( DeveloperTools::ADMIN_BAR_NODE ) );

		$this->plugin->settings()->set( Settings::API_BASE, self::LOCAL_PIXONAUT );
		$this->tools->admin_bar( $bar );

		$node = (array) $bar->get_node( DeveloperTools::ADMIN_BAR_NODE );
		$this->assertSame( 'Custom Pixonaut host', $node['title'] ?? null );
		$this->assertSame( array( 'title' => self::LOCAL_PIXONAUT ), $node['meta'] ?? null );
	}

	private function as_admin(): void {
		$this->as( 'administrator' );
	}

	/**
	 * @param string $role A role.
	 */
	private function as( string $role ): void {
		$user = self::factory()->user->create( array( 'role' => $role ) );
		wp_set_current_user( is_int( $user ) ? $user : 0 );
	}

	private function connect(): void {
		$this->plugin->connection_store()->save( StoredConnection::from_exchange( self::CONNECTED, '0b1c2d3e-4f50-4a6b-8c7d-0000000000aa', untrailingslashit( home_url() ), time() ) );
	}

	/**
	 * @return array<string, mixed> `GET /v1/connect`'s answer for the connection.
	 */
	private function connect_answer(): array {
		return array(
			'allowedOrigins'    => self::CONNECTED['publishableKey']['allowedOrigins'],
			'connectionId'      => self::CONNECTED['connectionId'],
			'environment'       => self::CONNECTED['environment'],
			'name'              => self::CONNECTED['integrationKey']['label'],
			'siteUrl'           => untrailingslashit( home_url() ),
			'tenantId'          => self::CONNECTED['tenantId'],
			'tenantName'        => self::CONNECTED['tenantName'],
			'tenantSlug'        => ConnectionAnswers::TENANT_SLUG,
			'webhookEndpointId' => self::CONNECTED['webhook']['id'],
		) + ConnectionAnswers::WORKSPACE_SETTINGS;
	}

	/**
	 * Runs an action's handler with its nonce, as `admin-post.php` would.
	 *
	 * @param string               $action An action of ACTIONS.
	 * @param array<string, mixed> $post   The posted form.
	 * @return string Where it sent the admin back to.
	 */
	private function post( string $action, array $post = array() ): string {
		$_REQUEST['_wpnonce'] = wp_create_nonce( $action );
		$_POST                = $post;
		try {
			$this->tools->{self::ACTIONS[ $action ]}();
		} catch ( RuntimeException $redirect ) {
			return $redirect->getMessage();
		}
		$this->fail( $action . ' did not send the admin back.' );
	}
}
