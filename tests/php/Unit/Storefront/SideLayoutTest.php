<?php
/**
 * @package Pixonaut\WooCommerce
 */

declare(strict_types=1);

namespace Pixonaut\WooCommerce\Tests\Unit\Storefront;

use Brain\Monkey\Functions;
use Pixonaut\WooCommerce\Catalogue\FormGroups;
use Pixonaut\WooCommerce\Storefront\SideLayout;
use Pixonaut\WooCommerce\Tests\Unit\TestCase;

final class SideLayoutTest extends TestCase {

	/** The template's picture group, as `arranged_reads()` names it. */
	private const PICTURE_GROUP = 'picture';

	/** The Text group renamed, the size placed before its slogan, reworded. */
	private const ARRANGED = array(
		'formGroups' => array(
			'groups' => array(
				array(
					'id'    => 'text',
					'title' => 'Your words',
					'items' => array(
						array( 'attribute' => 'size' ),
						array(
							'field'       => 'slogan:content',
							'label'       => 'Name on the back',
							'help'        => 'Back of the shirt',
							'placeholder' => 'Your name',
						),
					),
				),
			),
		),
	);

	private const OPTIONS = array(
		'templateId'  => 'mini-tee@2',
		'blankId'     => 'blank_tee',
		'placementId' => 'front',
		'views'       => array(),
		'variants'    => array( 'v_white' ),
		'printSizes'  => array(
			array(
				'widthMm'  => 280,
				'heightMm' => 360,
				'label'    => '280 × 360 mm',
			),
		),
	);

	public function test_the_preview_takes_the_starting_photos_size_and_the_strip_the_placements_views(): void {
		$layout = SideLayout::of( self::OPTIONS, self::reads(), null );

		$this->assertSame( '--pixonaut-preview-ratio: 1200 / 1600; --pixonaut-preview-aspect: 0.75', $layout->style() );
		$this->assertSame(
			array(
				array(
					'id'        => 'view_front',
					'label'     => 'Front',
					'thumbnail' => 'https://example.test/white-front.png',
				),
				array(
					'id'        => 'view_close',
					'label'     => 'Close-up',
					'thumbnail' => 'https://example.test/close.png',
				),
				array(
					'id'        => 'design',
					'label'     => '',
					'thumbnail' => '',
				),
			),
			$layout->views
		);
		$this->assertSame( array( '280 × 360 mm' ), $layout->sizes );
	}

	public function test_a_side_whose_variations_pick_the_size_prints_no_size_picker(): void {
		$options = array( 'showPickers' => array( 'size' => false ) ) + self::OPTIONS;

		$this->assertSame( array(), SideLayout::of( $options, self::reads(), null )->sizes );
		$this->assertSame( array( '280 × 360 mm' ), SideLayout::of( array( 'showPickers' => array( 'size' => true ) ) + self::OPTIONS, self::reads(), null )->sizes );
	}

	public function test_the_variant_shown_picks_its_own_photo(): void {
		$layout = SideLayout::of( self::OPTIONS, self::reads(), 'v_black' );

		$this->assertSame( 'https://example.test/black-front.png', $layout->views[0]['thumbnail'] );
		$this->assertSame( '--pixonaut-preview-ratio: 800 / 1000; --pixonaut-preview-aspect: 0.8', $layout->style() );
	}

	public function test_the_strip_shows_only_the_views_the_product_is_shown_in(): void {
		$layout = SideLayout::of( array( 'views' => array( 'view_close' ) ) + self::OPTIONS, self::reads(), null );

		$this->assertSame( array( 'view_close' ), array_column( $layout->views, 'id' ) );
		$this->assertSame( '--pixonaut-preview-ratio: 500 / 1000; --pixonaut-preview-aspect: 0.5', $layout->style() );
	}

	public function test_a_side_opening_on_the_design_view_takes_the_templates_ratio(): void {
		$layout = SideLayout::of( array( 'initialView' => 'design' ) + self::OPTIONS, self::reads(), null );

		$this->assertSame( '--pixonaut-preview-ratio: 280 / 360; --pixonaut-preview-aspect: 0.7778', $layout->style() );
	}

	public function test_a_photo_of_unknown_size_falls_back_to_a_square(): void {
		$reads = self::reads();
		$reads['blank']['mockups']['placements'][1]['views'][0]['photos'][1]['width'] = null;

		$this->assertSame( '--pixonaut-preview-ratio: 1 / 1; --pixonaut-preview-aspect: 1', SideLayout::of( self::OPTIONS, $reads, null )->style() );
	}

