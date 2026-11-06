<?php
/**
 * @package Pixonaut\WooCommerce
 */

declare(strict_types=1);

namespace Pixonaut\WooCommerce\Tests\Integration\Catalogue;

use Pixonaut\WooCommerce\Api\Pictures;
use Pixonaut\WooCommerce\Api\WcLogger;
use Pixonaut\WooCommerce\Catalogue\PictureQueue;
use Pixonaut\WooCommerce\Catalogue\ProductPictures;
use Pixonaut\WooCommerce\Catalogue\SharedPictures;
use Pixonaut\WooCommerce\Tests\Support\ActionSchedulerRunner;
use WC_Product;
use WP_UnitTestCase;

/**
 * Variations drawn from the same source share one picture, kept while any
 * of them shows it.
 */
final class SharedPicturesTest extends WP_UnitTestCase {

	use PicturedProducts;

	public function set_up(): void {
		parent::set_up();
		$this->set_up_pictures();
	}

	public function tear_down(): void {
		$this->tear_down_pictures();
		parent::tear_down();
	}

	public function test_variations_drawn_from_the_same_source_share_one_drawing_and_one_attachment(): void {
		$product = $this->sign();

		$this->draw_all();

		$this->assertCount( 1, $this->recorder->requests );
		$pictures = $this->pictures( $product );
		$this->assertCount( 1, array_unique( $pictures ) );
		$picture = $pictures[0];
		$this->assertNotSame( 0, $picture );
		$this->assertCount( count( self::sign_sizes() ) + 1, SharedPictures::owners( $picture ) );
		$this->assertCount( 1, $this->plugin_pictures() );
		foreach ( $this->family( $product ) as $member ) {
			$this->assertSame( $picture, (int) get_post_thumbnail_id( $member ) );
		}
	}

	public function test_regenerating_draws_the_shared_picture_once_and_deletes_the_one_it_replaces(): void {
		$product = $this->sign();
		$this->draw_all();
		$before = $this->pictures( $product )[0];

		ProductPictures::regenerate( $this->reload( $product ) );
		( new PictureQueue( $this->plugin->settings() ) )->queue_variations_now( $product->get_id() );
		$this->draw_all();

		$this->assertCount( 2, $this->recorder->requests );
		$after = $this->pictures( $product );
		$this->assertCount( 1, array_unique( $after ) );
		$this->assertNotSame( $before, $after[0] );
		$this->assertNull( get_post( $before ) );
		$this->assertSame( array( $after[0] ), $this->plugin_pictures() );
	}

	public function test_deleting_a_variation_keeps_the_shared_picture_until_the_last_one_showing_it_goes(): void {
		$product = $this->sign();
		$this->draw_all();
		$picture  = $this->pictures( $product )[0];
		$children = $this->reload( $product )->get_children();

		wp_delete_post( $children[0], true );

		$this->assertNotNull( get_post( $picture ) );
		$this->assertNotContains( $children[0], SharedPictures::owners( $picture ) );

		wp_delete_post( $children[1], true );
		wp_delete_post( $children[2], true );
		$this->assertNotNull( get_post( $picture ), 'The product still shows it.' );

		wp_delete_post( $product->get_id(), true );
		$this->assertNull( get_post( $picture ) );
	}

	public function test_deleting_the_shared_picture_from_the_media_library_queues_each_product_showing_it(): void {
		$product = $this->sign();
		$this->draw_all();
		$picture = $this->pictures( $product )[0];

		wp_delete_attachment( $picture, true );

		$queued = array_map(
			static fn ( $action ): int => (int) $action->get_args()[0],
			as_get_scheduled_actions(
				array(
					'hook'   => ProductPictures::ACTION,
					'status' => \ActionScheduler_Store::STATUS_PENDING,
				)
			)
		);
		sort( $queued );
		$this->assertSame( $this->family( $product ), $queued );
	}

