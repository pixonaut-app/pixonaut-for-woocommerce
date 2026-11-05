<?php
/**
 * @package Pixonaut\WooCommerce
 */

declare(strict_types=1);

namespace Pixonaut\WooCommerce\Tests\Integration\Privacy;

use Pixonaut\WooCommerce\Api\Client;
use Pixonaut\WooCommerce\Api\Deprecations;
use Pixonaut\WooCommerce\Api\Designs;
use Pixonaut\WooCommerce\Api\Operations;
use Pixonaut\WooCommerce\Api\Orders;
use Pixonaut\WooCommerce\Api\WcLogger;
use Pixonaut\WooCommerce\Api\WpHttpTransport;
use Pixonaut\WooCommerce\Cart\FieldValue;
use Pixonaut\WooCommerce\Connection\StoredConnection;
use Pixonaut\WooCommerce\Fulfilment\FulfilmentJobs;
use Pixonaut\WooCommerce\Fulfilment\LineState;
use Pixonaut\WooCommerce\Fulfilment\OrderEventLines;
use Pixonaut\WooCommerce\Fulfilment\OrderFailed;
use Pixonaut\WooCommerce\Fulfilment\OrderStatus;
use Pixonaut\WooCommerce\Labels;
use Pixonaut\WooCommerce\Milliseconds;
use Pixonaut\WooCommerce\Plugin;
use Pixonaut\WooCommerce\Privacy\LineErasure;
use Pixonaut\WooCommerce\Privacy\PersonalDataEraser;
use Pixonaut\WooCommerce\Privacy\PersonalDataExporter;
use Pixonaut\WooCommerce\Privacy\PolicyText;
use Pixonaut\WooCommerce\Proofs\ProofUrls;
use Pixonaut\WooCommerce\Refs;
use Pixonaut\WooCommerce\Tests\Integration\Fulfilment\FulfilmentTestCase;
use Pixonaut\WooCommerce\Tests\Support\ActionSchedulerRunner;
use Pixonaut\WooCommerce\Tests\Support\DesignFixture;
use Pixonaut\WooCommerce\Tests\Support\FulfilmentAnswers;
use Pixonaut\WooCommerce\Webhooks\Event;
use WC_Order;
use WC_Order_Item_Product;

/**
 * Tools > Export and Erase Personal Data for a customer's personalised
 * lines: the export lists Pixonaut's ids; the erasure erases a line's designs
 * and printshop order in Pixonaut, never while a paid line is on its way.
 */
final class PrivacyTest extends FulfilmentTestCase {

	private const EMAIL = 'ada@example.org';

	private const ORDER_ID = 'o_1';

	/**
	 * When Pixonaut erased the personal data, epoch milliseconds.
	 */
	private const ERASED_AT_MS = 1_800_000_500_000;

	/**
	 * WooCommerce's "Retain completed orders" setting.
	 */
	private const RETAIN_COMPLETED_OPTION = 'woocommerce_anonymize_completed_orders';

	private const RETAIN_ONE_DAY = array(
		'number' => 1,
		'unit'   => 'days',
	);

	/**
	 * How old the completed order is: past the one day retained.
	 */
	private const DAYS_PAST_RETENTION = 3;

	private PersonalDataEraser $eraser;

	private LineErasure $erasure;

	public function set_up(): void {
		parent::set_up();
		$client  = new Client( self::BASE_URL, Operations::plugin(), $this->store, new WpHttpTransport(), new WcLogger(), new Deprecations() );
		$store   = $this->store;
		$erasure = new LineErasure( new Designs( $client ), new Orders( $client ), static fn (): ?StoredConnection => $store->load(), new WcLogger() );
		// The plugin's own transition hook stays; its job runs against the recorder.
		remove_all_actions( LineErasure::ACTION );
		add_action( LineErasure::ACTION, array( $erasure, 'run' ) );
		$this->erasure = $erasure;
		$this->eraser  = new PersonalDataEraser( $erasure );
		update_option( PersonalDataEraser::REMOVE_ORDER_DATA_OPTION, 'yes' );
	}

	public function tear_down(): void {
		delete_option( PersonalDataEraser::REMOVE_ORDER_DATA_OPTION );
		delete_option( self::RETAIN_COMPLETED_OPTION );
		parent::tear_down();
	}

