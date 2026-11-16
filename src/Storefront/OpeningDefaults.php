<?php
/**
 * The default attributes a variable product's own page opens on.
 *
 * @package Pixonaut\WooCommerce
 */

declare(strict_types=1);

namespace Pixonaut\WooCommerce\Storefront;

use Pixonaut\WooCommerce\Catalogue\PicturedVariation;
use WC_Product;
use WC_Product_Variable;
use WC_Product_Variation;

/**
 * Has a personalised variable product's own page open on a whole variation.
 * WooCommerce opens the form on the product's default attributes, and on no
 * value of an attribute that has none, so the form then holds no variation
 * until the customer has chosen every value. On the product's page, each
 * attribute without a default takes its value from the first variation a
 * customer can buy that agrees with what is chosen already: the values the
 * page's address names, as a split card's link does, and the stored defaults
 * for the rest. With neither, that is the variation the product's picture is
 * drawn for (`PicturedVariation`). An attribute that variation is sold in
 * any value of stays open. A stored default is never replaced, and
 * nothing is stored: the product keeps the defaults it was given, which is
 * what the admin, the REST API and every other page read.
 */
final class OpeningDefaults {

	/**
	 * The filled defaults, by product id and by what they were filled from.
	 *
	 * @var array<int, array<string, array<string, string>>>
	 */
	private array $filled = array();

	/**
	 * While the variation to fill from is looked for, when the stored
	 * defaults are answered as they are.
	 *
	 * @var bool
	 */
	private bool $filling = false;

	public function register(): void {
		add_filter( 'woocommerce_product_get_default_attributes', array( $this, 'defaults' ), 10, 2 );
	}

	/**
	 * The product's default attributes, each one it stores none for filled
	 * in on its own page.
	 *
	 * @param mixed $defaults The stored defaults, by attribute.
	 * @param mixed $product  The product.
	 * @return mixed
	 */
	public function defaults( $defaults, $product ) {
		if ( $this->filling || ! is_array( $defaults ) || ! $product instanceof WC_Product_Variable || ! self::is_page_of( $product ) ) {
			return $defaults;
		}
		$stored = array_filter( array_map( 'strval', $defaults ), static fn ( string $value ): bool => '' !== $value );
		$key    = (string) wp_json_encode( array( $stored, OpeningChoice::requested( $product ) ) );
		$id     = $product->get_id();
		if ( ! isset( $this->filled[ $id ][ $key ] ) ) {
			$this->filling = true;
			try {
				$this->filled[ $id ] = array( $key => self::fill( $product, $stored ) );
			} finally {
				$this->filling = false;
			}
		}
		return $this->filled[ $id ][ $key ];
	}

	/**
	 * The stored defaults, and a value for each attribute that has none.
	 *
	 * @param WC_Product_Variable   $product The product.
	 * @param array<string, string> $stored  Its stored defaults, by attribute.
	 * @return array<string, string>
	 */
	private static function fill( WC_Product_Variable $product, array $stored ): array {
		$options = array();
		foreach ( $product->get_variation_attributes() as $name => $values ) {
			$options[ sanitize_title( (string) $name ) ] = array(
				'taxonomy' => taxonomy_exists( (string) $name ),
				'values'   => array_map( 'strval', (array) $values ),
			);
		}
		$named = array_intersect_key( OpeningChoice::requested( $product ), $options );
		// An attribute the address names opens on that, whatever its default.
		$open = array_diff_key( $options, $stored, $named );
		if ( array() === $open ) {
			return $stored;
		}
		$requested = array();
		foreach ( $named as $attribute => $value ) {
			$chosen = self::option( $value, $options[ $attribute ]['values'], $options[ $attribute ]['taxonomy'] );
			if ( '' !== $chosen ) {
				$requested[ $attribute ] = $chosen;
			}
		}
		// What the address names outranks a default, as it does in the form.
		$variation = PicturedVariation::agreeing_with( $product, $requested + $stored );
		if ( null === $variation && array() !== $requested ) {
			$variation = PicturedVariation::agreeing_with( $product, $requested );
		}
		if ( ! $variation instanceof WC_Product_Variation ) {
			return $stored;
		}
		$held = array_map( 'strval', $variation->get_attributes() );
		foreach ( array_keys( $open ) as $attribute ) {
			// An attribute the variation is sold in any value of stays the customer's to choose.
			if ( '' !== ( $held[ $attribute ] ?? '' ) ) {
				$stored[ $attribute ] = $held[ $attribute ];
			}
		}
		return $stored;
	}

	/**
	 * The value of an attribute the form opens on for a value the address
	 * names, as `wc_dropdown_variation_attribute_options()` selects it: a
	 * term by its slug, however the address spells it, and a custom value
	 * by itself or by the slug of it. The value as named when it is none of
	 * them, which no variation then agrees with.
	 *
	 * @param string   $named    The value the address names.
	 * @param string[] $values   The attribute's values, as a variation keeps them.
	 * @param bool     $taxonomy Whether the attribute is a global one, kept by its terms' slugs.
	 */
	private static function option( string $named, array $values, bool $taxonomy ): string {
		$slug = sanitize_title( $named );
		foreach ( $values as $value ) {
			if ( $taxonomy ? $slug === $value : ( $named === $value || ( $slug === $named && sanitize_title( $value ) === $named ) ) ) {
				return $value;
			}
		}
		return $named;
	}

	/**
	 * Whether the request is for the product's own page.
	 *
	 * @param WC_Product $product A product.
	 */
	private static function is_page_of( WC_Product $product ): bool {
		// The conditional tags answer nothing before the main query has run.
		return did_action( 'wp' ) > 0 && is_product() && get_queried_object_id() === $product->get_id();
	}
}
