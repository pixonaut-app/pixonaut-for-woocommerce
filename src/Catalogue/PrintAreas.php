<?php
/**
 * A blank's print areas per row of the options they change with.
 *
 * @package Pixonaut\WooCommerce
 */

declare(strict_types=1);

namespace Pixonaut\WooCommerce\Catalogue;

/**
 * Reads a blank whose print areas change with an option (`areasVaryBy`,
 * such as Size): its rows, the variants in each, and a placement's print
 * area per row, from `GET /v1/blanks/{id}` and `GET /v1/blanks/{id}/variants`.
 */
final class PrintAreas {

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
	public static function rows( array $variants, array $varies_by ): array {
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
	public static function areas( array $blank, ?string $placement_id ): array {
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
	public static function area_of( array $areas, array $row ): ?array {
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
	public static function fits( PrintSize $size, ?array $area ): bool {
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
	 * How a print covers a placement: `full` when it is the placement's
	 * whole area, so a print of any other size is refused.
	 */
	public const COVERAGE_FULL = 'full';

	/**
	 * Whether a print on the blank's placement covers its whole area
	 * (`coverage: full`), rather than being placed within it.
	 *
	 * @param array<string, mixed> $blank        The blank.
	 * @param string|null          $placement_id The placement.
	 */
	public static function covers_area( array $blank, ?string $placement_id ): bool {
		foreach ( is_array( $blank['placements'] ?? null ) ? $blank['placements'] : array() as $placement ) {
			if ( is_array( $placement ) && ( $placement['id'] ?? null ) === $placement_id ) {
				return self::COVERAGE_FULL === ( $placement['coverage'] ?? null );
			}
		}
		return false;
	}
}
