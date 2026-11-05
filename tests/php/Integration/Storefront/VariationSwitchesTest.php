<?php
/**
 * @package Pixonaut\WooCommerce
 */

declare(strict_types=1);

namespace Pixonaut\WooCommerce\Tests\Integration\Storefront;

use Pixonaut\WooCommerce\ApprovalMode;
use Pixonaut\WooCommerce\Catalogue\PrintSize;
use Pixonaut\WooCommerce\Catalogue\ProductConfig;
use Pixonaut\WooCommerce\Catalogue\ProductVariant;
use Pixonaut\WooCommerce\Catalogue\Side;
use Pixonaut\WooCommerce\Connection\ConnectionStore;
use Pixonaut\WooCommerce\FormLayout;
use Pixonaut\WooCommerce\Layout;
use Pixonaut\WooCommerce\Plugin;
use Pixonaut\WooCommerce\Storefront\Storefront;
use Pixonaut\WooCommerce\Storefront\VariationSwitches;
use Pixonaut\WooCommerce\Tests\Support\BlankFixture;
use Pixonaut\WooCommerce\Tests\Support\DesignFixture;
use WC_Product;
use WC_Product_Attribute;
use WC_Product_Variable;
use WC_Product_Variation;
use WP_UnitTestCase;

/**
 * The inline designer of a variable product mounts each side once, with the
 * print sizes of every variation, and tells the storefront script what each
 * variation switches it to.
 */
final class VariationSwitchesTest extends WP_UnitTestCase {

	/**
	 * The product's own size, first on every menu.
	 */
	private const PRODUCT_SIZE = array(
		'widthMm'  => 300,
		'heightMm' => 360,
		'label'    => '30×36',
	);

	private const SMALL_SIZE = array(
		'widthMm'  => 210,
		'heightMm' => 270,
		'label'    => 'Small',
	);

	private const POSTER_SIZE = array(
		'widthMm'  => 297,
		'heightMm' => 210,
		'label'    => 'A4 landscape',
	);

	private ConnectionStore $store;

	public function set_up(): void {
		parent::set_up();
		$plugin      = Plugin::boot( dirname( __DIR__, 4 ) . '/pixonaut-for-woocommerce.php' );
		$this->store = $plugin->connection_store();
		$plugin->storefront()->forget_page();
		StorefrontTest::connect( $this->store );
		wp_dequeue_script( Storefront::SCRIPT_HANDLE );
		wp_deregister_script( Storefront::SCRIPT_HANDLE );
	}

	public function tear_down(): void {
		$this->store->clear();
		unset( $GLOBALS['product'] );
		parent::tear_down();
	}

	/**
	 * A published variable product with a variation per key of `$variations`,
	 * each storing the configuration given for it, if any.
	 *
	 * @param ProductConfig|null                $config     The product's configuration.
	 * @param array<string, ProductConfig|null> $variations Variation configurations by `size` value.
	 * @return array{0: WC_Product, 1: array<string, WC_Product_Variation>}
	 */
	public static function variable( ?ProductConfig $config, array $variations ): array {
		$product   = new WC_Product_Variable();
		$attribute = new WC_Product_Attribute();
		$attribute->set_name( 'size' );
		$attribute->set_options( array_map( 'strval', array_keys( $variations ) ) );
		$attribute->set_variation( true );
		$product->set_name( 'Hoodie' );
		$product->set_status( 'publish' );
		$product->set_attributes( array( $attribute ) );
		$product->save();
		$config?->save_to( $product );
		DesignFixture::sell_variant( $product );
		$saved = array();
		foreach ( $variations as $size => $own ) {
			$variation = new WC_Product_Variation();
			$variation->set_parent_id( $product->get_id() );
			$variation->set_attributes( array( 'size' => (string) $size ) );
			$variation->set_regular_price( '30' );
			$variation->save();
			$own?->with_override( true )->save_to( $variation );
			DesignFixture::sell_variant( $variation );
			$saved[ (string) $size ] = $variation;
		}
		WC_Product_Variable::sync( $product->get_id() );
		$reloaded = wc_get_product( $product->get_id() );
		self::assertInstanceOf( WC_Product::class, $reloaded );
		return array( $reloaded, $saved );
	}

