<?php
/**
 * Text that follows the chosen variation, in a place that keeps its size.
 *
 * @package Pixonaut\WooCommerce
 */

declare(strict_types=1);

namespace Pixonaut\WooCommerce\Storefront;

use Pixonaut\WooCommerce\Plugin;
use WC_Product_Variable;
use WC_Product_Variation;

/**
 * A place for something each variation says of itself, such as its
 * description or its delivery time, that does not move the page when the
 * shopper chooses another variation.
 *
 * Every variation's text is printed up front, each distinct text once, all
 * of them stacked in the same cell of a grid: the place is as tall as the
 * tallest of them at any width, and only the chosen variation's is visible.
 * While no variation is chosen the place keeps its height and shows
 * nothing. A small script of its own follows the form's choice. The layout
 * is in the elements' own style, so the place keeps its size before any
 * stylesheet loads and on a page the storefront styles are not loaded on.
 * The place names its product, so it follows that product's form wherever
 * on the page it is printed, such as in the product's attributes table
 * (`DeliveryRows`).
 */
final class VariationSlots {

	public const SCRIPT_HANDLE = 'pixonaut-variation-slots';

	/** Marks a place the script follows the choice in. */
	public const SLOT_ATTRIBUTE = 'data-pixonaut-variation-slot';

	/** Names the product whose add-to-cart form a place follows. */
	public const PRODUCT_ATTRIBUTE = 'data-pixonaut-variation-product';

	/** Names the variations, by id and space-separated, a text in a place is of. */
	public const VARIATIONS_ATTRIBUTE = 'data-pixonaut-variations';

	/** Marks the text of the chosen variation. */
	public const CHOSEN_CLASS = 'is-chosen';

	private const SLOT_CLASS = 'pixonaut-variation-slot';
	private const ITEM_CLASS = 'pixonaut-variation-slot__item';

	/**
	 * Every text in the one cell, so the place is as tall as the tallest. In
	 * properties `wp_kses_post` keeps, as WooCommerce passes a table row's
	 * value through it.
	 */
	private const SLOT_STYLE = 'display:grid';
	private const ITEM_STYLE = 'grid-row:1;grid-column:1;margin:0';

	/** A text that is not the chosen variation's keeps its room, unseen. */
	private const UNCHOSEN_STYLE = ';visibility:hidden';

	/**
	 * The properties the styles above set. `wp_kses_post` keeps `display`
	 * only from WordPress 7.0 and `visibility` only from 7.1.
	 */
	private const STYLE_PROPERTIES = array( 'display', 'grid-row', 'grid-column', 'margin', 'visibility' );

	/**
	 * @param string $plugin_file The plugin's main file.
	 */
	public function __construct(
		private readonly string $plugin_file,
	) {
	}

	/** Hooks into WordPress. */
	public function register(): void {
		add_filter( 'safe_style_css', array( $this, 'keep_styles' ) );
	}

	/**
	 * Lets `wp_kses_post` keep the properties a place's style sets, as it
	 * does itself from WordPress 7.1. Before that it drops `display` or
	 * `visibility`, and every variation's text in a place shows at once,
	 * one under another, until the script hides them.
	 *
	 * @param mixed $properties The CSS properties a style attribute may set.
	 * @return mixed
	 */
	public function keep_styles( mixed $properties ): mixed {
		if ( ! is_array( $properties ) ) {
			return $properties;
		}
		return array_values( array_unique( array_merge( $properties, self::STYLE_PROPERTIES ) ) );
	}

	/**
	 * The place of a text that follows the chosen variation: each
	 * variation's text by its id, HTML, empty for one that has none. Empty
	 * when no variation has one.
	 *
	 * @param int               $product_id The variable product whose form the place follows.
	 * @param string            $css_class  The place's own class.
	 * @param string            $tag        The element each text is in: `div` or `p`.
	 * @param array<int,string> $texts      Each variation's text.
	 */
	public function varying( int $product_id, string $css_class, string $tag, array $texts ): string {
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
			'<div class="%s" style="%s" %s %s="%d">%s</div>',
			esc_attr( self::SLOT_CLASS . ' ' . $css_class ),
			esc_attr( self::SLOT_STYLE ),
			esc_attr( self::SLOT_ATTRIBUTE ),
			esc_attr( self::PRODUCT_ATTRIBUTE ),
			$product_id,
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
