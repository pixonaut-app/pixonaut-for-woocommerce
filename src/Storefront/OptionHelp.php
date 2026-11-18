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
	 * The block naming an attribute in the block form, which alone is told
	 * which attribute it names.
	 */
	private const NAME_BLOCK = 'woocommerce/add-to-cart-with-options-variation-selector-attribute-name';

	/**
	 * The blocks the block form draws an attribute's options with.
	 */
	private const BLOCK_FIELDS = array( 'woocommerce/product-filter-chips', 'woocommerce/dropdown' );

	/**
	 * The block context naming an attribute, and the one id the block form
	 * gives each of its attributes.
	 */
	private const ATTRIBUTE_NAME_CONTEXT = 'woocommerce/attributeName';
	private const ATTRIBUTE_ID_CONTEXT   = 'woocommerce/attributeId';

	/**
	 * The attribute each of the block form's attribute ids names, as its name
	 * block was told.
	 *
	 * @var array<string, string>
	 */
	private array $block_attributes = array();

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
		add_filter( 'render_block_' . self::NAME_BLOCK, array( $this, 'remember_block_attribute' ), self::PRIORITY, self::BLOCK_ARGS );
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
		$attribute = is_array( $args ) && is_string( $args['attribute'] ?? null ) ? $args['attribute'] : '';
		$help      = $product instanceof WC_Product ? $this->help( $product, $attribute ) : '';
		if ( '' === $help ) {
			return $html;
		}
		$id   = $this->next_id();
		$html = (string) preg_replace( '/<select\b/', '<select aria-describedby="' . esc_attr( $id ) . '"', $html, 1 );
		return $html . self::paragraph( $help, $id );
	}

	/**
	 * The block form's name of an attribute, unchanged: what it names is kept
	 * for the attribute's field, which is not told.
	 *
	 * @param string               $content  The block's output.
	 * @param array<string, mixed> $block    The parsed block.
	 * @param WP_Block|null        $instance The block instance.
	 */
	public function remember_block_attribute( $content, $block, $instance = null ): string {
		$id   = $instance instanceof WP_Block ? ( $instance->context[ self::ATTRIBUTE_ID_CONTEXT ] ?? null ) : null;
		$name = $instance instanceof WP_Block ? ( $instance->context[ self::ATTRIBUTE_NAME_CONTEXT ] ?? null ) : null;
		if ( is_string( $id ) && is_string( $name ) ) {
			$this->block_attributes[ $id ] = $name;
		}
		return (string) $content;
	}

	/**
	 * The block form's field of one attribute, followed by its help.
	 *
	 * @param string               $content  The block's output.
	 * @param array<string, mixed> $block    The parsed block.
	 * @param WP_Block|null        $instance The block instance.
	 */
	public function after_block_field( $content, $block, $instance = null ): string {
		global $product;
		$content = (string) $content;
		$id      = $instance instanceof WP_Block ? ( $instance->context[ self::ATTRIBUTE_ID_CONTEXT ] ?? null ) : null;
		// The same blocks draw the shop's filters, which name no attribute of a product.
		$attribute = is_string( $id ) ? ( $this->block_attributes[ $id ] ?? '' ) : '';
		$help      = '' !== $attribute && $product instanceof WC_Product ? $this->help( $product, $attribute ) : '';
		return '' === $help ? $content : $content . self::paragraph( $help, $this->next_id() );
	}

	/**
	 * The help a product's arrangement gives an attribute; empty for none.
	 *
	 * @param WC_Product $product   The product.
	 * @param string     $attribute The attribute's name, `pa_<slug>` for a global one.
	 */
	private function help( WC_Product $product, string $attribute ): string {
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
		return $this->helps[ $id ][ sanitize_title( $attribute ) ] ?? '';
	}

	/**
	 * The next help element's id.
	 */
	private function next_id(): string {
		++$this->printed;
		return self::HELP_CLASS . '-' . $this->printed;
	}

	/**
	 * The help's element.
	 *
	 * @param string $help Its words.
	 * @param string $id   Its id.
	 */
	private static function paragraph( string $help, string $id ): string {
		return '<p class="' . self::HELP_CLASS . '" id="' . esc_attr( $id ) . '">' . esc_html( $help ) . '</p>';
	}
}
