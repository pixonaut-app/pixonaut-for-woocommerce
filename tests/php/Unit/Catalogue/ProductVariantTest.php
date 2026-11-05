<?php
/**
 * @package Pixonaut\WooCommerce
 */

declare(strict_types=1);

namespace Pixonaut\WooCommerce\Tests\Unit\Catalogue;

use Brain\Monkey\Functions;
use Pixonaut\WooCommerce\Catalogue\BlankVariant;
use Pixonaut\WooCommerce\Catalogue\ProductConfig;
use Pixonaut\WooCommerce\Catalogue\Side;
use Pixonaut\WooCommerce\Catalogue\ProductVariant;
use Pixonaut\WooCommerce\Tests\Unit\TestCase;

final class ProductVariantTest extends TestCase {

	private const BLANK_ID = 'blank_tee';

	/**
	 * The version stored before views were named by id.
	 */
	private const VERSION_BEFORE_VIEW_IDS = 1;

	public function test_the_variant_whose_options_are_the_attributes_is_matched_case_and_spacing_aside(): void {
		$variant = ProductVariant::resolve(
			self::BLANK_ID,
			null,
			array(
				'colour '  => 'heather  forest',
				'Size'     => 'M',
				'Gift box' => 'Yes',
			),
			self::variants()
		);

		$this->assertSame( array( 'v_forest_m', 'Colour: Heather Forest, Size: M', null ), array( $variant->ref, $variant->label, $variant->problem() ) );
		$this->assertSame(
			array(
				array(
					'id'        => 'view_Front, flat lay',
					'placement' => 'front',
					'label'     => 'Front, flat lay',
				),
			),
			$variant->views
		);
	}

	public function test_a_variant_read_in_a_language_matches_its_options_as_written_too_and_keeps_its_names_in_it(): void {
		$swedish  = BlankVariant::list_from(
			array(
				array(
					'ref'     => 'v_red_s',
					'options' => array(
						'Färg'    => 'Röd',
						'Storlek' => 'S',
					),
				),
				array(
					'ref'     => 'v_red_m',
					'options' => array(
						'Färg'    => 'Röd',
						'Storlek' => 'M',
					),
				),
			)
		);
		$variants = BlankVariant::with_written( $swedish, self::variants() );
		$by_name  = static fn ( array $attributes ): ?string => ProductVariant::resolve( self::BLANK_ID, null, $attributes, $variants )->ref;

		$this->assertSame(
			'v_red_m',
			$by_name(
				array(
					'Färg'    => 'röd',
					'Storlek' => 'M',
				)
			)
		);
		$this->assertSame(
			'v_red_s',
			$by_name(
				array(
					'Colour' => 'Red',
					'Size'   => 'S',
				)
			)
		);
		$this->assertNull(
			$by_name(
				array(
					'Färg' => 'Röd',
					'Size' => 'S',
				)
			),
			'one language or the other, not a mix'
		);
		$this->assertSame(
			'Färg: Röd, Storlek: S',
			ProductVariant::resolve(
				self::BLANK_ID,
				null,
				array(
					'Colour' => 'Red',
					'Size'   => 'S',
				),
				$variants
			)->label
		);
	}

	public function test_attributes_matching_no_variant_or_several_map_to_none(): void {
		$this->assertSame(
			ProductVariant::UNMAPPED,
			ProductVariant::resolve(
				self::BLANK_ID,
				null,
				array(
					'Colour' => 'Red',
					'Size'   => 'Medium',
				),
				self::variants()
			)->problem()
		);
		// "Any size" is an empty label, which matches no option.
		$this->assertSame(
			ProductVariant::UNMAPPED,
			ProductVariant::resolve(
				self::BLANK_ID,
				null,
				array(
					'Colour' => 'Red',
					'Size'   => '',
				),
				self::variants()
			)->problem()
		);
		$this->assertNull( ProductVariant::resolve( self::BLANK_ID, null, array( 'Colour' => 'Red' ), self::variants() )->ref, 'two sizes match' );
	}

	public function test_a_pick_replaces_the_match_and_a_pick_the_blank_no_longer_has_is_none(): void {
		$attributes = array(
			'Colour' => 'Heather Forest',
			'Size'   => 'M',
		);

		$picked = ProductVariant::resolve( self::BLANK_ID, 'v_red_s', $attributes, self::variants() );
		$gone   = ProductVariant::resolve( self::BLANK_ID, 'v_gone', $attributes, self::variants() );

		$this->assertSame( array( 'v_red_s', 'v_red_s' ), array( $picked->pick, $picked->ref ) );
		$this->assertSame( array( 'v_gone', null, ProductVariant::UNMAPPED ), array( $gone->pick, $gone->ref, $gone->problem() ) );
	}

	/**
	 * @dataProvider retirements
	 *
	 * @param string $reason Why Pixonaut retired the variant.
	 */
	public function test_a_retired_variant_is_retired_whatever_its_reason( string $reason ): void {
		$variants = BlankVariant::list_from(
			array(
				array(
					'ref'     => 'v_red_s',
					'options' => array( 'Colour' => 'Red' ),
					'retired' => array( 'reason' => $reason ),
					'views'   => array(),
				),
			)
		);

		$variant = ProductVariant::resolve( self::BLANK_ID, null, array( 'Colour' => 'Red' ), $variants );

		$this->assertSame( array( 'v_red_s', $reason, ProductVariant::RETIRED ), array( $variant->ref, $variant->retired, $variant->problem() ) );
	}

	/**
	 * @return array<string, array{string}>
	 */
	public static function retirements(): array {
		return array(
			'retired by the merchant' => array( 'retired' ),
			'no longer sold'          => array( 'no_longer_sold' ),
			'a reason not known yet'  => array( 'discontinued_by_maker' ),
		);
	}