	public function test_the_exporter_and_eraser_are_registered(): void {
		$this->assertArrayHasKey( PersonalDataExporter::ID, apply_filters( 'wp_privacy_personal_data_exporters', array() ) );
		$this->assertArrayHasKey( PersonalDataEraser::ID, apply_filters( 'wp_privacy_personal_data_erasers', array() ) );
	}

	public function test_the_export_lists_the_designs_and_printshop_order_of_each_line(): void {
		$line = $this->line();
		$line->set_side_ref(
			'front',
			LineState::SIDE_FIELDS,
			array(
				array(
					'label' => 'Name',
					'type'  => FieldValue::TEXT,
					'value' => 'Anna',
				),
			)
		);
		$this->assertTrue( $line->save() );
		$this->ordered();

		$export = ( new PersonalDataExporter( new Labels() ) )->export( self::EMAIL, 1 );

		$this->assertTrue( $export['done'] );
		$this->assertCount( 1, $export['data'] );
		$fields = array_column( $export['data'][0]['data'], 'value', 'name' );
		$this->assertSame( $this->order->get_order_number(), $fields['Order number'] );
		$this->assertSame( 'Personalised tee', $fields['Product'] );
		$this->assertSame( self::DESIGN, $fields['Design IDs'] );
		$this->assertSame( 'Anna', $fields['Name'] );
		$this->assertSame( self::ORDER_ID, $fields['Print order ID'] );
		$this->assertSame( array(), ( new PersonalDataExporter( new Labels() ) )->export( 'someone@example.org', 1 )['data'] );
	}

	public function test_a_paid_line_is_retained_while_the_store_keeps_order_data(): void {
		update_option( PersonalDataEraser::REMOVE_ORDER_DATA_OPTION, 'no' );
		$this->paid();
		$this->cancelled();

		$result = $this->eraser->erase( self::EMAIL, 1 );

		$this->assertFalse( $result['items_removed'] );
		$this->assertTrue( $result['items_retained'] );
		$this->assertStringContainsString( 'were retained with the order', implode( "\n", $result['messages'] ) );
		$this->assertSame( array(), $this->recorder->requests );
	}

	public function test_a_line_never_paid_for_is_erased_whatever_the_store_keeps(): void {
		update_option( PersonalDataEraser::REMOVE_ORDER_DATA_OPTION, 'no' );
		$this->recorder->json( 200, $this->erased_design() );

		$result = $this->eraser->erase( self::EMAIL, 1 );

		$this->assertTrue( $result['items_removed'] );
		$this->assertFalse( $result['items_retained'] );
		$this->assertSame( 1, $this->count_sent( 'POST', '/v1/designs/' . self::DESIGN . '/erase-personal-data' ) );
		$line = $this->line();
		$this->assertSame( self::ERASED_AT_MS, $line->side_ref( 'front', LineState::SIDE_ERASED_AT ) );
		$this->assertNull( $line->ref( LineState::REF_ERASE ) );
		$this->assertStringContainsString( 'personal data in "Personalised tee" was erased', $this->notes() );
	}

	public function test_an_in_flight_line_is_retained_then_erased_once_it_ships(): void {
		$this->paid();
		$this->ordered();

		$result = $this->eraser->erase( self::EMAIL, 1 );

		$this->assertFalse( $result['items_removed'] );
		$this->assertTrue( $result['items_retained'] );
		$this->assertStringContainsString( 'needed to fulfil the order', implode( "\n", $result['messages'] ) );
		$this->assertSame( array(), $this->recorder->requests );
		$this->assertSame( LineState::ORDERED, $this->line()->state() );
		$this->assertIsArray( $this->line()->ref( LineState::REF_ERASE ) );

		$this->recorder
			->json( 200, $this->erased_design() )
			->json( 200, FulfilmentAnswers::orders( array( $this->erased_order( 'shipped' ) ) ) )
			->json( 200, $this->erased_order( 'shipped' ) );
		$this->assertTrue( $this->line()->transition( LineState::SHIPPED ) );
		ActionSchedulerRunner::run_pending( LineErasure::ACTION );

		$line = $this->line();
		$this->assertSame( LineState::SHIPPED, $line->state() );
		$this->assertSame( self::ERASED_AT_MS, $line->side_ref( 'front', LineState::SIDE_ERASED_AT ) );
		$this->assertSame( self::ERASED_AT_MS, $line->ref( LineState::REF_ORDER_ERASED_AT ) );
		$this->assertNull( $line->ref( LineState::REF_ERASE ) );
		$this->assertTrue( LineErasure::is_erased( $line ) );
	}

