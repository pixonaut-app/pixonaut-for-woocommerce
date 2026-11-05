<?php
/**
 * The notice a product raises while customers cannot buy it.
 *
 * @package Pixonaut\WooCommerce
 */

declare(strict_types=1);

namespace Pixonaut\WooCommerce\Admin;

use Pixonaut\WooCommerce\Catalogue\InvalidReason;
use Pixonaut\WooCommerce\Catalogue\ProductConfig;
use WC_Product;

/**
 * One notice per product or variation whose Pixonaut configuration cannot be
 * sold, saying why and linking to the screen it is fixed on. Raised and
 * cleared whenever the configuration is saved or checked again; never for
 * one in the trash.
 */
final class InvalidProductNotice {

	/**
	 * @param Notices $notices Holds the notices.
	 */
	public function __construct( private readonly Notices $notices ) {
	}

	/**
	 * The notice's key.
	 *
	 * @param int $product_id The product or variation.
	 */
	public static function key( int $product_id ): string {
		return 'product_' . $product_id . '_invalid';
	}

	/**
	 * Raises or clears the notice of a product or variation.
	 *
	 * @param WC_Product    $product The product or variation.
	 * @param ProductConfig $config  Its configuration as saved; a variation's applies only while it overrides.
	 */
	public function note( WC_Product $product, ProductConfig $config ): void {
		$key = self::key( $product->get_id() );
		if ( ! $config->personalise || $config->valid || ( $product->is_type( 'variation' ) && ! $config->override ) || TrashedProducts::in_trash( $product ) ) {
			$this->notices->remove( $key );
			return;
		}
		// A variation is edited on its product's screen. Built without
		// get_edit_post_link(), which is empty in a background job with no user.
		$edited = $product->get_parent_id() > 0 ? $product->get_parent_id() : $product->get_id();
		$link   = add_query_arg(
			array(
				'post'   => $edited,
				'action' => 'edit',
			),
			admin_url( 'post.php' )
		);
		$this->notices->add(
			$key,
			sprintf(
				/* translators: 1: product edit link, 2: why it cannot be bought. */
				__( 'Customers cannot buy %1$s: %2$s', 'pixonaut-for-woocommerce' ),
				sprintf( '<a href="%s">%s</a>', esc_url( $link ), esc_html( $product->get_name() ) ),
				esc_html( InvalidReason::message( $config->invalid_reason ) )
			),
			'error',
			false
		);
	}

	/**
	 * Drops the notice of a product that is gone.
	 *
	 * @param int $product_id The product or variation.
	 */
	public function forget( int $product_id ): void {
		$this->notices->remove( self::key( $product_id ) );
	}
}
