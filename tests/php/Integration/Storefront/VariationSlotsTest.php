<?php
/**
 * @package Pixonaut\WooCommerce
 */

declare(strict_types=1);

namespace Pixonaut\WooCommerce\Tests\Integration\Storefront;

use Pixonaut\WooCommerce\Plugin;
use Pixonaut\WooCommerce\Storefront\VariationSlots;
use Pixonaut\WooCommerce\Tests\Support\DesignFixture;
use Pixonaut\WooCommerce\Tests\Support\SplitPosters;
use WC_Product;
use WC_Product_Variable;
use WP_UnitTestCase;

/**
 * The add-to-cart form of a variable product the inline designer shows on
 * prints every variation's description up front, stacked in one place that
 * keeps the height of the longest, above the quantity and the price row.
 */
final class VariationSlotsTest extends WP_UnitTestCase {

	use SplitPosters;

	private const PICTURE = 'Bild';

	private const SIZE = 'Storlek';

	private const SHORT = 'Utan ram.';

	private const LONG = 'Inramad i svart ek, med passepartout och ett glas som inte reflekterar.';

	public function set_up(): void {
		parent::set_up();
		Plugin::boot( dirname( __DIR__, 4 ) . '/pixonaut-for-woocommerce.php' );
		$this->set_up_posters();
	}

	public function tear_down(): void {
		$this->tear_down_posters();
		wp_dequeue_script( VariationSlots::SCRIPT_HANDLE );
		parent::tear_down();
	}

	public function test_each_description_is_printed_once_for_the_variations_it_is_of_none_shown_yet(): void {
		$poster = $this->personalised();
		$ids    = $poster->get_children();
		$this->describe( $ids, array( self::LONG, self::LONG, self::SHORT ) );

		$html = $this->form( $this->fresh( $poster ) );

		$slot = (string) strstr( $html, '<div class="pixonaut-variation-slot pixonaut-variation-description"' );
		$this->assertNotSame( '', $slot, 'the place is printed' );
		$this->assertLessThan( strpos( $html, 'name="quantity"' ), strpos( $html, 'pixonaut-variation-description' ), 'above the quantity' );
		$this->assertSame( 1, substr_count( $slot, self::LONG ) );
		$this->assertStringContainsString( sprintf( 'data-pixonaut-variations="%d %d"', $ids[0], $ids[1] ), $slot );
		$this->assertStringContainsString( sprintf( 'data-pixonaut-variations="%d"', $ids[2] ), $slot );
		$this->assertSame( 2, substr_count( $slot, 'visibility:hidden' ) );
		$this->assertTrue( wp_script_is( VariationSlots::SCRIPT_HANDLE, 'enqueued' ) );
	}

	public function test_no_place_without_a_description_or_the_designer(): void {
		$poster = $this->personalised();
		$this->assertStringNotContainsString( 'pixonaut-variation-description', $this->form( $poster ), 'no variation has a description' );

		$plain = $this->poster();
		$this->describe( $plain->get_children(), array( self::LONG, self::SHORT, self::SHORT ) );
		$this->assertStringNotContainsString( 'pixonaut-variation-description', $this->form( $this->fresh( $plain ) ), 'no designer' );
	}

	/**
	 * Gives each variation its description, in order.
	 *
	 * @param array<int> $ids          The variations.
	 * @param string[]   $descriptions Their descriptions.
	 */
	private function describe( array $ids, array $descriptions ): void {
		foreach ( array_values( $ids ) as $index => $id ) {
			$variation = wc_get_product( $id );
			$this->assertInstanceOf( WC_Product::class, $variation );
			$variation->set_description( $descriptions[ $index ] );
			$variation->save();
		}
	}

	private function personalised(): WC_Product {
		$poster = $this->poster();
		VariationSwitchesTest::front()->save_to( $poster );
		DesignFixture::sell_variant( $poster );
		WC_Product_Variable::sync( $poster->get_id() );
		return $this->fresh( $poster );
	}

	private function fresh( WC_Product $product ): WC_Product {
		$read = wc_get_product( $product->get_id() );
		$this->assertInstanceOf( WC_Product::class, $read );
		return $read;
	}

	/**
	 * The product's add-to-cart form as WooCommerce prints it.
	 *
	 * @param WC_Product $product The variable product.
	 */
	private function form( WC_Product $product ): string {
		$this->go_to( (string) get_permalink( $product->get_id() ) );
		$GLOBALS['product'] = $product;
		ob_start();
		woocommerce_variable_add_to_cart();
		return (string) ob_get_clean();
	}
}
