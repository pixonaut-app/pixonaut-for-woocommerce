<?php
/**
 * @package Pixonaut\WooCommerce
 */

declare(strict_types=1);

namespace Pixonaut\WooCommerce\Tests\Integration\Cart;

use Pixonaut\WooCommerce\Vendor\Pixonaut\Sdk\Generated\ErrorCode;
use Pixonaut\WooCommerce\Api\Hosts;
use Pixonaut\WooCommerce\ApprovalMode;
use Pixonaut\WooCommerce\Cart\AddToCart;
use Pixonaut\WooCommerce\Cart\CartLine;
use Pixonaut\WooCommerce\Labels;
use Pixonaut\WooCommerce\Refs;
use Pixonaut\WooCommerce\Settings\Settings;
use Pixonaut\WooCommerce\Tests\Support\DesignFixture;
use Pixonaut\WooCommerce\Tests\Support\HttpRecorder;
use Pixonaut\WooCommerce\Catalogue\ProductConfig;
use Pixonaut\WooCommerce\Catalogue\Side;
use Pixonaut\WooCommerce\Tests\Support\BlankFixture;
use ReflectionProperty;
use WC_Product;
use WC_Product_Attribute;
use WC_Product_Variable;
use WC_Product_Variation;
use WC_Session;
use WP_REST_Request;

final class AddToCartTest extends PersonalisedCartTestCase {

	private const BACK_TOKEN = 'eyJhdWQiOiJwcm9vZiJ9.YmFjaw';

	/**
	 * The resolution a design's print is checked against, as Pixonaut answers a commit.
	 */
	private const PRINT_FLOOR_DPI = 150;

	private function label( string $key ): string {
		return $this->plugin->labels()->get( $key );
	}

	public function test_a_personalised_product_without_designs_is_refused(): void {
		$this->assertFalse( $this->post_add_to_cart( null ) );

		$this->assertSame( array( $this->label( Labels::DESIGN_REQUIRED ) ), self::errors() );
		$this->assertTrue( WC()->cart->is_empty() );
		$this->assertSame( array(), $this->recorder->requests );
	}

	public function test_a_product_that_is_not_personalised_is_left_alone(): void {
		$plain = self::factory()->post->create(
			array(
				'post_type'   => 'product',
				'post_status' => 'publish',
			)
		);

		$this->assertTrue( apply_filters( 'woocommerce_add_to_cart_validation', true, $plain, 1 ) );
		$this->assertSame( array(), $this->recorder->requests );
	}

	public function test_commits_each_side_and_stores_the_line_in_the_cart(): void {
		$committed = array();
		add_action(
			'pixonaut_wc_cart_designs_committed',
			static function ( CartLine $line ) use ( &$committed ): void {
				$committed[] = $line;
			}
		);
		$this->script_commit();

		$this->assertTrue( $this->post_add_to_cart() );

		[ $commit, $read ] = $this->recorder->requests;
		$this->assertSame( 'POST', $commit['method'] );
		$this->assertSame( Refs::commit_key( self::SITE, self::TOKEN ), $commit['headers']['Idempotency-Key'] );
		$this->assertEquals(
			array(
				'proofToken'        => self::TOKEN,
				'externalRef'       => self::cart_ref(),
				'fulfilmentPayload' => array( 'placement' => DesignFixture::PLACEMENT_ID ),
			),
			json_decode( (string) $commit['body'], true )
		);
		$this->assertSame( Hosts::server_api() . '/v1/designs/' . DesignFixture::ID, $read['url'] );

		$items = array_values( WC()->cart->get_cart() );
		$this->assertCount( 1, $items );
		$line = CartLine::of( $items[0] );
		$this->assertNotNull( $line );
		$this->assertSame( self::cart_ref(), $line->external_ref );
		$this->assertSame( self::TENANT, $line->tenant_id );
		$this->assertSame( ApprovalMode::ADD_TO_CART, $line->approval_mode );
		$this->assertSame( ApprovalMode::ADD_TO_CART, $line->consent['kind'] ?? null );
		$this->assertCount( 1, $line->sides );
		$side = $line->sides[0];
		$this->assertSame( array( 'front', DesignFixture::ID, 1, DesignFixture::TEMPLATE_BASE_ID, DesignFixture::LIVE_VERSION, DesignFixture::PROOF_ID ), array( $side->key, $side->design_id, $side->revision, $side->template_base_id, $side->template_version, $side->proof_id ) );
		$this->assertSame( BlankFixture::VARIANT_REF, $line->variant );
		$this->assertCount( 1, $committed );
	}

