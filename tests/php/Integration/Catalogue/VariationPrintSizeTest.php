<?php
/**
 * @package Pixonaut\WooCommerce
 */

declare(strict_types=1);

namespace Pixonaut\WooCommerce\Tests\Integration\Catalogue;

use DOMDocument;
use DOMXPath;
use Pixonaut\WooCommerce\Admin\LowResolutionWarnings;
use Pixonaut\WooCommerce\Admin\PanelFields;
use Pixonaut\WooCommerce\Admin\VariationPanel;
use Pixonaut\WooCommerce\Admin\VariationSizeField;
use Pixonaut\WooCommerce\Api\Answers\LowResolutionImage;
use Pixonaut\WooCommerce\Catalogue\InvalidDetail;
use Pixonaut\WooCommerce\Catalogue\InvalidReason;
use Pixonaut\WooCommerce\Catalogue\LowResolutionSize;
use Pixonaut\WooCommerce\Catalogue\PostedSides;
use Pixonaut\WooCommerce\Catalogue\PrintSize;
use Pixonaut\WooCommerce\Catalogue\ProductConfig;
use Pixonaut\WooCommerce\Catalogue\VariationSize;
use Pixonaut\WooCommerce\Catalogue\VariationSizes;
use Pixonaut\WooCommerce\Tests\Support\BlankFixture;
use Pixonaut\WooCommerce\Vendor\Pixonaut\Sdk\Exception\ApiException;
use WC_Product;
use WC_Product_Attribute;
use WC_Product_Variable;
use WC_Product_Variation;

/**
 * A variable product on a blank whose print areas change with its Size,
 * paired variation by variation: each variation prints at its variant's
 * area unless it is given a size of its own, which is judged for that
 * area and kept, refused or not.
 */
final class VariationPrintSizeTest extends ProductPanelTestCase {

	private const AREAS_VARY_BY = 'Size';

	/**
	 * The one side's key: its placement's, as none is entered.
	 */
	private const SIDE_KEY = self::PLACEMENT_ID;

	private const SMALL = '30x40';

	private const LARGE = '50x70';

	/**
	 * The rows' print areas, in millimetres, by size.
	 */
	private const AREAS = array(
		self::SMALL => array( 300, 400 ),
		self::LARGE => array( 508, 711.2 ),
	);

	private const LEGAL = array( 'legal' => true );

	private VariationPanel $variations;

	public function set_up(): void {
		parent::set_up();
		$this->variations                      = new VariationPanel( $this->panel, $this->plugin->templates(), $this->variant_field(), null, new VariationSizeField( new VariationSizes( $this->catalogue ), $this->plugin->templates() ) );
		$this->variants->answer                = BlankFixture::variants(
			array(
				array(
					'ref'     => 'v_small',
					'options' => array( self::AREAS_VARY_BY => self::SMALL ),
				),
				array(
					'ref'     => 'v_large',
					'options' => array( self::AREAS_VARY_BY => self::LARGE ),
				),
			),
			array( self::AREAS_VARY_BY )
		);
		$this->variants->answer['areasVaryBy'] = array( self::AREAS_VARY_BY );
	}

	public function test_a_variation_prints_at_its_variants_whole_area_with_nothing_entered(): void {
		list( , $small, $large ) = $this->linked( 'full' );

		$this->assertSame( array( 300.0, 400.0 ), $this->sold_size( $small ) );
		$this->assertSame( array( 508.0, 711.2 ), $this->sold_size( $large ) );
		$this->assertTrue( $this->reload_any( $small->get_id() )->is_purchasable() );
		$this->assertTrue( $this->reload_any( $large->get_id() )->is_purchasable() );
	}

	public function test_a_variation_prints_at_its_template_fitted_into_its_variants_usual_box(): void {
		list( , $small ) = $this->linked( 'within' );

		// The tee's 280:360 artwork in the 300 × 400 box: its width binds.
		list( $width, $height ) = $this->sold_size( $small );
		$this->assertSame( 300.0, $width );
		$this->assertEqualsWithDelta( 300 * self::PRINT_HEIGHT_MM / self::PRINT_WIDTH_MM, $height, 0.001 );
	}