	public function test_a_paid_line_not_yet_ordered_is_never_erased_mid_fulfilment(): void {
		$this->paid();
		$this->assertTrue( $this->line()->transition( LineState::APPROVED ) );

		$this->eraser->erase( self::EMAIL, 1 );
		ActionSchedulerRunner::run_pending( LineErasure::ACTION );

		$this->assertSame( array(), $this->recorder->requests );
		$this->assertSame( LineState::APPROVED, $this->line()->state() );
	}

	public function test_a_finished_line_is_erased_at_once(): void {
		$this->paid();
		$this->ordered();
		$this->cancelled();
		$this->recorder
			->json( 200, $this->erased_design() )
			->json( 200, FulfilmentAnswers::orders( array( $this->erased_order( 'cancelled' ) ) ) )
			->json( 200, $this->erased_order( 'cancelled' ) );

		$result = $this->eraser->erase( self::EMAIL, 1 );

		$this->assertTrue( $result['items_removed'] );
		$this->assertFalse( $result['items_retained'] );
		$this->assertSame( 1, $this->count_sent( 'POST', '/v1/orders/' . self::ORDER_ID . '/erase-personal-data' ) );
		$this->assertTrue( LineErasure::is_erased( $this->line() ) );

		// Erased already: asked again, nothing is sent.
		$again = $this->eraser->erase( self::EMAIL, 1 );
		$this->assertFalse( $again['items_removed'] );
		$this->assertFalse( $again['items_retained'] );
		$this->assertCount( 3, $this->recorder->requests );
	}

	/**
	 * @return array<string, array{bool}>
	 */
	public static function customers(): array {
		return array(
			'guest, by billing email' => array( false ),
			'customer account'        => array( true ),
		);
	}

	/**
	 * @dataProvider customers
	 *
	 * @param bool $account Whether the order belongs to a customer account with the address, under another billing email.
	 */
	public function test_the_eraser_runs_before_woocommerce_anonymises_the_order( bool $account ): void {
		if ( $account ) {
			$user = self::factory()->user->create( array( 'user_email' => self::EMAIL ) );
			$this->assertIsInt( $user );
			$this->order->set_customer_id( $user );
			$this->order->set_billing_email( 'billing@example.org' );
			$this->order->save();
		}
		$this->paid();
		$this->ordered();
		$this->cancelled();
		$this->recorder
			->json( 200, $this->erased_design() )
			->json( 200, FulfilmentAnswers::orders( array( $this->erased_order( 'cancelled' ) ) ) )
			->json( 200, $this->erased_order( 'cancelled' ) );
		$this->eraser->register();

		// As Tools > Erase Personal Data does: each eraser in turn, in the order registered.
		foreach ( apply_filters( 'wp_privacy_personal_data_erasers', array() ) as $eraser ) {
			call_user_func( $eraser['callback'], self::EMAIL, 1 );
		}

		$this->assertTrue( LineErasure::is_erased( $this->line() ) );
		$order = wc_get_order( $this->order->get_id() );
		$this->assertInstanceOf( WC_Order::class, $order );
		$this->assertNotSame( self::EMAIL, $order->get_billing_email() );
		$this->assertSame( 0, $order->get_customer_id() );
	}

