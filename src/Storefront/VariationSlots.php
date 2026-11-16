<?php
/**
 * Text that follows the chosen variation, in a place that keeps its size.
 *
 * @package Pixonaut\WooCommerce
 */

declare(strict_types=1);

namespace Pixonaut\WooCommerce\Storefront;

use Pixonaut\WooCommerce\Plugin;
use WC_Product;
use WC_Product_Variable;
use WC_Product_Variation;

/**
 * A place in the add-to-cart form for something each variation says of
 * itself, such as its description or its delivery time, that does not move
 * the page when the shopper chooses another variation.
 *
 * Every variation's text is printed up front, each distinct text once, all
 * of them stacked in the same cell of a grid: the place is as tall as the
 * tallest of them at any width, and only the chosen variation's is visible.
 * While no variation is chosen the place keeps its height and shows
 * nothing. A small script of its own follows the form's choice. The layout
 * is in the elements' own style, so the place keeps its size before any
 * stylesheet loads and on a page the storefront styles are not loaded on.
 *
 * On the page of a variable product the inline designer shows on, the
 * variation's description is printed this way just above the quantity and
 * Add to cart, where the theme's price row starts, in place of the one
 * WooCommerce redraws with each choice (hidden by `storefront.css`).
 */
final class VariationSlots {

	public const SCRIPT_HANDLE = 'pixonaut-variation-slots';

	/** Marks a place the script follows the choice in. */
	public const SLOT_ATTRIBUTE = 'data-pixonaut-variation-slot';

	/** Names the variations, by id and space-separated, a text in a place is of. */
	public const VARIATIONS_ATTRIBUTE = 'data-pixonaut-variations';

	/** Marks the text of the chosen variation. */
	public const CHOSEN_CLASS = 'is-chosen';

	/** The place of a variation's description. */
	public const DESCRIPTION_CLASS = 'pixonaut-variation-description';

	private const SLOT_CLASS = 'pixonaut-variation-slot';
	private const ITEM_CLASS = 'pixonaut-variation-slot__item';

	/** Every text in the one cell, so the place is as tall as the tallest. */
	private const SLOT_STYLE = 'display:grid';
	private const ITEM_STYLE = 'grid-area:1/1;margin:0';

	/** A text that is not the chosen variation's keeps its room, unseen. */
	private const UNCHOSEN_STYLE = ';visibility:hidden';

	/**
	 * Before WooCommerce's quantity input and the price row a theme opens
	 * there (at the default priority).
	 */
	private const DESCRIPTION_PRIORITY = 5;

	/**
	 * @param string         $plugin_file The plugin's main file.
	 * @param InlineProducts $products    Resolves a product to its designer.
	 */
	public function __construct(
		private readonly string $plugin_file,
		private readonly InlineProducts $products,
	) {
	}

	public function register(): void {
		add_action( 'woocommerce_before_add_to_cart_quantity', array( $this, 'print_description' ), self::DESCRIPTION_PRIORITY );
	}

	/**
	 * Prints the place of the chosen variation's description, on the form of
	 * a variable product the inline designer shows on whose variations have
	 * one.
	 */
	public function print_description(): void {
		$product = $GLOBALS['product'] ?? null;
		if ( ! $product instanceof WC_Product_Variable || null === $this->products->for_product( $product ) ) {
			return;
		}
		$texts = array();
		foreach ( self::variations( $product ) as $variation ) {
			// As WooCommerce formats it for its own place.
			$texts[ $variation->get_id() ] = wc_format_content( wp_kses_post( $variation->get_description() ) );
		}
		// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Each text is formatted and filtered above; the markup is escaped where it is built.
		echo $this->varying( self::DESCRIPTION_CLASS, 'div', $texts );
	}

	/**
	 * The place of a text that follows the chosen variation: each
	 * variation's text by its id, HTML, empty for one that has none. Empty
	 * when no variation has one.
	 *
	 * @param string            $css_class The place's own class.
	 * @param string            $tag       The element each text is in: `div` or `p`.
	 * @param array<int,string> $texts     Each variation's text.
	 */
	public function varying( string $css_class, string $tag, array $texts ): string {
		if ( array() === array_filter( $texts, static fn ( string $text ): bool => '' !== $text ) ) {
			return '';
		}
		$by_text = array();
		foreach ( $texts as $id => $text ) {
			if ( '' !== $text ) {
				$by_text[ $text ][] = $id;
			}
		}
		$items = '';
		foreach ( $by_text as $text => $ids ) {
			$items .= self::item( $tag, (string) $text, false, implode( ' ', $ids ) );
		}
		$this->enqueue();
		return sprintf(
			'<div class="%s" style="%s" %s>%s</div>',
			esc_attr( self::SLOT_CLASS . ' ' . $css_class ),
			esc_attr( self::SLOT_STYLE ),
			esc_attr( self::SLOT_ATTRIBUTE ),
			$items
		);
	}

	/**
	 * The same place for a product that has the one text: shown, with no
	 * script. Empty for an empty text.
	 *
	 * @param string $css_class The place's own class.
	 * @param string $tag       The element the text is in: `div` or `p`.
	 * @param string $text      The text, HTML.
	 */
	public function fixed( string $css_class, string $tag, string $text ): string {
		if ( '' === $text ) {
			return '';
		}
		return sprintf(
			'<div class="%s" style="%s">%s</div>',
			esc_attr( self::SLOT_CLASS . ' ' . $css_class ),
			esc_attr( self::SLOT_STYLE ),
			self::item( $tag, $text, true, null )
		);
	}

	/**
	 * The variations the form offers, as WooCommerce lists them in it.
	 *
	 * @param WC_Product_Variable $product The variable product.
	 * @return list<WC_Product_Variation>
	 */
	public static function variations( WC_Product_Variable $product ): array {
		return array_values( $product->get_available_variations( 'objects' ) );
	}

	/**
	 * One text of a place.
	 *
	 * @param string      $tag    `div` or `p`.
	 * @param string      $text   The text, HTML.
	 * @param bool        $chosen Whether it shows.
	 * @param string|null $ids    The variations it is of; null on a product with the one text.
	 */
	private static function item( string $tag, string $text, bool $chosen, ?string $ids ): string {
		$tag = 'p' === $tag ? 'p' : 'div';
		return sprintf(
			'<%1$s class="%2$s" style="%3$s"%4$s>%5$s</%1$s>',
			$tag,
			esc_attr( self::ITEM_CLASS . ( $chosen ? ' ' . self::CHOSEN_CLASS : '' ) ),
			esc_attr( self::ITEM_STYLE . ( $chosen ? '' : self::UNCHOSEN_STYLE ) ),
			null === $ids ? '' : sprintf( ' %s="%s"', self::VARIATIONS_ATTRIBUTE, esc_attr( $ids ) ),
			wp_kses_post( $text )
		);
	}

	/** Loads the script that follows the choice, once, in the footer. */
	private function enqueue(): void {
		if ( wp_script_is( self::SCRIPT_HANDLE, 'enqueued' ) ) {
			return;
		}
		$file  = dirname( $this->plugin_file ) . '/assets/build/variation-slots.asset.php';
		$asset = is_readable( $file ) ? require $file : array();
		wp_enqueue_script(
			self::SCRIPT_HANDLE,
			plugins_url( 'assets/build/variation-slots.js', $this->plugin_file ),
			is_array( $asset['dependencies'] ?? null ) ? $asset['dependencies'] : array(),
			is_string( $asset['version'] ?? null ) ? $asset['version'] : Plugin::VERSION,
			array(
				'in_footer' => true,
				'strategy'  => 'defer',
			)
		);
	}
}