	public function test_a_variation_whose_size_the_template_misfits_is_not_sold(): void {
		list( , $small, $large ) = $this->linked( 'full', array( self::LARGE ) );

		$this->assertTrue( $this->reload_any( $small->get_id() )->is_purchasable() );
		$this->assertFalse( $this->reload_any( $large->get_id() )->is_purchasable() );
		$this->assertSame( InvalidReason::VARIANT_SIZE_UNFIT, ProductConfig::config_for( $this->reload_any( $large->get_id() ) )->invalid_reason );
	}

	public function test_a_size_of_the_variations_own_saves_survives_reopening_and_is_sold(): void {
		list( , $small, $large ) = $this->linked( 'within' );
		$this->recorder->json( 200, array( 'verdicts' => array( self::LEGAL ) ) );

		$this->save_variation( $small, 0, self::custom( '210', '270', 'A4-ish' ) );

		$this->assertSame( array( 210.0, 270.0 ), $this->sold_size( $small ) );
		$this->assertSame( 'A4-ish', ProductConfig::config_for( $this->reload_any( $small->get_id() ) )->sides[0]->print_sizes[0]->label );
		$this->assertTrue( $this->reload_any( $small->get_id() )->is_purchasable() );
		$this->assertNotSame( array( 210.0, 270.0 ), $this->sold_size( $large ), 'only that variation' );
		$this->assertFalse( ProductConfig::is_stored_on( $this->reload_any( $small->get_id() ) ), 'the rest of its setup follows the product' );
		$this->assertSame(
			array(
				'kind'   => 'custom',
				'width'  => '210',
				'height' => '270',
				'label'  => 'A4-ish',
				'error'  => '',
			),
			$this->field( $small, 0 )
		);
		$last    = end( $this->recorder->requests );
		$checked = false === $last ? array() : json_decode( (string) $last['body'], true );
		$this->assertSame( array( 'sizes' ), array_keys( $checked ), 'the ratio alone is asked: the area is the row’s' );
	}

	public function test_a_variations_own_size_printing_an_image_too_soft_is_warned_of_until_accepted(): void {
		list( , $small ) = $this->linked( 'within' );
		$soft            = array(
			'layerId'        => 'photo',
			'assetId'        => 'ast_photo',
			'effectiveDpi'   => 150,
			'recommendedDpi' => 300,
		);
		$this->recorder->json( 200, array( 'verdicts' => array( self::LEGAL + array( 'lowResolution' => array( $soft ) ) ) ) );

		$this->save_variation( $small, 0, self::custom( '210', '270', 'A4-ish' ) );

		$this->assertTrue( $this->reload_any( $small->get_id() )->is_purchasable() );
		$this->assertStringContainsString( 'The image in “photo” prints at 150 dpi at A4-ish (210 × 270 mm)', $this->warnings( $small, 0 ) );

		$key = LowResolutionSize::key( self::SIDE_KEY, new PrintSize( 210, 270, 'A4-ish' ), LowResolutionImage::from_answer( $soft ) );
		$this->save_variation( $small, 0, self::custom( '210', '270', 'A4-ish' ), array( $key => LowResolutionWarnings::ACCEPT ) );

		$this->assertSame( array( $key ), LowResolutionWarnings::accepted_on( $this->reload_any( $small->get_id() ) ) );
		$this->assertSame( '', $this->warnings( $small, 0 ), 'the same size is not judged again, and its warning stays accepted' );
	}

