<?php
/**
 * @package Pixonaut\WooCommerce
 */

declare(strict_types=1);

namespace Pixonaut\WooCommerce\Tests\Integration\Cart;

use Pixonaut\WooCommerce\Api\Designs;
use Pixonaut\WooCommerce\Api\Hosts;
use Pixonaut\WooCommerce\ApprovalMode;
use Pixonaut\WooCommerce\Cart\AddToCart;
use Pixonaut\WooCommerce\Cart\CartEdit;
use Pixonaut\WooCommerce\Cart\CartEditRoute;
use Pixonaut\WooCommerce\Cart\CartLine;
use Pixonaut\WooCommerce\Cart\EditRefused;
use Pixonaut\WooCommerce\Cart\FieldValue;
use Pixonaut\WooCommerce\Catalogue\PrintSize;
use Pixonaut\WooCommerce\Catalogue\ProductConfig;
use Pixonaut\WooCommerce\Catalogue\Side;
use Pixonaut\WooCommerce\DesignerLanguage;
use Pixonaut\WooCommerce\FormLayout;
use Pixonaut\WooCommerce\Labels;
use Pixonaut\WooCommerce\Milliseconds;
use Pixonaut\WooCommerce\Plugin;
use Pixonaut\WooCommerce\Settings\Settings;
use Pixonaut\WooCommerce\Storefront\InlineProducts;
use Pixonaut\WooCommerce\Storefront\Preload;
use Pixonaut\WooCommerce\Tests\Integration\Storefront\VariationSwitchesTest;
use Pixonaut\WooCommerce\Tests\Support\BlankFixture;
use Pixonaut\WooCommerce\Tests\Support\DesignFixture;
use Pixonaut\WooCommerce\Tests\Support\RecordingLogger;
use WC_Product;
use WC_Product_Simple;
use WP_REST_Request;

final class CartEditTest extends PersonalisedCartTestCase {

	private const CORRECTION_TOKEN = 'eyJhdWQiOiJjb3JyZWN0aW9uIn0.c2lnbmF0dXJl';

	private const LINK = 'link_0123456789abcdef0123456789abcdef';

	private const CORRECTED_PROOF_ID = 'prf_corrected0123456789abcdef';

	/**
	 * When the update was made, in Unix seconds.
	 */
	private const UPDATED_AT = 1_800_000_600;

	private RecordingLogger $logger;

	public function set_up(): void {
		parent::set_up();
		$this->logger = new RecordingLogger();
	}

	public function tear_down(): void {
		unset( $_GET[ CartEdit::PARAM ] );
		parent::tear_down();
	}

	private function edit(): CartEdit {
		return new CartEdit(
			$this->plugin->connection(),
			new Designs( $this->plugin->client() ),
			$this->plugin->settings(),
			$this->plugin->labels(),
			$this->logger,
			static fn (): int => self::UPDATED_AT
		);
	}

	/**
	 * Adds the personalised product through its page's form.
	 *
	 * @param string|null $mode The product's approval mode; null follows the setting.
	 *
	 * @return string The line's key.
	 */
	private function added( ?string $mode = null ): string {
		if ( null !== $mode ) {
			$base = self::config();
			( new ProductConfig( personalise: true, blank_id: $base->blank_id, blank_name: $base->blank_name, sides: $base->sides, approval_mode: $mode ) )
				->save_to( $this->product() );
			DesignFixture::sell_variant( $this->product() );
			$_POST[ AddToCart::APPROVAL_FIELD ] = 'yes';
		}
		$this->script_commit();
		$this->assertTrue( $this->post_add_to_cart() );
		return (string) array_key_first( WC()->cart->get_cart() );
	}

	private function product(): WC_Product {
		$product = wc_get_product( $this->product_id );
		$this->assertInstanceOf( WC_Product::class, $product );
		return $product;
	}

	private function line( string $key ): CartLine {
		$line = CartLine::of( WC()->cart->get_cart_item( $key ) );
		$this->assertNotNull( $line );
		return $line;
	}

	/**
	 * Scripts a side's correction link, then the read of its design.
	 *
	 * @param array<string, mixed> $design Overrides of the design read.
	 */
	private function script_link( array $design = array() ): void {
		$this->recorder->json(
			201,
			array(
				'id'        => 'shl_0123',
				'token'     => self::LINK,
				'url'       => null,
				'expiresAt' => Milliseconds::of( self::UPDATED_AT + HOUR_IN_SECONDS ),
				'name'      => null,
				'brandId'   => null,
			)
		)->json( 200, DesignFixture::design( $design ) );
	}