	public function test_a_line_asked_to_be_erased_is_erased_once_its_printshop_order_fails(): void {
		$this->paid();
		$this->ordered();
		$this->assertStringContainsString( 'needed to fulfil the order', implode( "\n", $this->eraser->erase( self::EMAIL, 1 )['messages'] ) );
		$this->assertSame( array(), ActionSchedulerRunner::pending( LineErasure::ACTION ) );

		$this->order_failed();

		$this->assertSame( LineState::NEEDS_ATTENTION, $this->line()->state() );
		$this->assertCount( 1, ActionSchedulerRunner::pending( LineErasure::ACTION ) );
		$this->recorder
			->json( 200, $this->erased_design() )
			->json( 200, FulfilmentAnswers::orders( array( $this->erased_order( OrderStatus::FAILED ) ) ) )
			->json( 200, $this->erased_order( OrderStatus::FAILED ) );
		ActionSchedulerRunner::run_pending( LineErasure::ACTION );

		$this->assertSame( 1, $this->count_sent( 'POST', '/v1/designs/' . self::DESIGN . '/erase-personal-data' ) );
		$this->assertSame( 1, $this->count_sent( 'POST', '/v1/orders/' . self::ORDER_ID . '/erase-personal-data' ) );
		$this->assertTrue( LineErasure::is_erased( $this->line() ) );
	}

	public function test_a_line_whose_printshop_order_failed_is_erased_at_once(): void {
		$this->paid();
		$this->ordered();
		$this->order_failed();
		$this->recorder
			->json( 200, $this->erased_design() )
			->json( 200, FulfilmentAnswers::orders( array( $this->erased_order( OrderStatus::FAILED ) ) ) )
			->json( 200, $this->erased_order( OrderStatus::FAILED ) );

		$result = $this->eraser->erase( self::EMAIL, 1 );

		$this->assertTrue( $result['items_removed'] );
		$this->assertFalse( $result['items_retained'] );
		$this->assertTrue( LineErasure::is_erased( $this->line() ) );
	}

	public function test_a_line_paid_for_while_its_designs_are_erased_stops_and_waits(): void {
		$this->order = $this->paid_order( array( 'front', 'back' ) );
		$item        = current( $this->order->get_items() );
		$this->assertInstanceOf( WC_Order_Item_Product::class, $item );
		$this->item = $item;
		$this->recorder->json( 200, $this->erased_design() );
		$order = $this->order;
		add_action(
			'pixonaut_wc_api_after_request',
			static function () use ( $order ): void {
				// The payment lands while the first side is erased.
				$order->set_date_paid( time() );
				$order->save();
			}
		);

		$outcome = $this->erasure->request( $this->line() );

		$this->assertSame( LineErasure::WAITING, $outcome );
		$this->assertSame( 1, $this->count_sent( 'POST', '/v1/designs/' . self::DESIGN_PREFIX . 'front/erase-personal-data' ) );
		$this->assertSame( 0, $this->count_sent( 'POST', '/v1/designs/' . self::DESIGN_PREFIX . 'back/erase-personal-data' ) );
		$this->assertIsArray( $this->line()->ref( LineState::REF_ERASE ) );
		$this->assertFalse( LineErasure::is_erased( $this->line() ) );
	}

	public function test_a_failed_order_a_retry_replaced_is_erased_too(): void {
		$this->paid();
		$this->ordered();
		$this->cancelled();
		$replaced       = $this->erased_order( 'failed' );
		$replaced['id'] = 'o_0';
		$this->recorder
			->json( 200, $this->erased_design() )
			->json( 200, FulfilmentAnswers::orders( array( $this->erased_order( 'cancelled' ), $replaced ) ) )
			->json( 200, $this->erased_order( 'cancelled' ) )
			->json( 200, $replaced );

		$this->assertTrue( $this->eraser->erase( self::EMAIL, 1 )['items_removed'] );

		$this->assertSame( 1, $this->count_sent( 'POST', '/v1/orders/' . self::ORDER_ID . '/erase-personal-data' ) );
		$this->assertSame( 1, $this->count_sent( 'POST', '/v1/orders/o_0/erase-personal-data' ) );
	}

