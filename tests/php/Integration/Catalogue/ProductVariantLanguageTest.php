<?php
/**
 * @package Pixonaut\WooCommerce
 */

declare(strict_types=1);

namespace Pixonaut\WooCommerce\Tests\Integration\Catalogue;

use Pixonaut\WooCommerce\Catalogue\ProductVariant;
use Pixonaut\WooCommerce\Tests\Support\BlankFixture;
use WC_Product_Attribute;

/**
 * A blank's variants are read in the site's language: a product stores its
 * variant's options and views as named in it, and falls back to the names
 * as written when the blank has none in it or Pixonaut lacks the language.
 */
final class ProductVariantLanguageTest extends ProductPanelTestCase {

	private const LEGAL = array( 'legal' => true );

	private const SWEDISH_LOCALE = 'sv_SE';

	/**
	 * The site's language as set under Settings. WordPress keeps only an
	 * installed language there, so the test answers for the option instead.
	 *
	 * @var string
	 */
	private string $site_locale = self::SWEDISH_LOCALE;

	public function set_up(): void {
		parent::set_up();
		$this->variants->answer = self::red( 'Colour', 'Red', 'Front' );
		add_filter( 'pre_option_WPLANG', array( $this, 'site_locale' ) );
	}

	public function tear_down(): void {
		remove_filter( 'pre_option_WPLANG', array( $this, 'site_locale' ) );
		parent::tear_down();
	}

	/**
	 * The site's language for the test.
	 */
	public function site_locale(): string {
		return $this->site_locale;
	}

	public function test_a_product_stores_its_variants_names_in_the_sites_language(): void {
		$this->variants->in_language['sv'] = self::red( 'Färg', 'Röd', 'Framsida' );

		$variant = $this->linked( 'Färg', 'röd' );

		$this->assertSame( array( 'v_red', 'Färg: Röd', 'Framsida' ), array( $variant->ref, $variant->label, $variant->views[0]['label'] ?? null ) );
		$this->assertSame( array( 'sv', null ), $this->variants->languages );
	}

	public function test_attributes_naming_the_options_as_written_still_match_and_store_the_sites_names(): void {
		$this->variants->in_language['sv'] = self::red( 'Färg', 'Röd', 'Framsida' );

		$variant = $this->linked( 'Colour', 'Red' );

		$this->assertSame( array( 'v_red', 'Färg: Röd', 'Framsida' ), array( $variant->ref, $variant->label, $variant->views[0]['label'] ?? null ) );
		$this->assertSame( array( 'sv', null ), $this->variants->languages );
	}

	public function test_a_blank_without_names_in_the_sites_language_stores_them_as_written(): void {
		$variant = $this->linked( 'Colour', 'Red' );

		$this->assertSame( array( 'v_red', 'Colour: Red', 'Front' ), array( $variant->ref, $variant->label, $variant->views[0]['label'] ?? null ) );
		$this->assertSame( array( 'sv', null ), $this->variants->languages );
	}

	public function test_a_site_in_a_language_pixonaut_lacks_reads_the_names_as_written(): void {
		$this->site_locale                 = 'pl_PL';
		$this->variants->in_language['sv'] = self::red( 'Färg', 'Röd', 'Framsida' );

		$variant = $this->linked( 'Colour', 'Red' );

		$this->assertSame( array( 'v_red', 'Colour: Red' ), array( $variant->ref, $variant->label ) );
		$this->assertSame( array( null ), $this->variants->languages );
	}

	public function test_the_sites_language_holds_whatever_language_the_request_runs_in(): void {
		$this->variants->in_language['sv'] = self::red( 'Färg', 'Röd', 'Framsida' );
		$english                           = static fn (): string => 'en_US';
		add_filter( 'locale', $english );

		try {
			$variant = $this->linked( 'Färg', 'Röd' );
		} finally {
			remove_filter( 'locale', $english );
		}

		$this->assertSame( 'Färg: Röd', $variant->label );
		$this->assertSame( 'sv', $this->variants->languages[0] ?? null );
	}

	public function test_a_name_in_the_sites_language_equal_to_another_variants_name_as_written_matches_neither(): void {
		$colours                           = static fn ( string $red, string $navy ): array => BlankFixture::variants(
			array(
				array(
					'ref'     => 'v_red',
					'options' => array( 'Colour' => $red ),
				),
				array(
					'ref'     => 'v_navy',
					'options' => array( 'Colour' => $navy ),
				),
			),
			array( 'Colour' )
		);
		$this->variants->answer            = $colours( 'Red', 'Blue' );
		$this->variants->in_language['sv'] = $colours( 'Blue', 'Marin' );

		$this->assertNull( $this->linked( 'Colour', 'Blue' )->ref );
	}

	/**
	 * A simple product with one attribute, linked in its Pixonaut tab, and
	 * the variant it stored.
	 *
	 * @param string $name  The attribute's name.
	 * @param string $value Its one value.
	 */
	private function linked( string $name, string $value ): ProductVariant {
		$product   = $this->product();
		$attribute = new WC_Product_Attribute();
		$attribute->set_name( $name );
		$attribute->set_options( array( $value ) );
		$attribute->set_visible( true );
		$product->set_attributes( array( $attribute ) );
		$product->save();
		$this->variants->languages = array();
		$this->script_link( self::LEGAL );
		$this->save( $product->get_id() );
		$variant = ProductVariant::stored_on( $this->reload( $product->get_id() ) );
		$this->assertInstanceOf( ProductVariant::class, $variant );
		return $variant;
	}

	/**
	 * A blank of one red variant, named as given.
	 *
	 * @param string $axis   Its colour axis.
	 * @param string $colour Its colour.
	 * @param string $view   Its view's label.
	 * @return array<string, mixed>
	 */
	private static function red( string $axis, string $colour, string $view ): array {
		return BlankFixture::variants(
			array(
				array(
					'ref'     => 'v_red',
					'options' => array( $axis => $colour ),
					'views'   => array(
						array(
							'placementId' => self::PLACEMENT_ID,
							'viewId'      => BlankFixture::VIEW_ID,
							'label'       => $view,
						),
					),
				),
			),
			array( $axis )
		);
	}
}