	/**
	 * The tee's front with another template, blank, size or approval mode.
	 *
	 * @param array<string, mixed> $changes `template`, `version`, `blank`, `size` (a `PrintSize`) or `approval`.
	 */
	public static function front( array $changes = array() ): ProductConfig {
		$size = $changes['size'] ?? new PrintSize( 300.0, 360.0, '30×36' );
		return new ProductConfig(
			personalise: true,
			blank_id: $changes['blank'] ?? 'blank_tee',
			blank_name: 'Tee',
			sides: array(
				new Side( 'front', 'Front', 'front', 'Front', $changes['template'] ?? 'mini-tee', $changes['version'] ?? 2, null, 'Mini tee', array( $size ) ),
			),
			approval_mode: $changes['approval'] ?? null,
		);
	}

	public function test_a_side_mounts_once_with_the_sizes_of_every_variation(): void {
		[ $product ] = self::variable(
			self::front(),
			array(
				'M' => null,
				'S' => self::front( array( 'size' => new PrintSize( 210.0, 270.0, 'Small' ) ) ),
				'P' => self::front(
					array(
						'template' => 'mini-poster',
						'version'  => 1,
						'size'     => new PrintSize( 297.0, 210.0, 'A4 landscape' ),
					)
				),
			)
		);

		$mounts = self::data( $this->form_html( $product ), 'mounts' );

		$this->assertSame( array( 'front' ), array_keys( $mounts ) );
		$this->assertSame( 'mini-tee@2', $mounts['front']['templateId'] );
		$this->assertSame( 0, $mounts['front']['selectedPrintSizeIndex'] );
		$this->assertSame( array( self::PRODUCT_SIZE, self::SMALL_SIZE, self::POSTER_SIZE ), $mounts['front']['printSizes'] );
		$this->assertSame( array( BlankFixture::VARIANT_REF ), $mounts['front']['variants'] );
	}

	public function test_a_side_whose_variations_each_fix_a_size_draws_no_size_menu(): void {
		[ $product ] = self::variable(
			self::front(),
			array(
				'M' => null,
				'S' => self::front( array( 'size' => new PrintSize( 210.0, 270.0, 'Small' ) ) ),
			)
		);

		$mounts = self::data( $this->form_html( $product ), 'mounts' );

		$this->assertSame( array( 'size' => false ), $mounts['front']['showPickers'] ?? null );
	}

	public function test_a_side_a_variation_offers_several_sizes_on_keeps_its_size_menu(): void {
		$menu        = new ProductConfig(
			personalise: true,
			blank_id: 'blank_tee',
			blank_name: 'Tee',
			sides: array(
				new Side( 'front', 'Front', 'front', 'Front', 'mini-tee', 2, null, 'Mini tee', array( new PrintSize( 300.0, 360.0, '30×36' ), new PrintSize( 210.0, 270.0, 'Small' ) ) ),
			),
		);
		[ $product ] = self::variable(
			$menu,
			array(
				'M' => null,
				'S' => self::front( array( 'size' => new PrintSize( 210.0, 270.0, 'Small' ) ) ),
			)
		);
		$simple      = StorefrontTest::product( $menu );

		$this->assertArrayNotHasKey( 'showPickers', self::data( $this->form_html( $product ), 'mounts' )['front'] );
		Plugin::boot( dirname( __DIR__, 4 ) . '/pixonaut-for-woocommerce.php' )->storefront()->forget_page();
		$this->assertArrayNotHasKey( 'showPickers', self::data( $this->form_html( $simple ), 'mounts' )['front'], 'a simple product with several sizes' );
	}

	public function test_each_variation_says_what_it_switches_each_side_to(): void {
		[ $product, $variations ] = self::variable(
			self::front(),
			array(
				'M'  => null,
				'S'  => self::front( array( 'size' => new PrintSize( 210.0, 270.0, 'Small' ) ) ),
				'XL' => self::front( array( 'blank' => 'blank_hoodie' ) ),
				'P'  => self::front(
					array(
						'template' => 'mini-poster',
						'version'  => 1,
						'size'     => new PrintSize( 297.0, 210.0, 'A4 landscape' ),
					)
				),
			)
		);

		$switches = self::data( $this->form_html( $product ), 'variations' );

		$side     = static fn ( array $changes ): array => array(
			'front' => $changes + array(
				'templateId'  => 'mini-tee@2',
				'printSizes'  => array( 0 ),
				'blankId'     => 'blank_tee',
				'placementId' => 'front',
				'placements'  => array( array( 'placementId' => 'front' ) ),
				'views'       => array(),
				'variants'    => array( BlankFixture::VARIANT_REF ),
			),
		);
		$expected = array(
			'M'  => $side( array() ),
			'S'  => $side( array( 'printSizes' => array( 1 ) ) ),
			'XL' => $side( array( 'blankId' => 'blank_hoodie' ) ),
			'P'  => $side(
				array(
					'templateId' => 'mini-poster@1',
					'printSizes' => array( 2 ),
				)
			),
		);
		foreach ( $expected as $size => $sides ) {
			$switch = $switches[ $variations[ $size ]->get_id() ] ?? null;
			$this->assertIsArray( $switch, "variation $size" );
			$this->assertSame( VariationSwitches::PERSONALISED, $switch['state'] );
			$this->assertSame( array( 'attribute_size' => $size ), $switch['attributes'] );
			$this->assertSame( ApprovalMode::ADD_TO_CART, $switch['approvalMode'] );
			$this->assertSame( BlankFixture::VARIANT_REF, $switch['variant'], "variation $size" );
			foreach ( $sides['front'] as $key => $value ) {
				$this->assertSame( $value, $switch['sides']['front'][ $key ], "variation $size, $key" );
			}
		}
	}

