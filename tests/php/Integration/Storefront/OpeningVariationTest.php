<?php
/**
 * @package Pixonaut\WooCommerce
 */

declare(strict_types=1);

namespace Pixonaut\WooCommerce\Tests\Integration\Storefront;

use Pixonaut\WooCommerce\Catalogue\PrintSize;
use Pixonaut\WooCommerce\Connection\ConnectionStore;
use Pixonaut\WooCommerce\Layout;
use Pixonaut\WooCommerce\Plugin;
use Pixonaut\WooCommerce\Settings\Settings;
use Pixonaut\WooCommerce\Storefront\AttributeOptions;
use Pixonaut\WooCommerce\Storefront\Storefront;
use WC_Product;
use WC_Product_Variable;
use WC_Product_Variation;
use WP_Block;
use WP_UnitTestCase;

/**
 * The page of a variable product with the inline designer prints what the
 * designer shows once it takes over: the price of the variation the form
 * opens on, the swatches the store draws variation options as, and no
 * print size picker of the designer's own when the variations pick the
 * size.
 */
final class OpeningVariationTest extends WP_UnitTestCase {

	private const SMALL_PRICE = '249';

	private const LARGE_PRICE = '349';

	private ConnectionStore $store;

	private Plugin $plugin;

	public function set_up(): void {
		parent::set_up();
		$this->plugin = Plugin::boot( dirname( __DIR__, 4 ) . '/pixonaut-for-woocommerce.php' );
		$this->store  = $this->plugin->connection_store();
		$this->plugin->storefront()->forget_page();
		StorefrontTest::connect( $this->store );
		wp_dequeue_script( Storefront::SCRIPT_HANDLE );
		wp_deregister_script( Storefront::SCRIPT_HANDLE );
	}

	public function tear_down(): void {
		$this->store->clear();
		$this->plugin->settings()->set( Settings::ATTRIBUTE_OPTIONS, AttributeOptions::THEME );
		$this->plugin->settings()->set( Settings::DEFAULT_LAYOUT, Layout::INLINE );
		unset( $GLOBALS['product'], $_REQUEST['attribute_size'] );
		delete_option( 'woocommerce_hide_out_of_stock_items' );
		parent::tear_down();
	}

	public function test_the_page_prints_the_price_of_the_variation_the_form_opens_on(): void {
		$product = $this->sign( 'L' );
		$this->go_to( (string) get_permalink( $product->get_id() ) );

		$this->assertStringContainsString( self::LARGE_PRICE, $product->get_price_html() );
		$this->assertStringNotContainsString( self::SMALL_PRICE, $product->get_price_html() );
		$range = $this->form_attribute( $product, 'product-price' );
		$this->assertStringContainsString( self::SMALL_PRICE, $range );
		$this->assertStringContainsString( self::LARGE_PRICE, $range );
		$this->assertSame( array( 'attribute_size' => 'L' ), json_decode( $this->form_attribute( $product, 'opening-choice' ), true ) );
	}

	public function test_the_personalise_button_page_prints_the_opening_variations_price_with_its_range_beside_it(): void {
		$this->plugin->settings()->set( Settings::DEFAULT_LAYOUT, Layout::BUTTON );
		$product = $this->sign( 'L' );
		$this->go_to( (string) get_permalink( $product->get_id() ) );

		$this->assertStringContainsString( self::LARGE_PRICE, $product->get_price_html() );
		$this->assertStringNotContainsString( self::SMALL_PRICE, $product->get_price_html() );

		$GLOBALS['product'] = $product;
		ob_start();
		$this->plugin->personalise_button()->render();
		$html  = (string) ob_get_clean();
		$range = self::data_attribute( $html, 'product-price' );
		$this->assertStringContainsString( self::SMALL_PRICE, $range );
		$this->assertStringContainsString( self::LARGE_PRICE, $range );
		$this->assertSame( array( 'attribute_size' => 'L' ), json_decode( self::data_attribute( $html, 'opening-choice' ), true ) );
		$by_variation = json_decode( self::data_attribute( $html, 'prices' ), true );
		$this->assertIsArray( $by_variation );
		$this->assertCount( 2, $by_variation );
	}

	public function test_a_size_the_request_chooses_outranks_the_default(): void {
		$product                    = $this->sign( 'L' );
		$_REQUEST['attribute_size'] = 'S';
		$this->go_to( (string) get_permalink( $product->get_id() ) );

		$this->assertStringContainsString( self::SMALL_PRICE, $product->get_price_html() );
		$this->assertStringNotContainsString( self::LARGE_PRICE, $product->get_price_html() );
	}

