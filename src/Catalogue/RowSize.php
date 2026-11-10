<?php
/**
 * What a side prints at on one row of its blank's print areas.
 *
 * @package Pixonaut\WooCommerce
 */

declare(strict_types=1);

namespace Pixonaut\WooCommerce\Catalogue;

/**
 * On a blank whose print areas change with an option (`areasVaryBy`, such
 * as Size), a print naming no size is printed at its row's own size: the
 * template's ratio fitted into the row's usual box, or, on a placement a
 * print covers whole, the row's area. A personalised side keeps that size
 * per row, with the refs of the variants in the row and the row's area, so
 * a variation sold at the placement's default prints at its variant's row,
 * and a size entered for it is judged against that row.
 */
final class RowSize {

	/**
	 * @param array<string, string> $row       A value of each option the print areas change with, `Size => 30×40`.
	 * @param string[]              $variants  The refs of the blank's variants in the row, `v_…`.
	 * @param float                 $width_mm  The row's print area: its width in millimetres.
	 * @param float                 $height_mm Its height in millimetres.
	 * @param bool                  $full      Whether a print covers the area whole, so it is printed at the area alone.
	 * @param PrintSize|null        $size      What the side prints at on the row; null when its template cannot be printed at the row.
	 * @phpstan-param list<string> $variants
	 */
	public function __construct(
		public readonly array $row,
		public readonly array $variants,
		public readonly float $width_mm,
		public readonly float $height_mm,
		public readonly bool $full,
		public readonly ?PrintSize $size,
	) {
	}

	/**
	 * The row's size for a template of a ratio: its area when a print covers
	 * it whole, else the ratio fitted into the row's usual box, as Pixonaut
	 * sizes a print that names no size. Null when the area or box is not a
	 * size.
	 *
	 * @param array<string, mixed> $area    The row's print area, as the blank lists it.
	 * @param bool                 $full    Whether a print covers the area whole.
	 * @param float                $ratio_w The template's ratio: its width.
	 * @param float                $ratio_h Its height.
	 */
	public static function fitted( array $area, bool $full, float $ratio_w, float $ratio_h ): ?PrintSize {
		$width  = self::number( $area['widthMm'] ?? null );
		$height = self::number( $area['heightMm'] ?? null );
		if ( null === $width || null === $height ) {
			return null;
		}
		if ( $full ) {
			return self::size( $width, $height );
		}
		$box_w = self::number( $area['usualWidthMm'] ?? null ) ?? $width;
		$box_h = self::number( $area['usualHeightMm'] ?? null ) ?? $height;
		if ( ! PrintSize::fits( $ratio_w, $ratio_h ) ) {
			return self::size( $box_w, $box_h );
		}
		$ratio = $ratio_w / $ratio_h;
		// The box is wider than the template: its height binds.
		return $box_w / $box_h >= $ratio
			? self::size( min( $box_w, $box_h * $ratio ), $box_h )
			: self::size( $box_w, min( $box_h, $box_w / $ratio ) );
	}

	/**
	 * Why a size cannot be printed on the row, as Pixonaut's rule says of
	 * its area: larger than it (`too_large`), or, where a print covers the
	 * area whole, other than it (`print_size_not_area`). Null when it fits.
	 * The template's ratio is Pixonaut's to judge.
	 *
	 * @param PrintSize $size The size.
	 */
	public function refuses( PrintSize $size ): ?string {
		if ( $size->width_mm > $this->width_mm || $size->height_mm > $this->height_mm ) {
			return InvalidReason::TOO_LARGE;
		}
		// Pixonaut compares the millimetres exactly; the area's are what the blank says.
		if ( $this->full && ( $size->width_mm !== $this->width_mm || $size->height_mm !== $this->height_mm ) ) {
			return InvalidReason::PRINT_SIZE_NOT_AREA;
		}
		return null;
	}

	/**
	 * Whether the row holds a variant.
	 *
	 * @param string|null $variant The variant's ref; null for none.
	 */
	public function holds( ?string $variant ): bool {
		return null !== $variant && in_array( $variant, $this->variants, true );
	}

	/**
	 * The row as a side stores it.
	 *
	 * @return array<string, mixed>
	 */
	public function to_array(): array {
		return array(
			'row'      => $this->row,
			'variants' => $this->variants,
			'widthMm'  => $this->width_mm,
			'heightMm' => $this->height_mm,
			'full'     => $this->full,
			'size'     => $this->size?->stored(),
		);
	}

	/**
	 * @param mixed $data Stored shape, see to_array().
	 * @return self|null Null when the data is not a row.
	 */
	public static function from_array( mixed $data ): ?self {
		if ( ! is_array( $data ) || ! is_array( $data['row'] ?? null ) ) {
			return null;
		}
		$width  = self::number( $data['widthMm'] ?? null );
		$height = self::number( $data['heightMm'] ?? null );
		if ( null === $width || null === $height ) {
			return null;
		}
		$row = array_filter( $data['row'], static fn ( mixed $value, mixed $option ): bool => is_string( $value ) && is_string( $option ), ARRAY_FILTER_USE_BOTH );
		return new self(
			$row,
			array_values( array_filter( is_array( $data['variants'] ?? null ) ? $data['variants'] : array(), static fn ( mixed $ref ): bool => is_string( $ref ) && '' !== $ref ) ),
			$width,
			$height,
			isset( $data['full'] ) && true === $data['full'],
			PrintSize::from_array( $data['size'] ?? null ),
		);
	}

	private static function size( float $width_mm, float $height_mm ): PrintSize {
		return new PrintSize( $width_mm, $height_mm, PrintSize::label_for( $width_mm, $height_mm ), true );
	}

	private static function number( mixed $value ): ?float {
		return is_numeric( $value ) && PrintSize::fits( (float) $value, 1.0 ) ? (float) $value : null;
	}
}
