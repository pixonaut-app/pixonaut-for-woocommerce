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
use WP_Block;
use WP_UnitTestCase;

/**
 * The purchase panel of a product the inline designer shows on prints no
 * short description, in WooCommerce's own place and in the blocks a block
 * theme prints it with; a product without the designer keeps its own.
 */
final class DesignerSummaryTest extends WP_UnitTestCase {

	use SplitPosters;

	private const PICTURE = 'Bild';

	private const SIZE = 'Storlek';

	private const SUMMARY = 'En affisch, tryckt när du beställer.';

	public function set_up(): void {
		parent::set_up();
		Plugin::boot( dirname( __DIR__, 4 ) . '/pixonaut-for-woocommerce.php' );
		$this->set_up_posters();
	}

	public function tear_down(): void {
		$this->tear_down_posters();
		parent::tear_down();
	}

	public function test_a_designer_products_page_prints_no_short_description(): void {
		$poster = $this->personalised();

		$this->go_to( (string) get_permalink( $poster->get_id() ) );

		$this->assertStringNotContainsString( self::SUMMARY, $this->short_description() );
		$this->assertStringContainsString( 'kept', wc_format_content( 'kept' ), 'text WooCommerce formats as a short description is kept' );
		$this->assertSame( 20, has_action( 'woocommerce_single_product_summary', 'woocommerce_template_single_excerpt' ), 'put back for a later summary' );
		$this->assertSame( '', $this->block( 'woocommerce/product-summary', $poster->get_id() ) );
		$this->assertSame( '', $this->block( 'core/post-excerpt', $poster->get_id() ) );
	}

	public function test_a_product_without_the_designer_keeps_its_short_description(): void {
		$plain = $this->described( $this->poster() );

		$this->go_to( (string) get_permalink( $plain->get_id() ) );

		$this->assertStringContainsString( self::SUMMARY, $this->short_description() );
		$this->assertStringContainsString( self::SUMMARY, $this->block( 'woocommerce/product-summary', $plain->get_id() ) );
	}

	public function test_a_designer_product_listed_on_another_page_keeps_its_short_description(): void {
		$poster = $this->personalised();
		$other  = $this->described( $this->poster() );

		$this->go_to( (string) get_permalink( $other->get_id() ) );

		$this->assertStringContainsString( self::SUMMARY, $this->block( 'woocommerce/product-summary', $poster->get_id() ) );
	}

	private function personalised(): WC_Product {
		$poster = $this->described( $this->poster() );
		VariationSwitchesTest::front()->save_to( $poster );
		DesignFixture::sell_variant( $poster );
		WC_Product_Variable::sync( $poster->get_id() );
		$read = wc_get_product( $poster->get_id() );
		$this->assertInstanceOf( WC_Product::class, $read );
		return $read;
	}

	private function described( WC_Product $product ): WC_Product {
		$product->set_short_description( self::SUMMARY );
		$product->save();
		return $product;
	}

	/** The page's product summary, as WooCommerce prints it. */
	private function short_description(): string {
		// phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- The post WooCommerce's template reads, as the page's loop sets it.
		$GLOBALS['post']    = get_queried_object();
		$GLOBALS['product'] = wc_get_product( get_queried_object_id() );
		ob_start();
		do_action( 'woocommerce_single_product_summary' );
		return (string) ob_get_clean();
	}

	/**
	 * A block that prints a short description, for a product.
	 *
	 * @param string $name    The block.
	 * @param int    $post_id The product.
	 */
	private function block( string $name, int $post_id ): string {
		$block = new WP_Block(
			array(
				'blockName'    => $name,
				'attrs'        => array(),
				'innerBlocks'  => array(),
				'innerHTML'    => '',
				'innerContent' => array(),
			),
			array(
				'postId'   => $post_id,
				'postType' => 'product',
			)
		);
		return $block->render();
	}
}
