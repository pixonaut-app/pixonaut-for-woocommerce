<?php
/**
 * @package Pixonaut\WooCommerce
 */

declare(strict_types=1);

namespace Pixonaut\WooCommerce\Tests\Integration\Catalogue;

use Pixonaut\WooCommerce\Admin\InvalidProductNotice;
use Pixonaut\WooCommerce\Admin\VariantNotice;
use Pixonaut\WooCommerce\Api\Catalogue;
use Pixonaut\WooCommerce\Catalogue\InvalidReason;
use Pixonaut\WooCommerce\Catalogue\PrintSize;
use Pixonaut\WooCommerce\Catalogue\ProductConfig;
use Pixonaut\WooCommerce\Catalogue\ProductIndex;
use Pixonaut\WooCommerce\Catalogue\ProductPictures;
use Pixonaut\WooCommerce\Catalogue\ProductRecheck;
use Pixonaut\WooCommerce\Catalogue\ProductVariant;
use Pixonaut\WooCommerce\Catalogue\Side;
use Pixonaut\WooCommerce\Migrations\IndexProductConfigs;
use Pixonaut\WooCommerce\Migrations\Migration;
use Pixonaut\WooCommerce\Migrations\RecheckTemplateOnlySizes;
use Pixonaut\WooCommerce\Migrations\ResolveProductVariants;
use Pixonaut\WooCommerce\Migrations\ResolveVariantViews;
use Pixonaut\WooCommerce\Tests\Integration\Cart\PersonalisedCartTestCase;
use Pixonaut\WooCommerce\Tests\Support\ActionSchedulerRunner;
use Pixonaut\WooCommerce\Tests\Support\BlankFixture;
use Pixonaut\WooCommerce\Tests\Support\DesignFixture;
use Pixonaut\WooCommerce\Tests\Support\OpenApiAssert;
use Pixonaut\WooCommerce\Tests\Support\PngFixture;
use Pixonaut\WooCommerce\Tests\Support\ShopListing;
use Pixonaut\WooCommerce\Vendor\Pixonaut\Sdk\Webhooks\Signature;
use Pixonaut\WooCommerce\Webhooks\EventStore;
use Pixonaut\WooCommerce\Webhooks\Processor;
use WC_Product;
use WC_Product_Attribute;
use WC_Product_Simple;
use WC_Product_Variable;
use WC_Product_Variation;
use WP_REST_Request;

/**
 * Template and blank events, delivered to the store's endpoint, re-check the
 * products that use them against what Pixonaut answers.
 */
final class ProductRecheckTest extends PersonalisedCartTestCase {

	/**
	 * The fixture connection's webhook signing secret.
	 */
	private const SECRET = 'whsec_fixture_main';

	private const OTHER_TEMPLATE = 'other-tee';

	private const OTHER_BLANK = 'blank_other';

	private const NEW_VERSION = DesignFixture::LIVE_VERSION + 1;

	/**
	 * A base id that reads like `<baseId>@<version>`.
	 */
	private const VERSIONED_LOOKING_BASE_ID = 'shirt@2';

	private const BACK_PLACEMENT_ID = 'back';

	private const AXIS = 'Colour';

	/**
	 * The `_pixonaut_variant` version stored before views were named by id.
	 */
	private const VARIANT_VERSION_BEFORE_VIEW_IDS = 1;

	private int $sequence = 0;

	public function set_up(): void {
		parent::set_up();
		// Upgrading queues one re-check of every product, which these tests do not expect.
		as_unschedule_all_actions( ProductRecheck::QUEUE_ACTION );
		delete_option( ProductRecheck::BACKFILL_OPTION );
	}

	public function test_a_published_version_rolls_the_product_on_and_a_proof_from_the_one_replaced_still_adds(): void {
		$this->script_recheck( array( DesignFixture::TEMPLATE_BASE_ID => self::NEW_VERSION ) );

		$this->deliver(
			ProductRecheck::TEMPLATE_PUBLISHED,
			array(
				'templateId' => DesignFixture::TEMPLATE_BASE_ID . '@' . self::NEW_VERSION,
				'version'    => self::NEW_VERSION,
			)
		);

		$side = $this->stored( $this->product_id )->sides[0];
		$this->assertSame( array( self::NEW_VERSION, DesignFixture::LIVE_VERSION ), array( $side->template_version, $side->previous_version ) );
		$this->assertSame( 'POST /v1/templates/' . DesignFixture::TEMPLATE_BASE_ID . '@' . self::NEW_VERSION . '/print-size', $this->requested()[2] );
		$this->assertTrue( $this->stored( $this->product_id )->valid );

		$this->script_commit( array( 'templateVersion' => DesignFixture::LIVE_VERSION ) );
		$this->assertTrue( $this->post_add_to_cart(), implode( ' ', self::errors() ) );
	}

	public function test_a_published_version_draws_the_products_picture_from_it(): void {
		$this->draw_picture();
		$this->script_recheck( array( DesignFixture::TEMPLATE_BASE_ID => self::NEW_VERSION ) );

		$this->deliver(
			ProductRecheck::TEMPLATE_PUBLISHED,
			array(
				'templateId' => DesignFixture::TEMPLATE_BASE_ID . '@' . self::NEW_VERSION,
				'version'    => self::NEW_VERSION,
			)
		);
		$this->recorder->requests = array();
		$this->draw_picture();

		$this->assertCount( 1, $this->recorder->requests );
		$this->assertStringContainsString( '/t/' . DesignFixture::TEMPLATE_BASE_ID . '%40' . self::NEW_VERSION . '/mockup.png?', $this->recorder->requests[0]['url'] );
		$this->assertSame( ProductPictures::basis( $this->reload( $this->product_id ) ), ProductPictures::stored( $this->reload( $this->product_id ) )['basis'] );
	}

