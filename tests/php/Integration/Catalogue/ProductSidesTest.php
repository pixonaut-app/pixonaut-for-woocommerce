<?php
/**
 * @package Pixonaut\WooCommerce
 */

declare(strict_types=1);

namespace Pixonaut\WooCommerce\Tests\Integration\Catalogue;

use Pixonaut\WooCommerce\Admin\LinkedBlank;
use Pixonaut\WooCommerce\Admin\ProductPanel;
use Pixonaut\WooCommerce\Catalogue\InvalidReason;
use Pixonaut\WooCommerce\Catalogue\PostedSides;
use Pixonaut\WooCommerce\Catalogue\PrintSize;
use Pixonaut\WooCommerce\Catalogue\ProductConfig;
use Pixonaut\WooCommerce\Catalogue\Side;
use Pixonaut\WooCommerce\Tests\Support\ConnectionAnswers;
use WC_Product;

final class ProductSidesTest extends ProductPanelTestCase {

	private const BACK_ID = 'back';

	/**
	 * The blank's placements, front and back.
	 */
	private const PLACEMENTS = array(
		self::PLACEMENT_ID => 'Front',
		self::BACK_ID      => 'Back',
	);

	private const A4_WIDTH_MM = 210;

	private const A4_HEIGHT_MM = 297;

	/**
	 * Larger than the fixture placements' largest print area.
	 */
	private const OVERSIZED_MM = 900;

	private const LEGAL = array( 'legal' => true );

	private const TOO_LARGE = array(
		'legal'  => false,
		'reason' => InvalidReason::TOO_LARGE,
	);

	public function test_saves_each_side_with_its_own_size_menu_checked_in_one_call(): void {
		$product = $this->product();
		$this->recorder->json( 200, $this->blank( self::PLACEMENTS ) );
		$this->recorder->json( 200, $this->constraints( self::PLACEMENT_ID ) );
		$this->recorder->json( 200, array( 'verdicts' => array( self::LEGAL ) ) );
		// The back sells two custom sizes and no default: no constraints read.
		$this->recorder->json( 200, array( 'verdicts' => array( self::LEGAL, self::LEGAL ) ) );
		$this->script_template();

		$this->save_sides(
			$product->get_id(),
			array(
				array(
					'key'   => 'front',
					'label' => 'Front',
				),
				array(
					'key'       => 'back',
					'label'     => 'Back',
					'placement' => self::BACK_ID,
					'sizes'     => array( self::a4(), self::custom( '300', '400', '' ) ),
				),
			)
		);

		$saved  = $this->reload( $product->get_id() );
		$config = ProductConfig::stored_on( $saved );
		$this->assertTrue( $config->valid );
		$this->assertSame( array( 'front', 'back' ), array_map( static fn ( Side $side ): string => $side->key, $config->sides ) );
		$this->assertEquals( array( new PrintSize( self::PRINT_WIDTH_MM, self::PRINT_HEIGHT_MM, '280 × 360 mm', true ) ), $config->sides[0]->print_sizes );
		$back = $config->sides[1];
		$this->assertSame( 'Back', $back->label );
		$this->assertSame( self::BACK_ID, $back->placement_id );
		$this->assertSame( 'Back', $back->placement_name );
		$this->assertEquals(
			array(
				new PrintSize( self::A4_WIDTH_MM, self::A4_HEIGHT_MM, 'A4' ),
				new PrintSize( 300.0, 400.0, '300 × 400 mm' ),
			),
			$back->print_sizes
		);
		$this->assertTrue( $saved->is_purchasable() );

		$checks = array_values( array_filter( $this->recorder->requests, static fn ( array $request ): bool => 'POST' === $request['method'] ) );
		$this->assertCount( 2, $checks );
		$this->assertEquals(
			array(
				'sizes'       => array(
					array(
						'widthMm'  => self::A4_WIDTH_MM,
						'heightMm' => self::A4_HEIGHT_MM,
					),
					array(
						'widthMm'  => 300,
						'heightMm' => 400,
					),
				),
				'blankId'     => self::BLANK_ID,
				'placementId' => self::BACK_ID,
			),
			json_decode( (string) $checks[1]['body'], true )
		);
	}

