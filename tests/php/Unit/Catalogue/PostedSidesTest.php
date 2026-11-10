<?php
/**
 * @package Pixonaut\WooCommerce
 */

declare(strict_types=1);

namespace Pixonaut\WooCommerce\Tests\Unit\Catalogue;

use Brain\Monkey\Functions;
use Pixonaut\WooCommerce\Catalogue\CanvasRatio;
use Pixonaut\WooCommerce\Catalogue\InvalidReason;
use Pixonaut\WooCommerce\Catalogue\PostedSides;
use Pixonaut\WooCommerce\Catalogue\PrintSize;
use Pixonaut\WooCommerce\Catalogue\ProductLinker;
use Pixonaut\WooCommerce\Catalogue\Side;
use Pixonaut\WooCommerce\Catalogue\SideInput;
use Pixonaut\WooCommerce\Tests\Unit\TestCase;

final class PostedSidesTest extends TestCase {

	/**
	 * Most sides Pixonaut takes in one designer link (`maxItems` of `sides`).
	 */
	private const LINK_MAX_SIDES = 10;

	protected function setUp(): void {
		parent::setUp();
		Functions\stubTranslationFunctions();
	}

	public function test_reads_each_side_in_order(): void {
		$posted = PostedSides::parse(
			array(
				self::side( 'front', 'Front', array( self::default_size(), self::custom( '210', '297', 'A4' ) ) ),
				self::side( 'back', 'Back', array() ),
			)
		);

		$this->assertNull( $posted->problem );
		$this->assertCount( 2, $posted->sides );
		$front = $posted->sides[0];
		$this->assertSame( 'front', $front->key );
		$this->assertSame( 'Front', $front->label );
		$this->assertSame( 'front', $front->placement_id );
		$this->assertSame( 'mini-tee@2', $front->template_id );
		$this->assertEquals( array( null, new PrintSize( 210.0, 297.0, 'A4' ) ), $front->sizes );
		// A side with no sizes is sold in the placement's default.
		$this->assertSame( array( null ), $posted->sides[1]->sizes );
	}

	public function test_reads_a_sides_sizes_per_value_of_the_attribute_chosen(): void {
		$side                             = self::side( 'front', 'Front', array() );
		$side[ PostedSides::VALUE_SIZES ] = array(
			'attribute' => 'pa_size',
			'rows'      => array(
				'pa_size' => array(
					array(
						'value'  => '30x40',
						'name'   => '30×40 cm',
						'width'  => '300',
						'height' => '400',
						'label'  => '',
					),
					array(
						'value'  => '50x70',
						'name'   => '50×70 cm',
						'width'  => '500,5',
						'height' => '700',
						'label'  => 'Large',
					),
					array(
						'value'  => '70x100',
						'name'   => '70×100 cm',
						'width'  => '',
						'height' => '',
						'label'  => '',
					),
				),
				'colour'  => array(
					array(
						'value'  => 'red',
						'width'  => '100',
						'height' => '100',
					),
				),
			),
		);

		$posted = PostedSides::parse( array( $side ) );

		$this->assertNull( $posted->problem );
		$sizes = $posted->sides[0]->value_sizes;
		$this->assertNotNull( $sizes );
		$this->assertSame( 'pa_size', $sizes->attribute );
		$this->assertEquals(
			array(
				'30x40' => new PrintSize( 300.0, 400.0, '30×40 cm' ),
				'50x70' => new PrintSize( 500.5, 700.0, 'Large' ),
			),
			$sizes->sizes,
			'an empty label is the value’s name; a value left empty has no size; another attribute’s rows are not read'
		);
	}

	public function test_a_value_size_without_a_usable_width_is_a_problem(): void {
		$side                             = self::side( 'front', 'Front', array() );
		$side[ PostedSides::VALUE_SIZES ] = array(
			'attribute' => 'pa_size',
			'rows'      => array(
				'pa_size' => array(
					array(
						'value'  => '30x40',
						'width'  => 'abc',
						'height' => '400',
					),
				),
			),
		);

		$posted = PostedSides::parse( array( $side ) );

		$this->assertSame( InvalidReason::INVALID_PRINT_SIZE, $posted->problem );
		$this->assertTrue( $posted->sides[0]->value_sizes?->is_empty() );
	}

	public function test_a_side_without_the_field_keeps_its_sizes_per_value(): void {
		$posted = PostedSides::parse( array( self::side( 'front', 'Front', array() ) ) );

		$this->assertNull( $posted->sides[0]->value_sizes );
	}

