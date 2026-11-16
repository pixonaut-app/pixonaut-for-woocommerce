<?php
/**
 * @package Pixonaut\WooCommerce
 */

declare(strict_types=1);

namespace Pixonaut\WooCommerce\Tests\Integration\Catalogue;

use ActionScheduler;
use ActionScheduler_Store;
use Pixonaut\WooCommerce\Catalogue\PendingRechecks;
use Pixonaut\WooCommerce\Catalogue\ProductConfig;
use Pixonaut\WooCommerce\Catalogue\ProductRecheck;
use Pixonaut\WooCommerce\Catalogue\Side;
use Pixonaut\WooCommerce\Migrations\Migrations;
use Pixonaut\WooCommerce\Migrations\ReplaceQueuedRechecks;
use Pixonaut\WooCommerce\Tests\Integration\Cart\PersonalisedCartTestCase;
use Pixonaut\WooCommerce\Tests\Support\DesignFixture;
use WC_Product;
use WC_Product_Simple;

/**
 * Template and blank events arriving faster than the re-checks they queue
 * run leave each product waiting in one re-check.
 */
final class PendingRechecksTest extends PersonalisedCartTestCase {

	/**
	 * Products on the fixture's template, the one set up by the base class
	 * included: more than one batch.
	 */
	private const PRODUCTS = ProductRecheck::BATCH + 2;

	/**
	 * Product ids on the fixture's template, ascending.
	 *
	 * @var list<int>
	 */
	private array $products;

	public function set_up(): void {
		parent::set_up();
		$this->products = array( $this->product_id );
		for ( $i = 1; $i < self::PRODUCTS; $i++ ) {
			$this->products[] = $this->product();
		}
		// Upgrading and saving products queue re-checks these tests do not expect.
		as_unschedule_all_actions( ProductRecheck::QUEUE_ACTION );
		as_unschedule_all_actions( ProductRecheck::ACTION );
		delete_option( ProductRecheck::BACKFILL_OPTION );
	}

	public function test_several_events_for_the_same_products_queue_each_once(): void {
		$this->template_event();
		$this->template_event();
		$this->template_event();

		$this->assertSame( array_fill_keys( $this->products, 1 ), $this->times_waiting() );
		$this->assertCount( (int) ceil( self::PRODUCTS / ProductRecheck::BATCH ), $this->pending( ProductRecheck::ACTION ) );
	}

	public function test_events_arriving_before_the_products_are_paged_queue_one_paging(): void {
		$this->plugin->product_recheck()->recheck_all();
		$this->plugin->product_recheck()->recheck_all();

		$this->assertCount( 1, $this->pending( ProductRecheck::QUEUE_ACTION ) );
	}

	public function test_a_product_leaving_the_template_between_events_moves_no_product_into_a_second_batch(): void {
		$this->template_event();
		// Every batch after it now starts one product later.
		$moved = wc_get_product( $this->products[1] );
		$this->assertInstanceOf( WC_Product::class, $moved );
		$this->config_on( 'other-tee' )->save_to( $moved );
		$this->template_event();

		$this->assertSame( array_fill_keys( $this->products, 1 ), $this->times_waiting() );
	}

	public function test_a_product_added_between_two_events_is_rechecked_once(): void {
		$this->template_event();
		$added = $this->product();
		$this->template_event();
		$this->template_event();

		$this->assertSame( array_fill_keys( array( ...$this->products, $added ), 1 ), $this->times_waiting() );
		$this->assertSame( array( $added ), array_values( $this->pending( ProductRecheck::ACTION ) )[2]->get_args()['product_ids'] );
	}

	public function test_an_event_after_the_recheck_started_queues_the_product_again(): void {
		$this->template_event();
		$first = array_key_first( $this->pending( ProductRecheck::ACTION ) );
		// The runner marks the job started before it runs, which may be before it reads Pixonaut.
		ActionScheduler::store()->log_execution( $first );

		$this->template_event();

		$this->assertSame( array_fill_keys( $this->products, 1 ), $this->times_waiting() );
		$this->assertSame( ActionScheduler_Store::STATUS_RUNNING, ActionScheduler::store()->get_status( (string) $first ) );
	}

	public function test_a_recheck_soon_of_a_product_already_waiting_queues_nothing_and_its_own_counts_for_events(): void {
		$this->template_event();
		$this->assertTrue( ProductRecheck::queue_soon( array( $this->product_id ) ) );
		$this->assertSame( array_fill_keys( $this->products, 1 ), $this->times_waiting() );

		as_unschedule_all_actions( ProductRecheck::ACTION );
		$this->assertTrue( ProductRecheck::queue_soon( array( $this->product_id ) ) );
		$this->template_event();

		$this->assertSame( array_fill_keys( $this->products, 1 ), $this->times_waiting() );
	}

