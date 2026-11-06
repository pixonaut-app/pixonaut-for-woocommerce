<?php
/**
 * @package Pixonaut\WooCommerce
 */

declare(strict_types=1);

namespace Pixonaut\WooCommerce\Tests\Integration\Fulfilment;

use Pixonaut\WooCommerce\Admin\ConnectionScreen;
use Pixonaut\WooCommerce\Admin\OrderBox;
use Pixonaut\WooCommerce\Api\Deprecations;
use Pixonaut\WooCommerce\Api\HookListener;
use Pixonaut\WooCommerce\Api\Pixonaut;
use Pixonaut\WooCommerce\Api\WcLogger;
use Pixonaut\WooCommerce\Connection\ConnectAttempts;
use Pixonaut\WooCommerce\Connection\Connection;
use Pixonaut\WooCommerce\Connection\StoredConnection;
use Pixonaut\WooCommerce\Emails\ProofReadyEmail;
use Pixonaut\WooCommerce\Fulfilment\LineActions;
use Pixonaut\WooCommerce\Fulfilment\LineDisplay;
use Pixonaut\WooCommerce\Fulfilment\LineState;
use Pixonaut\WooCommerce\Fulfilment\OrderEventLines;
use Pixonaut\WooCommerce\Fulfilment\OrderFailed;
use Pixonaut\WooCommerce\Fulfilment\OrderStatusChanged;
use Pixonaut\WooCommerce\Fulfilment\Shipments;
use Pixonaut\WooCommerce\Labels;
use Pixonaut\WooCommerce\Plugin;
use Pixonaut\WooCommerce\Proofs\ProofCache;
use Pixonaut\WooCommerce\Proofs\ProofUrls;
use Pixonaut\WooCommerce\Schema;
use Pixonaut\WooCommerce\Tests\Support\ConnectionAnswers;
use Pixonaut\WooCommerce\Tests\Support\FulfilmentAnswers;
use ReflectionProperty;
use WC_Emails;
use WC_Order_Item_Product;

/**
 * A line whose design's personal data was erased in Pixonaut, after the
 * workspace's retention period or on a privacy request, shown wherever
 * the line is: no proof image, no proof link, and a line saying the
 * personalisation was removed.
 */
final class ErasedLineTest extends FulfilmentTestCase {

	/**
	 * When the design was erased, epoch milliseconds.
	 */
	private const ERASED_AT_MS = 1_800_000_500_000;

	private const ORDER_ID = 'ord_erased';

	private const PROOF_PAGE = 'https://studio.example/p/';

	private Plugin $plugin;

	public function set_up(): void {
		$this->plugin = Plugin::boot( dirname( __DIR__, 4 ) . '/pixonaut-for-woocommerce.php' );
		parent::set_up();
		reset_phpmailer_instance();
		// The class the plugin's emails extend, without making a mailer that hooks them.
		include_once WC()->plugin_path() . '/includes/emails/class-wc-email.php';
		// Emails hook their triggers when WooCommerce's mailer is made; each test makes its own.
		( new ReflectionProperty( WC_Emails::class, 'instance' ) )->setValue( null, null );
	}

	public function tear_down(): void {
		reset_phpmailer_instance();
		parent::tear_down();
	}

	/**
	 * The order's line on $sides, each with a proof and a proof page, $erased erased.
	 *
	 * @param string[] $sides  Side keys.
	 * @param string[] $erased Side keys whose design was erased.
	 * @phpstan-param list<string> $sides
	 * @phpstan-param list<string> $erased
	 */
	private function line_on( array $sides, array $erased ): WC_Order_Item_Product {
		$this->order = $this->paid_order( $sides );
		$item        = current( $this->order->get_items() );
		$this->assertInstanceOf( WC_Order_Item_Product::class, $item );
		$this->item = $item;
		$line       = $this->line();
		foreach ( $sides as $side ) {
			$line->set_side_ref( $side, LineState::SIDE_PROOF_ID, 'prf_' . $side );
			$line->set_side_ref( $side, LineState::SIDE_PROOF_URL, self::PROOF_PAGE . $side );
		}
		foreach ( $erased as $side ) {
			$line->set_side_ref( $side, LineState::SIDE_ERASED_AT, self::ERASED_AT_MS );
		}
		$this->assertTrue( $line->save() );
		return new WC_Order_Item_Product( $item->get_id() );
	}