	public function test_the_checkbox_mode_refuses_an_unticked_approval_box_before_committing(): void {
		$this->plugin->settings()->set( Settings::DEFAULT_APPROVAL_MODE, ApprovalMode::CHECKBOX );

		$this->assertFalse( $this->post_add_to_cart() );

		$this->assertSame( array( $this->label( Labels::APPROVAL_REQUIRED ) ), self::errors() );
		$this->assertSame( array(), $this->recorder->requests );
		$this->assertTrue( WC()->cart->is_empty() );
	}

	public function test_the_checkbox_mode_records_the_ticked_box_as_the_consent(): void {
		$this->plugin->settings()->set( Settings::DEFAULT_APPROVAL_MODE, ApprovalMode::CHECKBOX );
		$this->script_commit();
		$_POST[ AddToCart::APPROVAL_FIELD ] = 'yes';
		$before                             = time() * 1000;

		$this->assertTrue( $this->post_add_to_cart() );

		$line = CartLine::of( array_values( WC()->cart->get_cart() )[0] );
		$this->assertNotNull( $line );
		$this->assertSame( ApprovalMode::CHECKBOX, $line->approval_mode );
		$this->assertNotNull( $line->consent );
		$this->assertSame( ApprovalMode::CHECKBOX, $line->consent['kind'] );
		$this->assertGreaterThanOrEqual( $before, $line->consent['at'] );
	}

	/**
	 * @return array<string, array{string, bool}>
	 */
	public static function posted_previews(): array {
		return array(
			'opened'     => array( AddToCart::PREVIEW_OPENED, true ),
			'not opened' => array( AddToCart::PREVIEW_NOT_OPENED, false ),
		);
	}

	/**
	 * @dataProvider posted_previews
	 *
	 * @param string $posted   What the form posted.
	 * @param bool   $recorded What the commit says.
	 */
	public function test_the_checkbox_mode_commits_what_the_form_says_of_the_preview( string $posted, bool $recorded ): void {
		$this->plugin->settings()->set( Settings::DEFAULT_APPROVAL_MODE, ApprovalMode::CHECKBOX );
		$this->script_commit();
		$_POST[ AddToCart::APPROVAL_FIELD ]       = 'yes';
		$_POST[ AddToCart::PREVIEW_OPENED_FIELD ] = array( 'front' => $posted );

		$this->assertTrue( $this->post_add_to_cart() );

		[ $commit ] = $this->recorder->requests;
		$this->assertSame( $recorded, json_decode( (string) $commit['body'], true )['previewOpened'] );
		$this->assertSame( Refs::commit_key( self::SITE, self::TOKEN, $recorded ), $commit['headers']['Idempotency-Key'] );
		$line = CartLine::of( array_values( WC()->cart->get_cart() )[0] );
		$this->assertNotNull( $line );
		$this->assertSame( Refs::commit_key( self::SITE, self::TOKEN, $recorded ), $line->sides[0]->commit_key );
	}

	public function test_the_checkbox_mode_says_nothing_of_a_preview_the_form_gave_no_answer_for(): void {
		$this->plugin->settings()->set( Settings::DEFAULT_APPROVAL_MODE, ApprovalMode::CHECKBOX );
		$this->script_commit();
		$_POST[ AddToCart::APPROVAL_FIELD ]       = 'yes';
		$_POST[ AddToCart::PREVIEW_OPENED_FIELD ] = array( 'front' => '' );

		$this->assertTrue( $this->post_add_to_cart() );

		[ $commit ] = $this->recorder->requests;
		$this->assertArrayNotHasKey( 'previewOpened', json_decode( (string) $commit['body'], true ) );
		$this->assertSame( Refs::commit_key( self::SITE, self::TOKEN ), $commit['headers']['Idempotency-Key'] );
	}

	public function test_a_mode_without_the_box_says_nothing_of_the_preview_whatever_the_form_posts(): void {
		$this->script_commit();
		$_POST[ AddToCart::PREVIEW_OPENED_FIELD ] = array( 'front' => AddToCart::PREVIEW_OPENED );

		$this->assertTrue( $this->post_add_to_cart() );

		[ $commit ] = $this->recorder->requests;
		$this->assertArrayNotHasKey( 'previewOpened', json_decode( (string) $commit['body'], true ) );
	}

