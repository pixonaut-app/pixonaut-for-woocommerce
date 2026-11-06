<?php
/**
 * The standing designs of a side on a blank whose print areas vary.
 *
 * @package Pixonaut\WooCommerce
 */

declare(strict_types=1);

namespace Pixonaut\WooCommerce\Catalogue;

use Pixonaut\WooCommerce\Api\Catalogue;
use Pixonaut\WooCommerce\Api\Designs;
use Pixonaut\WooCommerce\Vendor\Pixonaut\Sdk\Exception\ApiException;
use Pixonaut\WooCommerce\Vendor\Pixonaut\Sdk\Pixonaut as Sdk;
use Pixonaut\WooCommerce\Vendor\Psr\Log\LoggerInterface;

/**
 * On a blank whose print areas change with an option (`areasVaryBy`, such as
 * Size), Pixonaut keeps a standing design per row of those options, and
 * refuses one that names no row. The store manager still sets the side up
 * once: each row the blank sells is given the standing design of the side's
 * template version for that row (`POST /v1/designs/standing` with its
 * `variantRow`), found again on every save while nothing changed and made
 * anew once something did, so the rows stay in step with the side.
 *
 * Rows are read from the blank's variants, as written: a row whose variants
 * are all retired sells nothing and gets no design. Each row is printed at
 * the side's size when that fits the row's print area, else at the row's
 * usual size, which Pixonaut fits to the template. Each design is read
 * for the size it prints at and the proof the product screen previews it
 * by, unless the side already holds that design's preview; a read that
 * fails is logged and leaves the preview out, not the design.
 *
 * A row the template cannot be printed at (Pixonaut refuses its design for
 * its print size: on a cut-and-sewn panel, the template's shape matches an
 * S's panel and not an XL's) gets no design, so its variants are not sold,
 * and is named among the side's misfit sizes.
 *
 * A row the store manager laid out by hand keeps its adjusted design, asking
 * Pixonaut nothing, while the side stays on the same template, blank and
 * placement (`LayoutAdjustment::holds()`): a new version or another size
 * leaves it as it is.
 */
final class StandingRows {

	/**
	 * @param Catalogue       $catalogue Reads the blank's variants and print areas.
	 * @param Designs         $designs   Finds standing designs.
	 * @param Sdk             $pixonaut Reads a standing design's size and proof.
	 * @param LoggerInterface $logger    Logs a design whose preview could not be read.
	 */
	public function __construct(
		private readonly Catalogue $catalogue,
		private readonly Designs $designs,
		private readonly Sdk $pixonaut,
		private readonly LoggerInterface $logger,
	) {
	}

	/**
	 * Each row's standing design for a side, in the order the blank lists
	 * its variants.
	 *
	 * @param string        $template_id `<baseId>@<version>`.
	 * @param string        $blank_id    The blank.
	 * @param Side          $side        The standing side, on one of the blank's placements.
	 * @param string[]      $varies_by   The options the print areas change with, as Pixonaut's refusal named them; used when the blank does not say.
	 * @param StandingRow[] $known  The rows the side held before, whose previews are kept for a design found again, and whose adjusted designs are kept.
	 * @phpstan-param list<string> $varies_by
	 * @phpstan-param list<StandingRow> $known
	 * @return array{rows: list<StandingRow>, misfits: list<string>} The rows given a design, and the values of those the template cannot be printed at.
	 *
	 * @throws ApiException When Pixonaut could not be read, or refused a row's design for anything but its print size.
	 */
	public function designs( string $template_id, string $blank_id, Side $side, array $varies_by, array $known = array() ): array {
		$blank     = $this->catalogue->blank( $blank_id );
		$named     = self::strings( $blank['areasVaryBy'] ?? null );
		$varies_by = array() === $named ? $varies_by : $named;
		$areas     = self::areas( $blank, $side->placement_id );

		$rows    = array();
		$misfits = array();
		foreach ( self::rows( $this->catalogue->variants( $blank_id ), $varies_by ) as $found ) {
			$adjusted = self::adjusted( $known, $found['row'], $blank_id, $side );
			if ( null !== $adjusted ) {
				$rows[] = $this->with_preview( $adjusted->with_variants( $found['variants'] ), $side );
				continue;
			}
			try {
				$rows[] = $this->row_design( $template_id, $blank_id, $side, $areas, $found['row'], $found['variants'], $known );
			} catch ( ApiException $e ) {
				if ( ! MisfitSizes::refused( $e ) ) {
					throw $e;
				}
				$misfits[] = MisfitSizes::value( $found['row'] );
			}
		}
		return array(
			'rows'    => $rows,
			'misfits' => $misfits,
		);
	}