	public function test_a_side_with_one_illegal_size_keeps_only_the_legal_ones_and_flags_the_product(): void {
		$product = $this->product();
		$this->recorder->json( 200, $this->blank( self::PLACEMENTS ) );
		$this->recorder->json( 200, $this->constraints( self::PLACEMENT_ID ) );
		$this->recorder->json( 200, array( 'verdicts' => array( self::LEGAL ) ) );
		$this->recorder->json( 200, array( 'verdicts' => array( self::LEGAL, self::TOO_LARGE ) ) );

		$this->save_sides(
			$product->get_id(),
			array(
				array(),
				array(
					'placement' => self::BACK_ID,
					'sizes'     => array( self::a4(), self::custom( (string) self::OVERSIZED_MM, (string) self::OVERSIZED_MM, 'Huge' ) ),
				),
			)
		);

		$saved  = $this->reload( $product->get_id() );
		$config = ProductConfig::stored_on( $saved );
		$this->assertFalse( $config->valid );
		$this->assertSame( InvalidReason::TOO_LARGE, $config->invalid_reason );
		$this->assertEquals( array( new PrintSize( self::A4_WIDTH_MM, self::A4_HEIGHT_MM, 'A4' ) ), $config->sides[1]->print_sizes );
		$this->assertCount( 1, $config->sides[0]->print_sizes );
		$this->assertFalse( $saved->is_purchasable() );
		$this->assertTrue( $this->plugin->notices()->has( ProductPanel::notice_key( $product->get_id() ) ) );
	}

	public function test_a_placement_used_by_two_sides_is_saved_but_cannot_be_bought(): void {
		$product = $this->product();
		$this->recorder->json( 200, $this->blank( self::PLACEMENTS ) );
		$sides = array( array( 'key' => 'front' ), array( 'key' => 'again' ) );
		// Each side is still checked on its own; the template's constraints on the placement are read once.
		$this->recorder->json( 200, $this->constraints( self::PLACEMENT_ID ) );
		$this->recorder->json( 200, array( 'verdicts' => array( self::LEGAL ) ) );
		$this->recorder->json( 200, array( 'verdicts' => array( self::LEGAL ) ) );

		$this->save_sides( $product->get_id(), $sides );

		$saved  = $this->reload( $product->get_id() );
		$config = ProductConfig::stored_on( $saved );
		$this->assertSame( InvalidReason::DUPLICATE_PLACEMENT, $config->invalid_reason );
		$this->assertCount( 2, $config->sides );
		$this->assertFalse( $saved->is_purchasable() );
	}

	public function test_an_unusable_custom_size_flags_the_product_and_keeps_the_rest(): void {
		$product = $this->product();
		$this->recorder->json( 200, $this->blank() );
		$this->recorder->json( 200, array( 'verdicts' => array( self::LEGAL ) ) );

		$this->save( $product->get_id(), array(), array( 'sizes' => array( self::custom( '0', '297', 'Flat' ), self::a4() ) ) );

		$config = ProductConfig::stored_on( $this->reload( $product->get_id() ) );
		$this->assertSame( InvalidReason::INVALID_PRINT_SIZE, $config->invalid_reason );
		$this->assertEquals( array( new PrintSize( self::A4_WIDTH_MM, self::A4_HEIGHT_MM, 'A4' ) ), $config->sides[0]->print_sizes );
	}

	public function test_without_a_blank_only_the_first_side_counts(): void {
		$product = $this->product();
		$this->recorder->json( 200, array( 'verdicts' => array( self::LEGAL ) ) );
		$this->script_template();
		$this->recorder->json( 200, self::printshops() );

		$this->save_sides( $product->get_id(), array( self::template_only_side(), array( 'placement' => self::BACK_ID ) ), array( 'pixonaut_blank' => '' ) );

		$config = ProductConfig::stored_on( $this->reload( $product->get_id() ) );
		$this->assertTrue( $config->valid );
		$this->assertCount( 1, $config->sides );
		$this->assertNull( $config->sides[0]->placement_id );
		$this->assertCount( 1, $config->sides[0]->print_sizes );
		$this->assertCount( 3, $this->recorder->requests, 'only the first side is checked, then the printshops are read' );
	}