	public function test_a_repeat_that_says_otherwise_of_the_preview_adopts_the_design_already_committed(): void {
		$this->plugin->settings()->set( Settings::DEFAULT_APPROVAL_MODE, ApprovalMode::CHECKBOX );
		$this->recorder
			->json(
				409,
				array(
					'error'    => ErrorCode::PROOF_ALREADY_COMMITTED,
					'designId' => DesignFixture::ID,
				)
			)
			->json( 200, DesignFixture::design( array( 'externalRef' => self::cart_ref() ) ) );
		$_POST[ AddToCart::APPROVAL_FIELD ]       = 'yes';
		$_POST[ AddToCart::PREVIEW_OPENED_FIELD ] = array( 'front' => AddToCart::PREVIEW_OPENED );

		$this->assertTrue( $this->post_add_to_cart() );

		$this->assertCount( 1, WC()->cart->get_cart() );
	}

	public function test_a_product_override_wins_over_the_default_mode(): void {
		$product = wc_get_product( $this->product_id );
		$this->assertInstanceOf( WC_Product::class, $product );
		self::config()->with_approval_mode( ApprovalMode::CHECKBOX )->save_to( $product );
		DesignFixture::sell_variant( $product );

		$this->assertFalse( $this->post_add_to_cart() );

		$this->assertSame( array( $this->label( Labels::APPROVAL_REQUIRED ) ), self::errors() );
	}

	public function test_an_unavailable_override_follows_the_default_mode(): void {
		$product = wc_get_product( $this->product_id );
		$this->assertInstanceOf( WC_Product::class, $product );
		self::config()->with_approval_mode( 'retired_mode' )->save_to( $product );
		DesignFixture::sell_variant( $product );
		$this->script_commit();

		$this->assertTrue( $this->post_add_to_cart() );

		$line = CartLine::of( array_values( WC()->cart->get_cart() )[0] );
		$this->assertSame( ApprovalMode::ADD_TO_CART, $line?->approval_mode );
	}

	public function test_the_proof_email_mode_takes_no_consent_at_add_to_cart(): void {
		$this->plugin->settings()->set( Settings::DEFAULT_APPROVAL_MODE, ApprovalMode::PROOF_EMAIL );
		$this->script_commit();

		$this->assertTrue( $this->post_add_to_cart() );

		$line = CartLine::of( array_values( WC()->cart->get_cart() )[0] );
		$this->assertNotNull( $line );
		$this->assertSame( ApprovalMode::PROOF_EMAIL, $line->approval_mode );
		$this->assertNull( $line->consent );
	}

	public function test_reads_a_single_token_for_a_one_sided_product(): void {
		$this->script_commit();

		$this->assertTrue( $this->post_add_to_cart( self::TOKEN ) );
	}

	public function test_a_store_may_filter_the_cart_item_data(): void {
		add_filter(
			'pixonaut_wc_cart_item_data',
			static function ( array $data ): array {
				$data['consent'] = null;
				return $data;
			}
		);
		$this->script_commit();

		$this->post_add_to_cart();

		$items = array_values( WC()->cart->get_cart() );
		$this->assertNull( CartLine::of( $items[0] )?->consent );
	}

	public function test_each_personalised_item_is_a_line_of_its_own(): void {
		$other = 'eyJhdWQiOiJwcm9vZiJ9.b3RoZXI';
		$this->script_commit();
		$this->post_add_to_cart();
		$this->recorder
			->json(
				201,
				array(
					'designId'      => 'd_second',
					'printFloorDpi' => 150,
					'lowResolution' => array(),
				)
			)
			->json(
				200,
				DesignFixture::design(
					array(
						'id'          => 'd_second',
						'externalRef' => self::cart_ref( $other ),
					)
				)
			);

		$this->assertTrue( $this->post_add_to_cart( array( 'front' => $other ) ) );

		$this->assertCount( 2, WC()->cart->get_cart() );
	}

	public function test_a_tampered_size_is_refused_and_its_design_deleted(): void {
		$this->script_commit(
			array(
				'printSize' => array(
					'widthMm'  => 330,
					'heightMm' => 420,
				),
			)
		);
		$this->recorder->raw( 204, '', 'application/json' );

		$this->assertFalse( $this->post_add_to_cart() );

		$this->assertSame( array( $this->label( Labels::DESIGN_MISMATCH ) ), self::errors() );
		$delete = $this->recorder->requests[2];
		$this->assertSame( 'DELETE', $delete['method'] );
		$this->assertSame( Hosts::server_api() . '/v1/designs/' . DesignFixture::ID, $delete['url'] );
		$this->assertTrue( WC()->cart->is_empty() );
	}

