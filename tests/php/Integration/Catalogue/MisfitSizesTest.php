<?php
/**
 * @package Pixonaut\WooCommerce
 */

declare(strict_types=1);

namespace Pixonaut\WooCommerce\Tests\Integration\Catalogue;

use DOMDocument;
use DOMXPath;
use Pixonaut\WooCommerce\Admin\CatalogueSearch;
use Pixonaut\WooCommerce\Api\Catalogue;
use Pixonaut\WooCommerce\Vendor\Pixonaut\Sdk\Generated\ErrorCode;
use Pixonaut\WooCommerce\Catalogue\InvalidReason;
use Pixonaut\WooCommerce\Catalogue\ProductConfig;
use Pixonaut\WooCommerce\Catalogue\Side;
use Pixonaut\WooCommerce\Catalogue\StandingRow;
use Pixonaut\WooCommerce\Plugin;
use Pixonaut\WooCommerce\Tests\Support\BlankFixture;
use Pixonaut\WooCommerce\Tests\Support\VerdictAnswers;
use WC_Product;
use WC_Product_Attribute;
use WC_Product_Variable;
use WC_Product_Variation;
use WP_REST_Request;

/**
 * On a blank whose print areas change with an option, a template can fit
 * the placement at some sizes only. Linking it says which, and the product's
 * panel keeps saying so; a standing side sells the sizes it fits.
 */
final class MisfitSizesTest extends StandingTestCase {

	/**
	 * Pixonaut's refusal of a design whose print does not fit the row's panel.
	 */
	private const MISFIT_REFUSAL = array(
		'error'      => ErrorCode::CONSTRAINT_VIOLATION,
		'violations' => array(
			array(
				'layerId'  => '',
				'prop'     => 'placementId',
				'rule'     => InvalidReason::WRONG_RATIO,
				'severity' => 'error',
				'given'    => array(
					'widthMm'  => 280,
					'heightMm' => 360,
				),
			),
		),
	);

	/**
	 * The rows of the tee blank, whose print areas change with Size.
	 */
	private const TEE_ROWS = array( array( 'S' ), array( 'M' ), array( 'XL' ) );

	/**
	 * The options the poster blank's print areas change with.
	 */
	private const POSTER_OPTIONS = array( 'Size', 'Orientation' );

	/**
	 * The rows of the poster blank: each size upright and landscape.
	 */
	private const POSTER_ROWS = array(
		array( '30x40', 'Vertical' ),
		array( '30x40', 'Horizontal' ),
		array( '50x70', 'Vertical' ),
		array( '50x70', 'Horizontal' ),
	);

	public function test_the_template_search_names_the_sizes_a_template_cannot_be_printed_at(): void {
		wp_set_current_user( $this->user( 'shop_manager' ) );
		$this->recorder->json(
			200,
			array(
				'items'    => array(
					self::fitting_template( 'mini-tee@2', 'Mini tee', array( 'S', 'M' ), array( 'XL', '3XL' ) ),
					self::fitting_template( 'tote@1', 'Tote', array(), array() ),
				),
				'total'    => 2,
				'page'     => 1,
				'pageSize' => Catalogue::SEARCH_PAGE_SIZE,
			)
		);

		$request = new WP_REST_Request( 'GET', '/' . Plugin::REST_NAMESPACE . CatalogueSearch::FITTING_TEMPLATES_ROUTE );
		$request->set_query_params(
			array(
				'blank'     => self::BLANK_ID,
				'placement' => self::PLACEMENT_ID,
			)
		);
		$results = rest_get_server()->dispatch( $request )->get_data()['results'];

		$this->assertSame( array( 'XL', '3XL' ), $results[0]['misfits'] );
		$this->assertSame( 'Cannot print at XL and 3XL', $results[0]['note'] );
		$this->assertStringContainsString( 'does not fit the placement at XL and 3XL', $results[0]['warning'] );
		$this->assertSame( array(), $results[1]['misfits'], 'a template that fits every size names none' );
		$this->assertSame( '', $results[1]['note'] );
		$this->assertSame( '', $results[1]['warning'] );
	}

