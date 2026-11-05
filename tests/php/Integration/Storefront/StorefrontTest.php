<?php
/**
 * @package Pixonaut\WooCommerce
 */

declare(strict_types=1);

namespace Pixonaut\WooCommerce\Tests\Integration\Storefront;

use Pixonaut\WooCommerce\ApprovalMode;
use Pixonaut\WooCommerce\Cart\AddToCart;
use Pixonaut\WooCommerce\Catalogue\PrintSize;
use Pixonaut\WooCommerce\Catalogue\ProductConfig;
use Pixonaut\WooCommerce\Catalogue\ProductVariant;
use Pixonaut\WooCommerce\Catalogue\Side;
use Pixonaut\WooCommerce\Connection\ConnectionStore;
use Pixonaut\WooCommerce\Connection\StoredConnection;
use Pixonaut\WooCommerce\DesignerLanguage;
use Pixonaut\WooCommerce\FormLayout;
use Pixonaut\WooCommerce\Labels;
use Pixonaut\WooCommerce\Layout;
use Pixonaut\WooCommerce\Plugin;
use Pixonaut\WooCommerce\Settings\Settings;
use Pixonaut\WooCommerce\Storefront\Accent;
use Pixonaut\WooCommerce\Storefront\AttributeOptions;
use Pixonaut\WooCommerce\Storefront\Preload;
use Pixonaut\WooCommerce\Storefront\Sdk;
use Pixonaut\WooCommerce\Storefront\Storefront;
use Pixonaut\WooCommerce\Tests\Support\BlankFixture;
use Pixonaut\WooCommerce\Tests\Support\ConnectionAnswers;
use Pixonaut\WooCommerce\Tests\Support\DesignFixture;
use Pixonaut\WooCommerce\Tests\Support\MemberKeys;
use WC_Product;
use WC_Product_Simple;
use WP_Block;
use WP_HTML_Tag_Processor;
use WP_UnitTestCase;

final class StorefrontTest extends WP_UnitTestCase {

	public const PUBLISHABLE_KEY = 'pk_live_89abcdef0123456789abcdef';

	/**
	 * What `POST /v1/connect/token` answered when the store connected.
	 */
	public const EXCHANGED = array(
		'tenantId'       => '0b1c2d3e-4f50-4a6b-8c7d-000000000003',
		'tenantName'     => 'Acme',
		'tenantSlug'     => ConnectionAnswers::TENANT_SLUG,
		'environment'    => 'live',
		'connectionId'   => 'sc_0b1c2d3e-4f50-4a6b-8c7d-000000000004',
		'integrationKey' => array(
			'id'    => '0b1c2d3e-4f50-4a6b-8c7d-000000000005',
			'key'   => 'ik_live_0123456789abcdef01234567',
			'label' => 'WooCommerce · Acme Shop',
		),
		'publishableKey' => array(
			'id'             => '0b1c2d3e-4f50-4a6b-8c7d-000000000006',
			'key'            => self::PUBLISHABLE_KEY,
			'allowedOrigins' => array( 'http://example.org' ),
		),
		'webhook'        => array(
			'id'     => '0b1c2d3e-4f50-4a6b-8c7d-000000000007',
			'url'    => 'http://example.org/wp-json/pixonaut/v1/webhook',
			'secret' => 'whsec_0123456789abcdef0123456789abcdef',
		),
		'memberKey'      => MemberKeys::ANSWER,
		'reachable'      => true,
	);

	private ConnectionStore $store;

	private Plugin $plugin;

	public function set_up(): void {
		parent::set_up();
		$plugin       = Plugin::boot( dirname( __DIR__, 4 ) . '/pixonaut-for-woocommerce.php' );
		$this->plugin = $plugin;
		// The plugin's own store: it remembers a pause another test caused until a save.
		$this->store = $plugin->connection_store();
		$plugin->storefront()->forget_page();
		self::connect( $this->store );
		wp_dequeue_script( Storefront::SCRIPT_HANDLE );
		wp_deregister_script( Storefront::SCRIPT_HANDLE );
		wp_dequeue_style( Storefront::STYLE_HANDLE );
		wp_deregister_style( Storefront::STYLE_HANDLE );
	}

	public function tear_down(): void {
		$this->store->clear();
		unset( $GLOBALS['product'] );
		parent::tear_down();
	}

	public static function connect( ConnectionStore $store ): void {
		$store->save( StoredConnection::from_exchange( self::EXCHANGED, wp_generate_uuid4(), home_url(), time() ) );
	}

	/**
	 * A simple product, personalised on the inline layout unless a config is given.
	 *
	 * @param ProductConfig|null $config Its configuration; none for a plain product.
	 */
	public static function product( ?ProductConfig $config ): WC_Product {
		$product = new WC_Product_Simple();
		$product->set_name( 'Tee' );
		$product->set_regular_price( '19.00' );
		$product->set_status( 'publish' );
		$product->save();
		$config?->save_to( $product );
		DesignFixture::sell_variant( $product );
		$reloaded = wc_get_product( $product->get_id() );
		self::assertInstanceOf( WC_Product::class, $reloaded );
		return $reloaded;
	}