	public function test_a_form_opening_on_no_variation_keeps_the_range(): void {
		$product = $this->sign( null );
		$this->go_to( (string) get_permalink( $product->get_id() ) );

		$this->assertStringContainsString( self::SMALL_PRICE, $product->get_price_html() );
		$this->assertStringContainsString( self::LARGE_PRICE, $product->get_price_html() );
		$html = $this->form_html( $product );
		$this->assertStringNotContainsString( 'data-pixonaut-product-price', $html );
		$this->assertStringNotContainsString( 'data-pixonaut-opening-choice', $html );
	}

	public function test_a_default_variation_the_form_hides_out_of_stock_keeps_the_products_price(): void {
		update_option( 'woocommerce_hide_out_of_stock_items', 'yes' );
		$product = $this->sign( 'L', 'L' );
		$this->go_to( (string) get_permalink( $product->get_id() ) );

		// The product's own price, which leaves the hidden variation out too.
		$this->assertStringContainsString( self::SMALL_PRICE, $product->get_price_html() );
		$this->assertStringNotContainsString( self::LARGE_PRICE, $product->get_price_html() );
		$html = $this->form_html( $product );
		$this->assertStringNotContainsString( 'data-pixonaut-product-price', $html );
		$this->assertStringNotContainsString( 'data-pixonaut-opening-choice', $html );
	}

	public function test_another_page_shows_the_products_range(): void {
		$product = $this->sign( 'L' );
		$this->go_to( home_url( '/' ) );

		$this->assertStringContainsString( self::SMALL_PRICE, $product->get_price_html() );
		$this->assertStringContainsString( self::LARGE_PRICE, $product->get_price_html() );
	}

	public function test_variations_that_pick_the_size_leave_the_printed_form_without_a_size_picker(): void {
		$product = $this->sign( 'L' );
		$this->go_to( (string) get_permalink( $product->get_id() ) );

		$html = $this->form_html( $product );

		$this->assertStringContainsString( 'pixonaut-form__placeholder', $html );
		$this->assertStringNotContainsString( 'pixonaut-placeholder__sizes', $html );
	}

	public function test_the_select_is_printed_with_the_swatches_the_store_draws(): void {
		$this->plugin->settings()->set( Settings::ATTRIBUTE_OPTIONS, AttributeOptions::SWATCHES );
		$product = $this->sign( 'L' );
		$this->go_to( (string) get_permalink( $product->get_id() ) );

		$html = $this->dropdown( $product );

		$this->assertSame( 1, preg_match( '/<\/select><div class="pixonaut-swatches pixonaut-swatches--printed" aria-hidden="true" inert>(.*)<\/div>$/s', $html, $match ) );
		$this->assertSame( '<span class="pixonaut-swatches__swatch">S</span><span class="pixonaut-swatches__swatch is-chosen">L</span>', $match[1] ?? '' );
	}

	public function test_the_block_forms_field_is_printed_with_the_swatches_the_store_draws(): void {
		$this->plugin->settings()->set( Settings::ATTRIBUTE_OPTIONS, AttributeOptions::SWATCHES );
		$product = $this->sign( 'L' );
		$this->go_to( (string) get_permalink( $product->get_id() ) );
		$GLOBALS['product'] = $product;

		$html = $this->rendered_block_field();

		$this->assertStringStartsWith( '<div class="chips"></div><div class="pixonaut-swatches pixonaut-swatches--printed"', $html );
		$this->assertStringContainsString( '<span class="pixonaut-swatches__swatch">S</span><span class="pixonaut-swatches__swatch is-chosen">L</span>', $html );
	}

	public function test_the_block_forms_field_is_printed_alone_when_the_theme_draws_the_options(): void {
		$product = $this->sign( 'L' );
		$this->go_to( (string) get_permalink( $product->get_id() ) );
		$GLOBALS['product'] = $product;

		$this->assertSame( '<div class="chips"></div>', $this->rendered_block_field() );
	}

	public function test_the_block_form_shows_its_quantity_from_the_start_when_it_opens_on_a_variation(): void {
		$GLOBALS['wp_interactivity'] = null;
		$product                     = $this->sign( 'L' );
		$this->go_to( (string) get_permalink( $product->get_id() ) );

		$this->form_html( $product );

		$this->assertSame( array( 'allowsQuantityChange' => true ), wp_interactivity_state( 'woocommerce/add-to-cart-with-options-quantity-selector' ) );
	}

	public function test_the_block_form_keeps_its_quantity_hidden_when_it_opens_on_no_variation(): void {
		$GLOBALS['wp_interactivity'] = null;
		$product                     = $this->sign( null );
		$this->go_to( (string) get_permalink( $product->get_id() ) );

		$this->form_html( $product );

		$this->assertSame( array(), wp_interactivity_state( 'woocommerce/add-to-cart-with-options-quantity-selector' ) );
	}