	private function script_corrected( bool $edited = true ): void {
		$this->recorder->json(
			200,
			array(
				'revision'      => $edited ? 2 : 1,
				'edited'        => $edited,
				'proofId'       => $edited ? self::CORRECTED_PROOF_ID : DesignFixture::PROOF_ID,
				'printFloorDpi' => 150,
				'lowResolution' => array(),
			)
		);
	}

	/**
	 * The request sent to Pixonaut, by its position among this test's requests.
	 *
	 * @param int $index Position.
	 *
	 * @return array<string, mixed>
	 */
	private function sent( int $index ): array {
		$request = $this->recorder->requests[ $index ] ?? null;
		$this->assertIsArray( $request );
		return $request;
	}

	public function test_a_personalised_line_links_to_its_edit_page(): void {
		$key       = $this->added();
		$item      = WC()->cart->get_cart_item( $key );
		$permalink = (string) get_permalink( $this->product_id );

		$this->assertSame( add_query_arg( CartEdit::PARAM, $key, $permalink ), apply_filters( 'woocommerce_cart_item_permalink', $permalink, $item, $key ) );
		// A product hidden from the catalogue gets no product link.
		$this->assertSame( '', apply_filters( 'woocommerce_cart_item_permalink', '', $item, $key ) );
		$this->assertSame( $permalink, apply_filters( 'woocommerce_cart_item_permalink', $permalink, array( 'key' => 'plain' ), 'plain' ) );
	}

	public function test_adding_from_the_form_lands_on_the_new_line_s_edit_page(): void {
		$key = $this->added();

		$this->assertSame(
			add_query_arg( CartEdit::PARAM, $key, (string) get_permalink( $this->product_id ) ),
			apply_filters( 'woocommerce_add_to_cart_redirect', false, $this->product() )
		);
	}

	public function test_a_store_that_sends_customers_to_the_cart_after_adding_keeps_doing_so(): void {
		update_option( 'woocommerce_cart_redirect_after_add', 'yes' );
		$this->added();

		// WooCommerce filters an empty URL, then redirects to the cart when the filter left it so.
		$this->assertFalse( apply_filters( 'woocommerce_add_to_cart_redirect', false, $this->product() ) );
		delete_option( 'woocommerce_cart_redirect_after_add' );
	}

	public function test_adding_a_product_that_is_not_personalised_stays_where_woocommerce_leaves_it(): void {
		$edit  = $this->edit();
		$plain = new WC_Product_Simple();
		$plain->set_regular_price( '5' );
		$plain->save();
		$key = (string) WC()->cart->add_to_cart( $plain->get_id() );
		$edit->added( $key, $plain->get_id(), 1, 0, array(), array() );

		$this->assertFalse( $edit->redirect( false ) );
	}

	public function test_a_line_of_a_product_that_no_longer_personalises_inline_has_no_edit_link(): void {
		$key = $this->added();
		( new ProductConfig() )->save_to( $this->product() );
		// As the cart loads it on the next request.
		$item = array( 'data' => $this->product() ) + WC()->cart->get_cart_item( $key );

		$this->assertNull( $this->edit()->url( $item ) );
	}

	public function test_the_edit_page_mounts_the_line_s_design_through_a_correction_link_minted_once(): void {
		$key                     = $this->added();
		$_GET[ CartEdit::PARAM ] = $key;
		$this->script_link();
		$products = new InlineProducts( $this->plugin->settings(), $this->plugin->connection(), $this->edit() );

		$designer = $products->for_product( $this->product() );
		$products->for_product( $this->product() );

		$this->assertNotNull( $designer );
		$this->assertSame( $key, $designer->edit?->key );
		// The page shows the design in the variant the line bought, as the product page did.
		$this->assertSame( BlankFixture::VARIANT_REF, $designer->variant );
		$this->assertSame(
			array(
				'front' => array(
					'correctionLink'     => self::LINK,
					'design'             => DesignFixture::ID,
					'apiBase'            => Hosts::api(),
					'locale'             => 'en',
					'preload'            => Preload::AUTO,
					'showPrintAreaFrame' => false,
					'blankId'            => DesignFixture::BLANK_ID,
					'placementId'        => DesignFixture::PLACEMENT_ID,
					'placements'         => array( array( 'placementId' => DesignFixture::PLACEMENT_ID ) ),
					'views'              => array(),
					'variants'           => array( BlankFixture::VARIANT_REF ),
				),
			),
			$designer->mounts
		);
		$link_requests = array_filter( $this->recorder->requests, static fn ( array $request ): bool => str_ends_with( (string) $request['url'], '/correction-links' ) );
		$this->assertCount( 1, $link_requests );
	}

