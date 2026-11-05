<?php
/**
 * A print size a side is sold in.
 *
 * @package Pixonaut\WooCommerce
 */

declare(strict_types=1);

namespace Pixonaut\WooCommerce\Catalogue;

use InvalidArgumentException;

/**
 * Width and height in millimetres, without bleed, with the label customers see.
 * A size resolved from the placement's default print area says so, so the
 * product screen shows the store manager's choice rather than its millimetres.
 */
final class PrintSize {

	/**
	 * @param float  $width_mm  Width in millimetres, finite and positive.
	 * @param float  $height_mm Height in millimetres, finite and positive.
	 * @param string $label     Label customers see, e.g. "30×40".
	 * @param bool   $placement_default Whether it is the placement's default print area, fitted to the template.
	 * @throws InvalidArgumentException When a dimension is not finite and positive.
	 */
	public function __construct(
		public readonly float $width_mm,
		public readonly float $height_mm,
		public readonly string $label,
		public readonly bool $placement_default = false,
	) {
		if ( ! self::is_usable( $width_mm ) || ! self::is_usable( $height_mm ) ) {
			throw new InvalidArgumentException( 'A print size needs a finite, positive width and height.' );
		}
	}

	/**
	 * @param mixed $data Stored shape, see stored().
	 * @return self|null Null when the data is not a usable size.
	 */
	public static function from_array( mixed $data ): ?self {
		if ( ! is_array( $data ) || ! is_numeric( $data['widthMm'] ?? null ) || ! is_numeric( $data['heightMm'] ?? null ) ) {
			return null;
		}
		$width  = (float) $data['widthMm'];
		$height = (float) $data['heightMm'];
		if ( ! self::is_usable( $width ) || ! self::is_usable( $height ) ) {
			return null;
		}
		return new self( $width, $height, is_string( $data['label'] ?? null ) ? $data['label'] : '', true === ( $data['placementDefault'] ?? false ) );
	}

	/**
	 * Whether a width and height in millimetres make a size: finite and positive.
	 *
	 * @param float $width_mm  Width.
	 * @param float $height_mm Height.
	 */
	public static function fits( float $width_mm, float $height_mm ): bool {
		return self::is_usable( $width_mm ) && self::is_usable( $height_mm );
	}

	/**
	 * The label a size gets when the store manager names none, e.g. "280 × 360 mm".
	 *
	 * @param float $width_mm  Width in millimetres.
	 * @param float $height_mm Height in millimetres.
	 */
	public static function label_for( float $width_mm, float $height_mm ): string {
		return sprintf(
			/* translators: 1: width, 2: height, both in millimetres. */
			__( '%1$s × %2$s mm', 'pixonaut-for-woocommerce' ),
			self::millimetres( $width_mm ),
			self::millimetres( $height_mm )
		);
	}

	/**
	 * The size as the SDK takes it in a print-size menu.
	 *
	 * @return array{widthMm: float, heightMm: float, label: string}
	 */
	public function to_array(): array {
		return array(
			'widthMm'  => $this->width_mm,
			'heightMm' => $this->height_mm,
			'label'    => $this->label,
		);
	}

	/**
	 * The size as a product's configuration stores it.
	 *
	 * @return array<string, float|string|bool>
	 */
	public function stored(): array {
		return $this->to_array() + ( $this->placement_default ? array( 'placementDefault' => true ) : array() );
	}

	/**
	 * Millimetres to one decimal, without a trailing `.0`.
	 *
	 * @param float $millimetres A length.
	 */
	public static function millimetres( float $millimetres ): string {
		return rtrim( rtrim( number_format( $millimetres, 1, '.', '' ), '0' ), '.' );
	}

	private static function is_usable( float $millimetres ): bool {
		return is_finite( $millimetres ) && $millimetres > 0;
	}
}
