<?php
/**
 * @package Pixonaut\WooCommerce
 */

declare(strict_types=1);

namespace Pixonaut\WooCommerce\Tests\Integration\Connection;

use Pixonaut\WooCommerce\Admin\ConnectionScreen;
use Pixonaut\WooCommerce\Admin\Notices;
use Pixonaut\WooCommerce\Admin\StudioPage;
use Pixonaut\WooCommerce\Api\Answers\ConnectedStore;
use Pixonaut\WooCommerce\Api\Deprecations;
use Pixonaut\WooCommerce\Api\HookListener;
use Pixonaut\WooCommerce\Api\Hosts;
use Pixonaut\WooCommerce\Api\Pixonaut;
use Pixonaut\WooCommerce\Connection\Cipher;
use Pixonaut\WooCommerce\Connection\ConnectAttempts;
use Pixonaut\WooCommerce\Connection\Connection;
use Pixonaut\WooCommerce\Connection\ConnectionStore;
use Pixonaut\WooCommerce\Connection\StoredConnection;
use Pixonaut\WooCommerce\Connection\WorkspaceSettings;
use Pixonaut\WooCommerce\DesignerLanguage;
use Pixonaut\WooCommerce\Plugin;
use Pixonaut\WooCommerce\Settings\Settings;
use Pixonaut\WooCommerce\Storefront\Accent;
use Pixonaut\WooCommerce\Storefront\DesignerLook;
use Pixonaut\WooCommerce\Tests\Support\ConnectionAnswers;
use Pixonaut\WooCommerce\Tests\Support\HttpRecorder;
use Pixonaut\WooCommerce\Tests\Support\RecordingLogger;
use RuntimeException;
use WP_UnitTestCase;

final class WorkspaceSettingsTest extends WP_UnitTestCase {

	/**
	 * Loopback, so the test bootstrap's host guard lets it through to the recorder.
	 */
	private const BASE_URL = 'http://127.0.0.1:9';

	private const ACCENT = '#1a73e8';

	private const NOW = 1_800_000_000;

	private const DEFAULT_BRAND = 'br_3f6c1a2e-9b4d-4e7a-8c5f-000000000001';

	private const PETS_BRAND = 'br_3f6c1a2e-9b4d-4e7a-8c5f-000000000002';

	private const PETS_ACCENT = '#2e7d32';

	private HttpRecorder $recorder;

	private ConnectionStore $store;

	private Connection $connection;

	private RecordingLogger $logger;

	private Plugin $plugin;

	public function set_up(): void {
		parent::set_up();
		$this->recorder = ( new HttpRecorder( self::BASE_URL ) )->start();
		$this->store    = new ConnectionStore( Cipher::for_site() );
		$this->store->save( self::connection() );
		$this->plugin     = Plugin::boot( dirname( __DIR__, 4 ) . '/pixonaut-for-woocommerce.php' );
		$client           = ( new Pixonaut( $this->store, new RecordingLogger(), new HookListener( new Deprecations() ), null, self::BASE_URL ) )->sdk();
		$this->connection = new Connection( $this->store, new ConnectAttempts(), $client, $this->plugin->notices(), new RecordingLogger(), ConnectionScreen::settings_url() );
		$this->logger     = new RecordingLogger();
		as_unschedule_all_actions( WorkspaceSettings::REFRESH_ACTION );
		WorkspaceSettings::forget();
	}

	public function tear_down(): void {
		$_POST    = array();
		$_REQUEST = array();
		$this->recorder->stop();
		$this->store->clear();
		WorkspaceSettings::forget();
		as_unschedule_all_actions( WorkspaceSettings::REFRESH_ACTION );
		delete_option( Notices::OPTION );
		parent::tear_down();
	}

	/**
	 * @param string|null $tenant_slug The slug the connect answered; null for a store connected before Pixonaut answered one.
	 */
	private static function connection( ?string $tenant_slug = null ): StoredConnection {
		return new StoredConnection(
			'5c3a1f6e-8b2d-4e7a-9c1b-000000000001',
			Connection::site_url(),
			time(),
			'0b1c2d3e-4f50-4a6b-8c7d-000000000003',
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
			'whsec_0123456789abcdef0123456789abcdef',
			$tenant_slug
		);
	}

