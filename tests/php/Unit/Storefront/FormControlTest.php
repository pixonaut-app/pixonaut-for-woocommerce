<?php
/**
 * @package Pixonaut\WooCommerce
 */

declare(strict_types=1);

namespace Pixonaut\WooCommerce\Tests\Unit\Storefront;

use Pixonaut\WooCommerce\Storefront\FormControl;
use Pixonaut\WooCommerce\Tests\Unit\TestCase;

final class FormControlTest extends TestCase {

	public function test_a_picture_to_upload_is_an_upload_button(): void {
		$control = FormControl::field( self::field( 'image', 'src', array( 'allowUpload' => true ) ), array() );

		$this->assertSame( 'image', $control['type'] );
		$this->assertTrue( $control['upload'] );
		$this->assertSame( array(), $control['options'] );
	}

	public function test_a_picture_from_a_list_has_a_no_picture_tile_first_unless_required(): void {
		$optional = FormControl::field( self::field( 'image', 'src', array( 'oneOf' => array( 'ast_a', 'ast_b' ) ) ), array() );
		$required = FormControl::field(
			self::field(
				'image',
				'src',
				array(
					'oneOf'    => array( 'ast_a' ),
					'required' => true,
				)
			),
			array()
		);

		$this->assertSame( array( '', 'ast_a', 'ast_b' ), $optional['options'] );
		$this->assertSame( 0, $optional['chosen'] );
		$this->assertFalse( $optional['upload'] );
		$this->assertSame( array( 'ast_a' ), $required['options'] );
		$this->assertSame( -1, $required['chosen'] );
	}

	public function test_a_picture_from_a_list_shows_its_default_chosen(): void {
		$control = FormControl::field( self::field( 'image', 'src', array( 'oneOf' => array( 'ast_a', 'ast_b' ) ), 'ast_b' ), array() );

		$this->assertSame( 2, $control['chosen'] );
	}

	public function test_a_font_choice_is_tiles_with_the_default_chosen(): void {
		$control = FormControl::field( self::field( 'select', 'fontFamily', array( 'oneOf' => array( 'Anton', 'Poppins' ) ), 'Poppins' ), array() );

		$this->assertSame( array( 'tiles', 'font' ), array( $control['type'], $control['kind'] ) );
		$this->assertSame( array( 'Anton', 'Poppins' ), $control['options'] );
		$this->assertSame( 1, $control['chosen'] );
	}

	public function test_a_fit_choice_is_tiles_of_its_fits(): void {
		$control = FormControl::field( self::field( 'select', 'fit', array( 'oneOf' => array( 'cover', 'contain' ) ), 'contain' ), array() );

		$this->assertSame( array( 'tiles', 'fit' ), array( $control['type'], $control['kind'] ) );
		$this->assertSame( array( 'cover', 'contain' ), $control['options'] );
		$this->assertSame( 1, $control['chosen'] );
	}

	public function test_a_shape_choice_starts_on_the_authored_shape(): void {
		$field   = array( 'layerId' => 'badge' ) + self::field( 'select', 'shape', array( 'oneOf' => array( 'shp_star', 'shp_heart' ) ), array( 'type' => 'path' ) );
		$control = FormControl::field( $field, array(), array( 'badge' => 'shp_heart' ) );

		$this->assertSame( array( 'tiles', 'shape' ), array( $control['type'], $control['kind'] ) );
		$this->assertSame( array( 'shp_star', 'shp_heart' ), $control['options'] );
		$this->assertSame( 1, $control['chosen'] );
	}

	public function test_a_shape_choice_that_allows_none_leads_with_it_chosen_for_no_shape(): void {
		$constraints = array(
			'oneOf'      => array( 'shp_star' ),
			'allowEmpty' => true,
		);
		$square      = array(
			'type'  => 'path',
			'dFrac' => 'M 0 0 L 1 0 L 1 1 L 0 1 Z',
		);
		$none        = FormControl::field( self::field( 'select', 'shape', $constraints, $square ), array() );
		$star        = FormControl::field( self::field( 'select', 'shape', $constraints, $square ), array(), array( 'layer' => 'shp_star' ) );

		$this->assertSame( array( '', 'shp_star' ), $none['options'] );
		$this->assertSame( 0, $none['chosen'] );
		$this->assertSame( 1, $star['chosen'] );
	}

	public function test_any_other_choice_is_a_menu_showing_the_default(): void {
		$control = FormControl::field( self::field( 'select', 'align', array( 'oneOf' => array( 'left', 'center' ) ), 'center' ), array() );

		$this->assertSame( 'select', $control['type'] );
		$this->assertSame( 'center', $control['value'] );
	}

