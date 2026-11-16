<?php
/**
 * @package Pixonaut\WooCommerce
 */

declare(strict_types=1);

namespace Pixonaut\WooCommerce\Tests\Integration\Catalogue;

use Pixonaut\WooCommerce\Catalogue\ProductConfig;
use Pixonaut\WooCommerce\Catalogue\RequestCache;
use Pixonaut\WooCommerce\Plugin;
use WC_Product;
use WC_Product_Attribute;
use WC_Product_Variable;
use WC_Product_Variation;
use WP_UnitTestCase;

/**
 * What a page reads many times is read once, and read again after anything
 * it depends on is written.
 */
final class RequestCacheTest extends WP_UnitTestCase {

	private const KEY = 'request-cache-test';

	public function set_up(): void {
		parent::set_up();
		Plugin::boot( dirname( __DIR__, 4 ) . '/pixonaut-for-woocommerce.php' );
		RequestCache::forget();
	}

	public function tear_down(): void {
		RequestCache::forget();
		parent::tear_down();
	}

	public function test_a_read_is_made_once_until_a_post_or_its_meta_is_written(): void {
		$post = self::factory()->post->create();
		$this->assertIsInt( $post );
		$reads = 0;
		$read  = static function () use ( &$reads ): int {
			return ++$reads;
		};

		RequestCache::remember( self::KEY, $read );
		RequestCache::remember( self::KEY, $read );
		$this->assertSame( 1, $reads );

		update_post_meta( $post, '_some_meta', 'value' );
		RequestCache::remember( self::KEY, $read );
		$this->assertSame( 2, $reads, 'a meta write' );

		clean_post_cache( $post );
		RequestCache::remember( self::KEY, $read );
		$this->assertSame( 3, $reads, 'a post write' );
	}

	public function test_a_write_to_the_plugins_or_woocommerces_option_forgets_and_a_transient_does_not(): void {
		$reads = 0;
		$read  = static function () use ( &$reads ): int {
			return ++$reads;
		};
		RequestCache::remember( self::KEY, $read );

		set_transient( 'pixonaut_wc_catalogue_request_cache_test', 1 );
		update_option( 'blogdescription', 'Elsewhere' );
		RequestCache::remember( self::KEY, $read );
		$this->assertSame( 1, $reads );

		update_option( 'woocommerce_currency', 'EUR' );
		RequestCache::remember( self::KEY, $read );
		$this->assertSame( 2, $reads );

		update_option( 'pixonaut_wc_request_cache_test', 1 );
		RequestCache::remember( self::KEY, $read );
		$this->assertSame( 3, $reads );
	}

	public function test_a_filters_key_changes_as_a_callback_is_added_or_removed(): void {
		$filter = 'pixonaut_wc_request_cache_test';
		$none   = RequestCache::filters_key( array( $filter ) );

		add_filter( $filter, '__return_true' );
		$one = RequestCache::filters_key( array( $filter ) );
		remove_filter( $filter, '__return_true' );

		$this->assertNotSame( $none, $one );
		$this->assertSame( $none, RequestCache::filters_key( array( $filter ) ) );
	}

	public function test_a_variable_products_variation_configurations_are_read_once_and_again_after_a_variation_changes(): void {
		list( $parent, $variation ) = self::variable();
		$product                    = wc_get_product( $parent );
		$this->assertInstanceOf( WC_Product::class, $product );
		$personalised = ( new ProductConfig( personalise: true ) )->to_json();
		$reads        = 0;
		$count        = static function ( $personalise ) use ( &$reads ) {
			++$reads;
			return $personalise;
		};
		update_post_meta( $parent, ProductConfig::META_KEY, wp_slash( $personalised ) );
		$product = wc_get_product( $parent );
		$this->assertInstanceOf( WC_Product::class, $product );
		add_filter( 'pixonaut_wc_catalogue_is_personalisable', $count );
		try {
			ProductConfig::variation_configs( $product );
			$first = $reads;
			ProductConfig::variation_configs( $product );
			$this->assertSame( $first, $reads, 'read once' );

			update_post_meta( $variation, 'attribute_colour', 'White' );
			ProductConfig::variation_configs( $product );
			$this->assertGreaterThan( $first, $reads, 'read again after the variation changed' );
		} finally {
			remove_filter( 'pixonaut_wc_catalogue_is_personalisable', $count );
		}

		$turned_off = static fn (): bool => false;
		add_filter( 'pixonaut_wc_catalogue_is_personalisable', $turned_off );
		try {
			$configs = ProductConfig::variation_configs( $product );
		} finally {
			remove_filter( 'pixonaut_wc_catalogue_is_personalisable', $turned_off );
		}
		$this->assertFalse( $configs[ $variation ]->personalise, 'a filter added since is applied' );
	}

	/**
	 * A variable product with one variation.
	 *
	 * @return array{0: int, 1: int} The product and its variation.
	 */
	private static function variable(): array {
		$attribute = new WC_Product_Attribute();
		$attribute->set_name( 'Colour' );
		$attribute->set_options( array( 'Red', 'White' ) );
		$attribute->set_visible( true );
		$attribute->set_variation( true );
		$parent = new WC_Product_Variable();
		$parent->set_name( 'Tee' );
		$parent->set_status( 'publish' );
		$parent->set_attributes( array( $attribute ) );
		$parent->save();
		$variation = new WC_Product_Variation();
		$variation->set_parent_id( $parent->get_id() );
		$variation->set_attributes( array( 'colour' => 'Red' ) );
		$variation->set_regular_price( '30' );
		$variation->save();
		WC_Product_Variable::sync( $parent->get_id() );
		return array( $parent->get_id(), $variation->get_id() );
	}
}
