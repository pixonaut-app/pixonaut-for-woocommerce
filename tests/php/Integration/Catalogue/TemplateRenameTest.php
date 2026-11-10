<?php
/**
 * @package Pixonaut\WooCommerce
 */

declare(strict_types=1);

namespace Pixonaut\WooCommerce\Tests\Integration\Catalogue;

use Pixonaut\WooCommerce\Cart\CartLine;
use Pixonaut\WooCommerce\Cart\CartCleanup;
use Pixonaut\WooCommerce\Catalogue\LayoutAdjustment;
use Pixonaut\WooCommerce\Catalogue\PrintSize;
use Pixonaut\WooCommerce\Catalogue\ProductConfig;
use Pixonaut\WooCommerce\Catalogue\ProductIndex;
use Pixonaut\WooCommerce\Catalogue\ProductRecheck;
use Pixonaut\WooCommerce\Catalogue\Side;
use Pixonaut\WooCommerce\Catalogue\StandingRow;
use Pixonaut\WooCommerce\Catalogue\TemplateRename;
use Pixonaut\WooCommerce\Catalogue\TemplateRenames;
use Pixonaut\WooCommerce\Fulfilment\LineState;
use Pixonaut\WooCommerce\Tests\Integration\Cart\PersonalisedCartTestCase;
use Pixonaut\WooCommerce\Tests\Support\ActionSchedulerRunner;
use Pixonaut\WooCommerce\Tests\Support\DesignFixture;
use Pixonaut\WooCommerce\Tests\Support\OpenApiAssert;
use Pixonaut\WooCommerce\Vendor\Pixonaut\Sdk\Webhooks\Signature;
use Pixonaut\WooCommerce\Webhooks\Processor;
use WC_Cart_Session;
use WC_Order;
use WC_Order_Item_Product;
use WC_Product;
use WC_Product_Simple;
use WC_Product_Variable;
use WC_Product_Variation;
use WP_REST_Request;

/**
 * `template.renamed`, delivered to the store's endpoint, moves every
 * reference the store holds to the template's new id, without asking
 * Pixonaut.
 */
final class TemplateRenameTest extends PersonalisedCartTestCase {

	/**
	 * The fixture connection's webhook signing secret.
	 */
	private const SECRET = 'whsec_fixture_main';

	private const NEW_ID = 'mini-tee-pandas';

	private const PINNED_VERSION = DesignFixture::LIVE_VERSION - 1;

	private const SAFARI_ID = 'mini-tee-safari';

	/**
	 * Milliseconds per second, as Pixonaut dates an event.
	 */
	private const MILLISECONDS = 1000;

	private int $sequence = 0;

	public function set_up(): void {
		parent::set_up();
		as_unschedule_all_actions( ProductRecheck::QUEUE_ACTION );
		delete_option( ProductRecheck::BACKFILL_OPTION );
		as_unschedule_all_actions( CartCleanup::ACTION );
		as_unschedule_all_actions( TemplateRename::ACTION );
	}

	public function tear_down(): void {
		delete_option( TemplateRenames::OPTION );
		as_unschedule_all_actions( CartCleanup::ACTION );
		as_unschedule_all_actions( TemplateRename::ACTION );
		parent::tear_down();
	}

	public function test_a_rename_moves_the_product_and_a_cart_line_made_before_it_still_buys(): void {
		$this->script_commit();
		$this->assertTrue( $this->post_add_to_cart(), implode( ' ', self::errors() ) );
		WC()->cart->set_session();
		$this->recorder->requests = array();

		$this->rename( DesignFixture::TEMPLATE_BASE_ID, self::NEW_ID );

		$side = $this->stored( $this->product_id )->sides[0];
		$this->assertSame( array( self::NEW_ID, DesignFixture::LIVE_VERSION, self::PREVIOUS_VERSION ), array( $side->template_base_id, $side->template_version, $side->previous_version ) );
		$this->assertSame( array( $this->product_id ), ProductIndex::using_template( self::NEW_ID, 0, TemplateRename::PAGE ) );
		$this->assertSame( array(), ProductIndex::using_template( DesignFixture::TEMPLATE_BASE_ID, 0, TemplateRename::PAGE ) );
		$this->assertSame( array(), $this->recorder->requests, 'Pixonaut is not asked' );

		self::reload_cart();
		$this->assertCount( 1, WC()->cart->get_cart(), 'the line made before the rename is kept' );
		$line = CartLine::of( (array) reset( WC()->cart->cart_contents ) );
		$this->assertNotNull( $line );
		$this->assertSame( self::NEW_ID, $line->sides[0]->template_base_id );

		// Pixonaut moved the designs: one made now names the new id.
		WC()->cart->empty_cart();
		$this->script_commit( array( 'templateBaseId' => self::NEW_ID ) );
		$this->assertTrue( $this->post_add_to_cart(), implode( ' ', self::errors() ) );
	}