	public function test_the_panel_shows_each_side_and_the_printshop_the_blank_names(): void {
		$product = $this->product();
		$this->recorder->json( 200, $this->blank( self::PLACEMENTS ) );
		$this->recorder->json( 200, $this->constraints( self::PLACEMENT_ID ) );
		$this->recorder->json( 200, array( 'verdicts' => array( self::LEGAL ) ) );
		$this->recorder->json( 200, array( 'verdicts' => array( self::LEGAL ) ) );
		$this->script_template();
		$this->save_sides(
			$product->get_id(),
			array(
				array(),
				array(
					'placement' => self::BACK_ID,
					'sizes'     => array( self::a4() ),
				),
			)
		);
		$this->recorder->json( 200, $this->blank( self::PLACEMENTS, 'ps_other' ) );
		$this->recorder->json( 200, $this->mockups( self::PLACEMENTS ) );
		$this->recorder->json( 200, self::printshops() );

		$panel = $this->panel->panel( $this->reload( $product->get_id() ) );

		$this->assertCount( 2, $panel['sides'] );
		$this->assertSame( self::BACK_ID, $panel['sides'][1]['placement_id'] );
		$this->assertSame(
			array(
				array(
					'start'  => true,
					'kind'   => PostedSides::SIZE_CUSTOM,
					'width'  => '210',
					'height' => '297',
					'label'  => 'A4',
				),
			),
			$panel['sides'][1]['sizes']
		);
		$this->assertSame( 'Other Print AB', $panel['printshop']['name'] );
		$this->assertStringEndsWith( ConnectionAnswers::studio_path() . 'blanks/' . self::BLANK_ID, $panel['printshop']['url'] );

		// Rendered, every side's fields are posted under its own index.
		ob_start();
		$GLOBALS['product_object'] = $this->reload( $product->get_id() );
		$this->panel->render();
		unset( $GLOBALS['product_object'] );
		$html = (string) ob_get_clean();
		$this->assertStringContainsString( 'name="pixonaut_sides[1][placement]"', $html );
		$this->assertStringContainsString( 'name="pixonaut_sides[1][sizes][0][width]" value="210"', $html );
		$this->assertStringContainsString( 'name="pixonaut_sides[' . ProductPanel::NEW_SIDE_INDEX . '][key]"', $html );
		$this->assertStringContainsString( 'Other Print AB', $html );
		// Two sides are within what Pixonaut recommends, and on a blank there may be more than one.
		$this->assertMatchesRegularExpression( '/class="description pixonaut-sides-over-recommended" data-recommended="' . PostedSides::RECOMMENDED_SIDES . '"\s+hidden>/', $html );
		// Each placement says the largest print it takes, for a custom size to show and check.
		$this->assertMatchesRegularExpression( '/<option value="' . self::BACK_ID . '" data-thumbnail="[^"]*"\s+data-max-width-mm="330" data-max-height-mm="420"/', $html );
		$this->assertStringContainsString( 'data-size-limit="Up to %1$s × %2$s mm"', $html );
	}

	public function test_a_printshop_pixonaut_cannot_name_says_so(): void {
		$product = $this->product();
		$this->script_link( self::LEGAL );
		$this->save( $product->get_id() );
		$this->recorder->json( 200, $this->blank( array( self::PLACEMENT_ID => 'Front' ), self::PRINTSHOP_ID ) );
		$this->recorder->json( 200, $this->mockups() );
		$this->recorder->json( 500, array( 'error' => 'internal_error' ) );

		$panel = $this->panel->panel( $this->reload( $product->get_id() ) );

		$this->assertSame( __( 'Pixonaut could not be asked which printshop this is.', 'pixonaut-for-woocommerce' ), $panel['printshop']['name'] );
	}

