<?php
/**
 * @package Pixonaut\WooCommerce
 */

declare(strict_types=1);

namespace Pixonaut\WooCommerce\Tests\Integration\Storefront;

use Pixonaut\WooCommerce\Catalogue\ArchiveIndexes;
use Pixonaut\WooCommerce\Catalogue\SplitListing;
use Pixonaut\WooCommerce\Schema;
use Pixonaut\WooCommerce\Storefront\ArchiveIndexDiscovery;
use Pixonaut\WooCommerce\Tests\Support\ActionSchedulerRunner;
use Pixonaut\WooCommerce\Tests\Support\SplitPosters;
use WC_Product;
use WC_Product_Simple;
use WP_Term;
use WP_UnitTestCase;

/**
 * A theme asks how many cards a category's archive lists, for a count it
 * prints elsewhere, such as the category's tile: the archive's index
 * answers, so a product listed once per picture counts once per card, as
 * the archive shows it.
 */
final class ArchiveCardCountTest extends WP_UnitTestCase {

	use SplitPosters;

	private const PICTURE = 'Bild';

	private const SIZE = 'Storlek';

	/**
	 * The count a theme passes in: WooCommerce's, a product each.
	 */
	private const PRODUCTS = 2;

	public function set_up(): void {
		parent::set_up();
		$this->set_up_posters();
		as_unschedule_all_actions( ArchiveIndexes::ACTION );
		add_theme_support( ArchiveIndexes::THEME_SUPPORT );
	}

	public function tear_down(): void {
		remove_theme_support( ArchiveIndexes::THEME_SUPPORT );
		as_unschedule_all_actions( ArchiveIndexes::ACTION );
		delete_option( Schema::ARCHIVE_INDEXES_OPTION );
		delete_option( Schema::ARCHIVE_INDEX_VISITS_OPTION );
		delete_option( Schema::ARCHIVE_INDEX_BODY_PREFIX . 'product_cat-' . $this->category );
		$this->tear_down_posters();
		parent::tear_down();
	}

	public function test_a_category_counts_the_cards_its_archive_lists(): void {
		SplitListing::set_attribute_mode( $this->attributes[ self::PICTURE ], SplitListing::ON );
		$this->poster();
		$this->simple( 'Uggla' );

		$this->assertSame( self::PRODUCTS, $this->card_count(), 'the count given until the index is built' );
		$this->assertTrue( as_has_scheduled_action( ArchiveIndexes::ACTION ), 'asking queued the build' );

		ActionSchedulerRunner::run_pending( ArchiveIndexes::ACTION );

		$this->assertSame( 3, $this->card_count(), 'the poster once per picture, and the mug' );
		$this->assertSame( $this->listed(), $this->card_count(), 'as many as the archive lists' );
	}

	public function test_without_the_themes_support_the_count_given_stands(): void {
		remove_theme_support( ArchiveIndexes::THEME_SUPPORT );
		SplitListing::set_attribute_mode( $this->attributes[ self::PICTURE ], SplitListing::ON );
		$this->poster();

		$this->assertSame( self::PRODUCTS, $this->card_count() );
		$this->assertFalse( as_has_scheduled_action( ArchiveIndexes::ACTION ) );
	}

	public function test_a_term_without_an_archive_index_keeps_the_count_given(): void {
		$term = get_term( $this->term( 'med-bild' ) );
		$this->assertInstanceOf( WP_Term::class, $term );

		$this->assertSame( self::PRODUCTS, apply_filters( ArchiveIndexDiscovery::CARD_COUNT_FILTER, self::PRODUCTS, $term ) );
		$this->assertSame( self::PRODUCTS, apply_filters( ArchiveIndexDiscovery::CARD_COUNT_FILTER, self::PRODUCTS, null ) );
	}

	/**
	 * What the filter answers for the category, given WooCommerce's count.
	 */
	private function card_count(): mixed {
		$category = get_term( $this->category, 'product_cat' );
		$this->assertInstanceOf( WP_Term::class, $category );
		return apply_filters( ArchiveIndexDiscovery::CARD_COUNT_FILTER, self::PRODUCTS, $category );
	}

	/**
	 * How many cards the category's archive lists.
	 */
	private function listed(): int {
		$page = get_term_link( $this->category, 'product_cat' );
		$this->assertIsString( $page );
		$this->go_to( $page );
		global $wp_query;
		return (int) $wp_query->found_posts;
	}

	private function simple( string $name ): WC_Product {
		$product = new WC_Product_Simple();
		$product->set_name( $name );
		$product->set_status( 'publish' );
		$product->set_category_ids( array( $this->category ) );
		$product->set_regular_price( '5' );
		$product->save();
		return $product;
	}
}