	public static function personalised( ?string $layout = null ): ProductConfig {
		return new ProductConfig(
			personalise: true,
			blank_id: 'blank_tee',
			blank_name: 'Tee',
			sides: array(
				new Side( 'front', 'Front', 'front', 'Front', 'mini-tee', 2, null, 'Mini tee', array( new PrintSize( 300.0, 360.0, '30×36' ) ) ),
			),
			layout: $layout,
		);
	}

	/**
	 * A product printed on the tee's front and back, with the same template.
	 */
	public static function two_sided(): ProductConfig {
		$front = self::personalised()->sides[0];
		return new ProductConfig(
			personalise: true,
			blank_id: 'blank_tee',
			blank_name: 'Tee',
			sides: array(
				$front,
				new Side( 'back', 'Back', 'back', 'Back', $front->template_base_id, $front->template_version, null, $front->template_name, $front->print_sizes ),
			),
		);
	}

	/**
	 * A side printed from a standing design instead.
	 *
	 * @param Side $side A personalised side.
	 */
	private static function standing( Side $side ): Side {
		return new Side( $side->key, $side->label, $side->placement_id, $side->placement_name, $side->template_base_id, $side->template_version, $side->previous_version, $side->template_name, $side->print_sizes, mode: Side::STANDING, standing_design_id: 'des_standing_' . $side->key );
	}

	public function test_the_classic_gallery_becomes_the_preview_on_a_personalised_product(): void {
		$product = self::product( self::personalised() );

		$this->go_to( (string) get_permalink( $product->get_id() ) );

		$this->assertFalse( has_action( 'woocommerce_before_single_product_summary', 'woocommerce_show_product_images' ) );
		$GLOBALS['product'] = $product;
		$html               = $this->capture( static fn () => do_action( 'woocommerce_before_single_product_summary' ) );
		$this->assertStringContainsString( 'id="pixonaut-preview-front"', $html );
		$this->assertStringContainsString( 'pixonaut-preview images', $html );
		$this->assertTrue( wp_script_is( Storefront::SCRIPT_HANDLE, 'enqueued' ) );
	}

	public function test_product_page_shortcode_prints_the_preview_in_place_of_the_gallery(): void {
		add_filter( 'woocommerce_product_tabs', '__return_empty_array' );
		$product = self::product( self::personalised() );
		$page_id = wp_insert_post(
			array(
				'post_type'   => 'page',
				'post_title'  => 'Shop window',
				'post_status' => 'publish',
			)
		);

		$this->go_to( (string) get_permalink( $page_id ) );
		$html = do_shortcode( sprintf( '[product_page id="%d"]', $product->get_id() ) );

		$this->assertStringContainsString( 'id="pixonaut-preview-front"', $html );
		$this->assertStringContainsString( 'pixonaut-preview images', $html );
		$this->assertStringNotContainsString( 'woocommerce-product-gallery', $html );
	}

	public function test_product_page_shortcode_keeps_the_gallery_of_a_plain_product(): void {
		add_filter( 'woocommerce_product_tabs', '__return_empty_array' );
		$product = new WC_Product_Simple();
		$product->set_name( 'Plain' );
		$product->set_status( 'publish' );
		$product->save();
		$page_id = wp_insert_post(
			array(
				'post_type'   => 'page',
				'post_title'  => 'Shop window',
				'post_status' => 'publish',
			)
		);

		$this->go_to( (string) get_permalink( $page_id ) );
		$html = do_shortcode( sprintf( '[product_page id="%d"]', $product->get_id() ) );

		$this->assertStringContainsString( 'woocommerce-product-gallery', $html );
		$this->assertStringNotContainsString( 'pixonaut-preview', $html );
	}

	public function test_product_page_shortcode_leaves_out_a_gallery_the_theme_removed(): void {
		add_filter( 'woocommerce_product_tabs', '__return_empty_array' );
		remove_action( 'woocommerce_before_single_product_summary', 'woocommerce_show_product_images', (int) has_action( 'woocommerce_before_single_product_summary', 'woocommerce_show_product_images' ) );
		$product = new WC_Product_Simple();
		$product->set_name( 'Plain' );
		$product->set_status( 'publish' );
		$product->save();
		$page_id = wp_insert_post(
			array(
				'post_type'   => 'page',
				'post_title'  => 'Shop window',
				'post_status' => 'publish',
			)
		);

		$this->go_to( (string) get_permalink( $page_id ) );
		$html = do_shortcode( sprintf( '[product_page id="%d"]', $product->get_id() ) );

		$this->assertStringNotContainsString( 'woocommerce-product-gallery', $html );
		$this->assertStringNotContainsString( 'pixonaut-preview', $html );
	}

	public function test_the_preview_ends_with_its_note(): void {
		$product = self::product( self::personalised() );
		$this->go_to( (string) get_permalink( $product->get_id() ) );
		$GLOBALS['product'] = $product;

		$html = $this->capture( static fn () => do_action( 'woocommerce_before_single_product_summary' ) );

		$this->assertMatchesRegularExpression( '/<\/div>\s*<p class="pixonaut-preview__note">The preview updates as you type<\/p>\s*<\/div>/', $html );
	}

