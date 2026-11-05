<?php
/**
 * @package Pixonaut\WooCommerce
 */

declare(strict_types=1);

namespace Pixonaut\WooCommerce\Tests\Integration\Catalogue;

use DOMDocument;
use DOMElement;
use DOMXPath;
use Pixonaut\WooCommerce\Admin\ProductPanel;
use Pixonaut\WooCommerce\Api\ErrorCodes;
use Pixonaut\WooCommerce\Catalogue\InvalidReason;
use Pixonaut\WooCommerce\Catalogue\ProductConfig;
use Pixonaut\WooCommerce\Catalogue\Side;
use Pixonaut\WooCommerce\Catalogue\StandingRow;
use Pixonaut\WooCommerce\Tests\Support\OpenApiAssert;

/**
 * A side set to standing is printed from the store's standing design,
 * found or created when the product is saved.
 */
final class ProductStandingTest extends StandingTestCase {

	private const BACK_ID = 'back';

	private const OTHER_STANDING_ID = 'des_standing_2';

	/**
	 * The template's next version, which every row follows.
	 */
	private const NEXT_TEMPLATE_ID = 'mini-tee@3';

	/**
	 * Pixonaut's refusal of a template version that does not exist.
	 */
	private const CONSTRAINT_VIOLATION = 'constraint_violation';

	public function test_saving_a_standing_side_finds_its_approved_design_and_keeps_it_on_the_side(): void {
		$product = $this->product();
		$this->script_link( array( 'legal' => true ) );
		$this->script_standing();

		$this->save( $product->get_id(), array(), array( 'mode' => Side::STANDING ) );

		$saved = $this->reload( $product->get_id() );
		$side  = ProductConfig::stored_on( $saved )->sides[0];
		$this->assertTrue( $side->is_standing() );
		$this->assertSame( self::STANDING_ID, $side->standing_design_id );
		$this->assertTrue( ProductConfig::stored_on( $saved )->valid );
		$this->assertTrue( $saved->is_purchasable() );
		$this->assertSame(
			array(
				'templateId'  => self::TEMPLATE_ID,
				'approve'     => true,
				'blankId'     => self::BLANK_ID,
				'placementId' => self::PLACEMENT_ID,
				'widthMm'     => self::PRINT_WIDTH_MM,
				'heightMm'    => self::PRINT_HEIGHT_MM,
			),
			$this->standing_requests()[0]
		);
	}

	public function test_saving_again_unchanged_finds_the_same_design(): void {
		$product = $this->product();
		foreach ( array( 1, 2 ) as $save ) {
			$this->script_link( array( 'legal' => true ) );
			$this->script_standing();
			$this->save( $product->get_id(), array(), array( 'mode' => Side::STANDING ) );
		}

		$requests = $this->standing_requests();
		$this->assertCount( 2, $requests );
		$this->assertSame( $requests[0], $requests[1] );
		$this->assertSame( self::STANDING_ID, ProductConfig::stored_on( $this->reload( $product->get_id() ) )->sides[0]->standing_design_id );
	}

	public function test_a_placement_change_finds_the_design_for_the_new_placement(): void {
		$product    = $this->product();
		$placements = array(
			self::PLACEMENT_ID => 'Front',
			self::BACK_ID      => 'Back',
		);
		$this->script_link( array( 'legal' => true ) );
		$this->script_standing();
		$this->save( $product->get_id(), array(), array( 'mode' => Side::STANDING ) );

		$this->recorder->json( 200, $this->blank( $placements ) );
		$this->recorder->json( 200, $this->constraints( self::BACK_ID ) );
		$this->recorder->json( 200, array( 'verdicts' => array( array( 'legal' => true ) ) ) );
		$this->script_template();
		$this->script_standing( self::OTHER_STANDING_ID, self::BACK_ID );
		$this->save(
			$product->get_id(),
			array(),
			array(
				'mode'      => Side::STANDING,
				'placement' => self::BACK_ID,
			)
		);

		$this->assertSame( self::BACK_ID, $this->standing_requests()[1]['placementId'] );
		$this->assertSame( self::OTHER_STANDING_ID, ProductConfig::stored_on( $this->reload( $product->get_id() ) )->sides[0]->standing_design_id );
	}

