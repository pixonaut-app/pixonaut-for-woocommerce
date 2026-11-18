<?php
/**
 * @package Pixonaut\WooCommerce
 */

declare(strict_types=1);

namespace Pixonaut\WooCommerce\Tests\Integration\Catalogue;

use Pixonaut\WooCommerce\Catalogue\SharedPictures;
use Pixonaut\WooCommerce\Catalogue\SmallerPictures;
use Pixonaut\WooCommerce\Migrations\Migrations;
use Pixonaut\WooCommerce\Migrations\WriteSmallerPictures;
use WP_UnitTestCase;

/**
 * Pictures are kept as WebP, and pictures kept as PNGs are written again
 * as WebP in place.
 */
final class SmallerPicturesTest extends WP_UnitTestCase {

	use PicturedProducts;

	/**
	 * Sides of the PNG an earlier version kept: larger than WordPress's
	 * thumbnail, so it has sizes of its own.
	 */
	private const KEPT_WIDTH  = 400;
	private const KEPT_HEIGHT = 500;

	public function set_up(): void {
		parent::set_up();
		as_unschedule_all_actions( SmallerPictures::ALL_HOOK );
		$this->set_up_pictures();
	}

	public function tear_down(): void {
		remove_all_filters( 'wp_image_editors' );
		remove_all_filters( 'update_attached_file' );
		delete_option( SmallerPictures::BACKFILL_OPTION );
		as_unschedule_all_actions( SmallerPictures::ALL_HOOK );
		$this->tear_down_pictures();
		parent::tear_down();
	}

	public function test_a_drawn_picture_is_kept_as_webp_and_the_png_is_not(): void {
		$picture = $this->drawn( $this->product( self::config() ) )['id'];

		$file = (string) get_attached_file( $picture );
		$this->assertSame( 'image/webp', get_post_mime_type( $picture ) );
		$this->assertStringEndsWith( '.webp', $file );
		$this->assertFileExists( $file );
		$this->assertFileDoesNotExist( substr( $file, 0, -strlen( 'webp' ) ) . 'png' );
	}

	public function test_a_drawn_picture_stays_a_png_where_the_image_editor_writes_no_webp(): void {
		add_filter( 'wp_image_editors', '__return_empty_array' );

		$picture = $this->drawn( $this->product( self::config() ) )['id'];

		$this->assertSame( 'image/png', get_post_mime_type( $picture ) );
		$this->assertStringEndsWith( '.png', (string) get_attached_file( $picture ) );
	}

	public function test_a_png_picture_is_written_again_as_webp_in_place_with_its_sizes(): void {
		$picture   = $this->kept_png( 'source-a' );
		$png_files = $this->files_of( $picture );
		$this->assertGreaterThan( 1, count( $png_files ) );

		SmallerPictures::ask();
		SmallerPictures::convert_all();

		$this->assertSame( 'image/webp', get_post_mime_type( $picture ) );
		$metadata = wp_get_attachment_metadata( $picture );
		$this->assertIsArray( $metadata );
		$sizes = $metadata['sizes'] ?? array();
		$this->assertSame( self::KEPT_WIDTH, $metadata['width'] ?? null );
		$this->assertNotSame( array(), $sizes );
		foreach ( $sizes as $size ) {
			$this->assertSame( 'image/webp', $size['mime-type'] );
		}
		foreach ( $this->files_of( $picture ) as $file ) {
			$this->assertStringEndsWith( '.webp', $file );
			$this->assertFileExists( $file );
		}
		foreach ( $png_files as $file ) {
			$this->assertFileDoesNotExist( $file );
		}
		$this->assertSame( 'source-a', get_post_meta( $picture, SharedPictures::SOURCE_META, true ) );
		$this->assertSame( array( $this->own_image ), SharedPictures::owners( $picture ) );
	}

	public function test_a_png_that_is_not_a_pixonaut_picture_is_left_as_it_is(): void {
		$picture = $this->kept_png( 'source-b' );
		delete_post_meta( $picture, SharedPictures::SOURCE_META );

		SmallerPictures::ask();
		SmallerPictures::convert_all();

		$this->assertSame( 'image/png', get_post_mime_type( $picture ) );
		$this->assertFileExists( (string) get_attached_file( $picture ) );
	}

	public function test_a_png_picture_stays_where_the_image_editor_writes_no_webp(): void {
		$picture = $this->kept_png( 'source-c' );
		$file    = (string) get_attached_file( $picture );
		add_filter( 'wp_image_editors', '__return_empty_array' );

		$this->assertFalse( SmallerPictures::convert( $picture ) );

		$this->assertSame( 'image/png', get_post_mime_type( $picture ) );
		$this->assertSame( $file, get_attached_file( $picture ) );
		$this->assertFileExists( $file );
	}

	public function test_a_png_picture_stays_when_its_webp_misses_a_size_its_png_has(): void {
		$picture  = $this->kept_png( 'source-d' );
		$metadata = wp_get_attachment_metadata( $picture );
		$this->assertIsArray( $metadata );
		// A size larger than the picture, which its WebP cannot be made in.
		$metadata['sizes']['large'] = array(
			'file'      => 'pixonaut-kept-source-d-1024x1280.png',
			'width'     => 1024,
			'height'    => 1280,
			'mime-type' => 'image/png',
		);
		wp_update_attachment_metadata( $picture, $metadata );
		$files = $this->png_files_beside( $picture );

		$this->assertFalse( SmallerPictures::convert( $picture ) );

		$this->assertSame( 'image/png', get_post_mime_type( $picture ) );
		$this->assertSame( $metadata, wp_get_attachment_metadata( $picture ) );
		$this->assertSame( $files, $this->png_files_beside( $picture ) );
		$this->assertSame( array(), glob( dirname( (string) get_attached_file( $picture ) ) . '/pixonaut-kept-source-d*.webp' ) );
	}

