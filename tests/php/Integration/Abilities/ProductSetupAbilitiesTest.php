<?php
/**
 * @package Pixonaut\WooCommerce
 */

declare(strict_types=1);

namespace Pixonaut\WooCommerce\Tests\Integration\Abilities;

use Pixonaut\WooCommerce\Abilities\Abilities;
use Pixonaut\WooCommerce\Admin\Notices;
use Pixonaut\WooCommerce\ApprovalMode;
use Pixonaut\WooCommerce\Api\Catalogue;
use Pixonaut\WooCommerce\Api\Hosts;
use Pixonaut\WooCommerce\Catalogue\CanvasRatio;
use Pixonaut\WooCommerce\Catalogue\PictureImage;
use Pixonaut\WooCommerce\Catalogue\PrintSize;
use Pixonaut\WooCommerce\Catalogue\ProductConfig;
use Pixonaut\WooCommerce\Catalogue\ProductPictures;
use Pixonaut\WooCommerce\Catalogue\ProductVariant;
use Pixonaut\WooCommerce\Catalogue\ProductVariants;
use Pixonaut\WooCommerce\Catalogue\ShowcaseImages;
use Pixonaut\WooCommerce\Catalogue\Side;
use Pixonaut\WooCommerce\Catalogue\StandingRow;
use Pixonaut\WooCommerce\Connection\StoredConnection;
use Pixonaut\WooCommerce\FormLayout;
use Pixonaut\WooCommerce\GroupNumbers;
use Pixonaut\WooCommerce\Layout;
use Pixonaut\WooCommerce\OptionGroups;
use Pixonaut\WooCommerce\Plugin;
use Pixonaut\WooCommerce\Settings\Settings;
use Pixonaut\WooCommerce\Tests\Support\BlankFixture;
use Pixonaut\WooCommerce\Tests\Support\HttpRecorder;
use Pixonaut\WooCommerce\Tests\Support\OpenApiAssert;
use Pixonaut\WooCommerce\Tests\Support\VariantsAnswer;
use ReflectionProperty;
use WC_Product;
use WC_Product_Simple;
use WC_Product_Variable;
use WC_Product_Variation;
use WP_Ability;
use WP_Error;
use WP_UnitTestCase;

/**
 * The abilities that set a product up as its product screen does, run as
 * the Abilities API (and so the MCP Adapter) runs them, against a recorder
 * that answers as Pixonaut.
 */
final class ProductSetupAbilitiesTest extends WP_UnitTestCase {

	public const SET_SETUP = Abilities::NAMESPACE . '/set-product-setup';

	public const SET_SHOWCASE = Abilities::NAMESPACE . '/set-product-showcase';

	public const MOVE_TEMPLATE = Abilities::NAMESPACE . '/move-product-template';

	public const USE_PICTURE = Abilities::NAMESPACE . '/use-product-picture';

	public const RESET_LAYOUT = Abilities::NAMESPACE . '/reset-standing-layout';

	public const WRITES = array( self::SET_SETUP, self::SET_SHOWCASE, self::MOVE_TEMPLATE, self::USE_PICTURE, self::RESET_LAYOUT );

	private const TEMPLATE_ID = 'mini-tee@2';

	private const NEXT_TEMPLATE_ID = 'mini-tee@3';

	/**
	 * The template's name in the spec's example answer of `GET /v1/templates/{id}`.
	 */
	private const TEMPLATE_NAME = 'T-shirt print, front';

	private const BLANK_ID = 'blank_tee';

	private const CONNECTED_AT = 1767225600;

	/**
	 * A print size of the template's ratio (280 × 360), in millimetres.
	 */
	private const SMALL_WIDTH_MM = 140;

	private const SMALL_HEIGHT_MM = 180;

	private const LARGE_WIDTH_MM = 280;

	private const LARGE_HEIGHT_MM = 360;

	private Plugin $plugin;

	private HttpRecorder $recorder;

	private VariantsAnswer $variants;

	public function set_up(): void {
		parent::set_up();
		$this->plugin   = Plugin::boot( dirname( __DIR__, 4 ) . '/pixonaut-for-woocommerce.php' );
		$this->recorder = ( new HttpRecorder( Hosts::server_api() ) )->start();
		$this->variants = ( new VariantsAnswer() )->start();
		$this->plugin->connection_store()->save( self::connection() );
		// A product without a blank is checked against the printshops it may be sent to.
		BlankFixture::remember_printshops( $this->plugin->catalogue_cache() );
		( new ReflectionProperty( ProductVariants::class, 'read' ) )->setValue( $this->plugin->product_variants(), array() );
		wp_set_current_user( $this->user( 'shop_manager' ) );
	}

	public function tear_down(): void {
		$this->recorder->stop();
		$this->variants->stop();
		$this->plugin->connection_store()->clear();
		delete_option( Notices::OPTION );
		delete_option( Settings::OPTION );
		parent::tear_down();
	}