	public function test_a_view_whose_photo_the_sdk_cannot_draw_is_left_out(): void {
		$reads = self::reads();
		$reads['blank']['mockups']['placements'][1]['views'][0]['photos'][1]['mount'] = null;

		$layout = SideLayout::of( self::OPTIONS, $reads, null );

		$this->assertSame( array( 'view_close', 'design' ), array_column( $layout->views, 'id' ) );
		$this->assertSame( '--pixonaut-preview-ratio: 500 / 1000; --pixonaut-preview-aspect: 0.5', $layout->style() );
	}

	public function test_a_placement_without_a_photo_the_sdk_can_draw_has_no_strip(): void {
		$reads = self::reads();
		foreach ( $reads['blank']['mockups']['placements'][1]['views'] as $view => $row ) {
			foreach ( array_keys( $row['photos'] ) as $photo ) {
				$reads['blank']['mockups']['placements'][1]['views'][ $view ]['photos'][ $photo ]['mount'] = null;
			}
		}

		$layout = SideLayout::of( self::OPTIONS, $reads, null );

		$this->assertSame( array(), $layout->views );
		$this->assertSame( '--pixonaut-preview-ratio: 280 / 360; --pixonaut-preview-aspect: 0.7778', $layout->style() );
	}

	public function test_without_reads_the_side_has_a_square_preview_and_nothing_else(): void {
		$layout = SideLayout::of( array( 'templateId' => 'mini-tee@2' ), array(), null );

		$this->assertSame( '--pixonaut-preview-ratio: 1 / 1; --pixonaut-preview-aspect: 1', $layout->style() );
		$this->assertSame( array(), $layout->views );
		$this->assertSame( array(), $layout->steps );
		$this->assertSame( array(), $layout->sizes );
	}

	public function test_without_a_blank_the_preview_takes_the_templates_ratio(): void {
		$reads = array( 'template' => self::reads()['template'] );

		$layout = SideLayout::of( array( 'templateId' => 'mini-tee@2' ), $reads, null );

		$this->assertSame( '--pixonaut-preview-ratio: 280 / 360; --pixonaut-preview-aspect: 0.7778', $layout->style() );
		$this->assertTrue( $layout->template_shaped, 'a theme holding previews at a height does not change its shape' );
	}

	public function test_a_correction_takes_its_designs_print_size_for_the_shape_on_no_blank(): void {
		$layout = SideLayout::of(
			array( 'design' => 'des_1' ),
			array(
				'printSize' => array(
					'w' => 300,
					'h' => 400,
				),
			),
			null
		);

		$this->assertSame( '--pixonaut-preview-ratio: 300 / 400; --pixonaut-preview-aspect: 0.75', $layout->style() );
		$this->assertTrue( $layout->template_shaped );
	}

	public function test_a_correction_on_a_blank_still_opens_at_its_photos_size(): void {
		$reads = array(
			'printSize' => array(
				'w' => 500,
				'h' => 500,
			),
		) + self::reads();

		$this->assertStringStartsWith( '--pixonaut-preview-ratio: 1200 / 1600;', SideLayout::of( self::OPTIONS, $reads, null )->style() );
	}

	public function test_on_a_blank_the_preview_follows_the_themes_height(): void {
		$this->assertFalse( SideLayout::of( self::OPTIONS, self::reads(), null )->template_shaped );
	}

	public function test_the_form_lists_each_steps_fields_then_its_sliders_and_drag_hint(): void {
		$layout = SideLayout::of( self::OPTIONS, self::reads(), null );

		$this->assertSame( array( 'Picture', 'Text' ), array_column( $layout->steps, 'label' ) );
		$this->assertSame(
			array(
				array( 'image', 'scale', 'move' ),
				array( 'text-lines', 'text' ),
			),
			array_map( static fn ( array $step ): array => array_column( $step['fields'], 'type' ), $layout->steps )
		);
		$this->assertSame( array( 'Motif', '', '' ), array_column( $layout->steps[0]['fields'], 'label' ) );
		$this->assertSame( array(), $layout->step_labels );
	}

