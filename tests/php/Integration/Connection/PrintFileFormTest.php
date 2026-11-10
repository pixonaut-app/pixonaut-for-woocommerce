<?php
/**
 * @package Pixonaut\WooCommerce
 */

declare(strict_types=1);

namespace Pixonaut\WooCommerce\Tests\Integration\Connection;

use Pixonaut\WooCommerce\Admin\ConnectionScreen;
use Pixonaut\WooCommerce\Api\Deprecations;
use Pixonaut\WooCommerce\Api\HookListener;
use Pixonaut\WooCommerce\Api\Pixonaut;
use Pixonaut\WooCommerce\Connection\Cipher;
use Pixonaut\WooCommerce\Connection\ConnectAttempts;
use Pixonaut\WooCommerce\Connection\Connection;
use Pixonaut\WooCommerce\Connection\ConnectionStore;
use Pixonaut\WooCommerce\Connection\StoredConnection;
use Pixonaut\WooCommerce\Connection\WorkspaceSettings;
use Pixonaut\WooCommerce\Plugin;
use Pixonaut\WooCommerce\Tests\Support\ConnectionAnswers;
use Pixonaut\WooCommerce\Tests\Support\HttpRecorder;
use Pixonaut\WooCommerce\Tests\Support\RecordingLogger;
use RuntimeException;
use WP_UnitTestCase;

/**
 * The connection card's print file form: what it shows, and what saving sends.
 */
final class PrintFileFormTest extends WP_UnitTestCase {

	/**
	 * Loopback, so the test bootstrap's host guard lets it through to the recorder.
	 */
	private const BASE_URL = 'http://127.0.0.1:9';

	private const CONNECTION_ID = 'sc_0b1c2d3e-4f50-4a6b-8c7d-000000000004';

	private HttpRecorder $recorder;

	private ConnectionStore $store;

	private Plugin $plugin;

	public function set_up(): void {
		parent::set_up();
		$this->recorder = ( new HttpRecorder( self::BASE_URL ) )->start();
		$this->store    = new ConnectionStore( Cipher::for_site() );
		$this->store->save(
			new StoredConnection(
				'5c3a1f6e-8b2d-4e7a-9c1b-000000000001',
				Connection::site_url(),
				time(),
				'0b1c2d3e-4f50-4a6b-8c7d-000000000003',
				'Acme',
				StoredConnection::LIVE,
				self::CONNECTION_ID,
				'ik_live_0123456789abcdef01234567',
				'0b1c2d3e-4f50-4a6b-8c7d-000000000005',
				'WooCommerce · Acme Shop',
				'pk_live_89abcdef0123456789abcdef',
				'0b1c2d3e-4f50-4a6b-8c7d-000000000006',
				array( 'http://example.org' ),
				'0b1c2d3e-4f50-4a6b-8c7d-000000000007',
				'http://example.org/wp-json/pixonaut/v1/webhook',
				'whsec_0123456789abcdef0123456789abcdef',
				ConnectionAnswers::TENANT_SLUG
			)
		);
		$this->plugin = Plugin::boot( dirname( __DIR__, 4 ) . '/pixonaut-for-woocommerce.php' );
		WorkspaceSettings::forget();
	}

	public function tear_down(): void {
		$_POST    = array();
		$_REQUEST = array();
		$this->recorder->stop();
		$this->store->clear();
		WorkspaceSettings::forget();
		as_unschedule_all_actions( WorkspaceSettings::REFRESH_ACTION );
		parent::tear_down();
	}

	private function screen(): ConnectionScreen {
		$client     = ( new Pixonaut( $this->store, new RecordingLogger(), new HookListener( new Deprecations() ), null, self::BASE_URL ) )->sdk();
		$connection = new Connection( $this->store, new ConnectAttempts(), $client, $this->plugin->notices(), new RecordingLogger(), ConnectionScreen::settings_url() );
		return new ConnectionScreen( $connection, $client, $this->plugin->webhook_endpoint(), new Deprecations(), $this->plugin->templates(), ConnectionScreen::settings_url(), new WorkspaceSettings( $connection, $client, new RecordingLogger() ) );
	}

	/**
	 * A `GET` or `PATCH /v1/connect` answer, the store's own print file choices and the resolved ones over the workspace's.
	 *
	 * @param string|null                            $form      The store's own form.
	 * @param array{unit: string, value: float}|null $tolerance The store's own tolerance.
	 * @return array<string, mixed>
	 */
	private static function answer( ?string $form = null, ?array $tolerance = null ): array {
		$answer                                       = ConnectionAnswers::connection( true );
		$answer['siteUrl']                            = untrailingslashit( home_url() );
		$answer['printFileForm']                      = $form;
		$answer['printFileTextTolerance']             = $tolerance;
		$answer['printing']['printFileForm']          = $form ?? $answer['printing']['printFileForm'];
		$answer['printing']['printFileTextTolerance'] = $tolerance ?? $answer['printing']['printFileTextTolerance'];
		$answer['connectionId']                       = self::CONNECTION_ID;
		return $answer;
	}

