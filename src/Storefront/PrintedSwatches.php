<?php
/**
 * The variation swatches printed with the page.
 *
 * @package Pixonaut\WooCommerce
 */

declare(strict_types=1);

namespace Pixonaut\WooCommerce\Storefront;

use Pixonaut\WooCommerce\Catalogue\ApplicableAttributes;
use Pixonaut\WooCommerce\Settings\Settings;
use WC_Product;
use WP_Block;

/**
 * When the store draws variation options as swatches
 * (`AttributeOptions::SWATCHES`) and the designer draws the add-to-cart form's
 * variation fields in its own form (a product with one side), each
 * attribute's field, a classic select or the block form's chips or dropdown,
 * is printed with the swatches the storefront script draws for it, in the
 * field's words and order, `.pixonaut-swatches--printed`, so the field the customer
 * sees does not change when the script takes over. They are not a field of
 * their own: `inert` and hidden from assistive technology. The stylesheet
 * shows them in the select's place only while the printed form
 * (`.pixonaut-form__placeholder`) stands in for the designer's; the script
 * swaps them for live swatches. A value Pixonaut draws as a colour or a
 * picture (`SwatchLooks`) is printed as it, with its name beside it or, where
 * the attribute's names are hidden (`SwatchNames`), as its accessible name
 * and tooltip alone. A value whose attribute shows prices (`ChoicePrices`)
 * carries the amount it shows for the choice the form opens on
 * (`ProductChoicePrices`), after its name.
 *
 * @phpstan-import-type Look from SwatchLooks
 * @phpstan-import-type AttributeLooks from SwatchLooks
 */
final class PrintedSwatches {

	/**
	 * The blocks the block form draws an attribute's options with.
	 */
	private const BLOCK_FIELDS = array( 'woocommerce/product-filter-chips', 'woocommerce/dropdown' );

	/**
	 * The block context holding an attribute's options.
	 */
	private const BLOCK_ITEMS_CONTEXT = 'woocommerce/selectableItems';

	/**
	 * The swatch classes.
	 */
	private const SWATCH_CLASS = 'pixonaut-swatches__swatch';
	private const CHOSEN_CLASS = 'is-chosen';
	private const LOOK_CLASS   = 'has-look';
	private const BARE_CLASS   = 'is-bare';
	private const PRICE_CLASS  = 'pixonaut-swatches__price';

	/**
	 * The designer each product's page prints swatches for, by product id;
	 * null for none.
	 *
	 * @var array<int, InlineDesigner|null>
	 */
	private array $prints = array();

	/**
	 * @param InlineProducts           $products Resolves a product to its designer.
	 * @param Settings                 $settings The store's attribute options setting.
	 * @param SwatchLooks              $looks    How each attribute value looks.
	 * @param ProductChoicePrices|null $prices The prices beside the swatches; null prints none.
	 */
	public function __construct(
		private readonly InlineProducts $products,
		private readonly Settings $settings,
		private readonly SwatchLooks $looks,
		private readonly ?ProductChoicePrices $prices = null,
	) {
	}

	public function register(): void {
		add_filter( 'woocommerce_dropdown_variation_attribute_options_html', array( $this, 'with_swatches' ), 10, 2 );
		foreach ( self::BLOCK_FIELDS as $block ) {
			add_filter( 'render_block_' . $block, array( $this, 'block_with_swatches' ), 10, 3 );
		}
	}

	/**
	 * The block form's field of one attribute, followed by its swatches.
	 *
	 * @param string               $content  The block's output.
	 * @param array<string, mixed> $block    The parsed block.
	 * @param WP_Block|null        $instance The block instance, with the attribute's items in its context.
	 */
	public function block_with_swatches( $content, $block, $instance = null ): string {
		global $product;
		$content = (string) $content;
		$items   = $instance instanceof WP_Block ? ( $instance->context[ self::BLOCK_ITEMS_CONTEXT ]['items'] ?? null ) : null;
		// The same blocks draw the shop's filters, with no attribute of a product.
		$designer = $product instanceof WC_Product ? $this->prints( $product ) : null;
		if ( ! is_array( $items ) || null === $designer ) {
			return $content;
		}
		$choices = array();
		$chosen  = '';
		$ids     = array();
		foreach ( $items as $item ) {
			if ( is_array( $item ) && is_scalar( $item['value'] ?? null ) && is_string( $item['label'] ?? null ) ) {
				$choices[ (string) $item['value'] ] = $item['label'];
				$chosen                             = true === ( $item['selected'] ?? null ) ? (string) $item['value'] : $chosen;
				$ids[ (string) $item['value'] ]     = is_string( $item['id'] ?? null ) ? $item['id'] : '';
			}
		}
		$looks = $this->looks->of( $designer );
		$field = self::block_field( array_merge( array_keys( $looks ), ApplicableAttributes::fields( $product ) ), $ids );
		return $content . self::swatches( $choices, $chosen, null === $field ? null : ( $looks[ $field ] ?? null ), null === $field ? array() : $this->prices( $product, $field ) );
	}

	/**
	 * The attribute's select, followed by its swatches.
	 *
	 * @param string $html The select.
	 * @param mixed  $args `wc_dropdown_variation_attribute_options()`'s arguments.
	 */
	public function with_swatches( $html, $args ): string {
		$html     = (string) $html;
		$product  = is_array( $args ) ? ( $args['product'] ?? null ) : null;
		$designer = $product instanceof WC_Product ? $this->prints( $product ) : null;
		if ( null === $designer ) {
			return $html;
		}
		$attribute = is_string( $args['attribute'] ?? null ) ? wc_variation_attribute_name( $args['attribute'] ) : '';
		return $html . self::swatches( self::choices( $html ), (string) ( $args['selected'] ?? '' ), $this->looks->of( $designer )[ $attribute ] ?? null, $this->prices( $product, $attribute ) );
	}