	public function test_a_blank_whose_areas_vary_gets_a_design_per_row_of_the_variants_it_sells(): void {
		$product = $this->product();
		$this->script_link( array( 'legal' => true ) );
		$this->script_rows( array( 'des_row_s', 'des_row_m' ) );

		$this->save( $product->get_id(), array(), array( 'mode' => Side::STANDING ) );

		$saved = $this->reload( $product->get_id() );
		$side  = ProductConfig::stored_on( $saved )->sides[0];
		$this->assertTrue( ProductConfig::stored_on( $saved )->valid );
		$this->assertNull( $side->standing_design_id );
		$this->assertSame(
			array(
				array( 'S', 'des_row_s', array( 'v_white_s', 'v_black_s' ), true ),
				array( 'M', 'des_row_m', array( 'v_white_m' ), false ),
			),
			array_map( static fn ( StandingRow $row ): array => array( $row->label(), $row->design_id, $row->variants, $row->usual ), $side->standing_rows ),
			'the retired variant is in no row; the side\'s size does not fit S'
		);
		$this->assertSame( self::ROW_PROOF, $side->standing_rows[0]->proof_id );
		$this->assertSame( '160 × 200 mm', $side->standing_rows[0]->size?->label );
		$requests = $this->standing_requests();
		$this->assertCount( 3, $requests );
		$this->assertArrayNotHasKey( 'variantRow', $requests[0] );
		$this->assertSame(
			array(
				'templateId'  => self::TEMPLATE_ID,
				'approve'     => true,
				'blankId'     => self::BLANK_ID,
				'placementId' => self::PLACEMENT_ID,
				'variantRow'  => array( self::AREAS_VARY_BY => 'S' ),
			),
			$requests[1],
			'S prints at its usual size'
		);
		$this->assertSame( array( self::AREAS_VARY_BY => 'M' ), $requests[2]['variantRow'] );
		$this->assertSame( self::PRINT_WIDTH_MM, $requests[2]['widthMm'] );
	}

	public function test_saving_again_keeps_every_row_in_step_with_the_side(): void {
		$product = $this->product();
		$this->script_link( array( 'legal' => true ) );
		$this->script_rows( array( 'des_row_s', 'des_row_m' ) );
		$this->save( $product->get_id(), array(), array( 'mode' => Side::STANDING ) );

		$this->script_link( array( 'legal' => true ) );
		$this->script_rows( array( 'des_row_s3', 'des_row_m3' ) );
		$this->save(
			$product->get_id(),
			array(),
			array(
				'mode'     => Side::STANDING,
				'template' => self::NEXT_TEMPLATE_ID,
			)
		);

		$side = ProductConfig::stored_on( $this->reload( $product->get_id() ) )->sides[0];
		$this->assertSame( array( 'des_row_s3', 'des_row_m3' ), array_map( static fn ( StandingRow $row ): string => $row->design_id, $side->standing_rows ) );
		$this->assertSame( array_fill( 0, 3, self::NEXT_TEMPLATE_ID ), array_column( array_slice( $this->standing_requests(), 3 ), 'templateId' ) );
	}

	public function test_saving_again_keeps_the_previews_of_designs_found_again_without_reading_them(): void {
		$product = $this->product();
		$this->script_link( array( 'legal' => true ) );
		$this->script_rows( array( 'des_row_s', 'des_row_m' ) );
		$this->save( $product->get_id(), array(), array( 'mode' => Side::STANDING ) );

		$this->script_link( array( 'legal' => true ) );
		$this->script_rows( array( 'des_row_s', 'des_row_m' ), 'known' );
		$this->save( $product->get_id(), array(), array( 'mode' => Side::STANDING ) );

		$reads = array_filter( $this->recorder->requests, static fn ( array $request ): bool => 'GET' === $request['method'] && str_contains( $request['url'], '/v1/designs/' ) );
		$this->assertCount( 2, $reads, 'read on the first save only' );
		$side = ProductConfig::stored_on( $this->reload( $product->get_id() ) )->sides[0];
		$this->assertSame( array( self::ROW_PROOF, self::ROW_PROOF ), array_map( static fn ( StandingRow $row ): ?string => $row->proof_id, $side->standing_rows ) );
	}

	public function test_a_preview_pixonaut_would_not_give_is_logged_and_the_row_kept(): void {
		$product = $this->product();
		$this->script_link( array( 'legal' => true ) );
		$this->script_rows( array( 'des_row_s', 'des_row_m' ), 'fail' );

		$this->save( $product->get_id(), array(), array( 'mode' => Side::STANDING ) );

		$config = ProductConfig::stored_on( $this->reload( $product->get_id() ) );
		$this->assertTrue( $config->valid );
		$this->assertSame( array( 'des_row_s', 'des_row_m' ), array_map( static fn ( StandingRow $row ): string => $row->design_id, $config->sides[0]->standing_rows ) );
		$this->assertNull( $config->sides[0]->standing_rows[0]->proof_id );
		$this->assertSame(
			array(
				'side'      => 'front',
				'design_id' => 'des_row_s',
				'status'    => 500,
				'code'      => 'db_error',
			),
			$this->logger->entries[0]['context'] ?? null
		);
	}