	public function test_the_previous_live_version_is_accepted(): void {
		$this->script_commit( array( 'templateVersion' => self::PREVIOUS_VERSION ) );

		$this->assertTrue( $this->post_add_to_cart() );
	}

	public function test_an_older_version_is_deleted_and_refused_with_the_reload_message(): void {
		$this->script_commit( array( 'templateVersion' => self::PREVIOUS_VERSION - 1 ) );
		$this->recorder->raw( 204, '', 'application/json' );

		$this->assertFalse( $this->post_add_to_cart() );

		$this->assertSame( array( 'This product was just updated, please reload.' ), self::errors() );
		$this->assertSame( 'DELETE', $this->recorder->requests[2]['method'] );
	}

	public function test_a_proof_committed_before_is_adopted(): void {
		$this->recorder
			->json(
				409,
				array(
					'error'    => ErrorCode::PROOF_ALREADY_COMMITTED,
					'designId' => DesignFixture::ID,
				)
			)
			->json( 200, DesignFixture::design( array( 'externalRef' => self::cart_ref() ) ) );

		$this->assertTrue( $this->post_add_to_cart() );

		$items = array_values( WC()->cart->get_cart() );
		$this->assertSame( DesignFixture::ID, CartLine::of( $items[0] )?->sides[0]->design_id );
	}

	public function test_a_design_already_in_the_cart_is_refused_and_kept(): void {
		$this->script_commit();
		$this->post_add_to_cart();
		$this->script_commit();

		$this->assertFalse( $this->post_add_to_cart() );

		$this->assertSame( array( $this->label( Labels::DESIGN_ALREADY_USED ) ), self::errors() );
		$this->assertCount( 4, $this->recorder->requests, 'Nothing is deleted.' );
		$this->assertCount( 1, WC()->cart->get_cart() );
	}

	public function test_a_design_held_by_an_order_is_refused_and_kept(): void {
		$this->script_commit( array( 'externalRef' => Refs::order_item_ref( self::SITE, 7, 9 ) ) );

		$this->assertFalse( $this->post_add_to_cart() );

		$this->assertSame( array( $this->label( Labels::DESIGN_ALREADY_USED ) ), self::errors() );
		$this->assertCount( 2, $this->recorder->requests );
	}

	public function test_an_expired_proof_is_refused(): void {
		$this->recorder->json( 410, array( 'error' => ErrorCode::PROOF_EXPIRED ) );

		$this->assertFalse( $this->post_add_to_cart() );

		$this->assertSame( array( $this->label( Labels::DESIGN_EXPIRED ) ), self::errors() );
	}

	public function test_a_routing_refusal_is_refused_with_an_admin_notice(): void {
		$this->recorder->json(
			422,
			array(
				'error'  => ErrorCode::PRINTSHOP_DISCONNECTED,
				'reason' => 'The blank\'s printshop is disconnected.',
			)
		);

		$this->assertFalse( $this->post_add_to_cart() );

		$this->assertSame( array( $this->label( Labels::PERSONALISATION_UNAVAILABLE ) ), self::errors() );
		$this->assertTrue( $this->plugin->notices()->has( AddToCart::ROUTING_NOTICE_PREFIX . $this->product_id ) );
	}

	public function test_nothing_is_committed_while_the_connection_is_paused(): void {
		$this->plugin->connection_store()->pause( 'unauthorized' );

		$this->assertFalse( $this->post_add_to_cart() );

		$this->assertSame( array( $this->label( Labels::PERSONALISATION_UNAVAILABLE ) ), self::errors() );
		$this->assertSame( array(), $this->recorder->requests );
	}

	public function test_the_store_api_refuses_a_personalised_product_without_designs(): void {
		// The Add to Cart with Options block adds through the Store API when its form fallback is off.
		add_filter( 'woocommerce_disable_compatibility_layer', '__return_true' );
		add_filter( 'woocommerce_store_api_disable_nonce_check', '__return_true' );
		$request = new WP_REST_Request( 'POST', '/wc/store/v1/cart/add-item' );
		$request->set_param( 'id', $this->product_id );
		$request->set_param( 'quantity', 1 );

		$response = rest_do_request( $request );

		$this->assertSame( 400, $response->get_status() );
		$data = $response->get_data();
		$this->assertIsArray( $data );
		$this->assertSame( $this->label( Labels::DESIGN_REQUIRED ), html_entity_decode( (string) $data['message'] ) );
		$this->assertTrue( WC()->cart->is_empty() );
		$this->assertSame( array(), $this->recorder->requests );
	}