	public function test_a_published_version_the_product_is_drawn_from_already_draws_nothing(): void {
		$published = array(
			'templateId' => DesignFixture::TEMPLATE_BASE_ID . '@' . DesignFixture::LIVE_VERSION,
			'version'    => DesignFixture::LIVE_VERSION,
		);
		// Re-checked once, so the product holds what Pixonaut answers.
		$this->script_recheck( array( DesignFixture::TEMPLATE_BASE_ID => DesignFixture::LIVE_VERSION ) );
		$this->deliver( ProductRecheck::TEMPLATE_PUBLISHED, $published );
		$this->draw_picture();
		$this->script_recheck( array( DesignFixture::TEMPLATE_BASE_ID => DesignFixture::LIVE_VERSION ) );

		$this->deliver( ProductRecheck::TEMPLATE_PUBLISHED, $published );

		$this->assertSame( array(), ActionSchedulerRunner::pending( ProductPictures::ACTION ) );
	}

	public function test_a_published_version_finds_the_standing_design_of_the_new_version(): void {
		$product = $this->reload( $this->product_id );
		$side    = self::config()->sides[0];
		self::config()->with_sides( array( new Side( $side->key, $side->label, $side->placement_id, $side->placement_name, $side->template_base_id, $side->template_version, $side->previous_version, $side->template_name, $side->print_sizes, mode: Side::STANDING, standing_design_id: 'des_standing_old' ) ) )->save_to( $product );
		$this->script_recheck( array( DesignFixture::TEMPLATE_BASE_ID => self::NEW_VERSION ) );
		$this->recorder->raw( 201, OpenApiAssert::example( 'POST', '/v1/designs/standing', 201, array( 'designId' => 'des_standing_new' ) ), 'application/json' );

		$this->deliver(
			ProductRecheck::TEMPLATE_PUBLISHED,
			array(
				'templateId' => DesignFixture::TEMPLATE_BASE_ID . '@' . self::NEW_VERSION,
				'version'    => self::NEW_VERSION,
			)
		);

		$stored = $this->stored( $this->product_id );
		$this->assertTrue( $stored->valid );
		$this->assertSame( 'des_standing_new', $stored->sides[0]->standing_design_id );
		$this->assertSame( 'POST /v1/designs/standing', $this->requested()[3] );
		$this->assertSame( DesignFixture::TEMPLATE_BASE_ID . '@' . self::NEW_VERSION, json_decode( (string) $this->recorder->requests[3]['body'], true )['templateId'] );
	}

	public function test_an_event_rechecks_only_the_products_using_its_template_or_blank(): void {
		$other = $this->product( self::OTHER_TEMPLATE, self::OTHER_BLANK );
		$this->script_recheck( array( DesignFixture::TEMPLATE_BASE_ID => DesignFixture::LIVE_VERSION ) );

		$this->deliver(
			ProductRecheck::TEMPLATE_LIVE_CHANGED,
			array(
				'baseId' => DesignFixture::TEMPLATE_BASE_ID,
				'live'   => true,
			)
		);

		$this->assertSame( array( 'GET /v1/templates', 'GET /v1/blanks/' . DesignFixture::BLANK_ID, 'POST /v1/templates/' . DesignFixture::TEMPLATE_BASE_ID . '@' . DesignFixture::LIVE_VERSION . '/print-size' ), $this->requested() );

		$this->recorder->requests = array();
		$this->script_recheck( array( self::OTHER_TEMPLATE => DesignFixture::LIVE_VERSION ) );
		$this->deliver(
			ProductRecheck::BLANK_UPDATED,
			array(
				'blankId' => self::OTHER_BLANK,
				'fields'  => array( 'placements' ),
			)
		);

		$this->assertSame( array( 'GET /v1/templates', 'GET /v1/blanks/' . self::OTHER_BLANK, 'POST /v1/templates/' . self::OTHER_TEMPLATE . '@' . DesignFixture::LIVE_VERSION . '/print-size' ), $this->requested() );
		$this->assertTrue( $this->stored( $other )->valid );
	}

	public function test_a_blank_update_keeps_what_was_read_of_the_template_version_and_a_new_version_forgets_it(): void {
		$product = $this->reload( $this->product_id );
		self::config()->with_sides( array( self::config()->sides[0]->with_offers_properties( false ) ) )->save_to( $product );
		$this->script_recheck( array( DesignFixture::TEMPLATE_BASE_ID => DesignFixture::LIVE_VERSION ) );

		$this->deliver_blank_updated();

		$this->assertFalse( $this->stored( $this->product_id )->sides[0]->offers_properties );

		$this->script_recheck( array( DesignFixture::TEMPLATE_BASE_ID => self::NEW_VERSION ) );
		$this->deliver(
			ProductRecheck::TEMPLATE_PUBLISHED,
			array(
				'templateId' => DesignFixture::TEMPLATE_BASE_ID . '@' . self::NEW_VERSION,
				'version'    => self::NEW_VERSION,
			)
		);

		$this->assertNull( $this->stored( $this->product_id )->sides[0]->offers_properties );
	}

	public function test_a_variant_retired_in_pixonaut_stops_sales_with_a_notice_until_it_is_sold_again(): void {
		// Background jobs run without a user.
		wp_set_current_user( 0 );
		$this->variants->answer = BlankFixture::variants( array( array( 'retired' => array( 'reason' => 'retired' ) ) ) );
		$this->script_recheck( array( DesignFixture::TEMPLATE_BASE_ID => DesignFixture::LIVE_VERSION ) );

		$this->deliver_blank_updated();

		$this->assertSame( 'retired', ProductVariant::stored_on( $this->reload( $this->product_id ) )?->retired );
		$this->assertFalse( $this->reload( $this->product_id )->is_purchasable() );
		$this->assertArrayHasKey( VariantNotice::key( $this->product_id ), $this->plugin->notices()->all() );

		$this->variants->answer = BlankFixture::variants();
		$this->script_recheck( array( DesignFixture::TEMPLATE_BASE_ID => DesignFixture::LIVE_VERSION ) );
		$this->deliver_blank_updated();

		$this->assertTrue( $this->reload( $this->product_id )->is_purchasable() );
		$this->assertArrayNotHasKey( VariantNotice::key( $this->product_id ), $this->plugin->notices()->all() );
	}

