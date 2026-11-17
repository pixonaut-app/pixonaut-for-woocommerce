<?php
/**
 * @package Pixonaut\WooCommerce
 */

declare(strict_types=1);

namespace Pixonaut\WooCommerce\Tests\Integration\Storefront;

use Pixonaut\WooCommerce\Plugin;
use Pixonaut\WooCommerce\Tests\Support\DesignFixture;
use Pixonaut\WooCommerce\Tests\Support\SplitPosters;
use WC_Product;
use WC_Product_Variable;
use WP_UnitTestCase;

/**
 * The page of a variable product the inline designer mounts on gives its
 * add-to-cart form every variation, however low the store set WooCommerce's
 * threshold for looking them up one choice at a time: handed from what is
 * kept between requests, by a script at the top of the form.
 */
final class VariationsUpFrontTest extends WP_UnitTestCase {

	use SplitPosters;

	private const PICTURE = 'Bild';

	private const SIZE = 'Storlek';

	/** Below the poster's three variations. */
	private const LOW_THRESHOLD = 2;

	/** WooCommerce's own threshold. */
	private const WOOCOMMERCE_THRESHOLD = 30;

	public function set_up(): void {
		parent::set_up();
		Plugin::boot( dirname( __DIR__, 4 ) . '/pixonaut-for-woocommerce.php' );
		$this->set_up_posters();
		add_filter( 'woocommerce_ajax_variation_threshold', array( $this, 'low_threshold' ) );
	}

	public function tear_down(): void {
		remove_filter( 'woocommerce_ajax_variation_threshold', array( $this, 'low_threshold' ) );
		$this->tear_down_posters();
		parent::tear_down();
	}

	public function low_threshold(): int {
		return self::LOW_THRESHOLD;
	}

	public function test_a_personalised_product_gives_the_form_every_variation(): void {
		$poster = $this->personalised();
		$this->go_to( (string) get_permalink( $poster->get_id() ) );
		$form = $this->form( $poster );

		$this->assertSame( -1, $this->threshold( $poster ), 'WooCommerce reads none of them for the form' );
		$this->assertSame( 1, preg_match( '/<form[^>]*data-product_variations="false">\s*<script>(.*?)<\/script>/s', $form, $script ), 'the script is the first thing in the form' );
		$this->assertStringNotContainsString( '<', $script[1] ?? '' );
		$this->assertSame( 1, preg_match( '/setAttribute\("data-product_variations",(".*?")\);/', $script[1] ?? '', $handed ) );
		$this->assertSame( json_decode( (string) wp_json_encode( $poster->get_available_variations() ), true ), json_decode( (string) json_decode( $handed[1] ?? '' ), true ) );
	}

	public function test_a_personalised_product_with_no_variation_to_offer_keeps_them_in_the_form(): void {
		$poster = $this->personalised();
		foreach ( $poster->get_children() as $id ) {
			$variation = wc_get_product( $id );
			$this->assertInstanceOf( WC_Product::class, $variation );
			$variation->set_status( 'private' );
			$variation->save();
		}
		$poster = wc_get_product( $poster->get_id() );
		$this->assertInstanceOf( WC_Product::class, $poster );
		$this->go_to( (string) get_permalink( $poster->get_id() ) );

		$this->assertSame( count( $poster->get_children() ), $this->threshold( $poster ) );
		$this->assertStringNotContainsString( 'setAttribute', $this->form( $poster ) );
	}

	public function test_a_product_without_the_designer_keeps_the_store_threshold(): void {
		$poster = $this->poster();
		$this->go_to( (string) get_permalink( $poster->get_id() ) );

		$this->assertSame( self::LOW_THRESHOLD, $this->threshold( $poster ) );
		$this->assertStringContainsString( 'data-product_variations="false"', $this->form( $poster ) );
	}

	private function personalised(): WC_Product_Variable {
		$poster = $this->poster();
		VariationSwitchesTest::front()->save_to( $poster );
		DesignFixture::sell_variant( $poster );
		WC_Product_Variable::sync( $poster->get_id() );
		$read = wc_get_product( $poster->get_id() );
		$this->assertInstanceOf( WC_Product_Variable::class, $read );
		return $read;
	}

	/**
	 * The threshold WooCommerce compares the product's variations with.
	 *
	 * @param WC_Product $product The variable product.
	 */
	private function threshold( WC_Product $product ): int {
		return (int) apply_filters( 'woocommerce_ajax_variation_threshold', self::WOOCOMMERCE_THRESHOLD, $product );
	}

	/**
	 * The product's add-to-cart form as WooCommerce prints it.
	 *
	 * @param WC_Product $product The variable product.
	 */
	private function form( WC_Product $product ): string {
		$GLOBALS['product'] = $product;
		ob_start();
		woocommerce_variable_add_to_cart();
		return (string) ob_get_clean();
	}
}