	public function test_each_variation_names_the_size_and_view_its_side_starts_on(): void {
		$product_size             = new PrintSize( 300.0, 360.0, '30×36' );
		$small                    = new PrintSize( 210.0, 270.0, 'Small' );
		$poster                   = new PrintSize( 297.0, 210.0, 'A4 landscape' );
		$starting                 = static fn ( ProductConfig $config, array $sizes, int $start, ?string $view ): ProductConfig => $config
			->with_sides( array( $config->sides[0]->with( 2, null, array_values( $sizes ), null, $start ) ) )
			->with_views( array(), $view );
		[ $product, $variations ] = self::variable(
			$starting( self::front(), array( $product_size, $small ), 1, 'view_flat' ),
			array(
				'M' => null,
				'S' => $starting( self::front(), array( $small, $poster ), 1, 'view_model' ),
			)
		);

		$html     = $this->form_html( $product );
		$mounts   = self::data( $html, 'mounts' );
		$switches = self::data( $html, 'variations' );

		$this->assertSame( array( self::PRODUCT_SIZE, self::SMALL_SIZE, self::POSTER_SIZE ), $mounts['front']['printSizes'] );
		$this->assertSame( 1, $mounts['front']['selectedPrintSizeIndex'] );
		$this->assertSame( 'view_flat', $mounts['front']['initialView'] );
		$this->assertSame( 1, $switches[ $variations['M']->get_id() ]['sides']['front']['selectedPrintSizeIndex'] );
		$this->assertSame( 'view_flat', $switches[ $variations['M']->get_id() ]['sides']['front']['initialView'] );
		$this->assertSame( 2, $switches[ $variations['S']->get_id() ]['sides']['front']['selectedPrintSizeIndex'] );
		$this->assertSame( 'view_model', $switches[ $variations['S']->get_id() ]['sides']['front']['initialView'] );
	}

	public function test_each_side_offers_the_variants_the_variations_on_its_blank_sell(): void {
		[ $product, $variations ] = self::variable(
			self::front(),
			array(
				'M'  => null,
				'D'  => null,
				'R'  => null,
				'XL' => self::front( array( 'blank' => 'blank_hoodie' ) ),
			)
		);
		( new ProductVariant( DesignFixture::BLANK_ID, 'v_tee_black', 'v_tee_black', 'Colour: Black', null, BlankFixture::VARIANT_VIEWS ) )->save_to( $variations['D'] );
		( new ProductVariant( DesignFixture::BLANK_ID, null, 'v_tee_red', 'Colour: Red', 'no_longer_sold' ) )->save_to( $variations['R'] );

		$html     = $this->form_html( $product );
		$mounts   = self::data( $html, 'mounts' );
		$switches = self::data( $html, 'variations' );

		// A retired variant is not offered, and the hoodie's is its own blank's.
		$tee = array( BlankFixture::VARIANT_REF, 'v_tee_black' );
		$this->assertSame( $tee, $mounts['front']['variants'] );
		$this->assertSame( $tee, $switches[ $variations['D']->get_id() ]['sides']['front']['variants'] );
		$this->assertSame( 'v_tee_black', $switches[ $variations['D']->get_id() ]['variant'] );
		$this->assertSame( array( BlankFixture::VARIANT_REF ), $switches[ $variations['XL']->get_id() ]['sides']['front']['variants'] );
	}