	public function test_a_paused_template_stops_sales_with_a_notice_and_resuming_it_sells_again(): void {
		// Background jobs run without a user.
		wp_set_current_user( 0 );
		$this->live_list( array() );

		$this->deliver(
			ProductRecheck::TEMPLATE_LIVE_CHANGED,
			array(
				'baseId' => DesignFixture::TEMPLATE_BASE_ID,
				'live'   => false,
			)
		);

		$this->assertSame( InvalidReason::TEMPLATE_NOT_LIVE, $this->stored( $this->product_id )->invalid_reason );
		$this->assertFalse( $this->reload( $this->product_id )->is_purchasable() );
		$notice = $this->plugin->notices()->all()[ InvalidProductNotice::key( $this->product_id ) ] ?? null;
		$this->assertNotNull( $notice );
		$this->assertStringContainsString( 'href="' . esc_url( admin_url( 'post.php?post=' . $this->product_id . '&action=edit' ) ) . '"', $notice['message'] );
		$this->assertCount( 1, $this->recorder->requests, 'a template no longer live is not checked further' );
		$this->assertNotContains( $this->product_id, ShopListing::shop(), 'a paused template takes the product out of the shop' );

		$this->script_recheck( array( DesignFixture::TEMPLATE_BASE_ID => DesignFixture::LIVE_VERSION ) );
		$this->deliver(
			ProductRecheck::TEMPLATE_LIVE_CHANGED,
			array(
				'baseId' => DesignFixture::TEMPLATE_BASE_ID,
				'live'   => true,
			)
		);

		$this->assertTrue( $this->stored( $this->product_id )->valid );
		$this->assertTrue( $this->reload( $this->product_id )->is_purchasable() );
		$this->assertFalse( $this->plugin->notices()->has( InvalidProductNotice::key( $this->product_id ) ) );
		$this->assertContains( $this->product_id, ShopListing::shop(), 'resuming the template lists the product again' );
	}

	public function test_a_deleted_template_stops_sales(): void {
		$this->live_list( array() );

		$this->deliver(
			ProductRecheck::TEMPLATE_DELETED,
			array(
				'baseId'  => DesignFixture::TEMPLATE_BASE_ID,
				'wasLive' => true,
			)
		);

		$this->assertSame( InvalidReason::TEMPLATE_NOT_LIVE, $this->stored( $this->product_id )->invalid_reason );
		$this->assertFalse( $this->reload( $this->product_id )->is_purchasable() );
	}

	public function test_a_deleted_blank_stops_sales_with_a_notice_and_restoring_it_sells_again(): void {
		// Background jobs run without a user.
		wp_set_current_user( 0 );
		$this->live_list( array( DesignFixture::TEMPLATE_BASE_ID => DesignFixture::LIVE_VERSION ) );
		$this->recorder->json( 200, BlankFixture::deleted() );

		$this->deliver(
			ProductRecheck::BLANK_DELETED,
			array(
				'blankId'   => DesignFixture::BLANK_ID,
				'blankName' => 'Fixture tee',
			)
		);

		$stored = $this->stored( $this->product_id );
		$this->assertSame( InvalidReason::BLANK_DELETED, $stored->invalid_reason );
		$this->assertEquals( self::config()->sides[0]->print_sizes, $stored->sides[0]->pending_sizes );
		$this->assertFalse( $this->reload( $this->product_id )->is_purchasable() );
		$this->assertTrue( $this->plugin->notices()->has( InvalidProductNotice::key( $this->product_id ) ) );
		$this->assertCount( 2, $this->recorder->requests, 'a deleted blank is not checked further' );

		$this->script_recheck( array( DesignFixture::TEMPLATE_BASE_ID => DesignFixture::LIVE_VERSION ) );
		$this->deliver(
			ProductRecheck::BLANK_RESTORED,
			array(
				'blankId'   => DesignFixture::BLANK_ID,
				'blankName' => 'Fixture tee',
			)
		);

		$stored = $this->stored( $this->product_id );
		$this->assertTrue( $stored->valid );
		$this->assertEquals( self::config()->sides[0]->print_sizes, $stored->sides[0]->print_sizes );
		$this->assertTrue( $this->reload( $this->product_id )->is_purchasable() );
		$this->assertFalse( $this->plugin->notices()->has( InvalidProductNotice::key( $this->product_id ) ) );
	}

	public function test_a_blank_gone_from_pixonaut_stops_sales(): void {
		$this->live_list( array( DesignFixture::TEMPLATE_BASE_ID => DesignFixture::LIVE_VERSION ) );
		$this->recorder->json( 404, array( 'error' => 'not_found' ) );

		$this->deliver(
			ProductRecheck::BLANK_DELETED,
			array(
				'blankId'   => DesignFixture::BLANK_ID,
				'blankName' => 'Fixture tee',
			)
		);

		$this->assertSame( InvalidReason::UNKNOWN_BLANK, $this->stored( $this->product_id )->invalid_reason );
		$this->assertFalse( $this->reload( $this->product_id )->is_purchasable() );
	}