	public function test_an_order_still_on_its_way_in_pixonaut_is_asked_again_later(): void {
		$this->paid();
		$this->ordered();
		$this->cancelled();
		$this->recorder
			->json( 200, $this->erased_design() )
			->json( 200, FulfilmentAnswers::orders( array( $this->erased_order( 'cancelled' ) ) ) )
			->json( 409, array( 'error' => 'order_in_flight' ) );

		$result = $this->eraser->erase( self::EMAIL, 1 );

		$this->assertStringContainsString( 'could not be erased yet', implode( "\n", $result['messages'] ) );
		$this->assertCount( 1, ActionSchedulerRunner::pending( LineErasure::ACTION ) );
		$this->assertSame( self::ERASED_AT_MS, $this->line()->side_ref( 'front', LineState::SIDE_ERASED_AT ) );

		$this->recorder
			->json( 200, FulfilmentAnswers::orders( array( $this->erased_order( 'cancelled' ) ) ) )
			->json( 200, $this->erased_order( 'cancelled' ) );
		ActionSchedulerRunner::run_pending( LineErasure::ACTION );

		$this->assertSame( 1, $this->count_sent( 'POST', '/v1/designs/' . self::DESIGN . '/erase-personal-data' ) );
		$this->assertTrue( LineErasure::is_erased( $this->line() ) );
	}

	public function test_a_busy_design_is_asked_again_later(): void {
		$this->recorder->json( 503, array( 'error' => 'lock_timeout' ) );

		$result = $this->eraser->erase( self::EMAIL, 1 );

		$this->assertTrue( $result['items_retained'] );
		$this->assertCount( 1, ActionSchedulerRunner::pending( LineErasure::ACTION ) );

		$this->recorder->json( 200, $this->erased_design() );
		ActionSchedulerRunner::run_pending( LineErasure::ACTION );

		$this->assertTrue( LineErasure::is_erased( $this->line() ) );
	}

	public function test_a_refusal_for_good_is_noted_and_dropped(): void {
		$this->recorder->json( 403, array( 'error' => 'forbidden' ) );

		$result = $this->eraser->erase( self::EMAIL, 1 );

		$this->assertStringContainsString( 'The order notes say why', implode( "\n", $result['messages'] ) );
		$this->assertStringContainsString( 'which answered "forbidden"', $this->notes() );
		$this->assertNull( $this->line()->ref( LineState::REF_ERASE ) );
	}

	public function test_a_deleted_design_counts_as_erased_and_its_proofs_are_purged(): void {
		$cache = Plugin::boot( dirname( __DIR__, 4 ) . '/pixonaut-for-woocommerce.php' )->proof_cache();
		$width = ProofUrls::WIDTHS[0];
		$cache->put( self::DESIGN, DesignFixture::PROOF_ID, $width, 'jpeg' );
		$this->recorder->raw( 204, '', 'text/plain' );

		$this->assertTrue( $this->eraser->erase( self::EMAIL, 1 )['items_removed'] );

		$this->assertNotNull( $this->line()->side_ref( 'front', LineState::SIDE_ERASED_AT ) );
		$this->assertNull( $cache->get( self::DESIGN, DesignFixture::PROOF_ID, $width ) );
	}

	public function test_an_erasure_reported_by_pixonaut_purges_the_proofs(): void {
		$cache = Plugin::boot( dirname( __DIR__, 4 ) . '/pixonaut-for-woocommerce.php' )->proof_cache();
		$width = ProofUrls::WIDTHS[0];
		$cache->put( self::DESIGN, DesignFixture::PROOF_ID, $width, 'jpeg' );

		do_action( 'pixonaut_wc_webhook_design_personal_data_erased', array( 'designId' => self::DESIGN ) );

		$this->assertNull( $cache->get( self::DESIGN, DesignFixture::PROOF_ID, $width ) );
	}

	public function test_a_fetch_landing_after_the_erasure_is_not_cached(): void {
		$cache = Plugin::boot( dirname( __DIR__, 4 ) . '/pixonaut-for-woocommerce.php' )->proof_cache();
		$width = ProofUrls::WIDTHS[0];

		$cache->forget( self::DESIGN );
		$cache->put( self::DESIGN, DesignFixture::PROOF_ID, $width, 'jpeg' );

		$this->assertNull( $cache->get( self::DESIGN, DesignFixture::PROOF_ID, $width ) );
	}

	public function test_a_line_of_another_workspace_is_not_erased(): void {
		$line = $this->line();
		$line->set_ref( LineState::REF_TENANT_ID, 'tnt_other' );
		$this->assertTrue( $line->save() );

		$result = $this->eraser->erase( self::EMAIL, 1 );

		$this->assertTrue( $result['items_retained'] );
		$this->assertSame( array(), $this->recorder->requests );
		$this->assertStringContainsString( 'not connected to the Pixonaut workspace', $this->notes() );
	}