	public function test_the_label_filter_changes_the_preview_note(): void {
		$product = self::product( self::personalised() );
		$this->go_to( (string) get_permalink( $product->get_id() ) );
		$GLOBALS['product'] = $product;
		add_filter( 'pixonaut_wc_storefront_label', static fn ( string $text, string $key ): string => Labels::PREVIEW_NOTE === $key ? 'Live preview' : $text, 10, 2 );

		$html = $this->capture( static fn () => do_action( 'woocommerce_before_single_product_summary' ) );

		$this->assertStringContainsString( '<p class="pixonaut-preview__note">Live preview</p>', $html );
	}

	public function test_the_classic_hook_prints_nothing_while_a_block_template_renders(): void {
		$product = self::product( self::personalised() );
		$this->go_to( (string) get_permalink( $product->get_id() ) );
		$GLOBALS['product'] = $product;
		// As WooCommerce's compatibility layer fires the classic hooks around a block template's blocks.
		add_filter(
			'render_block',
			function (): string {
				return $this->capture( static fn () => do_action( 'woocommerce_before_single_product_summary' ) );
			}
		);

		$group = array(
			'blockName'    => 'core/group',
			'attrs'        => array(),
			'innerBlocks'  => array(),
			'innerHTML'    => '',
			'innerContent' => array(),
		);
		$html  = apply_filters( 'render_block', '', $group, new WP_Block( $group ) );

		$this->assertStringNotContainsString( 'pixonaut-preview', (string) $html );
	}

	public function test_a_plain_product_keeps_its_gallery_and_loads_nothing(): void {
		$product = self::product( null );

		$this->go_to( (string) get_permalink( $product->get_id() ) );
		do_action( 'wp_enqueue_scripts' );

		$this->assertSame( 20, has_action( 'woocommerce_before_single_product_summary', 'woocommerce_show_product_images' ) );
		$this->assertFalse( wp_script_is( Storefront::SCRIPT_HANDLE, 'enqueued' ) );
		$this->assertFalse( wp_style_is( Storefront::STYLE_HANDLE, 'enqueued' ) );
		$this->assertSame( '', $this->form_html( $product ) );
	}

	/**
	 * @return array<string, array{string}>
	 */
	public static function layouts(): array {
		return array(
			'inline' => array( Layout::INLINE ),
			'button' => array( Layout::BUTTON ),
		);
	}

	/**
	 * @dataProvider layouts
	 *
	 * @param string $layout The product's layout.
	 */
	public function test_a_product_of_standing_sides_alone_keeps_its_gallery_and_shows_no_designer( string $layout ): void {
		$front   = self::personalised()->sides[0];
		$product = self::product( self::personalised( $layout )->with_sides( array( self::standing( $front ) ) ) );

		$this->go_to( (string) get_permalink( $product->get_id() ) );
		do_action( 'wp_enqueue_scripts' );

		$this->assertSame( 20, has_action( 'woocommerce_before_single_product_summary', 'woocommerce_show_product_images' ) );
		$this->assertFalse( wp_script_is( Storefront::SCRIPT_HANDLE, 'enqueued' ) );
		$this->assertSame( '', $this->form_html( $product ) );
	}

	public function test_a_standing_front_leaves_the_designer_the_back_alone(): void {
		$config  = self::two_sided();
		$product = self::product( $config->with_sides( array( self::standing( $config->sides[0] ), $config->sides[1] ) ) );

		$html = $this->form_html( $product );

		$this->assertSame( array( 'back' ), array_keys( $this->mounts( $html ) ) );
		$this->assertStringNotContainsString( 'pixonaut-form-front', $html );
	}

	public function test_the_button_layout_shows_the_button_instead_of_the_inline_designer(): void {
		$product = self::product( self::personalised( Layout::BUTTON ) );

		$this->go_to( (string) get_permalink( $product->get_id() ) );
		$html = $this->button_html( $product );

		$this->assertSame( 20, has_action( 'woocommerce_before_single_product_summary', 'woocommerce_show_product_images' ) );
		$this->assertStringNotContainsString( 'pixonaut-form', $this->form_html( $product ) );
		$this->assertStringNotContainsString( 'pixonaut-form', $html );
		$this->assertStringContainsString( 'pixonaut-personalise', $html );
	}

	public function test_a_filter_sells_a_personalised_product_as_a_plain_one(): void {
		$product = self::product( self::personalised() );
		$seen    = array();
		add_filter(
			'pixonaut_wc_catalogue_is_personalisable',
			static function ( bool $personalise, WC_Product $bought ) use ( &$seen ): bool {
				$seen[] = $bought->get_id();
				return false;
			},
			10,
			2
		);

		$this->go_to( (string) get_permalink( $product->get_id() ) );
		do_action( 'wp_enqueue_scripts' );

		$this->assertContains( $product->get_id(), $seen );
		$this->assertSame( 20, has_action( 'woocommerce_before_single_product_summary', 'woocommerce_show_product_images' ) );
		$this->assertFalse( wp_script_is( Storefront::SCRIPT_HANDLE, 'enqueued' ) );
		$this->assertSame( '', $this->form_html( $product ) );
	}