	public function test_a_size_the_updated_blank_cannot_print_stops_sales_and_keeps_the_menu_for_when_it_can_again(): void {
		$this->live_list( array( DesignFixture::TEMPLATE_BASE_ID => DesignFixture::LIVE_VERSION ) );
		$this->recorder->json( 200, BlankFixture::answer() );
		$this->recorder->json(
			200,
			array(
				'verdicts' => array(
					array(
						'legal'  => false,
						'reason' => InvalidReason::TOO_LARGE,
					),
				),
			)
		);
		$this->deliver_blank_updated();

		$stored = $this->stored( $this->product_id );
		$this->assertSame( InvalidReason::TOO_LARGE, $stored->invalid_reason );
		$this->assertEquals( self::config()->sides[0]->print_sizes, $stored->sides[0]->pending_sizes );

		$this->script_recheck( array( DesignFixture::TEMPLATE_BASE_ID => DesignFixture::LIVE_VERSION ) );
		$this->deliver_blank_updated();

		$stored = $this->stored( $this->product_id );
		$this->assertTrue( $stored->valid );
		$this->assertEquals( self::config()->sides[0]->print_sizes, $stored->sides[0]->print_sizes );
		$this->assertNull( $stored->sides[0]->pending_sizes );
	}

	public function test_pixonaut_not_answering_leaves_the_product_sellable_and_tries_again_later(): void {
		$before = $this->stored( $this->product_id );
		$this->live_list( array( DesignFixture::TEMPLATE_BASE_ID => DesignFixture::LIVE_VERSION ) );
		$this->recorder->fail();

		$this->deliver_blank_updated();

		$this->assertEquals( $before, $this->stored( $this->product_id ) );
		$this->assertTrue( $this->reload( $this->product_id )->is_purchasable() );
		$retries = as_get_scheduled_actions(
			array(
				'hook'   => ProductRecheck::ACTION,
				'group'  => ProductRecheck::GROUP,
				'status' => \ActionScheduler_Store::STATUS_PENDING,
			)
		);
		$this->assertCount( 1, $retries );
		$this->assertSame(
			array(
				'product_ids' => array( $this->product_id ),
				'attempt'     => 2,
			),
			array_values( $retries )[0]->get_args()
		);
	}

	public function test_a_configuration_saved_during_the_recheck_is_kept_with_its_index(): void {
		$this->script_recheck( array( DesignFixture::TEMPLATE_BASE_ID => self::NEW_VERSION ) );
		$newer = $this->config_on( self::OTHER_TEMPLATE, self::OTHER_BLANK );
		$save  = function ( $preempt, array $args, string $url ) use ( $newer ) {
			if ( str_ends_with( (string) wp_parse_url( $url, PHP_URL_PATH ), '/print-size' ) ) {
				// The merchant saves the product while Pixonaut checks the sizes.
				$newer->save_to( $this->reload( $this->product_id ) );
			}
			return $preempt;
		};
		add_filter( 'pre_http_request', $save, 5, 3 );

		try {
			$this->deliver(
				ProductRecheck::TEMPLATE_PUBLISHED,
				array(
					'templateId' => DesignFixture::TEMPLATE_BASE_ID . '@' . self::NEW_VERSION,
					'version'    => self::NEW_VERSION,
				)
			);
		} finally {
			remove_filter( 'pre_http_request', $save, 5 );
		}

		$this->assertSame( $newer->to_array(), $this->stored( $this->product_id )->to_array() );
		$this->assertSame( array( $this->product_id ), ProductIndex::using_template( self::OTHER_TEMPLATE, 0, ProductRecheck::QUEUE_PAGE ) );
		$this->assertSame( array(), ProductIndex::using_template( DesignFixture::TEMPLATE_BASE_ID, 0, ProductRecheck::QUEUE_PAGE ) );
	}

	public function test_an_event_whose_recheck_cannot_be_queued_is_handled_again(): void {
		$refuse = static fn ( $pre, string $hook ) => ProductRecheck::QUEUE_ACTION === $hook ? 0 : $pre;
		add_filter( 'pre_as_enqueue_async_action', $refuse, 10, 2 );
		try {
			$this->deliver_blank_updated();
		} finally {
			remove_filter( 'pre_as_enqueue_async_action', $refuse, 10 );
		}
		$event_id = 'evt_recheck_' . $this->sequence;

		$this->assertFalse( ( new EventStore() )->processed( $event_id ) );
		$this->assertCount( 1, $this->pending( Processor::PROCESS_HOOK, Processor::GROUP ), 'the event is tried again' );

		ActionSchedulerRunner::run_once( Processor::PROCESS_HOOK );

		$this->assertTrue( ( new EventStore() )->processed( $event_id ) );
		$this->assertCount( 1, $this->pending( ProductRecheck::QUEUE_ACTION, ProductRecheck::GROUP ) );
	}

	public function test_a_base_id_ending_like_a_version_is_used_as_it_is(): void {
		$product = $this->product( self::VERSIONED_LOOKING_BASE_ID, self::OTHER_BLANK );
		$this->live_list( array() );

		$this->deliver(
			ProductRecheck::TEMPLATE_LIVE_CHANGED,
			array(
				'baseId' => self::VERSIONED_LOOKING_BASE_ID,
				'live'   => false,
			)
		);

		$this->assertSame( InvalidReason::TEMPLATE_NOT_LIVE, $this->stored( $product )->invalid_reason );
		$this->assertTrue( $this->stored( $this->product_id )->valid, 'a product on another template is not re-checked' );
	}