	private function removed(): string {
		return $this->plugin->labels()->get( Labels::PERSONALISATION_REMOVED );
	}

	public function test_the_removed_text_names_the_retention_period(): void {
		$this->assertStringContainsStringIgnoringCase( "removed after the shop's retention period", $this->removed() );
	}

	public function test_the_order_details_say_an_erased_lines_personalisation_was_removed(): void {
		$item = $this->line_on( array( 'front' ), array( 'front' ) );

		$rows = $item->get_formatted_meta_data();

		$this->assertArrayHasKey( LineDisplay::REMOVED_ROW, $rows );
		$this->assertSame( esc_html( $this->plugin->labels()->get( Labels::PERSONALISATION ) ), $rows[ LineDisplay::REMOVED_ROW ]->display_key );
		$this->assertSame( esc_html( $this->removed() ), $rows[ LineDisplay::REMOVED_ROW ]->display_value );
	}

	public function test_a_line_not_erased_shows_no_removed_row(): void {
		$item = $this->line_on( array( 'front' ), array() );

		$this->assertArrayNotHasKey( LineDisplay::REMOVED_ROW, $item->get_formatted_meta_data() );
	}

	/**
	 * @return array<string, array{0: string}>
	 */
	public static function email_types(): array {
		return array(
			'html'  => array( 'html' ),
			'plain' => array( 'plain' ),
		);
	}

	/**
	 * @dataProvider email_types
	 *
	 * @param string $type The email's format.
	 */
	public function test_the_proof_email_links_no_erased_side_and_says_it_was_removed( string $type ): void {
		update_option(
			'woocommerce_' . ProofReadyEmail::ID . '_settings',
			array(
				'enabled'    => 'yes',
				'email_type' => $type,
			)
		);
		$item = $this->line_on( array( 'front', 'back' ), array( 'back' ) );
		WC()->mailer();

		do_action( 'pixonaut_wc_fulfilment_awaiting_customer_notification', $item );

		$mailer = tests_retrieve_phpmailer_instance();
		$this->assertNotFalse( $mailer );
		$proofs = array_values( array_filter( $mailer->mock_sent, static fn ( array $mail ): bool => str_contains( (string) $mail['subject'], 'Please approve your design' ) ) );
		$this->assertCount( 1, $proofs );
		$body = html_entity_decode( (string) $proofs[0]['body'], ENT_QUOTES );
		$this->assertStringContainsString( self::PROOF_PAGE . 'front', $body );
		$this->assertStringNotContainsString( self::PROOF_PAGE . 'back', $body );
		$this->assertStringContainsString( $this->removed(), $body );
	}

	public function test_an_order_lines_design_keeps_its_erasure_on_the_line_only(): void {
		$this->line_on( array( 'front' ), array() );

		do_action( 'pixonaut_wc_webhook_design_personal_data_erased', array( 'designId' => self::DESIGN ) );
		delete_transient( Schema::PROOF_ERASED_PREFIX . md5( self::DESIGN ) );

		$this->assertFalse( ProofCache::is_erased( self::DESIGN ) );
	}

	public function test_the_order_box_shows_an_erased_side_without_its_proof_or_studio_link(): void {
		$this->line_on( array( 'front', 'back' ), array( 'back' ) );

		$html = $this->render_box();

		$this->assertStringContainsString( ConnectionAnswers::studio_path() . 'designs/' . self::DESIGN_PREFIX . 'front', $html );
		$this->assertStringNotContainsString( '/designs/' . self::DESIGN_PREFIX . 'back', $html );
		$this->assertSame( 1, substr_count( $html, '<img ' ) );
		$this->assertStringContainsString( "removed after the shop's retention period", html_entity_decode( $html, ENT_QUOTES ) );
	}

