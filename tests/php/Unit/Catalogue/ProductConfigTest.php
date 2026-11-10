<?php
/**
 * @package Pixonaut\WooCommerce
 */

declare(strict_types=1);

namespace Pixonaut\WooCommerce\Tests\Unit\Catalogue;

use Brain\Monkey\Functions;
use InvalidArgumentException;
use LogicException;
use Mockery;
use Mockery\MockInterface;
use Pixonaut\WooCommerce\Catalogue\FormGroups;
use Pixonaut\WooCommerce\Catalogue\PrintSize;
use Pixonaut\WooCommerce\Catalogue\ProductConfig;
use Pixonaut\WooCommerce\Catalogue\VariationSize;
use Pixonaut\WooCommerce\Catalogue\Side;
use Pixonaut\WooCommerce\Tests\Unit\TestCase;
use RuntimeException;
use WC_Product;

final class ProductConfigTest extends TestCase {

	private const PARENT_ID = 10;

	protected function setUp(): void {
		parent::setUp();
		Functions\when( 'wp_json_encode' )->alias( 'json_encode' );
	}

	public function test_reads_a_v1_document(): void {
		$config = ProductConfig::from_json( (string) wp_json_encode( self::v1_document() ) );

		$this->assertTrue( $config->personalise );
		$this->assertSame( 'blank_tshirt', $config->blank_id );
		$this->assertSame( 'T-shirt', $config->blank_name );
		$this->assertSame( 'checkbox', $config->approval_mode );
		$this->assertNull( $config->layout );
		$this->assertCount( 2, $config->sides );
		$front = $config->sides[0];
		$this->assertSame( 'front', $front->key );
		$this->assertSame( 'front', $front->placement_id );
		$this->assertSame( 'tpl_name', $front->template_base_id );
		$this->assertSame( 3, $front->template_version );
		$this->assertSame( 2, $front->previous_version );
		$this->assertEquals( array( new PrintSize( 300.0, 400.0, '30×40' ) ), $front->print_sizes );
		$this->assertArrayNotHasKey( 'mockupIds', $front->to_array(), 'version 2 drops the mockups chosen per side' );
		$this->assertSame( array(), $config->views, 'a version 1 product is shown in every view' );
		$this->assertTrue( $config->valid );
	}

	public function test_reads_the_views_it_is_shown_in(): void {
		$document          = self::v1_document();
		$document['v']     = ProductConfig::SCHEMA_VERSION;
		$document['views'] = array( 'view_flat', 'view_flat', '', 7, 'view_model' );

		$config = ProductConfig::from_json( (string) wp_json_encode( $document ) );

		$this->assertSame( array( 'view_flat', 'view_model' ), $config->views );
		$this->assertSame( array( 'view_flat', 'view_model' ), json_decode( $config->to_json(), true )['views'] );
		$this->assertSame( array( 'view_model' ), $config->with_views( array( 'view_model' ) )->views );
		$this->assertSame( array( 'view_model' ), $config->with_views( array( 'view_model' ) )->with_validity( false, 'x' )->with_sides( array() )->with_override( true )->with_layout( null )->with_approval_mode( null )->with_personalise( true )->views );
	}

	public function test_reads_and_writes_its_form_layout(): void {
		$document               = self::v1_document();
		$document['v']          = ProductConfig::SCHEMA_VERSION;
		$document['formLayout'] = 'steps';

		$config = ProductConfig::from_json( (string) wp_json_encode( $document ) );

		$this->assertSame( 'steps', $config->form_layout );
		$this->assertSame( 'steps', json_decode( $config->to_json(), true )['formLayout'] );
		$this->assertSame( 'steps', $config->with_validity( false, 'x' )->with_sides( array() )->with_override( true )->with_layout( null )->with_approval_mode( null )->with_personalise( true )->with_views( array() )->form_layout );
		$this->assertNull( $config->with_form_layout( null )->form_layout );
		$version_2      = self::v1_document();
		$version_2['v'] = 2;
		$this->assertNull( ProductConfig::from_json( (string) wp_json_encode( $version_2 ) )->form_layout, 'a product saved before follows the store' );
	}

