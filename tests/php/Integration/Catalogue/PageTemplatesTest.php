<?php
/**
 * @package Pixonaut\WooCommerce
 */

declare(strict_types=1);

namespace Pixonaut\WooCommerce\Tests\Integration\Catalogue;

use Pixonaut\WooCommerce\Catalogue\InvalidReason;
use Pixonaut\WooCommerce\Catalogue\PageTemplate;
use Pixonaut\WooCommerce\Catalogue\PostedSides;
use Pixonaut\WooCommerce\Catalogue\ProductConfig;
use Pixonaut\WooCommerce\Catalogue\ProductIndex;
use Pixonaut\WooCommerce\Tests\Support\OpenApiAssert;
use Pixonaut\WooCommerce\Tests\Support\VerdictAnswers;

/**
 * A side whose placement is printed as pages offers page layouts: other
 * templates of its template's ratio a page may be designed on. The product
 * screen saves them checked with Pixonaut, the template index names them so
 * their events find the product, and a recheck moves each to its latest
 * version as it moves the side's template.
 */
final class PageTemplatesTest extends ProductPanelTestCase {

	private const TWO_PHOTOS = 'two-photos';

	private const FOUR_PHOTOS = 'four-photos';

	/**
	 * The page counts the fixture book's pages are printed at.
	 */
	private const PAGES = array(
		'min'  => 24,
		'max'  => 48,
		'step' => 4,
	);

	/**
	 * A ratio no page of the fixture book is printed at: square.
	 */
	private const SQUARE = array(
		'w' => 1,
		'h' => 1,
	);

	public function test_a_side_printed_as_pages_saves_its_page_layouts_read_live_at_its_ratio_and_indexed(): void {
		$product = $this->product();
		$this->script_paged_link();
		$this->script_read( self::TWO_PHOTOS . '@2', 'Two photos' );
		$this->script_read( self::FOUR_PHOTOS . '@1', 'Four photos' );

		$this->save( $product->get_id(), array(), array( PostedSides::PAGE_TEMPLATES => array( self::TWO_PHOTOS . '@2', self::FOUR_PHOTOS . '@1' ) ) );

		$saved  = $this->reload( $product->get_id() );
		$config = ProductConfig::stored_on( $saved );
		$this->assertTrue( $config->valid, (string) $config->invalid_reason );
		$this->assertEquals(
			array( new PageTemplate( self::TWO_PHOTOS, 2, null, 2, 'Two photos' ), new PageTemplate( self::FOUR_PHOTOS, 1, null, 1, 'Four photos' ) ),
			$config->sides[0]->page_templates
		);
		$this->assertEqualsCanonicalizing( array( 'mini-tee', self::TWO_PHOTOS, self::FOUR_PHOTOS ), get_post_meta( $product->get_id(), ProductIndex::TEMPLATE_META, false ) );
		$this->assertSame( array( $product->get_id() ), ProductIndex::using_template( self::FOUR_PHOTOS, 0, 10 ) );
	}

	public function test_a_side_saved_from_a_form_without_the_field_keeps_its_layouts_and_one_saved_empty_has_none(): void {
		$product = $this->linked_book_product();
		$this->script_paged_link();
		$this->script_read( self::TWO_PHOTOS . '@2', 'Two photos' );

		$this->save( $product->get_id() );

		$this->assertSame( self::TWO_PHOTOS, ProductConfig::stored_on( $this->reload( $product->get_id() ) )->sides[0]->page_templates[0]->base_id ?? null );

		$this->script_paged_link();
		$this->save( $product->get_id(), array(), array( PostedSides::PAGE_TEMPLATES => array( '' ) ) );

		$this->assertSame( array(), ProductConfig::stored_on( $this->reload( $product->get_id() ) )->sides[0]->page_templates );
	}

	public function test_page_layouts_on_a_placement_not_printed_as_pages_cannot_be_sold(): void {
		$product = $this->product();
		$this->script_blank( $this->blank() );
		$this->recorder->json( 200, $this->constraints( self::PLACEMENT_ID ) );
		$this->recorder->json( 200, array( 'verdicts' => array( VerdictAnswers::LEGAL ) ) );

		$this->save( $product->get_id(), array(), array( PostedSides::PAGE_TEMPLATES => array( self::TWO_PHOTOS . '@2' ) ) );

		$config = ProductConfig::stored_on( $this->reload( $product->get_id() ) );
		$this->assertFalse( $config->valid );
		$this->assertSame( InvalidReason::PAGE_TEMPLATES_NOT_PAGED, $config->invalid_reason );
		// Kept as entered, so the screen shows them with the reason.
		$this->assertSame( self::TWO_PHOTOS, $config->sides[0]->page_templates[0]->base_id );
	}

