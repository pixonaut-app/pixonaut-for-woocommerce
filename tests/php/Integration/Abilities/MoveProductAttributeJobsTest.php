<?php
/**
 * @package Pixonaut\WooCommerce
 */

declare(strict_types=1);

namespace Pixonaut\WooCommerce\Tests\Integration\Abilities;

use Pixonaut\WooCommerce\Abilities\Abilities;
use Pixonaut\WooCommerce\Abilities\MoveProductAttribute;
use Pixonaut\WooCommerce\Catalogue\FormGroups;
use Pixonaut\WooCommerce\Catalogue\PictureQueue;
use Pixonaut\WooCommerce\Catalogue\PrintSize;
use Pixonaut\WooCommerce\Catalogue\ProductConfig;
use Pixonaut\WooCommerce\Catalogue\ProductPictures;
use Pixonaut\WooCommerce\Catalogue\ProductRecheck;
use Pixonaut\WooCommerce\Catalogue\Side;
use Pixonaut\WooCommerce\Tests\Integration\Catalogue\PicturedProducts;
use Pixonaut\WooCommerce\Tests\Support\ActionSchedulerRunner;
use Pixonaut\WooCommerce\Tests\Support\DesignFixture;
use RuntimeException;
use WC_Post_Data;
use WC_Product;
use WP_Ability;
use WP_UnitTestCase;

/**
 * What a move leaves behind it, once the request's deferred work has run: a
 * move draws nothing and asks Pixonaut nothing.
 */
final class MoveProductAttributeJobsTest extends WP_UnitTestCase {

	use PicturedProducts;

	private const CUSTOM = 'Size';

	private const CUSTOM_SLUG = 'size';

	private const TAXONOMY = 'pa_size';

	public function set_up(): void {
		parent::set_up();
		$this->set_up_pictures();
		wc_create_attribute(
			array(
				'name' => self::CUSTOM,
				'slug' => self::CUSTOM_SLUG,
			)
		);
		register_taxonomy( self::TAXONOMY, array( 'product', 'product_variation' ), array( 'update_count_callback' => '_update_post_term_count' ) );
		foreach ( self::sign_sizes() as $size ) {
			wp_insert_term( $size, self::TAXONOMY );
		}
		$manager = self::factory()->user->create( array( 'role' => 'shop_manager' ) );
		wp_set_current_user( is_int( $manager ) ? $manager : 0 );
		$GLOBALS['wc_deferred_product_sync'] = array();
	}

	public function tear_down(): void {
		$GLOBALS['wc_deferred_product_sync'] = array();
		unregister_taxonomy( self::TAXONOMY );
		delete_transient( 'wc_attribute_taxonomies' );
		$this->tear_down_pictures();
		parent::tear_down();
	}

	public function test_a_product_whose_pictures_are_drawn_gets_no_job_and_stays_drawn_after_a_later_save(): void {
		$product = $this->drawn_sign();
		$asked   = count( $this->recorder->requests );

		$this->assert_moved( $product );
		$this->end_of_request();

		$this->assertSame( array(), ActionSchedulerRunner::pending( PictureQueue::VARIATIONS_ACTION ), 'The variations are not checked.' );
		$this->assertSame( array(), ActionSchedulerRunner::pending( ProductPictures::ACTION ), 'Nothing is drawn.' );
		$queue = new PictureQueue( $this->plugin->settings() );
		foreach ( $this->family( $product ) as $id ) {
			$this->assertFalse( $queue->is_due( PictureQueue::fresh( $id ) ?? $product ), "Picture of #{$id} stays current." );
		}

		$this->reload( $product )->save();
		ActionSchedulerRunner::run_pending( PictureQueue::VARIATIONS_ACTION );

		$this->assertSame( array(), ActionSchedulerRunner::pending( ProductPictures::ACTION ), 'A later save draws nothing either.' );
		$this->assertCount( $asked, $this->recorder->requests, 'Pixonaut is not asked.' );
	}

	public function test_a_picture_due_for_another_reason_is_not_tried_by_the_move(): void {
		$product   = $this->drawn_sign();
		$variation = $this->family( $product )[1];
		$record    = json_decode( (string) get_post_meta( $variation, ProductPictures::META_KEY, true ), true );
		$this->assertIsArray( $record );
		$record['failed_at'] = time() - ProductPictures::RETRY_SECONDS - MINUTE_IN_SECONDS;
		update_post_meta( $variation, ProductPictures::META_KEY, (string) wp_json_encode( $record ) );

		$this->assert_moved( $product );
		$this->end_of_request();

		$this->assertSame( array(), ActionSchedulerRunner::pending( ProductPictures::ACTION ) );
	}

	public function test_the_parent_sync_after_a_move_queues_no_recheck_of_a_stale_variation(): void {
		$product = $this->sign();
		self::config()->save_to( $product );
		$this->end_of_request();
		as_unschedule_all_actions( ProductRecheck::ACTION );

		$this->assert_moved( $product );
		$this->end_of_request();

		$this->assertSame( array(), ActionSchedulerRunner::pending( ProductRecheck::ACTION ), 'Pixonaut is not asked again.' );
	}