	public function test_reads_and_writes_its_starting_view_and_sizes(): void {
		$document                          = self::v1_document();
		$document['v']                     = ProductConfig::SCHEMA_VERSION;
		$document['views']                 = array( 'view_flat', 'view_model' );
		$document['initialView']           = 'view_model';
		$document['sides'][0]['startSize'] = 1;

		$config = ProductConfig::from_json( (string) wp_json_encode( $document ) );

		$this->assertSame( 'view_model', $config->initial_view );
		$this->assertSame( 1, $config->sides[0]->start_size );
		$this->assertSame( 'view_model', json_decode( $config->to_json(), true )['initialView'] );
		$this->assertSame( 'view_model', $config->with_validity( false, 'x' )->with_sides( array() )->with_override( true )->with_layout( null )->with_form_layout( null )->with_approval_mode( null )->with_personalise( true )->initial_view );
		$this->assertNull( $config->with_views( array( 'view_flat' ) )->initial_view );
		$this->assertSame( 'view_flat', $config->with_views( array( 'view_flat' ), 'view_flat' )->initial_view );
	}

	public function test_a_product_saved_before_starts_on_the_first_view_and_size(): void {
		$document                          = self::v1_document();
		$document['v']                     = 3;
		$document['sides'][0]['startSize'] = 1;

		$config = ProductConfig::from_json( (string) wp_json_encode( $document ) );

		$this->assertNull( $config->initial_view );
		$this->assertSame( 0, $config->sides[0]->start_size );
	}

	public function test_reads_and_writes_each_sides_mode_and_standing_design(): void {
		$document                                 = self::v1_document();
		$document['v']                            = ProductConfig::SCHEMA_VERSION;
		$document['sides'][0]['mode']             = Side::STANDING;
		$document['sides'][0]['standingDesignId'] = 'des_standing_1';
		$document['sides'][0]['template']['offersProperties'] = false;

		$side = ProductConfig::from_json( (string) wp_json_encode( $document ) )->sides[0];

		$this->assertTrue( $side->is_standing() );
		$this->assertSame( 'des_standing_1', $side->standing_design_id );
		$this->assertFalse( $side->offers_properties );
		$this->assertSame( Side::STANDING, $side->to_array()['mode'] );
		$this->assertSame( 'des_standing_1', $side->with( 9, null, array(), null )->standing_design_id );
	}

	public function test_a_standing_side_saved_before_rows_keeps_its_one_design(): void {
		$document                                 = self::v1_document();
		$document['v']                            = 7;
		$document['sides'][0]['mode']             = Side::STANDING;
		$document['sides'][0]['standingDesignId'] = 'des_standing_1';
		$document['sides'][0]['standingRows']     = array(
			array(
				'row'      => array( 'Size' => 'S' ),
				'designId' => 'des_s',
			),
		);

		$side = ProductConfig::from_json( (string) wp_json_encode( $document ) )->sides[0];

		$this->assertSame( array(), $side->standing_rows );
		$this->assertSame( 'des_standing_1', $side->standing_design_for( 'v_s' ) );
	}

	public function test_a_row_saved_before_layout_adjustments_follows_the_side(): void {
		$document                                 = self::v1_document();
		$document['v']                            = 8;
		$document['sides'][0]['mode']             = Side::STANDING;
		$document['sides'][0]['standingDesignId'] = null;
		$document['sides'][0]['formGroups']       = null;
		$document['sides'][0]['startSize']        = 0;
		$document['sides'][0]['standingRows']     = array(
			array(
				'row'      => array( 'Size' => 'S' ),
				'designId' => 'des_s',
				'adjusted' => array(
					'templateId'  => 'mini-tee@2',
					'blankId'     => 'blk_tee',
					'placementId' => 'front',
				),
			),
		);

		$rows = ProductConfig::from_json( (string) wp_json_encode( $document ) )->sides[0]->standing_rows;

		$this->assertSame( 'des_s', $rows[0]->design_id );
		$this->assertNull( $rows[0]->adjusted );
	}