	public function test_reads_a_sides_mode_personalised_unless_standing(): void {
		$standing         = self::side( 'front', 'Front', array() );
		$standing['mode'] = Side::STANDING;
		$unknown          = self::side( 'back', 'Back', array() );
		$unknown['mode']  = 'printed';

		$posted = PostedSides::parse( array( $standing, $unknown, self::side( 'sleeve', 'Sleeve', array() ) ) );

		$this->assertSame( array( Side::STANDING, Side::PERSONALISED, Side::PERSONALISED ), array_map( static fn ( SideInput $side ): string => $side->mode, $posted->sides ) );
	}

	public function test_reads_a_sides_canvas_ratio_following_unless_chosen(): void {
		$template                              = self::side( 'front', 'Front', array() );
		$template[ PostedSides::CANVAS_RATIO ] = CanvasRatio::TEMPLATE;
		$size                                  = self::side( 'back', 'Back', array() );
		$size[ PostedSides::CANVAS_RATIO ]     = CanvasRatio::PRINT_SIZE;
		$unknown                               = self::side( 'sleeve', 'Sleeve', array() );
		$unknown[ PostedSides::CANVAS_RATIO ]  = 'square';

		$posted = PostedSides::parse( array( $template, $size, $unknown, self::side( 'inside', 'Inside', array() ) ) );

		$this->assertSame( array( true, false, null, null ), array_map( static fn ( SideInput $side ): ?bool => $side->draw_at_template_ratio, $posted->sides ) );
	}

	public function test_a_side_stuck_to_one_version_is_linked_to_the_version_picked(): void {
		$pinned              = self::side( 'front', 'Front', array() );
		$pinned['pinned']    = PostedSides::PINNED;
		$pinned['version']   = '1';
		$followed            = self::side( 'back', 'Back', array() );
		$followed['version'] = '1';
		$unpicked            = self::side( 'sleeve', 'Sleeve', array() );
		$unpicked['pinned']  = PostedSides::PINNED;
		// Later than the latest the template field lists.
		$unlisted            = self::side( 'pocket', 'Pocket', array() );
		$unlisted['pinned']  = PostedSides::PINNED;
		$unlisted['version'] = '999999999';

		$posted = PostedSides::parse( array( $pinned, $followed, $unpicked, $unlisted ) );

		$this->assertSame(
			array( array( 'mini-tee@1', true ), array( 'mini-tee@2', false ), array( 'mini-tee@2', true ), array( 'mini-tee@2', true ) ),
			array_map( static fn ( SideInput $side ): array => array( $side->template_id, $side->pinned ), $posted->sides )
		);
	}

	public function test_reads_a_sides_arrangement(): void {
		Functions\when( 'sanitize_title' )->returnArg();
		Functions\when( 'wp_strip_all_tags' )->returnArg();
		$front                = self::side( 'front', 'Front', array() );
		$front['form_groups'] = array(
			'groups' => array(
				array(
					'id'    => 'text-front',
					'items' => array( array( 'attribute' => 'size' ) ),
				),
			),
		);

		$posted = PostedSides::parse( array( $front, self::side( 'back', 'Back', array() ) ) );

		$this->assertSame( array( 'size' ), $posted->sides[0]->form_groups?->attributes() );
		$this->assertNull( $posted->sides[1]->form_groups );
	}

	public function test_a_placement_used_twice_is_refused(): void {
		$posted = PostedSides::parse(
			array(
				self::side( 'front', 'Front' ),
				self::side( 'front', 'Front again', array(), 'second' ),
			)
		);

		$this->assertSame( InvalidReason::DUPLICATE_PLACEMENT, $posted->problem );
		// Both are kept, so the store manager sees what to fix.
		$this->assertCount( 2, $posted->sides );
	}

	public function test_the_first_sides_own_problem_is_told_apart_from_the_others(): void {
		$posted = PostedSides::parse(
			array(
				self::side( 'front', 'Front' ),
				self::side( 'back', 'Back', array( self::custom( '', '300', 'Broken' ) ) ),
			)
		);

		$this->assertSame( InvalidReason::INVALID_PRINT_SIZE, $posted->problem );
		$this->assertNull( $posted->first_problem, 'a product without a blank keeps the first side alone' );

		$first = PostedSides::parse( array( self::side( 'front', 'Front', array( self::custom( '', '300', 'Broken' ) ) ) ) );
		$this->assertSame( InvalidReason::INVALID_PRINT_SIZE, $first->first_problem );
	}