	public function test_a_product_on_a_blank_is_set_up_with_two_sides_their_sizes_and_its_settings(): void {
		$product = $this->product();
		$this->recorder->json(
			200,
			BlankFixture::answer(
				array(
					'front' => 'Front',
					'back'  => 'Back',
				)
			)
		);
		// Sizes of their own are judged without the placement's constraints.
		$this->recorder->json( 200, array( 'verdicts' => array( array( 'legal' => true ), array( 'legal' => true ) ) ) );
		$this->recorder->json( 200, array( 'verdicts' => array( array( 'legal' => true ) ) ) );
		$this->recorder->raw( 200, OpenApiAssert::example( 'GET', '/v1/templates/{id}', 200, array( 'status' => Catalogue::LIVE ) ), 'application/json' );

		$answer = $this->succeed(
			self::SET_SETUP,
			array(
				'product_id'    => $product->get_id(),
				'blank_id'      => self::BLANK_ID,
				'sides'         => array(
					array(
						'key'          => 'front',
						'label'        => 'Chest',
						'template_id'  => self::TEMPLATE_ID,
						'placement_id' => 'front',
						'print_sizes'  => array( self::size( self::SMALL_WIDTH_MM, self::SMALL_HEIGHT_MM, 'Small' ), self::size( self::LARGE_WIDTH_MM, self::LARGE_HEIGHT_MM, 'Large' ) ),
						'start_size'   => 1,
						'form_groups'  => array(
							'groups' => array(
								array(
									'id'    => 'wc-names',
									'title' => 'Names',
									'items' => array( array( 'field' => 'name:text' ) ),
								),
							),
						),
					),
					array(
						'key'          => 'back',
						'template_id'  => self::TEMPLATE_ID,
						'placement_id' => 'back',
						'print_sizes'  => array( self::size( self::LARGE_WIDTH_MM, self::LARGE_HEIGHT_MM, '' ) ),
					),
				),
				'layout'        => Layout::BUTTON,
				'form_layout'   => FormLayout::available()[0],
				'approval_mode' => ApprovalMode::CHECKBOX,
				'views'         => array( BlankFixture::VIEW_ID ),
				'initial_view'  => BlankFixture::VIEW_ID,
				'variant'       => BlankFixture::VARIANT_REF,
			)
		);

		$this->assertTrue( $answer['personalised'] );
		$this->assertTrue( $answer['valid'], (string) $answer['invalid_reason'] . ' ' . wp_json_encode( array_column( $this->recorder->requests, 'url' ) ) );
		$this->assertSame( array( 'front', 'back' ), array_column( $answer['sides'], 'key' ) );
		$this->assertSame( 'Chest', $answer['sides'][0]['label'] );
		$this->assertSame( array( 'Small', 'Large' ), array_column( $answer['sides'][0]['print_sizes'], 'label' ) );
		$this->assertSame( 1, $answer['sides'][0]['start_size'] );
		$this->assertSame( self::TEMPLATE_NAME, $answer['sides'][0]['template_name'], 'named as Pixonaut names it' );
		$this->assertSame( BlankFixture::VARIANT_REF, $answer['variant']['ref'] ?? null );
		$this->assertTrue( $answer['variant']['picked'] ?? null );
		$this->assertContains( $answer['picture'], array( 'off', 'pending', 'none' ) );
		$config = ProductConfig::stored_on( $this->reload( $product->get_id() ) );
		$this->assertSame( Layout::BUTTON, $config->layout );
		$this->assertSame( FormLayout::available()[0], $config->form_layout );
		$this->assertSame( ApprovalMode::CHECKBOX, $config->approval_mode );
		$this->assertSame( array( BlankFixture::VIEW_ID ), $config->views );
		$this->assertSame( BlankFixture::VIEW_ID, $config->initial_view );
		$this->assertSame( 'wc-names', $config->sides[0]->form_groups?->groups[0]['id'] );
		$this->assertSame( $this->reload( $product->get_id() )->is_purchasable(), $answer['purchasable'] );
	}

	public function test_a_template_id_without_its_version_links_the_live_version_and_left_out_fields_are_kept(): void {
		$product = $this->product();
		( new ProductConfig( approval_mode: ApprovalMode::CHECKBOX, layout: Layout::BUTTON ) )->save_to( $product );
		$this->recorder->json( 200, self::live_templates( 'mini-tee', 3 ) );
		$this->recorder->json( 200, array( 'verdicts' => array( array( 'legal' => true ) ) ) );
		$this->recorder->raw( 200, OpenApiAssert::example( 'GET', '/v1/templates/{id}', 200, array( 'status' => Catalogue::LIVE ) ), 'application/json' );

		$answer = $this->succeed(
			self::SET_SETUP,
			array(
				'product_id'   => $product->get_id(),
				'personalised' => true,
				'printshop_id' => BlankFixture::PRINTSHOP_ID,
				'sides'        => array(
					array(
						'template_id' => 'mini-tee',
						'print_sizes' => array( self::size( self::LARGE_WIDTH_MM, self::LARGE_HEIGHT_MM, 'A3' ) ),
					),
				),
			)
		);

		$this->assertTrue( $answer['valid'], (string) $answer['invalid_reason'] );
		$this->assertSame( self::NEXT_TEMPLATE_ID, $answer['sides'][0]['template_id'] );
		$this->assertStringEndsWith( '/v1/templates/mini-tee%403', $this->recorder->requests[2]['url'] );
		$this->assertSame( BlankFixture::PRINTSHOP_ID, $answer['printshop_id'] );
		$this->assertSame( ApprovalMode::CHECKBOX, $answer['approval_mode'] );
		$this->assertSame( Layout::BUTTON, $answer['layout'] );
	}