	/**
	 * A row given the side's design for it: at the side's size when that
	 * fits the row's print area, else at the row's usual size.
	 *
	 * @param string                     $template_id `<baseId>@<version>`.
	 * @param string                     $blank_id    The blank.
	 * @param Side                       $side        The standing side.
	 * @param list<array<string, mixed>> $areas       The placement's print areas.
	 * @param array<string, string>      $values      The row's values, `Size => XL`.
	 * @param string[]                   $variants    The refs of the blank's variants in the row.
	 * @param StandingRow[]              $known       The rows held before, whose previews are kept for a design found again.
	 * @phpstan-param list<string> $variants
	 * @phpstan-param list<StandingRow> $known
	 *
	 * @throws ApiException When Pixonaut refused the row's design or could not be reached.
	 */
	private function row_design( string $template_id, string $blank_id, Side $side, array $areas, array $values, array $variants, array $known = array() ): StandingRow {
		$size                       = $side->standing_size();
		$sized                      = null !== $size && self::fits( $size, self::area_of( $areas, $values ) ) ? $size : null;
		$design_id                  = $this->designs->standing( $template_id, $blank_id, $side->placement_id, $sized, $values );
		list( $printed, $proof_id ) = self::known_preview( $known, $design_id ) ?? $this->preview( $design_id, $side->key );
		return new StandingRow( $values, $design_id, $variants, null === $sized && null !== $size, $printed, $proof_id );
	}

	/**
	 * A kept row with its preview, read again when it was not read before.
	 *
	 * @param StandingRow $row  The row.
	 * @param Side        $side The side it is a row of, for the log.
	 */
	private function with_preview( StandingRow $row, Side $side ): StandingRow {
		if ( null !== $row->proof_id ) {
			return $row;
		}
		list( $printed, $proof_id ) = $this->preview( $row->design_id, $side->key );
		return new StandingRow( $row->row, $row->design_id, $row->variants, $row->usual, $printed, $proof_id, $row->adjusted );
	}

	/**
	 * What a row's design was asked for by, which a layout adjustment of it
	 * must name again: the adjustment it holds, else the side's template
	 * version on its placement at the size the row prints at (none for the
	 * row's usual size). Null when the side names no template version or
	 * placement.
	 *
	 * @param Side        $side     The standing side.
	 * @param string      $blank_id The blank.
	 * @param StandingRow $row      One of the side's rows.
	 */
	public static function basis( Side $side, string $blank_id, StandingRow $row ): ?LayoutAdjustment {
		if ( null !== $row->adjusted ) {
			return $row->adjusted;
		}
		if ( null === $side->template_version || null === $side->placement_id ) {
			return null;
		}
		return new LayoutAdjustment( (string) new TemplateId( $side->template_base_id, $side->template_version ), $blank_id, $side->placement_id, $row->usual ? null : $side->standing_size() );
	}

