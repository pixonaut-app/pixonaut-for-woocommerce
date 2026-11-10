<?php
/**
 * A standing side's design for one row of its blank's print areas.
 *
 * @package Pixonaut\WooCommerce
 */

declare(strict_types=1);

namespace Pixonaut\WooCommerce\Catalogue;

/**
 * On a blank whose print areas change with an option (`areasVaryBy`, such
 * as Size), a standing side is printed from one standing design per row of
 * those options: the same template version, sized for that row's print
 * area. Each row keeps the refs of the blank's variants that are in it, so
 * a sale finds its design by the variant it sells. A row whose layout the
 * store manager adjusted by hand is printed from the adjustment's own
 * standing design instead (`LayoutAdjustment`).
 */
final class StandingRow {

	/**
	 * @param array<string, string> $row       A value of each option the print areas change with, by option name as written, `Size => XL`.
	 * @param string                $design_id The row's standing design.
	 * @param string[]              $variants  The refs of the blank's variants in the row, `v_…`.
	 * @param bool                  $usual     Whether the design is printed at the row's usual size, as the side's size does not fit the row.
	 * @param PrintSize|null        $size      What the design is printed at, as Pixonaut last said; null when it was not read.
	 * @param string|null           $proof_id  The design's proof, which names its preview; null when it was not read.
	 * @param LayoutAdjustment|null $adjusted  What the row's layout was adjusted on, when `$design_id` is its adjustment's; null while it follows the side.
	 * @phpstan-param list<string> $variants
	 */
	public function __construct(
		public readonly array $row,
		public readonly string $design_id,
		public readonly array $variants,
		public readonly bool $usual = false,
		public readonly ?PrintSize $size = null,
		public readonly ?string $proof_id = null,
		public readonly ?LayoutAdjustment $adjusted = null,
	) {
	}

	/**
	 * Whether the row names these values, in whatever order.
	 *
	 * @param array<string, string> $row A value of each option, by option name.
	 */
	public function is( array $row ): bool {
		$mine = $this->row;
		ksort( $mine );
		ksort( $row );
		return $mine === $row;
	}

	/**
	 * A copy holding the refs of the blank's variants in the row now.
	 *
	 * @param string[] $variants The refs, `v_…`.
	 * @phpstan-param list<string> $variants
	 */
	public function with_variants( array $variants ): self {
		return new self( $this->row, $this->design_id, $variants, $this->usual, $this->size, $this->proof_id, $this->adjusted );
	}

	/**
	 * A copy whose adjustment is on template `$to` when it was on `$from`;
	 * this very row otherwise.
	 *
	 * @param string $from The template's old base id.
	 * @param string $to   Its new base id.
	 */
	public function with_template_renamed( string $from, string $to ): self {
		$adjusted = $this->adjusted?->with_template_renamed( $from, $to );
		if ( $adjusted === $this->adjusted ) {
			return $this;
		}
		return new self( $this->row, $this->design_id, $this->variants, $this->usual, $this->size, $this->proof_id, $adjusted );
	}

	/**
	 * The row's values as the store manager reads them, `XL` or `XL, Tall`.
	 */
	public function label(): string {
		return implode( ', ', $this->row );
	}

	/**
	 * @param mixed $data Stored shape, see to_array().
	 * @return self|null Null when the data lacks a row or a design.
	 */
	public static function from_array( mixed $data ): ?self {
		if ( ! is_array( $data ) || ! is_array( $data['row'] ?? null ) || array() === $data['row'] || ! is_string( $data['designId'] ?? null ) || '' === $data['designId'] ) {
			return null;
		}
		$row = array();
		foreach ( $data['row'] as $option => $value ) {
			if ( ! is_string( $option ) || ! is_string( $value ) ) {
				return null;
			}
			$row[ $option ] = $value;
		}
		$variants = array_values( array_filter( is_array( $data['variants'] ?? null ) ? $data['variants'] : array(), 'is_string' ) );
		$usual    = $data['usual'] ?? false;
		return new self( $row, $data['designId'], $variants, true === $usual, PrintSize::from_array( $data['size'] ?? null ), self::text_or_null( $data['proofId'] ?? null ), LayoutAdjustment::from_array( $data['adjusted'] ?? null ) );
	}

	private static function text_or_null( mixed $value ): ?string {
		return is_string( $value ) && '' !== $value ? $value : null;
	}

	/**
	 * @return array{row: array<string, string>, designId: string, variants: list<string>, usual: bool, size: array<string, mixed>|null, proofId: string|null, adjusted: array<string, mixed>|null}
	 */
	public function to_array(): array {
		return array(
			'row'      => $this->row,
			'designId' => $this->design_id,
			'variants' => $this->variants,
			'usual'    => $this->usual,
			'size'     => $this->size?->stored(),
			'proofId'  => $this->proof_id,
			'adjusted' => $this->adjusted?->to_array(),
		);
	}
}
