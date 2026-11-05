<?php
/**
 * @package Pixonaut\WooCommerce
 */

declare(strict_types=1);

namespace Pixonaut\WooCommerce\Tests\Integration\Catalogue;

use DOMDocument;
use DOMElement;
use DOMXPath;
use Pixonaut\WooCommerce\Api\ErrorCodes;
use Pixonaut\WooCommerce\Tests\Support\DesignFixture;
use Pixonaut\WooCommerce\Tests\Support\OpenApiAssert;

/**
 * Scripts a standing side's save, on a blank whose print areas vary with
 * Size or on one whose areas do not, and reads what it sent and shows.
 */
abstract class StandingTestCase extends ProductPanelTestCase {

	protected const STANDING_ID = 'des_standing_1';

	protected const STANDING_ROUTE = 'POST /v1/designs/standing';

	/**
	 * The option the print areas of a blank whose areas vary change with.
	 */
	protected const AREAS_VARY_BY = 'Size';

	/**
	 * The proofs of the row designs, which name their previews.
	 */
	protected const ROW_PROOF = 'prf_row';

	/**
	 * Scripts what a save of a standing side on a blank whose print areas
	 * change with Size reads after the template: the refusal of a design
	 * naming no row, the blank with a front too small for the side's size on
	 * S, then per row its design and the design read back. The blank's
	 * variants are White S, Black S, White M, and a retired Red M.
	 *
	 * @param string[] $design_ids The rows' designs, S first; fewer stops the script after them.
	 * @param string   $read       `read` to answer each design's read, `known` when the side already holds its preview, `fail` to refuse it.
	 * @param bool     $refused    Whether the side first asks for a design naming no row, as a save does.
	 * @phpstan-param list<string> $design_ids
	 */
	protected function script_rows( array $design_ids, string $read = 'read', bool $refused = true ): void {
		if ( $refused ) {
			$this->recorder->json(
				422,
				array(
					'error'       => ErrorCodes::VARIANT_REQUIRED,
					'reason'      => 'The blank\'s print areas change with Size.',
					'areasVaryBy' => array( self::AREAS_VARY_BY ),
				)
			);
		}
		$blank                           = $this->blank();
		$blank['areasVaryBy']            = array( self::AREAS_VARY_BY );
		$blank['placements'][0]['areas'] = array(
			array(
				'row'           => array( self::AREAS_VARY_BY => 'S' ),
				'widthMm'       => 220,
				'heightMm'      => 280,
				'usualWidthMm'  => 160,
				'usualHeightMm' => 200,
			),
			array(
				'row'           => array( self::AREAS_VARY_BY => 'M' ),
				'widthMm'       => 330,
				'heightMm'      => 420,
				'usualWidthMm'  => 280,
				'usualHeightMm' => 360,
			),
		);
		$this->recorder->json( 200, $blank );
		$variant = static fn ( string $ref, string $color, string $size, ?array $retired = null ): array => array(
			'ref'     => $ref,
			'options' => array(
				'Color'             => $color,
				self::AREAS_VARY_BY => $size,
			),
			'retired' => $retired,
			'views'   => array(),
		);
		// Read beside the script; the read of the blank's variants after the save gets the same.
		$this->variants->answer = array(
			'axes'        => array( 'Color', self::AREAS_VARY_BY ),
			'options'     => array(),
			'areasVaryBy' => array( self::AREAS_VARY_BY ),
			'variants'    => array(
				$variant( 'v_white_s', 'White', 'S' ),
				$variant( 'v_black_s', 'Black', 'S' ),
				$variant( 'v_white_m', 'White', 'M' ),
				$variant( 'v_red_m', 'Red', 'M', array( 'reason' => 'retired' ) ),
			),
		);
		$sizes                  = array( array( 160, 200 ), array( self::PRINT_WIDTH_MM, self::PRINT_HEIGHT_MM ) );
		foreach ( $design_ids as $at => $design_id ) {
			$this->script_standing( $design_id );
			if ( 'known' === $read ) {
				continue;
			}
			if ( 'fail' === $read ) {
				$this->recorder->json( 500, array( 'error' => 'db_error' ) );
				continue;
			}
			$this->recorder->json(
				200,
				DesignFixture::design(
					array(
						'id'        => $design_id,
						'proofId'   => self::ROW_PROOF,
						'printSize' => array(
							'widthMm'  => $sizes[ $at ][0],
							'heightMm' => $sizes[ $at ][1],
						),
					)
				)
			);
		}
	}