	public function test_a_design_on_the_new_id_adds_before_the_rename_reaches_the_product(): void {
		TemplateRenames::record( DesignFixture::TEMPLATE_BASE_ID, self::NEW_ID, time() * self::MILLISECONDS );
		$this->script_commit( array( 'templateBaseId' => self::NEW_ID ) );

		$this->assertTrue( $this->post_add_to_cart(), implode( ' ', self::errors() ) );
	}

	public function test_a_rename_keeps_a_pinned_side_s_version_and_moves_its_layout_adjustments(): void {
		$adjusted = new LayoutAdjustment( DesignFixture::TEMPLATE_BASE_ID . '@' . self::PINNED_VERSION, DesignFixture::BLANK_ID, DesignFixture::PLACEMENT_ID, null );
		$side     = self::config()->sides[0];
		$standing = new Side(
			$side->key,
			$side->label,
			$side->placement_id,
			$side->placement_name,
			$side->template_base_id,
			self::PINNED_VERSION,
			null,
			$side->template_name,
			$side->print_sizes,
			mode: Side::STANDING,
			standing_rows: array( new StandingRow( array( 'Size' => 'XL' ), 'des_adjusted', array( 'v_xl' ), adjusted: $adjusted ) ),
			pinned: true,
			latest_version: DesignFixture::LIVE_VERSION
		);
		self::config()->with_sides( array( $standing ) )->save_to( $this->reload( $this->product_id ) );

		$this->rename( DesignFixture::TEMPLATE_BASE_ID, self::NEW_ID );

		$moved = $this->stored( $this->product_id )->sides[0];
		$this->assertSame( array( self::NEW_ID, self::PINNED_VERSION, true, DesignFixture::LIVE_VERSION ), array( $moved->template_base_id, $moved->template_version, $moved->pinned, $moved->latest_version ) );
		$this->assertSame( self::NEW_ID . '@' . self::PINNED_VERSION, $moved->standing_rows[0]->adjusted?->template_id );
		$this->assertTrue( $moved->standing_rows[0]->adjusted->holds( self::NEW_ID, DesignFixture::BLANK_ID, DesignFixture::PLACEMENT_ID ) );
	}

	public function test_a_rename_moves_a_variation_s_own_sides_and_leaves_other_templates(): void {
		$product = new WC_Product_Variable();
		$product->set_name( 'Personalised hoodie' );
		$product->save();
		$variation = new WC_Product_Variation();
		$variation->set_parent_id( $product->get_id() );
		$variation->set_regular_price( '30' );
		$variation->save();
		self::config()->with_override( true )->save_to( $variation );
		$other = new WC_Product_Simple();
		$other->set_name( 'Mug' );
		$other->save();
		self::config()->with_sides( array( self::side_on( 'mug' ) ) )->save_to( $other );

		$this->rename( DesignFixture::TEMPLATE_BASE_ID, self::NEW_ID );

		$this->assertSame( self::NEW_ID, $this->stored( $variation->get_id() )->sides[0]->template_base_id );
		$this->assertSame( 'mug', $this->stored( $other->get_id() )->sides[0]->template_base_id );
		$this->assertSame( array( $this->product_id, $variation->get_id() ), ProductIndex::using_template( self::NEW_ID, 0, TemplateRename::PAGE ) );
	}

