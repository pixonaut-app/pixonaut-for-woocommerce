<?php
/**
 * @package Pixonaut\WooCommerce
 */

declare(strict_types=1);

namespace Pixonaut\WooCommerce\Tests\Integration\Catalogue;

use Pixonaut\WooCommerce\Catalogue\ProductPictures;
use Pixonaut\WooCommerce\Catalogue\SharedPictures;
use Pixonaut\WooCommerce\Catalogue\UnfinishedPictures;
use Pixonaut\WooCommerce\Migrations\DeleteUnfinishedPictures;
use Pixonaut\WooCommerce\Migrations\Migrations;
use Pixonaut\WooCommerce\Tests\Support\ActionSchedulerRunner;
use Pixonaut\WooCommerce\Tests\Support\SlowImageEditor;
use WC_Product;
use WP_UnitTestCase;

/**
 * A picture's job stopped while WordPress made the image sizes leaves the
 * attachment it inserted, shown by no product; it is deleted with its
 * files once no job can still be saving it.
 */
final class UnfinishedPicturesTest extends WP_UnitTestCase {

	use PicturedProducts;

	/**
	 * Attachments inserted since `set_up()`.
	 *
	 * @var list<int>
	 */
	private array $inserted = array();

	public function set_up(): void {
		parent::set_up();
		$this->set_up_pictures();
		SlowImageEditor::use();
		add_action( 'add_attachment', array( $this, 'inserted' ) );
	}

	public function tear_down(): void {
		remove_action( 'add_attachment', array( $this, 'inserted' ) );
		SlowImageEditor::stop();
		set_time_limit( 0 );
		as_unschedule_all_actions( UnfinishedPictures::ALL_HOOK );
		delete_option( UnfinishedPictures::BACKFILL_OPTION );
		$this->tear_down_pictures();
		parent::tear_down();
	}

	/**
	 * @param mixed $attachment_id The attachment inserted.
	 */
	public function inserted( mixed $attachment_id ): void {
		$this->inserted[] = (int) $attachment_id;
	}

	public function test_a_job_stopped_while_the_image_sizes_are_made_leaves_no_picture(): void {
		$product = $this->product( self::config() );
		// Another drawing of it, which took over the lock, is saving its own picture.
		$replacement                  = $this->unfinished( $product, 0 );
		SlowImageEditor::$limit_fixed = true;
		$this->recorder->raw( 200, self::png(), 'image/png' );

		ActionSchedulerRunner::run_once( ProductPictures::ACTION );

		$this->assertCount( 1, ActionSchedulerRunner::failed( ProductPictures::ACTION ), 'The job stopped.' );
		$this->assertSame( $replacement, $this->inserted[0] );
		$this->assertCount( 2, $this->inserted );
		$this->assertNull( get_post( $this->inserted[1] ) );
		$this->assertNotNull( get_post( $replacement ), 'Only the stopped job\'s picture is deleted.' );
		wp_delete_attachment( $replacement, true );
		$this->assertSame( array(), glob( wp_upload_dir()['path'] . '/pixonaut-' . $product->get_id() . '-*' ) );
		$this->assertSame( $this->own_image, (int) $this->reload( $product )->get_image_id() );
	}

	public function test_the_next_drawing_of_the_product_deletes_a_picture_an_earlier_job_left(): void {
		$product = $this->product( self::config() );
		$left    = $this->unfinished( $product, ProductPictures::LOCK_SECONDS + 1 );
		$file    = (string) get_attached_file( $left );

		$drawn = $this->drawn( $product )['id'];

		$this->assertNull( get_post( $left ) );
		$this->assertFileDoesNotExist( $file );
		$this->assertNotNull( get_post( $drawn ) );
	}

	public function test_a_picture_inserted_within_a_drawings_time_is_kept(): void {
		$product = $this->product( self::config() );
		$saving  = $this->unfinished( $product, 0 );

		$this->drawn( $product );

		$this->assertNotNull( get_post( $saving ) );
	}

