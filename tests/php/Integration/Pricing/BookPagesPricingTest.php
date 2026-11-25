<?php
/**
 * @package Pixonaut\WooCommerce
 */

declare(strict_types=1);

namespace Pixonaut\WooCommerce\Tests\Integration\Pricing;

use Pixonaut\WooCommerce\Abilities\ProductPrices;
use Pixonaut\WooCommerce\Admin\PricingPanel;
use Pixonaut\WooCommerce\Catalogue\BlankOptionLinks;
use Pixonaut\WooCommerce\Catalogue\ProductConfig;
use Pixonaut\WooCommerce\Catalogue\ProductVariant;
use Pixonaut\WooCommerce\Pricing\PriceState;
use Pixonaut\WooCommerce\Pricing\PriceTax;
use Pixonaut\WooCommerce\Pricing\ProductPricing;
use WC_Product_Attribute;
use WC_Product_Variable;
use WC_Product_Variation;

/**
 * A notebook sold at the page count of a global attribute standing for it:
 * each variation is priced by the cost at its count.
 */
final class BookPagesPricingTest extends PricingTestCase {

	private const TAXONOMY = 'pa_sidor';

	private const NOTEBOOK = 'v_a5_90';

	/**
	 * The store's page counts, by slug: each value's name.
	 */
	private const COUNTS = array(
		'20-sidor' => '20 sidor',
		'44-sidor' => '44 sidor',
		'60-sidor' => '60 sidor',
	);

	/**
	 * What the printshop charges at each count the placement offers, as
	 * the answer writes it; null at a count it gives no price for.
	 */
	private const COSTS_BY_PAGES = array(
		20 => null,
		44 => '12.52',
		60 => '15.10',
	);

	/** The variant's own cost: the one at its fewest priced pages. */
	private const FEWEST_PRICED = '12.52';

	private const SHIPPING = '7.45';

	private int $attribute = 0;

	/**
	 * Term ids by slug.
	 *
	 * @var array<string, int>
	 */
	private array $terms = array();

	public function set_up(): void {
		parent::set_up();
		$attribute = wc_create_attribute(
			array(
				'name' => 'Sidor',
				'slug' => 'sidor',
			)
		);
		$this->assertIsInt( $attribute );
		$this->attribute = $attribute;
		register_taxonomy( self::TAXONOMY, array( 'product', 'product_variation' ) );
		// What WooCommerce holds for an attribute from `init` on.
		$GLOBALS['wc_product_attributes'][ self::TAXONOMY ] = current( wp_list_filter( wc_get_attribute_taxonomies(), array( 'attribute_id' => $this->attribute ) ) );
		foreach ( self::COUNTS as $slug => $name ) {
			$term = wp_insert_term( $name, self::TAXONOMY, array( 'slug' => $slug ) );
			$this->assertIsArray( $term );
			$this->terms[ $slug ] = (int) $term['term_id'];
		}
		BlankOptionLinks::set( $this->attribute, BlankOptionLinks::PAGE_COUNT, array() );
		$this->store_rule();
	}

	public function tear_down(): void {
		unregister_taxonomy( self::TAXONOMY );
		unset( $GLOBALS['wc_product_attributes'][ self::TAXONOMY ] );
		delete_transient( 'wc_attribute_taxonomies' );
		delete_option( BlankOptionLinks::OPTION );
		parent::tear_down();
	}

	public function test_each_page_count_is_priced_by_the_rule_on_the_cost_at_that_count(): void {
		$ids = $this->notebook();
		$this->script_book_costs();

		$this->pricing->refresh( $this->parent_of( $ids['44-sidor'] ) );
		$this->pricing->apply( $this->parent_of( $ids['44-sidor'] ) );

		$rule = $this->pricing->rule_for( $this->parent_of( $ids['44-sidor'] ) );
		foreach ( array(
			'44-sidor' => 44,
			'60-sidor' => 60,
		) as $slug => $pages ) {
			$cost = self::amount( (string) self::COSTS_BY_PAGES[ $pages ] ) + self::amount( self::SHIPPING );
			$this->assertSame( $rule->price_for( $cost, PriceTax::of( $this->product( $ids[ $slug ] ) ) ), self::amount( $this->price( $ids[ $slug ] ) ), $slug . ' is priced at its own count' );
			$stored = PriceState::stored_on( $this->product( $ids[ $slug ] ) )->cost;
			$this->assertNotNull( $stored );
			$this->assertSame( $pages, $stored->pages );
		}
		$this->assertNotSame( $this->price( $ids['44-sidor'] ), $this->price( $ids['60-sidor'] ) );
	}