	public function test_a_page_layout_at_another_ratio_cannot_be_sold(): void {
		$product = $this->product();
		$this->script_paged_link();
		$this->script_read( self::TWO_PHOTOS . '@2', 'Two photos', self::SQUARE );

		$this->save( $product->get_id(), array(), array( PostedSides::PAGE_TEMPLATES => array( self::TWO_PHOTOS . '@2' ) ) );

		$config = ProductConfig::stored_on( $this->reload( $product->get_id() ) );
		$this->assertSame( InvalidReason::PAGE_TEMPLATE_RATIO, $config->invalid_reason );
	}

	public function test_a_recheck_moves_each_page_layout_to_its_live_version_keeping_the_one_it_left(): void {
		$stored = $this->linked_book();
		$this->script_paged_link();
		$this->script_read( self::TWO_PHOTOS . '@3', 'Two photos' );

		$checked = $this->linker->recheck(
			$stored,
			array(
				'mini-tee'       => 2,
				self::TWO_PHOTOS => 3,
			)
		);

		$this->assertTrue( $checked->valid, (string) $checked->invalid_reason );
		$layout = $checked->sides[0]->page_templates[0];
		$this->assertSame( array( 3, 2, 3 ), array( $layout->version, $layout->previous_version, $layout->latest_version ) );
	}

	public function test_a_new_version_of_a_page_layout_at_another_ratio_makes_the_book_unsellable(): void {
		$stored = $this->linked_book();
		$this->script_paged_link();
		$this->script_read( self::TWO_PHOTOS . '@3', 'Two photos', self::SQUARE );

		$checked = $this->linker->recheck(
			$stored,
			array(
				'mini-tee'       => 2,
				self::TWO_PHOTOS => 3,
			)
		);

		$this->assertSame( InvalidReason::PAGE_TEMPLATE_RATIO, $checked->invalid_reason );
	}

	public function test_a_page_layout_no_longer_live_makes_the_book_unsellable_without_asking_pixonaut(): void {
		$stored = $this->linked_book();

		$checked = $this->linker->recheck( $stored, array( 'mini-tee' => 2 ) );

		$this->assertSame( InvalidReason::TEMPLATE_NOT_LIVE, $checked->invalid_reason );
		$this->assertSame( array(), $this->recorder->requests );
	}

	/**
	 * The configuration of a book saved with one page layout, two photos at version 2.
	 */
	private function linked_book(): ProductConfig {
		return ProductConfig::stored_on( $this->reload( $this->linked_book_product()->get_id() ) );
	}

	/**
	 * A book product saved with one page layout, two photos at version 2.
	 */
	private function linked_book_product(): \WC_Product_Simple {
		$product = $this->product();
		$this->script_paged_link();
		$this->script_read( self::TWO_PHOTOS . '@2', 'Two photos' );
		$this->save( $product->get_id(), array(), array( PostedSides::PAGE_TEMPLATES => array( self::TWO_PHOTOS . '@2' ) ) );
		$this->recorder->requests = array();
		$stored                   = ProductConfig::stored_on( $this->reload( $product->get_id() ) );
		$this->assertTrue( $stored->valid, (string) $stored->invalid_reason );
		return $product;
	}

	/**
	 * Scripts a check of a book's pages up to its page layouts' reads: the
	 * blank with its front printed as pages, the constraints, the verdict
	 * and the side's template, live.
	 */
	private function script_paged_link(): void {
		$this->script_paged_recheck();
		$this->script_read( self::TEMPLATE_ID, 'Mini tee' );
	}

	/**
	 * Scripts what a check of the book asks before its templates are read.
	 */
	private function script_paged_recheck(): void {
		$blank                           = $this->blank();
		$blank['placements'][0]['pages'] = self::PAGES;
		$this->script_blank( $blank );
		$this->recorder->json( 200, $this->constraints( self::PLACEMENT_ID ) );
		$this->recorder->json( 200, array( 'verdicts' => array( VerdictAnswers::LEGAL ) ) );
	}

	/**
	 * Scripts `GET /v1/templates/{id}` of a live template version.
	 *
	 * @param string                     $id    `<baseId>@<version>`.
	 * @param string                     $name  Its name.
	 * @param array{w: int, h: int}|null $ratio Its ratio; null for the tee's 280:360.
	 */
	private function script_read( string $id, string $name, ?array $ratio = null ): void {
		// Read as objects, so the example's empty objects stay objects.
		$read                  = json_decode( OpenApiAssert::example( 'GET', '/v1/templates/{id}', 200 ) );
		$read->template->id    = $id;
		$read->template->name  = $name;
		$read->template->ratio = (object) ( $ratio ?? array(
			'w' => self::PRINT_WIDTH_MM,
			'h' => self::PRINT_HEIGHT_MM,
		) );
		$this->recorder->raw( 200, (string) wp_json_encode( $read ), 'application/json' );
	}
}