	public function test_each_side_the_storefront_sees_can_be_filtered(): void {
		$product = $this->product();
		$this->script_link( self::LEGAL );
		$this->save( $product->get_id() );
		$seen   = array();
		$filter = static function ( Side $side, WC_Product $bought ) use ( &$seen ): Side {
			$seen[] = $bought->get_id();
			return new Side( $side->key, 'Chest', $side->placement_id, $side->placement_name, $side->template_base_id, $side->template_version, $side->previous_version, $side->template_name, $side->print_sizes );
		};
		add_filter( 'pixonaut_wc_catalogue_side_config', $filter, 10, 2 );

		$resolved = ProductConfig::config_for( $this->reload( $product->get_id() ) );
		$stored   = ProductConfig::stored_on( $this->reload( $product->get_id() ) );

		remove_filter( 'pixonaut_wc_catalogue_side_config', $filter, 10 );
		$this->assertSame( 'Chest', $resolved->sides[0]->label );
		$this->assertSame( 'Front', $stored->sides[0]->label );
		$this->assertSame( array( $product->get_id() ), $seen );
	}

	public function test_a_menu_pixonaut_could_not_check_is_kept_for_the_next_save(): void {
		$product = $this->product();
		$sizes   = array( self::a4(), self::custom( '300', '400', 'Large' ) );
		$this->recorder->json( 200, $this->blank() );
		$this->recorder->json( 500, array( 'error' => 'internal_error' ) );

		$this->save( $product->get_id(), array(), array( 'sizes' => $sizes ) );

		$config = ProductConfig::stored_on( $this->reload( $product->get_id() ) );
		$this->assertSame( InvalidReason::CHECK_FAILED, $config->invalid_reason );
		$this->assertSame( array(), $config->sides[0]->print_sizes );
		$this->assertEquals(
			array(
				new PrintSize( self::A4_WIDTH_MM, self::A4_HEIGHT_MM, 'A4' ),
				new PrintSize( 300.0, 400.0, 'Large' ),
			),
			$config->sides[0]->pending_sizes
		);
		$this->assert_panel_sizes( $product->get_id(), $sizes );

		$this->recorder->requests = array();
		$this->recorder->json( 200, $this->blank() );
		$this->recorder->json( 200, array( 'verdicts' => array( self::LEGAL, self::LEGAL ) ) );
		$this->script_template();
		$this->save( $product->get_id(), array(), array( 'sizes' => $sizes ) );

		$checks = array_values( array_filter( $this->recorder->requests, static fn ( array $request ): bool => 'POST' === $request['method'] ) );
		$this->assertCount( 1, $checks );
		$body = json_decode( (string) $checks[0]['body'], true );
		$this->assertIsArray( $body );
		$this->assertEquals(
			array(
				array(
					'widthMm'  => self::A4_WIDTH_MM,
					'heightMm' => self::A4_HEIGHT_MM,
				),
				array(
					'widthMm'  => 300,
					'heightMm' => 400,
				),
			),
			$body['sizes']
		);
		$config = ProductConfig::stored_on( $this->reload( $product->get_id() ) );
		$this->assertTrue( $config->valid );
		$this->assertNull( $config->sides[0]->pending_sizes );
		$this->assertCount( 2, $config->sides[0]->print_sizes );
	}

	public function test_a_menu_is_kept_when_the_blank_cannot_be_read(): void {
		$product = $this->product();
		$sizes   = array( self::default_size(), self::a4() );
		$this->recorder->json( 500, array( 'error' => 'internal_error' ) );

		$this->save( $product->get_id(), array(), array( 'sizes' => $sizes ) );

		$config = ProductConfig::stored_on( $this->reload( $product->get_id() ) );
		$this->assertSame( InvalidReason::CHECK_FAILED, $config->invalid_reason );
		$this->assertSame( array(), $config->sides[0]->print_sizes );
		$this->assertEquals( array( null, new PrintSize( self::A4_WIDTH_MM, self::A4_HEIGHT_MM, 'A4' ) ), $config->sides[0]->pending_sizes );
		$this->assert_panel_sizes( $product->get_id(), array( ProductPanel::default_size_fields(), self::a4() ) );
	}