	/**
	 * Posts the print file form as an admin.
	 *
	 * @param array<string, string> $fields The form's fields.
	 * @return string Where the admin was sent back to.
	 */
	private function save( array $fields ): string {
		$admin = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( is_int( $admin ) ? $admin : 0 );
		$_REQUEST['_wpnonce'] = wp_create_nonce( ConnectionScreen::PRINT_FILE_ACTION );
		$_POST                = $fields;
		add_filter(
			'wp_redirect',
			static function ( string $location ): string {
				throw new RuntimeException( $location ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- a test's redirect.
			}
		);
		try {
			$this->screen()->handle_print_file_form();
		} catch ( RuntimeException $redirect ) {
			return $redirect->getMessage();
		}
		$this->fail( 'Saving the print files did not send the admin back.' );
	}

	private function card_html(): string {
		ob_start();
		$this->plugin->templates()->render( 'admin/connection-card.php', $this->screen()->card() );
		return (string) ob_get_clean();
	}

	public function test_following_the_workspace_shows_its_form(): void {
		$this->recorder->json( 200, self::answer() );
		$this->recorder->json( 500, array( 'error' => 'internal_error' ) );

		$html = $this->card_html();

		$this->assertMatchesRegularExpression( '/<option value=""\s+selected=\'selected\'>\s*Follow the workspace \(Flat\)/', $html );
		$this->assertStringContainsString( 'Print files are made as: Flat.', $html );
		$this->assertStringContainsString( 'Empty follows the workspace: 1 px.', $html );
		$this->assertMatchesRegularExpression( '/<tr class="pixonaut-hidden">\s*<th scope="row"><label for="pixonaut-print-file-tolerance">/', $html );
		$this->assertStringContainsString( 'Save print files', $html );
		$this->assertMatchesRegularExpression( '/name="print_file_tolerance"[^>]*step="any"/', $html );
	}

	public function test_the_stores_own_live_text_shows_with_its_tolerance(): void {
		$tolerance = array(
			'unit'  => 'mm',
			'value' => 0.5,
		);
		$this->recorder->json( 200, self::answer( 'layered_live_text', $tolerance ) );
		$this->recorder->json( 500, array( 'error' => 'internal_error' ) );

		$html = $this->card_html();

		$this->assertMatchesRegularExpression( '/value="layered_live_text"\s+selected=\'selected\'/', $html );
		$this->assertStringContainsString( 'Print files are made as: Layers with live text, within 0.5 mm at text edges.', $html );
		$this->assertMatchesRegularExpression( '/name="print_file_tolerance"[^>]*value="0.5"/', $html );
		$this->assertMatchesRegularExpression( '/value="mm"\s+selected=\'selected\'/', $html );
		$this->assertMatchesRegularExpression( '/<tr class="">\s*<th scope="row"><label for="pixonaut-print-file-tolerance">/', $html );
	}

	public function test_saving_live_text_sends_it_and_keeps_the_answer(): void {
		$tolerance = array(
			'unit'  => 'px',
			'value' => 3.0,
		);
		$this->recorder->json( 200, self::answer( 'layered_live_text', $tolerance ) );

		$back = $this->save(
			array(
				ConnectionScreen::PRINT_FILE_FORM_ARG => 'layered_live_text',
				ConnectionScreen::TOLERANCE_ARG       => '3',
				ConnectionScreen::TOLERANCE_UNIT_ARG  => 'px',
			)
		);

		$this->assertStringContainsString( ConnectionScreen::RESULT_ARG . '=' . ConnectionScreen::PRINT_FILE_SAVED, $back );
		$this->assertSame( 'PATCH', $this->recorder->requests[0]['method'] );
		$this->assertSame(
			array(
				'printFileForm'          => 'layered_live_text',
				'printFileTextTolerance' => array(
					'unit'  => 'px',
					'value' => 3,
				),
			),
			json_decode( (string) $this->recorder->requests[0]['body'], true )
		);
		$kept = get_option( WorkspaceSettings::OPTION );
		$this->assertIsArray( $kept );
		$this->assertArrayHasKey( 'read_at', $kept );
	}

	public function test_following_the_workspace_sends_null(): void {
		$this->recorder->json( 200, self::answer() );

		$this->save(
			array(
				ConnectionScreen::PRINT_FILE_FORM_ARG => '',
				ConnectionScreen::TOLERANCE_ARG       => '3',
				ConnectionScreen::TOLERANCE_UNIT_ARG  => 'px',
			)
		);

		$this->assertSame(
			array(
				'printFileForm'          => null,
				'printFileTextTolerance' => null,
			),
			json_decode( (string) $this->recorder->requests[0]['body'], true )
		);
	}

	public function test_a_tolerance_out_of_its_limits_sends_nothing(): void {
		$back = $this->save(
			array(
				ConnectionScreen::PRINT_FILE_FORM_ARG => 'layered_live_text',
				ConnectionScreen::TOLERANCE_ARG       => '6',
				ConnectionScreen::TOLERANCE_UNIT_ARG  => 'mm',
			)
		);

		$this->assertStringContainsString( ConnectionScreen::RESULT_ARG . '=' . ConnectionScreen::PRINT_FILE_INVALID, $back );
		$this->assertSame( array(), $this->recorder->requests );
	}

	public function test_saving_a_form_this_release_does_not_know_keeps_it_and_sends_nothing(): void {
		$back = $this->save( array( ConnectionScreen::PRINT_FILE_FORM_ARG => 'engraved' ) );

		$this->assertStringContainsString( ConnectionScreen::RESULT_ARG . '=' . ConnectionScreen::PRINT_FILE_SAVED, $back );
		$this->assertSame( array(), $this->recorder->requests );
	}

	public function test_a_refusal_is_reported(): void {
		$this->recorder->json( 422, array( 'error' => 'validation_error' ) );

		$back = $this->save( array( ConnectionScreen::PRINT_FILE_FORM_ARG => 'flat' ) );

		$this->assertStringContainsString( ConnectionScreen::RESULT_ARG . '=' . ConnectionScreen::PRINT_FILE_FAILED, $back );
	}
}
