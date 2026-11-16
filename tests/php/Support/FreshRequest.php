<?php
/**
 * Starts a test as a request of its own, for the plugin's shared services.
 *
 * @package Pixonaut\WooCommerce
 */

declare(strict_types=1);

namespace Pixonaut\WooCommerce\Tests\Support;

use Pixonaut\WooCommerce\Catalogue\ProductVariants;
use Pixonaut\WooCommerce\Catalogue\VariationSizes;
use Pixonaut\WooCommerce\Plugin;
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
}