	/**
	 * A row printed from the standing design Pixonaut makes from a layout
	 * adjustment's proof of it (`POST /v1/designs/standing` with
	 * `proofToken`), read for its preview.
	 *
	 * @param Side             $side        The standing side.
	 * @param StandingRow      $row         The row adjusted.
	 * @param LayoutAdjustment $basis       What the adjusted design was asked for by, `basis()`.
	 * @param string           $proof_token The adjustment's proof.
	 *
	 * @throws ApiException When Pixonaut refused (`not_a_layout_proof`, `layout_proof_mismatch`, `unknown_proof`, …) or could not be reached.
	 */
	public function adjust( Side $side, StandingRow $row, LayoutAdjustment $basis, string $proof_token ): StandingRow {
		$design_id                  = $this->designs->standing( $basis->template_id, $basis->blank_id, $basis->placement_id, $basis->size, $row->row, $proof_token );
		list( $printed, $proof_id ) = self::known_preview( array( $row ), $design_id ) ?? $this->preview( $design_id, $side->key );
		return new StandingRow( $row->row, $design_id, $row->variants, $row->usual, $printed, $proof_id, $basis );
	}

	/**
	 * A side's rows with one of them following the side again: its
	 * adjustment dropped, it is given the side's design for its row, while
	 * every other row keeps what it holds, asking Pixonaut nothing for them.
	 *
	 * @param string      $template_id `<baseId>@<version>`.
	 * @param string      $blank_id    The blank.
	 * @param Side        $side        The standing side.
	 * @param StandingRow $reset       The row to reset.
	 * @return list<StandingRow>
	 *
	 * @throws ApiException When Pixonaut could not be read, or refused a row's design.
	 */
	public function reset( string $template_id, string $blank_id, Side $side, StandingRow $reset ): array {
		$areas = self::areas( $this->catalogue->blank( $blank_id ), $side->placement_id );
		$rows  = array();
		foreach ( $side->standing_rows as $row ) {
			$rows[] = $row->design_id === $reset->design_id ? $this->row_design( $template_id, $blank_id, $side, $areas, $row->row, $row->variants ) : $row;
		}
		return $rows;
	}

	/**
	 * The row held before for these values, when it was laid out by hand on
	 * the side's template, blank and placement.
	 *
	 * @param StandingRow[]         $known    The rows held before.
	 * @param array<string, string> $row      The row's values.
	 * @param string                $blank_id The blank.
	 * @param Side                  $side     The side.
	 * @phpstan-param list<StandingRow> $known
	 */
	private static function adjusted( array $known, array $row, string $blank_id, Side $side ): ?StandingRow {
		foreach ( $known as $held ) {
			if ( $held->is( $row ) && null !== $held->adjusted && $held->adjusted->holds( $side->template_base_id, $blank_id, $side->placement_id ) ) {
				return $held;
			}
		}
		return null;
	}

	/**
	 * The preview a row held before for the same design, when it was read.
	 *
	 * @param StandingRow[] $known     The rows held before.
	 * @param string        $design_id The design found now.
	 * @phpstan-param list<StandingRow> $known
	 * @return array{0: PrintSize|null, 1: string}|null
	 */
	private static function known_preview( array $known, string $design_id ): ?array {
		foreach ( $known as $row ) {
			if ( $row->design_id === $design_id && null !== $row->proof_id ) {
				return array( $row->size, $row->proof_id );
			}
		}
		return null;
	}

	/**
	 * What a design prints at and its proof, read from it (`GET /v1/designs/{id}`).
	 *
	 * @param string $design_id The design.
	 * @param string $side_key  The side it is a row of, for the log.
	 * @return array{0: PrintSize|null, 1: string|null} Null for either when it was not read.
	 */
	private function preview( string $design_id, string $side_key ): array {
		try {
			$design = $this->pixonaut->designs()->get( $design_id )->raw();
		} catch ( ApiException $e ) {
			$this->logger->warning(
				sprintf( 'Pixonaut gave no preview of the standing design %s of side "%s": %s', $design_id, $side_key, $e->getMessage() ),
				array(
					'side'      => $side_key,
					'design_id' => $design_id,
					'status'    => $e->status,
					'code'      => $e->errorCode,
				)
			);
			return array( null, null );
		}
		$size     = PrintSize::from_array( $design[ Designs::PRINT_SIZE ] ?? null );
		$proof_id = $design[ Designs::PROOF_ID ] ?? null;
		return array(
			null === $size ? null : new PrintSize( $size->width_mm, $size->height_mm, PrintSize::label_for( $size->width_mm, $size->height_mm ) ),
			is_string( $proof_id ) && '' !== $proof_id ? $proof_id : null,
		);
	}