	public function test_a_variation_gets_its_own_setup_follows_its_product_again_and_picks_its_variant(): void {
		$product = $this->variable_product();
		$small   = $this->variation( $product, 'S' );
		$this->recorder->json( 200, array( 'verdicts' => array( array( 'legal' => true ) ) ) );
		$this->recorder->raw( 200, OpenApiAssert::example( 'GET', '/v1/templates/{id}', 200, array( 'status' => Catalogue::LIVE ) ), 'application/json' );

		$own = $this->succeed(
			self::SET_SETUP,
			array(
				'product_id' => $small->get_id(),
				'blank_id'   => '',
				'sides'      => array(
					array(
						'template_id' => self::TEMPLATE_ID,
						'print_sizes' => array( self::size( self::SMALL_WIDTH_MM, self::SMALL_HEIGHT_MM, 'Small' ) ),
					),
				),
			)
		);

		$this->assertTrue( $own['own_setup'] );
		$this->assertTrue( $own['valid'], (string) $own['invalid_reason'] );
		$this->assertSame( 'Small', $own['sides'][0]['print_sizes'][0]['label'] );
		$this->assertTrue( ProductConfig::stored_on( $this->reload( $small->get_id() ) )->override );
		$this->assertSame( self::BLANK_ID, ProductConfig::stored_on( $this->reload( $product->get_id() ) )->blank_id, 'the product keeps its own' );

		$following = $this->succeed(
			self::SET_SETUP,
			array(
				'product_id' => $small->get_id(),
				'own_setup'  => false,
				'variant'    => 'v_s',
			)
		);

		$this->assertFalse( $following['own_setup'] );
		$this->assertSame( self::BLANK_ID, $following['blank_id'], 'it gives its product\'s setup' );
		$variation = $this->reload( $small->get_id() );
		$this->assertFalse( ProductConfig::stored_on( $variation )->override );
		$this->assertSame( 'mini-tee', ProductConfig::stored_on( $variation )->sides[0]->template_base_id, 'its own link is kept for later' );
		$this->assertSame( 'v_s', ProductVariant::stored_on( $variation )?->pick );
	}

	public function test_moving_to_a_new_version_keeps_the_sides_of_the_product_and_its_variations(): void {
		$product = $this->product();
		self::template_only( 'Small', self::SMALL_WIDTH_MM, self::SMALL_HEIGHT_MM )->save_to( $product );
		$this->recorder->json( 200, array( 'verdicts' => array( array( 'legal' => true ) ) ) );
		$this->recorder->raw( 200, OpenApiAssert::example( 'GET', '/v1/templates/{id}', 200, array( 'status' => Catalogue::LIVE ) ), 'application/json' );

		$answer = $this->succeed(
			self::MOVE_TEMPLATE,
			array(
				'product_id'     => $product->get_id(),
				'template_id'    => 'mini-tee',
				'to_template_id' => self::NEXT_TEMPLATE_ID,
			)
		);

		$this->assertSame( 1, $answer['moved_sides_total'] );
		$this->assertSame( self::NEXT_TEMPLATE_ID, $answer['sides'][0]['template_id'] );
		$this->assertSame( 'Small', $answer['sides'][0]['print_sizes'][0]['label'], 'its size is kept' );
		$this->assertSame( 'Front print', $answer['sides'][0]['label'], 'its label is kept' );
		$this->assertStringEndsWith( '/v1/templates/mini-tee%403/print-size', $this->recorder->requests[0]['url'] );
	}

	public function test_a_pinned_side_stays_on_an_older_version_and_keeps_its_pin_when_its_sides_are_left_out(): void {
		$product = $this->product();
		$config  = self::template_only( 'Small', self::SMALL_WIDTH_MM, self::SMALL_HEIGHT_MM );
		// The product already knows of version 3, so a following side on version 2 would be moved to it.
		$config->with_sides( array( $config->sides[0]->with_versions( false, null, 3 ) ) )->save_to( $product );
		$this->recorder->json( 200, array( 'verdicts' => array( array( 'legal' => true ) ) ) );
		$this->recorder->raw( 200, OpenApiAssert::example( 'GET', '/v1/templates/{id}', 200, array( 'status' => Catalogue::LIVE ) ), 'application/json' );

		$answer = $this->succeed(
			self::SET_SETUP,
			array(
				'product_id' => $product->get_id(),
				'blank_id'   => '',
				'sides'      => array(
					array(
						'template_id'     => self::TEMPLATE_ID,
						'template_pinned' => true,
						'print_sizes'     => array( self::size( self::SMALL_WIDTH_MM, self::SMALL_HEIGHT_MM, 'Small' ) ),
					),
				),
			)
		);

		$this->assertSame( self::TEMPLATE_ID, $answer['sides'][0]['template_id'] );
		$this->assertTrue( $answer['sides'][0]['template_pinned'] );

		$this->recorder->json( 200, array( 'verdicts' => array( array( 'legal' => true ) ) ) );
		$this->recorder->raw( 200, OpenApiAssert::example( 'GET', '/v1/templates/{id}', 200, array( 'status' => Catalogue::LIVE ) ), 'application/json' );
		$this->succeed(
			self::SET_SETUP,
			array(
				'product_id'    => $product->get_id(),
				'approval_mode' => ApprovalMode::CHECKBOX,
			)
		);

		$side = ProductConfig::stored_on( $this->reload( $product->get_id() ) )->sides[0];
		$this->assertTrue( $side->pinned );
		$this->assertSame( 2, $side->template_version );
	}