	/**
	 * The printed swatches of `$choices`; empty for no choice.
	 *
	 * @param array<string, string> $choices Each value's words, in order.
	 * @param string                $chosen  The value the field opens on.
	 * @param AttributeLooks|null   $looks   The attribute's looks; null when none of its values has one.
	 * @param array<string, string> $prices  The price each value shows beside its name, by value.
	 */
	private static function swatches( array $choices, string $chosen, ?array $looks = null, array $prices = array() ): string {
		$swatches = '';
		foreach ( $choices as $value => $label ) {
			$look      = $looks['looks'][ (string) $value ] ?? null;
			$bare      = null !== $look && null !== $looks && ! $looks['names'];
			$classes   = array_merge(
				array( self::SWATCH_CLASS ),
				(string) $value === $chosen ? array( self::CHOSEN_CLASS ) : array(),
				null !== $look ? array( self::LOOK_CLASS ) : array(),
				$bare ? array( self::BARE_CLASS ) : array()
			);
			$swatches .= '<span class="' . esc_attr( implode( ' ', $classes ) ) . '"' . ( $bare ? ' title="' . esc_attr( $label ) . '"' : '' ) . '>'
				. ( null === $look ? '' : self::look( $look ) )
				. ( $bare ? '' : ( null === $look && ! isset( $prices[ (string) $value ] ) ? esc_html( $label ) : '<span class="pixonaut-swatches__name">' . esc_html( $label ) . '</span>' ) )
				. ( isset( $prices[ (string) $value ] ) ? '<span class="' . self::PRICE_CLASS . '">' . esc_html( $prices[ (string) $value ] ) . '</span>' : '' )
				. '</span>';
		}
		return '' === $swatches ? '' : '<div class="pixonaut-swatches pixonaut-swatches--printed" aria-hidden="true" inert>' . $swatches . '</div>';
	}

	/**
	 * A value's look, as the storefront script draws it (`swatch-looks.ts`).
	 *
	 * @param array<string, mixed> $look The look.
	 * @phpstan-param Look $look
	 */
	private static function look( array $look ): string {
		if ( 'image' === $look['kind'] ) {
			return '<span class="pixonaut-swatches__look"><img src="' . esc_url( $look['url'] ) . '" alt="" decoding="async" /></span>';
		}
		$colours = $look['colours'];
		$fill    = 1 === count( $colours ) ? $colours[0] : 'linear-gradient(135deg, ' . $colours[0] . ' 50%, ' . $colours[1] . ' 50%)';
		return '<span class="pixonaut-swatches__look" style="' . esc_attr( 'background: ' . $fill ) . '"></span>';
	}

	/**
	 * The field a block field's items are of: the block form names each item
	 * `<field slug>-<value slug>`. Every item's id must be the field's, as
	 * one field's name can begin another's (`frame` and `frame-colour`).
	 *
	 * @param array<string>         $fields The product's fields.
	 * @param array<string, string> $ids    The items' ids, by value.
	 */
	private static function block_field( array $fields, array $ids ): ?string {
		if ( array() === $ids ) {
			return null;
		}
		foreach ( array_unique( $fields ) as $field ) {
			$prefix = sanitize_title( $field ) . '-';
			foreach ( $ids as $value => $id ) {
				if ( $prefix . sanitize_title( (string) $value ) !== $id ) {
					continue 2;
				}
			}
			return $field;
		}
		return null;
	}

	/**
	 * The price each value of an attribute shows beside its swatch for the
	 * choice the form opens on, by value.
	 *
	 * @param WC_Product $product The product.
	 * @param string     $field   The attribute's field.
	 * @return array<string, string>
	 */
	private function prices( WC_Product $product, string $field ): array {
		return null === $this->prices || '' === $field ? array() : $this->prices->texts( $product, $field, OpeningChoice::values( $product ) );
	}

	/**
	 * The designer the product's page prints its fields' swatches for: the
	 * page is the product's, the store draws swatches, and its designer draws
	 * the variation fields in its form. Null when it prints none.
	 *
	 * @param WC_Product $product The product the field is for.
	 */
	private function prints( WC_Product $product ): ?InlineDesigner {
		$id = $product->get_id();
		if ( ! array_key_exists( $id, $this->prints ) ) {
			$designer            = AttributeOptions::swatches( $this->settings ) && is_product() && get_queried_object_id() === $id ? $this->products->for_product( $product ) : null;
			$this->prints[ $id ] = null !== $designer && $designer->available() && 1 === count( $designer->mounts ) ? $designer : null;
		}
		return $this->prints[ $id ];
	}

	/**
	 * The options the select offers, each value's words, as WooCommerce
	 * printed them in it, in its order; "Choose an option" left out.
	 *
	 * @param string $select The select's markup.
	 * @return array<string, string>
	 */
	private static function choices( string $select ): array {
		$choices = array();
		preg_match_all( '/<option\s+value="([^"]*)"[^>]*>(.*?)<\/option>/s', $select, $options, PREG_SET_ORDER );
		foreach ( $options as $option ) {
			$value = html_entity_decode( $option[1], ENT_QUOTES );
			if ( '' !== $value ) {
				$choices[ $value ] = trim( html_entity_decode( wp_strip_all_tags( $option[2] ), ENT_QUOTES ) );
			}
		}
		return $choices;
	}
}