	public function test_a_problem_in_what_was_entered_stays_after_a_recheck(): void {
		self::config()->with_validity( false, InvalidReason::DUPLICATE_PLACEMENT )->save_to( $this->reload( $this->product_id ) );
		$this->script_recheck( array( DesignFixture::TEMPLATE_BASE_ID => DesignFixture::LIVE_VERSION ) );

		$this->deliver_blank_updated();

		$this->assertCount( 3, $this->recorder->requests, 'the sizes are still checked' );
		$this->assertSame( InvalidReason::DUPLICATE_PLACEMENT, $this->stored( $this->product_id )->invalid_reason );
		$this->assertFalse( $this->reload( $this->product_id )->is_purchasable() );
	}

	public function test_a_problem_in_what_was_entered_stays_after_its_blank_is_deleted_and_restored(): void {
		self::config()->with_validity( false, InvalidReason::DUPLICATE_PLACEMENT )->save_to( $this->reload( $this->product_id ) );
		$this->live_list( array( DesignFixture::TEMPLATE_BASE_ID => DesignFixture::LIVE_VERSION ) );
		$this->recorder->json( 200, BlankFixture::deleted() );
		$this->deliver(
			ProductRecheck::BLANK_DELETED,
			array(
				'blankId'   => DesignFixture::BLANK_ID,
				'blankName' => 'Fixture tee',
			)
		);
		$this->assertSame( InvalidReason::DUPLICATE_PLACEMENT, $this->stored( $this->product_id )->invalid_reason );

		$this->script_recheck( array( DesignFixture::TEMPLATE_BASE_ID => DesignFixture::LIVE_VERSION ) );
		$this->deliver(
			ProductRecheck::BLANK_RESTORED,
			array(
				'blankId'   => DesignFixture::BLANK_ID,
				'blankName' => 'Fixture tee',
			)
		);

		$this->assertSame( InvalidReason::DUPLICATE_PLACEMENT, $this->stored( $this->product_id )->invalid_reason );
		$this->assertFalse( $this->reload( $this->product_id )->is_purchasable() );
	}

	public function test_a_paused_template_names_the_side_it_is_on(): void {
		$front = self::config()->sides[0];
		$back  = new Side( 'back', 'Back', self::BACK_PLACEMENT_ID, 'Back', self::OTHER_TEMPLATE, DesignFixture::LIVE_VERSION, null, null, $front->print_sizes );
		self::config()->with_sides( array( $front, $back ) )->save_to( $this->reload( $this->product_id ) );
		$this->live_list( array( DesignFixture::TEMPLATE_BASE_ID => DesignFixture::LIVE_VERSION ) );

		$this->deliver(
			ProductRecheck::TEMPLATE_LIVE_CHANGED,
			array(
				'baseId' => self::OTHER_TEMPLATE,
				'live'   => false,
			)
		);

		$stored = $this->stored( $this->product_id );
		$this->assertSame( InvalidReason::TEMPLATE_NOT_LIVE, $stored->invalid_reason );
		$this->assertSame( 'Back', $stored->invalid_detail?->side );
		$this->assertStringStartsWith( 'Side “Back”: ', InvalidReason::stored( $stored ) );
	}

	public function test_a_side_without_sizes_saved_while_pixonaut_was_away_is_not_retried_for_ever(): void {
		$front = self::config()->sides[0];
		$empty = new Side( $front->key, $front->label, $front->placement_id, $front->placement_name, $front->template_base_id, $front->template_version, null, $front->template_name );
		self::config()->with_sides( array( $empty ) )->with_validity( false, InvalidReason::CHECK_FAILED )->save_to( $this->reload( $this->product_id ) );
		$this->live_list( array( DesignFixture::TEMPLATE_BASE_ID => DesignFixture::LIVE_VERSION ) );
		$this->recorder->json( 200, BlankFixture::answer() );

		$this->deliver_blank_updated();

		$this->assertSame( InvalidReason::INVALID_PRINT_SIZE, $this->stored( $this->product_id )->invalid_reason );
		$this->assertSame( array(), $this->pending( ProductRecheck::ACTION, ProductRecheck::GROUP ) );
	}

	public function test_a_side_pixonaut_did_not_answer_is_tried_again_whatever_another_side_says(): void {
		$front = self::config()->sides[0];
		$back  = new Side( 'back', 'Back', self::BACK_PLACEMENT_ID, 'Back', $front->template_base_id, $front->template_version, null, $front->template_name, $front->print_sizes );
		self::config()->with_sides( array( $front, $back ) )->save_to( $this->reload( $this->product_id ) );
		$before = $this->stored( $this->product_id );
		$this->live_list( array( DesignFixture::TEMPLATE_BASE_ID => DesignFixture::LIVE_VERSION ) );
		$this->recorder->json(
			200,
			BlankFixture::answer(
				array(
					DesignFixture::PLACEMENT_ID => 'Front',
					self::BACK_PLACEMENT_ID     => 'Back',
				)
			)
		);
		$this->recorder->json(
			200,
			array(
				'verdicts' => array(
					array(
						'legal'  => false,
						'reason' => InvalidReason::TOO_LARGE,
					),
				),
			)
		);
		$this->recorder->fail();

		$this->deliver_blank_updated();

		$this->assertEquals( $before, $this->stored( $this->product_id ) );
		$retries = $this->pending( ProductRecheck::ACTION, ProductRecheck::GROUP );
		$this->assertCount( 1, $retries );
		$this->assertSame( 2, array_values( $retries )[0]->get_args()['attempt'] );
	}

	public function test_the_baseline_rechecks_every_configured_product(): void {
		$other = $this->product( self::OTHER_TEMPLATE, self::OTHER_BLANK );
		$this->live_list(
			array(
				DesignFixture::TEMPLATE_BASE_ID => DesignFixture::LIVE_VERSION,
				self::OTHER_TEMPLATE            => self::NEW_VERSION,
			)
		);
		$products = 2;
		for ( $i = 0; $i < $products; $i++ ) {
			$this->recorder->json( 200, BlankFixture::answer() );
			$this->recorder->json( 200, array( 'verdicts' => array( array( 'legal' => true ) ) ) );
		}

		do_action( 'pixonaut_wc_events_baseline_set', self::connection()->summary() );
		$this->run_rechecks();

		$this->assertSame( self::NEW_VERSION, $this->stored( $other )->sides[0]->template_version );
		$this->assertCount( 5, $this->recorder->requests, 'one live list for the batch, a blank read and a check per product' );
	}