	public function test_a_variant_without_options_is_every_products(): void {
		$variants = BlankVariant::list_from(
			array(
				array(
					'ref'     => 'v_tote',
					'options' => array(),
					'retired' => null,
					'views'   => array(),
				),
			)
		);

		$variant = ProductVariant::resolve( 'blank_tote', null, array( 'Size' => 'L' ), $variants );

		$this->assertSame( array( 'v_tote', 'v_tote', array() ), array( $variant->ref, $variant->label, $variant->views ) );
		$this->assertNull( $variant->problem() );
	}

	public function test_a_side_has_no_photo_of_the_variant_without_one_in_a_view_the_product_is_shown_in(): void {
		$variant = new ProductVariant(
			self::BLANK_ID,
			ref: 'v_forest_m',
			views: array(
				array(
					'id'        => 'view_flat',
					'placement' => 'front',
					'label'     => 'Flat',
				),
				array(
					'id'        => 'view_model',
					'placement' => 'front',
					'label'     => 'On a model',
				),
			)
		);
		$front   = new Side( 'front', 'Front', 'front', 'Front', 'tee', 1, null, null );
		$back    = new Side( 'back', 'Back', 'back', 'Back', 'tee', 1, null, null );
		$config  = new ProductConfig( personalise: true, blank_id: self::BLANK_ID, sides: array( $front, $back ) );

		$this->assertSame( array( $back ), $variant->sides_without_photo( $config ), 'every view, when none is chosen' );
		$this->assertSame( array( $back ), $variant->sides_without_photo( $config->with_views( array( 'view_model', 'view_back' ) ) ) );
		$this->assertSame( array( $front, $back ), $variant->sides_without_photo( $config->with_views( array( 'view_back' ) ) ) );
		$this->assertSame( array(), $variant->sides_without_photo( $config->with_sides( array( $front ) )->with_views( array( 'view_flat' ) ) ) );
	}

	public function test_a_stored_variant_round_trips_and_one_of_an_unknown_version_is_none(): void {
		Functions\when( 'wp_json_encode' )->alias( 'json_encode' );
		$variant = ProductVariant::resolve(
			self::BLANK_ID,
			null,
			array(
				'Colour' => 'Heather Forest',
				'Size'   => 'M',
			),
			self::variants()
		);
		$stored  = (string) wp_json_encode( $variant->to_array() );

		$this->assertEquals( $variant, ProductVariant::from_document( $stored ) );
		$this->assertNull( ProductVariant::from_document( (string) wp_json_encode( array( 'v' => ProductVariant::SCHEMA_VERSION + 1 ) + $variant->to_array() ) ) );
		$this->assertNull( ProductVariant::from_document( (string) wp_json_encode( array( 'v' => self::VERSION_BEFORE_VIEW_IDS - 1 ) + $variant->to_array() ) ) );
	}

	public function test_a_variant_stored_before_views_had_ids_keeps_its_pick_and_reads_no_views(): void {
		Functions\when( 'wp_json_encode' )->alias( 'json_encode' );
		$document = (string) wp_json_encode(
			array(
				'v'       => self::VERSION_BEFORE_VIEW_IDS,
				'blankId' => self::BLANK_ID,
				'pick'    => 'v_red_s',
				'ref'     => 'v_red_s',
				'label'   => 'Red / S',
				'retired' => 'retired',
				'views'   => array( 'Front, flat lay' ),
			)
		);

		$this->assertEquals( new ProductVariant( self::BLANK_ID, 'v_red_s', 'v_red_s', 'Red / S', 'retired' ), ProductVariant::from_document( $document ) );

		$matched = (string) wp_json_encode(
			array(
				'v'         => self::VERSION_BEFORE_VIEW_IDS,
				'blankId'   => self::BLANK_ID,
				'pick'      => null,
				'ref'       => 'v_red_m',
				'label'     => 'Red / M',
				'retired'   => null,
				'views'     => array(),
				'matchedOn' => array( 'Colour' => 'Red' ),
			)
		);

		$this->assertEquals( new ProductVariant( self::BLANK_ID, ref: 'v_red_m', label: 'Red / M', matched_on: array( 'Colour' => 'Red' ) ), ProductVariant::from_document( $matched ) );
	}

	public function test_a_list_entry_without_a_ref_is_left_out(): void {
		$variants = BlankVariant::list_from( array( array( 'options' => array( 'Colour' => 'Red' ) ), 'not a variant', array( 'ref' => 'v_ok' ) ) );

		$this->assertSame( array( 'v_ok' ), array_map( static fn ( BlankVariant $variant ): string => $variant->ref, $variants ) );
	}

	/**
	 * Two colours in two sizes; Heather Forest M alone has a photo.
	 *
	 * @return list<BlankVariant>
	 */
	private static function variants(): array {
		$variant = static fn ( string $ref, string $colour, string $size, array $views = array() ): array => array(
			'ref'     => $ref,
			'options' => array(
				'Colour' => $colour,
				'Size'   => $size,
			),
			'retired' => null,
			'views'   => array_map(
				static fn ( string $label ): array => array(
					'placementId' => 'front',
					'viewId'      => 'view_' . $label,
					'label'       => $label,
				),
				$views
			),
		);
		return BlankVariant::list_from(
			array(
				$variant( 'v_forest_m', 'Heather Forest', 'M', array( 'Front, flat lay' ) ),
				$variant( 'v_forest_s', 'Heather Forest', 'S' ),
				$variant( 'v_red_s', 'Red', 'S' ),
				$variant( 'v_red_m', 'Red', 'M' ),
			)
		);
	}
}