	public function test_a_refused_row_leaves_the_product_unsellable(): void {
		$product = $this->product();
		$this->script_link( array( 'legal' => true ) );
		$this->script_rows( array() );
		$this->recorder->json( 422, array( 'error' => self::CONSTRAINT_VIOLATION ) );

		$this->save( $product->get_id(), array(), array( 'mode' => Side::STANDING ) );

		$config = ProductConfig::stored_on( $this->reload( $product->get_id() ) );
		$this->assertFalse( $config->valid );
		$this->assertSame( InvalidReason::STANDING_REFUSED, $config->invalid_reason );
	}

	public function test_the_panel_previews_each_rows_design_on_the_blank(): void {
		$product = $this->product();
		$this->script_link( array( 'legal' => true ) );
		$this->script_rows( array( 'des_row_s', 'des_row_m' ) );
		$this->save( $product->get_id(), array(), array( 'mode' => Side::STANDING ) );

		$tiles = $this->option_tiles( $product->get_id() );

		$this->assertSame( array( 'S', 'M' ), array_column( $tiles, 'value' ) );
		$this->assertStringContainsString( 'des_row_s', $tiles[0]['image'] );
		$this->assertStringContainsString( 'v=' . self::ROW_PROOF, $tiles[0]['image'] );
		$this->assertStringContainsString( rawurlencode( 'blank::v_white_s' ), $tiles[0]['image'], 'drawn on a photo of the row\'s own size' );
		$this->assertTrue( $tiles[0]['usual'] );
		$this->assertFalse( $tiles[1]['usual'] );
	}

	public function test_the_panel_shows_no_previews_for_a_side_with_one_design(): void {
		$product = $this->product();
		$this->script_link( array( 'legal' => true ) );
		$this->script_standing();
		$this->save( $product->get_id(), array(), array( 'mode' => Side::STANDING ) );

		$this->assertSame( array(), $this->option_tiles( $product->get_id() ) );
	}

	public function test_a_refused_standing_design_leaves_the_product_unsellable_with_a_notice(): void {
		$product = $this->product();
		$this->script_link( array( 'legal' => true ) );
		$this->recorder->json( 422, array( 'error' => self::CONSTRAINT_VIOLATION ) );

		$this->save( $product->get_id(), array(), array( 'mode' => Side::STANDING ) );

		$saved  = $this->reload( $product->get_id() );
		$config = ProductConfig::stored_on( $saved );
		$this->assertFalse( $config->valid );
		$this->assertSame( InvalidReason::STANDING_REFUSED, $config->invalid_reason );
		$this->assertTrue( $config->sides[0]->is_standing() );
		$this->assertFalse( $saved->is_purchasable() );
		$this->assertTrue( $this->plugin->notices()->has( ProductPanel::notice_key( $product->get_id() ) ) );
		$this->assertSame(
			array(
				'side'        => 'front',
				'template_id' => self::TEMPLATE_ID,
				'blank_id'    => self::BLANK_ID,
				'placement'   => self::PLACEMENT_ID,
				'status'      => 422,
				'code'        => self::CONSTRAINT_VIOLATION,
			),
			$this->logger->entries[0]['context'] ?? null
		);
	}

	public function test_a_store_that_may_not_approve_designs_is_told_so(): void {
		$product = $this->product();
		$this->script_link( array( 'legal' => true ) );
		$this->recorder->json( 403, array( 'error' => ErrorCodes::FORBIDDEN ) );

		$this->save( $product->get_id(), array(), array( 'mode' => Side::STANDING ) );

		$this->assertSame( InvalidReason::STANDING_FORBIDDEN, ProductConfig::stored_on( $this->reload( $product->get_id() ) )->invalid_reason );
	}

	public function test_a_personalised_side_asks_for_no_standing_design(): void {
		$product = $this->product();
		$this->script_link( array( 'legal' => true ) );

		$this->save( $product->get_id() );

		$side = ProductConfig::stored_on( $this->reload( $product->get_id() ) )->sides[0];
		$this->assertFalse( $side->is_standing() );
		$this->assertNull( $side->standing_design_id );
		$this->assertSame( array(), $this->standing_requests() );
	}