	public function test_a_side_saved_before_misfit_sizes_knows_of_none(): void {
		$document                                 = self::v1_document();
		$document['v']                            = 9;
		$document['sides'][0]['mode']             = Side::PERSONALISED;
		$document['sides'][0]['standingDesignId'] = null;
		$document['sides'][0]['formGroups']       = null;
		$document['sides'][0]['startSize']        = 0;
		$document['sides'][0]['standingRows']     = array();

		$config = ProductConfig::from_json( (string) wp_json_encode( $document ) );

		$this->assertSame( array(), $config->sides[0]->misfit_sizes );
		$this->assertSame( ProductConfig::SCHEMA_VERSION, json_decode( $config->to_json(), true )['v'] );
	}

	public function test_a_product_saved_before_modes_is_personalised(): void {
		$document                     = self::v1_document();
		$document['v']                = 4;
		$document['sides'][0]['mode'] = Side::STANDING;

		$side = ProductConfig::from_json( (string) wp_json_encode( $document ) )->sides[0];

		$this->assertFalse( $side->is_standing() );
		$this->assertNull( $side->standing_design_id );
		$this->assertNull( $side->offers_properties );
	}

	public function test_reads_and_writes_each_sides_arrangement(): void {
		Functions\when( 'sanitize_text_field' )->returnArg();
		Functions\when( 'wp_strip_all_tags' )->returnArg();
		$document                           = self::v1_document();
		$document['v']                      = ProductConfig::SCHEMA_VERSION;
		$document['sides'][0]['formGroups'] = array(
			'groups' => array(
				array(
					'id'    => 'text-front',
					'title' => 'Your words',
					'items' => array( array( 'field' => 'slogan:content' ) ),
				),
			),
		);

		$side = ProductConfig::from_json( (string) wp_json_encode( $document ) )->sides[0];

		$this->assertNotNull( $side->form_groups );
		$this->assertSame( 'Your words', $side->form_groups->groups[0]['title'] ?? null );
		$this->assertSame( $side->form_groups, $side->with( 9, null, array(), null )->form_groups, 'a recheck keeps it' );
		$this->assertSame( $side->form_groups, $side->with_offers_properties( true )->with_standing_design( null )->form_groups );
		$this->assertSame( 'Your words', $side->to_array()['formGroups']['groups'][0]['title'] );
	}

	public function test_a_product_saved_before_arrangements_shows_the_templates_form(): void {
		$document                           = self::v1_document();
		$document['v']                      = 5;
		$document['sides'][0]['formGroups'] = array(
			'groups' => array(
				array(
					'id'    => 'stale',
					'items' => array(),
				),
			),
		);

		$side = ProductConfig::from_json( (string) wp_json_encode( $document ) )->sides[0];

		$this->assertNull( $side->form_groups );
		$this->assertNull( $side->to_array()['formGroups'] );
	}

	public function test_reads_and_writes_its_printshop(): void {
		$document                = self::v1_document();
		$document['v']           = ProductConfig::SCHEMA_VERSION;
		$document['printshopId'] = 'ps_signs';

		$config = ProductConfig::from_json( (string) wp_json_encode( $document ) );

		$this->assertSame( 'ps_signs', $config->printshop_id );
		$this->assertSame( 'ps_signs', $config->to_array()['printshopId'] );
		$this->assertSame( 'ps_signs', $config->with_layout( null )->with_views( array() )->printshop_id, 'other changes keep it' );
		$this->assertNull( $config->with_printshop( null )->printshop_id );
	}

	public function test_a_product_saved_before_printshops_uses_the_workspace_default(): void {
		$document                = self::v1_document();
		$document['v']           = 6;
		$document['printshopId'] = 'ps_stale';

		$this->assertNull( ProductConfig::from_json( (string) wp_json_encode( $document ) )->printshop_id );
	}

	public function test_only_a_product_without_a_blank_orders_from_its_own_printshop(): void {
		$no_blank = new ProductConfig( personalise: true, printshop_id: 'ps_signs' );
		$on_blank = new ProductConfig( personalise: true, blank_id: 'blank_tee', printshop_id: 'ps_signs' );

		$this->assertSame( 'ps_signs', $no_blank->order_printshop() );
		$this->assertNull( $on_blank->order_printshop(), 'the blank decides its printshop' );
		$this->assertNull( ( new ProductConfig( personalise: true ) )->order_printshop() );
	}