	public function test_a_key_used_twice_is_refused(): void {
		$posted = PostedSides::parse(
			array(
				self::side( 'front', 'Front', array(), 'side' ),
				self::side( 'back', 'Back', array(), 'side' ),
			)
		);

		$this->assertSame( InvalidReason::DUPLICATE_SIDE_KEY, $posted->problem );
	}

	public function test_a_key_is_made_one_pixonaut_takes_and_defaults_to_the_placement(): void {
		$posted = PostedSides::parse(
			array(
				self::side( 'front', 'Front', array(), '  Front Side!  ' ),
				self::side( 'Back_1', 'Back', array(), '' ),
			)
		);

		$this->assertSame( 'front-side', $posted->sides[0]->key );
		$this->assertSame( 'back_1', $posted->sides[1]->key );
		$this->assertSame( str_repeat( 'k', PostedSides::KEY_MAX_LENGTH ), PostedSides::key_from( str_repeat( 'K', PostedSides::KEY_MAX_LENGTH + 1 ) ) );
	}

	public function test_a_key_of_digits_alone_is_prefixed(): void {
		$posted = PostedSides::parse(
			array(
				self::side( 'front', 'Front', array(), '123' ),
				self::side( '1', 'Back', array(), '' ),
			)
		);

		$this->assertSame( 'side-123', $posted->sides[0]->key );
		$this->assertSame( 'side-1', $posted->sides[1]->key );
		$long = PostedSides::key_from( str_repeat( '9', PostedSides::KEY_MAX_LENGTH ) );
		$this->assertSame( PostedSides::KEY_MAX_LENGTH, strlen( $long ) );
		$this->assertStringStartsWith( PostedSides::NUMERIC_KEY_PREFIX, $long );
	}

	public function test_a_side_without_key_or_placement_keeps_the_single_side_key(): void {
		$posted = PostedSides::parse( array( self::side( '', '', array(), '' ) ) );

		$this->assertSame( ProductLinker::SINGLE_SIDE_KEY, $posted->sides[0]->key );
	}

	/**
	 * @return array<string, array{string, string}>
	 */
	public static function unusable_sizes(): array {
		return array(
			'zero width'      => array( '0', '297' ),
			'negative height' => array( '210', '-1' ),
			'not a number'    => array( 'wide', '297' ),
			'missing height'  => array( '210', '' ),
			'not finite'      => array( '1e999', '297' ),
		);
	}

	/**
	 * @dataProvider unusable_sizes
	 *
	 * @param string $width  Width as typed.
	 * @param string $height Height as typed.
	 */
	public function test_an_unusable_custom_size_is_dropped_and_flagged( string $width, string $height ): void {
		$posted = PostedSides::parse( array( self::side( 'front', 'Front', array( self::custom( $width, $height, 'Odd' ), self::custom( '210', '297', 'A4' ) ) ) ) );

		$this->assertSame( InvalidReason::INVALID_PRINT_SIZE, $posted->problem );
		$this->assertEquals( array( new PrintSize( 210.0, 297.0, 'A4' ) ), $posted->sides[0]->sizes );
	}

	public function test_a_menu_of_only_unusable_sizes_is_not_replaced_by_the_default(): void {
		$posted = PostedSides::parse( array( self::side( 'front', 'Front', array( self::custom( '0', '0', '' ) ) ) ) );

		$this->assertSame( InvalidReason::INVALID_PRINT_SIZE, $posted->problem );
		$this->assertSame( array(), $posted->sides[0]->sizes );
	}

	public function test_an_unusable_size_on_one_side_leaves_another_sides_empty_menu_the_default(): void {
		$posted = PostedSides::parse(
			array(
				self::side( 'front', 'Front', array( self::custom( '0', '0', '' ), self::custom( '210', '297', 'A4' ) ) ),
				self::side( 'back', 'Back', array() ),
			)
		);

		$this->assertSame( InvalidReason::INVALID_PRINT_SIZE, $posted->problem );
		$this->assertSame( array( null ), $posted->sides[1]->sizes );
	}

	public function test_a_custom_size_takes_a_decimal_comma_and_a_default_label(): void {
		$posted = PostedSides::parse( array( self::side( 'front', 'Front', array( self::custom( '210,5', '297', '' ) ) ) ) );

		$this->assertEquals( array( new PrintSize( 210.5, 297.0, '210.5 × 297 mm' ) ), $posted->sides[0]->sizes );
	}

	public function test_a_second_default_entry_adds_nothing(): void {
		$posted = PostedSides::parse( array( self::side( 'front', 'Front', array( self::default_size(), self::default_size() ) ) ) );

		$this->assertNull( $posted->problem );
		$this->assertSame( array( null ), $posted->sides[0]->sizes );
	}