	public function test_a_personalised_side_keeps_the_sizes_its_template_misfits_and_the_panel_names_them(): void {
		$product = $this->product();
		$this->script_varying_link( array( 'XL' ) );

		$this->save( $product->get_id(), array( 'pixonaut_variant' => 'v_xl' ) );

		$config = ProductConfig::stored_on( $this->reload( $product->get_id() ) );
		$this->assertTrue( $config->valid, 'the sizes it fits still sell' );
		$this->assertSame( array( 'XL' ), $config->sides[0]->misfit_sizes );
		$this->assertCount( 4, $this->recorder->requests, 'the constraints read for the default size names the misfits too' );
		$this->assertStringContainsString( 'does not fit the placement at XL', $this->misfit_warning( $product->get_id() ) );
	}

	public function test_the_warning_goes_once_the_template_fits_every_size(): void {
		$product = $this->product();
		$this->script_varying_link( array( 'XL' ) );
		$this->save( $product->get_id() );

		$this->script_varying_link( array() );
		$this->save( $product->get_id() );

		$this->assertSame( array(), ProductConfig::stored_on( $this->reload( $product->get_id() ) )->sides[0]->misfit_sizes );
		$this->assertSame( '', $this->misfit_warning( $product->get_id() ) );
	}

	public function test_a_save_pixonaut_cannot_check_keeps_the_misfits_it_knew(): void {
		$product = $this->product();
		$this->script_varying_link( array( 'XL' ) );
		$this->save( $product->get_id() );

		// The constraints read fails, then the blank read.
		$blank                = $this->blank();
		$blank['areasVaryBy'] = array( self::AREAS_VARY_BY );
		$this->recorder->json( 200, $blank );
		$this->recorder->json( 500, array( 'error' => ErrorCode::DB_ERROR ) );
		$this->save( $product->get_id() );
		$after_check = ProductConfig::stored_on( $this->reload( $product->get_id() ) );

		$this->recorder->json( 500, array( 'error' => ErrorCode::DB_ERROR ) );
		$this->save( $product->get_id() );
		$after_blank = ProductConfig::stored_on( $this->reload( $product->get_id() ) );

		$this->assertSame( InvalidReason::CHECK_FAILED, $after_check->invalid_reason );
		$this->assertSame( array( 'XL' ), $after_check->sides[0]->misfit_sizes );
		$this->assertSame( InvalidReason::CHECK_FAILED, $after_blank->invalid_reason );
		$this->assertSame( array( 'XL' ), $after_blank->sides[0]->misfit_sizes );
	}

	public function test_another_template_forgets_the_misfits_of_the_one_before(): void {
		$product = $this->product();
		$this->script_varying_link( array( 'XL' ) );
		$this->save( $product->get_id() );

		$blank                = $this->blank();
		$blank['areasVaryBy'] = array( self::AREAS_VARY_BY );
		$this->recorder->json( 200, $blank );
		$this->recorder->json( 500, array( 'error' => ErrorCode::DB_ERROR ) );
		$this->save( $product->get_id(), array(), array( 'template' => 'mini-tee@3' ) );

		$this->assertSame( array(), ProductConfig::stored_on( $this->reload( $product->get_id() ) )->sides[0]->misfit_sizes );
	}

	public function test_a_standing_side_sells_the_sizes_it_fits_and_names_the_one_it_does_not(): void {
		$product = $this->product();
		$this->script_link( VerdictAnswers::LEGAL );
		$this->script_rows( array( 'des_row_s' ) );
		$this->recorder->json( 422, self::MISFIT_REFUSAL );

		$this->save( $product->get_id(), array( 'pixonaut_variant' => 'v_white_s' ), array( 'mode' => Side::STANDING ) );

		$saved  = $this->reload( $product->get_id() );
		$config = ProductConfig::stored_on( $saved );
		$this->assertTrue( $config->valid );
		$this->assertSame( array( 'S' ), array_map( static fn ( StandingRow $row ): string => $row->label(), $config->sides[0]->standing_rows ) );
		$this->assertSame( array( 'M' ), $config->sides[0]->misfit_sizes );
		$this->assertTrue( $saved->is_purchasable(), 'S has its design' );
		$this->assertSame( '', $this->misfit_warning( $product->get_id() ), 'the product sells S alone, which the template fits' );
	}