	public function test_the_canvas_ratio_is_set_on_the_product_and_a_side_and_kept_when_left_out(): void {
		$product = $this->product();
		self::template_only( 'Small', self::SMALL_WIDTH_MM, self::SMALL_HEIGHT_MM )->save_to( $product );
		$this->recorder->json( 200, array( 'verdicts' => array( array( 'legal' => true ) ) ) );
		$this->recorder->raw( 200, OpenApiAssert::example( 'GET', '/v1/templates/{id}', 200, array( 'status' => Catalogue::LIVE ) ), 'application/json' );

		$answer = $this->succeed(
			self::SET_SETUP,
			array(
				'product_id'   => $product->get_id(),
				'blank_id'     => '',
				'canvas_ratio' => CanvasRatio::TEMPLATE,
				'sides'        => array(
					array(
						'template_id'  => self::TEMPLATE_ID,
						'canvas_ratio' => CanvasRatio::PRINT_SIZE,
						'print_sizes'  => array( self::size( self::SMALL_WIDTH_MM, self::SMALL_HEIGHT_MM, 'Small' ) ),
					),
				),
			)
		);

		$this->assertSame( CanvasRatio::TEMPLATE, $answer['canvas_ratio'] );
		$this->assertSame( CanvasRatio::PRINT_SIZE, $answer['sides'][0]['canvas_ratio'] );

		$this->recorder->json( 200, array( 'verdicts' => array( array( 'legal' => true ) ) ) );
		$this->recorder->raw( 200, OpenApiAssert::example( 'GET', '/v1/templates/{id}', 200, array( 'status' => Catalogue::LIVE ) ), 'application/json' );
		$this->succeed(
			self::SET_SETUP,
			array(
				'product_id'    => $product->get_id(),
				'approval_mode' => ApprovalMode::CHECKBOX,
			)
		);

		$config = ProductConfig::stored_on( $this->reload( $product->get_id() ) );
		$this->assertTrue( $config->draw_at_template_ratio );
		$this->assertFalse( $config->sides[0]->draw_at_template_ratio );
	}

	public function test_the_products_choices_for_its_forms_options_and_numbers_are_read_and_set_and_kept_when_left_out(): void {
		$product = $this->product();
		self::template_only( 'Small', self::SMALL_WIDTH_MM, self::SMALL_HEIGHT_MM )->save_to( $product );
		$this->recorder->json( 200, array( 'verdicts' => array( array( 'legal' => true ) ) ) );
		$this->recorder->raw( 200, OpenApiAssert::example( 'GET', '/v1/templates/{id}', 200, array( 'status' => Catalogue::LIVE ) ), 'application/json' );

		$answer = $this->succeed(
			self::SET_SETUP,
			array(
				'product_id'    => $product->get_id(),
				'option_groups' => OptionGroups::LAST,
				'group_numbers' => GroupNumbers::OFF,
			)
		);

		$this->assertSame( OptionGroups::LAST, $answer['option_groups'] );
		$this->assertSame( GroupNumbers::OFF, $answer['group_numbers'] );

		$this->recorder->json( 200, array( 'verdicts' => array( array( 'legal' => true ) ) ) );
		$this->recorder->raw( 200, OpenApiAssert::example( 'GET', '/v1/templates/{id}', 200, array( 'status' => Catalogue::LIVE ) ), 'application/json' );
		$this->succeed(
			self::SET_SETUP,
			array(
				'product_id'    => $product->get_id(),
				'approval_mode' => ApprovalMode::CHECKBOX,
			)
		);
		$config = ProductConfig::stored_on( $this->reload( $product->get_id() ) );
		$this->assertSame( OptionGroups::LAST, $config->option_groups );
		$this->assertSame( GroupNumbers::OFF, $config->group_numbers );

		$this->recorder->json( 200, array( 'verdicts' => array( array( 'legal' => true ) ) ) );
		$this->recorder->raw( 200, OpenApiAssert::example( 'GET', '/v1/templates/{id}', 200, array( 'status' => Catalogue::LIVE ) ), 'application/json' );
		$this->succeed(
			self::SET_SETUP,
			array(
				'product_id'    => $product->get_id(),
				'option_groups' => '',
				'group_numbers' => '',
			)
		);
		$config = ProductConfig::stored_on( $this->reload( $product->get_id() ) );
		$this->assertNull( $config->option_groups, 'empty follows the store again' );
		$this->assertNull( $config->group_numbers );
	}

