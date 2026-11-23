<?php
/**
 * @package Pixonaut\WooCommerce
 */

declare(strict_types=1);

namespace Pixonaut\WooCommerce\Tests\Integration\Catalogue;

use Pixonaut\WooCommerce\Catalogue\ProductMeta;
use WC_Data;
use WC_Product;
use WC_Product_Simple;
use WP_UnitTestCase;

/**
 * Many products' meta read in one query serve WooCommerce's and
 * WordPress's reads of it, with no query of their own.
 */
final class ProductMetaTest extends WP_UnitTestCase {

	/** The object cache group WooCommerce keeps a product's meta rows in. */
	private const WC_CACHE_GROUP = 'products';

	public function test_primed_products_read_their_meta_without_a_query(): void {
		$ids = array();
		foreach ( array( 'red', 'blue' ) as $colour ) {
			$product = new WC_Product_Simple();
			$product->set_regular_price( '10' );
			$product->update_meta_data( 'colour', $colour );
			$product->add_meta_data( 'tag', 'a' );
			$product->add_meta_data( 'tag', 'b' );
			$product->save();
			$ids[] = $product->get_id();
		}
		global $wpdb;
		$this->forget( $ids );
		$before = $wpdb->num_queries;
		$this->read( $ids );
		$this->assertGreaterThan( $before, $wpdb->num_queries, 'unprimed, each product is read by a query' );
		$this->forget( $ids );
		ProductMeta::prime( $ids );
		$before = $wpdb->num_queries;

		$read = $this->read( $ids );

		$this->assertSame(
			array(
				array( 'red', array( 'a', 'b' ), array( 'a', 'b' ), '10' ),
				array( 'blue', array( 'a', 'b' ), array( 'a', 'b' ), '10' ),
			),
			$read
		);
		$this->assertSame( $before, $wpdb->num_queries, 'no query of their own' );
	}

	public function test_a_primed_products_meta_keeps_its_ids_for_a_later_write(): void {
		$product = new WC_Product_Simple();
		$product->update_meta_data( 'colour', 'red' );
		$product->save();
		$id = $product->get_id();
		$this->forget( array( $id ) );
		ProductMeta::prime( array( $id ) );

		$primed = $this->product( $id );
		$primed->update_meta_data( 'colour', 'green' );
		$primed->save();

		$this->assertSame( array( 'green' ), get_post_meta( $id, 'colour', false ), 'updated in place, not added beside' );
	}

	public function test_a_product_already_read_is_not_read_again(): void {
		$product = new WC_Product_Simple();
		$product->save();
		$id = $product->get_id();
		$this->product( $id )->get_meta( 'colour' );
		get_post_meta( $id );
		global $wpdb;
		$before = $wpdb->num_queries;

		ProductMeta::prime( array( $id ) );

		$this->assertSame( $before, $wpdb->num_queries );
	}

	/**
	 * Each product's meta, as WooCommerce and WordPress read it.
	 *
	 * @param int[] $ids Products.
	 * @phpstan-param list<int> $ids
	 * @return list<array<mixed>>
	 */
	private function read( array $ids ): array {
		$read = array();
		foreach ( $ids as $id ) {
			$product = $this->product( $id );
			$read[]  = array( $product->get_meta( 'colour' ), array_column( array_map( static fn ( $row ) => $row->get_data(), $product->get_meta( 'tag', false ) ), 'value' ), get_post_meta( $id, 'tag', false ), get_post_meta( $id, '_regular_price', true ) );
		}
		return $read;
	}

	/**
	 * Forgets what WooCommerce and WordPress read of the products' meta, as
	 * at the start of a request.
	 *
	 * @param int[] $ids Products.
	 * @phpstan-param list<int> $ids
	 */
	private function forget( array $ids ): void {
		foreach ( $ids as $id ) {
			// What WC_Data::delete_meta_cache() does, which WooCommerce has only from 11.0.
			wp_cache_delete( WC_Data::generate_meta_cache_key( $id, self::WC_CACHE_GROUP ), self::WC_CACHE_GROUP );
			wp_cache_delete( (string) $id, 'post_meta' );
		}
	}

	private function product( int $id ): WC_Product {
		$product = wc_get_product( $id );
		$this->assertInstanceOf( WC_Product::class, $product );
		return $product;
	}
}