	public function test_a_filter_sets_the_layout_of_a_product(): void {
		$product = self::product( self::personalised() );
		add_filter(
			'pixonaut_wc_catalogue_layout',
			static fn ( ?string $layout, WC_Product $bought ): ?string => $bought->get_id() === $product->get_id() ? Layout::BUTTON : $layout,
			10,
			2
		);

		$this->go_to( (string) get_permalink( $product->get_id() ) );
		$html = $this->button_html( $product );

		$this->assertStringNotContainsString( 'pixonaut-form', $this->form_html( $product ) );
		$this->assertStringContainsString( 'pixonaut-personalise', $html );
	}

	/**
	 * @return array<string, array{string}>
	 */
	public static function gallery_blocks(): array {
		return array(
			'product image gallery' => array( 'woocommerce/product-image-gallery' ),
			'product gallery'       => array( 'woocommerce/product-gallery' ),
		);
	}

	/**
	 * @dataProvider gallery_blocks
	 *
	 * @param string $name The gallery block.
	 */
	public function test_a_gallery_block_becomes_the_preview_of_its_context_product( string $name ): void {
		$personalised = self::product( self::personalised() );
		$plain        = self::product( null );

		$preview = apply_filters( 'render_block_' . $name, '<div>gallery</div>', array( 'blockName' => $name ), $this->block( $name, $personalised ) );
		$kept    = apply_filters( 'render_block_' . $name, '<div>gallery</div>', array( 'blockName' => $name ), $this->block( $name, $plain ) );

		$this->assertStringContainsString( 'id="pixonaut-preview-front"', (string) $preview );
		$this->assertStringContainsString( 'data-pixonaut-product="' . $personalised->get_id() . '"', (string) $preview );
		$this->assertStringNotContainsString( 'images', (string) $preview );
		$this->assertSame( '<div>gallery</div>', $kept );
	}

	public function test_only_the_first_personalised_product_on_a_page_gets_the_designer(): void {
		$first   = self::product( self::personalised() );
		$second  = self::product( self::personalised() );
		$gallery = 'woocommerce/product-gallery';

		$preview      = apply_filters( 'render_block_' . $gallery, '<div>gallery</div>', array( 'blockName' => $gallery ), $this->block( $gallery, $first ) );
		$form         = $this->form_html( $first );
		$second_form  = $this->form_html( $second );
		$second_block = apply_filters( 'render_block_' . $gallery, '<div>gallery</div>', array( 'blockName' => $gallery ), $this->block( $gallery, $second ) );
		$again        = apply_filters( 'render_block_' . $gallery, '<div>gallery</div>', array( 'blockName' => $gallery ), $this->block( $gallery, $first ) );

		$this->assertStringContainsString( 'id="pixonaut-preview-front"', (string) $preview );
		$this->assertStringContainsString( 'id="pixonaut-form-front"', $form );
		$this->assertStringNotContainsString( 'data-pixonaut-mounts', $second_form );
		$this->assertStringNotContainsString( 'pixonaut-form-front', $second_form );
		$this->assertMatchesRegularExpression( '/class="pixonaut-form__unavailable" role="alert">Personalisation is unavailable/', $second_form );
		$this->assertStringContainsString( 'name="pixonaut_designs[front]"', $second_form );
		$this->assertSame( '<div>gallery</div>', $second_block );
		$this->assertSame( '<div>gallery</div>', $again );
	}

	public function test_the_form_carries_the_mount_options_and_the_designs_field(): void {
		$product = self::product( self::personalised() );

		$html = $this->form_html( $product );

		$this->assertStringContainsString( 'id="pixonaut-form-front"', $html );
		$this->assertStringContainsString( 'name="pixonaut_designs[front]"', $html );
		$this->assertSame(
			array(
				'front' => array(
					'publishableKey'         => self::PUBLISHABLE_KEY,
					'apiBase'                => \Pixonaut\WooCommerce\Api\Hosts::api(),
					'templateId'             => 'mini-tee@2',
					'selectedPrintSizeIndex' => 0,
					'locale'                 => 'en',
					'preload'                => Preload::AUTO,
					'showPrintAreaFrame'     => false,
					'blankId'                => 'blank_tee',
					'placementId'            => 'front',
					'placements'             => array( array( 'placementId' => 'front' ) ),
					'views'                  => array(),
					'variants'               => array( BlankFixture::VARIANT_REF ),
					'printSizes'             => array(
						array(
							'widthMm'  => 300,
							'heightMm' => 360,
							'label'    => '30×36',
						),
					),
				),
			),
			$this->mounts( $html )
		);
	}

	public function test_the_form_names_the_blank_variant_the_designer_shows_the_product_in(): void {
		$product = self::product( self::personalised()->with_views( array( 'view_flat' ) ) );

		$html = $this->form_html( $product );

		$this->assertStringContainsString( 'data-pixonaut-variant="' . BlankFixture::VARIANT_REF . '"', $html );
		$this->assertSame( array( 'view_flat' ), $this->mounts( $html )['front']['views'] );
		// The designer offers the product's own variant alone.
		$this->assertSame( array( BlankFixture::VARIANT_REF ), $this->mounts( $html )['front']['variants'] );
	}