	public function test_the_script_placing_the_parts_is_printed_once_after_a_printed_form(): void {
		if ( ! is_readable( dirname( __DIR__, 4 ) . '/assets/build/storefront-parts.js' ) ) {
			$this->markTestSkipped( 'The assets are not built.' );
		}
		$product = $this->sign( 'L' );
		$this->go_to( (string) get_permalink( $product->get_id() ) );
		$this->form_html( $product );

		$this->assertStringContainsString( '<script', $this->after_form() );
		$this->assertSame( '', $this->after_form() );
	}

	public function test_no_script_placing_the_parts_is_printed_without_a_printed_form(): void {
		$this->go_to( '/' );

		$this->assertSame( '', $this->after_form() );
	}

	public function test_the_select_is_printed_alone_when_the_theme_draws_the_options(): void {
		$product = $this->sign( 'L' );
		$this->go_to( (string) get_permalink( $product->get_id() ) );

		$this->assertStringNotContainsString( 'pixonaut-swatches', $this->dropdown( $product ) );
	}

	/**
	 * A variable product sold small and large, each variation printing at
	 * its own size and priced apart, its form opening on `$opens_on`.
	 *
	 * @param string|null $opens_on     The size the form opens on; null for none.
	 * @param string|null $out_of_stock A size out of stock; null for none.
	 */
	private function sign( ?string $opens_on, ?string $out_of_stock = null ): WC_Product {
		[ $product, $variations ] = VariationSwitchesTest::variable(
			VariationSwitchesTest::front(),
			array(
				'S' => VariationSwitchesTest::front( array( 'size' => new PrintSize( 350.0, 500.0, 'Small' ) ) ),
				'L' => VariationSwitchesTest::front( array( 'size' => new PrintSize( 500.0, 700.0, 'Large' ) ) ),
			)
		);
		$prices                   = array(
			'S' => self::SMALL_PRICE,
			'L' => self::LARGE_PRICE,
		);
		foreach ( $variations as $size => $variation ) {
			$this->assertInstanceOf( WC_Product_Variation::class, $variation );
			$variation->set_regular_price( $prices[ $size ] );
			if ( $size === $out_of_stock ) {
				$variation->set_stock_status( 'outofstock' );
			}
			$variation->save();
		}
		if ( null !== $opens_on ) {
			$product->set_default_attributes( array( 'size' => $opens_on ) );
			$product->save();
		}
		WC_Product_Variable::sync( $product->get_id() );
		$reloaded = wc_get_product( $product->get_id() );
		$this->assertInstanceOf( WC_Product::class, $reloaded );
		return $reloaded;
	}

	private static function data_attribute( string $html, string $name ): string {
		return 1 === preg_match( '/data-pixonaut-' . $name . '="([^"]*)"/', $html, $matches ) ? html_entity_decode( $matches[1] ) : '';
	}

	private function form_html( WC_Product $product ): string {
		$GLOBALS['product'] = $product;
		ob_start();
		do_action( 'woocommerce_before_add_to_cart_button' );
		return (string) ob_get_clean();
	}

	/**
	 * A data attribute of the form, decoded.
	 *
	 * @param WC_Product $product The product.
	 * @param string     $name    The attribute's name after `data-pixonaut-`.
	 */
	private function form_attribute( WC_Product $product, string $name ): string {
		$this->assertSame( 1, preg_match( '/data-pixonaut-' . $name . '="([^"]*)"/', $this->form_html( $product ), $match ) );
		return html_entity_decode( $match[1] ?? '', ENT_QUOTES );
	}

	private function after_form(): string {
		ob_start();
		$this->plugin->storefront()->print_parts_script();
		return (string) ob_get_clean();
	}

	/**
	 * The block form's chips of the size, as the page renders them.
	 */
	private function rendered_block_field(): string {
		$block          = new WP_Block(
			array(
				'blockName'    => 'woocommerce/product-filter-chips',
				'attrs'        => array(),
				'innerBlocks'  => array(),
				'innerHTML'    => '',
				'innerContent' => array(),
			)
		);
		$block->context = array(
			'woocommerce/selectableItems' => array(
				'items' => array(
					array(
						'value'    => 'S',
						'label'    => 'S',
						'selected' => false,
					),
					array(
						'value'    => 'L',
						'label'    => 'L',
						'selected' => true,
					),
				),
			),
		);
		return (string) apply_filters( 'render_block_woocommerce/product-filter-chips', '<div class="chips"></div>', array(), $block ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound, WordPress.NamingConventions.ValidHookName.UseUnderscores -- WordPress's own filter.
	}

	private function dropdown( WC_Product $product ): string {
		ob_start();
		wc_dropdown_variation_attribute_options(
			array(
				'product'   => $product,
				'attribute' => 'size',
				'options'   => array( 'S', 'L' ),
				'selected'  => 'L',
			)
		);
		return trim( (string) ob_get_clean() );
	}
}