	public function test_after_the_upgrade_a_template_only_product_without_a_size_stops_selling_with_a_notice(): void {
		// Background jobs run without a user.
		wp_set_current_user( 0 );
		$this->template_only( array() );
		$this->live_list( array( DesignFixture::TEMPLATE_BASE_ID => DesignFixture::LIVE_VERSION ) );

		( new RecheckTemplateOnlySizes() )->up();
		$this->plugin->product_recheck()->queue_backfill();
		$this->run_rechecks();

		$this->assertSame( InvalidReason::MISSING_PRINT_SIZE, $this->stored( $this->product_id )->invalid_reason );
		$this->assertFalse( $this->reload( $this->product_id )->is_purchasable() );
		$notice = $this->plugin->notices()->all()[ InvalidProductNotice::key( $this->product_id ) ] ?? null;
		$this->assertNotNull( $notice );
		$this->assertStringContainsString( esc_html( InvalidReason::message( InvalidReason::MISSING_PRINT_SIZE ) ), $notice['message'] );
		$this->assertSame( array( 'GET /v1/templates' ), $this->requested(), 'nothing to check without a size' );
	}

	public function test_a_template_only_products_sizes_are_checked_against_its_template_alone(): void {
		$this->template_only( array( new PrintSize( DesignFixture::WIDTH_MM, DesignFixture::HEIGHT_MM, 'Large' ) ) );
		$this->live_list( array( DesignFixture::TEMPLATE_BASE_ID => DesignFixture::LIVE_VERSION ) );
		$this->recorder->json( 200, array( 'verdicts' => array( array( 'legal' => true ) ) ) );

		$this->deliver(
			ProductRecheck::TEMPLATE_LIVE_CHANGED,
			array(
				'baseId' => DesignFixture::TEMPLATE_BASE_ID,
				'live'   => true,
			)
		);

		$this->assertTrue( $this->stored( $this->product_id )->valid );
		$this->assertSame( 'POST /v1/templates/' . DesignFixture::TEMPLATE_BASE_ID . '@' . DesignFixture::LIVE_VERSION . '/print-size', $this->requested()[1] );
		$this->assertEquals(
			array(
				'sizes' => array(
					array(
						'widthMm'  => DesignFixture::WIDTH_MM,
						'heightMm' => DesignFixture::HEIGHT_MM,
					),
				),
			),
			json_decode( (string) $this->recorder->requests[1]['body'], true ),
			'no blank or placement is named'
		);
	}

	/**
	 * @return array<string, array{0: Migration}>
	 */
	public static function variant_upgrades(): array {
		return array(
			'blank variants'      => array( new ResolveProductVariants() ),
			'their views by id'   => array( new ResolveVariantViews() ),
			'template-only sizes' => array( new RecheckTemplateOnlySizes() ),
		);
	}

	/**
	 * @dataProvider variant_upgrades
	 *
	 * @param Migration $upgrade The upgrade.
	 */
	public function test_the_upgrade_queues_the_recheck_of_every_product_and_asks_again_until_it_is_queued( Migration $upgrade ): void {
		$upgrade->up();
		$refuse = static fn ( $pre, string $hook ) => ProductRecheck::QUEUE_ACTION === $hook ? 0 : $pre;
		add_filter( 'pre_as_enqueue_async_action', $refuse, 10, 2 );
		try {
			$this->plugin->product_recheck()->queue_backfill();
		} finally {
			remove_filter( 'pre_as_enqueue_async_action', $refuse, 10 );
		}

		$this->assertSame( ProductRecheck::BACKFILL_PENDING, get_option( ProductRecheck::BACKFILL_OPTION ) );
		$this->assertCount( 0, $this->pending( ProductRecheck::QUEUE_ACTION, ProductRecheck::GROUP ) );

		$this->plugin->product_recheck()->queue_backfill();

		$this->assertFalse( get_option( ProductRecheck::BACKFILL_OPTION ) );
		$queued = $this->pending( ProductRecheck::QUEUE_ACTION, ProductRecheck::GROUP );
		$this->assertCount( 1, $queued );
		$this->assertSame( ProductRecheck::BY_ANY, array_values( $queued )[0]->get_args()['by'] );
	}

	public function test_a_variation_whose_attributes_change_outside_the_product_screen_sells_nothing_until_matched_again(): void {
		$this->variants->answer = self::colour_variants();
		$red                    = $this->linked_variation( 'Red' );
		$this->assertSame( 'v_red', ProductVariant::sold_by( $red, ProductConfig::config_for( $red ) ) );

		$red->set_attributes( array( strtolower( self::AXIS ) => 'White' ) );
		$red->save();

		$this->assertFalse( $this->reload( $red->get_id() )->is_purchasable(), 'its match was made on Red' );
		$this->assertCount( 1, $this->pending( ProductRecheck::ACTION, ProductRecheck::GROUP ) );

		$this->live_list( array( DesignFixture::TEMPLATE_BASE_ID => DesignFixture::LIVE_VERSION ) );
		ActionSchedulerRunner::run_once( ProductRecheck::ACTION );

		$white = $this->reload( $red->get_id() );
		$this->assertSame( 'v_white', ProductVariant::sold_by( $white, ProductConfig::config_for( $white ) ) );
		$this->assertTrue( $white->is_purchasable() );
	}