	public function test_a_side_mounts_on_its_starting_size_and_the_products_starting_view(): void {
		$config  = self::personalised();
		$sizes   = array( new PrintSize( 300.0, 360.0, '30×36' ), new PrintSize( 210.0, 270.0, 'Small' ) );
		$product = self::product( $config->with_sides( array( $config->sides[0]->with( 2, null, $sizes, null, 1 ) ) )->with_views( array( 'view_flat' ), 'view_flat' ) );

		$mount = $this->mounts( $this->form_html( $product ) )['front'];

		$this->assertSame( 1, $mount['selectedPrintSizeIndex'] );
		$this->assertSame( 'view_flat', $mount['initialView'] );
	}

	/**
	 * The product's and its side's canvas ratio choices, and the option the side mounts with.
	 *
	 * @return array<string, array{0: bool|null, 1: bool|null, 2: bool|null}>
	 */
	public static function canvas_ratios(): array {
		return array(
			'following the template'  => array( null, null, null ),
			'the template’s ratio'    => array( true, null, true ),
			'the chosen size’s ratio' => array( false, null, false ),
			'the side’s own choice'   => array( true, false, false ),
		);
	}

	/**
	 * @dataProvider canvas_ratios
	 *
	 * @param bool|null $product  The product's choice.
	 * @param bool|null $side     The side's choice.
	 * @param bool|null $expected The `drawAtTemplateRatio` mounted; null when it is left out.
	 */
	public function test_a_side_mounts_with_the_canvas_its_product_or_it_chooses( ?bool $product, ?bool $side, ?bool $expected ): void {
		$config = self::personalised();
		$config = $config->with_draw_at_template_ratio( $product )->with_sides( array( $config->sides[0]->with_draw_at_template_ratio( $side ) ) );

		$mount = $this->mounts( $this->form_html( self::product( $config ) ) )['front'];

		if ( null === $expected ) {
			$this->assertArrayNotHasKey( 'drawAtTemplateRatio', $mount, 'following the template leaves the option out' );
			return;
		}
		$this->assertSame( $expected, $mount['drawAtTemplateRatio'] );
	}

	public function test_a_product_saved_before_mounts_on_its_first_size_and_view(): void {
		$mount = $this->mounts( $this->form_html( self::product( self::personalised() ) ) )['front'];

		$this->assertSame( 0, $mount['selectedPrintSizeIndex'] );
		$this->assertArrayNotHasKey( 'initialView', $mount );
	}

	public function test_a_product_selling_no_variant_names_none(): void {
		$product = self::product( self::personalised() );
		ProductVariant::delete_from( $product );

		$this->assertStringNotContainsString( 'data-pixonaut-variant', $this->form_html( $product ) );
	}

	public function test_a_product_with_one_side_has_no_side_tabs(): void {
		$product = self::product( self::personalised() );

		$this->assertStringNotContainsString( 'role="tablist"', $this->form_html( $product ) );
	}

	public function test_a_front_and_a_back_get_a_tab_each_showing_the_front_first(): void {
		$product = self::product( self::two_sided()->with_approval_mode( ApprovalMode::CHECKBOX ) );

		$html = $this->form_html( $product );

		$tags = new WP_HTML_Tag_Processor( $html );
		$this->assertTrue( $tags->next_tag( array( 'class_name' => 'pixonaut-tabs' ) ) );
		$this->assertSame( array( 'tablist', 'Sides' ), array( $tags->get_attribute( 'role' ), $tags->get_attribute( 'aria-label' ) ) );
		$tabs = array();
		while ( $tags->next_tag( array( 'class_name' => 'pixonaut-tabs__tab' ) ) ) {
			$tabs[] = array( $tags->get_attribute( 'data-pixonaut-side' ), $tags->get_attribute( 'aria-controls' ), $tags->get_attribute( 'aria-selected' ), $tags->get_attribute( 'tabindex' ) );
		}
		$this->assertSame(
			array(
				array( 'front', 'pixonaut-form-front', 'true', '0' ),
				array( 'back', 'pixonaut-form-back', 'false', '-1' ),
			),
			$tabs
		);
		$this->assertMatchesRegularExpression( '/id="pixonaut-tab-front"[^>]*>Front<\/button>\s*<button[^>]*id="pixonaut-tab-back"[^>]*>Back<\/button>/', $html );
		$this->assertSame( array( 'front', 'back' ), array_keys( $this->mounts( $html ) ) );
		$front_first = array(
			'front' => false,
			'back'  => true,
		);
		$this->assertSame( $front_first, self::hidden_by_side( $html, 'pixonaut-form__side' ) );
		$this->assertSame( $front_first, self::hidden_by_side( $html, 'pixonaut-approval__proof' ) );
		$tags = new WP_HTML_Tag_Processor( $html );
		$tags->next_tag( array( 'class_name' => 'pixonaut-form__side' ) );
		$this->assertSame( array( 'tabpanel', 'pixonaut-tab-front' ), array( $tags->get_attribute( 'role' ), $tags->get_attribute( 'aria-labelledby' ) ) );
	}