	public function test_the_store_api_refuses_a_personalised_product_even_with_designs(): void {
		add_filter( 'woocommerce_store_api_disable_nonce_check', '__return_true' );
		$_POST[ AddToCart::FIELD ] = array( 'front' => self::TOKEN );
		$request                   = new WP_REST_Request( 'POST', '/wc/store/v1/cart/add-item' );
		$request->set_param( 'id', $this->product_id );
		$request->set_param( 'quantity', 1 );

		$response = rest_do_request( $request );

		$this->assertSame( 400, $response->get_status() );
		$this->assertTrue( WC()->cart->is_empty() );
		$this->assertSame( array(), $this->recorder->requests );
	}

	public function test_a_variation_added_over_ajax_keeps_its_designs(): void {
		$parent = new WC_Product_Variable();
		$parent->set_name( 'Personalised hoodie' );
		$attribute = new WC_Product_Attribute();
		$attribute->set_name( 'size' );
		$attribute->set_options( array( 'S' ) );
		$attribute->set_variation( true );
		$parent->set_attributes( array( $attribute ) );
		$parent->save();
		self::config()->save_to( $parent );
		$variation = new WC_Product_Variation();
		$variation->set_parent_id( $parent->get_id() );
		$variation->set_attributes( array( 'size' => 'S' ) );
		$variation->set_regular_price( '30' );
		$variation->save();
		DesignFixture::sell_variant( $variation );
		$this->script_commit();
		$_POST[ AddToCart::FIELD ] = array( 'front' => self::TOKEN );

		// WooCommerce's AJAX add validates the variation by its own id, then adds it under its parent.
		$this->assertTrue( apply_filters( 'woocommerce_add_to_cart_validation', true, $variation->get_id(), 1 ) );
		$key = WC()->cart->add_to_cart( $parent->get_id(), 1, $variation->get_id(), array( 'attribute_size' => 'S' ) );

		$this->assertIsString( $key );
		$this->assertSame( DesignFixture::ID, CartLine::of( WC()->cart->get_cart_item( $key ) )?->sides[0]->design_id );
	}

	public function test_another_cart_sending_the_same_designs_is_refused_and_nothing_deleted(): void {
		$this->script_commit();
		$this->post_add_to_cart();
		$first_ref = self::cart_ref();
		$this->become_another_customer();
		// Within a day the commit key is replayed with the other cart's ref.
		$this->recorder->json( 422, array( 'error' => ErrorCode::IDEMPOTENCY_KEY_REUSED ) );

		$this->assertFalse( $this->post_add_to_cart() );

		$this->assertSame( array( $this->label( Labels::DESIGN_ALREADY_USED ) ), self::errors() );
		wc_clear_notices();
		// Later, the proof is answered as committed; the design still carries the first cart's ref.
		$this->recorder
			->json(
				409,
				array(
					'error'    => ErrorCode::PROOF_ALREADY_COMMITTED,
					'designId' => DesignFixture::ID,
				)
			)
			->json(
				200,
				DesignFixture::design(
					array(
						'externalRef' => $first_ref,
						'printSize'   => null,
					)
				)
			);

		$this->assertFalse( $this->post_add_to_cart() );

		$this->assertSame( array( $this->label( Labels::DESIGN_ALREADY_USED ) ), self::errors() );
		$this->assertCount( 5, $this->recorder->requests, 'Nothing is deleted.' );
	}

