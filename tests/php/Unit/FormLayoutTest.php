<?php
/**
 * @package Pixonaut\WooCommerce
 */

declare(strict_types=1);

namespace Pixonaut\WooCommerce\Tests\Unit;

use Brain\Monkey\Functions;
use Pixonaut\WooCommerce\Catalogue\ProductConfig;
use Pixonaut\WooCommerce\FormLayout;
use Pixonaut\WooCommerce\Settings\Settings;

final class FormLayoutTest extends TestCase {

	use FakeOptions;

	protected function setUp(): void {
		parent::setUp();
		$this->fake_options();
		Functions\stubTranslationFunctions();
	}

	public function test_by_default_the_template_chooses(): void {
		$settings = new Settings();

		$this->assertSame( FormLayout::TEMPLATE, $settings->get( Settings::FORM_LAYOUT ) );
		$this->assertNull( FormLayout::resolve( new ProductConfig( personalise: true ), $settings ) );
	}

	public function test_the_stores_layout_applies_to_a_product_without_its_own(): void {
		$settings = new Settings();
		$settings->set( Settings::FORM_LAYOUT, FormLayout::STEPS );

		$this->assertSame( FormLayout::STEPS, FormLayout::resolve( new ProductConfig( personalise: true ), $settings ) );
	}

	public function test_a_products_layout_outranks_the_stores(): void {
		$settings = new Settings();
		$settings->set( Settings::FORM_LAYOUT, FormLayout::STEPS );
		$product = new ProductConfig( personalise: true );

		$this->assertSame( FormLayout::PAGE, FormLayout::resolve( $product->with_form_layout( FormLayout::PAGE ), $settings ) );
		$this->assertNull( FormLayout::resolve( $product->with_form_layout( FormLayout::TEMPLATE ), $settings ) );
	}

	public function test_an_unknown_layout_follows_the_store(): void {
		$this->options[ Settings::OPTION ] = array( Settings::FORM_LAYOUT => 'carousel' );

		$this->assertSame( FormLayout::TEMPLATE, ( new Settings() )->get( Settings::FORM_LAYOUT ) );
		$this->assertNull( FormLayout::resolve( ( new ProductConfig( personalise: true ) )->with_form_layout( 'carousel' ), new Settings() ) );
	}
}