	public function test_a_variation_made_outside_the_product_screen_gets_its_variant_from_the_queued_recheck(): void {
		$this->variants->answer = self::colour_variants();
		$red                    = $this->linked_variation( 'Red' );

		$white = new WC_Product_Variation();
		$white->set_parent_id( $red->get_parent_id() );
		$white->set_attributes( array( strtolower( self::AXIS ) => 'White' ) );
		$white->set_regular_price( '30' );
		$white->save();

		$this->assertFalse( $this->reload( $white->get_id() )->is_purchasable(), 'it has no variant yet' );

		$this->live_list( array( DesignFixture::TEMPLATE_BASE_ID => DesignFixture::LIVE_VERSION ) );
		ActionSchedulerRunner::run_once( ProductRecheck::ACTION );

		$this->assertSame( 'v_white', ProductVariant::stored_on( $this->reload( $white->get_id() ) )?->ref );
		$this->assertTrue( $this->reload( $white->get_id() )->is_purchasable() );
	}

	public function test_the_recheck_after_the_views_upgrade_keeps_a_pick_stored_before_it(): void {
		$this->variants->answer = self::colour_variants();
		$red                    = $this->linked_variation( 'Red' );
		// Its pick as stored before views were named by id, unlike its attributes.
		update_post_meta(
			$red->get_id(),
			ProductVariant::META_KEY,
			wp_slash(
				(string) wp_json_encode(
					array(
						'v'       => self::VARIANT_VERSION_BEFORE_VIEW_IDS,
						'blankId' => DesignFixture::BLANK_ID,
						'pick'    => 'v_white',
						'ref'     => 'v_white',
						'label'   => 'White',
						'retired' => null,
						'views'   => array( 'Front' ),
					)
				)
			)
		);
		ProductConfig::delete_from( $this->reload( $this->product_id ) );

		( new ResolveVariantViews() )->up();
		$this->plugin->product_recheck()->queue_backfill();
		$this->script_recheck( array( DesignFixture::TEMPLATE_BASE_ID => DesignFixture::LIVE_VERSION ) );
		$this->run_rechecks();

		$stored = ProductVariant::stored_on( $this->reload( $red->get_id() ) );
		$this->assertSame( 'v_white', $stored?->pick );
		$this->assertSame( 'v_white', $stored->ref );
		$this->assertSame( ProductVariant::SCHEMA_VERSION, json_decode( (string) ProductVariant::document_on( $this->reload( $red->get_id() ) ), true )['v'], 'stored again' );
	}

	public function test_the_index_follows_the_configuration(): void {
		$product = $this->reload( $this->product_id );

		$this->assertSame( array( $this->product_id ), ProductIndex::using_template( DesignFixture::TEMPLATE_BASE_ID, 0, ProductRecheck::QUEUE_PAGE ) );
		$this->assertSame( array( $this->product_id ), ProductIndex::using_blank( DesignFixture::BLANK_ID, 0, ProductRecheck::QUEUE_PAGE ) );

		ProductConfig::delete_from( $product );

		$this->assertSame( array(), ProductIndex::using_template( DesignFixture::TEMPLATE_BASE_ID, 0, ProductRecheck::QUEUE_PAGE ) );
		$this->assertSame( array(), ProductIndex::using_blank( DesignFixture::BLANK_ID, 0, ProductRecheck::QUEUE_PAGE ) );
	}

	public function test_the_migration_indexes_configurations_stored_before_the_index(): void {
		delete_post_meta( $this->product_id, ProductIndex::TEMPLATE_META );
		delete_post_meta( $this->product_id, ProductIndex::BLANK_META );

		( new IndexProductConfigs() )->up();

		$this->assertSame( array( DesignFixture::TEMPLATE_BASE_ID ), get_post_meta( $this->product_id, ProductIndex::TEMPLATE_META, false ) );
		$this->assertSame( array( DesignFixture::BLANK_ID ), get_post_meta( $this->product_id, ProductIndex::BLANK_META, false ) );
	}

	/**
	 * A red and a white variant of the blank.
	 *
	 * @return array<string, mixed>
	 */
	private static function colour_variants(): array {
		return BlankFixture::variants(
			array(
				array(
					'ref'     => 'v_red',
					'options' => array( self::AXIS => 'Red' ),
				),
				array(
					'ref'     => 'v_white',
					'options' => array( self::AXIS => 'White' ),
				),
			),
			array( self::AXIS )
		);
	}

	/**
	 * A variable product in red and white, personalised on the blank, with
	 * one variation, whose variant is resolved.
	 *
	 * @param string $colour The variation's colour.
	 */
	private function linked_variation( string $colour ): WC_Product {
		$attribute = new WC_Product_Attribute();
		$attribute->set_name( self::AXIS );
		$attribute->set_options( array( 'Red', 'White' ) );
		$attribute->set_visible( true );
		$attribute->set_variation( true );
		$parent = new WC_Product_Variable();
		$parent->set_name( 'Personalised tee in colours' );
		$parent->set_status( 'publish' );
		$parent->set_attributes( array( $attribute ) );
		$parent->save();
		self::config()->save_to( $parent );

		$variation = new WC_Product_Variation();
		$variation->set_parent_id( $parent->get_id() );
		$variation->set_attributes( array( strtolower( self::AXIS ) => $colour ) );
		$variation->set_regular_price( '30' );
		$variation->save();
		WC_Product_Variable::sync( $parent->get_id() );
		// What the variations' saves queued is not what these tests are about.
		as_unschedule_all_actions( ProductRecheck::ACTION );

		$this->plugin->product_variants()->refresh( $this->reload( $parent->get_id() ) );
		return $this->reload( $variation->get_id() );
	}