	public function test_a_palette_field_takes_the_templates_palette_by_name(): void {
		$reads                                     = self::reads();
		$reads['template']['template']['palettes'] = array(
			'Brand' => array(
				array(
					'name'  => 'Red',
					'color' => array( 'rgb' => array( 255, 0, 0 ) ),
				),
				array(
					'name'  => 'Black',
					'color' => array( 'cmyk' => array( 0, 0, 0, 100 ) ),
				),
			),
		);
		$reads['template']['formSchema']['steps'][1]['fields'][] = array(
			'label'       => 'Ink',
			'prop'        => 'fill',
			'type'        => 'color',
			'constraints' => array( 'palette' => 'Brand' ),
			'default'     => array( 'cmyk' => array( 0, 0, 0, 100 ) ),
		);

		$ink = SideLayout::of( self::OPTIONS, $reads, null )->steps[1]['fields'][2];

		$this->assertSame( 'palette', $ink['type'] );
		$this->assertSame( array( '#ff0000', '#000000' ), $ink['options'] );
		$this->assertSame( 1, $ink['chosen'] );
		$this->assertSame( 'Black', $ink['aside'] );
	}

	public function test_a_shape_field_starts_on_the_shape_the_template_read_names(): void {
		$reads                              = self::reads();
		$reads['template']['layerShapeIds'] = array( 'badge' => 'shp_heart' );
		$reads['template']['formSchema']['steps'][1]['fields'][] = array(
			'layerId'     => 'badge',
			'label'       => 'Badge',
			'prop'        => 'shape',
			'type'        => 'select',
			'constraints' => array( 'oneOf' => array( 'shp_star', 'shp_heart' ) ),
		);

		$this->assertSame( 1, SideLayout::of( self::OPTIONS, $reads, null )->steps[1]['fields'][2]['chosen'] );
	}

	public function test_the_designer_speaks_the_mounts_locale_else_the_workspaces_language(): void {
		$reads                                  = self::reads();
		$reads['template']['workspaceLanguage'] = 'de';

		$this->assertSame( 'sv', SideLayout::of( array( 'locale' => 'sv' ) + self::OPTIONS, $reads, null )->language );
		$this->assertSame( 'de', SideLayout::of( self::OPTIONS, $reads, null )->language );
		$this->assertNull( SideLayout::of( self::OPTIONS, self::reads(), null )->language );
	}

	public function test_the_preview_keeps_the_marks_room_unless_the_template_read_says_it_shows_none(): void {
		$reads                          = self::reads();
		$reads['template']['poweredBy'] = false;

		$this->assertFalse( SideLayout::of( self::OPTIONS, $reads, null )->powered_by );
		$this->assertTrue( SideLayout::of( self::OPTIONS, self::reads(), null )->powered_by, 'a read that does not say, as the SDK takes it' );
		$this->assertTrue( SideLayout::of( self::OPTIONS, array(), null )->powered_by, 'no reads: the SDK reads the template itself' );
	}

	public function test_the_steps_layout_shows_the_first_step_alone(): void {
		$layout = SideLayout::of( array( 'layout' => 'steps' ) + self::OPTIONS, self::reads(), null );

		$this->assertSame( array( 'Picture' ), array_column( $layout->steps, 'label' ) );
		$this->assertSame( array( 'Picture', 'Text' ), $layout->step_labels );
		$this->assertFalse( $layout->options_step );
	}

	public function test_steps_with_the_products_options_open_on_their_step(): void {
		$layout = SideLayout::of( array( 'layout' => 'steps' ) + self::OPTIONS, self::reads(), null, true );

		$this->assertTrue( $layout->options_step );
		$this->assertSame( array(), $layout->steps );
		$this->assertSame( array( '', 'Picture', 'Text' ), $layout->step_labels );
	}

	public function test_the_groups_a_side_arranged_are_printed_renamed_and_in_order(): void {
		$layout = SideLayout::of( self::ARRANGED + self::OPTIONS, self::arranged_reads(), null, true, true );

		$this->assertSame( array( 'Your words', 'Picture' ), array_column( $layout->steps, 'label' ) );
		$fields = $layout->steps[0]['fields'];
		$this->assertSame( 'host', $fields[0]['type'] );
		$this->assertSame( 'attribute_size', $fields[0]['host'] );
		$this->assertSame( 'Name on the back', $fields[1]['label'] );
		$this->assertSame( 'Back of the shirt', $fields[1]['help'] );
		$this->assertSame( 'Your name', $fields[1]['placeholder'] );
	}

