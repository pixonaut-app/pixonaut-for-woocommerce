<?php
/**
 * The line a product's arrangement prints under an option's field.
 *
 * @package Pixonaut\WooCommerce
 */

declare(strict_types=1);

namespace Pixonaut\WooCommerce\Storefront;

use WC_Product;
use WP_Block;
use WP_HTML_Tag_Processor;

/**
 * When a side's arrangement (`FormGroups`) gives a variation attribute help,
 * the add-to-cart form prints it under that attribute's field, a classic
 * select or the block form's chips or dropdown, after the swatches printed
 * for it: `.pixonaut-option-help`. It is part of the attribute's markup, so
 * it goes wherever the field goes, into the printed form and the designer's,
 * and stays under the field on a page without either.
 */
final class OptionHelp {

	/**
	 * The help's class.
	 */
	public const HELP_CLASS = 'pixonaut-option-help';

	/**
	 * After `PrintedSwatches`, so the help ends the field's markup.
	 */
	private const PRIORITY = 20;

	/**
	 * The arguments `woocommerce_dropdown_variation_attribute_options_html`
	 * and `render_block_{$name}` pass.
	 */
	private const SELECT_ARGS = 2;
	private const BLOCK_ARGS  = 3;

	/**
	 * The blocks the block form draws an attribute's options with, and the
	 * context holding the options.
	 */
	private const BLOCK_FIELDS        = array( 'woocommerce/product-filter-chips', 'woocommerce/dropdown' );
	private const BLOCK_ITEMS_CONTEXT = 'woocommerce/selectableItems';

	/**
	 * What the block form puts before an attribute's slug in its options'
	 * ids (`wc_variation_attribute_name()`).
	 */
	private const OPTION_ID_PREFIX = 'attribute_';

	/**
	 * The roles of the element grouping a block field's chips.
	 */
	private const GROUP_ROLES = array( 'radiogroup', 'group' );

	/**
	 * The attribute naming what describes a control.
	 */
	private const DESCRIBED_BY = 'aria-describedby';

	/**
	 * The help of each product's attributes, by product id and attribute
	 * slug.
	 *
	 * @var array<int, array<string, string>>
	 */
	private array $helps = array();

	/**
	 * Help elements printed so far, which number their ids.
	 *
	 * @var int
	 */
	private int $printed = 0;

	/**
	 * @param InlineProducts $products Resolves a product to its designer.
	 */
	public function __construct( private readonly InlineProducts $products ) {
	}

	public function register(): void {
		add_filter( 'woocommerce_dropdown_variation_attribute_options_html', array( $this, 'after_select' ), self::PRIORITY, self::SELECT_ARGS );
		foreach ( self::BLOCK_FIELDS as $block ) {
			add_filter( 'render_block_' . $block, array( $this, 'after_block_field' ), self::PRIORITY, self::BLOCK_ARGS );
		}
	}

	/**
	 * The attribute's select, described by the help after it.
	 *
	 * @param string $html The select, and what is printed after it so far.
	 * @param mixed  $args `wc_dropdown_variation_attribute_options()`'s arguments.
	 */
	public function after_select( $html, $args ): string {
		$html      = (string) $html;
		$product   = is_array( $args ) ? ( $args['product'] ?? null ) : null;
		$attribute = is_array( $args ) && is_string( $args['attribute'] ?? null ) ? sanitize_title( $args['attribute'] ) : '';
		$help      = $product instanceof WC_Product ? ( $this->helps( $product )[ $attribute ] ?? '' ) : '';
		return '' === $help ? $html : self::described( $html, $help, $this->next_id() );
	}

	/**
	 * The block form's field of one attribute, described by the help after
	 * it. The field is not told its attribute, so it is found by the ids
	 * WooCommerce gives the attribute's options, `attribute_<slug>-<value>`.
	 *
	 * @param string               $content  The block's output.
	 * @param array<string, mixed> $block    The parsed block.
	 * @param WP_Block|null        $instance The block instance, with the attribute's options in its context.
	 */
	public function after_block_field( $content, $block, $instance = null ): string {
		global $product;
		$content = (string) $content;
		$items   = $instance instanceof WP_Block ? ( $instance->context[ self::BLOCK_ITEMS_CONTEXT ]['items'] ?? null ) : null;
		$first   = is_array( $items ) ? reset( $items ) : null;
		$item_id = is_array( $first ) && is_string( $first['id'] ?? null ) ? $first['id'] : '';
		// The same blocks draw the shop's filters, with no attribute of a product.
		if ( '' === $item_id || ! $product instanceof WC_Product ) {
			return $content;
		}
		$help    = '';
		$matched = '';
		foreach ( $this->helps( $product ) as $slug => $words ) {
			// The longest slug wins: `pa_frame-colour-black` starts with `pa_frame-` too.
			if ( str_starts_with( $item_id, self::OPTION_ID_PREFIX . $slug . '-' ) && strlen( $slug ) > strlen( $matched ) ) {
				$matched = $slug;
				$help    = $words;
			}
		}
		return '' === $help ? $content : self::described( $content, $help, $this->next_id() );
	}

	/**
	 * The help a product's arrangement gives each of its attributes, by the
	 * attribute's slug. An attribute has one field on the page, so it has one
	 * line: the first a side gives it.
	 *
	 * @param WC_Product $product The product.
	 * @return array<string, string>
	 */
	private function helps( WC_Product $product ): array {
		$id = $product->get_id();
		if ( ! array_key_exists( $id, $this->helps ) ) {
			$this->helps[ $id ] = array();
			$designer           = is_product() && get_queried_object_id() === $id ? $this->products->for_product( $product ) : null;
			foreach ( null === $designer ? array() : $designer->config->sides as $side ) {
				$groups = $side->form_groups;
				if ( null === $groups ) {
					continue;
				}
				foreach ( $groups->attributes() as $slug ) {
					$help = $groups->attribute_help( $slug );
					if ( '' !== $help && ! isset( $this->helps[ $id ][ $slug ] ) ) {
						$this->helps[ $id ][ $slug ] = $help;
					}
				}
			}
		}
		return $this->helps[ $id ];
	}

	/**
	 * `$html`, a field's markup, followed by the help, the field's control
	 * (its select, or the group of its chips) described by it besides
	 * whatever described it before.
	 *
	 * @param string $html The field's markup.
	 * @param string $help The help's words.
	 * @param string $id   The help's id.
	 */
	private static function described( string $html, string $help, string $id ): string {
		$tags = new WP_HTML_Tag_Processor( $html );
		while ( $tags->next_tag() ) {
			if ( 'SELECT' === $tags->get_tag() || in_array( $tags->get_attribute( 'role' ), self::GROUP_ROLES, true ) ) {
				$before = $tags->get_attribute( self::DESCRIBED_BY );
				$tags->set_attribute( self::DESCRIBED_BY, trim( ( is_string( $before ) ? $before : '' ) . ' ' . $id ) );
				break;
			}
		}
		return $tags->get_updated_html() . '<p class="' . self::HELP_CLASS . '" id="' . esc_attr( $id ) . '">' . esc_html( $help ) . '</p>';
	}

	/**
	 * The next help element's id.
	 */
	private function next_id(): string {
		++$this->printed;
		return self::HELP_CLASS . '-' . $this->printed;
	}
}
