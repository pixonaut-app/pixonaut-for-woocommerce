<?php
/**
 * @package Pixonaut\WooCommerce
 */

declare(strict_types=1);

namespace Pixonaut\WooCommerce\Tests\Integration\Storefront;

use Pixonaut\WooCommerce\Plugin;
use Pixonaut\WooCommerce\Settings\Settings;
use Pixonaut\WooCommerce\Storefront\VariationSlots;
use Pixonaut\WooCommerce\Tests\Support\DesignFixture;
use Pixonaut\WooCommerce\Tests\Support\SplitPosters;
use WC_Product;
use WC_Product_Variable;
use WP_Block;
use WP_UnitTestCase;

/**
 * A variable product the inline designer shows on lists the chosen
 * variation's description in a "Delivery" row of its attributes table,
 * every variation's text stacked in one place that keeps the height of the
 * longest, and its add-to-cart form prints neither that nor a delivery
 * time.
 */
final class DeliveryRowsTest extends WP_UnitTestCase {

	use SplitPosters;

	private const PICTURE = 'Bild';

	private const SIZE = 'Storlek';

	private const SHORT = 'Utan ram.';

	private const LONG = 'Inramad i svart ek, med passepartout och ett glas som inte reflekterar.';

	/** The place of the variation's description. */
	private const DELIVERY_SLOT = '<div class="pixonaut-variation-slot pixonaut-variation-description"';

	public function set_up(): void {
		parent::set_up();
		Plugin::boot( dirname( __DIR__, 4 ) . '/pixonaut-for-woocommerce.php' )->settings()->set( Settings::DELIVERY_TIME_SHOW, true );
		$this->set_up_posters();
	}

	public function tear_down(): void {
		$this->tear_down_posters();
		Plugin::boot( dirname( __DIR__, 4 ) . '/pixonaut-for-woocommerce.php' )->settings()->set( Settings::DELIVERY_TIME_SHOW, false );
		wp_dequeue_script( VariationSlots::SCRIPT_HANDLE );
		parent::tear_down();
	}

	public function test_each_description_is_a_delivery_row_once_for_the_variations_it_is_of_none_shown_yet(): void {
		$poster = $this->personalised();
		$ids    = $poster->get_children();
		$this->describe( $ids, array( self::LONG, self::LONG, self::SHORT ) );

		$html = $this->table( $this->fresh( $poster ) );

		$this->assertStringContainsString( '<th class="woocommerce-product-attributes-item__label" scope="row">Delivery</th>', $html );
		$slot = (string) strstr( $html, self::DELIVERY_SLOT );
		$this->assertNotSame( '', $slot, 'the place is printed' );
		$this->assertStringContainsString( sprintf( 'data-pixonaut-variation-product="%d"', $poster->get_id() ), $slot );
		$this->assertSame( 1, substr_count( $slot, self::LONG ) );
		$this->assertStringContainsString( sprintf( 'data-pixonaut-variations="%d %d"', $ids[0], $ids[1] ), $slot );
		$this->assertStringContainsString( sprintf( 'data-pixonaut-variations="%d"', $ids[2] ), $slot );
		$this->assertSame( 2, substr_count( $slot, 'visibility:hidden' ), 'kept in the style WooCommerce lets through' );
		$this->assertStringContainsString( 'grid-row:1;grid-column:1', $slot );
		$this->assertTrue( wp_script_is( VariationSlots::SCRIPT_HANDLE, 'enqueued' ) );
	}

	public function test_the_form_holds_no_description_and_no_delivery_time(): void {
		$poster = $this->personalised();
		$this->describe( $poster->get_children(), array( self::LONG, self::SHORT, self::SHORT ) );

		$form = $this->form( $this->fresh( $poster ) );

		$this->assertStringNotContainsString( 'pixonaut-variation-description', $form );
		$this->assertStringNotContainsString( 'pixonaut-delivery-time', $form );
		$this->assertStringNotContainsString( self::LONG, $form );
	}

	public function test_no_delivery_row_without_a_description_or_the_designer(): void {
		$poster = $this->personalised();
		$this->assertStringNotContainsString( 'pixonaut-variation-description', $this->table( $poster ), 'no variation has a description' );

		$plain = $this->poster();
		$this->describe( $plain->get_children(), array( self::LONG, self::SHORT, self::SHORT ) );
		$this->assertStringNotContainsString( 'pixonaut-variation-description', $this->table( $this->fresh( $plain ) ), 'no designer' );
	}

	public function test_the_product_specifications_block_lists_the_rows_in_its_own_markup(): void {
		$poster = $this->personalised();
		$this->describe( $poster->get_children(), array( self::LONG, self::SHORT, self::SHORT ) );

		$block = new WP_Block(
			array(
				'blockName'    => 'woocommerce/product-specifications',
				'attrs'        => array(),
				'innerBlocks'  => array(),
				'innerHTML'    => '',
				'innerContent' => array(),
			),
			array( 'postId' => $poster->get_id() )
		);
		$html  = $block->render();

		$row = (string) strstr( $html, '<tr class="wp-block-product-specifications-item wp-block-product-specifications-item-pixonaut_delivery">' );
		$this->assertNotSame( '', $row, 'the row is printed' );
		$this->assertStringContainsString( '<th scope="row" class="wp-block-product-specifications-item__label">Delivery</th>', $row );
		$this->assertStringContainsString( self::DELIVERY_SLOT, $row );
		$this->assertLessThan( strpos( $html, '</tbody>' ), strpos( $html, 'pixonaut_delivery' ), 'inside the table body' );
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
	 * The product's attributes table as WooCommerce prints it.
	 *
	 * @param WC_Product $product The variable product.
	 */
	private function table( WC_Product $product ): string {
		ob_start();
		wc_display_product_attributes( $product );
		return (string) ob_get_clean();
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