	public function test_a_refused_size_stays_in_the_field_with_why_and_the_variation_is_not_sold(): void {
		list( , $small ) = $this->linked( 'within' );
		$requests        = count( $this->recorder->requests );

		$this->save_variation( $small, 0, self::custom( '400', '500', 'Poster' ) );

		$this->assertCount( $requests, $this->recorder->requests, 'larger than the row: Pixonaut need not be asked' );
		$this->assertFalse( $this->reload_any( $small->get_id() )->is_purchasable() );
		$this->assertSame( InvalidReason::TOO_LARGE, ProductConfig::config_for( $this->reload_any( $small->get_id() ) )->invalid_reason );
		$field = $this->field( $small, 0 );
		$this->assertSame( array( 'custom', '400', '500' ), array( $field['kind'], $field['width'], $field['height'] ) );
		$this->assertSame( InvalidReason::message( InvalidReason::TOO_LARGE, null, true ), $field['error'] );
		$sold = ProductConfig::config_for( $this->reload_any( $small->get_id() ) );
		$this->assertSame( 'Poster (400 × 500 mm)', $sold->invalid_detail?->size, 'the notice names the refused size by its name and dimensions' );
		$this->assertStringStartsWith( 'At Poster (400 × 500 mm): ', InvalidReason::stored( $sold ) );
		$this->assertStringContainsString( 'the variation’s Pixonaut fields', InvalidReason::stored( $sold ) );
	}

	public function test_a_size_pixonaut_refuses_to_judge_says_what_it_answered(): void {
		list( , $small ) = $this->linked( 'within' );
		$this->recorder->json( 403, array( 'error' => 'workspace_suspended' ) );

		$this->save_variation( $small, 0, self::custom( '210', '270' ) );

		$sold = ProductConfig::config_for( $this->reload_any( $small->get_id() ) );
		$this->assertSame( InvalidReason::CHECK_FAILED, $sold->invalid_reason );
		$this->assertSame( 'workspace_suspended', $sold->invalid_detail?->code );
		$suspended = InvalidReason::message( InvalidReason::CHECK_FAILED, new InvalidDetail( code: 'workspace_suspended' ), true );
		$this->assertStringEndsWith( $suspended, InvalidReason::stored( $sold ) );
		$this->assertSame( $suspended, $this->field( $small, 0 )['error'] );
	}

	public function test_a_size_other_than_a_whole_area_is_refused_where_the_print_covers_it(): void {
		list( , $small ) = $this->linked( 'full' );

		$this->save_variation( $small, 0, self::custom( '280', '360' ) );

		$this->assertSame( InvalidReason::PRINT_SIZE_NOT_AREA, VariationSize::stored_on( $this->reload_any( $small->get_id() ) )->refused( self::SIDE_KEY ) );
		$this->assertFalse( $this->reload_any( $small->get_id() )->is_purchasable() );
	}

	public function test_back_to_the_products_size_drops_the_own_one(): void {
		list( , $small ) = $this->linked( 'within' );
		$this->save_variation( $small, 0, self::custom( '400', '500' ) );

		$this->save_variation( $small, 0, array( 'kind' => 'product' ) );

		$this->assertSame( array(), VariationSize::stored_on( $this->reload_any( $small->get_id() ) )->sizes );
		$this->assertTrue( $this->reload_any( $small->get_id() )->is_purchasable() );
	}

	public function test_updating_the_product_judges_a_variations_own_size_again(): void {
		list( $parent, $small ) = $this->linked( 'within' );
		$this->recorder->json( 200, array( 'verdicts' => array( self::LEGAL ) ) );
		$this->save_variation( $small, 0, self::custom( '210', '270' ) );

		// The product is updated onto the template's next version, whose ratio the size is off.
		$this->script_varying_save( 'within' );
		$this->recorder->json(
			200,
			array(
				'verdicts' => array(
					array(
						'legal'  => false,
						'reason' => InvalidReason::WRONG_RATIO,
					),
				),
			)
		);
		$this->save( $parent->get_id(), array(), array( 'template' => 'mini-tee@3' ) );

		$this->assertSame( InvalidReason::WRONG_RATIO, VariationSize::stored_on( $this->reload_any( $small->get_id() ) )->refused( self::SIDE_KEY ) );
		$this->assertFalse( $this->reload_any( $small->get_id() )->is_purchasable() );
	}