	public function test_the_upgrade_deletes_every_unfinished_picture_no_job_can_still_be_saving(): void {
		$product  = $this->product( self::config() );
		$finished = $this->drawn( $product )['id'];
		$left     = $this->unfinished( $product, ProductPictures::LOCK_SECONDS + 1 );
		$recent   = $this->unfinished( $product, 0 );
		$other    = $this->product( self::config() );
		$drawing  = $this->unfinished( $other, ProductPictures::LOCK_SECONDS + 1 );
		$shown    = $this->unfinished( $this->product( self::config() ), ProductPictures::LOCK_SECONDS + 1 );
		$shown_by = $this->product( self::config() );
		$lock     = ProductPictures::LOCK_OPTION_PREFIX . $other->get_id();
		$shown_by->set_image_id( $shown );
		$shown_by->save();
		add_option( $lock, (string) time(), '', false );

		( new DeleteUnfinishedPictures() )->up();
		UnfinishedPictures::queue_backfill();
		$this->assertTrue( as_has_scheduled_action( UnfinishedPictures::ALL_HOOK ) );
		as_unschedule_all_actions( UnfinishedPictures::ALL_HOOK );
		UnfinishedPictures::delete_all();

		$this->assertContains( DeleteUnfinishedPictures::class, array_map( 'get_class', Migrations::all() ) );
		$this->assertNull( get_post( $left ) );
		$this->assertNotNull( get_post( $finished ) );
		$this->assertNotNull( get_post( $recent ), 'A job may still be saving it.' );
		$this->assertNotNull( get_post( $drawing ), 'Its product is being drawn.' );
		$this->assertNotNull( get_post( $shown ), 'A product shows it.' );
		$looks_again = ActionSchedulerRunner::due_at( UnfinishedPictures::ALL_HOOK );
		$this->assertCount( 1, $looks_again, 'The pictures passed over are looked at again.' );
		$this->assertGreaterThanOrEqual( time() + ProductPictures::LOCK_SECONDS - MINUTE_IN_SECONDS, $looks_again[0] );

		// Once no job can still be saving them.
		delete_option( $lock );
		$this->age( $recent, ProductPictures::LOCK_SECONDS + 1 );
		UnfinishedPictures::delete_all();

		$this->assertNull( get_post( $recent ) );
		$this->assertNull( get_post( $drawing ) );
		$this->assertNotNull( get_post( $shown ) );
		$this->assertFalse( get_option( UnfinishedPictures::BACKFILL_OPTION ) );
	}

	public function test_a_full_batch_queues_the_next_from_the_last_picture_taken_up(): void {
		$product = $this->product( self::config() );
		$left    = array();
		for ( $index = 0; $index < UnfinishedPictures::BATCH; ++$index ) {
			$left[] = $this->unfinished( $product, ProductPictures::LOCK_SECONDS + 1 );
		}
		UnfinishedPictures::ask();

		UnfinishedPictures::delete_all();

		$this->assertSame(
			array(
				'after'    => max( $left ),
				'deferred' => false,
			),
			get_option( UnfinishedPictures::BACKFILL_OPTION )
		);
		$this->assertTrue( as_has_scheduled_action( UnfinishedPictures::ALL_HOOK ) );
		UnfinishedPictures::delete_all();
		$this->assertFalse( get_option( UnfinishedPictures::BACKFILL_OPTION ) );
	}

	/**
	 * A picture saved as far as a job stopped during
	 * `wp_generate_attachment_metadata()` leaves it: its file, naming the
	 * product, no source recorded.
	 *
	 * @param WC_Product $product A product or variation.
	 * @param int        $ago     How long ago it was inserted, in seconds.
	 *
	 * @return int The attachment.
	 */
	private function unfinished( WC_Product $product, int $ago ): int {
		$upload = wp_upload_bits( sprintf( 'pixonaut-%d-unfinished.png', $product->get_id() ), null, self::png() );
		$this->assertFalse( $upload['error'] );
		$attachment = wp_insert_attachment(
			array(
				'post_mime_type' => 'image/png',
				'post_title'     => $product->get_name(),
				'post_status'    => 'inherit',
			),
			$upload['file'],
			$product->get_id()
		);
		$this->assertGreaterThan( 0, $attachment );
		add_post_meta( $attachment, SharedPictures::PICTURE_OF_META, $product->get_id() );
		$this->age( $attachment, $ago );
		return $attachment;
	}

	/**
	 * Moves the attachment's insertion back in time.
	 *
	 * @param int $attachment An attachment.
	 * @param int $ago        How long ago it was inserted, in seconds.
	 */
	private function age( int $attachment, int $ago ): void {
		global $wpdb;
		$inserted = gmdate( 'Y-m-d H:i:s', time() - $ago );
		$wpdb->update(
			$wpdb->posts,
			array(
				'post_date'     => $inserted,
				'post_date_gmt' => $inserted,
			),
			array( 'ID' => $attachment )
		);
		clean_post_cache( $attachment );
	}
}