	private function settings( int $now = self::NOW ): WorkspaceSettings {
		return new WorkspaceSettings( $this->connection, ( new Pixonaut( $this->store, new RecordingLogger(), new HookListener( new Deprecations() ), null, self::BASE_URL ) )->sdk(), $this->logger, static fn (): int => $now );
	}

	/**
	 * A `GET` or `PATCH /v1/connect` answer for the stored connection.
	 *
	 * @param array<string, mixed> $settings Workspace settings over the defaults.
	 * @param string[]             $origins  The publishable key's origins.
	 * @phpstan-param list<string> $origins
	 * @return array<string, mixed>
	 */
	private static function answer( array $settings = array(), array $origins = array( 'http://example.org' ) ): array {
		$connection = self::connection();
		return array(
			'allowedOrigins'    => $origins,
			'connectionId'      => $connection->connection_id,
			'environment'       => $connection->environment,
			'name'              => $connection->integration_key_label,
			'siteUrl'           => untrailingslashit( home_url() ),
			'tenantId'          => $connection->tenant_id,
			'tenantName'        => $connection->tenant_name,
			'tenantSlug'        => ConnectionAnswers::TENANT_SLUG,
			'webhookEndpointId' => $connection->webhook_id,
		) + $settings + ConnectionAnswers::WORKSPACE_SETTINGS;
	}

	/**
	 * @return array<string, mixed>
	 */
	private static function branded(): array {
		return array(
			'branding' => array(
				'logoUrl'     => 'http://127.0.0.1:9/l/logo',
				'accentColor' => self::ACCENT,
				'brandId'     => null,
			),
		);
	}

	/**
	 * The workspace's two brands, the store drawn in one of them.
	 *
	 * @param string|null $brand_id The brand chosen for the store; null for the default.
	 * @return array<string, mixed>
	 */
	private static function two_brands( ?string $brand_id ): array {
		$pets = self::PETS_BRAND === $brand_id;
		return array(
			'branding' => array(
				'logoUrl'     => null,
				'accentColor' => $pets ? self::PETS_ACCENT : self::ACCENT,
				'brandId'     => $pets ? self::PETS_BRAND : self::DEFAULT_BRAND,
			),
			'brandId'  => $brand_id,
			'brands'   => array(
				array(
					'id'          => self::DEFAULT_BRAND,
					'name'        => 'Acme',
					'isDefault'   => true,
					'logoUrl'     => null,
					'accentColor' => self::ACCENT,
				),
				array(
					'id'          => self::PETS_BRAND,
					'name'        => 'Acme Pets',
					'isDefault'   => false,
					'logoUrl'     => null,
					'accentColor' => self::PETS_ACCENT,
				),
			),
		);
	}

	private function screen(): ConnectionScreen {
		$client = ( new Pixonaut( $this->store, new RecordingLogger(), new HookListener( new Deprecations() ), null, self::BASE_URL ) )->sdk();
		return new ConnectionScreen( $this->connection, $client, $this->plugin->webhook_endpoint(), new Deprecations(), $this->plugin->templates(), ConnectionScreen::settings_url(), $this->settings() );
	}

