<?php
/**
 * @package Pixonaut\WooCommerce
 */

declare(strict_types=1);

namespace Pixonaut\WooCommerce\Tests\Integration\Cart;

use Pixonaut\WooCommerce\Api\Hosts;
use Pixonaut\WooCommerce\Cart\AddToCart;
use Pixonaut\WooCommerce\Catalogue\PrintSize;
use Pixonaut\WooCommerce\Catalogue\ProductConfig;
use Pixonaut\WooCommerce\Catalogue\Side;
use Pixonaut\WooCommerce\Labels;
use Pixonaut\WooCommerce\Tests\Support\DesignFixture;
use WC_Product_Attribute;
use WC_Product_Variable;
use WC_Product_Variation;

/**
 * Adding a variation checks its design against the menu the variation
 * resolves to: its own when it overrides, else its product's.
 */
final class VariationCartTest extends PersonalisedCartTestCase {

	private const A4_WIDTH_MM = 210.0;

	private const A4_HEIGHT_MM = 297.0;

	private WC_Product_Variable $parent;

	/**
	 * Variations by size: `S` inherits the product's menu, `L` overrides it with A4 alone.
	 *
	 * @var array<string, WC_Product_Variation>
	 */
	private array $variations = array();

	public function set_up(): void {
		parent::set_up();
		$this->parent = new WC_Product_Variable();
		$this->parent->set_name( 'Personalised hoodie' );
		$attribute = new WC_Product_Attribute();
		$attribute->set_name( 'size' );
		$attribute->set_options( array( 'S', 'L' ) );
		$attribute->set_variation( true );
		$this->parent->set_attributes( array( $attribute ) );
		$this->parent->save();
		self::config()->save_to( $this->parent );
		foreach ( array( 'S', 'L' ) as $size ) {
			$variation = new WC_Product_Variation();
			$variation->set_parent_id( $this->parent->get_id() );
			$variation->set_attributes( array( 'size' => $size ) );
			$variation->set_regular_price( '30' );
			$variation->save();
			$this->variations[ $size ] = $variation;
		}
		self::a4_only()->save_to( $this->variations['L'] );
		DesignFixture::sell_variant( $this->parent );
	}

	public function test_a_size_on_the_products_menu_is_accepted_for_the_inheriting_variation(): void {
		$this->script_commit();

		$this->assertTrue( $this->add_variation( 'S' ) );

		$this->assertCount( 1, WC()->cart->get_cart() );
	}

	public function test_a_size_legal_for_one_variation_is_refused_for_another_and_its_design_deleted(): void {
		$this->script_commit();
		$this->recorder->raw( 204, '', 'application/json' );

		$this->assertFalse( $this->add_variation( 'L' ) );

		$this->assertSame( array( $this->label( Labels::DESIGN_MISMATCH ) ), self::errors() );
		$delete = $this->recorder->requests[2];
		$this->assertSame( 'DELETE', $delete['method'] );
		$this->assertSame( Hosts::server_api() . '/v1/designs/' . DesignFixture::ID, $delete['url'] );
		$this->assertTrue( WC()->cart->is_empty() );
	}

	public function test_the_overriding_variations_own_size_is_accepted(): void {
		$this->script_commit(
			array(
				'printSize' => array(
					'widthMm'  => self::A4_WIDTH_MM,
					'heightMm' => self::A4_HEIGHT_MM,
				),
			)
		);

		$this->assertTrue( $this->add_variation( 'L' ) );
	}

	/**
	 * The product's configuration with the front sold in A4 alone, overriding.
	 */
	private static function a4_only(): ProductConfig {
		$front = self::config()->sides[0];
		return new ProductConfig(
			personalise: true,
			override: true,
			blank_id: self::config()->blank_id,
			blank_name: self::config()->blank_name,
			sides: array(
				new Side( $front->key, $front->label, $front->placement_id, $front->placement_name, $front->template_base_id, $front->template_version, $front->previous_version, $front->template_name, array( new PrintSize( self::A4_WIDTH_MM, self::A4_HEIGHT_MM, 'A4' ) ) ),
			)
		);
	}

	/**
	 * Posts the product page's add-to-cart form for a variation, as
	 * WooCommerce's form handler adds one: validation, then the cart.
	 *
	 * @param string $size The variation's size.
	 */
	private function add_variation( string $size ): bool {
		$_POST[ AddToCart::FIELD ] = array( 'front' => self::TOKEN );
		$variation                 = $this->variations[ $size ];
		$attributes                = array( 'attribute_size' => $size );
		if ( ! apply_filters( 'woocommerce_add_to_cart_validation', true, $this->parent->get_id(), 1, $variation->get_id(), $attributes ) ) {
			return false;
		}
		return false !== WC()->cart->add_to_cart( $this->parent->get_id(), 1, $variation->get_id(), $attributes );
	}

	private function label( string $key ): string {
		return $this->plugin->labels()->get( $key );
	}
}