	public function test_a_variation_is_refused_these_choices_it_follows_its_product_for(): void {
		$small = $this->variation( $this->variable_product(), 'S' );

		$answer = $this->ability( self::SET_SETUP )->execute(
			array(
				'product_id'    => $small->get_id(),
				'group_numbers' => GroupNumbers::OFF,
			)
		);

		$this->assertInstanceOf( WP_Error::class, $answer );
		$this->assertSame( 'pixonaut_wc_ability_product_only', $answer->get_error_code() );
	}

	public function test_a_side_moved_to_a_version_stays_on_it_until_it_is_given_without_a_pin(): void {
		$product = $this->product();
		self::template_only( 'Small', self::SMALL_WIDTH_MM, self::SMALL_HEIGHT_MM )->save_to( $product );
		$this->recorder->json( 200, array( 'verdicts' => array( array( 'legal' => true ) ) ) );
		$this->recorder->raw( 200, OpenApiAssert::example( 'GET', '/v1/templates/{id}', 200, array( 'status' => Catalogue::LIVE ) ), 'application/json' );

		$pinned = $this->succeed(
			self::MOVE_TEMPLATE,
			array(
				'product_id'     => $product->get_id(),
				'template_id'    => 'mini-tee',
				'to_template_id' => self::NEXT_TEMPLATE_ID,
			)
		);

		$this->assertTrue( $pinned['sides'][0]['template_pinned'] );

		$this->recorder->json( 200, array( 'verdicts' => array( array( 'legal' => true ) ) ) );
		$this->recorder->raw( 200, OpenApiAssert::example( 'GET', '/v1/templates/{id}', 200, array( 'status' => Catalogue::LIVE ) ), 'application/json' );
		$side = $pinned['sides'][0];
		// What `list-products` gives besides what `set-product-setup` takes; no blank, so no placement.
		unset( $side['template_pinned'], $side['template_name'], $side['placement_id'], $side['placement_name'], $side['standing_options'] );
		$following = $this->succeed(
			self::SET_SETUP,
			array(
				'product_id' => $product->get_id(),
				'sides'      => array( $side ),
			)
		);

		$this->assertSame( self::NEXT_TEMPLATE_ID, $following['sides'][0]['template_id'] );
		$this->assertFalse( $following['sides'][0]['template_pinned'] );
	}

	public function test_a_variable_products_variations_with_their_own_setup_move_with_it(): void {
		$product = $this->variable_product();
		$small   = $this->variation( $product, 'S' );
		$large   = $this->variation( $product, 'L' );
		self::template_only( 'Small', self::SMALL_WIDTH_MM, self::SMALL_HEIGHT_MM, true )->save_to( $small );
		$this->recorder->json( 200, array( 'verdicts' => array( array( 'legal' => true ) ) ) );
		$this->recorder->raw( 200, OpenApiAssert::example( 'GET', '/v1/templates/{id}', 200, array( 'status' => Catalogue::LIVE ) ), 'application/json' );

		$answer = $this->succeed(
			self::MOVE_TEMPLATE,
			array(
				'product_id'     => $product->get_id(),
				'template_id'    => self::TEMPLATE_ID,
				'to_template_id' => self::NEXT_TEMPLATE_ID,
			)
		);

		// The product is on a blank with another template: only the variation moves.
		$this->assertSame( 1, $answer['moved_sides_total'] );
		$this->assertSame( array( $small->get_id() ), array_column( $answer['variations'], 'product_id' ) );
		$moved = ProductConfig::stored_on( $this->reload( $small->get_id() ) );
		$this->assertTrue( $moved->override );
		$this->assertSame( 3, $moved->sides[0]->template_version );
		$this->assertFalse( ProductConfig::is_stored_on( $this->reload( $large->get_id() ) ) );
	}

	public function test_moving_from_a_template_no_side_is_on_changes_nothing(): void {
		$product = $this->product();
		self::template_only( 'Small', self::SMALL_WIDTH_MM, self::SMALL_HEIGHT_MM )->save_to( $product );
		$before = ProductConfig::document_on( $product );

		$answer = $this->ability( self::MOVE_TEMPLATE )->execute(
			array(
				'product_id'     => $product->get_id(),
				'template_id'    => 'mini-poster',
				'to_template_id' => 'mini-poster@2',
			)
		);

		$this->assertInstanceOf( WP_Error::class, $answer );
		$this->assertSame( 'pixonaut_wc_ability_not_on_template', $answer->get_error_code() );
		$this->assertSame( $before, ProductConfig::document_on( $this->reload( $product->get_id() ) ) );
		$this->assertSame( array(), $this->recorder->requests );
	}

	public function test_a_link_kept_while_personalisation_is_off_is_not_moved_or_turned_on(): void {
		$product = $this->product();
		$off     = self::template_only( 'Small', self::SMALL_WIDTH_MM, self::SMALL_HEIGHT_MM )->with_personalise( false );
		$off->save_to( $product );
		$before = ProductConfig::document_on( $product );

		$answer = $this->ability( self::MOVE_TEMPLATE )->execute(
			array(
				'product_id'     => $product->get_id(),
				'template_id'    => 'mini-tee',
				'to_template_id' => self::NEXT_TEMPLATE_ID,
			)
		);

		$this->assertInstanceOf( WP_Error::class, $answer );
		$this->assertSame( 'pixonaut_wc_ability_not_on_template', $answer->get_error_code() );
		$this->assertSame( $before, ProductConfig::document_on( $this->reload( $product->get_id() ) ) );
		$this->assertSame( array(), $this->recorder->requests );
	}