	public function test_the_designer_sees_only_the_personalised_sides(): void {
		$front = new Side( 'front', 'Front', 'front', 'Front', 'mini-tee', 2, null, 'Mini tee', mode: Side::STANDING, standing_design_id: 'des_standing_1' );
		$back  = new Side( 'back', 'Back', 'back', 'Back', 'mini-tee', 2, null, 'Mini tee' );
		$mixed = new ProductConfig( personalise: true, sides: array( $front, $back ) );

		$this->assertSame( array( $back ), $mixed->for_designer()->sides );
		$this->assertTrue( $mixed->for_designer()->personalise );
		$this->assertSame( array( $front ), $mixed->standing_sides() );
		$personalised = $mixed->with_sides( array( $back ) );
		$this->assertSame( $personalised, $personalised->for_designer() );
		$standing = $mixed->with_sides( array( $front ) );
		$this->assertFalse( $standing->for_designer()->personalise, 'a product of standing sides alone opens no designer' );
		$this->assertTrue( $standing->personalise, 'the cart and fulfilment still read it as sold through Pixonaut' );
	}

	public function test_writes_what_it_reads(): void {
		$config = ProductConfig::from_json( (string) wp_json_encode( self::v1_document() ) );

		$this->assertEquals( $config, ProductConfig::from_json( $config->to_json() ) );
		$this->assertSame( ProductConfig::SCHEMA_VERSION, json_decode( $config->to_json(), true )['v'] );
	}

	public function test_document_without_a_version_is_not_a_configuration(): void {
		$document = self::v1_document();
		unset( $document['v'] );

		$this->assertFalse( ProductConfig::from_json( (string) wp_json_encode( $document ) )->personalise );
		$this->assertFalse( ProductConfig::from_json( 'not json' )->personalise );
	}

	public function test_document_from_a_newer_version_is_invalid_and_not_overwritten(): void {
		$newer  = (string) wp_json_encode( array( 'v' => ProductConfig::SCHEMA_VERSION + 1 ) );
		$config = ProductConfig::from_json( $newer );

		$this->assertTrue( $config->personalise, 'not sold unpersonalised' );
		$this->assertFalse( $config->valid );
		$this->assertSame( ProductConfig::REASON_NEWER_SCHEMA, $config->invalid_reason );

		$this->expectException( LogicException::class );
		( new ProductConfig( personalise: true ) )->save_to( $this->product( $newer ) );
	}

	public function test_invalid_sides_and_sizes_are_dropped(): void {
		$document          = self::v1_document();
		$document['sides'] = array(
			array( 'label' => 'No key' ),
			array( 'key' => 'no-template' ),
			array(
				'key'        => 'front',
				'template'   => array( 'baseId' => 'tpl' ),
				'printSizes' => array(
					array(
						'widthMm'  => 0,
						'heightMm' => 10,
					),
					array( 'widthMm' => 'x' ),
					array(
						'widthMm'  => '1e400',
						'heightMm' => 10,
					),
				),
			),
		);

		$sides = ProductConfig::from_json( (string) wp_json_encode( $document ) )->sides;

		$this->assertCount( 2, $sides );
		// A side whose template is not chosen yet is kept, with none.
		$this->assertSame( '', $sides[0]->template_base_id );
		$this->assertNull( $sides[0]->template_version );
		$this->assertSame( 'front', $sides[1]->label, 'label falls back to the key' );
		$this->assertSame( array(), $sides[1]->print_sizes );
	}

	public function test_a_menu_waiting_for_a_check_round_trips(): void {
		$side = new Side( 'front', 'Front', 'front', 'Front', 'tpl', 1, null, null, array(), array( null, new PrintSize( 210.5, 297.0, 'A4' ) ) );

		$read = Side::from_array( json_decode( (string) wp_json_encode( $side->to_array() ), true ) );

		$this->assertNotNull( $read );
		$this->assertEquals( $side->pending_sizes, $read->pending_sizes );
		$this->assertArrayNotHasKey( 'pendingSizes', ( new Side( 'front', 'Front', null, null, 'tpl', 1, null, null ) )->to_array() );
	}

	public function test_print_size_rejects_non_finite_dimensions(): void {
		$this->expectException( InvalidArgumentException::class );
		new PrintSize( INF, 10.0, 'Infinite' );
	}

