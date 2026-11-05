<?php
/**
 * @package Pixonaut\WooCommerce
 */

declare(strict_types=1);

namespace Pixonaut\WooCommerce\Tests\Integration\Admin;

use ActionScheduler_Action;
use ActionScheduler_Store;
use Pixonaut\WooCommerce\Admin\InvalidProductNotice;
use Pixonaut\WooCommerce\Admin\VariantNotice;
use Pixonaut\WooCommerce\Api\Catalogue;
use Pixonaut\WooCommerce\Catalogue\InvalidReason;
use Pixonaut\WooCommerce\Catalogue\ProductConfig;
use Pixonaut\WooCommerce\Catalogue\ProductRecheck;
use Pixonaut\WooCommerce\Migrations\ForgetTrashedProductNotices;
use Pixonaut\WooCommerce\Tests\Integration\Cart\PersonalisedCartTestCase;
use WC_Product;
use WC_Product_Simple;
use WC_Product_Variable;
use WC_Product_Variation;

/**
 * Products in the trash raise no notice and are not checked.
 */
final class TrashedProductsTest extends PersonalisedCartTestCase {

	/**
	 * An id no post has.
	 */
	private const GONE_PRODUCT_ID = 999999;

	public function set_up(): void {
		parent::set_up();
		// Upgrading queues one re-check of every product, which these tests do not expect.
		as_unschedule_all_actions( ProductRecheck::QUEUE_ACTION );
		delete_option( ProductRecheck::BACKFILL_OPTION );
	}

	public function test_trashing_a_product_drops_its_notices_and_its_variations(): void {
		$variable = new WC_Product_Variable();
		$variable->set_name( 'Personalised hoodie' );
		$variable->save();
		$variation = new WC_Product_Variation();
		$variation->set_parent_id( $variable->get_id() );
		$variation->save();
		$notices = $this->plugin->notices();
		foreach ( array( InvalidProductNotice::key( $variable->get_id() ), InvalidProductNotice::key( $variation->get_id() ), VariantNotice::key( $variable->get_id() ), InvalidProductNotice::key( $this->product_id ) ) as $key ) {
			$notices->add( $key, 'Customers cannot buy it.', 'error', false );
		}

		wp_trash_post( $variable->get_id() );

		$this->assertSame( array( InvalidProductNotice::key( $this->product_id ) ), array_keys( $notices->all() ) );
	}

	public function test_a_product_in_the_trash_raises_no_notice(): void {
		$product = $this->reload();
		wp_trash_post( $this->product_id );

		( new InvalidProductNotice( $this->plugin->notices() ) )->note( $product, self::config()->with_validity( false, InvalidReason::TEMPLATE_NOT_LIVE ) );

		$this->assertSame( array(), $this->plugin->notices()->all() );
	}

	public function test_a_recheck_does_not_check_a_product_in_the_trash(): void {
		wp_trash_post( $this->product_id );
		$this->plugin->notices()->add( InvalidProductNotice::key( $this->product_id ), 'Customers cannot buy it.', 'error', false );
		$this->recorder->json(
			200,
			array(
				'items'    => array(),
				'total'    => 0,
				'page'     => 1,
				'pageSize' => Catalogue::LIST_PAGE_SIZE,
			)
		);

		$this->plugin->product_recheck()->recheck( array( $this->product_id ) );

		$this->assertCount( 1, $this->recorder->requests, 'only the live templates are listed' );
		$this->assertTrue( ProductConfig::stored_on( $this->reload() )->valid, 'kept as it was' );
		$this->assertSame( array(), $this->plugin->notices()->all() );
	}

	public function test_restoring_a_product_checks_it_again(): void {
		wp_trash_post( $this->product_id );
		as_unschedule_all_actions( ProductRecheck::ACTION );

		wp_untrash_post( $this->product_id );

		$queued = as_get_scheduled_actions(
			array(
				'hook'   => ProductRecheck::ACTION,
				'status' => ActionScheduler_Store::STATUS_PENDING,
			)
		);
		$this->assertContains(
			array(
				'product_ids' => array( $this->product_id ),
				'attempt'     => 1,
			),
			array_map( static fn ( ActionScheduler_Action $action ): array => $action->get_args(), $queued )
		);
	}

	public function test_upgrading_drops_the_notices_of_products_in_the_trash_or_gone(): void {
		$trashed = new WC_Product_Simple();
		$trashed->save();
		$notices = $this->plugin->notices();
		$notices->add( InvalidProductNotice::key( $this->product_id ), 'Customers cannot buy it.', 'error', false );
		$notices->add( InvalidProductNotice::key( $trashed->get_id() ), 'Customers cannot buy it.', 'error', false );
		$notices->add( VariantNotice::key( self::GONE_PRODUCT_ID ), 'Customers cannot buy all of it.', 'error', false );
		$notices->add( 'connection_paused', 'Paused.', 'error', false );
		// Trashed before trashing dropped its notices.
		remove_all_actions( 'trashed_post' );
		wp_trash_post( $trashed->get_id() );

		( new ForgetTrashedProductNotices() )->up();

		$this->assertSame( array( InvalidProductNotice::key( $this->product_id ), 'connection_paused' ), array_keys( $notices->all() ) );
	}

	private function reload(): WC_Product {
		$product = wc_get_product( $this->product_id );
		$this->assertInstanceOf( WC_Product::class, $product );
		return $product;
	}
}
