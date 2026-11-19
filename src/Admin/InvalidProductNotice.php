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
use WC_Product_Variation;

/**
 * One notice per product or variation whose Pixonaut configuration cannot be
 * sold, saying why and linking to the screen it is fixed on. Raised and
 * cleared whenever the configuration is saved or checked again; never for
 * one in the trash.
 */
final class InvalidProductNotice {

	/**
	 * Runs before `Notices` shows the notices, at the default priority.
	 */
	private const BEFORE_NOTICES = 9;

	/**
	 * A notice's key, capturing the product's id (`key()`).
	 */
	private const KEY_PATTERN = '/^product_(\\d+)_invalid$/';

	/**
	 * The id of WooCommerce's Variations panel on the product screen.
	 */
	private const VARIATIONS_PANEL = 'variable_product_options';

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
	 * Clears the notices of products and variations that customers can buy
	 * again before the notices are shown. A notice is raised and cleared as
	 * a configuration is saved, but saves made at the same time, such as a
	 * product's variations saved by several requests at once, can leave one
	 * standing over a configuration saved after it.
	 */
	public function register(): void {
		add_action( 'admin_notices', array( $this, 'reconcile' ), self::BEFORE_NOTICES );
	}

	/**
	 * Clears each product's or variation's notice its stored configuration
	 * no longer warrants, and says again why the rest cannot be bought: a
	 * reason can read differently by now, such as when a check refused for
	 * too many requests runs again.
	 */
	public function reconcile(): void {
		$messages = array();
		foreach ( array_keys( $this->notices->all() ) as $key ) {
			if ( 1 !== preg_match( self::KEY_PATTERN, $key, $matches ) ) {
				continue;
			}
			$product          = wc_get_product( (int) $matches[1] );
			$text             = $product instanceof WC_Product ? self::text( $product, ProductConfig::stored_on( $product ) ) : null;
			$messages[ $key ] = $product instanceof WC_Product && null !== $text ? self::message( $product, $text ) : null;
		}
		$this->notices->revise( $messages );
	}

	/**
	 * Raises or clears the notice of a product or variation.
	 *
	 * @param WC_Product    $product The product or variation.
	 * @param ProductConfig $config  Its configuration as saved; a variation's applies only while it overrides.
	 */
	public function note( WC_Product $product, ProductConfig $config ): void {
		$key  = self::key( $product->get_id() );
		$text = self::text( $product, $config );
		if ( null === $text ) {
			$this->notices->remove( $key );
			return;
		}
		$this->notices->add( $key, self::message( $product, $text ), 'error', false );
	}

	/**
	 * The notice's text: which product or variation, linked to its screen, and why customers cannot buy it.
	 *
	 * @param WC_Product $product The product or variation.
	 * @param string     $text    Why it cannot be bought.
	 */
	private static function message( WC_Product $product, string $text ): string {
		$variation = $product->is_type( 'variation' );
		// A variation is edited on its product's screen, in its Variations panel. Built without
		// get_edit_post_link(), which is empty in a background job with no user.
		$link = add_query_arg(
			array(
				'post'   => $variation ? $product->get_parent_id() : $product->get_id(),
				'action' => 'edit',
			),
			admin_url( 'post.php' )
		) . ( $variation ? '#' . self::VARIATIONS_PANEL : '' );
		return sprintf(
				/* translators: 1: product edit link, 2: why it cannot be bought. */
			__( 'Customers cannot buy %1$s: %2$s', 'pixonaut-for-woocommerce' ),
			sprintf( '<a href="%s">%s</a>', esc_url( $link ), esc_html( $variation ? self::variation_name( $product ) : $product->get_name() ) ),
			esc_html( $text )
		);
	}

	/**
	 * Why a product or variation cannot be bought, as its notice says it;
	 * null when it has no notice.
	 *
	 * @param WC_Product    $product The product or variation.
	 * @param ProductConfig $config  Its configuration as saved.
	 */
	private static function text( WC_Product $product, ProductConfig $config ): ?string {
		$variation = $product->is_type( 'variation' );
		if ( ( $variation && ! $config->override ) || TrashedProducts::in_trash( $product ) ) {
			return null;
		}
		return InvalidReason::explain( $product, $config, $variation );
	}

	/**
	 * A variation as its notice names it: its product, and its attributes
	 * or, without any, its id. A variation's own name is its product's
	 * when it has more than two attributes.
	 *
	 * @param WC_Product $variation The variation.
	 */
	private static function variation_name( WC_Product $variation ): string {
		$parent     = wc_get_product( $variation->get_parent_id() );
		$attributes = $variation instanceof WC_Product_Variation ? wc_get_formatted_variation( $variation, true, false, false ) : '';
		return sprintf(
			/* translators: 1: the product's name, 2: the variation's attributes, e.g. "Size: M, Color: Red", or its id, e.g. "#123". */
			__( '%1$s, variation %2$s', 'pixonaut-for-woocommerce' ),
			$parent instanceof WC_Product ? $parent->get_name() : $variation->get_name(),
			'' === $attributes ? '#' . $variation->get_id() : $attributes
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