	public function test_a_variation_moved_to_another_blank_matches_its_variant_again(): void {
		$product = $this->variable_product();
		$small   = $this->variation( $product, 'S' );
		$this->succeed(
			self::SET_SETUP,
			array(
				'product_id' => $small->get_id(),
				'variant'    => 'v_l',
			)
		);
		$this->assertSame( 'v_l', ProductVariant::stored_on( $this->reload( $small->get_id() ) )?->pick );
		$this->recorder->json( 200, array( 'verdicts' => array( array( 'legal' => true ) ) ) );
		$this->recorder->raw( 200, OpenApiAssert::example( 'GET', '/v1/templates/{id}', 200, array( 'status' => Catalogue::LIVE ) ), 'application/json' );

		// Its own setup has no blank: the pick of the product's blank no longer applies.
		$this->succeed(
			self::SET_SETUP,
			array(
				'product_id' => $small->get_id(),
				'blank_id'   => '',
				'sides'      => array(
					array(
						'template_id' => self::TEMPLATE_ID,
						'print_sizes' => array( self::size( self::SMALL_WIDTH_MM, self::SMALL_HEIGHT_MM, 'Small' ) ),
					),
				),
			)
		);

		$this->assertNull( ProductVariant::stored_on( $this->reload( $small->get_id() ) )->pick ?? null );
	}

	public function test_a_showcase_is_chosen_from_the_templates_sets_and_the_pictures_drawn_again(): void {
		$this->pictures_on();
		$product = $this->product();
		self::template_only( 'Small', self::SMALL_WIDTH_MM, self::SMALL_HEIGHT_MM )->save_to( $product );
		$this->recorder->raw( 200, OpenApiAssert::example( 'GET', '/v1/templates/{id}', 200 ), 'application/json' );

		$answer = $this->succeed(
			self::SET_SHOWCASE,
			array(
				'product_id' => $product->get_id(),
				'images'     => array(
					array(
						'id'   => 'graduation-blue',
						'kind' => ShowcaseImages::MOCKUP,
					),
				),
			)
		);

		$this->assertSame(
			array(
				array(
					'id'   => 'graduation-blue',
					'name' => 'Blue, with a name',
					// A product without a blank has no photo to draw on.
					'kind' => ShowcaseImages::PREVIEW,
				),
			),
			$answer['showcase_images']
		);
		$this->assertSame( 'graduation-blue', $answer['available_showcases'][0]['id'] );
		$this->assertSame( 'pending', $answer['picture'], 'drawn again with the set' );
		$this->assertSame( $answer['showcase_images'], ShowcaseImages::on( $this->reload( $product->get_id() ) ) );
	}

	public function test_a_showcase_set_the_template_does_not_offer_is_refused(): void {
		$product = $this->product();
		self::template_only( 'Small', self::SMALL_WIDTH_MM, self::SMALL_HEIGHT_MM )->save_to( $product );
		$this->recorder->raw( 200, OpenApiAssert::example( 'GET', '/v1/templates/{id}', 200 ), 'application/json' );

		$answer = $this->ability( self::SET_SHOWCASE )->execute(
			array(
				'product_id' => $product->get_id(),
				'images'     => array( array( 'id' => 'wedding' ) ),
			)
		);

		$this->assertInstanceOf( WP_Error::class, $answer );
		$this->assertStringContainsString( 'graduation-blue', $answer->get_error_message() );
		$this->assertSame( array(), ShowcaseImages::on( $this->reload( $product->get_id() ) ) );
	}

	public function test_the_pixonaut_picture_takes_the_place_of_the_store_managers_image(): void {
		$this->pictures_on();
		$product = $this->product();
		self::template_only( 'Small', self::SMALL_WIDTH_MM, self::SMALL_HEIGHT_MM )->save_to( $product );
		$own     = self::factory()->attachment->create();
		$picture = self::factory()->attachment->create();
		$this->assertIsInt( $own );
		$this->assertIsInt( $picture );
		update_post_meta( $picture, PictureImage::PICTURE_OF_META, $product->get_id() );
		update_post_meta( $product->get_id(), PictureImage::PREVIOUS_META, 0 );
		update_post_meta( $product->get_id(), PictureImage::THUMBNAIL_META, $own );
		$product = $this->reload( $product->get_id() );
		$product->update_meta_data( ProductPictures::META_KEY, (string) wp_json_encode( array( 'attachment' => $picture ) ) );
		$product->save_meta_data();

		$answer = $this->succeed( self::USE_PICTURE, array( 'product_id' => $product->get_id() ) );

		$this->assertSame( $picture, PictureImage::current( $this->reload( $product->get_id() ) ) );
		$this->assertNotSame( 'own_image', $answer['picture'] );
	}