	public function test_a_variation_switches_a_side_to_its_own_form_layout(): void {
		[ $product, $variations ] = self::variable(
			self::front()->with_form_layout( FormLayout::PAGE ),
			array(
				'M' => null,
				'S' => self::front()->with_form_layout( FormLayout::STEPS ),
				'T' => self::front()->with_form_layout( FormLayout::TEMPLATE ),
			)
		);

		$html     = $this->form_html( $product );
		$switches = self::data( $html, 'variations' );

		$this->assertSame( FormLayout::PAGE, self::data( $html, 'mounts' )['front']['layout'] );
		$this->assertSame( FormLayout::PAGE, $switches[ $variations['M']->get_id() ]['sides']['front']['layout'] );
		$this->assertSame( FormLayout::STEPS, $switches[ $variations['S']->get_id() ]['sides']['front']['layout'] );
		$this->assertArrayNotHasKey( 'layout', $switches[ $variations['T']->get_id() ]['sides']['front'], 'the template chooses' );
	}

	public function test_a_variation_switches_a_side_to_its_own_canvas(): void {
		[ $product, $variations ] = self::variable(
			self::front()->with_draw_at_template_ratio( true ),
			array(
				'M' => null,
				'S' => self::front()->with_draw_at_template_ratio( false ),
				'T' => self::front(),
			)
		);

		$html     = $this->form_html( $product );
		$switches = self::data( $html, 'variations' );

		$this->assertTrue( self::data( $html, 'mounts' )['front']['drawAtTemplateRatio'] );
		$this->assertTrue( $switches[ $variations['M']->get_id() ]['sides']['front']['drawAtTemplateRatio'] );
		$this->assertFalse( $switches[ $variations['S']->get_id() ]['sides']['front']['drawAtTemplateRatio'] );
		$this->assertTrue( $switches[ $variations['T']->get_id() ]['sides']['front']['drawAtTemplateRatio'], 'a variation choosing nothing takes the product’s choice' );
	}

	public function test_a_variation_without_a_design_is_plain_and_one_that_cannot_mount_unavailable(): void {
		[ $product, $variations ] = self::variable(
			self::front(),
			array(
				'M' => null,
				'N' => new ProductConfig(),
				'B' => self::front()->with_layout( Layout::BUTTON ),
				'X' => self::front()->with_validity( false, 'unknown_placement' ),
			)
		);

		$switches = self::data( $this->form_html( $product ), 'variations' );

		$this->assertSame( VariationSwitches::PLAIN, $switches[ $variations['N']->get_id() ]['state'] );
		$this->assertSame( VariationSwitches::UNAVAILABLE, $switches[ $variations['B']->get_id() ]['state'] );
		$this->assertSame( VariationSwitches::UNAVAILABLE, $switches[ $variations['X']->get_id() ]['state'] );
	}

	public function test_each_variation_carries_its_price_as_the_store_formats_it(): void {
		[ $product, $variations ] = self::variable(
			self::front(),
			array(
				'M' => null,
				'S' => self::front( array( 'size' => new PrintSize( 210.0, 270.0, 'Small' ) ) ),
				'N' => new ProductConfig(),
				'F' => null,
			)
		);
		$variations['S']->set_regular_price( '45' );
		$variations['S']->save();
		$variations['F']->set_regular_price( '' );
		$variations['F']->save();
		WC_Product_Variable::sync( $product->get_id() );
		$reloaded = wc_get_product( $product->get_id() );
		$this->assertInstanceOf( WC_Product::class, $reloaded );

		$switches = self::data( $this->form_html( $reloaded ), 'variations' );

		foreach ( array( 'M', 'S', 'N' ) as $size ) {
			$variation = wc_get_product( $variations[ $size ]->get_id() );
			$this->assertInstanceOf( WC_Product::class, $variation );
			// The data attribute is decoded once more than the price's own entities.
			$this->assertSame( html_entity_decode( $variation->get_price_html(), ENT_QUOTES ), $switches[ $variation->get_id() ]['priceHtml'] ?? null, "variation $size" );
		}
		$this->assertStringContainsString( '45', $switches[ $variations['S']->get_id() ]['priceHtml'] );
		$this->assertStringContainsString( '30', $switches[ $variations['M']->get_id() ]['priceHtml'] );
		// Without a price, the page keeps the product's own.
		$this->assertArrayNotHasKey( 'priceHtml', $switches[ $variations['F']->get_id() ] ?? array() );
	}