	public function test_a_rename_moves_the_order_lines_made_from_the_template(): void {
		$moved = $this->order_line( DesignFixture::TEMPLATE_BASE_ID );
		$kept  = $this->order_line( 'mug' );

		$this->rename( DesignFixture::TEMPLATE_BASE_ID, self::NEW_ID );

		$this->assertSame( self::NEW_ID, LineState::of( $this->item( $moved ) )->side_ref( 'front', LineState::SIDE_TEMPLATE_BASE_ID ) );
		$this->assertSame( DesignFixture::LIVE_VERSION, LineState::of( $this->item( $moved ) )->side_ref( 'front', LineState::SIDE_TEMPLATE_VERSION ) );
		$this->assertSame( 'mug', LineState::of( $this->item( $kept ) )->side_ref( 'front', LineState::SIDE_TEMPLATE_BASE_ID ) );
	}

	public function test_a_rename_delivered_twice_changes_nothing_more(): void {
		$this->order_line( DesignFixture::TEMPLATE_BASE_ID );
		$at     = $this->rename( DesignFixture::TEMPLATE_BASE_ID, self::NEW_ID );
		$config = ProductConfig::document_on( $this->reload( $this->product_id ) );
		$line   = $this->line_documents();
		$stored = get_option( TemplateRenames::OPTION );

		$this->rename( DesignFixture::TEMPLATE_BASE_ID, self::NEW_ID, $at );

		$this->assertSame( $config, ProductConfig::document_on( $this->reload( $this->product_id ) ) );
		$this->assertSame( $line, $this->line_documents() );
		$this->assertSame( $stored, get_option( TemplateRenames::OPTION ) );
		$this->assertCount( 1, $stored );
		$this->assertSame( array(), ActionSchedulerRunner::pending( Processor::PROCESS_HOOK ), 'no retry is queued' );
	}

	public function test_a_rename_of_a_template_the_store_does_not_use_changes_no_product(): void {
		$config = ProductConfig::document_on( $this->reload( $this->product_id ) );

		$this->rename( 'never-used', 'never-used-either' );

		$this->assertSame( $config, ProductConfig::document_on( $this->reload( $this->product_id ) ) );
		$this->assertSame( array(), ActionSchedulerRunner::pending( Processor::PROCESS_HOOK ), 'no retry is queued' );
	}

	public function test_a_chain_of_renames_ends_on_the_last_id_and_keeps_the_cart_line(): void {
		$this->script_commit();
		$this->assertTrue( $this->post_add_to_cart(), implode( ' ', self::errors() ) );
		WC()->cart->set_session();

		$this->rename( DesignFixture::TEMPLATE_BASE_ID, self::NEW_ID );
		$this->rename( self::NEW_ID, self::SAFARI_ID );

		$this->assertSame( self::SAFARI_ID, $this->stored( $this->product_id )->sides[0]->template_base_id );
		self::reload_cart();
		$this->assertCount( 1, WC()->cart->get_cart() );
		$this->assertSame( self::SAFARI_ID, CartLine::of( (array) reset( WC()->cart->cart_contents ) )?->sides[0]->template_base_id );
	}

	public function test_a_rename_handled_again_after_a_later_one_moves_what_is_left_to_the_last_id(): void {
		$this->script_commit();
		$this->assertTrue( $this->post_add_to_cart(), implode( ' ', self::errors() ) );
		WC()->cart->set_session();
		$at = $this->rename( DesignFixture::TEMPLATE_BASE_ID, self::NEW_ID );
		$this->rename( self::NEW_ID, self::SAFARI_ID );
		$left = $this->order_line( DesignFixture::TEMPLATE_BASE_ID );

		// Pixonaut delivers the first change again, as a new event.
		$this->rename( DesignFixture::TEMPLATE_BASE_ID, self::NEW_ID, $at );

		$this->assertSame( self::SAFARI_ID, TemplateRenames::current( DesignFixture::TEMPLATE_BASE_ID ) );
		$this->assertSame( DesignFixture::TEMPLATE_BASE_ID, TemplateRenames::first( self::SAFARI_ID ) );
		$this->assertSame( self::SAFARI_ID, $this->stored( $this->product_id )->sides[0]->template_base_id );
		$this->assertSame( self::SAFARI_ID, LineState::of( $this->item( $left ) )->side_ref( 'front', LineState::SIDE_TEMPLATE_BASE_ID ) );
		self::reload_cart();
		$this->assertCount( 1, WC()->cart->get_cart(), 'the line made before the renames is kept' );
		$this->assertSame( self::SAFARI_ID, CartLine::of( (array) reset( WC()->cart->cart_contents ) )?->sides[0]->template_base_id );
	}