	public function test_groups_placing_an_attribute_leave_no_options_part_or_step(): void {
		$layout = SideLayout::of( array( 'layout' => 'steps' ) + self::ARRANGED + self::OPTIONS, self::arranged_reads(), null, true, true );

		$this->assertFalse( $layout->options_step );
		$this->assertFalse( $layout->lends_options );
		$this->assertTrue( $layout->lends_cart );
		$this->assertSame( array( 'Your words', 'Picture' ), $layout->step_labels );
		$this->assertSame( array( 'Your words' ), array_column( $layout->steps, 'label' ) );
	}

	public function test_a_form_in_steps_holds_hidden_slots_for_attributes_in_later_steps(): void {
		$options = array(
			'layout'     => 'steps',
			'formGroups' => array(
				'groups' => array(
					array(
						'id'    => 'text',
						'items' => array( array( 'attribute' => 'size' ) ),
					),
					array(
						'id'    => self::PICTURE_GROUP,
						'items' => array( array( 'attribute' => 'colour' ) ),
					),
				),
			),
		) + self::OPTIONS;

		$layout = SideLayout::of( $options, self::arranged_reads(), null, true, true );

		$this->assertSame( 'attribute_size', $layout->steps[0]['fields'][0]['host'] );
		$this->assertSame( array( 'attribute_colour' ), $layout->later_hosts );
		$this->assertSame( array(), SideLayout::of( array( 'layout' => 'page' ) + $options, self::arranged_reads(), null, true, true )->later_hosts );
	}

	public function test_the_groups_of_a_products_options_stand_after_the_templates_when_last(): void {
		$options = array(
			'formGroups' => array(
				'groups' => array(
					array(
						'id'    => self::PICTURE_GROUP,
						'items' => array(),
					),
					array(
						'id'    => 'text',
						'items' => array(),
					),
					array(
						'id'    => 'wc-option-storlek',
						'title' => 'Storlek',
						'items' => array( array( 'attribute' => 'storlek' ) ),
					),
				),
			),
		) + self::OPTIONS;

		$layout = SideLayout::of( $options, self::arranged_reads(), null, true, true );

		$this->assertFalse( $layout->lends_options );
		$this->assertCount( 3, $layout->steps );
		$this->assertSame( 'Storlek', $layout->steps[2]['label'] );
		$this->assertSame( 'attribute_storlek', $layout->steps[2]['fields'][0]['host'] );

		$in_steps = SideLayout::of( array( 'layout' => 'steps' ) + $options, self::arranged_reads(), null, true, true );
		$this->assertSame( array( 'attribute_storlek' ), $in_steps->later_hosts );
		$this->assertSame( 'Storlek', $in_steps->step_labels[2] );
	}

	public function test_without_a_form_to_lend_from_an_attribute_is_left_out_with_its_own_group(): void {
		$arranged = self::ARRANGED;

		$arranged['formGroups']['groups'][] = array(
			'id'    => 'wc-options',
			'title' => 'Options',
			'items' => array( array( 'attribute' => 'colour' ) ),
		);

		$layout = SideLayout::of( $arranged + self::OPTIONS, self::arranged_reads(), null, false, false );

		$this->assertSame( array( 'Your words', 'Picture' ), array_column( $layout->steps, 'label' ) );
		$this->assertNotContains( 'host', array_column( $layout->steps[0]['fields'], 'type' ) );
		$this->assertFalse( $layout->lends_options );
		$this->assertFalse( $layout->lends_cart );
	}

	public function test_a_saved_option_rename_reaches_the_printed_control(): void {
		Functions\when( 'wp_strip_all_tags' )->returnArg();
		Functions\when( 'sanitize_text_field' )->returnArg();
		$saved = FormGroups::from_array(
			array(
				'groups' => array(
					array(
						'id'    => 'text',
						'items' => array(
							array(
								'field'   => 'slogan:content',
								'options' => array(
									'1'   => 'One',
									'red' => 'Crimson',
								),
							),
						),
					),
				),
			)
		);

		$layout = SideLayout::of( array( 'formGroups' => $saved?->to_array() ) + self::OPTIONS, self::arranged_reads(), null );

		$this->assertSame(
			array(
				1     => 'One',
				'red' => 'Crimson',
			),
			$layout->steps[0]['fields'][0]['words']
		);
	}

	public function test_an_arranged_field_keeps_its_size_slider_after_it(): void {
		$layout = SideLayout::of(
			array(
				'formGroups' => array(
					'groups' => array(
						array(
							'id'    => self::PICTURE_GROUP,
							'items' => array(
								array( 'field' => 'motif:src' ),
							),
						),
					),
				),
			) + self::OPTIONS,
			self::arranged_reads(),
			null
		);

		$this->assertSame(
			array( 'image', 'scale', 'move' ),
			array_column( $layout->steps[0]['fields'], 'type' )
		);
	}

