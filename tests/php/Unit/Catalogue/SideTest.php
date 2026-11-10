<?php
/**
 * @package Pixonaut\WooCommerce
 */

declare(strict_types=1);

namespace Pixonaut\WooCommerce\Tests\Unit\Catalogue;

use Brain\Monkey\Functions;
use Pixonaut\WooCommerce\Catalogue\FormGroups;
use Pixonaut\WooCommerce\Catalogue\LayoutAdjustment;
use Pixonaut\WooCommerce\Catalogue\PrintSize;
use Pixonaut\WooCommerce\Catalogue\Side;
use Pixonaut\WooCommerce\Catalogue\StandingRow;
use Pixonaut\WooCommerce\Tests\Unit\TestCase;

final class SideTest extends TestCase {

	private const LIVE_VERSION = 3;

	private const PREVIOUS_VERSION = 2;

	public function test_a_new_live_version_rolls_the_side_on_and_keeps_the_one_replaced(): void {
		$side = self::side( self::LIVE_VERSION, self::PREVIOUS_VERSION );

		$rolled = $side->rolled_to( self::LIVE_VERSION + 1 );

		$this->assertSame( self::LIVE_VERSION + 1, $rolled->template_version );
		$this->assertSame( self::LIVE_VERSION, $rolled->previous_version );
		$this->assertSame( array( $side->key, $side->template_base_id, $side->placement_id, $side->print_sizes ), array( $rolled->key, $rolled->template_base_id, $rolled->placement_id, $rolled->print_sizes ) );
	}

	public function test_a_side_rolled_on_keeps_its_canvas_ratio(): void {
		$side = self::side( self::LIVE_VERSION, self::PREVIOUS_VERSION )->with_draw_at_template_ratio( true );

		$this->assertTrue( $side->rolled_to( self::LIVE_VERSION + 1 )->draw_at_template_ratio );
		$this->assertTrue( Side::from_array( $side->to_array() )?->draw_at_template_ratio );
		$this->assertNull( $side->with_draw_at_template_ratio( null )->to_array()['drawAtTemplateRatio'] );
	}

	public function test_the_same_live_version_leaves_the_side_as_it_is(): void {
		$side = self::side( self::LIVE_VERSION, self::PREVIOUS_VERSION );

		$this->assertSame( $side, $side->rolled_to( self::LIVE_VERSION ) );
	}

	public function test_a_version_rolled_back_to_is_followed_too(): void {
		$rolled = self::side( self::LIVE_VERSION, self::PREVIOUS_VERSION )->rolled_to( self::PREVIOUS_VERSION );

		$this->assertSame( self::PREVIOUS_VERSION, $rolled->template_version );
		$this->assertSame( self::LIVE_VERSION, $rolled->previous_version );
	}

	public function test_a_pinned_side_stays_on_its_version_and_learns_the_latest(): void {
		$side = self::side( self::PREVIOUS_VERSION, null )->with_versions( true, null, self::PREVIOUS_VERSION );

		$rolled = $side->rolled_to( self::LIVE_VERSION );

		$this->assertSame( array( self::PREVIOUS_VERSION, null, self::LIVE_VERSION ), array( $rolled->template_version, $rolled->previous_version, $rolled->latest_version ) );
		$this->assertSame( array( self::LIVE_VERSION, self::PREVIOUS_VERSION, 1 ), $rolled->versions() );
		$this->assertSame( $rolled, $rolled->rolled_to( self::LIVE_VERSION ) );
	}

	public function test_a_side_given_its_template_name_keeps_its_pin_and_the_latest_version(): void {
		$named = self::side( self::PREVIOUS_VERSION, null )->with_versions( true, null, self::LIVE_VERSION )->with_template_name( 'Mini tee' );

		$this->assertSame( array( 'Mini tee', true, self::LIVE_VERSION ), array( $named->template_name, $named->pinned, $named->latest_version ) );
	}

	public function test_a_followed_side_keeps_its_setup_on_the_new_version(): void {
		Functions\when( 'wp_strip_all_tags' )->returnArg();
		Functions\when( 'sanitize_text_field' )->returnArg();
		$sizes  = array( new PrintSize( 210.0, 297.0, 'A4' ), new PrintSize( 297.0, 420.0, 'A3' ) );
		$groups = FormGroups::from_array(
			array(
				'groups' => array(
					array(
						'id'    => 'text-front',
						'title' => 'Your shirt',
						'items' => array( array( 'field' => 'slogan:content' ) ),
					),
				),
			)
		);
		$this->assertNotNull( $groups );
		$side = ( new Side( 'front', 'Front', 'front', 'Front', 'mini-tee', self::LIVE_VERSION, null, 'Mini tee', $sizes, null, 1, Side::STANDING ) )->with_form_groups( $groups );

		$rolled = $side->rolled_to( self::LIVE_VERSION + 1 );

		$this->assertSame( array( self::LIVE_VERSION + 1, self::LIVE_VERSION + 1, false ), array( $rolled->template_version, $rolled->latest_version, $rolled->pinned ) );
		$this->assertSame( array( $sizes, 1, Side::STANDING, $groups ), array( $rolled->print_sizes, $rolled->start_size, $rolled->mode, $rolled->form_groups ) );
	}

