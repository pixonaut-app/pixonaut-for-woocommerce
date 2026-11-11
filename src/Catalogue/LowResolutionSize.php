<?php
/**
 * A print size at which images of the template print below their recommended resolution.
 *
 * @package Pixonaut\WooCommerce
 */

declare(strict_types=1);

namespace Pixonaut\WooCommerce\Catalogue;

use Pixonaut\WooCommerce\Api\Answers\LowResolutionImage;

/**
 * One size of a side, as Pixonaut last judged it: the template's images
 * that print below their recommended resolution there, and whether
 * Pixonaut refused the size for them, which it does when the workspace
 * refuses such sizes rather than warns of them.
 */
final class LowResolutionSize {

	/**
	 * @param PrintSize            $size    The size judged.
	 * @param LowResolutionImage[] $images  The images that print below their recommended resolution at it.
	 * @param bool                 $refused Whether Pixonaut refused the size for them.
	 * @phpstan-param list<LowResolutionImage> $images
	 */
	public function __construct(
		public readonly PrintSize $size,
		public readonly array $images,
		public readonly bool $refused = false,
	) {
	}

	/**
	 * What names one image at one size of a side, for accepting it there:
	 * the side, the size in millimetres and the picture. Not the size's
	 * place in the menu, which removing another size moves.
	 *
	 * @param string             $side_key The side's key.
	 * @param PrintSize          $size     The size.
	 * @param LowResolutionImage $image    The image.
	 */
	public static function key( string $side_key, PrintSize $size, LowResolutionImage $image ): string {
		return implode( '|', array( $side_key, PrintSize::millimetres( $size->width_mm ), PrintSize::millimetres( $size->height_mm ), $image->asset_id ) );
	}

	/**
	 * The size as `to_array()` wrote it; null for anything else.
	 *
	 * @param mixed $data The stored size.
	 */
	public static function from_array( mixed $data ): ?self {
		if ( ! is_array( $data ) ) {
			return null;
		}
		$size   = PrintSize::from_array( $data['size'] ?? null );
		$images = LowResolutionImage::list_from_stored( $data['images'] ?? null );
		if ( null === $size || array() === $images ) {
			return null;
		}
		return new self( $size, $images, true === ( $data['refused'] ?? false ) );
	}

	/**
	 * Sizes as `to_array()` wrote them, skipping anything else.
	 *
	 * @param mixed $data The stored list.
	 * @return list<self>
	 */
	public static function list_from_array( mixed $data ): array {
		return array_values( array_filter( array_map( array( self::class, 'from_array' ), is_array( $data ) ? $data : array() ) ) );
	}

	/**
	 * @return array{size: array<string, mixed>, images: list<array<string, mixed>>, refused: bool}
	 */
	public function to_array(): array {
		return array(
			'size'    => $this->size->stored(),
			'images'  => array_map( static fn ( LowResolutionImage $image ): array => $image->stored(), $this->images ),
			'refused' => $this->refused,
		);
	}
}