	public function test_a_template_taking_its_id_back_keeps_the_cart_line(): void {
		$this->script_commit();
		$this->assertTrue( $this->post_add_to_cart(), implode( ' ', self::errors() ) );
		WC()->cart->set_session();

		$this->rename( DesignFixture::TEMPLATE_BASE_ID, self::NEW_ID );
		$this->rename( self::NEW_ID, DesignFixture::TEMPLATE_BASE_ID );

		$this->assertSame( DesignFixture::TEMPLATE_BASE_ID, $this->stored( $this->product_id )->sides[0]->template_base_id );
		$this->assertSame( DesignFixture::TEMPLATE_BASE_ID, TemplateRenames::first( DesignFixture::TEMPLATE_BASE_ID ) );
		self::reload_cart();
		$this->assertCount( 1, WC()->cart->get_cart(), 'the line made before the renames is kept' );
		$this->assertSame( DesignFixture::TEMPLATE_BASE_ID, CartLine::of( (array) reset( WC()->cart->cart_contents ) )?->sides[0]->template_base_id );
	}

	public function test_a_rename_that_cannot_be_stored_moves_nothing_and_is_tried_again(): void {
		$config = ProductConfig::document_on( $this->reload( $this->product_id ) );
		add_filter( 'query', array( self::class, 'refuse_writes_to_the_history' ) );

		$this->rename( DesignFixture::TEMPLATE_BASE_ID, self::NEW_ID );

		remove_filter( 'query', array( self::class, 'refuse_writes_to_the_history' ) );
		$this->assertSame( $config, ProductConfig::document_on( $this->reload( $this->product_id ) ) );
		$this->assertFalse( get_option( TemplateRenames::OPTION ) );
		$this->assertCount( 1, ActionSchedulerRunner::pending( Processor::PROCESS_HOOK ), 'the event is tried again' );
		as_unschedule_all_actions( Processor::PROCESS_HOOK );
	}

	public function test_renames_stored_at_once_are_both_kept(): void {
		$wrote = false;
		$other = static function ( string $query ) use ( &$wrote ): string {
			if ( ! $wrote && self::writes_the_history( $query ) ) {
				$wrote = true;
				// Another request notes another template's change between this one's read and write.
				TemplateRenames::record( 'mug', 'mug-pandas', time() * self::MILLISECONDS );
			}
			return $query;
		};
		add_filter( 'query', $other );

		TemplateRenames::record( DesignFixture::TEMPLATE_BASE_ID, self::NEW_ID, time() * self::MILLISECONDS );

		remove_filter( 'query', $other );
		$this->assertTrue( $wrote );
		$this->assertSame( self::NEW_ID, TemplateRenames::current( DesignFixture::TEMPLATE_BASE_ID ) );
		$this->assertSame( 'mug-pandas', TemplateRenames::current( 'mug' ) );
	}

	public function test_a_rename_moves_a_page_per_job_and_queues_the_rest(): void {
		$ids = array( $this->product_id );
		for ( $made = count( $ids ); $made <= TemplateRename::PAGE; $made++ ) {
			$product = new WC_Product_Simple();
			$product->set_name( 'Personalised tee ' . $made );
			$product->save();
			self::config()->save_to( $product );
			$ids[] = $product->get_id();
		}

		$this->rename( DesignFixture::TEMPLATE_BASE_ID, self::NEW_ID );

		$this->assertSame( array( end( $ids ) ), ProductIndex::using_template( DesignFixture::TEMPLATE_BASE_ID, 0, TemplateRename::PAGE ), 'one page moved' );
		$this->assertCount( 1, ActionSchedulerRunner::pending( TemplateRename::ACTION ) );

		ActionSchedulerRunner::run_pending( TemplateRename::ACTION );

		$this->assertSame( array(), ProductIndex::using_template( DesignFixture::TEMPLATE_BASE_ID, 0, TemplateRename::PAGE ) );
		$this->assertSame( $ids, ProductIndex::using_template( self::NEW_ID, 0, TemplateRename::PAGE + 1 ) );
	}

	/**
	 * The database refuses writes to the history.
	 *
	 * @param string $query A query.
	 */
	public static function refuse_writes_to_the_history( string $query ): string {
		return self::writes_the_history( $query ) ? '' : $query;
	}