	public function test_the_preview_of_a_front_and_a_back_shows_the_front_first(): void {
		$product = self::product( self::two_sided() );
		$this->go_to( (string) get_permalink( $product->get_id() ) );
		$GLOBALS['product'] = $product;

		$html = $this->capture( static fn () => do_action( 'woocommerce_before_single_product_summary' ) );

		$this->assertSame(
			array(
				'front' => false,
				'back'  => true,
			),
			self::hidden_by_side( $html, 'pixonaut-preview__frame' )
		);
	}

	public function test_the_checkbox_mode_adds_the_approval_box_whose_label_links_the_proof(): void {
		$product = self::product( self::personalised()->with_approval_mode( ApprovalMode::CHECKBOX ) );

		$html = $this->form_html( $product );

		$this->assertMatchesRegularExpression( '/<input type="checkbox" class="pixonaut-approval__box" name="' . AddToCart::APPROVAL_FIELD . '" value="yes" required \/>\s*<span>\s*I approve the <button type="button" class="pixonaut-approval__proof pixonaut-approval__proof--link" data-pixonaut-side="front">preview<\/button>\s*<\/span>\s*<\/label>/', $html );
		$this->assertStringNotContainsString( 'Open proof', $html );
		$this->assertMatchesRegularExpression( '/<p class="pixonaut-approval__error" role="alert" hidden><\/p>\s*<\/div>/', $html );
	}

	public function test_an_approval_label_linking_no_words_keeps_a_proof_button_beside_the_box(): void {
		$product = self::product( self::personalised()->with_approval_mode( ApprovalMode::CHECKBOX ) );
		add_filter( 'pixonaut_wc_storefront_label', static fn ( string $text, string $key ): string => Labels::APPROVE_PREVIEW === $key ? 'I like it' : $text, 10, 2 );

		$html = $this->form_html( $product );

		$this->assertMatchesRegularExpression( '/required \/>\s*I like it\s*<\/label>\s*<button type="button" class="pixonaut-approval__proof" data-pixonaut-side="front">Open proof<\/button>/', $html );
	}

	public function test_a_filter_sets_the_approval_mode_of_a_product(): void {
		$product = self::product( self::personalised() );
		add_filter( 'pixonaut_wc_catalogue_approval_mode', static fn (): string => ApprovalMode::CHECKBOX );

		$this->assertStringContainsString( 'pixonaut-approval__box', $this->form_html( $product ) );
	}

	public function test_the_add_to_cart_mode_shows_no_approval_box(): void {
		$product = self::product( self::personalised() );

		$this->assertStringNotContainsString( 'pixonaut-approval', $this->form_html( $product ) );
	}

	public function test_an_unavailable_designer_shows_no_approval_box(): void {
		$this->store->clear();
		$product = self::product( self::personalised()->with_approval_mode( ApprovalMode::CHECKBOX ) );

		$this->assertStringNotContainsString( 'pixonaut-approval', $this->form_html( $product ) );
	}

	public function test_the_designer_speaks_the_language_the_store_chose(): void {
		$this->plugin->settings()->set( Settings::DESIGNER_LANGUAGE, DesignerLanguage::SWEDISH );

		$mounts = $this->mounts( $this->form_html( self::product( self::personalised() ) ) );

		$this->assertSame( 'sv', $mounts['front']['locale'] );
	}

	public function test_each_visitor_sees_the_designer_in_the_language_they_browse_the_site_in(): void {
		$visitor = static fn (): string => 'de_CH';
		add_filter( 'determine_locale', $visitor );
		$german  = $this->plugin->inline_products()->for_product( self::product( self::personalised() ) )?->mounts;
		remove_filter( 'determine_locale', $visitor );
		$visitor = static fn (): string => 'pl_PL';
		add_filter( 'determine_locale', $visitor );
		$polish  = $this->plugin->inline_products()->for_product( self::product( self::personalised() ) )?->mounts;
		remove_filter( 'determine_locale', $visitor );

		$this->assertSame( 'de', $german['front']['locale'] ?? null );
		$this->assertIsArray( $polish );
		$this->assertArrayNotHasKey( 'locale', $polish['front'], 'Pixonaut uses the workspace default for a language it lacks' );
	}

	public function test_the_workspace_default_sends_the_designer_no_language(): void {
		$this->plugin->settings()->set( Settings::DESIGNER_LANGUAGE, DesignerLanguage::WORKSPACE_DEFAULT );

		$mounts = $this->mounts( $this->form_html( self::product( self::personalised() ) ) );

		$this->assertArrayNotHasKey( 'locale', $mounts['front'] );
	}

	public function test_by_default_the_template_chooses_the_form_layout(): void {
		$mounts = $this->mounts( $this->form_html( self::product( self::personalised() ) ) );

		$this->assertArrayNotHasKey( 'layout', $mounts['front'] );
	}

	public function test_the_stores_form_layout_applies_to_a_product_without_its_own(): void {
		$this->plugin->settings()->set( Settings::FORM_LAYOUT, FormLayout::STEPS );

		$mounts = $this->mounts( $this->form_html( self::product( self::personalised() ) ) );

		$this->assertSame( FormLayout::STEPS, $mounts['front']['layout'] );
	}