	public function test_a_move_leaves_no_parent_sync_for_the_end_of_the_request_and_prices_the_parent(): void {
		$product = $this->sign();
		$this->end_of_request();

		$this->assert_moved( $product );

		$this->assertNotContains( $product->get_id(), $GLOBALS['wc_deferred_product_sync'] ?? array() );
		$this->assertSame( array( '20' ), array_values( array_unique( get_post_meta( $product->get_id(), '_price', false ) ) ) );
	}

	public function test_a_move_that_fails_leaves_no_job_and_no_parent_sync(): void {
		$product = $this->drawn_sign();
		$break   = static function (): void {
			throw new RuntimeException( 'The save failed.' );
		};
		add_action( 'woocommerce_update_product_variation', $break );

		$answer = $this->move( $product );
		remove_action( 'woocommerce_update_product_variation', $break );

		$this->assertSame( MoveProductAttribute::FAILED, self::status( $answer ) );
		$this->assertNotContains( $product->get_id(), $GLOBALS['wc_deferred_product_sync'] ?? array() );
		$this->assertSame( array(), ActionSchedulerRunner::pending( PictureQueue::VARIATIONS_ACTION ) );
		$this->assertSame( array(), ActionSchedulerRunner::pending( ProductPictures::ACTION ) );
	}

	/**
	 * A sign arranged by the attribute the move renames, its pictures drawn
	 * and its earlier saves' work done.
	 */
	private function drawn_sign(): WC_Product {
		$product = $this->sign();
		$groups  = FormGroups::from_array(
			array(
				'groups' => array(
					array(
						'id'    => 'wc-size',
						'title' => 'Size',
						'items' => array( array( 'attribute' => self::CUSTOM_SLUG ) ),
					),
				),
			)
		);
		$sizes   = array( new PrintSize( DesignFixture::WIDTH_MM, DesignFixture::HEIGHT_MM, 'Large' ) );
		( new ProductConfig(
			personalise: true,
			sides: array( new Side( 'front', 'Front', null, null, DesignFixture::TEMPLATE_BASE_ID, DesignFixture::LIVE_VERSION, null, 'Mini tee', $sizes, form_groups: $groups ) )
		) )->save_to( $this->reload( $product ) );
		$this->end_of_request();
		$this->recorder->raw( 200, self::png(), 'image/png' );
		ActionSchedulerRunner::run_pending( PictureQueue::VARIATIONS_ACTION );
		ActionSchedulerRunner::run_pending( ProductPictures::ACTION );
		// Showing the product's picture changes it, which checks its variations once more.
		ActionSchedulerRunner::run_pending( PictureQueue::VARIATIONS_ACTION );
		$this->assertSame( array(), ActionSchedulerRunner::pending( PictureQueue::VARIATIONS_ACTION ) );
		$this->assertSame( array(), ActionSchedulerRunner::pending( ProductPictures::ACTION ) );
		$this->assertNotSame( array(), $this->recorder->requests, 'The pictures were drawn.' );
		return $this->reload( $product );
	}

	/**
	 * The product and its variations.
	 *
	 * @param WC_Product $product The variable product.
	 * @return array<int>
	 */
	private function family( WC_Product $product ): array {
		return array_merge( array( $product->get_id() ), array_map( 'intval', $this->reload( $product )->get_children() ) );
	}

	/**
	 * What WooCommerce runs at shutdown, which a test never reaches.
	 */
	private function end_of_request(): void {
		WC_Post_Data::do_deferred_product_sync();
		// WooCommerce 10.6 leaves the list full, for a request that ends here.
		$GLOBALS['wc_deferred_product_sync'] = array();
	}

	private function assert_moved( WC_Product $product ): void {
		$this->assertSame( MoveProductAttribute::MOVED, self::status( $this->move( $product ) ) );
	}

	/**
	 * @param array<mixed> $answer The ability's answer.
	 */
	private static function status( array $answer ): mixed {
		$products = $answer['products'] ?? null;
		$row      = is_array( $products ) ? ( $products[0] ?? null ) : null;
		return is_array( $row ) ? ( $row['status'] ?? null ) : null;
	}

	/**
	 * @param WC_Product $product The product to move.
	 * @return array<mixed>
	 */
	private function move( WC_Product $product ): array {
		$ability = wp_get_ability( Abilities::NAMESPACE . '/move-product-attribute' );
		$this->assertInstanceOf( WP_Ability::class, $ability );
		$answer = $ability->execute(
			array(
				'product_ids' => array( $product->get_id() ),
				'from'        => self::CUSTOM,
				'to'          => self::TAXONOMY,
			)
		);
		$this->assertIsArray( $answer );
		return $answer;
	}
}