	public function test_a_product_copied_with_the_mark_of_its_original_is_queued(): void {
		$this->template_event();
		$copy = $this->product();
		update_post_meta( $copy, PendingRechecks::META, get_post_meta( $this->product_id, PendingRechecks::META, true ) );

		$this->template_event();

		$this->assertSame( 1, $this->times_waiting()[ $copy ] ?? 0 );
	}

	public function test_the_upgrade_replaces_the_rechecks_queued_before_with_one_of_every_product(): void {
		// What the batches of earlier versions left: nearly the same products, cut at other places, and a retry.
		foreach ( array( array_slice( $this->products, 0, ProductRecheck::BATCH ), array_slice( $this->products, 1, ProductRecheck::BATCH ), array_slice( $this->products, ProductRecheck::BATCH ) ) as $batch ) {
			as_enqueue_async_action(
				ProductRecheck::ACTION,
				array(
					'product_ids' => $batch,
					'attempt'     => 1,
				),
				ProductRecheck::GROUP
			);
		}
		as_schedule_single_action(
			time() + ProductRecheck::RETRY_BASE_SECONDS,
			ProductRecheck::ACTION,
			array(
				'product_ids' => array( $this->product_id ),
				'attempt'     => 2,
			),
			ProductRecheck::GROUP
		);
		as_enqueue_async_action(
			ProductRecheck::QUEUE_ACTION,
			array(
				'by'    => ProductRecheck::BY_TEMPLATE,
				'key'   => DesignFixture::TEMPLATE_BASE_ID,
				'after' => $this->products[ ProductRecheck::BATCH - 1 ],
			),
			ProductRecheck::GROUP
		);

		( new ReplaceQueuedRechecks() )->up();
		$this->plugin->product_recheck()->queue_backfill();

		$this->assertSame( array(), $this->pending( ProductRecheck::ACTION ) );
		$queued = array_values( $this->pending( ProductRecheck::QUEUE_ACTION ) );
		$this->assertCount( 1, $queued );
		$this->assertSame( ProductRecheck::BY_ANY, $queued[0]->get_args()['by'] );
		$this->assertFalse( get_option( ProductRecheck::BACKFILL_OPTION ) );

		$this->plugin->product_recheck()->queue_page( ProductRecheck::BY_ANY, '', 0 );

		$this->assertSame( array_fill_keys( $this->products, 1 ), $this->times_waiting() );
		$this->assertContains( ReplaceQueuedRechecks::class, array_map( 'get_class', Migrations::all() ) );
	}

	/**
	 * Runs what a template event queues, without running the re-checks.
	 */
	private function template_event(): void {
		$this->plugin->product_recheck()->queue_page( ProductRecheck::BY_TEMPLATE, DesignFixture::TEMPLATE_BASE_ID, 0 );
	}

	/**
	 * How many pending re-checks name each product.
	 *
	 * @return array<int, int> By product id, ascending.
	 */
	private function times_waiting(): array {
		$times = array();
		foreach ( $this->pending( ProductRecheck::ACTION ) as $job ) {
			foreach ( $job->get_args()['product_ids'] as $product_id ) {
				$times[ $product_id ] = ( $times[ $product_id ] ?? 0 ) + 1;
			}
		}
		ksort( $times );
		return $times;
	}

	/**
	 * Another personalised product on the fixture's template.
	 */
	private function product(): int {
		$product = new WC_Product_Simple();
		$product->set_name( 'Another tee' );
		$product->set_regular_price( '20' );
		$product->save();
		self::config()->save_to( $product );
		return $product->get_id();
	}

	/**
	 * The fixture's configuration, its side on another template.
	 *
	 * @param string $template Template base id.
	 */
	private function config_on( string $template ): ProductConfig {
		$config = self::config();
		$side   = $config->sides[0];
		return new ProductConfig(
			personalise: true,
			blank_id: $config->blank_id,
			sides: array( new Side( $side->key, $side->label, $side->placement_id, $side->placement_name, $template, $side->template_version, null, null, $side->print_sizes ) ),
		);
	}

	/**
	 * The jobs of a hook waiting to run, by id, ascending.
	 *
	 * @param string $hook The jobs' hook.
	 * @return array<int, \ActionScheduler_Action>
	 */
	private function pending( string $hook ): array {
		return as_get_scheduled_actions(
			array(
				'hook'     => $hook,
				'group'    => ProductRecheck::GROUP,
				'status'   => ActionScheduler_Store::STATUS_PENDING,
				'per_page' => -1,
				'orderby'  => 'action_id',
				'order'    => 'ASC',
			)
		);
	}
}