	public function test_a_standing_size_the_template_cannot_be_printed_at_cannot_be_bought(): void {
		$product = $this->product();
		$this->script_link( VerdictAnswers::LEGAL );
		$this->script_rows( array( 'des_row_s' ) );
		$this->recorder->json( 422, self::MISFIT_REFUSAL );

		$this->save( $product->get_id(), array( 'pixonaut_variant' => 'v_white_m' ), array( 'mode' => Side::STANDING ) );

		$this->assertTrue( ProductConfig::stored_on( $this->reload( $product->get_id() ) )->valid );
		$this->assertFalse( $this->reload( $product->get_id() )->is_purchasable(), 'M has no design' );
		$this->assertStringContainsString( 'does not fit the placement at M', $this->misfit_warning( $product->get_id() ) );
	}

	public function test_a_simple_product_is_warned_only_about_the_size_it_sells(): void {
		$product = $this->product();
		$this->script_varying_link( array( 'M', 'XL' ) );
		$this->save( $product->get_id(), array( 'pixonaut_variant' => 'v_xl' ) );

		$this->assertSame( array( 'M', 'XL' ), ProductConfig::stored_on( $this->reload( $product->get_id() ) )->sides[0]->misfit_sizes );
		$warning = $this->misfit_warning( $product->get_id() );
		$this->assertStringContainsString( 'does not fit the placement at XL:', $warning );

		$this->script_varying_link( array( 'M', 'XL' ) );
		$this->save( $product->get_id(), array( 'pixonaut_variant' => 'v_s' ) );

		$this->assertSame( '', $this->misfit_warning( $product->get_id() ), 'it sells S now, which the template fits' );
	}

	public function test_a_product_selling_only_upright_sizes_is_not_warned_about_the_landscape_ones(): void {
		$poster  = $this->poster( array( array( '30x40', 'Vertical' ), array( '50x70', 'Vertical' ) ) );
		$misfits = array( '30x40 / Horizontal', '50x70 / Horizontal' );
		$this->script_varying_link( $misfits, self::POSTER_OPTIONS );

		$this->save( $poster->get_id() );

		$saved = wc_get_product( $poster->get_id() );
		$this->assertInstanceOf( WC_Product::class, $saved );
		$this->assertSame( $misfits, ProductConfig::stored_on( $saved )->sides[0]->misfit_sizes );
		$this->assertSame( '', $this->misfit_warning( $poster->get_id(), self::POSTER_OPTIONS ) );
	}

	public function test_a_variable_product_is_warned_about_the_sizes_its_variations_sell(): void {
		$poster = $this->poster( array( array( '30x40', 'Vertical' ), array( '30x40', 'Horizontal' ) ) );
		$this->script_varying_link( array( '30x40 / Horizontal', '50x70 / Horizontal' ), self::POSTER_OPTIONS );

		$this->save( $poster->get_id() );

		$this->assertStringContainsString( 'does not fit the placement at 30x40 / Horizontal:', $this->misfit_warning( $poster->get_id(), self::POSTER_OPTIONS ) );
	}

	public function test_a_variation_with_its_own_setup_is_not_judged_by_its_products(): void {
		$poster = $this->poster( array( array( '30x40', 'Vertical' ), array( '30x40', 'Horizontal' ) ) );
		$this->script_varying_link( array( '30x40 / Horizontal', '50x70 / Horizontal' ), self::POSTER_OPTIONS );
		$this->save( $poster->get_id() );

		$landscape = wc_get_product( $poster->get_children()[1] );
		$this->assertInstanceOf( WC_Product_Variation::class, $landscape );
		ProductConfig::stored_on( $landscape )->with_override( true )->save_to( $landscape );

		$this->assertSame( '', $this->misfit_warning( $poster->get_id(), self::POSTER_OPTIONS ), 'the one variation following the product is upright' );
	}

