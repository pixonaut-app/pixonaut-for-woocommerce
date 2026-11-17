<?php
/**
 * Pictures of what the designer draws first, kept as files of the store's.
 *
 * @package Pixonaut\WooCommerce
 */

declare(strict_types=1);

namespace Pixonaut\WooCommerce\Storefront;

use Closure;
use Pixonaut\WooCommerce\Api\Pictures;
use Pixonaut\WooCommerce\Catalogue\CatalogueCache;
use Pixonaut\WooCommerce\Catalogue\ProductConfig;
use Pixonaut\WooCommerce\Schema;
use Pixonaut\WooCommerce\Vendor\Pixonaut\Sdk\Exception\ApiException;
use WP_Error;

/**
 * The picture a side's preview shows from the first paint until the
 * designer has drawn: its template with its default values on the photo of
 * the view and variant the designer opens on, drawn by Pixonaut
 * (`GET /t/{id}/mockup.png`), as the designer's first frame is. The
 * storefront keeps it as a file in the uploads, as a WebP where the
 * server's image editor writes one, so a product page prints it as an
 * image of its own site that the browser loads with the page.
 *
 * A page never waits for one: a side without its picture is printed without,
 * and the picture is drawn once the page is sent, or, on a server that
 * cannot finish a response early, in an Action Scheduler job. One older than a day is
 * printed, and drawn again the same way, as a template's default values may
 * name the day (a date of birth that defaults to today). One page view at a
 * time draws a picture, and one Pixonaut refused is not asked for again for a
 * minute. Pictures no product page has printed for a month are deleted when
 * the next one is drawn.
 */
final class StagePosters {

	/**
	 * Under the uploads, where the pictures are kept.
	 */
	public const DIRECTORY = Schema::STAGE_POSTER_DIRECTORY;

	/**
	 * The long edge Pixonaut draws a picture at, in pixels: the largest a
	 * product page shows a preview at, on a screen of twice the density.
	 */
	public const SIZE = 1024;

	/**
	 * How long a picture is printed before it is drawn again.
	 */
	public const FRESH_SECONDS = 86400;

	/**
	 * Thirty days: how long a picture no page printed is kept. A page
	 * printing one draws it again within a day, so one older is printed
	 * nowhere.
	 */
	public const KEPT_SECONDS = 30 * self::FRESH_SECONDS;

	/**
	 * How long a page view holds the right to draw a picture: a drawing that
	 * failed is tried again after it.
	 */
	public const HOLD_SECONDS = 60;

	public const HOLD_OPTION_PREFIX = Schema::STAGE_POSTER_HOLD_OPTION_PREFIX;

	/**
	 * The job drawing a picture where the response cannot be finished early.
	 */
	public const ACTION = 'pixonaut_wc_stage_poster';

	public const GROUP = Schema::ACTION_GROUP;

	/**
	 * The quality a picture is written at: a photo, shown at most at its own size.
	 */
	private const QUALITY = 80;

	/**
	 * The formats a picture is written in, the first the image editor
	 * supports, by extension. Pixonaut's PNG is kept where it supports none.
	 *
	 * @var array<string, string>
	 */
	private const FORMATS = array(
		'webp' => 'image/webp',
		'jpg'  => 'image/jpeg',
	);

	private const PNG = 'png';

	/**
	 * The pictures to draw once the page is sent, by key.
	 *
	 * @var array<string, array{template: string, blank: string, placement: string, view: string, variant: string|null}>
	 */
	private array $queued = array();

	/**
	 * Finishes the response to the visitor; null where the server offers no way.
	 *
	 * @var (Closure(): bool)|null
	 */
	private ?Closure $finish_response;

	/**
	 * @param Pictures               $pictures        Draws the pictures.
	 * @param (Closure(): bool)|null $finish_response Finishes the response; by default PHP-FPM's or LiteSpeed's, where the server has one.
	 */
	public function __construct( private readonly Pictures $pictures, ?Closure $finish_response = null ) {
		$this->finish_response = $finish_response ?? CatalogueCache::server_finish_response();
	}

	public function register(): void {
		add_action( self::ACTION, array( $this, 'draw_scheduled' ), 10, 5 );
	}