	public function test_the_edit_page_shows_no_size_menu_and_opens_on_the_product_s_starting_view(): void {
		$config = self::config();
		$sizes  = array( new PrintSize( 210.0, 270.0, 'Small' ), new PrintSize( DesignFixture::WIDTH_MM, DesignFixture::HEIGHT_MM, 'Large' ) );
		$key    = $this->added();
		// The product starts on its small size now; the line's design was printed large.
		$config->with_sides( array( $config->sides[0]->with( DesignFixture::LIVE_VERSION, self::PREVIOUS_VERSION, $sizes, null, 0 ) ) )
			->with_views( array( BlankFixture::VIEW_ID ), BlankFixture::VIEW_ID )
			->save_to( $this->product() );
		$_GET[ CartEdit::PARAM ] = $key;
		$this->script_link();

		$mount = ( new InlineProducts( $this->plugin->settings(), $this->plugin->connection(), $this->edit() ) )->for_product( $this->product() )?->mounts['front'] ?? array();

		// The SDK keeps a correction at the size it was bought at.
		$this->assertArrayNotHasKey( 'printSizes', $mount );
		$this->assertArrayNotHasKey( 'selectedPrintSizeIndex', $mount );
		$this->assertSame( array( BlankFixture::VIEW_ID ), $mount['views'] ?? null );
		$this->assertSame( BlankFixture::VIEW_ID, $mount['initialView'] ?? null );
	}

	/**
	 * @return array<string, array{0: array<string, mixed>}>
	 */
	public static function designs_no_longer_sold(): array {
		return array(
			'another blank'       => array( array( 'blankId' => 'blank_hoodie' ) ),
			'another placement'   => array( array( 'placementId' => 'back' ) ),
			'a size off the menu' => array(
				array(
					'printSize' => array(
						'widthMm'  => 100.0,
						'heightMm' => 100.0,
					),
				),
			),
		);
	}

	/**
	 * @dataProvider designs_no_longer_sold
	 *
	 * @param array<string, mixed> $design Overrides of the design read.
	 */
	public function test_a_design_the_product_no_longer_sells_opens_on_the_design_alone( array $design ): void {
		$_GET[ CartEdit::PARAM ] = $this->added();
		$this->script_link( $design );

		$mount = ( new InlineProducts( $this->plugin->settings(), $this->plugin->connection(), $this->edit() ) )->for_product( $this->product() )?->mounts['front'] ?? array();

		$this->assertSame( self::LINK, $mount['correctionLink'] ?? null );
		$this->assertSame( array(), array_intersect_key( $mount, array_flip( array( 'blankId', 'placementId', 'placements', 'views', 'variants', 'printSizes', 'selectedPrintSizeIndex' ) ) ) );
	}

	public function test_a_design_pixonaut_does_not_answer_for_opens_on_the_design_alone(): void {
		$_GET[ CartEdit::PARAM ] = $this->added();
		$this->recorder->json(
			201,
			array(
				'id'        => 'shl_0123',
				'token'     => self::LINK,
				'url'       => null,
				'expiresAt' => Milliseconds::of( self::UPDATED_AT + HOUR_IN_SECONDS ),
				'name'      => null,
				'brandId'   => null,
			)
		)->fail();

		$mount = ( new InlineProducts( $this->plugin->settings(), $this->plugin->connection(), $this->edit() ) )->for_product( $this->product() )?->mounts['front'] ?? array();

		$this->assertSame( self::LINK, $mount['correctionLink'] ?? null );
		$this->assertArrayNotHasKey( 'blankId', $mount );
		$this->assertNotSame( array(), $this->logger->entries );
	}