	public function test_the_template_search_for_a_product_names_only_the_sizes_it_sells(): void {
		$product = $this->product();
		$this->script_varying_link( array() );
		$this->save( $product->get_id(), array( 'pixonaut_variant' => 'v_xl' ) );
		$this->recorder->json(
			200,
			array(
				'items'    => array(
					self::fitting_template( 'mini-tee@2', 'Mini tee', array( 'S' ), array( 'M', 'XL' ) ),
					self::fitting_template( 'tote@1', 'Tote', array( 'XL' ), array( 'S', 'M' ) ),
				),
				'total'    => 2,
				'page'     => 1,
				'pageSize' => Catalogue::SEARCH_PAGE_SIZE,
			)
		);
		$this->recorder->json( 200, $this->varying_blank( array( self::AREAS_VARY_BY ) ) );

		$request = new WP_REST_Request( 'GET', '/' . Plugin::REST_NAMESPACE . CatalogueSearch::FITTING_TEMPLATES_ROUTE );
		$request->set_query_params(
			array(
				'blank'     => self::BLANK_ID,
				'placement' => self::PLACEMENT_ID,
				'product'   => (string) $product->get_id(),
			)
		);
		$results = rest_get_server()->dispatch( $request )->get_data()['results'];

		$this->assertSame( array( 'XL' ), $results[0]['misfits'] );
		$this->assertSame( 'Cannot print at XL', $results[0]['note'] );
		$this->assertStringContainsString( 'does not fit the placement at XL:', $results[0]['warning'] );
		$this->assertSame( 'mini-tee@2', $results[0]['id'] );
		$this->assertSame( array(), $results[1]['misfits'], 'it misfits only sizes the product does not sell' );
		$this->assertSame( '', $results[1]['note'] );
		$this->assertSame( '', $results[1]['warning'] );
	}

	public function test_a_standing_side_whose_template_fits_no_size_cannot_be_sold_and_says_why(): void {
		$product = $this->product();
		$this->script_link( VerdictAnswers::LEGAL );
		$this->script_rows( array() );
		$this->recorder->json( 422, self::MISFIT_REFUSAL );
		$this->recorder->json( 422, self::MISFIT_REFUSAL );

		$this->save( $product->get_id(), array(), array( 'mode' => Side::STANDING ) );

		$config = ProductConfig::stored_on( $this->reload( $product->get_id() ) );
		$this->assertFalse( $config->valid );
		$this->assertSame( InvalidReason::NO_FITTING_SIZE, $config->invalid_reason );
		$this->assertSame( array( 'S', 'M' ), $config->sides[0]->misfit_sizes );
	}

	/**
	 * Scripts a one-sided save on a blank whose print areas change with
	 * some options: the blank, the template's constraints naming the
	 * misfits, the size's verdict and the template's read. The blank's
	 * variants are one per row.
	 *
	 * @param string[] $misfits   The rows the template cannot be printed at.
	 * @param string[] $varies_by The options the print areas change with.
	 * @phpstan-param list<string> $misfits
	 * @phpstan-param list<string> $varies_by
	 */
	private function script_varying_link( array $misfits, array $varies_by = array( self::AREAS_VARY_BY ) ): void {
		$rows     = self::POSTER_OPTIONS === $varies_by ? self::POSTER_ROWS : self::TEE_ROWS;
		$variants = array();
		foreach ( $rows as $row ) {
			$variants[] = array(
				'ref'     => 'v_' . strtolower( implode( '_', $row ) ),
				'options' => array_combine( $varies_by, $row ),
			);
		}
		$this->variants->answer = array( 'areasVaryBy' => $varies_by ) + BlankFixture::variants( $variants, $varies_by );
		$this->recorder->json( 200, $this->varying_blank( $varies_by ) );
		$constraints                       = $this->constraints( self::PLACEMENT_ID );
		$named                             = array_map( static fn ( array $row ): string => implode( ' / ', $row ), $rows );
		$constraints['placement']['sizes'] = array(
			'fits'    => array_values( array_diff( $named, $misfits ) ),
			'misfits' => $misfits,
		);
		$this->recorder->json( 200, $constraints );
		$this->recorder->json( 200, array( 'verdicts' => array( VerdictAnswers::LEGAL ) ) );
		$this->script_template();
	}

