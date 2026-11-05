<?php
/**
 * Resolves a product's blank variant again once its attributes change.
 *
 * @package Pixonaut\WooCommerce
 */

declare(strict_types=1);

namespace Pixonaut\WooCommerce\Catalogue;

use WC_Product;

/**
 * A product or variation is saved by more than the product screen: the
 * REST API, an import, or code calling `save()`. When one personalised on a
 * blank was saved with attributes its variant was not matched on, or a
 * variation was made without a variant, its re-check is queued. It is never
 * resolved inside the save, which would ask Pixonaut while the save waits.
 * Until the re-check runs, a match on attributes it no longer has sells
 * nothing (`ProductVariant::sold_by()`).
 */
final class VariantWatch {

	/**
	 * The arguments `woocommerce_new_product_variation`,
	 * `woocommerce_update_product_variation` and `woocommerce_update_product` pass.
	 */
	private const SAVE_ARGS = 2;

	/**
	 * After the save's own callbacks.
	 */
	private const HOOK_PRIORITY = 20;

	/**
	 * @param ProductRecheck $recheck Resolves the variants in a job.
	 */
	public function __construct( private readonly ProductRecheck $recheck ) {
	}

	public function register(): void {
		add_action( 'woocommerce_new_product_variation', array( $this, 'saved' ), self::HOOK_PRIORITY, self::SAVE_ARGS );
		add_action( 'woocommerce_update_product_variation', array( $this, 'saved' ), self::HOOK_PRIORITY, self::SAVE_ARGS );
		add_action( 'woocommerce_update_product', array( $this, 'saved' ), self::HOOK_PRIORITY, self::SAVE_ARGS );
	}

	/**
	 * Queues the re-check of the saved product, or of each of a variable
	 * product's variations, whose variant is no longer the one stored.
	 *
	 * @param mixed $product_id The saved product or variation.
	 * @param mixed $product    The product or variation as saved; unused, it is loaded again.
	 */
	public function saved( mixed $product_id, mixed $product = null ): void {
		unset( $product );
		if ( AttributeMove::writing() ) {
			return;
		}
		$saved = wc_get_product( (int) $product_id );
		if ( ! $saved instanceof WC_Product ) {
			return;
		}
		$sold  = $saved->is_type( 'variable' ) ? ProductConfig::sold_variations( $saved ) : array( array( $saved, ProductConfig::config_for( $saved ) ) );
		$stale = array();
		foreach ( $sold as list( $one, $config ) ) {
			if ( self::is_stale( $one, $config ) ) {
				$stale[] = $one->get_id();
			}
		}
		if ( array() !== $stale ) {
			$this->recheck->queue( $stale );
		}
	}

	/**
	 * Whether a product or variation personalised on a blank has no variant
	 * stored for it, or one matched on other attributes.
	 *
	 * @param WC_Product    $product A product or variation, not a variable product.
	 * @param ProductConfig $config  The configuration it is sold under.
	 */
	private static function is_stale( WC_Product $product, ProductConfig $config ): bool {
		if ( ! $config->personalise || null === $config->blank_id ) {
			return false;
		}
		$stored = ProductVariant::stored_on( $product );
		return null === $stored || $stored->blank_id !== $config->blank_id || ! $stored->is_current( $product );
	}
}