	public function test_the_edit_page_speaks_the_designer_language_the_store_chose(): void {
		$_GET[ CartEdit::PARAM ] = $this->added();
		$this->script_link();
		$this->plugin->settings()->set( Settings::DESIGNER_LANGUAGE, DesignerLanguage::SWEDISH );

		$designer = ( new InlineProducts( $this->plugin->settings(), $this->plugin->connection(), $this->edit() ) )->for_product( $this->product() );

		$this->assertSame( 'sv', $designer?->mounts['front']['locale'] );
	}

	public function test_the_edit_page_lays_the_form_out_as_the_product_does(): void {
		$_GET[ CartEdit::PARAM ] = $this->added();
		$this->script_link();
		$this->plugin->settings()->set( Settings::FORM_LAYOUT, FormLayout::STEPS );

		$designer = ( new InlineProducts( $this->plugin->settings(), $this->plugin->connection(), $this->edit() ) )->for_product( $this->product() );

		$this->assertSame( FormLayout::STEPS, $designer?->mounts['front']['layout'] );
	}

	public function test_the_edit_page_of_a_variation_s_line_mounts_the_sides_the_line_has(): void {
		$line = $this->line( $this->added() );
		$tee  = VariationSwitchesTest::front();
		$back = new Side( 'back', 'Back', 'back', 'Back', 'mini-tee', 2, null, 'Mini tee', $tee->sides[0]->print_sizes );
		// A product printed on both sides, with a variation printed on its front only.
		[ $product, $variations ] = VariationSwitchesTest::variable(
			new ProductConfig( personalise: true, blank_id: $tee->blank_id, blank_name: $tee->blank_name, sides: array( $tee->sides[0], $back ) ),
			array( 'S' => $tee )
		);
		$key                      = str_repeat( 'a', 32 );
		WC()->cart->set_cart_contents(
			array(
				$key => array(
					'key'          => $key,
					'product_id'   => $product->get_id(),
					'variation_id' => $variations['S']->get_id(),
					'variation'    => array(),
					'quantity'     => 1,
					'data'         => $variations['S'],
					CartLine::KEY  => $line->to_array(),
				),
			)
		);
		$_GET[ CartEdit::PARAM ] = $key;
		$this->script_link();

		$designer = ( new InlineProducts( $this->plugin->settings(), $this->plugin->connection(), $this->edit() ) )->for_product( $product );

		$this->assertNotNull( $designer );
		$this->assertSame( array( 'front' ), array_keys( $designer->mounts ) );
		$this->assertSame( array( 'front' => 'Front' ), $designer->side_labels() );
	}

	public function test_the_edit_page_s_button_reads_update_design(): void {
		$_GET[ CartEdit::PARAM ] = $this->added();

		$this->assertSame( $this->plugin->labels()->get( Labels::UPDATE_DESIGN ), $this->edit()->button_text( 'Add to cart', $this->product() ) );
	}

	public function test_a_key_of_another_product_s_line_or_of_none_opens_no_line(): void {
		$_GET[ CartEdit::PARAM ] = $this->added();
		$other                   = new WC_Product_Simple();
		$other->save();
		$this->assertNull( $this->edit()->line_for( $other ) );

		$_GET[ CartEdit::PARAM ] = str_repeat( 'f', 32 );
		$this->assertNull( $this->edit()->line_for( $this->product() ) );
	}

	public function test_a_design_that_takes_no_correction_leaves_the_page_unavailable(): void {
		$_GET[ CartEdit::PARAM ] = $this->added();
		$this->recorder->json( 409, array( 'error' => 'not_editable' ) );
		$products = new InlineProducts( $this->plugin->settings(), $this->plugin->connection(), $this->edit() );

		$designer = $products->for_product( $this->product() );

		$this->assertNotNull( $designer );
		$this->assertFalse( $designer->available() );
		$this->assertNotSame( array(), $this->logger->entries );
	}