	public function test_an_image_not_yet_replaced_by_a_picture_is_left_alone(): void {
		$this->pictures_on();
		$product = $this->product();
		self::template_only( 'Small', self::SMALL_WIDTH_MM, self::SMALL_HEIGHT_MM )->save_to( $product );
		$own = self::factory()->attachment->create();
		$this->assertIsInt( $own );
		// The first picture is still to be drawn: the image is not remembered yet.
		update_post_meta( $product->get_id(), PictureImage::THUMBNAIL_META, $own );

		$answer = $this->ability( self::USE_PICTURE )->execute( array( 'product_id' => $product->get_id() ) );

		$this->assertInstanceOf( WP_Error::class, $answer );
		$this->assertSame( 'pixonaut_wc_ability_no_own_image', $answer->get_error_code() );
		$this->assertSame( $own, PictureImage::current( $this->reload( $product->get_id() ) ) );
	}

	public function test_the_picture_is_refused_while_the_store_does_not_use_pictures(): void {
		update_option( Settings::OPTION, array( Settings::PRODUCT_PICTURES => false ) );
		$product = $this->product();
		self::template_only( 'Small', self::SMALL_WIDTH_MM, self::SMALL_HEIGHT_MM )->save_to( $product );

		$answer = $this->ability( self::USE_PICTURE )->execute( array( 'product_id' => $product->get_id() ) );

		$this->assertInstanceOf( WP_Error::class, $answer );
		$this->assertSame( 'pixonaut_wc_ability_pictures_off', $answer->get_error_code() );
	}

	public function test_resetting_an_option_not_adjusted_asks_nothing_and_an_unknown_one_is_refused(): void {
		$product = $this->product();
		$side    = ( new Side( 'front', 'Front', 'front', 'Front', 'mini-tee', 2, null, 'Mini tee', mode: Side::STANDING ) )
			->with_standing_rows( array( new StandingRow( array( 'Size' => 'S' ), 'des_row_s', array( 'v_s' ) ) ) );
		( new ProductConfig( personalise: true, blank_id: self::BLANK_ID, sides: array( $side ) ) )->save_to( $product );

		$answer = $this->succeed(
			self::RESET_LAYOUT,
			array(
				'product_id' => $product->get_id(),
				'side_key'   => 'front',
				'design_id'  => 'des_row_s',
			)
		);
		$this->assertSame( array( 'des_row_s' ), array_column( $answer['sides'][0]['standing_options'], 'design_id' ) );
		$this->assertFalse( $answer['sides'][0]['standing_options'][0]['adjusted'] );

		$unknown = $this->ability( self::RESET_LAYOUT )->execute(
			array(
				'product_id' => $product->get_id(),
				'side_key'   => 'front',
				'design_id'  => 'des_gone',
			)
		);
		$this->assertInstanceOf( WP_Error::class, $unknown );
		$this->assertSame( 'pixonaut_wc_ability_unknown_option', $unknown->get_error_code() );
		$this->assertSame( array(), $this->recorder->requests );
	}

	public function test_every_write_is_refused_to_a_user_who_may_not_edit_the_product(): void {
		$product   = $this->variable_product();
		$variation = $this->variation( $product, 'S' );
		$before    = ProductConfig::document_on( $product );
		$inputs    = array(
			self::SET_SETUP     => array( 'personalised' => false ),
			self::SET_SHOWCASE  => array( 'images' => array() ),
			self::MOVE_TEMPLATE => array(
				'template_id'    => 'mini-tee',
				'to_template_id' => self::NEXT_TEMPLATE_ID,
			),
			self::USE_PICTURE   => array(),
			self::RESET_LAYOUT  => array(
				'side_key'  => 'front',
				'design_id' => 'des_row_s',
			),
		);
		$this->assertSame( self::WRITES, array_keys( $inputs ) );

		foreach ( array( 'customer', 'contributor', 'author' ) as $role ) {
			wp_set_current_user( $this->user( $role ) );
			foreach ( $inputs as $name => $input ) {
				foreach ( array( $product->get_id(), $variation->get_id() ) as $id ) {
					$answer = $this->ability( $name )->execute( array( 'product_id' => $id ) + $input );
					$this->assertInstanceOf( WP_Error::class, $answer, $name . ' as ' . $role );
					$this->assertSame( 'ability_invalid_permissions', $answer->get_error_code(), $name . ' as ' . $role );
				}
			}
		}
		$this->assertSame( $before, ProductConfig::document_on( $this->reload( $product->get_id() ) ) );
		$this->assertSame( array(), $this->recorder->requests );
	}

	public function test_the_writes_are_registered_and_marked_as_changing_the_product(): void {
		foreach ( self::WRITES as $name ) {
			$ability = $this->ability( $name );
			$this->assertSame( Abilities::CATEGORY, $ability->get_category(), $name );
			$this->assertFalse( $ability->get_meta_item( 'annotations' )['readonly'], $name );
			$this->assertTrue( $ability->get_meta_item( 'annotations' )['destructive'], $name );
			$this->assertSame( array( 'public' => true ), $ability->get_meta_item( 'mcp' ), $name );
		}
	}

	/**
	 * Runs an ability that is to succeed.
	 *
	 * @param string       $name  The ability.
	 * @param array<mixed> $input Its input.
	 * @return array<string, mixed>
	 */
	private function succeed( string $name, array $input ): array {
		$answer = $this->ability( $name )->execute( $input );
		$this->assertIsArray( $answer, $answer instanceof WP_Error ? $answer->get_error_message() : $name );
		return $answer;
	}

