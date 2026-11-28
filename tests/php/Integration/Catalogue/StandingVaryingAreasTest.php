<?php
/**
 * @package Pixonaut\WooCommerce
 */

declare(strict_types=1);

namespace Pixonaut\WooCommerce\Tests\Integration\Catalogue;

use Pixonaut\WooCommerce\Catalogue\InvalidReason;
use Pixonaut\WooCommerce\Catalogue\MisfitSizes;
use Pixonaut\WooCommerce\Catalogue\ProductConfig;
use Pixonaut\WooCommerce\Catalogue\RowSize;
use Pixonaut\WooCommerce\Catalogue\Side;
use Pixonaut\WooCommerce\Catalogue\StandingRow;
use Pixonaut\WooCommerce\Tests\Support\DesignFixture;
use Pixonaut\WooCommerce\Tests\Support\VerdictAnswers;
use Pixonaut\WooCommerce\Vendor\Pixonaut\Sdk\Generated\ErrorCode;

/**
 * A standing side on a placement printed across its whole area, with an
 * area per row (a pack of cards sold in A6 and A5), is judged on its rows'
 * areas, as a personalised side is: the placement's own box, the widest and
 * tallest of its rows, is the area of no row, and Pixonaut refuses it.
 */
final class StandingVaryingAreasTest extends StandingTestCase {

	private const A6 = 'A6';

	private const A5 = 'A5';

	/**
	 * The rows' print areas, in millimetres, by size.
	 */
	private const AREAS = array(
		self::A6 => array( 105, 148 ),
		self::A5 => array( 148, 210 ),
	);

	/**
	 * The placement's own box: the widest and tallest of its rows.
	 */
	private const PLACEMENT_BOX_MM = 210;

	public function set_up(): void {
		parent::set_up();
		$variant                = static fn ( string $ref, string $size ): array => array(
			'ref'     => $ref,
			'options' => array( self::AREAS_VARY_BY => $size ),
			'retired' => null,
			'views'   => array(),
		);
		$this->variants->answer = array(
			'axes'        => array( self::AREAS_VARY_BY ),
			'options'     => array(),
			'areasVaryBy' => array( self::AREAS_VARY_BY ),
			'paintBy'     => null,
			'variants'    => array( $variant( 'v_a6', self::A6 ), $variant( 'v_a5', self::A5 ) ),
		);
	}

	public function test_a_standing_side_at_the_placements_default_is_judged_on_its_rows_areas_and_sold(): void {
		$product = $this->product();
		$this->script_save();

		$this->save( $product->get_id(), array(), array( 'mode' => Side::STANDING ) );

		$asked = $this->print_size_bodies();
		$this->assertCount( 1, $asked );
		$this->assertSame( array( 'sizes', 'blankId', 'placementId' ), array_keys( $asked[0] ) );
		$this->assertSame(
			array_values( self::AREAS ),
			array_map( static fn ( array $size ): array => array( $size['widthMm'], $size['heightMm'] ), $asked[0]['sizes'] ),
			'each row\'s area is asked about, not the placement\'s own box'
		);
		$config = ProductConfig::stored_on( $this->reload( $product->get_id() ) );
		$this->assertTrue( $config->valid, (string) $config->invalid_reason );
		$side = $config->sides[0];
		$this->assertSame( 'des_row_a6', $side->standing_design_for( 'v_a6' ), 'an A6 variant is printed from its row\'s design' );
		$this->assertSame(
			array( array( self::A6, ...self::AREAS[ self::A6 ] ), array( self::A5, ...self::AREAS[ self::A5 ] ) ),
			array_map( static fn ( RowSize $row ): array => array( $row->row[ self::AREAS_VARY_BY ], (int) $row->size?->width_mm, (int) $row->size?->height_mm ), $side->row_sizes ),
			'each row prints at its whole area'
		);
		$this->assertSame(
			array( array( self::A6, 'des_row_a6', true ), array( self::A5, 'des_row_a5', true ) ),
			array_map( static fn ( StandingRow $row ): array => array( $row->label(), $row->design_id, $row->usual ), $side->standing_rows ),
			'each row\'s design prints at its row\'s area'
		);
		$requests = $this->standing_requests();
		$this->assertCount( 3, $requests );
		$this->assertSame( array( self::AREAS_VARY_BY => self::A6 ), $requests[1]['variantRow'] );
		$this->assertArrayNotHasKey( 'widthMm', $requests[1], 'the row prints at its usual size, its area' );
		$this->assertArrayNotHasKey( 'widthMm', $requests[2] );
	}