	public function test_a_page_with_the_products_options_shows_every_step(): void {
		$layout = SideLayout::of( array( 'layout' => 'page' ) + self::OPTIONS, self::reads(), null, true );

		$this->assertFalse( $layout->options_step );
		$this->assertSame( array( 'Picture', 'Text' ), array_column( $layout->steps, 'label' ) );
	}

	/**
	 * The reads below with each group and field named, as `formGroups` names them.
	 *
	 * @return array<string, mixed>
	 */
	private static function arranged_reads(): array {
		$reads = self::reads();
		$steps = &$reads['template']['formSchema']['steps'];

		$steps[0]['groupId']    = self::PICTURE_GROUP;
		$steps[0]['fields'][0] += array(
			'layerId' => 'motif',
			'prop'    => 'src',
		);
		$steps[1]['groupId']    = 'text';
		$steps[1]['fields'][0] += array(
			'layerId' => 'slogan',
			'prop'    => 'content',
		);
		$steps[1]['fields'][1] += array(
			'layerId' => 'name',
			'prop'    => 'content',
		);
		return $reads;
	}

	/**
	 * Reads of a tee whose front has a photo per colour, and a close-up.
	 *
	 * @return array<string, mixed>
	 */
	private static function reads(): array {
		return array(
			'template'  => array(
				'template'   => array(
					'id'    => 'mini-tee',
					'ratio' => array(
						'w' => 280,
						'h' => 360,
					),
				),
				'formSchema' => array(
					'steps' => array(
						array(
							'label'      => 'Picture',
							'fields'     => array(
								array(
									'label'       => 'Motif',
									'type'        => 'image',
									'constraints' => array(),
								),
							),
							'transforms' => array(
								array(
									'layerId'   => 'motif',
									'transform' => array(
										'translate' => array( 'editable' => true ),
										'scale'     => array( 'editable' => true ),
									),
								),
							),
						),
						array(
							'label'      => 'Text',
							'fields'     => array(
								array(
									'label'       => 'Slogan',
									'type'        => 'text',
									'constraints' => array( 'maxLines' => 2 ),
								),
								array(
									'label'       => 'Name',
									'type'        => 'text',
									'constraints' => array( 'maxLines' => 1 ),
								),
							),
							'transforms' => array(
								array(
									'layerId'   => 'slogan',
									'transform' => array( 'rotate' => array( 'editable' => false ) ),
								),
							),
						),
					),
				),
			),
			'placement' => array( 'templateId' => 'mini-tee@2' ),
			'blank'     => array(
				'mockups'  => array(
					'placements' => array(
						array(
							'id'    => 'back',
							'views' => array(
								array(
									'id'     => 'view_back',
									'label'  => 'Back',
									'photos' => array( self::photo( 'back', array(), 1000, 1000 ) ),
								),
							),
						),
						array(
							'id'    => 'front',
							'views' => array(
								array(
									'id'     => 'view_front',
									'label'  => 'Front',
									'photos' => array(
										self::photo( 'black-front', array( 'v_black' ), 800, 1000 ),
										self::photo( 'white-front', array( 'v_white' ), 1200, 1600 ),
									),
								),
								array(
									'id'     => 'view_close',
									'label'  => 'Close-up',
									'photos' => array( self::photo( 'close', array(), 500, 1000 ) ),
								),
							),
						),
					),
				),
				'variants' => array( 'variants' => array() ),
			),
		);
	}

	/**
	 * @param string   $name   The photo's file name.
	 * @param string[] $refs   The variants it shows.
	 * @param int      $width  Its width in pixels.
	 * @param int      $height Its height in pixels.
	 * @return array<string, mixed>
	 */
	private static function photo( string $name, array $refs, int $width, int $height ): array {
		return array(
			'id'           => 'mockup_' . $name,
			'thumbnailUrl' => 'https://example.test/' . $name . '.png',
			'width'        => $width,
			'height'       => $height,
			'variantRefs'  => $refs,
			'mount'        => array(
				'url'             => 'https://example.test/' . $name . '-full.png',
				'photoSizePx'     => array(
					'w' => $width,
					'h' => $height,
				),
				'placementRectPx' => array(
					'x' => 0,
					'y' => 0,
					'w' => $width,
					'h' => $height,
				),
			),
		);
	}
}
