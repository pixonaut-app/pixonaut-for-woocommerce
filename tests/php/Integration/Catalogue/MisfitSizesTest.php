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

		$this->save( $product->get_id() );

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
		$this->script_link( array( 'legal' => true ) );
		$this->script_rows( array( 'des_row_s' ) );
		$this->recorder->json( 422, self::MISFIT_REFUSAL );

		$this->save( $product->get_id(), array( 'pixonaut_variant' => 'v_white_s' ), array( 'mode' => Side::STANDING ) );

		$saved  = $this->reload( $product->get_id() );
		$config = ProductConfig::stored_on( $saved );
		$this->assertTrue( $config->valid );
		$this->assertSame( array( 'S' ), array_map( static fn ( StandingRow $row ): string => $row->label(), $config->sides[0]->standing_rows ) );
		$this->assertSame( array( 'M' ), $config->sides[0]->misfit_sizes );
		$this->assertTrue( $saved->is_purchasable(), 'S has its design' );
		$this->assertStringContainsString( 'does not fit the placement at M', $this->misfit_warning( $product->get_id() ) );
	}

	public function test_a_standing_size_the_template_cannot_be_printed_at_cannot_be_bought(): void {
		$product = $this->product();
		$this->script_link( array( 'legal' => true ) );
		$this->script_rows( array( 'des_row_s' ) );
		$this->recorder->json( 422, self::MISFIT_REFUSAL );

		$this->save( $product->get_id(), array( 'pixonaut_variant' => 'v_white_m' ), array( 'mode' => Side::STANDING ) );

		$this->assertTrue( ProductConfig::stored_on( $this->reload( $product->get_id() ) )->valid );
		$this->assertFalse( $this->reload( $product->get_id() )->is_purchasable(), 'M has no design' );
	}

	public function test_a_standing_side_whose_template_fits_no_size_cannot_be_sold_and_says_why(): void {
		$product = $this->product();
		$this->script_link( array( 'legal' => true ) );
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
	 * Size: the blank, the template's constraints naming the misfits, the
	 * size's verdict and the template's read.
	 *
	 * @param string[] $misfits The sizes the template cannot be printed at.
	 * @phpstan-param list<string> $misfits
	 */
	private function script_varying_link( array $misfits ): void {
		$blank                = $this->blank();
		$blank['areasVaryBy'] = array( self::AREAS_VARY_BY );
		$this->recorder->json( 200, $blank );
		$constraints                       = $this->constraints( self::PLACEMENT_ID );
		$constraints['placement']['sizes'] = array(
			'fits'    => array_values( array_diff( array( 'S', 'M', 'XL' ), $misfits ) ),
			'misfits' => $misfits,
		);
		$this->recorder->json( 200, $constraints );
		$this->recorder->json( 200, array( 'verdicts' => array( array( 'legal' => true ) ) ) );
		$this->script_template();
	}

	/**
	 * The warning the product screen shows on the product's first side; empty when hidden.
	 *
	 * @param int $product_id The product.
	 */
	private function misfit_warning( int $product_id ): string {
		$this->recorder->json( 200, $this->blank() );
		$this->recorder->json( 200, $this->mockups() );
		$this->recorder->json( 200, self::printshops() );
		$document = new DOMDocument();
		libxml_use_internal_errors( true );
		$document->loadHTML( '<?xml encoding="utf-8"?>' . $this->panel->fields_html( $this->panel->panel( $this->reload( $product_id ) ) ) );
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
			'sizes'          => array(
				'fits'    => $fits,
				'misfits' => $misfits,
			),
			'previewUrl'     => null,
		);
	}
}