	public function test_a_row_whose_area_pixonaut_refuses_gets_no_design_and_the_others_are_sold(): void {
		$product  = $this->product();
		$too_soft = array(
			'legal'         => false,
			'reason'        => InvalidReason::LOW_RESOLUTION,
			'lowResolution' => array(),
		);
		$this->script_save( array( VerdictAnswers::LEGAL, $too_soft ), array( self::A6 ) );

		$this->save( $product->get_id(), array(), array( 'mode' => Side::STANDING ) );

		$config = ProductConfig::stored_on( $this->reload( $product->get_id() ) );
		$this->assertTrue( $config->valid, 'a row it fits still sells' );
		$side = $config->sides[0];
		$this->assertSame( 'des_row_a6', $side->standing_design_for( 'v_a6' ) );
		$this->assertNull( $side->standing_design_for( 'v_a5' ), 'an A5 variant is not sold' );
		$this->assertCount( 2, $this->standing_requests(), 'no design is asked for the refused row' );
		$this->assertSame( array( MisfitSizes::value( array( self::AREAS_VARY_BY => self::A5 ) ) ), $side->misfit_sizes );
	}

	/**
	 * Scripts a save of the standing side at the placement's default on the
	 * card blank: the blank, the constraints, each row's area's verdict, the
	 * template's read, then the refusal of a design naming no row, the blank
	 * again and each designed row's design with its read.
	 *
	 * @param array[]|null  $verdicts Pixonaut's verdict of each row's area; null for legal.
	 * @param string[]|null $designed The rows given a design; null for every row.
	 * @phpstan-param list<array<string, mixed>>|null $verdicts
	 * @phpstan-param list<string>|null $designed
	 */
	private function script_save( ?array $verdicts = null, ?array $designed = null ): void {
		$blank = $this->card_blank();
		$this->script_blank( $blank );
		$constraints                               = $this->constraints( self::PLACEMENT_ID );
		$constraints['ratio']                      = array(
			'w' => self::AREAS[ self::A6 ][0],
			'h' => self::AREAS[ self::A6 ][1],
		);
		$constraints['placement']['coverage']      = 'full';
		$constraints['placement']['printWidthMm']  = self::PLACEMENT_BOX_MM;
		$constraints['placement']['printHeightMm'] = self::PLACEMENT_BOX_MM;
		$this->recorder->json( 200, $constraints );
		$this->recorder->json( 200, array( 'verdicts' => $verdicts ?? array_fill( 0, count( self::AREAS ), VerdictAnswers::LEGAL ) ) );
		$this->script_template();
		$this->recorder->json(
			422,
			array(
				'error'       => ErrorCode::VARIANT_REQUIRED,
				'reason'      => 'The blank\'s print areas change with Size.',
				'areasVaryBy' => array( self::AREAS_VARY_BY ),
			)
		);
		$this->script_blank( $blank );
		foreach ( array_intersect_key( self::AREAS, array_flip( $designed ?? array_keys( self::AREAS ) ) ) as $size => list( $width, $height ) ) {
			$design_id = 'des_row_' . strtolower( $size );
			$this->script_standing( $design_id );
			$this->recorder->json(
				200,
				DesignFixture::design(
					array(
						'id'        => $design_id,
						'proofId'   => self::ROW_PROOF,
						'printSize' => array(
							'widthMm'  => $width,
							'heightMm' => $height,
						),
					)
				)
			);
		}
	}

	/**
	 * The blank, its front printed across an area per size.
	 *
	 * @return array<string, mixed>
	 */
	private function card_blank(): array {
		$blank                              = $this->blank();
		$blank['areasVaryBy']               = array( self::AREAS_VARY_BY );
		$blank['placements'][0]['coverage'] = 'full';
		$blank['placements'][0]['areas']    = array();
		foreach ( self::AREAS as $size => list( $width, $height ) ) {
			$blank['placements'][0]['areas'][] = array(
				'row'           => array( self::AREAS_VARY_BY => $size ),
				'widthMm'       => $width,
				'heightMm'      => $height,
				'usualWidthMm'  => $width,
				'usualHeightMm' => $height,
			);
		}
		return $blank;
	}

	/**
	 * What each print-size check asked, in order.
	 *
	 * @return list<array<string, mixed>>
	 */
	private function print_size_bodies(): array {
		$bodies = array();
		foreach ( $this->recorder->requests as $request ) {
			if ( str_contains( $request['url'], '/print-size' ) ) {
				$bodies[] = (array) json_decode( (string) $request['body'], true );
			}
		}
		return $bodies;
	}
}
