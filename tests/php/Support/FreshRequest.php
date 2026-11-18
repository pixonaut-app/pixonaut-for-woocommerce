<?php
/**
 * Starts a test as a request of its own, for the plugin's shared services.
 *
 * @package Pixonaut\WooCommerce
 */

declare(strict_types=1);

namespace Pixonaut\WooCommerce\Tests\Support;

use Pixonaut\WooCommerce\Catalogue\FilterDataRefresh;
use Pixonaut\WooCommerce\Catalogue\FilterIndex;
use Pixonaut\WooCommerce\Catalogue\FilterOffers;
use Pixonaut\WooCommerce\Catalogue\ProductVariants;
use Pixonaut\WooCommerce\Catalogue\RequestCache;
use Pixonaut\WooCommerce\Catalogue\SplitCards;
use Pixonaut\WooCommerce\Catalogue\VariationSizes;
use Pixonaut\WooCommerce\Plugin;
use Pixonaut\WooCommerce\Storefront\FilteredOpening;
use Pixonaut\WooCommerce\Storefront\OpeningDefaults;
use Pixonaut\WooCommerce\Storefront\ProductPageCache;
use ReflectionProperty;

/**
 * `Plugin::boot()` gives one instance for the whole PHPUnit run, and its
 * `product_variants()` keeps what it read of a blank's variants, a failed
 * read, and Pixonaut's verdicts on variation sizes for the rest of the
 * request. WordPress rolls back the database after each test but not these,
 * so a test that read a blank's variants would answer a later test's reads
 * of the same blank. A test that resolves variants through the plugin starts
 * here.
 */
final class FreshRequest {

	public static function begin( Plugin $plugin ): void {
		$variants = $plugin->product_variants();
		( new ReflectionProperty( ProductVariants::class, 'read' ) )->setValue( $variants, array() );
		( new ReflectionProperty( ProductVariants::class, 'failed' ) )->setValue( $variants, array() );
		$sizes = ( new ReflectionProperty( ProductVariants::class, 'sizes' ) )->getValue( $variants );
		if ( $sizes instanceof VariationSizes ) {
			$sizes->forget();
		}
	}

	/**
	 * Forgets what the shop's filters and card links read of products'
	 * variations this request, as the next request starts without it;
	 * what `FilterIndex` kept between requests stays.
	 */
	public static function forget_filter_reads(): void {
		foreach ( array( 'variations', 'offers', 'offers_plain', 'cards', 'card_terms', 'links' ) as $memo ) {
			( new ReflectionProperty( SplitCards::class, $memo ) )->setValue( null, array() );
		}
		( new ReflectionProperty( FilterOffers::class, 'offers' ) )->setValue( null, array() );
		( new ReflectionProperty( FilteredOpening::class, 'values' ) )->setValue( null, array() );
		( new ReflectionProperty( FilterIndex::class, 'looked_up' ) )->setValue( null, array() );
		( new ReflectionProperty( FilterIndex::class, 'switched' ) )->setValue( null, false );
		( new ReflectionProperty( FilterIndex::class, 'errors' ) )->setValue( null, null );
		( new ReflectionProperty( FilterDataRefresh::class, 'noted' ) )->setValue( null, null );
		( new ReflectionProperty( FilterDataRefresh::class, 'dependencies_changed' ) )->setValue( null, false );
		RequestCache::forget();
	}

	/**
	 * Starts the next request now, without what this one read of product
	 * pages: the versions of products it read and the defaults it filled
	 * in. What `ProductPageCache` kept between requests stays.
	 */
	public static function forget_page_reads(): void {
		( new ReflectionProperty( ProductPageCache::class, 'versions' ) )->setValue( null, array() );
		( new ReflectionProperty( ProductPageCache::class, 'written' ) )->setValue( null, array() );
		$_SERVER['REQUEST_TIME_FLOAT'] = microtime( true );
		global $wp_filter;
		foreach ( $wp_filter['woocommerce_product_get_default_attributes']->callbacks ?? array() as $callbacks ) {
			foreach ( $callbacks as $callback ) {
				$function = $callback['function'];
				if ( is_array( $function ) && $function[0] instanceof OpeningDefaults ) {
					( new ReflectionProperty( OpeningDefaults::class, 'filled' ) )->setValue( $function[0], array() );
				}
			}
		}
	}
}