	/**
	 * The picture a side's preview shows until its designer has drawn, or
	 * null when there is none yet, or none to draw: a side on no blank, or
	 * opening on the Design view. A missing or old one is drawn once the
	 * page is sent.
	 *
	 * @param array<string, mixed> $options The side's mount options.
	 * @param SideLayout           $layout  The side's layout.
	 * @param string|null          $variant The ref of the blank variant the page shows; null for the side's first.
	 * @return array{url: string, width: int, height: int, template: string, variant: string}|null
	 */
	public function for_side( array $options, SideLayout $layout, ?string $variant ): ?array {
		$view      = $layout->views[0]['id'] ?? null;
		$template  = $options['templateId'] ?? null;
		$blank     = $options['blankId'] ?? null;
		$placement = $options['placementId'] ?? null;
		$variant ??= is_array( $options['variants'] ?? null ) && is_string( $options['variants'][0] ?? null ) ? $options['variants'][0] : null;
		if ( ! is_string( $template ) || ! is_string( $blank ) || ! is_string( $placement ) || ! is_string( $view ) || ProductConfig::DESIGN_VIEW === $view || isset( $options['design'] ) ) {
			return null;
		}
		$source = array(
			'template'  => $template,
			'blank'     => $blank,
			'placement' => $placement,
			'view'      => $view,
			'variant'   => $variant,
		);
		$key    = self::key( $source );
		$kept   = $this->kept( $key );
		if ( null === $kept || time() - $kept['drawn_at'] >= self::FRESH_SECONDS ) {
			$this->queue( $key, $source );
		}
		if ( null === $kept ) {
			return null;
		}
		return array(
			'url'      => $kept['url'],
			'width'    => $kept['width'],
			'height'   => $kept['height'],
			'template' => $template,
			'variant'  => $variant ?? '',
		);
	}

	/**
	 * Draws the pictures the page printed without, or printed old, after
	 * every other shutdown callback and once the response is finished.
	 */
	public function draw_queued(): void {
		$queued       = $this->queued;
		$this->queued = array();
		if ( array() === $queued || null === $this->finish_response ) {
			return;
		}
		( $this->finish_response )();
		foreach ( $queued as $key => $source ) {
			if ( $this->draw( $key, $source ) ) {
				delete_option( self::HOLD_OPTION_PREFIX . $key );
			}
		}
	}

	/**
	 * Draws a picture a page queued as a job (`ACTION`).
	 *
	 * @param mixed $template  The template id.
	 * @param mixed $blank     The blank.
	 * @param mixed $placement The placement.
	 * @param mixed $view      The view.
	 * @param mixed $variant   The variant ref; null for the view's default photo.
	 */
	public function draw_scheduled( mixed $template, mixed $blank, mixed $placement, mixed $view, mixed $variant = null ): void {
		if ( ! is_string( $template ) || ! is_string( $blank ) || ! is_string( $placement ) || ! is_string( $view ) ) {
			return;
		}
		$source = array(
			'template'  => $template,
			'blank'     => $blank,
			'placement' => $placement,
			'view'      => $view,
			'variant'   => is_string( $variant ) ? $variant : null,
		);
		$key    = self::key( $source );
		if ( $this->draw( $key, $source ) ) {
			delete_option( self::HOLD_OPTION_PREFIX . $key );
		}
	}

	/**
	 * Draws one picture and keeps it, in place of any kept before; false
	 * when Pixonaut refused or could not be reached, or the file could not
	 * be written.
	 *
	 * @param string                                                                                        $key    The picture's key.
	 * @param array{template: string, blank: string, placement: string, view: string, variant: string|null} $source What it is drawn from.
	 */
	private function draw( string $key, array $source ): bool {
		try {
			$png = $this->pictures->mockup( $source['template'], $source['blank'], $source['placement'], $source['view'], $source['variant'], self::SIZE );
		} catch ( ApiException ) {
			return false;
		}
		$directory = self::directory();
		if ( null === $directory || ! wp_mkdir_p( $directory ) ) {
			return false;
		}
		$drawn = $directory . '/' . $key . '-drawing.' . self::PNG;
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- a file of the uploads, written as WordPress writes its own.
		if ( false === file_put_contents( $drawn, $png ) ) {
			return false;
		}
		$size = wp_getimagesize( $drawn );
		if ( ! is_array( $size ) || $size[0] <= 0 || $size[1] <= 0 ) {
			wp_delete_file( $drawn );
			return false;
		}
		$stale = $this->files( $key );
		$kept  = $this->converted( $drawn, $directory . '/' . $key . '-' . $size[0] . 'x' . $size[1] );
		foreach ( $stale as $file ) {
			if ( $file !== $kept ) {
				wp_delete_file( $file );
			}
		}
		$this->prune( $directory );
		return true;
	}

	/**
	 * Writes the picture drawn at `$drawn` to `$name`, with the extension of
	 * the first format the image editor supports, and deletes the drawing;
	 * keeps it as a PNG under `$name` where the editor supports none.
	 *
	 * @param string $drawn The PNG Pixonaut drew.
	 * @param string $name  The kept file's path, without its extension.
	 * @return string The kept file's path.
	 */
	private function converted( string $drawn, string $name ): string {
		$editor = wp_get_image_editor( $drawn );
		if ( ! $editor instanceof WP_Error ) {
			$editor->set_quality( self::QUALITY );
			foreach ( self::FORMATS as $extension => $mime ) {
				if ( ! wp_image_editor_supports( array( 'mime_type' => $mime ) ) ) {
					continue;
				}
				$saved = $editor->save( $name . '.' . $extension, $mime );
				if ( is_array( $saved ) ) {
					wp_delete_file( $drawn );
					return $name . '.' . $extension;
				}
			}
		}
		$kept = $name . '.' . self::PNG;
		// phpcs:ignore WordPress.WP.AlternativeFunctions.rename_rename -- moves a file of the uploads within its directory.
		rename( $drawn, $kept );
		return $kept;
	}