	public function test_the_retention_of_completed_orders_erases_a_shipped_line_at_pixonaut(): void {
		$this->assertNotFalse( has_action( PersonalDataEraser::BEFORE_REMOVE_ORDER_DATA_ACTION ) );
		$this->assertNotFalse( has_action( PersonalDataEraser::REMOVED_ORDER_DATA_ACTION ) );
		$this->retention_through_this_eraser();
		update_option( PersonalDataEraser::REMOVE_ORDER_DATA_OPTION, 'no' );
		$this->paid();
		$this->ordered();
		$this->assertTrue( $this->line()->transition( LineState::SHIPPED ) );
		$this->completed_long_ago();
		$this->recorder
			->json( 200, $this->erased_design() )
			->json( 200, FulfilmentAnswers::orders( array( $this->erased_order( 'shipped' ) ) ) )
			->json( 200, $this->erased_order( 'shipped' ) );

		$this->assertSame( 1, \WC_Privacy::anonymize_completed_orders() );

		$this->assertSame( 1, $this->count_sent( 'POST', '/v1/designs/' . self::DESIGN . '/erase-personal-data' ) );
		$this->assertSame( 1, $this->count_sent( 'POST', '/v1/orders/' . self::ORDER_ID . '/erase-personal-data' ) );
		$line = $this->line();
		$this->assertSame( self::ERASED_AT_MS, $line->side_ref( 'front', LineState::SIDE_ERASED_AT ) );
		$this->assertSame( self::ERASED_AT_MS, $line->ref( LineState::REF_ORDER_ERASED_AT ) );
		$this->assertTrue( LineErasure::is_erased( $line ) );
	}

	public function test_the_retention_keeps_an_in_flight_line_until_it_is_finished(): void {
		$this->retention_through_this_eraser();
		$this->paid();
		$this->ordered();
		$this->completed_long_ago();

		$this->assertSame( 1, \WC_Privacy::anonymize_completed_orders() );

		$this->assertSame( array(), $this->recorder->requests );
		$this->assertSame( LineState::ORDERED, $this->line()->state() );
		$this->assertIsArray( $this->line()->ref( LineState::REF_ERASE ) );
	}

	public function test_the_retention_keeps_the_note_of_a_line_it_could_not_erase(): void {
		$this->retention_through_this_eraser();
		$this->paid();
		$this->ordered();
		$this->assertTrue( $this->line()->transition( LineState::SHIPPED ) );
		$line = $this->line();
		$line->set_ref( LineState::REF_TENANT_ID, 'tnt_other' );
		$this->assertTrue( $line->save() );
		$this->completed_long_ago();

		$this->assertSame( 1, \WC_Privacy::anonymize_completed_orders() );

		$this->assertSame( array(), $this->recorder->requests );
		$notes = $this->notes();
		$this->assertStringContainsString( 'Personal data removed.', $notes );
		$this->assertStringContainsString( 'not connected to the Pixonaut workspace', $notes );
	}

	public function test_an_erasure_request_removing_order_data_asks_pixonaut_once(): void {
		$this->retention_through_this_eraser();
		$this->recorder->json( 200, $this->erased_design() );

		$this->assertTrue( $this->eraser->erase( self::EMAIL, 1 )['items_removed'] );
		$order = wc_get_order( $this->order->get_id() );
		$this->assertInstanceOf( WC_Order::class, $order );
		\WC_Privacy_Erasers::remove_order_personal_data( $order );

		$this->assertCount( 1, $this->recorder->requests );
	}

	public function test_the_policy_guide_shows_the_plugin_section(): void {
		require_once ABSPATH . 'wp-admin/includes/plugin.php';
		require_once ABSPATH . 'wp-admin/includes/class-wp-privacy-policy-content.php';
		set_current_screen( 'dashboard' );
		remove_all_actions( 'admin_init' );
		( new PolicyText() )->register();

		do_action( 'admin_init' );

		$sections = array_column( \WP_Privacy_Policy_Content::get_suggested_policy_text(), 'policy_text', 'plugin_name' );
		$this->assertArrayHasKey( PolicyText::PLUGIN_NAME, $sections );
		$text = $sections[ PolicyText::PLUGIN_NAME ];
		$this->assertStringContainsString( 'wp-suggested-text', $text );
		$this->assertStringContainsString( 'photos you upload', $text );
		$this->assertStringContainsString( 'shipping address', $text );
		$this->assertStringContainsString( 'Pixel', $text );
		$this->assertStringContainsString( PolicyText::SUBPROCESSORS_URL, $text );
	}