	public function test_a_products_form_layout_outranks_the_stores(): void {
		$this->plugin->settings()->set( Settings::FORM_LAYOUT, FormLayout::STEPS );

		$mounts = $this->mounts( $this->form_html( self::product( self::personalised()->with_form_layout( FormLayout::PAGE ) ) ) );

		$this->assertSame( FormLayout::PAGE, $mounts['front']['layout'] );
	}

	public function test_the_designer_preloads_as_the_store_setting_says(): void {
		$this->plugin->settings()->set( Settings::DESIGNER_PRELOAD, Preload::OFF );

		$mounts = $this->mounts( $this->form_html( self::product( self::personalised() ) ) );

		$this->assertSame( Preload::OFF, $mounts['front']['preload'] );
	}

	public function test_the_designer_frames_the_print_area_and_takes_a_custom_accent_as_the_store_settings_say(): void {
		$this->plugin->settings()->set( Settings::PRINT_AREA_FRAME, true );
		$this->plugin->settings()->set( Settings::DESIGNER_ACCENT, Accent::CUSTOM );
		$this->plugin->settings()->set( Settings::DESIGNER_ACCENT_COLOUR, '#1E73BE' );

		$mounts = $this->mounts( $this->form_html( self::product( self::personalised() ) ) );

		$this->assertTrue( $mounts['front']['showPrintAreaFrame'] );
		$this->assertSame( '#1e73be', $mounts['front']['accent'] );
	}

	public function test_an_accent_following_the_theme_is_left_to_the_script(): void {
		$this->plugin->settings()->set( Settings::DESIGNER_ACCENT, Accent::THEME );
		$this->plugin->settings()->set( Settings::DESIGNER_ACCENT_COLOUR, '#1e73be' );

		$mounts = $this->mounts( $this->form_html( self::product( self::personalised() ) ) );

		$this->assertArrayNotHasKey( 'accent', $mounts['front'] );
	}

	public function test_a_theme_filter_changes_the_mount_options(): void {
		add_filter(
			'pixonaut_wc_storefront_mount_options',
			static function ( array $options, WC_Product $product, string $side ): array {
				$options['locale']  = 'sv-SE';
				$options['preload'] = 'eager';
				$options['side']    = $side;
				return $options;
			},
			10,
			3
		);

		$mounts = $this->mounts( $this->form_html( self::product( self::personalised() ) ) );

		$this->assertSame( 'sv-SE', $mounts['front']['locale'] );
		$this->assertSame( 'eager', $mounts['front']['preload'] );
		$this->assertSame( 'front', $mounts['front']['side'] );
	}

	public function test_a_store_that_is_not_connected_says_personalisation_is_unavailable(): void {
		$this->store->clear();
		$product = self::product( self::personalised() );

		$html = $this->form_html( $product );

		$this->assertStringNotContainsString( 'data-pixonaut-mounts', $html );
		$this->assertStringNotContainsString( 'pixonaut-form-front', $html );
		$this->assertMatchesRegularExpression( '/class="pixonaut-form__unavailable" role="alert">Personalisation is unavailable/', $html );
		$this->assertStringContainsString( 'name="pixonaut_designs[front]"', $html );
	}

	public function test_an_invalid_product_keeps_its_gallery_and_says_personalisation_is_unavailable(): void {
		$product = self::product( self::personalised()->with_validity( false, 'unknown_placement' ) );

		$this->go_to( (string) get_permalink( $product->get_id() ) );

		$this->assertSame( 20, has_action( 'woocommerce_before_single_product_summary', 'woocommerce_show_product_images' ) );
		$this->assertStringNotContainsString( 'data-pixonaut-mounts', $this->form_html( $product ) );
	}

	public function test_the_script_gets_the_pinned_sdk_and_the_labels(): void {
		$this->form_html( self::product( self::personalised() ) );

		$before = wp_scripts()->get_inline_script_data( Storefront::SCRIPT_HANDLE, 'before' );

		$this->assertStringContainsString( 'window.' . Storefront::SCRIPT_GLOBAL . ' = ', $before );
		$this->assertStringContainsString( (string) wp_json_encode( Sdk::SCRIPT_URL ), $before );
		$this->assertStringContainsString( (string) wp_json_encode( Sdk::INTEGRITY ), $before );
		$this->assertStringContainsString( 'personalisation_unavailable', $before );
		$this->assertStringContainsString( 'storefront\/unavailable', $before );
	}

	public function test_a_staging_cdn_loads_the_pinned_sdk_from_there_with_its_integrity(): void {
		$this->plugin->settings()->set( Settings::CDN_BASE, 'https://cdn.staging.example.test' );
		$this->form_html( self::product( self::personalised() ) );

		$before = wp_scripts()->get_inline_script_data( Storefront::SCRIPT_HANDLE, 'before' );

		$this->assertStringContainsString( (string) wp_json_encode( 'https://cdn.staging.example.test/sdk/' . Sdk::VERSION . '/pixonaut.iife.js' ), $before );
		$this->assertStringContainsString( (string) wp_json_encode( Sdk::INTEGRITY ), $before );
	}