	/**
	 * Scripts a re-check of one product: the live list, the blank and the
	 * sizes' verdict.
	 *
	 * @param array<string, int> $live Live versions by base id.
	 */
	private function script_recheck( array $live ): void {
		$this->live_list( $live );
		$this->recorder->json( 200, BlankFixture::answer() );
		$this->recorder->json( 200, array( 'verdicts' => array( array( 'legal' => true ) ) ) );
	}

	/**
	 * @param array<string, int> $live Live versions by base id.
	 */
	private function live_list( array $live ): void {
		$items = array();
		foreach ( $live as $base_id => $version ) {
			$items[] = array(
				'id'                   => $base_id . '@' . $version,
				'baseId'               => $base_id,
				'name'                 => $base_id,
				'latestVersion'        => $version,
				'ratio'                => array(
					'w' => DesignFixture::WIDTH_MM,
					'h' => DesignFixture::HEIGHT_MM,
				),
				'ratioTolerance'       => 0,
				'fieldKeys'            => array(),
				'previewUrl'           => 'https://api.pixonaut.test/i/preview',
				'offersProperties'     => true,
				'backgroundSeeThrough' => null,
			);
		}
		$this->recorder->json(
			200,
			array(
				'items'    => $items,
				'total'    => count( $items ),
				'page'     => 1,
				'pageSize' => Catalogue::LIST_PAGE_SIZE,
			)
		);
	}

	private function deliver_blank_updated(): void {
		$this->deliver(
			ProductRecheck::BLANK_UPDATED,
			array(
				'blankId' => DesignFixture::BLANK_ID,
				'fields'  => array( 'placements' ),
			)
		);
	}

	/**
	 * Delivers an event signed as Pixonaut signs it, then runs the jobs it queues once.
	 *
	 * @param string               $type    Event type.
	 * @param array<string, mixed> $payload Its payload.
	 */
	private function deliver( string $type, array $payload ): void {
		$body = (string) wp_json_encode(
			array(
				'id'        => 'evt_recheck_' . ( ++$this->sequence ),
				'type'      => $type,
				'payload'   => $payload,
				'createdAt' => time() * 1000,
			)
		);
		OpenApiAssert::webhook( $type, $body );
		$timestamp = (string) time();
		$request   = new WP_REST_Request( 'POST', '/pixonaut/v1/webhook' );
		$request->set_header( 'content-type', 'application/json' );
		$request->set_header( Signature::TIMESTAMP_HEADER, $timestamp );
		$request->set_header( Signature::SIGNATURE_HEADER, 'v1=' . hash_hmac( 'sha256', "{$timestamp}.{$body}", self::SECRET ) );
		$request->set_body( $body );
		$this->assertSame( 200, rest_do_request( $request )->get_status() );

		ActionSchedulerRunner::run_once( Processor::PROCESS_HOOK );
		$this->run_rechecks();
	}

	/**
	 * Runs the product's picture job, Pixonaut answering with a PNG.
	 */
	private function draw_picture(): void {
		$this->assertCount( 1, ActionSchedulerRunner::pending( ProductPictures::ACTION ) );
		$this->recorder->raw( 200, PngFixture::white(), 'image/png' );
		ActionSchedulerRunner::run_pending( ProductPictures::ACTION );
	}

	private function run_rechecks(): void {
		ActionSchedulerRunner::run_once( ProductRecheck::QUEUE_ACTION );
		ActionSchedulerRunner::run_once( ProductRecheck::ACTION );
	}

	/**
	 * @return list<string> Each request as `<METHOD> <path>`.
	 */
	private function requested(): array {
		return array_map(
			static fn ( array $request ): string => $request['method'] . ' ' . rawurldecode( (string) wp_parse_url( $request['url'], PHP_URL_PATH ) ),
			$this->recorder->requests
		);
	}

	/**
	 * A second personalised product, on another template and blank.
	 *
	 * @param string $template Template base id.
	 * @param string $blank    Blank id.
	 */
	private function product( string $template, string $blank ): int {
		$product = new WC_Product_Simple();
		$product->set_name( 'Other tee' );
		$product->set_regular_price( '20' );
		$product->save();
		$this->config_on( $template, $blank )->save_to( $product );
		return $product->get_id();
	}

	/**
	 * A configuration of one side, on another template and blank.
	 *
	 * @param string $template Template base id.
	 * @param string $blank    Blank id.
	 */
	private function config_on( string $template, string $blank ): ProductConfig {
		$side = self::config()->sides[0];
		return new ProductConfig(
			personalise: true,
			blank_id: $blank,
			sides: array( new Side( $side->key, $side->label, $side->placement_id, $side->placement_name, $template, DesignFixture::LIVE_VERSION, null, null, array( new PrintSize( DesignFixture::WIDTH_MM, DesignFixture::HEIGHT_MM, 'Large' ) ) ) ),
		);
	}

	/**
	 * Makes the product template-only: its side on no blank, sold in `$sizes`.
	 *
	 * @param PrintSize[] $sizes The side's menu.
	 * @phpstan-param list<PrintSize> $sizes
	 */
	private function template_only( array $sizes ): void {
		$side = self::config()->sides[0];
		( new ProductConfig( personalise: true, sides: array( new Side( $side->key, $side->label, null, null, $side->template_base_id, $side->template_version, null, null, $sizes ) ) ) )->save_to( $this->reload( $this->product_id ) );
	}

	/**
	 * The jobs of a hook waiting to run.
	 *
	 * @param string $hook  The jobs' hook.
	 * @param string $group Their group.
	 * @return array<int, \ActionScheduler_Action>
	 */
	private function pending( string $hook, string $group ): array {
		return as_get_scheduled_actions(
			array(
				'hook'   => $hook,
				'group'  => $group,
				'status' => \ActionScheduler_Store::STATUS_PENDING,
			)
		);
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
