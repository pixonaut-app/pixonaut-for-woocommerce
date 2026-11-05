<?php
/**
 * The admin notice of a product, or its variations, selling no blank variant.
 *
 * @package Pixonaut\WooCommerce
 */

declare(strict_types=1);

namespace Pixonaut\WooCommerce\Admin;

use Pixonaut\WooCommerce\Catalogue\ProductConfig;
use Pixonaut\WooCommerce\Catalogue\ProductVariant;
use WC_Product;
use WC_Product_Variation;

/**
 * Names a product, or the variations of a variable product, customers
 * cannot buy because no blank variant is theirs, or theirs is retired,
 * until each has one again. A product in the trash has no notice.
 */
final class VariantNotice {

	public function __construct( private readonly Notices $notices ) {
	}

	/**
	 * The notice's key for a product.
	 *
	 * @param int $product_id The product.
	 */
	public static function key( int $product_id ): string {
		return 'product_' . $product_id . '_variants';
	}

	/**
	 * Raises the product's notice while it, or one of its variations a
	 * customer can choose, sells no variant, and clears it once each does.
	 *
	 * @param WC_Product $product A product, not a variation.
	 */
	public function note( WC_Product $product ): void {
		$key = self::key( $product->get_id() );
		if ( TrashedProducts::in_trash( $product ) ) {
			$this->notices->remove( $key );
			return;
		}
		$sold     = $product->is_type( 'variable' )
			? ProductConfig::sold_variations( $product )
			: array( array( $product, ProductConfig::config_for( $product ) ) );
		$problems = array();
		foreach ( $sold as list( $one, $config ) ) {
			if ( ProductVariant::sellable( $one, $config ) ) {
				continue;
			}
			$stored  = ProductVariant::stored_on( $one );
			$retired = null !== $stored && $stored->blank_id === $config->blank_id && ProductVariant::RETIRED === $stored->problem();
			if ( ! $one instanceof WC_Product_Variation ) {
				$problems[] = $retired
					/* translators: Part of an admin notice, shown to the merchant, after "Customers cannot buy %s:"; says why the product cannot be bought. Lower case, as it ends a sentence. */
					? __( 'its blank variant is retired', 'pixonaut-for-woocommerce' )
					/* translators: Part of an admin notice, shown to the merchant, after "Customers cannot buy %s:"; says why the product cannot be bought. Lower case, as it ends a sentence. */
					: __( 'it is no variant of its blank', 'pixonaut-for-woocommerce' );
				continue;
			}
			$name       = wc_get_formatted_variation( $one, true, false, false );
			$name       = '' === $name ? '#' . $one->get_id() : $name;
			$problems[] = $retired
				/* translators: %s: the variation's attributes, e.g. "Red, M". */
				? sprintf( __( 'variation %s, whose blank variant is retired', 'pixonaut-for-woocommerce' ), $name )
				/* translators: %s: the variation's attributes, e.g. "Red, M". */
				: sprintf( __( 'variation %s, which is no variant of its blank', 'pixonaut-for-woocommerce' ), $name );
		}
		if ( array() === $problems ) {
			$this->notices->remove( $key );
			return;
		}
		// Built without get_edit_post_link(), which is empty in a background job with no user.
		$link = add_query_arg(
			array(
				'post'   => $product->get_id(),
				'action' => 'edit',
			),
			admin_url( 'post.php' )
		);
		$this->notices->add(
			$key,
			sprintf(
				/* translators: 1: product edit link, 2: what customers cannot buy and why, e.g. "variation Red, M, whose blank variant is retired". */
				__( 'Customers cannot buy all of %1$s: %2$s. Pick the variant in its Pixonaut fields.', 'pixonaut-for-woocommerce' ),
				sprintf( '<a href="%s">%s</a>', esc_url( $link ), esc_html( $product->get_name() ) ),
				esc_html( implode( '; ', $problems ) )
			),
			'error',
			false
		);
	}

	/**
	 * Drops a deleted product's notice.
	 *
	 * @param int $product_id The product.
	 */
	public function forget( int $product_id ): void {
		$this->notices->remove( self::key( $product_id ) );
	}
}