	public function test_a_side_without_a_template_is_kept_on_an_invalid_product(): void {
		$product = $this->product();
		$back    = array(
			'key'           => 'back',
			'label'         => 'Back',
			'placement'     => self::BACK_ID,
			'template'      => '',
			'template_name' => '',
			'sizes'         => array( self::a4() ),
		);
		foreach ( array( 1, 2 ) as $save ) {
			$this->recorder->json( 200, $this->blank( self::PLACEMENTS ) );
			$this->recorder->json( 200, $this->constraints( self::PLACEMENT_ID ) );
			$this->recorder->json( 200, array( 'verdicts' => array( self::LEGAL ) ) );

			$this->save_sides( $product->get_id(), array( array( 'key' => 'front' ), $back ) );

			$config = ProductConfig::stored_on( $this->reload( $product->get_id() ) );
			$this->assertSame( InvalidReason::MISSING_TEMPLATE, $config->invalid_reason, 'save ' . $save );
			$this->assertSame( array( 'front', 'back' ), array_map( static fn ( Side $side ): string => $side->key, $config->sides ) );
			$this->assertSame( '', $config->sides[1]->template_base_id );
			$this->assertNull( $config->sides[1]->template_version );
			$this->assertFalse( $this->reload( $product->get_id() )->is_purchasable() );
		}

		$this->recorder->json( 200, $this->blank( self::PLACEMENTS ) );
		$this->recorder->json( 200, $this->mockups( self::PLACEMENTS ) );
		$this->recorder->json( 200, self::printshops() );
		$panel = $this->panel->panel( $this->reload( $product->get_id() ) );
		$this->assertCount( 2, $panel['sides'] );
		$this->assertSame( self::BACK_ID, $panel['sides'][1]['placement_id'] );
		$this->assertSame( 'Back', $panel['sides'][1]['label'] );
		$this->assertSame( '', $panel['sides'][1]['template_id'] );
		$this->assertSame( array( array( 'start' => true ) + self::a4() ), $panel['sides'][1]['sizes'] );
	}

	public function test_a_refused_size_stays_in_the_menu_as_entered_and_the_product_is_not_sold(): void {
		$product = $this->product();
		$sizes   = array( self::a4(), self::custom( (string) self::OVERSIZED_MM, (string) self::OVERSIZED_MM, 'Huge' ), self::custom( '300', '400', 'Large' ) );
		$this->recorder->json( 200, $this->blank() );
		$this->recorder->json( 200, array( 'verdicts' => array( self::LEGAL, self::TOO_LARGE, self::LEGAL ) ) );

		$this->save(
			$product->get_id(),
			array(),
			array(
				'sizes'      => $sizes,
				'start_size' => '2',
			)
		);

		$config = ProductConfig::stored_on( $this->reload( $product->get_id() ) );
		$this->assertFalse( $config->valid );
		$this->assertSame( InvalidReason::TOO_LARGE, $config->invalid_reason );
		$this->assertSame( 2, $config->sides[0]->start_size, 'the starting size is the one entered' );
		$this->assertFalse( $this->reload( $product->get_id() )->is_purchasable() );
		$this->assert_panel_sizes( $product->get_id(), $sizes, 2 );
	}

	public function test_a_menu_pixonaut_could_not_check_keeps_its_starting_size(): void {
		$product = $this->product();
		$sizes   = array( self::a4(), self::custom( '300', '400', 'Large' ) );
		$this->recorder->json( 200, $this->blank() );
		$this->recorder->json( 500, array( 'error' => 'internal_error' ) );

		$this->save(
			$product->get_id(),
			array(),
			array(
				'sizes'      => $sizes,
				'start_size' => '1',
			)
		);

		$side = ProductConfig::stored_on( $this->reload( $product->get_id() ) )->sides[0];
		$this->assertSame( 1, $side->start_size );
		$this->assertSame( 0, $side->selected_size_index(), 'nothing is sold until the menu is checked' );
		$this->assert_panel_sizes( $product->get_id(), $sizes, 1 );
	}