	public function test_the_size_row_marked_to_start_is_found_among_the_sizes_kept(): void {
		// Row keys skip numbers once a row was removed, and an unusable row is dropped.
		$side = self::side(
			'front',
			'Front',
			array(
				0 => self::custom( '0', '0', '' ),
				2 => self::default_size(),
				5 => self::custom( '210', '297', 'A4' ),
			)
		);

		$posted = PostedSides::parse( array( array( 'start_size' => '5' ) + $side ) );

		$this->assertSame( 1, $posted->sides[0]->start );
	}

	public function test_a_side_starts_on_its_first_size_when_none_or_a_dropped_row_is_marked(): void {
		$side = self::side( 'front', 'Front', array( self::custom( '210', '297', 'A4' ), self::custom( '0', '0', '' ) ) );

		$this->assertSame( 0, PostedSides::parse( array( $side ) )->sides[0]->start );
		$this->assertSame( 0, PostedSides::parse( array( array( 'start_size' => '1' ) + $side ) )->sides[0]->start );
	}

	public function test_a_second_default_entry_marked_to_start_starts_on_the_first(): void {
		$side = self::side( 'front', 'Front', array( self::custom( '210', '297', 'A4' ), self::default_size(), self::default_size() ) );

		$this->assertSame( 1, PostedSides::parse( array( array( 'start_size' => '2' ) + $side ) )->sides[0]->start );
	}

	public function test_more_sizes_than_one_check_judges_are_refused(): void {
		$sizes  = array_fill( 0, PostedSides::MAX_SIZES + 1, self::custom( '210', '297', 'A4' ) );
		$posted = PostedSides::parse( array( self::side( 'front', 'Front', $sizes ) ) );

		$this->assertSame( InvalidReason::TOO_MANY_PRINT_SIZES, $posted->problem );
		$this->assertCount( PostedSides::MAX_SIZES, $posted->sides[0]->sizes );
	}

	public function test_more_sides_than_a_designer_takes_are_refused(): void {
		$rows = array();
		for ( $i = 0; $i <= PostedSides::MAX_SIDES; $i++ ) {
			$rows[] = self::side( 'placement_' . $i, 'Side ' . $i );
		}

		$posted = PostedSides::parse( $rows );

		$this->assertSame( InvalidReason::TOO_MANY_SIDES, $posted->problem );
		$this->assertCount( PostedSides::MAX_SIDES, $posted->sides );
	}

	public function test_ten_sides_are_kept_past_the_recommended_four(): void {
		$rows = array();
		for ( $i = 0; $i < self::LINK_MAX_SIDES; $i++ ) {
			$rows[] = self::side( 'placement_' . $i, 'Side ' . $i );
		}

		$posted = PostedSides::parse( $rows );

		$this->assertNull( $posted->problem );
		$this->assertCount( self::LINK_MAX_SIDES, $posted->sides );
		$this->assertGreaterThan( PostedSides::RECOMMENDED_SIDES, count( $posted->sides ) );
	}

	public function test_an_untouched_row_is_skipped(): void {
		$posted = PostedSides::parse(
			array(
				self::side( 'front', 'Front' ),
				array(
					'key'       => '',
					'label'     => '',
					'placement' => '',
					'template'  => '',
					'sizes'     => array( self::default_size() ),
				),
				'not a row',
			)
		);

		$this->assertNull( $posted->problem );
		$this->assertCount( 1, $posted->sides );
	}

	public function test_nothing_posted_is_no_sides(): void {
		$posted = PostedSides::parse( null );

		$this->assertSame( array(), $posted->sides );
		$this->assertNull( $posted->problem );
	}

	/**
	 * A posted side on `$placement` with the live tee template.
	 *
	 * @param string                            $placement Placement id.
	 * @param string                            $label     Side label.
	 * @param array<int, array<string, string>> $sizes Size rows, by the row keys posted.
	 * @param string|null                       $key       Typed key; null for the placement's.
	 * @return array<string, mixed>
	 */
	private static function side( string $placement, string $label, array $sizes = array(), ?string $key = null ): array {
		return array(
			'key'           => $key ?? $placement,
			'label'         => $label,
			'placement'     => $placement,
			'template'      => 'mini-tee@2',
			'template_name' => 'Mini tee',
			'sizes'         => $sizes,
		);
	}

	/**
	 * @return array<string, string>
	 */
	private static function default_size(): array {
		return array( 'kind' => PostedSides::SIZE_PLACEMENT_DEFAULT );
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