	private function ability( string $name ): WP_Ability {
		$ability = wp_get_ability( $name );
		$this->assertInstanceOf( WP_Ability::class, $ability, $name );
		return $ability;
	}

	/**
	 * A print size as the abilities take it.
	 *
	 * @param float  $width_mm  Width in millimetres.
	 * @param float  $height_mm Height in millimetres.
	 * @param string $label     Its label; empty for the millimetres.
	 * @return array<string, mixed>
	 */
	private static function size( float $width_mm, float $height_mm, string $label ): array {
		return array(
			'width_mm'  => $width_mm,
			'height_mm' => $height_mm,
		) + ( '' === $label ? array() : array( 'label' => $label ) );
	}

	/**
	 * A template-only link on `mini-tee@2` sold in one print size.
	 *
	 * @param string $label     The size's label.
	 * @param float  $width_mm  Width in millimetres.
	 * @param float  $height_mm Height in millimetres.
	 * @param bool   $override  Whether a variation's own configuration applies.
	 */
	private static function template_only( string $label, float $width_mm, float $height_mm, bool $override = false ): ProductConfig {
		return new ProductConfig(
			personalise: true,
			override: $override,
			sides: array( new Side( 'design', 'Front print', null, null, 'mini-tee', 2, null, 'Mini tee', array( new PrintSize( $width_mm, $height_mm, $label ) ) ) ),
		);
	}

	/**
	 * A `GET /v1/templates?status=live` answer listing one template.
	 *
	 * @param string $base_id Its base id.
	 * @param int    $version Its live version.
	 * @return array<string, mixed>
	 */
	private static function live_templates( string $base_id, int $version ): array {
		return array(
			'items'    => array(
				array(
					'id'                   => $base_id . '@' . $version,
					'baseId'               => $base_id,
					'name'                 => 'Mini tee',
					'latestVersion'        => $version,
					'ratio'                => array(
						'w' => self::LARGE_WIDTH_MM,
						'h' => self::LARGE_HEIGHT_MM,
					),
					'ratioTolerance'       => 0,
					'fieldKeys'            => array(),
					'previewUrl'           => '',
					'offersProperties'     => true,
					'backgroundSeeThrough' => null,
				),
			),
			'total'    => 1,
			'page'     => 1,
			'pageSize' => Catalogue::LIST_PAGE_SIZE,
		);
	}

	private function pictures_on(): void {
		update_option( Settings::OPTION, array( Settings::PRODUCT_PICTURES => true ) + (array) get_option( Settings::OPTION, array() ) );
	}

	private function product(): WC_Product_Simple {
		$product = new WC_Product_Simple();
		$product->set_name( 'Personalised tee' );
		$product->set_regular_price( '25' );
		$product->save();
		return $product;
	}

	/**
	 * A variable product sold in sizes, personalised on the blank's front.
	 */
	private function variable_product(): WC_Product_Variable {
		$this->variants->answer = BlankFixture::variants(
			array(
				array(
					'ref'     => 'v_s',
					'options' => array( 'Size' => 'S' ),
				),
				array(
					'ref'     => 'v_l',
					'options' => array( 'Size' => 'L' ),
				),
			),
			array( 'Size' )
		);
		$product                = new WC_Product_Variable();
		$attribute              = new \WC_Product_Attribute();
		$attribute->set_name( 'Size' );
		$attribute->set_options( array( 'S', 'L' ) );
		$attribute->set_variation( true );
		$product->set_name( 'Personalised hoodie' );
		$product->set_attributes( array( $attribute ) );
		$product->save();
		( new ProductConfig( personalise: true, blank_id: self::BLANK_ID, sides: array( new Side( 'front', 'Front', 'front', 'Front', 'mini-poster', 1, null, 'Mini poster' ) ) ) )->save_to( $product );
		return $product;
	}

	/**
	 * @param WC_Product_Variable $product The variable product.
	 * @param string              $size    Its size.
	 */
	private function variation( WC_Product_Variable $product, string $size ): WC_Product_Variation {
		$variation = new WC_Product_Variation();
		$variation->set_parent_id( $product->get_id() );
		$variation->set_attributes( array( 'size' => $size ) );
		$variation->set_regular_price( '25' );
		$variation->save();
		return $variation;
	}

	private function reload( int $product_id ): WC_Product {
		wp_cache_delete( (string) $product_id, 'post_meta' );
		$product = wc_get_product( $product_id );
		$this->assertInstanceOf( WC_Product::class, $product );
		return $product;
	}

	private function user( string $role ): int {
		$id = self::factory()->user->create( array( 'role' => $role ) );
		return is_int( $id ) ? $id : 0;
	}

	private static function connection(): StoredConnection {
		return new StoredConnection(
			'site_fixture',
			home_url(),
			self::CONNECTED_AT,
			'tenant_fixture',
			'Fixture Prints',
			StoredConnection::LIVE,
			'conn_main',
			'ik_live_fixture_main',
			'ik_id',
			'WooCommerce · example.org',
			'pk_live_fixture_main',
			'pk_id',
			array( 'http://example.org' ),
			'wh_fixture_main',
			'http://example.org/wp-json/pixonaut/v1/webhook',
			'whsec_fixture_main'
		);
	}
}