	/**
	 * The blank, its print areas changing with some options.
	 *
	 * @param string[] $varies_by The options.
	 * @phpstan-param list<string> $varies_by
	 * @return array<string, mixed>
	 */
	private function varying_blank( array $varies_by ): array {
		$blank                = $this->blank();
		$blank['areasVaryBy'] = $varies_by;
		return $blank;
	}

	/**
	 * A variable poster with a variation per size and orientation given.
	 *
	 * @param list<array{string, string}> $sold Each variation's size and orientation.
	 */
	private function poster( array $sold ): WC_Product_Variable {
		$poster     = new WC_Product_Variable();
		$attributes = array();
		foreach ( self::POSTER_OPTIONS as $at => $name ) {
			$attribute = new WC_Product_Attribute();
			$attribute->set_name( $name );
			$attribute->set_options( array_values( array_unique( array_column( self::POSTER_ROWS, $at ) ) ) );
			$attribute->set_visible( true );
			$attribute->set_variation( true );
			$attributes[] = $attribute;
		}
		$poster->set_name( 'Poster' );
		$poster->set_attributes( $attributes );
		$poster->save();
		foreach ( $sold as $row ) {
			$variation = new WC_Product_Variation();
			$variation->set_parent_id( $poster->get_id() );
			$variation->set_attributes( array_combine( array_map( 'sanitize_title', self::POSTER_OPTIONS ), $row ) );
			$variation->set_regular_price( '30' );
			$variation->save();
		}
		WC_Product_Variable::sync( $poster->get_id() );
		$synced = wc_get_product( $poster->get_id() );
		$this->assertInstanceOf( WC_Product_Variable::class, $synced );
		return $synced;
	}

	/**
	 * The warning the product screen shows on the product's first side; empty when hidden.
	 *
	 * @param int      $product_id The product.
	 * @param string[] $varies_by  The options its blank's print areas change with.
	 * @phpstan-param list<string> $varies_by
	 */
	private function misfit_warning( int $product_id, array $varies_by = array( self::AREAS_VARY_BY ) ): string {
		$product = wc_get_product( $product_id );
		$this->assertInstanceOf( WC_Product::class, $product );
		$this->recorder->json( 200, $this->blank() );
		$this->recorder->json( 200, $this->mockups() );
		if ( array() !== ProductConfig::stored_on( $product )->sides[0]->misfit_sizes ) {
			// The rows the product sells are told from the options the blank's print areas change with.
			$this->recorder->json( 200, $this->varying_blank( $varies_by ) );
		}
		$this->recorder->json( 200, self::printshops() );
		$document = new DOMDocument();
		libxml_use_internal_errors( true );
		$document->loadHTML( '<?xml encoding="utf-8"?>' . $this->panel->fields_html( $this->panel->panel( $product ) ) );
		libxml_clear_errors();
		$xpath = new DOMXPath( $document );
		return trim( (string) $xpath->evaluate( 'string(//div[contains(@class, "pixonaut-sides")]//div[contains(@class, "pixonaut-misfit-warning")][not(@hidden)])' ) );
	}

	/**
	 * A fitting-templates item, legal at its default size.
	 *
	 * @param string   $id      `<baseId>@<version>`.
	 * @param string   $name    Its name.
	 * @param string[] $fits    The sizes it fits.
	 * @param string[] $misfits The sizes it does not.
	 * @return array<string, mixed>
	 */
	private static function fitting_template( string $id, string $name, array $fits, array $misfits ): array {
		return array(
			'templateId'     => $id,
			'name'           => $name,
			'status'         => 'live',
			'ratio'          => array(
				'w' => self::PRINT_WIDTH_MM,
				'h' => self::PRINT_HEIGHT_MM,
			),
			'ratioTolerance' => 0,
			'printWidthMm'   => self::PRINT_WIDTH_MM,
			'printHeightMm'  => self::PRINT_HEIGHT_MM,
			'verdict'        => array( 'legal' => true ),
			'lowResolution'  => array(),
			'sizes'          => array(
				'fits'    => $fits,
				'misfits' => $misfits,
			),
			'previewUrl'     => null,
		);
	}
}