	public function test_a_size_judged_for_another_template_version_is_not_sold_until_judged_again(): void {
		list( $parent, $small ) = $this->linked( 'within' );
		$this->recorder->json( 200, array( 'verdicts' => array( self::LEGAL ) ) );
		$this->save_variation( $small, 0, self::custom( '210', '270' ) );

		$config = ProductConfig::stored_on( $this->reload_any( $parent->get_id() ) );
		$moved  = $config->with_sides( array( $config->sides[0]->rolled_to( 3 ) ) );
		$moved->save_to( $this->reload_any( $parent->get_id() ) );

		$this->assertSame( InvalidReason::VARIATION_SIZE_UNCHECKED, ProductConfig::config_for( $this->reload_any( $small->get_id() ) )->invalid_reason );
	}

	public function test_an_overriding_variation_shows_no_size_field_and_drops_its_own_size(): void {
		list( , $small ) = $this->linked( 'within' );
		$this->save_variation( $small, 0, self::custom( '400', '500' ) );

		$sizes = new VariationSizeField( new VariationSizes( $this->catalogue ), $this->plugin->templates() );
		$sizes->save( $this->reload_any( $small->get_id() ), array( PanelFields::OVERRIDE => 'yes' ) );

		$this->assertSame( array(), VariationSize::stored_on( $this->reload_any( $small->get_id() ) )->sizes );
	}

	public function test_saving_the_same_size_again_asks_pixonaut_nothing(): void {
		list( , $small ) = $this->linked( 'within' );
		$this->recorder->json( 200, array( 'verdicts' => array( self::LEGAL ) ) );
		$this->save_variation( $small, 0, self::custom( '210', '270' ) );
		$requests = count( $this->recorder->requests );

		$this->save_variation( $small, 0, self::custom( '210', '270' ) );

		$this->assertCount( $requests, $this->recorder->requests );
		$this->assertTrue( $this->reload_any( $small->get_id() )->is_purchasable() );
	}

	public function test_a_custom_size_without_a_usable_width_keeps_the_one_saved(): void {
		list( , $small ) = $this->linked( 'within' );
		$this->save_variation( $small, 0, self::custom( '400', '500' ) );

		$this->save_variation( $small, 0, self::custom( '', 'abc' ) );

		$size = VariationSize::stored_on( $this->reload_any( $small->get_id() ) )->size_of( self::SIDE_KEY );
		$this->assertSame( array( 400.0, 500.0 ), array( $size?->width_mm, $size?->height_mm ) );
		$this->assertFalse( $this->reload_any( $small->get_id() )->is_purchasable() );
	}

	public function test_a_rejudge_pixonaut_cannot_answer_keeps_the_verdict_and_says_so(): void {
		list( $parent, $small ) = $this->linked( 'within' );
		$this->recorder->json( 200, array( 'verdicts' => array( self::LEGAL ) ) );
		$this->save_variation( $small, 0, self::custom( '210', '270' ) );
		$config = ProductConfig::stored_on( $this->reload_any( $parent->get_id() ) );
		$config->with_sides( array( $config->sides[0]->rolled_to( 3 ) ) )->save_to( $this->reload_any( $parent->get_id() ) );
		$this->recorder->json( 500, array( 'error' => 'db_error' ) );

		try {
			( new VariationSizes( $this->catalogue ) )->rejudge( $this->reload_any( $parent->get_id() ) );
			$this->fail( 'the caller is told to check again' );
		} catch ( ApiException $e ) {
			$this->assertSame( 500, $e->status );
		}

		$this->assertNull( VariationSize::stored_on( $this->reload_any( $small->get_id() ) )->refused( self::SIDE_KEY ), 'the verdict it had stands' );
		$this->assertSame( InvalidReason::VARIATION_SIZE_UNCHECKED, ProductConfig::config_for( $this->reload_any( $small->get_id() ) )->invalid_reason );
	}