	public function test_failed_encoding_keeps_the_stored_config(): void {
		$product = $this->product( $this->config_json( 'tpl_stored', override: false ) );
		$product->shouldNotReceive( 'update_meta_data' );
		$product->shouldNotReceive( 'save_meta_data' );
		Functions\when( 'wp_json_encode' )->justReturn( false );

		$this->expectException( RuntimeException::class );
		( new ProductConfig( personalise: true ) )->save_to( $product );
	}

	public function test_with_validity_keeps_the_reason_only_when_invalid(): void {
		$config = new ProductConfig( personalise: true );

		$this->assertSame( 'template_deleted', $config->with_validity( false, 'template_deleted' )->invalid_reason );
		$this->assertNull( $config->with_validity( true, 'ignored' )->invalid_reason );
	}

	public function test_variation_inherits_the_product_config(): void {
		$product   = $this->product( $this->config_json( 'tpl_product', override: false ) );
		$variation = $this->product( $this->config_json( 'tpl_variation', override: false ) );

		$this->assertSame( 'tpl_product', ProductConfig::config_for( $product, $variation )->sides[0]->template_base_id );
	}

	public function test_variation_with_override_uses_its_own_config(): void {
		$product   = $this->product( $this->config_json( 'tpl_product', override: false ) );
		$variation = $this->product( $this->config_json( 'tpl_variation', override: true ) );

		$this->assertSame( 'tpl_variation', ProductConfig::config_for( $product, $variation )->sides[0]->template_base_id );
	}

	public function test_a_variation_overriding_the_size_keeps_the_products_groups(): void {
		Functions\when( 'sanitize_text_field' )->returnArg();
		Functions\when( 'sanitize_title' )->returnArg();
		Functions\when( 'wp_strip_all_tags' )->returnArg();
		$groups    = FormGroups::from_array(
			array(
				'groups' => array(
					array(
						'id'    => 'own-size',
						'title' => 'Size',
						'items' => array( array( 'attribute' => 'pa_size' ) ),
					),
				),
			)
		);
		$product   = $this->product(
			( new ProductConfig(
				personalise: true,
				sides: array(
					new Side( 'front', 'Front', null, null, 'tpl', 1, null, null, form_groups: $groups ),
					new Side( 'back', 'Back', null, null, 'tpl', 1, null, null, form_groups: $groups ),
				),
			) )->to_json()
		);
		$own       = FormGroups::from_array(
			array(
				'groups' => array(
					array(
						'id'    => 'own-mine',
						'title' => 'Mine',
						'items' => array(),
					),
				),
			)
		);
		$variation = $this->product(
			( new ProductConfig(
				personalise: true,
				override: true,
				sides: array(
					new Side( 'front', 'Front', null, null, 'tpl', 1, null, null, array( new PrintSize( 350.0, 500.0, '35 × 50 cm' ) ) ),
					new Side( 'back', 'Back', null, null, 'tpl', 1, null, null, form_groups: $own ),
					new Side( 'inside', 'Inside', null, null, 'tpl', 1, null, null ),
				),
			) )->to_json()
		);

		$sides = ProductConfig::config_for( $product, $variation )->sides;

		$this->assertNotNull( $groups );
		$this->assertSame( $groups->to_array(), $sides[0]->form_groups?->to_array(), 'a side of its own size takes the product side’s groups' );
		$this->assertEquals( array( new PrintSize( 350.0, 500.0, '35 × 50 cm' ) ), $sides[0]->print_sizes, 'and keeps its size' );
		$this->assertSame( 'Mine', $sides[1]->form_groups?->groups[0]['title'] ?? null, 'a side with groups of its own keeps them' );
		$this->assertNull( $sides[2]->form_groups, 'a side the product lacks shows its template’s form' );
		$this->assertNull( ProductConfig::stored_on( $variation )->sides[0]->form_groups, 'the variation’s stored configuration is left as it is' );
	}