	public function test_whether_a_side_is_pinned_and_the_latest_version_round_trip(): void {
		$side = self::side( self::PREVIOUS_VERSION, null )->with_versions( true, 1, self::LIVE_VERSION );

		$read = Side::from_array( $side->to_array() );

		$this->assertSame( array( true, 1, self::LIVE_VERSION ), array( $read?->pinned, $read?->previous_version, $read?->latest_version ) );
		// Stored before sides could be pinned: it follows its template.
		$stored = array(
			'key'      => 'front',
			'template' => array(
				'baseId'  => 'mini-tee',
				'version' => self::PREVIOUS_VERSION,
			),
		);
		$this->assertFalse( Side::from_array( $stored )?->pinned );
	}

	public function test_the_versions_offered_run_from_the_latest_known_down_to_the_first(): void {
		$this->assertSame( array( self::LIVE_VERSION, self::PREVIOUS_VERSION, 1 ), self::side( self::LIVE_VERSION, null )->versions() );
		$this->assertSame( array(), Side::from_array( array( 'key' => 'front' ) )?->versions() );
	}

	public function test_only_the_newest_versions_are_offered_with_the_sides_own(): void {
		$latest = Side::MAX_VERSIONS_OFFERED * 2;

		$versions = self::side( 1, null )->with_versions( true, null, $latest )->versions();

		$this->assertCount( Side::MAX_VERSIONS_OFFERED + 1, $versions );
		$this->assertSame( array( $latest, 1 ), array( $versions[0], $versions[ Side::MAX_VERSIONS_OFFERED ] ) );
	}

	public function test_the_menu_is_the_pending_one_else_the_sizes_sold_with_the_default_resized(): void {
		$custom  = new PrintSize( 210.0, 297.0, 'A4' );
		$default = new PrintSize( 280.0, 360.0, '280 × 360 mm', true );

		$this->assertSame( array( $custom, null ), self::side( self::LIVE_VERSION, null, array( $custom, $default ) )->menu() );
		$this->assertSame( array( null ), self::side( self::LIVE_VERSION, null, array( $custom ), array( null ) )->menu() );
		$this->assertSame( array(), self::side( self::LIVE_VERSION, null )->menu() );
	}

	public function test_the_starting_size_round_trips_and_follows_a_new_version(): void {
		$sizes = array( new PrintSize( 210.0, 297.0, 'A4' ), new PrintSize( 297.0, 420.0, 'A3' ) );
		$side  = new Side( 'front', 'Front', 'front', 'Front', 'mini-tee', self::LIVE_VERSION, null, 'Mini tee', $sizes, null, 1 );

		$this->assertSame( 1, Side::from_array( $side->to_array() )?->start_size );
		$this->assertSame( 1, $side->rolled_to( self::LIVE_VERSION + 1 )->start_size );
		$this->assertSame( 0, $side->with( self::LIVE_VERSION, null, $sizes, null, 0 )->start_size );
	}

	public function test_the_designer_starts_on_the_first_size_when_the_one_chosen_is_not_sold(): void {
		$size = new PrintSize( 210.0, 297.0, 'A4' );

		$this->assertSame( 1, self::side( self::LIVE_VERSION, null, array( $size, $size ) )->with( self::LIVE_VERSION, null, array( $size, $size ), null, 1 )->selected_size_index() );
		$this->assertSame( 0, self::side( self::LIVE_VERSION, null, array( $size ) )->with( self::LIVE_VERSION, null, array( $size ), null, 1 )->selected_size_index() );
		// A menu waiting for a check sells nothing yet.
		$this->assertSame( 0, self::side( self::LIVE_VERSION, null )->with( self::LIVE_VERSION, null, array(), array( null, $size ), 1 )->selected_size_index() );
		$this->assertSame(
			0,
			Side::from_array(
				array(
					'key'       => 'front',
					'startSize' => -1,
				)
			)?->start_size
		);
	}