	public function test_a_variation_with_no_variant_or_a_retired_one_is_unavailable(): void {
		[ $product, $variations ] = self::variable(
			self::front(),
			array(
				'M' => null,
				'R' => null,
				'U' => null,
			)
		);
		( new ProductVariant( DesignFixture::BLANK_ID, null, 'v_tee_red', 'Colour: Red', 'no_longer_sold' ) )->save_to( $variations['R'] );
		( new ProductVariant( DesignFixture::BLANK_ID ) )->save_to( $variations['U'] );

		$switches = self::data( $this->form_html( $product ), 'variations' );

		$this->assertSame( VariationSwitches::PERSONALISED, $switches[ $variations['M']->get_id() ]['state'] );
		$this->assertSame( VariationSwitches::UNAVAILABLE, $switches[ $variations['R']->get_id() ]['state'] );
		$this->assertSame( VariationSwitches::UNAVAILABLE, $switches[ $variations['U']->get_id() ]['state'] );
	}

	public function test_a_form_that_cannot_mount_still_says_which_variations_are_plain(): void {
		[ $product, $variations ] = self::variable(
			self::front()->with_validity( false, 'unknown_placement' ),
			array(
				'M' => null,
				'N' => new ProductConfig(),
			)
		);

		$html     = $this->form_html( $product );
		$switches = self::data( $html, 'variations' );

		$this->assertStringNotContainsString( 'data-pixonaut-mounts', $html );
		$this->assertSame( VariationSwitches::PLAIN, $switches[ $variations['N']->get_id() ]['state'] );
	}

	public function test_a_disabled_variation_is_left_out(): void {
		[ $product, $variations ] = self::variable(
			self::front(),
			array(
				'M' => null,
				'S' => null,
			)
		);
		$variations['S']->set_status( 'private' );
		$variations['S']->save();

		$switches = self::data( $this->form_html( $product ), 'variations' );

		$this->assertSame( array( (string) $variations['M']->get_id() ), array_map( 'strval', array_keys( $switches ) ) );
	}

	public function test_a_product_personalised_only_in_a_variation_mounts_that_variation(): void {
		[ $product ] = self::variable(
			null,
			array(
				'N' => null,
				'S' => self::front( array( 'size' => new PrintSize( 210.0, 270.0, 'Small' ) ) ),
			)
		);

		$mounts = self::data( $this->form_html( $product ), 'mounts' );

		$this->assertSame( array( self::SMALL_SIZE ), $mounts['front']['printSizes'] );
	}

	public function test_a_product_personalised_only_in_variations_mounts_the_first_inline_one(): void {
		[ $product, $variations ] = self::variable(
			null,
			array(
				'B' => self::front()->with_layout( Layout::BUTTON ),
				'S' => self::front( array( 'size' => new PrintSize( 210.0, 270.0, 'Small' ) ) ),
			)
		);

		$html     = $this->form_html( $product );
		$mounts   = self::data( $html, 'mounts' );
		$switches = self::data( $html, 'variations' );

		$this->assertSame( array( self::SMALL_SIZE ), $mounts['front']['printSizes'] );
		$this->assertSame( VariationSwitches::UNAVAILABLE, $switches[ $variations['B']->get_id() ]['state'] );
		$this->assertSame( VariationSwitches::PERSONALISED, $switches[ $variations['S']->get_id() ]['state'] );
	}

	public function test_a_variation_approved_by_checkbox_adds_the_approval_box(): void {
		[ $product ] = self::variable(
			self::front(),
			array(
				'M' => null,
				'C' => self::front( array( 'approval' => ApprovalMode::CHECKBOX ) ),
			)
		);

		$this->assertStringContainsString( 'pixonaut-approval__box', $this->form_html( $product ) );
	}

	public function test_a_simple_product_has_no_variations(): void {
		$product = StorefrontTest::product( StorefrontTest::personalised() );

		$this->assertStringNotContainsString( 'data-pixonaut-variations', $this->form_html( $product ) );
	}

	private function form_html( WC_Product $product ): string {
		$GLOBALS['product'] = $product;
		ob_start();
		do_action( 'woocommerce_before_add_to_cart_button' );
		return (string) ob_get_clean();
	}

	/**
	 * A JSON data attribute of the form.
	 *
	 * @param string $html The form's markup.
	 * @param string $name `mounts` or `variations`.
	 * @return array<int|string, mixed>
	 */
	private static function data( string $html, string $name ): array {
		self::assertSame( 1, preg_match( '/data-pixonaut-' . $name . '="([^"]*)"/', $html, $match ) );
		$data = json_decode( html_entity_decode( $match[1] ?? '', ENT_QUOTES ), true );
		self::assertIsArray( $data );
		return $data;
	}
}