	public function test_a_rejudge_never_writes_over_sizes_saved_meanwhile(): void {
		list( , $small ) = $this->linked( 'within' );
		$this->save_variation( $small, 0, self::custom( '400', '500' ) );
		$read = VariationSize::document_on( $this->reload_any( $small->get_id() ) );
		$this->assertNotNull( $read );
		$this->save_variation( $small, 0, array( 'kind' => 'product' ) );

		$this->assertFalse( VariationSize::from_document( $read )->replace_on( $this->reload_any( $small->get_id() ), $read ) );
		$this->assertSame( array(), VariationSize::stored_on( $this->reload_any( $small->get_id() ) )->sizes );
	}

	public function test_a_placement_with_areas_no_variant_sold_is_in_sells_nothing(): void {
		$this->variants->answer = BlankFixture::variants(
			array(
				array(
					'ref'     => 'v_huge',
					'options' => array( self::AREAS_VARY_BY => '100x140' ),
				),
			),
			array( self::AREAS_VARY_BY )
		);

		list( $parent, $small ) = $this->linked( 'within' );

		$this->assertSame( InvalidReason::VARIANT_SIZE_UNFIT, ProductConfig::stored_on( $this->reload_any( $parent->get_id() ) )->invalid_reason );
		$this->assertFalse( $this->reload_any( $small->get_id() )->is_purchasable() );
	}

	/**
	 * A custom size posted for the one side.
	 *
	 * @param string $width  Width in millimetres.
	 * @param string $height Height in millimetres.
	 * @param string $label  Its label.
	 * @return array<string, string>
	 */
	private static function custom( string $width, string $height, string $label = '' ): array {
		return array(
			'kind'   => VariationSizeField::CUSTOM,
			'width'  => $width,
			'height' => $height,
			'label'  => $label,
		);
	}

	/**
	 * The variable product with a small and a large variation, linked on
	 * the varying blank at the placement's default.
	 *
	 * @param string   $coverage `full` or `within`.
	 * @param string[] $misfits  The sizes the template cannot be printed at.
	 * @return array{WC_Product_Variable, WC_Product_Variation, WC_Product_Variation}
	 */
	private function linked( string $coverage, array $misfits = array() ): array {
		$parent    = new WC_Product_Variable();
		$attribute = new WC_Product_Attribute();
		$attribute->set_name( self::AREAS_VARY_BY );
		$attribute->set_options( array( self::SMALL, self::LARGE ) );
		$attribute->set_visible( true );
		$attribute->set_variation( true );
		$parent->set_name( 'Poster' );
		$parent->set_attributes( array( $attribute ) );
		$parent->save();
		$variations = array();
		foreach ( array( self::SMALL, self::LARGE ) as $size ) {
			$variation = new WC_Product_Variation();
			$variation->set_parent_id( $parent->get_id() );
			$variation->set_attributes( array( sanitize_title( self::AREAS_VARY_BY ) => $size ) );
			$variation->set_regular_price( '30' );
			$variation->save();
			$variations[] = $variation;
		}
		WC_Product_Variable::sync( $parent->get_id() );
		$this->script_varying_save( $coverage, $misfits );
		$this->save( $parent->get_id() );
		return array( $parent, $variations[0], $variations[1] );
	}

	/**
	 * Scripts a save of the product on the varying blank at the placement's
	 * default: the blank, the constraints, the default's verdict and the
	 * template's read.
	 *
	 * @param string   $coverage `full` or `within`.
	 * @param string[] $misfits  The sizes the template cannot be printed at.
	 */
	private function script_varying_save( string $coverage, array $misfits = array() ): void {
		$blank                              = $this->blank();
		$blank['areasVaryBy']               = array( self::AREAS_VARY_BY );
		$blank['placements'][0]['coverage'] = $coverage;
		$blank['placements'][0]['areas']    = array();
		foreach ( self::AREAS as $size => list( $width, $height ) ) {
			$blank['placements'][0]['areas'][] = array(
				'row'           => array( self::AREAS_VARY_BY => $size ),
				'widthMm'       => $width,
				'heightMm'      => $height,
				'usualWidthMm'  => $width,
				'usualHeightMm' => $height,
			);
		}
		$this->script_blank( $blank );
		$constraints                       = $this->constraints( self::PLACEMENT_ID );
		$constraints['placement']['sizes'] = array(
			'fits'    => array_values( array_diff( array_keys( self::AREAS ), $misfits ) ),
			'misfits' => $misfits,
		);
		$this->recorder->json( 200, $constraints );
		$this->recorder->json( 200, array( 'verdicts' => array( self::LEGAL ) ) );
		$this->script_template();
	}

