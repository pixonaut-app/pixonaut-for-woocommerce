<?php
/**
 * @package Pixonaut\WooCommerce
 */

declare(strict_types=1);

namespace Pixonaut\WooCommerce\Tests\Unit\Catalogue;

use Brain\Monkey\Functions;
use Pixonaut\WooCommerce\Catalogue\SearchResults;
use Pixonaut\WooCommerce\Tests\Unit\TestCase;

final class SearchResultsTest extends TestCase {

	public function test_templates_are_listed_at_their_latest_version_with_their_preview(): void {
		$options = SearchResults::templates(
			array(
				array(
					'baseId'         => 'mini-tee',
					'name'           => 'Mini tee',
					'latestVersion'  => 2,
					'ratio'          => array(
						'w' => 280,
						'h' => 360,
					),
					'ratioTolerance' => 0,
					'previewUrl'     => 'https://api.pixonaut.app/i/short-lived',
				),
				array(
					'baseId'        => 'unnamed',
					'latestVersion' => 1,
				),
				array(
					'baseId'        => 'never-published',
					'name'          => 'Draft',
					'latestVersion' => 0,
				),
				array( 'name' => 'No id' ),
			)
		);

		$this->assertSame(
			array(
				array(
					'id'        => 'mini-tee@2',
					'text'      => 'Mini tee',
					'thumbnail' => 'https://api.pixonaut.app/i/short-lived',
				),
				array(
					'id'        => 'unnamed@1',
					'text'      => 'unnamed@1',
					'thumbnail' => '',
				),
			),
			$options
		);
	}

	public function test_a_template_says_whether_its_background_shows_through_only_when_pixonaut_knows(): void {
		$item = array(
			'baseId'        => 'mini-tee',
			'name'          => 'Mini tee',
			'latestVersion' => 2,
		);

		$options = SearchResults::templates(
			array(
				$item + array( 'backgroundSeeThrough' => true ),
				$item + array( 'backgroundSeeThrough' => false ),
				$item + array( 'backgroundSeeThrough' => null ),
				$item,
			)
		);

		$this->assertTrue( $options[0]['backgroundSeeThrough'] ?? null );
		$this->assertFalse( $options[1]['backgroundSeeThrough'] ?? null );
		$this->assertArrayNotHasKey( 'backgroundSeeThrough', $options[2] );
		$this->assertArrayNotHasKey( 'backgroundSeeThrough', $options[3] );
	}

	public function test_a_template_says_whether_it_offers_a_field_when_pixonaut_does(): void {
		$options = SearchResults::templates(
			array(
				array(
					'baseId'           => 'mini-tee',
					'name'             => 'Mini tee',
					'latestVersion'    => 2,
					'offersProperties' => false,
				),
				array(
					'baseId'        => 'mini-poster',
					'name'          => 'Mini poster',
					'latestVersion' => 1,
				),
			)
		);

		$this->assertSame( false, $options[0]['offersProperties'] ?? null );
		$this->assertArrayNotHasKey( 'offersProperties', $options[1] );
	}

	public function test_blanks_carry_their_first_photo_and_placements_by_shop_name(): void {
		$options = SearchResults::blanks(
			array(
				array(
					'id'           => 'blank_tee',
					'name'         => 'Fixture tee',
					'thumbnailUrl' => 'https://api.pixonaut.app/i/photo',
					'placements'   => array(
						array(
							'id'          => 'front',
							'name'        => 'front-print',
							'shopName'    => 'Front',
							'maxWidthMm'  => 330,
							'maxHeightMm' => 420.5,
						),
						array(
							'id'       => 'back',
							'name'     => 'Back print',
							'shopName' => null,
						),
						array( 'name' => 'No id' ),
					),
				),
				array( 'name' => 'No id' ),
			)
		);

		$this->assertSame(
			array(
				array(
					'id'         => 'blank_tee',
					'text'       => 'Fixture tee',
					'thumbnail'  => 'https://api.pixonaut.app/i/photo',
					'placements' => array(
						array(
							'id'          => 'front',
							'text'        => 'Front',
							'maxWidthMm'  => 330.0,
							'maxHeightMm' => 420.5,
						),
						array(
							'id'   => 'back',
							'text' => 'Back print',
						),
					),
				),
			),
			$options
		);
	}