	/**
	 * Deletes the pictures not drawn again for `KEPT_SECONDS`.
	 *
	 * @param string $directory The pictures' directory.
	 */
	private function prune( string $directory ): void {
		foreach ( self::glob( $directory . '/*' ) as $file ) {
			$touched = filemtime( $file );
			if ( false !== $touched && time() - $touched >= self::KEPT_SECONDS ) {
				wp_delete_file( $file );
			}
		}
	}

	/**
	 * The kept picture's link, size and when it was drawn; null for none.
	 *
	 * @param string $key The picture's key.
	 * @return array{url: string, width: int, height: int, drawn_at: int}|null
	 */
	private function kept( string $key ): ?array {
		foreach ( $this->files( $key ) as $file ) {
			if ( 1 !== preg_match( '/-(\d+)x(\d+)\.(?:webp|jpg|png)$/', $file, $size ) ) {
				continue;
			}
			$drawn_at = filemtime( $file );
			$upload   = wp_upload_dir( null, false );
			return array(
				'url'      => $upload['baseurl'] . '/' . self::DIRECTORY . '/' . basename( $file ),
				'width'    => (int) $size[1],
				'height'   => (int) $size[2],
				'drawn_at' => false === $drawn_at ? 0 : $drawn_at,
			);
		}
		return null;
	}

	/**
	 * The files kept for a picture.
	 *
	 * @param string $key The picture's key.
	 * @return list<string>
	 */
	private function files( string $key ): array {
		$directory = self::directory();
		return null === $directory ? array() : self::glob( $directory . '/' . $key . '-*' );
	}

	/**
	 * Draws the picture once the page is sent, or queues a job to, unless
	 * another page view is drawing it, or a drawing of it failed less than a
	 * minute ago.
	 *
	 * @param string                                                                                        $key    The picture's key.
	 * @param array{template: string, blank: string, placement: string, view: string, variant: string|null} $source What it is drawn from.
	 */
	private function queue( string $key, array $source ): void {
		if ( isset( $this->queued[ $key ] ) || ( null === $this->finish_response && ! did_action( 'action_scheduler_init' ) ) ) {
			return;
		}
		$args = array_values( $source );
		if ( null === $this->finish_response && as_has_scheduled_action( self::ACTION, $args, self::GROUP ) ) {
			return;
		}
		if ( ! $this->hold( $key ) ) {
			return;
		}
		if ( null === $this->finish_response ) {
			as_enqueue_async_action( self::ACTION, $args, self::GROUP );
			return;
		}
		$this->queued[ $key ] = $source;
		add_action( 'shutdown', array( $this, 'draw_queued' ), PHP_INT_MAX );
	}

	/**
	 * Takes the right to draw a picture. `add_option()` inserts or fails
	 * atomically, so two page views never both draw it; a hold older than
	 * `HOLD_SECONDS` is taken over.
	 *
	 * @param string $key The picture's key.
	 */
	private function hold( string $key ): bool {
		$option = self::HOLD_OPTION_PREFIX . $key;
		$now    = time();
		if ( add_option( $option, (string) $now, '', false ) ) {
			return true;
		}
		$held_since = get_option( $option );
		if ( is_numeric( $held_since ) && $now - (int) $held_since < self::HOLD_SECONDS ) {
			return false;
		}
		delete_option( $option );
		return add_option( $option, (string) $now, '', false );
	}

	/**
	 * A picture's key: a hash of what it is drawn from and its size.
	 *
	 * @param array{template: string, blank: string, placement: string, view: string, variant: string|null} $source What it is drawn from.
	 */
	private static function key( array $source ): string {
		return md5( (string) wp_json_encode( array( $source, self::SIZE ) ) );
	}

	/**
	 * The files matching a pattern; none when it cannot be read.
	 *
	 * @param string $pattern A `glob()` pattern.
	 * @return list<string>
	 */
	private static function glob( string $pattern ): array {
		$files = glob( $pattern );
		return false === $files ? array() : $files;
	}

	/**
	 * The pictures' directory; null when the uploads have none.
	 */
	public static function directory(): ?string {
		$upload = wp_upload_dir( null, false );
		return '' !== $upload['basedir'] ? $upload['basedir'] . '/' . self::DIRECTORY : null;
	}
}