	public function test_a_standing_side_is_printed_at_its_starting_size_and_forgets_what_it_read_of_a_version_it_leaves(): void {
		$small = new PrintSize( 100.0, 125.0, 'S' );
		$large = new PrintSize( 200.0, 250.0, 'L' );
		$side  = self::side( self::LIVE_VERSION, null, array( $small, $large ) )->with( self::LIVE_VERSION, null, array( $small, $large ), null, 1 )->with_offers_properties( true );

		$this->assertSame( $large, $side->standing_size() );
		$this->assertTrue( $side->offers_properties );
		$this->assertNull( $side->rolled_to( self::LIVE_VERSION + 1 )->offers_properties );
		$this->assertNull( self::side( self::LIVE_VERSION, null )->standing_size() );
	}

	public function test_a_side_with_a_design_per_row_finds_the_one_of_the_variant_sold(): void {
		$small = new StandingRow( array( 'Size' => 'S' ), 'des_s', array( 'v_white_s', 'v_black_s' ), true, new PrintSize( 160.0, 200.0, '160 × 200 mm' ), 'prf_s' );
		$large = new StandingRow( array( 'Size' => 'L' ), 'des_l', array( 'v_white_l' ) );
		$side  = self::side( self::LIVE_VERSION, null )->with_standing_design( 'des_one' )->with_standing_rows( array( $small, $large ) );

		$this->assertNull( $side->standing_design_id, 'rows replace the one design' );
		$this->assertSame( 'des_s', $side->standing_design_for( 'v_black_s' ) );
		$this->assertSame( 'des_l', $side->standing_design_for( 'v_white_l' ) );
		$this->assertNull( $side->standing_design_for( 'v_red_xl' ), 'a variant in no row has no design' );
		$this->assertNull( $side->standing_design_for( null ) );
		$this->assertEquals( $side, Side::from_array( $side->to_array() ) );
		$this->assertEquals( array( $small, $large ), $side->rolled_to( self::LIVE_VERSION + 1 )->standing_rows, 'kept until the next save finds them again' );
	}

	public function test_the_sizes_a_template_misfits_round_trip_and_survive_its_other_copies(): void {
		$side = self::side( self::LIVE_VERSION, null )->with_misfit_sizes( array( 'XL', '3XL' ) );

		$this->assertSame( array( 'XL', '3XL' ), Side::from_array( $side->to_array() )?->misfit_sizes );
		$this->assertSame( array( 'XL', '3XL' ), $side->with_standing_rows( array() )->with_standing_design( 'des_one' )->with_offers_properties( true )->with( self::LIVE_VERSION, null, array(), null )->misfit_sizes );

		$stored                = $side->to_array();
		$stored['misfitSizes'] = array( 'XL', '', 3, null );
		$this->assertSame( array( 'XL' ), Side::from_array( $stored )?->misfit_sizes, 'only text is kept' );
	}

	public function test_a_side_with_one_design_prints_every_variant_from_it(): void {
		$side = self::side( self::LIVE_VERSION, null )->with_standing_rows( array( new StandingRow( array( 'Size' => 'S' ), 'des_s', array( 'v_s' ) ) ) )->with_standing_design( 'des_one' );

		$this->assertSame( array(), $side->standing_rows );
		$this->assertSame( 'des_one', $side->standing_design_for( 'v_s' ) );
		$this->assertSame( 'des_one', $side->standing_design_for( null ) );
	}

	public function test_a_stored_row_without_a_design_or_values_is_dropped(): void {
		$stored                 = self::side( self::LIVE_VERSION, null )->to_array();
		$stored['standingRows'] = array(
			array(
				'row'      => array( 'Size' => 'M' ),
				'designId' => 'des_m',
				'variants' => array( 'v_m', 7 ),
			),
			array(
				'row'      => array(),
				'designId' => 'des_x',
			),
			array( 'row' => array( 'Size' => 'L' ) ),
		);

		$rows = Side::from_array( $stored )?->standing_rows;

		$this->assertEquals( array( new StandingRow( array( 'Size' => 'M' ), 'des_m', array( 'v_m' ) ) ), $rows );
	}

	public function test_a_row_laid_out_by_hand_keeps_what_it_was_adjusted_on(): void {
		$adjusted = new LayoutAdjustment( 'mini-tee@2', 'blk_tee', 'front', new PrintSize( 150.0, 190.0, 'S size' ) );
		$row      = new StandingRow( array( 'Size' => 'S' ), 'des_s_adjusted', array( 'v_s' ), false, null, 'prf_s', $adjusted );

		$read = Side::from_array( self::side( self::LIVE_VERSION, null )->with_standing_rows( array( $row ) )->to_array() )?->standing_rows[0];

		$this->assertEquals( $adjusted, $read?->adjusted );
		$this->assertEquals( array( 'v_s', 'v_s2' ), $read?->with_variants( array( 'v_s', 'v_s2' ) )->variants );
		$this->assertEquals( $adjusted, $read?->with_variants( array() )->adjusted );
	}