	public function test_a_count_the_printshop_gives_no_price_at_is_flagged_and_cannot_be_bought(): void {
		$ids = $this->notebook();
		$this->script_book_costs();

		$this->pricing->refresh( $this->parent_of( $ids['20-sidor'] ) );

		$twenty = $this->product( $ids['20-sidor'] );
		$this->assertTrue( ProductPricing::unpriced_pages( $twenty ) );
		$this->assertFalse( $twenty->is_purchasable(), 'nothing is sold at a count with no price' );
		$this->assertTrue( $this->product( $ids['44-sidor'] )->is_purchasable() );
		$rows = array_column( array_map( static fn ( $row ): array => ProductPrices::row( $row ), $this->pricing->rows( $this->parent_of( $ids['20-sidor'] ) ) ), null, 'id' );
		$this->assertSame( PriceState::FLAG_NO_COST, $rows[ $ids['20-sidor'] ]['flag'] );
		$this->assertSame( 20, $rows[ $ids['20-sidor'] ]['cost']['pages'] );
		$this->assertSame( 44, $rows[ $ids['44-sidor'] ]['cost']['pages'] );
	}

	public function test_the_prices_table_names_the_count_each_cost_is_at(): void {
		$ids = $this->notebook();
		$this->script_book_costs();

		$this->pricing->refresh( $this->parent_of( $ids['44-sidor'] ) );

		$rows = array();
		foreach ( $this->pricing->rows( $this->parent_of( $ids['44-sidor'] ) ) as $row ) {
			$rows[ $row->id ] = PricingPanel::row( $row );
		}
		$this->assertStringContainsString( 'at 44 pages', $rows[ $ids['44-sidor'] ]['cost_parts'] );
		$this->assertStringContainsString( 'at 60 pages', $rows[ $ids['60-sidor'] ]['cost_parts'] );
	}

	public function test_a_count_the_placement_does_not_offer_has_no_price(): void {
		$ids = $this->notebook( array( '44-sidor' ) );
		$this->script_book_costs( array( 60 => '15.10' ) );

		$this->pricing->refresh( $this->parent_of( $ids['44-sidor'] ) );

		$this->assertTrue( ProductPricing::unpriced_pages( $this->product( $ids['44-sidor'] ) ) );
	}

	public function test_a_value_linked_to_a_count_stands_for_it_whatever_its_name(): void {
		$ids = $this->notebook( array( '20-sidor' ) );
		BlankOptionLinks::set( $this->attribute, BlankOptionLinks::PAGE_COUNT, array( $this->terms['20-sidor'] => '60 pages' ) );
		$this->script_book_costs();

		$this->pricing->refresh( $this->parent_of( $ids['20-sidor'] ) );

		$this->assertSame( array( $this->terms['20-sidor'] => '60' ), BlankOptionLinks::of( $this->attribute )['values'] ?? null );
		$this->assertSame( 60, BlankOptionLinks::page_count( $this->product( $ids['20-sidor'] ) ) );
		$this->assertSame( self::amount( '15.10' ), PriceState::stored_on( $this->product( $ids['20-sidor'] ) )->cost?->product );
	}

	public function test_a_blank_printed_as_no_pages_prices_every_count_at_its_variants_cost(): void {
		$ids = $this->notebook( array( '44-sidor' ) );
		$this->script_book_costs( array() );

		$this->pricing->refresh( $this->parent_of( $ids['44-sidor'] ) );

		$stored = PriceState::stored_on( $this->product( $ids['44-sidor'] ) )->cost;
		$this->assertNotNull( $stored );
		$this->assertSame( 44, $stored->pages );
		$this->assertSame( self::amount( self::FEWEST_PRICED ), $stored->product );
		$this->assertFalse( ProductPricing::unpriced_pages( $this->product( $ids['44-sidor'] ) ) );
	}

	public function test_a_cost_read_at_another_count_is_not_the_cost_of_the_count_sold_now(): void {
		$ids = $this->notebook( array( '44-sidor' ) );
		$this->script_book_costs();
		$this->pricing->refresh( $this->parent_of( $ids['44-sidor'] ) );

		$variation = $this->product( $ids['44-sidor'] );
		$variation->set_attributes( array( self::TAXONOMY => '60-sidor' ) );
		$variation->save();

		$this->assertSame( array(), array_filter( array_map( static fn ( $row ) => $row->cost, $this->pricing->rows( $this->parent_of( $ids['44-sidor'] ) ) ) ) );
	}

	public function test_a_count_sold_before_its_cost_is_read_cannot_be_bought(): void {
		$ids = $this->notebook( array( '44-sidor' ) );
		$this->assertFalse( $this->product( $ids['44-sidor'] )->is_purchasable(), 'never read' );

		// A cost read before the attribute stood for a page count is no count's.
		$this->script_costs( array( self::NOTEBOOK => self::FEWEST_PRICED ), self::SHIPPING );
		BlankOptionLinks::set( $this->attribute, '', array() );
		$this->pricing->refresh( $this->parent_of( $ids['44-sidor'] ) );
		$this->assertTrue( $this->product( $ids['44-sidor'] )->is_purchasable() );
		BlankOptionLinks::set( $this->attribute, BlankOptionLinks::PAGE_COUNT, array() );

		$this->assertFalse( $this->product( $ids['44-sidor'] )->is_purchasable() );
		$this->assertSame( array(), array_filter( array_map( static fn ( $row ) => $row->cost, $this->pricing->rows( $this->parent_of( $ids['44-sidor'] ) ) ) ) );
	}

