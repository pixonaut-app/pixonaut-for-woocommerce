<?php
/**
 * An image written again in a smaller format.
 *
 * @package Pixonaut\WooCommerce
 */

declare(strict_types=1);

namespace Pixonaut\WooCommerce\Catalogue;

use WP_Error;

/**
 * Pixonaut draws PNGs, lossless and several times larger than a photo-like
 * picture needs. A copy is written in the first of the given formats the
 * site's image editor writes; where it writes none, there is no copy and
 * the PNG is kept.
 */
final class SmallerImage {

	/**
	 * WebP, by extension: keeps a picture's transparency, which a product
	 * picture clipped to its template's shape has.
	 *
	 * @var array<string, string>
	 */
	public const WEBP = array( 'webp' => 'image/webp' );

	/**
	 * Writes a copy of `$file` to `$name` with the extension of the first
	 * format the image editor writes; the file itself stays.
	 *
	 * @param string                $file    An image.
	 * @param string                $name    The copy's path, without its extension.
	 * @param array<string, string> $formats Media types by extension, in order of preference.
	 * @param int|null              $quality The copy's quality, 1 to 100; null for the editor's default for its format.
	 * @return string|null The copy's path; null when none of the formats could be written.
	 */
	public static function write( string $file, string $name, array $formats, ?int $quality = null ): ?string {
		$editor = wp_get_image_editor( $file );
		if ( $editor instanceof WP_Error ) {
			return null;
		}
		if ( null !== $quality ) {
			$editor->set_quality( $quality );
		}
		foreach ( $formats as $extension => $mime ) {
			if ( ! wp_image_editor_supports( array( 'mime_type' => $mime ) ) ) {
				continue;
			}
			$saved = $editor->save( $name . '.' . $extension, $mime );
			if ( is_array( $saved ) ) {
				return $saved['path'];
			}
		}
		return null;
	}
}