	public function test_a_standing_front_and_a_personalised_back_find_a_design_for_the_front_alone(): void {
		$product = $this->product();
		$this->recorder->json(
			200,
			$this->blank(
				array(
					self::PLACEMENT_ID => 'Front',
					self::BACK_ID      => 'Back',
				)
			)
		);
		foreach ( array( self::PLACEMENT_ID, self::BACK_ID ) as $placement ) {
			$this->recorder->json( 200, $this->constraints( $placement ) );
			$this->recorder->json( 200, array( 'verdicts' => array( array( 'legal' => true ) ) ) );
		}
		$this->script_template();
		$this->script_standing();

		$this->save_sides(
			$product->get_id(),
			array(
				array(
					'key'  => 'front',
					'mode' => Side::STANDING,
				),
				array(
					'key'       => 'back',
					'placement' => self::BACK_ID,
				),
			)
		);

		$config = ProductConfig::stored_on( $this->reload( $product->get_id() ) );
		$this->assertTrue( $config->valid );
		$this->assertSame( array( 'front' ), array_map( static fn ( Side $side ): string => $side->key, $config->standing_sides() ) );
		$this->assertSame( array( 'back' ), array_map( static fn ( Side $side ): string => $side->key, $config->personalised_sides() ) );
		$this->assertCount( 1, $this->standing_requests() );
	}

	public function test_a_standing_side_on_a_template_with_fields_warns_that_customers_cannot_fill_them_in(): void {
		$product = $this->product();
		$this->script_offering( true );
		$this->script_standing();
		$this->save( $product->get_id(), array(), array( 'mode' => Side::STANDING ) );

		$this->assertSame( array( 'standing' ), $this->warnings( $product->get_id() ) );
		$this->assertTrue( $this->reload( $product->get_id() )->is_purchasable(), 'a warning does not stop sales' );
	}

	public function test_a_personalised_side_on_a_template_without_fields_warns_there_is_nothing_to_personalise(): void {
		$product = $this->product();
		$this->script_offering( false );
		$this->save( $product->get_id() );

		$this->assertSame( array( 'personalised' ), $this->warnings( $product->get_id() ) );
		$this->assertTrue( $this->reload( $product->get_id() )->is_purchasable() );
	}

	public function test_a_mode_that_suits_its_template_warns_of_nothing(): void {
		$product = $this->product();
		$this->script_offering( true );
		$this->save( $product->get_id() );

		$this->assertSame( array(), $this->warnings( $product->get_id() ) );
	}

	/**
	 * Scripts a one-sided save whose template version does or does not offer a field.
	 *
	 * @param bool $offers Whether it does.
	 */
	private function script_offering( bool $offers ): void {
		$this->recorder->json( 200, $this->blank() );
		$this->recorder->json( 200, $this->constraints( self::PLACEMENT_ID ) );
		$this->recorder->json( 200, array( 'verdicts' => array( array( 'legal' => true ) ) ) );
		$this->recorder->raw( 200, OpenApiAssert::example( 'GET', '/v1/templates/{id}', 200, array( 'offersProperties' => $offers ) ), 'application/json' );
	}

	/**
	 * The mode warnings the product screen shows for the product's sides,
	 * by the mode each warns about.
	 *
	 * @param int $product_id The product.
	 * @return list<string>
	 */
	private function warnings( int $product_id ): array {
		// The panel reads the blank, its views and its printshop to show them.
		$this->recorder->json( 200, $this->blank() );
		$this->recorder->json( 200, $this->mockups() );
		$this->recorder->json( 200, self::printshops() );
		$document = new DOMDocument();
		libxml_use_internal_errors( true );
		$document->loadHTML( '<?xml encoding="utf-8"?>' . $this->panel->fields_html( $this->panel->panel( $this->reload( $product_id ) ) ) );
		libxml_clear_errors();
		$shown = array();
		$found = ( new DOMXPath( $document ) )->query( '//div[contains(@class, "pixonaut-side")]//*[contains(@class, "pixonaut-mode-warning--") and not(@hidden)]' );
		foreach ( false === $found ? array() : $found as $warning ) {
			if ( $warning instanceof DOMElement ) {
				$shown[] = str_contains( $warning->getAttribute( 'class' ), '--standing' ) ? Side::STANDING : Side::PERSONALISED;
			}
		}
		return $shown;
	}
}