	public function test_a_colour_is_a_swatch_and_names_an_rgb_colour_beside_its_label(): void {
		$rgb  = FormControl::field( self::field( 'color', 'fill', array(), array( 'rgb' => array( 28, 43, 94 ) ) ), array() );
		$cmyk = FormControl::field( self::field( 'color', 'fill', array(), array( 'cmyk' => array( 0, 100, 100, 0 ) ) ), array() );

		$this->assertSame( 'color', $rgb['type'] );
		$this->assertSame( '#1c2b5e', $rgb['value'] );
		$this->assertSame( '#1C2B5E', $rgb['aside'] );
		$this->assertSame( '#ff0000', $cmyk['value'] );
		$this->assertSame( '', $cmyk['aside'] );
	}

	public function test_a_palette_names_the_chosen_entry_beside_its_label(): void {
		$control = FormControl::field( self::field( 'color', 'fill', array( 'palette' => 'Brand' ), 'Navy' ), self::palettes() );

		$this->assertSame( 'palette', $control['type'] );
		$this->assertSame( array( '#ff0000', '#1c2b5e' ), $control['options'] );
		$this->assertSame( array( 1, 'Navy', false ), array( $control['chosen'], $control['aside'], $control['custom'] ) );
	}

	public function test_a_palette_offering_any_colour_shows_custom_and_a_default_of_its_own_in_the_well(): void {
		$constraints = array(
			'palette'  => 'Brand',
			'anyColor' => true,
		);
		$inside      = FormControl::field( self::field( 'color', 'fill', $constraints, array( 'rgb' => array( 255, 0, 0 ) ) ), self::palettes() );
		$outside     = FormControl::field( self::field( 'color', 'fill', $constraints, array( 'rgb' => array( 0, 128, 0 ) ) ), self::palettes() );

		$this->assertSame( array( true, 0, 'Red', '' ), array( $inside['custom'], $inside['chosen'], $inside['aside'], $inside['value'] ) );
		$this->assertSame( array( true, -1, '', '#008000' ), array( $outside['custom'], $outside['chosen'], $outside['aside'], $outside['value'] ) );
	}

	public function test_a_gradient_is_a_colour_control_per_colour_along_it(): void {
		$gradient = array(
			'gradient' => array(
				'kind'  => 'linear',
				'stops' => array(
					array(
						'at'    => 0,
						'color' => array( 'rgb' => array( 255, 0, 0 ) ),
					),
					array(
						'at'    => 1,
						'color' => array( 'rgb' => array( 0, 0, 255 ) ),
					),
				),
			),
		);
		$free     = FormControl::field( self::field( 'color', 'fill', array(), $gradient ), array() );
		$branded  = FormControl::field( self::field( 'color', 'fill', array( 'palette' => 'Brand' ), $gradient ), self::palettes() );

		$this->assertSame( 'gradient', $free['type'] );
		$this->assertSame( array( 'color', 'color' ), array_column( $free['stops'], 'type' ) );
		$this->assertSame( array( '#ff0000', '#0000ff' ), array_column( $free['stops'], 'value' ) );
		$this->assertSame( array( '#FF0000', '#0000FF' ), array_column( $free['stops'], 'aside' ) );
		$this->assertSame( array( 'palette', 'palette' ), array_column( $branded['stops'], 'type' ) );
		$this->assertSame( array( 0, -1 ), array_column( $branded['stops'], 'chosen' ) );
	}

	public function test_a_crop_is_a_zoom_slider_at_the_authors_zoom(): void {
		$crop    = array(
			'xFrac' => 0,
			'yFrac' => 0,
			'zoom'  => 2,
		);
		$control = FormControl::field( self::field( 'crop', 'crop', array(), $crop ), array() );
		$bounded = FormControl::field(
			self::field(
				'crop',
				'crop',
				array(
					'minZoom' => 1.5,
					'maxZoom' => 2.5,
				)
			),
			array()
		);

		$this->assertSame( array( 'crop', 50.0, '2', array( '1', '3' ) ), array( $control['type'], $control['position'], $control['value'], $control['bounds'] ) );
		$this->assertSame( array( 0.0, '1', array( '1.5', '2.5' ) ), array( $bounded['position'], $bounded['value'], $bounded['bounds'] ) );
	}

