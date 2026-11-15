<?php
/**
 * @package Pixonaut\WooCommerce
 */

declare(strict_types=1);

namespace Pixonaut\WooCommerce\Tests\Integration\Migrations;

use Pixonaut\WooCommerce\Catalogue\SplitCards;
use Pixonaut\WooCommerce\Migrations\Migrations;
use Pixonaut\WooCommerce\Migrations\WriteCombinationCards;
use Pixonaut\WooCommerce\Migrations\WriteSplitCards;
use WP_UnitTestCase;

/**
 * Upgrading asks for every variable product's split cards to be written.
 * The cards it writes are tested with the shop's listings.
 */
final class WriteSplitCardsTest extends WP_UnitTestCase {

	public function tear_down(): void {
		delete_option( SplitCards::BACKFILL_OPTION );
		as_unschedule_all_actions( SplitCards::ALL_HOOK );
		parent::tear_down();
	}

	public function test_the_job_is_asked_for_until_it_is_queued(): void {
		( new WriteSplitCards() )->up();

		$this->assertSame( SplitCards::BACKFILL_PENDING, get_option( SplitCards::BACKFILL_OPTION ) );
		SplitCards::queue_backfill();
		$this->assertFalse( get_option( SplitCards::BACKFILL_OPTION ) );
		$this->assertTrue( as_has_scheduled_action( SplitCards::ALL_HOOK, array( 0 ) ) );
	}

	public function test_the_cards_are_asked_for_again_for_the_products_listed_by_several_attributes(): void {
		( new WriteCombinationCards() )->up();

		$this->assertSame( SplitCards::BACKFILL_PENDING, get_option( SplitCards::BACKFILL_OPTION ) );
	}

	public function test_both_are_migrations_in_order(): void {
		$classes = array_map( 'get_class', Migrations::all() );

		$this->assertSame( array( WriteSplitCards::class, WriteCombinationCards::class ), array_values( array_intersect( $classes, array( WriteSplitCards::class, WriteCombinationCards::class ) ) ) );
	}
}