	public function test_reads_and_writes_its_canvas_ratio_and_each_sides(): void {
		$document                                    = self::v1_document();
		$document['v']                               = ProductConfig::SCHEMA_VERSION;
		$document['drawAtTemplateRatio']             = true;
		$document['sides'][0]['drawAtTemplateRatio'] = false;
		$document['sides'][1]['drawAtTemplateRatio'] = 'yes';

		$config = ProductConfig::from_json( (string) wp_json_encode( $document ) );

		$this->assertTrue( $config->draw_at_template_ratio );
		$this->assertFalse( $config->sides[0]->draw_at_template_ratio );
		$this->assertNull( $config->sides[1]->draw_at_template_ratio, 'anything but a boolean follows' );
		$this->assertTrue( $config->to_array()['drawAtTemplateRatio'] );
		$this->assertFalse( $config->to_array()['sides'][0]['drawAtTemplateRatio'] );
		$this->assertTrue( $config->with_layout( null )->with_validity( false, 'x' )->with_sides( array() )->draw_at_template_ratio, 'other changes keep it' );
		$this->assertNull( $config->with_draw_at_template_ratio( null )->draw_at_template_ratio );
	}

	public function test_a_product_saved_before_canvas_ratios_follows_the_template(): void {
		$document                                    = self::v1_document();
		$document['v']                               = 10;
		$document['drawAtTemplateRatio']             = true;
		$document['sides'][0]['drawAtTemplateRatio'] = false;

		$config = ProductConfig::from_json( (string) wp_json_encode( $document ) );

		$this->assertNull( $config->draw_at_template_ratio );
		$this->assertNull( $config->sides[0]->draw_at_template_ratio );
	}

	public function test_reads_and_writes_its_choices_for_the_forms_options_and_numbers(): void {
		$document                 = self::v1_document();
		$document['v']            = ProductConfig::SCHEMA_VERSION;
		$document['optionGroups'] = 'last';
		$document['groupNumbers'] = 'off';

		$config = ProductConfig::from_json( (string) wp_json_encode( $document ) );

		$this->assertSame( 'last', $config->option_groups );
		$this->assertSame( 'off', $config->group_numbers );
		$this->assertSame( 'last', $config->to_array()['optionGroups'] );
		$this->assertSame( 'off', $config->to_array()['groupNumbers'] );
		$this->assertSame( 'last', $config->with_form_layout( 'steps' )->option_groups, 'a copy keeps them' );
		$this->assertNull( $config->with_option_groups( null )->option_groups );
		$this->assertNull( $config->with_group_numbers( null )->group_numbers );
	}

	public function test_a_product_saved_before_these_choices_follows_the_store(): void {
		$document                 = self::v1_document();
		$document['v']            = 11;
		$document['optionGroups'] = 'last';
		$document['groupNumbers'] = 'off';

		$config = ProductConfig::from_json( (string) wp_json_encode( $document ) );

		$this->assertNull( $config->option_groups );
		$this->assertNull( $config->group_numbers );
	}

	public function test_reads_and_writes_its_split_listing_choices(): void {
		$document                 = self::v1_document();
		$document['v']            = ProductConfig::SCHEMA_VERSION;
		$document['splitListing'] = array(
			'7'  => 'on',
			'3'  => 'off',
			'9'  => 'auto',
			'x'  => 'on',
			'-2' => 'on',
			'4'  => 'sometimes',
		);

		$config = ProductConfig::from_json( (string) wp_json_encode( $document ) );

		$this->assertSame(
			array(
				3 => 'off',
				7 => 'on',
			),
			$config->split_listing,
			'only an On or Off choice of an attribute id is kept'
		);
		$this->assertSame( '{"3":"off","7":"on"}', (string) wp_json_encode( json_decode( $config->to_json(), true )['splitListing'] ) );
		$this->assertSame( $config->split_listing, $config->with_validity( false, 'x' )->with_sides( array() )->with_override( true )->with_layout( null )->with_approval_mode( null )->with_personalise( true )->with_views( array() )->with_form_layout( 'steps' )->with_printshop( null )->with_draw_at_template_ratio( true )->with_option_groups( null )->with_group_numbers( null )->on_blank( 'blank' )->split_listing, 'a copy keeps them' );
		$this->assertSame( array( 5 => 'on' ), $config->with_split_listing( array( 5 => 'on' ) )->split_listing );
		$this->assertSame( array(), ProductConfig::from_json( $config->with_split_listing( array() )->to_json() )->split_listing, 'no choices read back as none' );
	}