	private static function writes_the_history( string $query ): bool {
		return str_contains( $query, TemplateRenames::OPTION ) && 1 === preg_match( '/^\s*(UPDATE|INSERT)/i', $query );
	}

	/**
	 * Delivers `template.renamed` signed as Pixonaut signs it, then runs its job.
	 *
	 * @param string   $from The old base id.
	 * @param string   $to   The new one.
	 * @param int|null $at   When it changed, in milliseconds; null for a moment later than every rename before.
	 * @return int When it changed.
	 */
	private function rename( string $from, string $to, ?int $at = null ): int {
		// One millisecond apart, as two renames are at the least.
		$at ??= time() * self::MILLISECONDS + $this->sequence + 1;
		$body = (string) wp_json_encode(
			array(
				'id'        => 'evt_rename_' . ( ++$this->sequence ),
				'type'      => TemplateRename::TYPE,
				'payload'   => array(
					'oldId'     => $from,
					'newId'     => $to,
					'renamedAt' => $at,
				),
				'createdAt' => time() * self::MILLISECONDS,
			)
		);
		OpenApiAssert::webhook( TemplateRename::TYPE, $body );
		$timestamp = (string) time();
		$request   = new WP_REST_Request( 'POST', '/pixonaut/v1/webhook' );
		$request->set_header( 'content-type', 'application/json' );
		$request->set_header( Signature::TIMESTAMP_HEADER, $timestamp );
		$request->set_header( Signature::SIGNATURE_HEADER, 'v1=' . hash_hmac( 'sha256', "{$timestamp}.{$body}", self::SECRET ) );
		$request->set_body( $body );
		$this->assertSame( 200, rest_do_request( $request )->get_status() );

		ActionSchedulerRunner::run_once( Processor::PROCESS_HOOK );
		return $at;
	}

	/**
	 * An order line whose front side was made from a template.
	 *
	 * @param string $base_id The template's base id.
	 * @return int The order item's id.
	 */
	private function order_line( string $base_id ): int {
		$order = new WC_Order();
		$item  = new WC_Order_Item_Product();
		$item->set_name( 'Personalised tee' );
		$item->set_quantity( 1 );
		$order->add_item( $item );
		$order->set_status( 'processing' );
		$order->save();
		$line = LineState::start( $item, array( 'front' ) );
		$line->set_side_ref( 'front', LineState::SIDE_DESIGN_ID, DesignFixture::ID );
		$line->set_side_ref( 'front', LineState::SIDE_TEMPLATE_BASE_ID, $base_id );
		$line->set_side_ref( 'front', LineState::SIDE_TEMPLATE_VERSION, DesignFixture::LIVE_VERSION );
		$this->assertTrue( $line->save() );
		return $item->get_id();
	}

	private function item( int $item_id ): WC_Order_Item_Product {
		$item = \WC_Order_Factory::get_order_item( $item_id );
		$this->assertInstanceOf( WC_Order_Item_Product::class, $item );
		return $item;
	}

	/**
	 * @return list<string> Every stored order line document.
	 */
	private function line_documents(): array {
		global $wpdb;
		return array_values( $wpdb->get_col( $wpdb->prepare( "SELECT meta_value FROM {$wpdb->prefix}woocommerce_order_itemmeta WHERE meta_key = %s ORDER BY meta_id", LineState::META_KEY ) ) );
	}

	private static function side_on( string $base_id ): Side {
		return new Side( 'front', 'Front', DesignFixture::PLACEMENT_ID, 'Front', $base_id, DesignFixture::LIVE_VERSION, null, 'Mug', array( new PrintSize( DesignFixture::WIDTH_MM, DesignFixture::HEIGHT_MM, 'Large' ) ) );
	}

	/**
	 * Loads the cart from the session, as the customer's next request does.
	 */
	private static function reload_cart(): void {
		WC()->cart->set_cart_contents( array() );
		( new WC_Cart_Session( WC()->cart ) )->get_cart_from_session();
	}

	private function stored( int $product_id ): ProductConfig {
		return ProductConfig::stored_on( $this->reload( $product_id ) );
	}

	private function reload( int $product_id ): WC_Product {
		$product = wc_get_product( $product_id );
		$this->assertInstanceOf( WC_Product::class, $product );
		return $product;
	}
}