	public function test_a_number_is_a_slider_at_its_default(): void {
		$control = FormControl::field(
			self::field(
				'number',
				'opacity',
				array(
					'min' => 0.5,
					'max' => 1.5,
				),
				1
			),
			array()
		);
		$bare    = FormControl::field( self::field( 'number', 'size', array(), 25 ), array() );

		$this->assertSame( 'number', $control['type'] );
		$this->assertSame( 50.0, $control['position'] );
		$this->assertSame( 25.0, $bare['position'] );
	}

	public function test_a_text_size_without_bounds_slides_over_the_designers_range(): void {
		// 0.005 to 0.5 of the plate's height.
		$control = FormControl::field( self::field( 'number', 'fontSizeFrac', array(), 0.25 ), array() );

		$this->assertEqualsWithDelta( 49.4949, $control['position'], 0.0001 );
	}

	public function test_text_takes_a_line_or_up_to_five_rows_and_counts_its_characters(): void {
		$line  = FormControl::field(
			self::field(
				'text',
				'content',
				array(
					'maxLines'  => 1,
					'maxLength' => 12,
				),
				'Hej'
			),
			array()
		);
		$lines = FormControl::field( self::field( 'text', 'content', array( 'maxLines' => 9 ) ), array() );
		$open  = FormControl::field( self::field( 'text', 'content', array() ), array() );

		$this->assertSame( array( 'text', 'Hej', '3 / 12', 12 ), array( $line['type'], $line['value'], $line['aside'], $line['max_length'] ) );
		$this->assertSame( array( 'text-lines', 5 ), array( $lines['type'], $lines['rows'] ) );
		$this->assertSame( array( 'text-lines', 3, '' ), array( $open['type'], $open['rows'], $open['aside'] ) );
	}

	public function test_characters_are_counted_as_a_browser_counts_them(): void {
		$control = FormControl::field( self::field( 'text', 'content', array( 'maxLength' => 10 ), "Hej \u{1F600}" ), array() );

		$this->assertSame( '6 / 10', $control['aside'] );
	}

	public function test_a_text_a_menu_a_number_and_a_free_colour_name_their_field_for_the_sdk(): void {
		$colour = array( 'rgb' => array( 255, 0, 0 ) );
		$named  = array(
			self::field( 'text', 'content', array(), 'Hello' ),
			self::field( 'select', 'align', array( 'oneOf' => array( 'left', 'right' ) ), 'left' ),
			self::field( 'number', 'opacity', array(), 50 ),
			self::field( 'color', 'color', array(), $colour ),
		);
		foreach ( $named as $field ) {
			$this->assertSame( 'layer-1:' . $field['prop'], FormControl::field( array( 'layerId' => 'layer-1' ) + $field, array() )['name'], $field['prop'] );
		}
	}

	public function test_the_artworks_fields_name_its_reserved_layer_as_given(): void {
		$field = array( 'layerId' => '$artwork' ) + self::field( 'color', 'color', array(), array( 'rgb' => array( 1, 2, 3 ) ) );

		$this->assertSame( '$artwork:color', FormControl::field( $field, array() )['name'] );
	}

	public function test_what_has_no_plain_input_or_draws_faces_names_no_field(): void {
		$palettes = array( 'brand' => array() );
		$unnamed  = array(
			self::field( 'image', 'src', array( 'allowUpload' => true ) ),
			self::field( 'crop', 'crop', array() ),
			self::field( 'select', 'fontFamily', array( 'oneOf' => array( 'Anton', 'Poppins' ) ), 'Poppins' ),
			self::field( 'select', 'fit', array( 'oneOf' => array( 'cover', 'contain' ) ), 'cover' ),
			self::field( 'select', 'shape', array( 'oneOf' => array( 'shp_a', 'shp_b' ) ) ),
			self::field( 'color', 'color', array( 'palette' => 'brand' ), array( 'rgb' => array( 1, 2, 3 ) ) ),
			self::field( 'color', 'fill', array(), array( 'gradient' => array( 'stops' => array() ) ) ),
		);
		foreach ( $unnamed as $field ) {
			$this->assertSame( '', FormControl::field( array( 'layerId' => 'layer-1' ) + $field, $palettes )['name'], $field['prop'] );
		}
	}

	public function test_a_longer_list_of_fonts_or_shapes_is_the_sdks_drawn_list_with_no_input(): void {
		$fonts  = array_map( static fn ( int $at ): string => 'Font ' . $at, range( 1, 9 ) );
		$font   = FormControl::field( array( 'layerId' => 'layer-1' ) + self::field( 'select', 'fontFamily', array( 'oneOf' => $fonts ), 'Font 3' ), array() );
		$shapes = array_map( static fn ( int $at ): string => 'shp_' . $at, range( 1, 9 ) );
		$shape  = FormControl::field( array( 'layerId' => 'layer-1' ) + self::field( 'select', 'shape', array( 'oneOf' => $shapes ) ), array() );

		$this->assertSame( array( 'select', 'Font 3', '' ), array( $font['type'], $font['value'], $font['name'] ) );
		$this->assertSame( array( 'select', '', '' ), array( $shape['type'], $shape['value'], $shape['name'] ) );
	}