	/**
	 * The rows the blank sells: each distinct set of values the variants
	 * still sold have for the options the print areas change with, with the
	 * refs of those variants. A variant lacking one of the options is in no
	 * row.
	 *
	 * @param array[]  $variants  `GET /v1/blanks/{id}/variants` as written.
	 * @param string[] $varies_by The options the print areas change with.
	 * @phpstan-param list<array<string, mixed>> $variants
	 * @phpstan-param list<string> $varies_by
	 * @return list<array{row: array<string, string>, variants: list<string>}>
	 */
	private static function rows( array $variants, array $varies_by ): array {
		$rows = array();
		foreach ( $variants as $variant ) {
			$ref     = $variant['ref'] ?? null;
			$options = is_array( $variant['options'] ?? null ) ? $variant['options'] : array();
			if ( ! is_string( $ref ) || '' === $ref || null !== ( $variant['retired'] ?? null ) ) {
				continue;
			}
			$row = array();
			foreach ( $varies_by as $option ) {
				if ( ! is_string( $options[ $option ] ?? null ) ) {
					continue 2;
				}
				$row[ $option ] = $options[ $option ];
			}
			$key = (string) wp_json_encode( $row );
			if ( ! isset( $rows[ $key ] ) ) {
				$rows[ $key ] = array(
					'row'      => $row,
					'variants' => array(),
				);
			}
			$rows[ $key ]['variants'][] = $ref;
		}
		return array_values( $rows );
	}

	/**
	 * The placement's print area per row, from `GET /v1/blanks/{id}`.
	 *
	 * @param array<string, mixed> $blank        The blank.
	 * @param string|null          $placement_id The side's placement.
	 * @return list<array<string, mixed>>
	 */
	private static function areas( array $blank, ?string $placement_id ): array {
		foreach ( is_array( $blank['placements'] ?? null ) ? $blank['placements'] : array() as $placement ) {
			if ( is_array( $placement ) && ( $placement['id'] ?? null ) === $placement_id && is_array( $placement['areas'] ?? null ) ) {
				return array_values( array_filter( $placement['areas'], 'is_array' ) );
			}
		}
		return array();
	}

	/**
	 * The print area of a row; null when the placement names none for it.
	 *
	 * @param list<array<string, mixed>> $areas The placement's areas.
	 * @param array<string, string>      $row   The row.
	 * @return array<string, mixed>|null
	 */
	private static function area_of( array $areas, array $row ): ?array {
		foreach ( $areas as $area ) {
			$named = is_array( $area['row'] ?? null ) ? $area['row'] : array();
			ksort( $named );
			$wanted = $row;
			ksort( $wanted );
			if ( $named === $wanted ) {
				return $area;
			}
		}
		return null;
	}

	/**
	 * Whether a size fits inside a row's print area. Without an area of its
	 * own the row takes the placement's, which the side's sizes were checked
	 * against.
	 *
	 * @param PrintSize                 $size The side's size.
	 * @param array<string, mixed>|null $area The row's print area.
	 */
	private static function fits( PrintSize $size, ?array $area ): bool {
		if ( null === $area ) {
			return true;
		}
		$width  = $area['widthMm'] ?? null;
		$height = $area['heightMm'] ?? null;
		if ( ! is_numeric( $width ) || ! is_numeric( $height ) ) {
			return true;
		}
		return $size->width_mm <= (float) $width && $size->height_mm <= (float) $height;
	}

	/**
	 * @param mixed $value A list from an answer.
	 * @return list<string>
	 */
	private static function strings( mixed $value ): array {
		return array_values( array_filter( is_array( $value ) ? $value : array(), static fn ( mixed $item ): bool => is_string( $item ) && '' !== $item ) );
	}
}