	public function test_a_product_saved_before_split_listings_follows_its_attributes(): void {
		$document                 = self::v1_document();
		$document['v']            = 13;
		$document['splitListing'] = array( '7' => 'on' );

		$this->assertSame( array(), ProductConfig::from_json( (string) wp_json_encode( $document ) )->split_listing );
	}

	public function test_a_sides_canvas_ratio_comes_before_its_products(): void {
		$side   = new Side( 'front', 'Front', null, null, 'tpl', 1, null, null );
		$config = new ProductConfig( personalise: true, sides: array( $side ), draw_at_template_ratio: true );

		$this->assertTrue( $config->draw_at_template_ratio_for( $side ) );
		$this->assertFalse( $config->draw_at_template_ratio_for( $side->with_draw_at_template_ratio( false ) ) );
		$this->assertNull( $config->with_draw_at_template_ratio( null )->draw_at_template_ratio_for( $side ), 'neither chooses: the template decides' );
	}

	public function test_an_overriding_variation_chooses_its_canvas_ratio_before_the_side_and_the_product(): void {
		$product = $this->product(
			( new ProductConfig(
				personalise: true,
				sides: array(
					new Side( 'front', 'Front', null, null, 'tpl', 1, null, null, draw_at_template_ratio: false ),
					new Side( 'back', 'Back', null, null, 'tpl', 1, null, null ),
				),
				draw_at_template_ratio: true,
			) )->to_json()
		);
		$sides   = array(
			new Side( 'front', 'Front', null, null, 'tpl', 1, null, null ),
			new Side( 'back', 'Back', null, null, 'tpl', 1, null, null ),
			new Side( 'inside', 'Inside', null, null, 'tpl', 1, null, null, draw_at_template_ratio: false ),
		);

		$following = ProductConfig::config_for( $product, $this->product( ( new ProductConfig( personalise: true, override: true, sides: $sides ) )->to_json() ) );
		$choosing  = ProductConfig::config_for( $product, $this->product( ( new ProductConfig( personalise: true, override: true, sides: $sides, draw_at_template_ratio: true ) )->to_json() ) );

		$this->assertSame( array( false, true, false ), array_map( array( $following, 'draw_at_template_ratio_for' ), $following->sides ), 'a variation choosing nothing takes the product side’s choice, else the product’s' );
		$this->assertSame( array( true, true, false ), array_map( array( $choosing, 'draw_at_template_ratio_for' ), $choosing->sides ), 'a variation’s own choice comes first, its sides’ before it' );
	}

	public function test_variation_without_config_inherits(): void {
		$product   = $this->product( $this->config_json( 'tpl_product', override: false ) );
		$variation = $this->product( '' );

		$this->assertSame( 'tpl_product', ProductConfig::config_for( $product, $variation )->sides[0]->template_base_id );
	}

	public function test_a_variation_alone_resolves_through_its_parent(): void {
		$parent    = $this->product( $this->config_json( 'tpl_product', override: false ) );
		$variation = $this->product( '', is_variation: true );
		Functions\expect( 'wc_get_product' )->once()->with( self::PARENT_ID )->andReturn( $parent );

		$this->assertSame( 'tpl_product', ProductConfig::config_for( $variation )->sides[0]->template_base_id );
	}

	public function test_newer_variation_config_fails_closed_over_an_unconfigured_parent(): void {
		$product   = $this->product( '' );
		$variation = $this->product( self::newer_json() );

		$this->assertFailsClosed( ProductConfig::config_for( $product, $variation ) );
	}

	public function test_newer_variation_config_fails_closed_over_a_configured_parent(): void {
		$product   = $this->product( $this->config_json( 'tpl_product', override: false ) );
		$variation = $this->product( self::newer_json() );

		$this->assertFailsClosed( ProductConfig::config_for( $product, $variation ) );
	}

	public function test_newer_variation_config_alone_fails_closed_over_an_unconfigured_parent(): void {
		$variation = $this->product( self::newer_json(), is_variation: true );
		Functions\expect( 'wc_get_product' )->once()->with( self::PARENT_ID )->andReturn( $this->product( '' ) );

		$this->assertFailsClosed( ProductConfig::config_for( $variation ) );
	}