	public function test_a_custom_sdk_script_loads_without_the_pinned_integrity(): void {
		$this->plugin->settings()->set( Settings::SDK_URL, 'http://localhost:5173/pixonaut.iife.js' );
		$this->form_html( self::product( self::personalised() ) );

		$before = wp_scripts()->get_inline_script_data( Storefront::SCRIPT_HANDLE, 'before' );

		$this->assertStringContainsString( (string) wp_json_encode( 'http://localhost:5173/pixonaut.iife.js' ), $before );
		$this->assertStringNotContainsString( (string) wp_json_encode( Sdk::SCRIPT_URL ), $before );
		$this->assertStringNotContainsString( (string) wp_json_encode( Sdk::INTEGRITY ), $before );
		$this->assertStringContainsString( '"integrity":""', $before );
	}

	public function test_sdk_debug_reaches_only_a_store_manager(): void {
		$this->plugin->settings()->set( Settings::SDK_DEBUG, true );
		$this->plugin->storefront()->enqueue();
		$this->assertStringContainsString( '"debug":false', wp_scripts()->get_inline_script_data( Storefront::SCRIPT_HANDLE, 'before' ) );

		wp_dequeue_script( Storefront::SCRIPT_HANDLE );
		wp_deregister_script( Storefront::SCRIPT_HANDLE );
		$manager = self::factory()->user->create( array( 'role' => 'shop_manager' ) );
		wp_set_current_user( is_int( $manager ) ? $manager : 0 );
		$this->plugin->storefront()->enqueue();
		$this->assertStringContainsString( '"debug":true', wp_scripts()->get_inline_script_data( Storefront::SCRIPT_HANDLE, 'before' ) );
	}

	public function test_the_script_takes_the_accent_from_the_theme_only_when_the_store_follows_it(): void {
		$this->plugin->storefront()->enqueue();
		$this->assertStringNotContainsString( '"themeAccent"', wp_scripts()->get_inline_script_data( Storefront::SCRIPT_HANDLE, 'before' ) );

		wp_dequeue_script( Storefront::SCRIPT_HANDLE );
		wp_deregister_script( Storefront::SCRIPT_HANDLE );
		$this->plugin->settings()->set( Settings::DESIGNER_ACCENT, Accent::THEME );
		$this->plugin->storefront()->enqueue();
		$this->assertStringContainsString( '"themeAccent":true', wp_scripts()->get_inline_script_data( Storefront::SCRIPT_HANDLE, 'before' ) );
	}

	public function test_the_script_draws_attribute_swatches_only_when_the_store_chose_them(): void {
		$this->plugin->storefront()->enqueue();
		$this->assertStringNotContainsString( '"attributeSwatches"', wp_scripts()->get_inline_script_data( Storefront::SCRIPT_HANDLE, 'before' ) );

		wp_dequeue_script( Storefront::SCRIPT_HANDLE );
		wp_deregister_script( Storefront::SCRIPT_HANDLE );
		$this->plugin->settings()->set( Settings::ATTRIBUTE_OPTIONS, AttributeOptions::SWATCHES );
		$this->plugin->storefront()->enqueue();
		$this->assertStringContainsString( '"attributeSwatches":true', wp_scripts()->get_inline_script_data( Storefront::SCRIPT_HANDLE, 'before' ) );
	}

	/**
	 * @param callable(): void $render Prints something.
	 */
	private function capture( callable $render ): string {
		ob_start();
		$render();
		return (string) ob_get_clean();
	}

	/**
	 * Whether each element of `$class` is hidden, by its side.
	 *
	 * @param string $html  Markup.
	 * @param string $css_class A class each side's part carries.
	 *
	 * @return array<string, bool>
	 */
	private static function hidden_by_side( string $html, string $css_class ): array {
		$tags   = new WP_HTML_Tag_Processor( $html );
		$hidden = array();
		while ( $tags->next_tag( array( 'class_name' => $css_class ) ) ) {
			$hidden[ (string) $tags->get_attribute( 'data-pixonaut-side' ) ] = null !== $tags->get_attribute( 'hidden' );
		}
		return $hidden;
	}

	/**
	 * What the add-to-cart form prints just before WooCommerce's button.
	 *
	 * @param WC_Product $product The product.
	 */
	private function button_html( WC_Product $product ): string {
		$GLOBALS['product'] = $product;
		return $this->capture( static fn () => do_action( 'woocommerce_after_add_to_cart_quantity' ) );
	}

	private function form_html( WC_Product $product ): string {
		$GLOBALS['product'] = $product;
		return $this->capture( static fn () => do_action( 'woocommerce_before_add_to_cart_button' ) );
	}

	/**
	 * @param string $html The form's markup.
	 * @return array<string, array<string, mixed>>
	 */
	private function mounts( string $html ): array {
		$this->assertSame( 1, preg_match( '/data-pixonaut-mounts="([^"]*)"/', $html, $match ) );
		$mounts = json_decode( html_entity_decode( $match[1] ?? '', ENT_QUOTES ), true );
		$this->assertIsArray( $mounts );
		return $mounts;
	}

	private function block( string $name, WC_Product $product ): WP_Block {
		return new WP_Block(
			array(
				'blockName'    => $name,
				'attrs'        => array(),
				'innerBlocks'  => array(),
				'innerHTML'    => '',
				'innerContent' => array(),
			),
			array( 'postId' => $product->get_id() )
		);
	}
}