	public function test_an_update_saves_the_correction_and_the_line_takes_its_revision_proof_and_consent(): void {
		$key = $this->added();
		$this->script_corrected();

		$line = $this->edit()->update( $key, 'front', self::CORRECTION_TOKEN, false );

		$patch = $this->sent( 2 );
		$this->assertSame( 'PATCH', $patch['method'] );
		$this->assertStringEndsWith( '/v1/designs/' . DesignFixture::ID, (string) $patch['url'] );
		$this->assertSame( array( 'proofToken' => self::CORRECTION_TOKEN ), json_decode( (string) $patch['body'], true ) );

		$stored = $this->line( $key );
		$this->assertEquals( $line, $stored );
		$this->assertSame( 2, $stored->sides[0]->revision );
		$this->assertSame( self::CORRECTED_PROOF_ID, $stored->sides[0]->proof_id );
		$this->assertSame(
			array(
				'kind' => ApprovalMode::ADD_TO_CART,
				'at'   => Milliseconds::of( self::UPDATED_AT ),
			),
			$stored->consent
		);
		$thumbnail = (string) apply_filters( 'woocommerce_cart_item_thumbnail', '', WC()->cart->get_cart_item( $key ), $key );
		$this->assertStringContainsString( 'v=' . self::CORRECTED_PROOF_ID, html_entity_decode( $thumbnail ) );
		$this->assertSame( 1, did_action( 'pixonaut_wc_cart_design_updated' ) );
	}

	public function test_an_update_through_the_route_takes_the_design_s_field_values_now(): void {
		$key   = $this->added();
		$lines = WC()->cart->cart_contents;
		$lines[ $key ][ CartLine::KEY ]['sides'][0]['fields'] = array(
			array(
				'label' => 'Name',
				'type'  => FieldValue::TEXT,
				'value' => 'Anna',
			),
		);
		WC()->cart->set_cart_contents( $lines );
		$this->script_corrected();

		$response = rest_do_request(
			self::request(
				CartEditRoute::UPDATE_ROUTE,
				$key,
				array(
					'proofToken' => self::CORRECTION_TOKEN,
					'fields'     => array(
						array(
							'label' => 'Name',
							'type'  => FieldValue::TEXT,
							'value' => 'Bo',
						),
					),
				)
			)
		);

		$this->assertSame( 200, $response->get_status() );
		$this->assertEquals( array( new FieldValue( 'Name', FieldValue::TEXT, 'Bo' ) ), $this->line( $key )->sides[0]->fields );
	}

	public function test_values_beyond_what_a_line_keeps_are_cut_not_refused(): void {
		$key = $this->added();
		$this->script_corrected();
		$long = str_repeat( 'a', FieldValue::MAX_VALUE_LENGTH + 1 );

		$response = rest_do_request(
			self::request(
				CartEditRoute::UPDATE_ROUTE,
				$key,
				array(
					'proofToken' => self::CORRECTION_TOKEN,
					'fields'     => array_fill(
						0,
						FieldValue::MAX_FIELDS + 1,
						array(
							'label' => str_repeat( 'l', FieldValue::MAX_LABEL_LENGTH + 1 ),
							'type'  => FieldValue::TEXT,
							'value' => $long,
						)
					),
				)
			)
		);

		$this->assertSame( 200, $response->get_status() );
		$fields = $this->line( $key )->sides[0]->fields;
		$this->assertCount( FieldValue::MAX_FIELDS, $fields );
		$this->assertSame( FieldValue::MAX_VALUE_LENGTH, strlen( $fields[0]->value ) );
		$this->assertSame( FieldValue::MAX_LABEL_LENGTH, strlen( $fields[0]->label ) );
	}

	public function test_a_correction_that_changed_nothing_keeps_the_revision(): void {
		$key = $this->added();
		$this->script_corrected( false );

		$this->edit()->update( $key, 'front', self::CORRECTION_TOKEN, false );

		$this->assertSame( 1, $this->line( $key )->sides[0]->revision );
		$this->assertSame( DesignFixture::PROOF_ID, $this->line( $key )->sides[0]->proof_id );
	}

	public function test_the_checkbox_mode_needs_the_box_ticked_again(): void {
		$key                      = $this->added( ApprovalMode::CHECKBOX );
		$this->recorder->requests = array();

		try {
			$this->edit()->update( $key, 'front', self::CORRECTION_TOKEN, false );
			$this->fail( 'An update without the box ticked was saved.' );
		} catch ( EditRefused $refused ) {
			$this->assertSame( EditRefused::APPROVAL_REQUIRED, $refused->reason );
		}
		$this->assertSame( array(), $this->recorder->requests );

		$this->script_corrected();
		$this->edit()->update( $key, 'front', self::CORRECTION_TOKEN, true );
		$this->assertSame( ApprovalMode::CHECKBOX, $this->line( $key )->consent['kind'] ?? null );
	}

