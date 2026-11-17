<?php
/**
 * The free space where the shop keeps its media.
 *
 * @package Pixonaut\WooCommerce
 */

declare(strict_types=1);

namespace Pixonaut\WooCommerce\Catalogue;

/**
 * Pixonaut pictures are files in the shop's media library, so they take the
 * shop's own space, never Pixonaut's. On a WordPress network that enforces
 * upload quotas, it is what is left of the site's quota; otherwise the free
 * space on the disk of the uploads directory. Unknown (null) when PHP may
 * not read it.
 */
final class MediaSpace {

	/**
	 * Network option that turns off the sites' upload quotas.
	 */
	private const QUOTA_OFF_OPTION = 'upload_space_check_disabled';

	/**
	 * @param \Closure|null $disk_free `fn(string $directory): float|false`, the free bytes on the directory's disk; `disk_free_space()` when null.
	 * @param \Closure|null $quota     `fn(): ?int`, the bytes left of the site's upload quota, or null when none applies; WordPress's when null.
	 */
	public function __construct(
		private readonly ?\Closure $disk_free = null,
		private readonly ?\Closure $quota = null,
	) {
	}

	/**
	 * The free bytes for new media; null when they cannot be read.
	 */
	public function free(): ?int {
		$free = $this->measured();

		/**
		 * Filters the free space, in bytes, where the shop keeps its media,
		 * which Pixonaut pictures take: what is left of the site's upload
		 * quota on a network enforcing one, else the free space on the disk
		 * of the uploads directory. Return null when it cannot be known. A
		 * site whose media is kept elsewhere (offloaded to object storage)
		 * can say how much room is left there.
		 *
		 * @since 1.15.0
		 *
		 * @param int|null $free Free bytes; null when they cannot be read.
		 *
		 * @example
		 * add_filter( 'pixonaut_wc_catalogue_media_free_bytes', function ( $free ) {
		 *     return 500 * GB_IN_BYTES; // The bucket's own limit.
		 * } );
		 */
		$filtered = apply_filters( 'pixonaut_wc_catalogue_media_free_bytes', $free );
		return is_int( $filtered ) ? max( 0, $filtered ) : null;
	}

	/**
	 * The free bytes as WordPress and PHP read them; null when they cannot.
	 */
	private function measured(): ?int {
		$left = null === $this->quota ? self::quota_left() : ( $this->quota )();
		if ( null !== $left ) {
			return max( 0, $left );
		}
		$directory = wp_upload_dir( null, false )['basedir'];
		$free      = null === $this->disk_free ? self::disk_free( $directory ) : ( $this->disk_free )( $directory );
		return is_float( $free ) || is_int( $free ) ? max( 0, (int) $free ) : null;
	}

	/**
	 * The bytes left of the site's upload quota on a network that enforces
	 * one; null otherwise.
	 */
	public static function quota_left(): ?int {
		if ( ! is_multisite() || get_site_option( self::QUOTA_OFF_OPTION ) ) {
			return null;
		}
		return (int) round( ( get_space_allowed() - get_space_used() ) * MB_IN_BYTES );
	}

	/**
	 * The free bytes on the directory's disk; false when PHP may not read it.
	 *
	 * @param string $directory A directory.
	 */
	private static function disk_free( string $directory ): float|false {
		if ( ! function_exists( 'disk_free_space' ) || ! is_dir( $directory ) ) {
			return false;
		}
		// A host may refuse it (open_basedir); that is no error of the shop's.
		return @disk_free_space( $directory ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- as above.
	}
}