	public function test_a_variation_for_any_count_cannot_be_bought(): void {
		$ids       = $this->notebook( array( '44-sidor' ) );
		$variation = $this->product( $ids['44-sidor'] );
		$variation->set_attributes( array( self::TAXONOMY => '' ) );
		$variation->save();
		$this->script_book_costs();

		$this->pricing->refresh( $this->parent_of( $ids['44-sidor'] ) );

		$this->assertSame( BlankOptionLinks::UNRESOLVED_PAGES, BlankOptionLinks::page_count( $this->product( $ids['44-sidor'] ) ) );
		$this->assertFalse( $this->product( $ids['44-sidor'] )->is_purchasable() );
	}

	public function test_a_count_the_variations_do_not_vary_by_is_the_products_one_value(): void {
		$ids     = $this->notebook( array( '60-sidor' ) );
		$product = $this->parent_of( $ids['60-sidor'] );
		$pages   = $product->get_attributes()[ self::TAXONOMY ];
		$pages->set_variation( false );
		$size = new WC_Product_Attribute();
		$size->set_name( self::AXIS );
		$size->set_options( array( 'A5' ) );
		$size->set_variation( true );
		$product->set_attributes( array( $pages, $size ) );
		$product->save();
		$variation = $this->product( $ids['60-sidor'] );
		$variation->set_attributes( array( sanitize_title( self::AXIS ) => 'A5' ) );
		$variation->save();
		$this->script_book_costs();

		$this->pricing->refresh( $this->parent_of( $ids['60-sidor'] ) );

		$this->assertSame( 60, BlankOptionLinks::page_count( $this->product( $ids['60-sidor'] ) ) );
		$this->assertSame( self::amount( '15.10' ), PriceState::stored_on( $this->product( $ids['60-sidor'] ) )->cost?->product );
	}

	/**
	 * A notebook on the blank with a variation per page count, each selling
	 * the same blank variant.
	 *
	 * @param string[] $slugs The counts it is sold at, by slug.
	 * @phpstan-param list<string> $slugs
	 * @return array<string, int> The variations' ids, by slug.
	 */
	private function notebook( array $slugs = array( '20-sidor', '44-sidor', '60-sidor' ) ): array {
		$attribute = new WC_Product_Attribute();
		$attribute->set_id( $this->attribute );
		$attribute->set_name( self::TAXONOMY );
		$attribute->set_options( array_map( fn ( string $slug ): int => $this->terms[ $slug ], $slugs ) );
		$attribute->set_variation( true );
		$product = new WC_Product_Variable();
		$product->set_name( 'Notebook' );
		$product->set_attributes( array( $attribute ) );
		$product->save();
		wp_set_object_terms( $product->get_id(), array_map( fn ( string $slug ): int => $this->terms[ $slug ], $slugs ), self::TAXONOMY );
		$this->on_notebook_blank( $product );
		$ids = array();
		foreach ( $slugs as $slug ) {
			$variation = new WC_Product_Variation();
			$variation->set_parent_id( $product->get_id() );
			$variation->set_attributes( array( self::TAXONOMY => $slug ) );
			$variation->set_regular_price( '100' );
			$variation->save();
			( new ProductVariant( self::BLANK_ID, self::NOTEBOOK, self::NOTEBOOK, 'A5' ) )->save_to( $variation );
			$ids[ $slug ] = $variation->get_id();
		}
		return $ids;
	}

	/**
	 * Scripts one read of the notebook's costs, with its cost at each page count.
	 *
	 * @param array<int, string|null> $by_pages What it costs at each count; empty for a blank printed as no pages.
	 */
	private function script_book_costs( array $by_pages = self::COSTS_BY_PAGES ): void {
		$money = static fn ( ?string $amount ): ?array => null === $amount ? null : array(
			'amount'   => $amount,
			'currency' => get_woocommerce_currency(),
		);
		$pages = array();
		foreach ( $by_pages as $count => $product ) {
			$pages[] = array(
				'pages'    => $count,
				'product'  => $money( $product ),
				'shipping' => null === $product ? null : $money( self::SHIPPING ),
				'times'    => self::NO_TIMES,
			);
		}
		$this->recorder->json(
			200,
			array(
				'costs'          => array(
					array(
						'variant'   => self::NOTEBOOK,
						'product'   => $money( self::FEWEST_PRICED ),
						'shipping'  => $money( self::SHIPPING ),
						'times'     => self::NO_TIMES,
						'pages'     => $pages,
						'region'    => (string) WC()->countries->get_base_country(),
						'readAt'    => '2026-11-23T12:00:00.000Z',
						'changedAt' => null,
					),
				),
				'complete'       => true,
				'read'           => true,
				'missing'        => array(),
				'currencyNeeded' => false,
			)
		);
	}

	private function on_notebook_blank( WC_Product_Variable $product ): void {
		( new ProductConfig( personalise: true, blank_id: self::BLANK_ID ) )->save_to( $product );
	}
}