	/**
	 * Posts the brand form as an admin.
	 *
	 * @param string $brand The brand field: an id, or empty for the default.
	 * @return string Where the admin was sent back to.
	 */
	private function choose_brand( string $brand ): string {
		$admin = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( is_int( $admin ) ? $admin : 0 );
		$_REQUEST['_wpnonce']                 = wp_create_nonce( ConnectionScreen::BRAND_ACTION );
		$_POST[ ConnectionScreen::BRAND_ARG ] = $brand;
		add_filter(
			'wp_redirect',
			static function ( string $location ): string {
				throw new RuntimeException( $location ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- a test's redirect.
			}
		);
		try {
			$this->screen()->handle_choose_brand();
		} catch ( RuntimeException $redirect ) {
			return $redirect->getMessage();
		}
		$this->fail( 'Choosing a brand did not send the admin back.' );
	}

	public function test_the_card_offers_the_workspaces_brands_with_the_stores_chosen(): void {
		$this->recorder->json( 200, self::answer( self::two_brands( self::PETS_BRAND ) ) );
		$this->recorder->json( 500, array( 'error' => 'internal_error' ) );

		$card = $this->screen()->card();

		$this->assertSame( self::PETS_BRAND, $card['workspace_settings']['brand_id'] );
		$this->assertSame( array( 'Acme', 'Acme Pets' ), array_column( $card['workspace_settings']['brands'], 'name' ) );
		$this->assertSame( self::PETS_BRAND, WorkspaceSettings::brand_id() );
		$this->assertSame( self::PETS_ACCENT, WorkspaceSettings::accent( self::NOW ) );
		ob_start();
		$this->plugin->templates()->render( 'admin/connection-card.php', $card );
		$html = (string) ob_get_clean();
		$this->assertStringContainsString( '<option value="">', str_replace( "\t", '', $html ) );
		$this->assertStringContainsString( 'Acme (workspace default)', $html );
		$this->assertMatchesRegularExpression( '/value="' . self::PETS_BRAND . '"\s+selected=\'selected\'/', $html );
		$this->assertStringContainsString( 'Save brand', $html );
	}

	public function test_a_store_that_chose_the_default_brand_itself_keeps_it_on_saving(): void {
		$answer            = self::two_brands( null );
		$answer['brandId'] = self::DEFAULT_BRAND;
		$this->recorder->json( 200, self::answer( $answer ) );
		$this->recorder->json( 500, array( 'error' => 'internal_error' ) );

		ob_start();
		$this->plugin->templates()->render( 'admin/connection-card.php', $this->screen()->card() );
		$html = (string) ob_get_clean();

		$this->assertMatchesRegularExpression( '/value="' . self::DEFAULT_BRAND . '"\s+selected=\'selected\'/', $html );
		$this->assertStringNotContainsString( 'value=""', $html );
	}

	public function test_a_workspace_with_one_brand_shows_it_as_the_only_choice(): void {
		$answer           = self::two_brands( null );
		$answer['brands'] = array_slice( $answer['brands'], 0, 1 );
		$this->recorder->json( 200, self::answer( $answer ) );
		$this->recorder->json( 500, array( 'error' => 'internal_error' ) );

		ob_start();
		$this->plugin->templates()->render( 'admin/connection-card.php', $this->screen()->card() );
		$html = (string) ob_get_clean();

		$this->assertSame( 1, substr_count( $html, '<option ' ) );
		$this->assertStringContainsString( 'Acme (workspace default)', $html );
		$this->assertStringNotContainsString( 'Save brand', $html );
	}

	public function test_choosing_a_brand_sets_it_and_the_designer_takes_its_accent(): void {
		$this->settings()->remember( ConnectedStore::from_answer( self::answer( self::two_brands( null ) ) ) );
		$this->recorder->json( 200, self::answer( self::two_brands( self::PETS_BRAND ) ) );

		$back = $this->choose_brand( self::PETS_BRAND );

		$this->assertStringContainsString( ConnectionScreen::RESULT_ARG . '=' . ConnectionScreen::BRAND_CHOSEN, $back );
		$this->assertSame( 'PATCH', $this->recorder->requests[0]['method'] );
		$this->assertSame( array( 'brandId' => self::PETS_BRAND ), json_decode( (string) $this->recorder->requests[0]['body'], true ) );
		$this->assertSame( self::PETS_BRAND, WorkspaceSettings::brand_id() );
		$this->assertSame( self::PETS_ACCENT, DesignerLook::mount_options( $this->plugin->settings() )['accent'] );
	}

	public function test_choosing_the_workspace_default_follows_it(): void {
		$this->settings()->remember( ConnectedStore::from_answer( self::answer( self::two_brands( self::PETS_BRAND ) ) ) );
		$this->recorder->json( 200, self::answer( self::two_brands( null ) ) );

		$this->choose_brand( '' );

		$this->assertSame( array( 'brandId' => null ), json_decode( (string) $this->recorder->requests[0]['body'], true ) );
		$this->assertSame( self::DEFAULT_BRAND, WorkspaceSettings::brand_id() );
		$this->assertSame( self::ACCENT, WorkspaceSettings::accent( self::NOW ) );
	}

	public function test_a_refused_brand_is_reported_and_the_kept_brand_stays(): void {
		$this->settings()->remember( ConnectedStore::from_answer( self::answer( self::two_brands( null ) ) ) );
		$this->recorder->json( 404, array( 'error' => 'brand_not_found' ) );

		$back = $this->choose_brand( 'br_gone' );

		$this->assertStringContainsString( ConnectionScreen::RESULT_ARG . '=' . ConnectionScreen::BRAND_NOT_FOUND, $back );
		$this->assertSame( self::DEFAULT_BRAND, WorkspaceSettings::brand_id() );

		$this->recorder->fail();
		$this->assertStringContainsString( ConnectionScreen::RESULT_ARG . '=' . ConnectionScreen::BRAND_FAILED, $this->choose_brand( self::PETS_BRAND ) );
	}

	public function test_connecting_adds_the_www_counterpart_and_keeps_the_new_workspaces_accent(): void {
		$this->settings()->remember( ConnectedStore::from_answer( self::answer() ) );
		$www = (string) Connection::www_origin();
		$this->recorder->json( 200, self::answer( self::branded(), array( Connection::origin( Connection::site_url() ), $www ) ) );

		$this->settings()->connected();

		$this->assertSame( 'PATCH', $this->recorder->requests[0]['method'] );
		$this->assertSame( array( 'additionalOrigins' => array( $www ) ), json_decode( (string) $this->recorder->requests[0]['body'], true ) );
		$this->assertSame( self::ACCENT, WorkspaceSettings::accent( self::NOW ) );
	}

	public function test_a_refused_origin_is_logged_and_the_last_workspaces_accent_forgotten(): void {
		$this->settings()->remember( ConnectedStore::from_answer( self::answer( self::branded() ) ) );
		$this->recorder->json(
			400,
			array(
				'error'  => 'invalid_origin',
				'origin' => (string) Connection::www_origin(),
			)
		);

		$this->settings()->connected();

		$this->assertFalse( get_option( WorkspaceSettings::OPTION ) );
		$this->assertCount( 1, $this->logger->entries );
		$this->assertSame( 'invalid_origin', $this->logger->entries[0]['context']['code'] );
	}

	public function test_an_accent_read_more_than_a_day_ago_is_read_again(): void {
		$this->settings()->remember( ConnectedStore::from_answer( self::answer( self::branded() ) ) );

		$this->assertSame( self::ACCENT, WorkspaceSettings::accent( self::NOW + WorkspaceSettings::STALE_SECONDS ) );
		$this->assertFalse( as_has_scheduled_action( WorkspaceSettings::REFRESH_ACTION ), 'a day old is still fresh' );

		$this->assertSame( self::ACCENT, WorkspaceSettings::accent( self::NOW + WorkspaceSettings::STALE_SECONDS + 1 ) );
		$this->assertTrue( as_has_scheduled_action( WorkspaceSettings::REFRESH_ACTION ) );

		$this->recorder->json( 200, self::answer() );
		$this->settings( self::NOW + WorkspaceSettings::STALE_SECONDS + 1 )->refresh();

		$this->assertSame( 'GET', $this->recorder->requests[0]['method'] );
		$this->assertNull( WorkspaceSettings::accent( self::NOW + WorkspaceSettings::STALE_SECONDS + 1 ), 'the accent was taken away in the studio' );
	}

	public function test_a_failed_read_keeps_the_accent_and_waits_another_day(): void {
		$this->settings()->remember( ConnectedStore::from_answer( self::answer( self::branded() ) ) );
		$later = self::NOW + WorkspaceSettings::STALE_SECONDS + 1;
		$this->recorder->json( 500, array( 'error' => 'internal_error' ) );

		$this->settings( $later )->refresh();

		$this->assertSame( self::ACCENT, WorkspaceSettings::accent( $later ) );
		$this->assertFalse( as_has_scheduled_action( WorkspaceSettings::REFRESH_ACTION ) );
	}

	public function test_an_answer_about_a_connection_no_longer_stored_is_not_kept(): void {
		$this->settings()->remember( ConnectedStore::from_answer( array( 'connectionId' => 'sc_gone' ) + self::answer( self::branded() ) ) );

		$this->assertFalse( get_option( WorkspaceSettings::OPTION ) );

		$this->store->clear();
		$this->settings()->remember( ConnectedStore::from_answer( self::answer( self::branded() ) ) );

		$this->assertFalse( get_option( WorkspaceSettings::OPTION ), 'nor one read while the store disconnected' );
	}

	public function test_connecting_keeps_the_slug_the_connect_answered_even_when_pixonaut_refuses_the_origin(): void {
		$this->store->save( self::connection( ConnectionAnswers::TENANT_SLUG ) );
		$this->recorder->json(
			400,
			array(
				'error'  => 'invalid_origin',
				'origin' => (string) Connection::www_origin(),
			)
		);

		$this->settings()->connected();

		$this->assertSame( ConnectionAnswers::TENANT_SLUG, WorkspaceSettings::tenant_slug() );
		$this->assertNull( WorkspaceSettings::accent( self::NOW ) );
		$this->assertTrue( as_has_scheduled_action( WorkspaceSettings::REFRESH_ACTION ), 'nothing was read yet' );
	}

	public function test_a_store_connected_without_a_slug_has_it_after_its_next_read(): void {
		$this->assertNull( WorkspaceSettings::tenant_slug() );
		$this->assertSame( '', StudioPage::url( StudioPage::DESIGNS, 'd_front' ), 'the studio keeps no page outside a workspace' );
		$this->assertTrue( as_has_scheduled_action( WorkspaceSettings::REFRESH_ACTION ) );

		$this->recorder->json( 200, self::answer() );
		$this->settings()->refresh();

		$this->assertSame( 'GET', $this->recorder->requests[0]['method'] );
		$this->assertSame( ConnectionAnswers::TENANT_SLUG, WorkspaceSettings::tenant_slug() );
		$this->assertSame( Hosts::studio() . ConnectionAnswers::studio_path() . 'designs/d_front', StudioPage::url( StudioPage::DESIGNS, 'd_front' ) );
	}

	public function test_a_failed_read_keeps_the_known_slug(): void {
		$this->settings()->remember( ConnectedStore::from_answer( self::answer() ) );

		$this->recorder->json( 500, array( 'error' => 'internal_error' ) );
		$this->settings( self::NOW + WorkspaceSettings::STALE_SECONDS + 1 )->refresh();
		$this->assertSame( Hosts::studio() . ConnectionAnswers::studio_path() . 'blanks/bl_tee', StudioPage::url( StudioPage::BLANKS, 'bl_tee' ) );
	}

	public function test_a_failed_read_is_not_repeated_on_every_screen(): void {
		$this->recorder->json( 500, array( 'error' => 'internal_error' ) );
		$this->settings()->refresh();
		as_unschedule_all_actions( WorkspaceSettings::REFRESH_ACTION );

		$this->assertNull( WorkspaceSettings::tenant_slug( self::NOW ) );
		$this->assertFalse( as_has_scheduled_action( WorkspaceSettings::REFRESH_ACTION ), 'read less than a day ago' );

		$this->assertNull( WorkspaceSettings::tenant_slug( self::NOW + WorkspaceSettings::STALE_SECONDS + 1 ) );
		$this->assertTrue( as_has_scheduled_action( WorkspaceSettings::REFRESH_ACTION ), 'read again a day later' );
	}

	public function test_a_failed_read_does_not_restore_the_slug_of_a_connection_replaced_meanwhile(): void {
		$this->settings()->remember( ConnectedStore::from_answer( self::answer() ) );
		$store = $this->store;
		// The store disconnects in another request while this one waits for Pixonaut.
		add_filter(
			'pre_http_request',
			static function ( $preempt ) use ( $store ) {
				$store->clear();
				WorkspaceSettings::forget();
				return $preempt;
			},
			1
		);
		$this->recorder->json( 500, array( 'error' => 'internal_error' ) );

		$this->settings( self::NOW + WorkspaceSettings::STALE_SECONDS + 1 )->refresh();

		$this->assertFalse( get_option( WorkspaceSettings::OPTION ) );
		$this->assertCount( 1, $this->logger->entries );
	}

	public function test_disconnecting_forgets_the_workspace_settings(): void {
		$this->settings()->remember( ConnectedStore::from_answer( self::answer( self::branded() ) ) );

		do_action( 'pixonaut_wc_connection_disconnected', true );

		$this->assertFalse( get_option( WorkspaceSettings::OPTION ) );
	}

	public function test_the_card_shows_the_workspace_settings_and_keeps_the_accent_and_language(): void {
		$settings = self::branded() + array(
			'proofWatermark'  => true,
			'defaultLanguage' => 'de',
			'deliverOrdersTo' => array(
				'id'   => 'adr_1',
				'name' => 'Warehouse',
			),
		);
		$this->recorder->json( 200, self::answer( $settings ) );
		// The endpoint's health: Pixonaut does not answer it here.
		$this->recorder->json( 500, array( 'error' => 'internal_error' ) );
		$client = ( new Pixonaut( $this->store, new RecordingLogger(), new HookListener( new Deprecations() ), null, self::BASE_URL ) )->sdk();

		$card = ( new ConnectionScreen( $this->connection, $client, $this->plugin->webhook_endpoint(), new Deprecations(), $this->plugin->templates(), ConnectionScreen::settings_url(), $this->settings() ) )->card();

		$this->assertSame(
			array(
				'brand_id'         => null,
				'chosen_brand_id'  => null,
				'brands'           => array(),
				'logo_url'         => 'http://127.0.0.1:9/l/logo',
				'accent'           => self::ACCENT,
				'watermark'        => true,
				'deliver_to'       => 'Warehouse',
				'min_print_dpi'    => 150.0,
				'default_language' => 'Deutsch',
			),
			$card['workspace_settings']
		);
		$this->assertSame( self::ACCENT, WorkspaceSettings::accent() );
		$this->assertSame( 'de', WorkspaceSettings::default_language() );
		$this->assertSame( 'Pixonaut workspace default (Deutsch)', DesignerLanguage::names()[ DesignerLanguage::WORKSPACE_DEFAULT ] );
		ob_start();
		$this->plugin->templates()->render( 'admin/connection-card.php', $card );
		$html = (string) ob_get_clean();
		$this->assertStringContainsString( 'pixonaut-workspace-settings', $html );
		$this->assertStringContainsString( 'Warehouse', $html );
		$this->assertStringContainsString( 'Deutsch', $html );
	}

	public function test_a_language_pixonaut_does_not_list_is_not_kept(): void {
		$this->settings()->remember( ConnectedStore::from_answer( self::answer( self::branded() + array( 'defaultLanguage' => 'pl' ) ) ) );

		$this->assertNull( WorkspaceSettings::default_language() );
		$this->assertSame( 'Pixonaut workspace default', DesignerLanguage::names()[ DesignerLanguage::WORKSPACE_DEFAULT ] );
	}

	public function test_the_designer_takes_the_workspace_accent_only_when_the_store_chose_none(): void {
		$this->settings()->remember( ConnectedStore::from_answer( self::answer( self::branded() ) ) );
		$settings = $this->plugin->settings();

		$this->assertSame( self::ACCENT, DesignerLook::mount_options( $settings )['accent'] );

		$settings->set( Settings::DESIGNER_ACCENT, Accent::THEME );
		$this->assertArrayNotHasKey( 'accent', DesignerLook::mount_options( $settings ) );

		$settings->set( Settings::DESIGNER_ACCENT, Accent::CUSTOM );
		$settings->set( Settings::DESIGNER_ACCENT_COLOUR, '#1e73be' );
		$this->assertSame( '#1e73be', DesignerLook::mount_options( $settings )['accent'] );
	}
}