	public function test_an_adjustment_holds_on_its_template_in_any_version_blank_and_placement_only(): void {
		$adjusted = new LayoutAdjustment( 'mini-tee@2', 'blk_tee', 'front', null );

		$this->assertTrue( $adjusted->holds( 'mini-tee', 'blk_tee', 'front' ) );
		$this->assertFalse( $adjusted->holds( 'other-tee', 'blk_tee', 'front' ) );
		$this->assertFalse( $adjusted->holds( 'mini-tee', 'blk_hoodie', 'front' ) );
		$this->assertFalse( $adjusted->holds( 'mini-tee', 'blk_tee', 'back' ) );
		$this->assertNull( LayoutAdjustment::from_array( array( 'templateId' => 'mini-tee@2' ) ) );
	}

	public function test_a_row_is_known_by_its_values_in_any_order(): void {
		$row = new StandingRow(
			array(
				'Size' => 'S',
				'Fit'  => 'Tall',
			),
			'des_s',
			array()
		);

		$this->assertTrue(
			$row->is(
				array(
					'Fit'  => 'Tall',
					'Size' => 'S',
				)
			)
		);
		$this->assertFalse( $row->is( array( 'Size' => 'S' ) ) );
	}

	public function test_a_name_the_merchant_typed_is_kept(): void {
		$side = new Side( 'front', 'Chest print', 'front', 'Front', 'mini-tee', self::LIVE_VERSION, null, 'Mini tee' );

		$this->assertSame( 'Chest print', $side->typed_label() );
		$this->assertSame( 'Chest print', Side::from_array( $side->to_array() )?->label );
		$this->assertSame( 'Chest print', $side->with_template_name( 'Mini tee, new' )->label );
	}

	/**
	 * @return iterable<string, array{string}>
	 */
	public static function followed_names(): iterable {
		yield 'the placement name' => array( 'Front' );
		yield 'the template name' => array( 'Mini tee' );
		yield 'the template id' => array( 'mini-tee' );
		yield 'the key' => array( 'front' );
		yield 'another template id' => array( 'kids-poster-safari-copy-17' );
	}

	/**
	 * @dataProvider followed_names
	 *
	 * @param string $label The side's stored name.
	 */
	public function test_a_name_the_side_follows_is_not_typed( string $label ): void {
		$side = new Side( 'front', $label, 'front', 'Front', 'mini-tee', self::LIVE_VERSION, null, 'Mini tee' );

		$this->assertSame( '', $side->typed_label() );
	}

	public function test_a_stored_template_id_reads_back_as_what_the_side_follows(): void {
		$on_blank   = new Side( 'design', 'kids-poster-safari-copy-17', 'print', 'Print', 'kids-poster-hearts', self::LIVE_VERSION, null, 'Kids poster, hearts' );
		$no_blank   = new Side( 'design', 'kids-poster-safari-copy-17', null, null, 'kids-poster-hearts', self::LIVE_VERSION, null, 'Kids poster, hearts' );
		$no_name_at = new Side( 'design', 'kids-poster-hearts', null, null, 'kids-poster-hearts', self::LIVE_VERSION, null, null );

		$this->assertSame( 'Print', Side::from_array( $on_blank->to_array() )?->label );
		$this->assertSame( 'Kids poster, hearts', Side::from_array( $no_blank->to_array() )?->label );
		$this->assertSame( 'design', Side::from_array( $no_name_at->to_array() )?->label );
	}

	public function test_a_name_that_followed_the_template_follows_its_new_name(): void {
		$side = new Side( 'design', 'Mini tee', null, null, 'mini-tee', self::LIVE_VERSION, null, 'Mini tee' );

		$this->assertSame( 'Mini tee, new', $side->with_template_name( 'Mini tee, new' )->label );
	}

	public function test_an_empty_name_follows_the_placement_then_the_template_then_the_key(): void {
		$this->assertSame( 'Front', Side::followed_label( 'Front', 'Mini tee', 'front' ) );
		$this->assertSame( 'Mini tee', Side::followed_label( null, 'Mini tee', 'front' ) );
		$this->assertSame( 'Mini tee', Side::followed_label( '', 'Mini tee', 'front' ) );
		$this->assertSame( 'front', Side::followed_label( null, null, 'front' ) );
	}

	/**
	 * @param int         $version  Live version.
	 * @param int|null    $previous Previous version.
	 * @param PrintSize[] $sizes    Sizes sold.
	 * @param array|null  $pending  Menu kept unchecked.
	 * @phpstan-param list<PrintSize> $sizes
	 * @phpstan-param list<PrintSize|null>|null $pending
	 */
	private static function side( int $version, ?int $previous, array $sizes = array(), ?array $pending = null ): Side {
		return new Side( 'front', 'Front', 'front', 'Front', 'mini-tee', $version, $previous, 'Mini tee', $sizes, $pending );
	}
}