	public function test_a_source_another_job_draws_is_waited_for_and_then_shared(): void {
		$product  = $this->sign();
		$children = $this->reload( $product )->get_children();
		$this->recorder->raw( 200, self::png(), 'image/png' );
		ActionSchedulerRunner::run_pending( PictureQueue::VARIATIONS_ACTION );
		do_action( ProductPictures::ACTION, $children[0] );
		$picture = (int) get_post_thumbnail_id( $children[0] );
		$key     = (string) get_post_meta( $picture, SharedPictures::SOURCE_META, true );
		// Another job holds the source as if still drawing it; the picture was saved meanwhile.
		add_option( SharedPictures::lock_option( $this->reload( $product ), $key ), (string) time(), '', false );
		delete_post_meta( $picture, SharedPictures::PICTURE_OF_META );
		$slept = 0;
		$jobs  = new ProductPictures(
			new Pictures( $this->plugin->pixonaut() ),
			$this->plugin->catalogue(),
			$this->plugin->catalogue_cache(),
			new WcLogger(),
			$this->plugin->settings(),
			null,
			static function () use ( &$slept, $picture, $children ): void {
				++$slept;
				add_post_meta( $picture, SharedPictures::PICTURE_OF_META, $children[0] );
			}
		);

		$jobs->refresh( $children[1] );

		$this->assertSame( 1, $slept );
		$this->assertCount( 1, $this->recorder->requests );
		$this->assertSame( $picture, (int) get_post_thumbnail_id( $children[1] ) );
	}

	public function test_a_source_another_family_draws_is_not_waited_for(): void {
		$product = $this->sign();
		$other   = $this->sign();
		$key     = md5( 'the same template' );
		add_option( SharedPictures::lock_option( $other, $key ), (string) time(), '', false );
		$slept = 0;

		[ $found, $claim ] = SharedPictures::claim(
			$this->reload( $product ),
			$key,
			static fn (): int => time(),
			static function () use ( &$slept ): void {
				++$slept;
			},
			ProductPictures::LOCK_SECONDS
		);

		$this->assertSame( 0, $slept );
		$this->assertSame( 0, $found );
		$this->assertNotNull( $claim );
		SharedPictures::unclaim( $this->reload( $product ), $key, (string) $claim );
		$this->assertFalse( get_option( SharedPictures::lock_option( $product, $key ) ) );
	}

	public function test_a_picture_deleted_before_it_is_shared_is_not_taken(): void {
		$product = $this->sign();
		$this->draw_all();
		$picture  = $this->pictures( $product )[0];
		$children = $this->reload( $product )->get_children();
		$taker    = wc_get_product( $children[0] );
		$this->assertInstanceOf( WC_Product::class, $taker );
		delete_post_meta( $picture, SharedPictures::PICTURE_OF_META, (string) $taker->get_id() );

		wp_delete_attachment( $picture, true );

		$this->assertFalse( SharedPictures::share( $picture, $taker ) );
		$this->assertSame( array(), get_post_meta( $picture, SharedPictures::PICTURE_OF_META, false ) );
	}

	public function test_a_deleted_picture_its_product_still_names_is_not_its_picture(): void {
		$product = $this->sign();
		$this->draw_all();
		$picture = $this->pictures( $product )[0];
		$owner   = $this->reload( $product );

		wp_delete_attachment( $picture, true );
		// The row a product adds as the attachment goes, as if taking it meanwhile.
		add_post_meta( $picture, SharedPictures::PICTURE_OF_META, $owner->get_id() );

		$this->assertFalse( SharedPictures::is_picture( $picture, $owner ) );
	}

	/**
	 * Runs every drawing queued, the variations' too.
	 */
	private function draw_all(): void {
		$this->recorder->raw( 200, self::png(), 'image/png' );
		ActionSchedulerRunner::run_pending( PictureQueue::VARIATIONS_ACTION );
		ActionSchedulerRunner::run_pending( ProductPictures::ACTION );
	}

	/**
	 * The pictures the product and its variations show.
	 *
	 * @param WC_Product $product The variable product.
	 * @return list<int>
	 */
	private function pictures( WC_Product $product ): array {
		return array_map( static fn ( int $id ): int => (int) get_post_thumbnail_id( $id ), $this->family( $product ) );
	}

	/**
	 * The product and its variations, by id.
	 *
	 * @param WC_Product $product The variable product.
	 * @return list<int>
	 */
	private function family( WC_Product $product ): array {
		$ids = array_merge( array( $product->get_id() ), array_map( 'intval', $this->reload( $product )->get_children() ) );
		sort( $ids );
		return $ids;
	}

	/**
	 * Every Pixonaut picture in the media library.
	 *
	 * @return list<int>
	 */
	private function plugin_pictures(): array {
		return array_values(
			array_map(
				'intval',
				get_posts(
					array(
						'post_type'      => 'attachment',
						'post_status'    => 'any',
						'fields'         => 'ids',
						'posts_per_page' => -1,
						'meta_key'       => SharedPictures::PICTURE_OF_META, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- a test.
					)
				)
			)
		);
	}
}