	public function test_a_menu_that_does_not_hold_its_default_is_not_taken_for_the_customers_choice(): void {
		$control = FormControl::field( array( 'layerId' => 'layer-1' ) + self::field( 'select', 'align', array( 'oneOf' => array( 'left', 'right' ) ), 'center' ), array() );

		$this->assertSame( array( 'left', '' ), array( $control['value'], $control['name'] ) );
	}

	public function test_a_slider_input_prints_its_bounds_step_and_value(): void {
		$control = FormControl::field(
			array( 'layerId' => 'layer-1' ) + self::field(
				'number',
				'opacity',
				array(
					'min'  => 0,
					'max'  => 1,
					'step' => 0.05,
				),
				0.25
			),
			array()
		);

		$this->assertSame( array( '0', '1' ), $control['bounds'] );
		$this->assertSame( '0.05', $control['step'] );
		$this->assertSame( '0.25', $control['value'] );
	}

	public function test_a_slider_with_no_step_leaves_its_default_off_any_grid(): void {
		$control = FormControl::field( array( 'layerId' => 'layer-1' ) + self::field( 'number', 'opacity', array(), 0.5 ), array() );

		$this->assertSame( array( '0', '100' ), $control['bounds'] );
		$this->assertSame( 'any', $control['step'] );
	}

	public function test_a_default_outside_a_sliders_range_is_left_to_the_sdk(): void {
		$control = FormControl::field( array( 'layerId' => 'layer-1' ) + self::field( 'number', 'opacity', array( 'max' => 10 ), 50 ), array() );

		$this->assertSame( '', $control['name'] );
	}

	public function test_a_field_keeps_its_label_as_the_read_gives_it(): void {
		$control = FormControl::field(
			array(
				'layerId'        => 'layer-1',
				'label'          => 'Din text',
				'labelIsDefault' => true,
			) + self::field( 'text', 'content', array() ),
			array()
		);

		$this->assertSame( 'Din text', $control['label'] );
	}

	public function test_help_is_the_fields_description(): void {
		$control = FormControl::field( array( 'description' => ' Printed on the chest. ' ) + self::field( 'text', 'content', array() ), array() );

		$this->assertSame( 'Printed on the chest.', $control['help'] );
	}

	public function test_a_scale_slider_stands_at_full_size(): void {
		$control = FormControl::scale(
			array(
				'editable' => true,
				'min'      => 0.5,
				'max'      => 1.5,
			)
		);

		$this->assertSame( array( 'scale', '', 50.0 ), array( $control['type'], $control['label'], $control['position'] ) );
	}

	public function test_a_scale_slider_without_bounds_runs_from_a_tenth_to_ten_times(): void {
		$control = FormControl::scale( array( 'editable' => true ) );

		$this->assertEqualsWithDelta( 9.0909, $control['position'], 0.0001 );
	}

	public function test_the_drag_hint_takes_one_layers_label_and_says_when_there_are_several(): void {
		$this->assertSame( 'Move the logo', FormControl::move( array( 'Move the logo' ) )['label'] );
		$this->assertFalse( FormControl::move( array( 'Move the logo' ) )['several'] );
		$this->assertTrue( FormControl::move( array( '', '' ) )['several'] );
	}

	/**
	 * @return array<string, list<array{name: string, css: string}>>
	 */
	private static function palettes(): array {
		return array(
			'Brand' => array(
				array(
					'name' => 'Red',
					'css'  => '#ff0000',
				),
				array(
					'name' => 'Navy',
					'css'  => '#1c2b5e',
				),
			),
		);
	}

	/**
	 * @param string               $type        The field's type.
	 * @param string               $prop        The layer prop it sets.
	 * @param array<string, mixed> $constraints Its constraints.
	 * @param mixed                $fallback    Its default.
	 * @return array<string, mixed>
	 */
	private static function field( string $type, string $prop, array $constraints, mixed $fallback = null ): array {
		return array(
			'layerId'     => 'layer',
			'prop'        => $prop,
			'type'        => $type,
			'label'       => 'Field',
			'constraints' => $constraints,
			'default'     => $fallback,
		);
	}
}