	public function test_newer_variation_config_alone_fails_closed_over_a_configured_parent(): void {
		$parent    = $this->product( $this->config_json( 'tpl_product', override: false ) );
		$variation = $this->product( self::newer_json(), is_variation: true );
		Functions\expect( 'wc_get_product' )->once()->with( self::PARENT_ID )->andReturn( $parent );

		$this->assertFailsClosed( ProductConfig::config_for( $variation ) );
	}

	public function test_unticking_the_override_makes_a_variation_inherit_and_keeps_its_link(): void {
		$product   = $this->product( $this->config_json( 'tpl_product', override: false ) );
		$own       = ProductConfig::from_json( $this->config_json( 'tpl_variation', override: true ) );
		$variation = $this->product( $own->with_override( false )->to_json() );

		$this->assertSame( 'tpl_product', ProductConfig::config_for( $product, $variation )->sides[0]->template_base_id );
		$this->assertSame( 'tpl_variation', ProductConfig::stored_on( $variation )->sides[0]->template_base_id );
		$this->assertEquals( $own, $own->with_override( false )->with_override( true ) );
	}

	public function test_only_a_stored_document_counts_as_stored(): void {
		$this->assertFalse( ProductConfig::is_stored_on( $this->product( '' ) ) );
		$this->assertTrue( ProductConfig::is_stored_on( $this->product( $this->config_json( 'tpl', override: false ) ) ) );
	}

	public function test_product_without_config_is_not_personalised(): void {
		$this->assertFalse( ProductConfig::config_for( $this->product( '' ) )->personalise );
	}

	private function assertFailsClosed( ProductConfig $config ): void {
		$this->assertTrue( $config->personalise );
		$this->assertFalse( $config->valid );
		$this->assertSame( ProductConfig::REASON_NEWER_SCHEMA, $config->invalid_reason );
	}

	private static function newer_json(): string {
		return (string) wp_json_encode( array( 'v' => ProductConfig::SCHEMA_VERSION + 1 ) );
	}

	private function config_json( string $template, bool $override ): string {
		$config = new ProductConfig(
			personalise: true,
			override: $override,
			sides: array( new Side( 'front', 'Front', null, null, $template, 1, null, null ) ),
		);
		return $config->to_json();
	}

	/**
	 * @param string $meta         The stored configuration; empty for none.
	 * @param bool   $is_variation Whether the product is a variation.
	 * @return WC_Product&MockInterface
	 */
	private function product( string $meta, bool $is_variation = false ): WC_Product {
		/** @var WC_Product&MockInterface $product */
		$product = Mockery::mock( WC_Product::class );
		$product->allows( 'get_meta' )->with( ProductConfig::META_KEY, true )->andReturn( $meta );
		// No print size of its own.
		$product->allows( 'get_meta' )->with( VariationSize::META_KEY, true )->andReturn( '' );
		$product->allows( 'is_type' )->with( 'variation' )->andReturn( $is_variation );
		$product->allows( 'get_parent_id' )->andReturn( self::PARENT_ID );
		return $product;
	}

	/**
	 * @return array<string, mixed>
	 */
	private static function v1_document(): array {
		return array(
			'v'            => 1,
			'personalise'  => true,
			'override'     => false,
			'blank'        => array(
				'id'   => 'blank_tshirt',
				'name' => 'T-shirt',
			),
			'sides'        => array(
				array(
					'key'        => 'front',
					'label'      => 'Front',
					'placement'  => array(
						'id'   => 'front',
						'name' => 'Chest',
					),
					'template'   => array(
						'baseId'          => 'tpl_name',
						'version'         => 3,
						'previousVersion' => 2,
						'name'            => 'Name print',
					),
					'printSizes' => array(
						array(
							'widthMm'  => 300,
							'heightMm' => 400,
							'label'    => '30×40',
						),
					),
					'mockupIds'  => array( 'mock_1', 'mock_2' ),
				),
				array(
					'key'       => 'back',
					'label'     => 'Back',
					'placement' => array( 'id' => 'back' ),
					'template'  => array(
						'baseId'  => 'tpl_number',
						'version' => 1,
					),
				),
			),
			'layout'       => null,
			'approvalMode' => 'checkbox',
			'validity'     => array(
				'valid'  => true,
				'reason' => null,
			),
		);
	}
}