	/**
	 * WooCommerce removing an order's personal data reaches this test's
	 * eraser, which talks to the recorder, rather than the plugin's own.
	 */
	private function retention_through_this_eraser(): void {
		remove_all_actions( PersonalDataEraser::BEFORE_REMOVE_ORDER_DATA_ACTION );
		remove_all_actions( PersonalDataEraser::REMOVED_ORDER_DATA_ACTION );
		$this->eraser->register();
		update_option( self::RETAIN_COMPLETED_OPTION, self::RETAIN_ONE_DAY );
	}

	/**
	 * The order is completed, and older than the retention period.
	 */
	private function completed_long_ago(): void {
		$order = wc_get_order( $this->order->get_id() );
		$this->assertInstanceOf( WC_Order::class, $order );
		$order->set_status( 'completed' );
		$order->set_date_created( strtotime( '-' . self::DAYS_PAST_RETENTION . ' days' ) );
		$order->save();
		as_unschedule_all_actions( FulfilmentJobs::ACTION );
		as_unschedule_all_actions( LineErasure::ACTION );
		$this->order = $order;
	}

	/**
	 * Marks the order paid, as a payment does.
	 */
	private function paid(): void {
		$this->order->set_status( 'processing' );
		$this->order->set_date_paid( time() );
		$this->order->save();
		// Processing scheduled the fulfilment job; each test drives the line itself.
		as_unschedule_all_actions( FulfilmentJobs::ACTION );
	}

	private function ordered(): void {
		$line = $this->line();
		$line->set_ref( LineState::REF_ORDER_ID, self::ORDER_ID );
		$this->assertTrue( $line->transition( LineState::ORDERED ) );
	}

	/**
	 * Pixonaut reports the printshop order failed.
	 */
	private function order_failed(): void {
		$plugin = Plugin::boot( dirname( __DIR__, 4 ) . '/pixonaut-for-woocommerce.php' );
		$event  = new Event(
			'evt_failed',
			OrderFailed::TYPE,
			Milliseconds::of( time() ),
			array(
				'orderId'     => self::ORDER_ID,
				'externalRef' => Refs::order_item_ref( self::SITE, $this->order->get_id(), $this->item->get_id() ),
				'reason'      => 'Out of stock.',
			)
		);
		( new OrderFailed( new OrderEventLines( $plugin->connection(), new WcLogger() ) ) )->handle( $event );
		$this->assertSame( OrderStatus::FAILED, $this->line()->ref( LineState::REF_ORDER_STATUS ) );
	}

	private function cancelled(): void {
		$this->assertTrue( $this->line()->transition( LineState::CANCELLED ) );
		as_unschedule_all_actions( LineErasure::ACTION );
	}

	/**
	 * @return array<string, mixed> `200` of the design's erasure.
	 */
	private function erased_design(): array {
		return DesignFixture::design(
			array(
				'id'                   => self::DESIGN,
				'personalDataErasedAt' => self::ERASED_AT_MS,
				'editable'             => false,
				'canEdit'              => false,
			)
		);
	}

	/**
	 * @param string $status The order's final status.
	 *
	 * @return array<string, mixed> `200` of the order's erasure.
	 */
	private function erased_order( string $status ): array {
		return FulfilmentAnswers::order(
			self::ORDER_ID,
			array( 'p_front' ),
			Refs::order_item_ref( self::SITE, $this->order->get_id(), $this->item->get_id() ),
			array(
				'status'               => $status,
				'personalDataErasedAt' => self::ERASED_AT_MS,
			)
		);
	}

	/**
	 * The order's notes as one string.
	 */
	private function notes(): string {
		$notes = wc_get_order_notes( array( 'order_id' => $this->order->get_id() ) );
		return implode( "\n", array_map( static fn ( $note ): string => (string) $note->content, $notes ) );
	}
}