	public function test_the_order_box_says_when_the_orders_recipient_details_were_removed(): void {
		$this->line_on( array( 'front' ), array( 'front' ) );
		$line = $this->line();
		$line->set_ref( LineState::REF_ORDER_ERASED_AT, self::ERASED_AT_MS );
		$this->assertTrue( $line->save() );

		$html = html_entity_decode( $this->render_box(), ENT_QUOTES );

		$this->assertStringContainsString( "Recipient details removed after the shop's retention period", $html );
	}

	public function test_the_order_box_learns_of_a_recipient_erased_by_retention_from_the_order(): void {
		$this->line_on( array( 'front' ), array() );
		$line = $this->line();
		$line->set_ref( LineState::REF_ORDER_ID, self::ORDER_ID );
		$this->assertTrue( $line->save() );
		$this->assertTrue( $this->line()->transition( LineState::SHIPPED ) );
		$erased = array(
			'status'               => 'shipped',
			'personalDataErasedAt' => self::ERASED_AT_MS,
		);
		$this->recorder->json( 200, FulfilmentAnswers::read_order( self::ORDER_ID, (string) $this->line()->ref( LineState::REF_EXTERNAL_REF ), $erased ) );

		$first  = html_entity_decode( $this->render_box(), ENT_QUOTES );
		$second = html_entity_decode( $this->render_box(), ENT_QUOTES );

		$this->assertStringContainsString( "Recipient details removed after the shop's retention period", $first );
		$this->assertStringContainsString( "Recipient details removed after the shop's retention period", $second );
		$this->assertSame( self::ERASED_AT_MS, $this->line()->ref( LineState::REF_ORDER_ERASED_AT ) );
		$this->assertCount( 1, $this->recorder->requests, 'Once kept, the erasure is not asked about again.' );
	}

	public function test_the_order_box_shows_nothing_of_an_erasure_while_the_recipient_is_kept(): void {
		$this->line_on( array( 'front' ), array() );
		$line = $this->line();
		$line->set_ref( LineState::REF_ORDER_ID, self::ORDER_ID );
		$this->assertTrue( $line->save() );
		$this->assertTrue( $this->line()->transition( LineState::SHIPPED ) );
		$this->recorder->json( 200, FulfilmentAnswers::read_order( self::ORDER_ID, (string) $this->line()->ref( LineState::REF_EXTERNAL_REF ), array( 'status' => 'shipped' ) ) );

		$html = html_entity_decode( $this->render_box(), ENT_QUOTES );

		$this->assertStringNotContainsString( 'Recipient details removed', $html );
		$this->assertNull( $this->line()->ref( LineState::REF_ORDER_ERASED_AT ) );
	}

	private function render_box(): string {
		$user = self::factory()->user->create( array( 'role' => 'shop_manager' ) );
		$this->assertIsInt( $user );
		wp_set_current_user( $user );
		ob_start();
		( new OrderBox( $this->line_actions(), new ProofUrls( 'secret' ), $this->plugin->templates() ) )->render( wc_get_order( $this->order->get_id() ) );
		return (string) ob_get_clean();
	}

	/**
	 * The line actions, reading orders from the recorder.
	 */
	private function line_actions(): LineActions {
		$client     = ( new Pixonaut( $this->store, new WcLogger(), new HookListener( new Deprecations() ), null, self::BASE_URL ) )->sdk();
		$store      = $this->store;
		$connection = new Connection( $store, new ConnectAttempts(), $client, $this->plugin->notices(), new WcLogger(), ConnectionScreen::settings_url() );
		$lines      = new OrderEventLines( $connection, new WcLogger() );
		return new LineActions(
			$client,
			static fn (): ?StoredConnection => $store->load(),
			new OrderStatusChanged( $lines, new Shipments() ),
			new OrderFailed( $lines ),
			static fn (): bool => true,
			static fn (): bool => false,
			new WcLogger()
		);
	}
}
