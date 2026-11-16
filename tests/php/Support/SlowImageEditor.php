<?php
/**
 * An image editor that takes longer to make image sizes than a web request may run.
 *
 * @package Pixonaut\WooCommerce
 */

declare(strict_types=1);

namespace Pixonaut\WooCommerce\Tests\Support;

use Error;
use WP_Image_Editor_GD;

require_once ABSPATH . 'wp-includes/class-wp-image-editor.php';
require_once ABSPATH . 'wp-includes/class-wp-image-editor-gd.php';

/**
 * WordPress's GD editor, as slow as Imagick on a busy host: each image size
 * takes `SIZE_SECONDS`. Under a time limit shorter than that it stops as
 * PHP would, with the fatal error PHP raises; it never waits for real.
 * `use()` makes it the editor WordPress picks.
 */
final class SlowImageEditor extends WP_Image_Editor_GD {

	/**
	 * How long making one image size takes: past a web request's usual
	 * 30-second limit.
	 */
	public const SIZE_SECONDS = 45;

	/**
	 * Whether PHP's time limit stays as it is, as on a host that disables
	 * `set_time_limit()`.
	 *
	 * @var bool
	 */
	public static bool $limit_fixed = false;

	/**
	 * The time limit in force on a host with `$limit_fixed`, in seconds.
	 */
	public const FIXED_LIMIT = 30;

	/**
	 * Image sizes made since `use()`.
	 *
	 * @var int
	 */
	public static int $made = 0;

	/**
	 * Makes it the editor WordPress picks until `stop()`.
	 */
	public static function use(): void {
		self::$made        = 0;
		self::$limit_fixed = false;
		add_filter( 'wp_image_editors', array( self::class, 'editors' ) );
	}

	public static function stop(): void {
		remove_filter( 'wp_image_editors', array( self::class, 'editors' ) );
	}

	/**
	 * @return list<class-string>
	 */
	public static function editors(): array {
		return array( self::class );
	}

	/**
	 * @param array<string, mixed> $size_data The size.
	 *
	 * @return array<string, mixed>|\WP_Error
	 */
	public function make_subsize( $size_data ) {
		self::spend();
		++self::$made;
		return parent::make_subsize( $size_data );
	}

	/**
	 * Stops as PHP does when making an image size would pass the time limit.
	 *
	 * @throws Error When it would.
	 */
	private static function spend(): void {
		$limit = self::$limit_fixed ? self::FIXED_LIMIT : (int) ini_get( 'max_execution_time' );
		if ( 0 !== $limit && $limit < self::SIZE_SECONDS ) {
			throw new Error( sprintf( 'Maximum execution time of %d seconds exceeded', $limit ) );
		}
	}
}
