<?php
/**
 * An image of a template that prints below its recommended resolution at a size.
 *
 * @package Pixonaut\WooCommerce
 */

declare(strict_types=1);

namespace Pixonaut\WooCommerce\Api\Answers;

use Pixonaut\WooCommerce\Vendor\Pixonaut\Sdk\Exception\UnexpectedResponse;

/**
 * An item of a print-size verdict's `lowResolution`: an image the workspace
 * put in the template, which prints below its recommended resolution at the
 * size judged. Kept with the size on the product, as `stored()` writes it.
 */
final class LowResolutionImage {

	/**
	 * @param string      $layer_id        `layerId`: the layer the image fills, or the artwork's own key for its image background. An internal key, never shown.
	 * @param string      $asset_id        `assetId`: the library picture.
	 * @param float       $effective_dpi   `effectiveDpi`: pixels per inch along the edge that decides, at the size.
	 * @param float       $recommended_dpi `recommendedDpi`: the resolution it is judged against.
	 * @param string|null $label           `label`: the image as the merchant knows it (its layer's name, else its form field's label, else Pixonaut's word for a picture or the background, in the workspace's language); null for a blank one, and for an image kept before Pixonaut named them.
	 */
	public function __construct(
		public readonly string $layer_id,
		public readonly string $asset_id,
		public readonly float $effective_dpi,
		public readonly float $recommended_dpi,
		public readonly ?string $label = null,
	) {
	}

	/**
	 * @param array<string, mixed> $data The item.
	 * @param string               $path Where it is in the answer.
	 *
	 * @throws UnexpectedResponse When a field is missing or of another type.
	 */
	public static function from_answer( array $data, string $path = '$' ): self {
		return new self(
			Read::string( $data, 'layerId', $path ),
			Read::string( $data, 'assetId', $path ),
			Read::float( $data, 'effectiveDpi', $path ),
			Read::float( $data, 'recommendedDpi', $path ),
			self::named( Read::optional_string( $data, 'label', $path ) ),
		);
	}

	/**
	 * The image as `stored()` wrote it; null for anything else.
	 *
	 * @param mixed $data The stored image.
	 */
	public static function from_stored( mixed $data ): ?self {
		if ( ! is_array( $data ) ) {
			return null;
		}
		$layer = $data['layerId'] ?? null;
		$asset = $data['assetId'] ?? null;
		$dpi   = $data['effectiveDpi'] ?? null;
		$aim   = $data['recommendedDpi'] ?? null;
		if ( ! is_string( $layer ) || ! is_string( $asset ) || '' === $asset || ! is_numeric( $dpi ) || ! is_numeric( $aim ) ) {
			return null;
		}
		$label = $data['label'] ?? null;
		return new self( $layer, $asset, (float) $dpi, (float) $aim, self::named( is_string( $label ) ? $label : null ) );
	}

	/**
	 * A label worth showing: null for none or a blank one.
	 *
	 * @param string|null $label The label as answered or stored.
	 */
	private static function named( ?string $label ): ?string {
		return null === $label || '' === trim( $label ) ? null : $label;
	}

	/**
	 * @return array{layerId: string, assetId: string, effectiveDpi: float, recommendedDpi: float, label?: string}
	 */
	public function stored(): array {
		return array(
			'layerId'        => $this->layer_id,
			'assetId'        => $this->asset_id,
			'effectiveDpi'   => $this->effective_dpi,
			'recommendedDpi' => $this->recommended_dpi,
		) + ( null === $this->label ? array() : array( 'label' => $this->label ) );
	}

	/**
	 * Images as `stored()` wrote them, skipping anything else.
	 *
	 * @param mixed $data The stored list.
	 * @return list<self>
	 */
	public static function list_from_stored( mixed $data ): array {
		return array_values( array_filter( array_map( array( self::class, 'from_stored' ), is_array( $data ) ? $data : array() ) ) );
	}
}