	public function test_a_png_picture_is_put_back_when_the_attachment_cannot_be_changed(): void {
		$picture  = $this->kept_png( 'source-e' );
		$file     = (string) get_attached_file( $picture );
		$metadata = wp_get_attachment_metadata( $picture );
		add_filter( 'update_attached_file', static fn ( string $path ): string => str_ends_with( $path, '.webp' ) ? $path . '.lost' : $path );

		$this->assertFalse( SmallerPictures::convert( $picture ) );

		remove_all_filters( 'update_attached_file' );
		$this->assertSame( 'image/png', get_post_mime_type( $picture ) );
		$this->assertSame( $file, get_attached_file( $picture ) );
		$this->assertSame( $metadata, wp_get_attachment_metadata( $picture ) );
		foreach ( $this->files_of( $picture ) as $png ) {
			$this->assertFileExists( $png );
		}
		$this->assertSame( array(), glob( dirname( $file ) . '/pixonaut-kept-source-e*.webp' ) );
	}

	public function test_a_full_batch_keeps_its_last_picture_and_queues_the_next(): void {
		$pictures = array();
		for ( $index = 0; $index < SmallerPictures::BATCH; ++$index ) {
			$pictures[] = $this->kept_png( 'source-' . $index, 1, 1 );
		}
		SmallerPictures::ask();

		SmallerPictures::convert_all();

		$this->assertSame( (string) max( $pictures ), get_option( SmallerPictures::BACKFILL_OPTION ) );
		$this->assertTrue( as_has_scheduled_action( SmallerPictures::ALL_HOOK ) );
	}

	public function test_a_job_that_stopped_is_queued_again_from_the_last_picture_taken_up(): void {
		$taken   = $this->kept_png( 'source-f', 1, 1 );
		$waiting = $this->kept_png( 'source-g', 1, 1 );
		update_option( SmallerPictures::BACKFILL_OPTION, (string) $taken );

		SmallerPictures::queue_backfill();
		SmallerPictures::queue_backfill();

		$pending = array(
			'hook'   => SmallerPictures::ALL_HOOK,
			'status' => \ActionScheduler_Store::STATUS_PENDING,
		);
		$this->assertCount( 1, as_get_scheduled_actions( $pending, 'ids' ) );
		SmallerPictures::convert_all();
		$this->assertSame( 'image/png', get_post_mime_type( $taken ) );
		$this->assertSame( 'image/webp', get_post_mime_type( $waiting ) );
		$this->assertFalse( get_option( SmallerPictures::BACKFILL_OPTION ) );
	}

	public function test_the_upgrade_asks_for_every_picture_to_be_converted(): void {
		( new WriteSmallerPictures() )->up();

		$this->assertSame( '0', get_option( SmallerPictures::BACKFILL_OPTION ) );
		SmallerPictures::queue_backfill();
		$this->assertTrue( as_has_scheduled_action( SmallerPictures::ALL_HOOK ) );
		$this->assertContains( WriteSmallerPictures::class, array_map( 'get_class', Migrations::all() ) );
	}

	/**
	 * The PNG files beside the picture's file named after it.
	 *
	 * @param int $attachment A picture.
	 * @return list<string>
	 */
	private function png_files_beside( int $attachment ): array {
		$file  = (string) get_attached_file( $attachment );
		$found = glob( substr( $file, 0, -strlen( '.png' ) ) . '*.png' );
		return false === $found ? array() : $found;
	}

	/**
	 * A picture an earlier version kept as a PNG, with the sizes WordPress
	 * made of it, shown by the test's own image as its product.
	 *
	 * @param string $key    What it was drawn from.
	 * @param int    $width  Its width.
	 * @param int    $height Its height.
	 * @phpstan-param positive-int $width
	 * @phpstan-param positive-int $height
	 */
	private function kept_png( string $key, int $width = self::KEPT_WIDTH, int $height = self::KEPT_HEIGHT ): int {
		$image = imagecreatetruecolor( $width, $height );
		$this->assertNotFalse( $image );
		ob_start();
		imagepng( $image );
		$upload = wp_upload_bits( 'pixonaut-kept-' . $key . '.png', null, (string) ob_get_clean() );
		$this->assertFalse( $upload['error'] );
		$picture = wp_insert_attachment(
			array(
				'post_mime_type' => 'image/png',
				'post_title'     => 'Kept',
				'post_status'    => 'inherit',
			),
			$upload['file']
		);
		$this->assertGreaterThan( 0, $picture );
		require_once ABSPATH . 'wp-admin/includes/image.php';
		wp_update_attachment_metadata( $picture, wp_generate_attachment_metadata( $picture, $upload['file'] ) );
		add_post_meta( $picture, SharedPictures::PICTURE_OF_META, $this->own_image );
		update_post_meta( $picture, SharedPictures::SOURCE_META, $key );
		return $picture;
	}

	/**
	 * The attachment's file and the files of each of its sizes.
	 *
	 * @param int $attachment An image.
	 * @return list<string>
	 */
	private function files_of( int $attachment ): array {
		$file     = (string) get_attached_file( $attachment );
		$metadata = wp_get_attachment_metadata( $attachment );
		$sizes    = is_array( $metadata ) ? $metadata['sizes'] ?? array() : array();
		return array_values( array_unique( array_merge( array( $file ), array_map( static fn ( array $size ): string => dirname( $file ) . '/' . $size['file'], $sizes ) ) ) );
	}
}
