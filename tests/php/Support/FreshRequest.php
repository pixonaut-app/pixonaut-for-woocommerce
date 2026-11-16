<?php
/**
 * Starts a test as a request of its own, for the plugin's shared services.
 *
 * @package Pixonaut\WooCommerce
 */

declare(strict_types=1);

namespace Pixonaut\WooCommerce\Tests\Support;

use Pixonaut\WooCommerce\Catalogue\FilterIndex;
use Pixonaut\WooCommerce\Catalogue\FilterOffers;
use Pixonaut\WooCommerce\Catalogue\ProductVariants;
use Pixonaut\WooCommerce\Catalogue\RequestCache;
use Pixonaut\WooCommerce\Catalogue\SplitCards;
use Pixonaut\WooCommerce\Catalogue\VariationSizes;
use Pixonaut\WooCommerce\Plugin;
use Pixonaut\WooCommerce\Storefront\FilteredOpening;
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
		RequestCache::forget();
	}
}