	public function test_a_placement_limit_needs_both_sides_above_zero(): void {
		$this->assertSame( array(), SearchResults::limit( array( 'maxWidthMm' => 330 ) ) );
		$this->assertSame(
			array(),
			SearchResults::limit(
				array(
					'maxWidthMm'  => 0,
					'maxHeightMm' => 420,
				)
			)
		);
		$this->assertSame(
			array(),
			SearchResults::limit(
				array(
					'maxWidthMm'  => '330',
					'maxHeightMm' => 420,
				)
			)
		);
	}

	public function test_blanks_still_importing_or_failed_are_left_out(): void {
		$options = SearchResults::blanks(
			array(
				array(
					'id'         => 'blank_importing',
					'name'       => 'Importing tee',
					'status'     => 'importing',
					'placements' => array(),
				),
				array(
					'id'            => 'blank_failed',
					'name'          => 'Failed tee',
					'status'        => 'failed',
					'importFailure' => 'partner_failed',
					'placements'    => array(),
				),
				array(
					'id'         => 'blank_ready',
					'name'       => 'Ready tee',
					'status'     => 'ready',
					'placements' => array(),
				),
			)
		);

		$this->assertSame( array( 'blank_ready' ), array_column( $options, 'id' ) );
	}

	public function test_fitting_templates_keep_only_legal_ones(): void {
		$options = SearchResults::fitting_templates(
			array(
				array(
					'templateId' => 'mini-tee@2',
					'name'       => 'Mini tee',
					'verdict'    => array( 'legal' => true ),
					'previewUrl' => 'https://api.pixonaut.app/i/preview',
				),
				array(
					'templateId' => 'poster@1',
					'name'       => 'Poster',
					'verdict'    => array(
						'legal'  => false,
						'reason' => 'wrong_ratio',
					),
				),
				array(
					'templateId' => 'no-version',
					'name'       => 'Malformed',
					'verdict'    => array( 'legal' => true ),
				),
			)
		);

		$this->assertSame(
			array(
				array(
					'id'        => 'mini-tee@2',
					'text'      => 'Mini tee',
					'thumbnail' => 'https://api.pixonaut.app/i/preview',
					'misfits'   => array(),
					'note'      => '',
					'warning'   => '',
				),
			),
			$options
		);
	}

	public function test_a_fitting_template_names_the_sizes_it_cannot_be_printed_at(): void {
		Functions\stubTranslationFunctions();
		Functions\when( 'wp_sprintf' )->alias( static fn ( string $format, array $items ): string => str_replace( '%l', implode( ' and ', $items ), $format ) );

		$options = SearchResults::fitting_templates(
			array(
				array(
					'templateId' => 'panel-tee@1',
					'name'       => 'Panel tee',
					'verdict'    => array( 'legal' => true ),
					'sizes'      => array(
						'fits'    => array( 'S', 'M' ),
						'misfits' => array( 'XL', '', 7, 'XL / Long' ),
					),
				),
			)
		);

		$this->assertSame( array( 'XL', 'XL / Long' ), $options[0]['misfits'], 'values that are not text are dropped' );
		$this->assertSame( 'Cannot print at XL and XL / Long', $options[0]['note'] );
		$this->assertStringContainsString( 'at XL and XL / Long', $options[0]['warning'] );
	}

	public function test_a_blank_or_template_without_a_picture_has_an_empty_thumbnail(): void {
		$blanks    = SearchResults::blanks(
			array(
				array(
					'id'           => 'blank_bare',
					'name'         => 'Bare tee',
					'thumbnailUrl' => null,
				),
			)
		);
		$templates = SearchResults::fitting_templates(
			array(
				array(
					'templateId' => 'unpictured@1',
					'name'       => 'Draft',
					'verdict'    => array( 'legal' => true ),
					'previewUrl' => null,
				),
			)
		);

		$this->assertSame( '', $blanks[0]['thumbnail'] ?? null );
		$this->assertSame( '', $templates[0]['thumbnail'] ?? null );
	}
}