	public function test_custom_millimetres_are_shown_exactly(): void {
		$product = $this->product();
		$this->recorder->json( 200, $this->blank() );
		$this->recorder->json( 200, array( 'verdicts' => array( self::LEGAL ) ) );
		$this->script_template();
		$this->save( $product->get_id(), array(), array( 'sizes' => array( self::custom( '210.07', '270.09', 'Odd' ) ) ) );

		$this->assert_panel_sizes( $product->get_id(), array( self::custom( '210.07', '270.09', 'Odd' ) ) );
	}

	public function test_a_printshop_from_the_blanks_category_says_so(): void {
		$product = $this->product();
		$this->script_link( self::LEGAL );
		$this->save( $product->get_id() );
		$this->recorder->json( 200, $this->blank( array( self::PLACEMENT_ID => 'Front' ), 'ps_other', LinkedBlank::SOURCE_CATEGORY ) );
		$this->recorder->json( 200, $this->mockups() );
		$this->recorder->json( 200, self::printshops() );

		$panel = $this->panel->panel( $this->reload( $product->get_id() ) );

		$this->assertSame( 'Other Print AB (from the blank’s category)', $panel['printshop']['name'] );
	}

	public function test_a_blank_with_no_printshop_anywhere_says_none_is_set(): void {
		$product = $this->product();
		$this->script_link( self::LEGAL );
		$this->save( $product->get_id() );
		$this->recorder->json( 200, $this->blank( array( self::PLACEMENT_ID => 'Front' ), null, LinkedBlank::SOURCE_NONE ) );
		$this->recorder->json( 200, $this->mockups() );
		$this->recorder->json( 200, self::printshops() );

		$panel = $this->panel->panel( $this->reload( $product->get_id() ) );

		$this->assertStringStartsWith( 'None set', $panel['printshop']['name'] );
	}

	public function test_a_blank_pixonaut_cannot_read_does_not_claim_the_workspace_default(): void {
		$product = $this->product();
		$this->script_link( self::LEGAL );
		$this->save( $product->get_id() );
		// The printshops are already known; the blank is not.
		$this->recorder->json( 200, self::printshops() );
		$this->recorder->json( 500, array( 'error' => 'internal_error' ) );
		$this->cache->remember( 'printshop-connections', array(), fn (): array => $this->catalogue->printshop_connections() );
		$this->recorder->json( 200, $this->mockups() );

		$panel = $this->panel->panel( $this->reload( $product->get_id() ) );

		$this->assertSame( __( 'Pixonaut could not be asked which printshop this is.', 'pixonaut-for-woocommerce' ), $panel['printshop']['name'] );
		$this->assertStringEndsWith( ConnectionAnswers::studio_path() . 'blanks/' . self::BLANK_ID, $panel['printshop']['url'] );
	}

	/**
	 * Asserts the size rows the panel shows for the product's first side.
	 *
	 * @param int                              $product_id The product.
	 * @param list<array<string, string|bool>> $expected   The rows, without which one starts.
	 * @param int                              $start      The row marked to start.
	 */
	private function assert_panel_sizes( int $product_id, array $expected, int $start = 0 ): void {
		$this->recorder->json( 200, $this->blank() );
		$this->recorder->json( 200, $this->mockups() );
		$this->recorder->json( 200, self::printshops() );
		$panel = $this->panel->panel( $this->reload( $product_id ) );
		$rows  = array();
		foreach ( $expected as $index => $row ) {
			$rows[] = array( 'start' => $index === $start ) + $row;
		}
		$this->assertSame( $rows, $panel['sides'][0]['sizes'] );
	}

	/**
	 * @return array<string, string|bool>
	 */
	private static function default_size(): array {
		return ProductPanel::default_size_fields();
	}

	/**
	 * @return array<string, string>
	 */
	private static function a4(): array {
		return self::custom( (string) self::A4_WIDTH_MM, (string) self::A4_HEIGHT_MM, 'A4' );
	}

	/**
	 * @param string $width  Width as typed.
	 * @param string $height Height as typed.
	 * @param string $label  Label as typed.
	 * @return array<string, string>
	 */
	private static function custom( string $width, string $height, string $label ): array {
		return array(
			'kind'   => PostedSides::SIZE_CUSTOM,
			'width'  => $width,
			'height' => $height,
			'label'  => $label,
		);
	}
}