	/**
	 * Saves a variation's panel, following its product, with a print size for its one side.
	 *
	 * @param WC_Product            $variation The variation.
	 * @param int                   $loop      Its place in the variations list.
	 * @param array<string, mixed>  $size      The size's fields.
	 * @param array<string, string> $choices  What was chosen on its warnings about soft images, by key.
	 */
	private function save_variation( WC_Product $variation, int $loop, array $size, array $choices = array() ): void {
		wp_set_current_user( $this->user( 'administrator' ) );
		$_POST = array(
			PanelFields::VARIATION_FIELD => array(
				$loop => wp_slash(
					array(
						PanelFields::NONCE             => wp_create_nonce( VariationPanel::NONCE_ACTION ),
						PanelFields::PRINT_SIZE_LISTED => '1',
						PanelFields::PRINT_SIZE        => array( self::SIDE_KEY => $size ),
						PanelFields::LOW_RESOLUTION    => $choices,
					)
				),
			),
		);
		$this->variations->save( $variation->get_id(), $loop );
		$_POST = array();
	}

	/**
	 * What a variation is sold at on its one side, width and height.
	 *
	 * @param WC_Product $variation The variation.
	 * @return array{float, float}
	 */
	private function sold_size( WC_Product $variation ): array {
		$size = ProductConfig::config_for( $this->reload_any( $variation->get_id() ) )->sides[0]->print_sizes[0];
		return array( $size->width_mm, $size->height_mm );
	}

	/**
	 * The variation's print size field as its panel shows it.
	 *
	 * @param WC_Product $variation The variation.
	 * @param int        $loop      Its place in the variations list.
	 * @return array{kind: string, width: string, height: string, label: string, error: string}
	 */
	private function field( WC_Product $variation, int $loop ): array {
		$html     = ( new VariationSizeField( new VariationSizes( $this->catalogue ), $this->plugin->templates() ) )->html( $this->reload_any( $variation->get_id() ), PanelFields::variation( $loop ), false );
		$document = new DOMDocument();
		libxml_use_internal_errors( true );
		$document->loadHTML( '<?xml encoding="utf-8"?>' . $html );
		libxml_clear_errors();
		$xpath = new DOMXPath( $document );
		$value = static fn ( string $part ): string => (string) $xpath->evaluate( 'string(//input[contains(@name, "[' . $part . ']")]/@value)' );
		return array(
			'kind'   => (string) $xpath->evaluate( 'string(//select[contains(@class, "pixonaut-variation-size-kind")]/option[@selected]/@value)' ),
			'width'  => $value( 'width' ),
			'height' => $value( 'height' ),
			'label'  => $value( 'label' ),
			'error'  => trim( (string) $xpath->evaluate( 'string(//*[contains(@class, "pixonaut-variation-size-error")])' ) ),
		);
	}

	/**
	 * The warnings about soft images the variation's print size field shows; empty for none.
	 *
	 * @param WC_Product $variation The variation.
	 * @param int        $loop      Its place in the variations list.
	 */
	private function warnings( WC_Product $variation, int $loop ): string {
		$field = new VariationSizeField( new VariationSizes( $this->catalogue ), $this->plugin->templates(), $this->panel->low_resolution() );
		$html  = $field->html( $this->reload_any( $variation->get_id() ), PanelFields::variation( $loop ), false );
		return 1 === preg_match( '/<div class="inline notice notice-warning pixonaut-low-resolution">.*<\/div>/s', $html, $match ) ? $match[0] : '';
	}

	private function reload_any( int $product_id ): WC_Product {
		$product = wc_get_product( $product_id );
		$this->assertInstanceOf( WC_Product::class, $product );
		return $product;
	}
}