	public function test_a_front_and_a_back_commit_two_designs_into_one_line(): void {
		$sides = $this->link_front_and_back();
		$ref   = Refs::cart_ref( self::SITE, Refs::line_key( $sides, (string) WC()->session->get_customer_id() ) );
		$this->script_front_commit( $ref )
			->json( 201, self::created( 'd_back' ) )
			->json( 200, self::back_design( $ref ) );

		$this->assertTrue( $this->post_add_to_cart( $sides ) );

		$commits = array_values( array_filter( $this->recorder->requests, static fn ( array $request ): bool => 'POST' === $request['method'] ) );
		$this->assertSame(
			array( self::TOKEN, self::BACK_TOKEN ),
			array_map( static fn ( array $commit ): mixed => json_decode( (string) $commit['body'], true )['proofToken'] ?? null, $commits )
		);
		$this->assertSame(
			array( array( 'placement' => DesignFixture::PLACEMENT_ID ), array( 'placement' => 'back' ) ),
			array_map( static fn ( array $commit ): mixed => json_decode( (string) $commit['body'], true )['fulfilmentPayload'] ?? null, $commits ),
			'Each side\'s commit carries its placement; the recipient waits for the order.'
		);
		$items = array_values( WC()->cart->get_cart() );
		$this->assertCount( 1, $items );
		$line = CartLine::of( $items[0] );
		$this->assertNotNull( $line );
		$this->assertSame( $ref, $line->external_ref );
		$this->assertSame(
			array(
				array( 'front', DesignFixture::ID ),
				array( 'back', 'd_back' ),
			),
			array_map( static fn ( $side ): array => array( $side->key, $side->design_id ), $line->sides )
		);
	}

	public function test_a_failure_on_a_later_side_keeps_the_earlier_ones_for_a_retry(): void {
		$sides = $this->link_front_and_back();
		$ref   = Refs::cart_ref( self::SITE, Refs::line_key( $sides, (string) WC()->session->get_customer_id() ) );
		$this->script_front_commit( $ref )
			->json( 500, array( 'error' => 'db_error' ) );

		$this->assertFalse( $this->post_add_to_cart( $sides ) );

		$this->assertSame( array( $this->label( Labels::DESIGN_NOT_SAVED ) ), self::errors() );
		$this->assertCount( 3, $this->recorder->requests, 'The front is not deleted.' );
		wc_clear_notices();
		$this->script_front_commit( $ref )
			->json( 201, self::created( 'd_back' ) )
			->json( 200, self::back_design( $ref ) );

		$this->assertTrue( $this->post_add_to_cart( $sides ) );

		$items = array_values( WC()->cart->get_cart() );
		$this->assertSame( array( DesignFixture::ID, 'd_back' ), array_map( static fn ( $side ) => $side->design_id, CartLine::of( $items[0] )->sides ?? array() ) );
	}

	/**
	 * Links the product's front, as the fixture does, and a back with the
	 * same template.
	 *
	 * @return array<string, string> The proof token of each side.
	 */
	private function link_front_and_back(): array {
		$front   = self::config()->sides[0];
		$product = wc_get_product( $this->product_id );
		$this->assertInstanceOf( WC_Product::class, $product );
		( new ProductConfig(
			personalise: true,
			blank_id: DesignFixture::BLANK_ID,
			sides: array(
				$front,
				new Side( 'back', 'Back', 'back', 'Back', $front->template_base_id, $front->template_version, $front->previous_version, null, $front->print_sizes ),
			)
		) )->save_to( $product );
		DesignFixture::sell_variant( $product );
		return array(
			'front' => self::TOKEN,
			'back'  => self::BACK_TOKEN,
		);
	}

	/**
	 * Scripts the front's commit and read under the line's cart ref; the
	 * back's answers follow.
	 *
	 * @param string $ref The line's cart ref.
	 */
	private function script_front_commit( string $ref ): HttpRecorder {
		return $this->recorder
			->json( 201, self::created( DesignFixture::ID ) )
			->json( 200, DesignFixture::design( array( 'externalRef' => $ref ) ) );
	}

	/**
	 * `201` of `POST /v1/designs`.
	 *
	 * @param string $id The design.
	 *
	 * @return array<string, mixed>
	 */
	private static function created( string $id ): array {
		return array(
			'designId'      => $id,
			'printFloorDpi' => self::PRINT_FLOOR_DPI,
			'lowResolution' => array(),
		);
	}

	/**
	 * The back's design, committed under the line's cart ref.
	 *
	 * @param string $ref The line's cart ref.
	 *
	 * @return array<string, mixed>
	 */
	private static function back_design( string $ref ): array {
		return DesignFixture::design(
			array(
				'id'          => 'd_back',
				'placementId' => 'back',
				'externalRef' => $ref,
			)
		);
	}

	/**
	 * Gives the session another customer id and an empty cart, as another
	 * shopper's would have.
	 */
	private function become_another_customer(): void {
		WC()->cart->empty_cart();
		$id = new ReflectionProperty( WC_Session::class, '_customer_id' );
		$id->setValue( WC()->session, 'another-customer' );
	}
}