	public function test_the_proof_email_mode_records_no_consent(): void {
		$key = $this->added( ApprovalMode::PROOF_EMAIL );
		$this->script_corrected();

		$this->edit()->update( $key, 'front', self::CORRECTION_TOKEN, false );

		$this->assertNull( $this->line( $key )->consent );
	}

	/**
	 * @return array<string, array{0: string, 1: string}>
	 */
	public static function refusals(): array {
		return array(
			'changed elsewhere' => array( 'revision_changed', EditRefused::REVISION_CHANGED ),
			'proof expired'     => array( 'proof_expired', EditRefused::PROOF_EXPIRED ),
			'not editable'      => array( 'not_editable', EditRefused::NOT_EDITABLE ),
			'another design'    => array( 'proof_not_for_design', EditRefused::NOT_SAVED ),
		);
	}

	/**
	 * @dataProvider refusals
	 *
	 * @param string $code   Pixonaut's refusal.
	 * @param string $reason The update's refusal.
	 */
	public function test_a_refused_correction_leaves_the_line_as_it_was( string $code, string $reason ): void {
		$key    = $this->added();
		$before = $this->line( $key );
		$status = array(
			'proof_expired' => 410,
		)[ $code ] ?? 409;
		$this->recorder->json( $status, array( 'error' => $code ) );

		try {
			$this->edit()->update( $key, 'front', self::CORRECTION_TOKEN, false );
			$this->fail( 'A refused correction was saved.' );
		} catch ( EditRefused $refused ) {
			$this->assertSame( $reason, $refused->reason );
		}
		$this->assertEquals( $before, $this->line( $key ) );
	}

	public function test_the_route_saves_the_update_and_answers_a_refusal_with_its_message(): void {
		$key = $this->added();
		$this->script_corrected();

		$response = rest_do_request( self::request( CartEditRoute::UPDATE_ROUTE, $key, array( 'proofToken' => self::CORRECTION_TOKEN ) ) );
		$this->assertSame( 200, $response->get_status() );
		$this->assertSame(
			array(
				'revision'    => 2,
				'redirectUrl' => wc_get_cart_url(),
			),
			$response->get_data()
		);

		$this->recorder->json( 409, array( 'error' => 'revision_changed' ) );
		$refused = rest_do_request( self::request( CartEditRoute::UPDATE_ROUTE, $key, array( 'proofToken' => self::CORRECTION_TOKEN ) ) );
		$this->assertSame( 409, $refused->get_status() );
		$data = (array) $refused->get_data();
		$this->assertSame( 'revision_changed', $data['code'] ?? null );
		$this->assertSame( $this->plugin->labels()->get( Labels::DESIGN_CHANGED_ELSEWHERE ), $data['message'] ?? null );

		$gone = rest_do_request( self::request( CartEditRoute::UPDATE_ROUTE, str_repeat( 'a', 32 ), array( 'proofToken' => self::CORRECTION_TOKEN ) ) );
		$this->assertSame( 404, $gone->get_status() );
	}

	public function test_the_link_route_mints_the_side_s_link_again(): void {
		$key = $this->added();
		$this->script_link();
		$this->plugin->settings()->set( Settings::DESIGNER_PRELOAD, Preload::OFF );

		$response = rest_do_request( self::request( CartEditRoute::LINK_ROUTE, $key, array() ) );

		$this->assertSame( 200, $response->get_status() );
		$data = (array) $response->get_data();
		$this->assertSame( self::LINK, $data['mounts']['front']['correctionLink'] ?? null );
		$this->assertSame( Preload::OFF, $data['mounts']['front']['preload'] ?? null );
	}

	/**
	 * @param string               $route  The route under the plugin's namespace.
	 * @param string               $key    The cart item's key.
	 * @param array<string, mixed> $params Further body parameters.
	 *
	 * @return WP_REST_Request<array<string, mixed>>
	 */
	private static function request( string $route, string $key, array $params ): WP_REST_Request {
		$request = new WP_REST_Request( 'POST', '/' . Plugin::REST_NAMESPACE . $route );
		$request->set_header( 'Content-Type', 'application/json' );
		$request->set_body(
			(string) wp_json_encode(
				array(
					'key'  => $key,
					'side' => 'front',
				) + $params
			)
		);
		return $request;
	}
}