	/**
	 * The per-option previews the product screen shows for the product's first side.
	 *
	 * @param int $product_id The product.
	 * @return list<array{value: string, image: string, usual: bool, adjusted: bool, adjust: string, reset: string}>
	 */
	protected function option_tiles( int $product_id ): array {
		$this->recorder->json( 200, $this->blank() );
		$this->recorder->json( 200, $this->mockups() );
		$this->recorder->json( 200, self::printshops() );
		$document = new DOMDocument();
		libxml_use_internal_errors( true );
		$document->loadHTML( '<?xml encoding="utf-8"?>' . $this->panel->fields_html( $this->panel->panel( $this->reload( $product_id ) ) ) );
		libxml_clear_errors();
		$xpath = new DOMXPath( $document );
		$found = $xpath->query( '//div[contains(@class, "pixonaut-sides")]//li[contains(@class, "pixonaut-standing-option")]' );
		$tiles = array();
		foreach ( false === $found ? array() : $found as $tile ) {
			if ( ! $tile instanceof DOMElement ) {
				continue;
			}
			$image   = $tile->getElementsByTagName( 'img' )->item( 0 );
			$tiles[] = array(
				'value'    => trim( (string) $xpath->evaluate( 'string(.//strong)', $tile ) ),
				'image'    => null === $image ? '' : $image->getAttribute( 'src' ),
				'usual'    => str_contains( (string) $xpath->evaluate( 'string(.)', $tile ), 'Usual size' ),
				'adjusted' => (bool) $xpath->evaluate( 'boolean(.//*[contains(@class, "pixonaut-standing-option__adjusted")])', $tile ),
				'adjust'   => (string) $xpath->evaluate( 'string(.//a[contains(@class, "pixonaut-standing-option__adjust")]/@href)', $tile ),
				'reset'    => (string) $xpath->evaluate( 'string(.//a[contains(@class, "pixonaut-standing-option__reset")]/@href)', $tile ),
			);
		}
		return $tiles;
	}

	/**
	 * Scripts the `POST /v1/designs/standing` answer.
	 *
	 * @param string $design_id    The design found.
	 * @param string $placement_id Its placement.
	 */
	protected function script_standing( string $design_id = self::STANDING_ID, string $placement_id = self::PLACEMENT_ID ): void {
		$this->recorder->raw(
			201,
			OpenApiAssert::example(
				'POST',
				'/v1/designs/standing',
				201,
				array(
					'designId'    => $design_id,
					'templateId'  => self::TEMPLATE_ID,
					'blankId'     => self::BLANK_ID,
					'placementId' => $placement_id,
					'widthMm'     => self::PRINT_WIDTH_MM,
					'heightMm'    => self::PRINT_HEIGHT_MM,
				)
			),
			'application/json'
		);
	}

	/**
	 * The bodies of the standing design requests sent, in order.
	 *
	 * @return list<array<string, mixed>>
	 */
	protected function standing_requests(): array {
		$bodies = array();
		foreach ( $this->recorder->requests as $request ) {
			if ( self::STANDING_ROUTE === $request['method'] . ' ' . wp_parse_url( $request['url'], PHP_URL_PATH ) ) {
				$bodies[] = json_decode( (string) $request['body'], true );
			}
		}
		return $bodies;
	}
}
