<?php
/**
 * A variation's own print size, per side of its product.
 *
 * @package Pixonaut\WooCommerce
 */

declare(strict_types=1);

namespace Pixonaut\WooCommerce\Admin;

use Pixonaut\WooCommerce\Catalogue\InvalidDetail;
use Pixonaut\WooCommerce\Catalogue\InvalidReason;
use Pixonaut\WooCommerce\Catalogue\PostedSides;
use Pixonaut\WooCommerce\Catalogue\PrintSize;
use Pixonaut\WooCommerce\Catalogue\ProductConfig;
use Pixonaut\WooCommerce\Catalogue\ProductVariant;
use Pixonaut\WooCommerce\Catalogue\Side;
use Pixonaut\WooCommerce\Catalogue\ValueSizes;
use Pixonaut\WooCommerce\Catalogue\VariationSize;
use Pixonaut\WooCommerce\Catalogue\VariationSizes;
use Pixonaut\WooCommerce\Templates;
use WC_Product;
use WC_Product_Attribute;

/**
 * In a variation's Pixonaut section, each personalised side of what it
 * inherits says what the variation prints at (on a blank whose print areas
 * change with an option, its blank variant's area; with a size per value
 * of an attribute, its value's) and takes a size of the variation's own
 * instead, judged with Pixonaut on saving. A refused size stays in the
 * field, with the reason. A variation that overrides its whole
 * setup sets its sizes there, so this field is hidden and its sizes dropped.
 */
final class VariationSizeField {

	/**
	 * The `kind` of a side printed at the size of the variation's own.
	 */
	public const CUSTOM = PostedSides::SIZE_CUSTOM;

	/**
	 * @param VariationSizes $sizes     Judges and stores the sizes.
	 * @param Templates      $templates Renders the field.
	 */
	public function __construct(
		private readonly VariationSizes $sizes,
		private readonly Templates $templates,
	) {
	}

	/**
	 * The field of a variation; empty when its product personalises no side.
	 *
	 * @param WC_Product  $variation The variation.
	 * @param PanelFields $fields    Names the field.
	 * @param bool        $override  Whether the variation overrides its whole setup.
	 */
	public function html( WC_Product $variation, PanelFields $fields, bool $override ): string {
		$parent = wc_get_product( $variation->get_parent_id() );
		if ( ! $parent instanceof WC_Product ) {
			return '';
		}
		$config = ProductConfig::inherited_by( $parent, $variation );
		$sides  = $config->personalise ? $config->personalised_sides() : array();
		if ( array() === $sides ) {
			return '';
		}
		$variant    = ProductVariant::sold_by( $variation, $config );
		$attributes = ValueSizes::attributes_of( $variation );
		$inherited  = ( new VariationSize() )->applied_to( $config, $variant, $attributes );
		$own        = VariationSize::stored_on( $variation );
		$rows       = array();
		foreach ( $sides as $side ) {
			$size   = $own->size_of( $side->key );
			$value  = VariationSize::value_size( $side, $attributes );
			$name   = $fields->name( PanelFields::PRINT_SIZE ) . '[' . $side->key . ']';
			$rows[] = array(
				'label'      => $side->label,
				'name'       => $name,
				'id'         => $fields->id( PanelFields::PRINT_SIZE . '_' . $side->key ),
				'inherited'  => self::inherited( $inherited, $side, $parent, null !== $value ),
				'custom'     => null !== $size,
				'width'      => null === $size ? '' : (string) $size->width_mm,
				'height'     => null === $size ? '' : (string) $size->height_mm,
				'size_label' => null === $size || PrintSize::label_for( $size->width_mm, $size->height_mm ) === $size->label ? '' : $size->label,
				'error'      => self::error( $own, $side, $config->blank_id, $variant, $value ),
			);
		}
		return $this->templates->html(
			'admin/variation-print-size.php',
			array(
				'listed_name' => $fields->name( PanelFields::PRINT_SIZE_LISTED ),
				'hidden'      => $override,
				'sides'       => $rows,
			)
		);
	}

	/**
	 * Judges and stores what was posted for a variation. A form without the
	 * field keeps the sizes saved; an override drops them.
	 *
	 * @param WC_Product   $variation The variation.
	 * @param array<mixed> $posted    The variation's fields, by `PanelFields` key.
	 */
	public function save( WC_Product $variation, array $posted ): void {
		if ( isset( $posted[ PanelFields::OVERRIDE ] ) ) {
			// Its own setup holds its sizes, its attribute values' among them.
			( new VariationSize() )->save_to( $variation );
			return;
		}
		if ( ! isset( $posted[ PanelFields::PRINT_SIZE_LISTED ] ) ) {
			return;
		}
		$sizes = self::parsed( $posted[ PanelFields::PRINT_SIZE ] ?? null );
		$held  = VariationSize::stored_on( $variation );
		foreach ( $sizes as $key => $size ) {
			// A custom size without a usable width and height keeps the size saved before, rather than falling back to the product's.
			$sizes[ $key ] = $size ?? $held->size_of( (string) $key );
		}
		$this->sizes->save( $variation, $sizes );
	}

	/**
	 * The sizes posted, by side key: each side set to a custom size, null
	 * for one without a usable width and height; a side left out is the
	 * product's.
	 *
	 * @param mixed $posted Rows of `kind`, `width`, `height` and `label`, by side key, unslashed.
	 * @return array<string, PrintSize|null>
	 */
	public static function parsed( mixed $posted ): array {
		$sizes = array();
		foreach ( is_array( $posted ) ? $posted : array() as $key => $row ) {
			if ( ! is_array( $row ) || self::CUSTOM !== ( $row['kind'] ?? null ) ) {
				continue;
			}
			$sizes[ sanitize_text_field( (string) $key ) ] = PostedSides::custom_size( map_deep( $row, 'sanitize_text_field' ) );
		}
		return $sizes;
	}

	/**
	 * The product's choice for a side, as the dropdown names it: the sizes
	 * the variation prints the side at without one of its own, which its
	 * attribute value may give.
	 *
	 * @param ProductConfig $inherited What the variation sells without sizes of its own.
	 * @param Side          $side      The side.
	 * @param WC_Product    $product   The variation's product.
	 * @param bool          $by_value  Whether its attribute value gives the size.
	 */
	private static function inherited( ProductConfig $inherited, Side $side, WC_Product $product, bool $by_value ): string {
		$labels = array();
		foreach ( $inherited->sides as $sold ) {
			if ( $sold->key === $side->key ) {
				$labels = array_map( static fn ( PrintSize $size ): string => $size->label, $sold->print_sizes );
			}
		}
		if ( $by_value && null !== $side->value_sizes && array() !== $labels ) {
			return sprintf(
				/* translators: 1: the product attribute whose value gives the print size, e.g. "Size"; 2: the print size, e.g. "30×40 cm". Option in a variation's print size dropdown: print the side at the size the product gives the variation's value of that attribute. Admin product page. */
				__( 'As its %1$s (%2$s)', 'pixonaut-for-woocommerce' ),
				wc_attribute_label( self::attribute_name( $product, $side->value_sizes->attribute ), $product ),
				implode( ', ', $labels )
			);
		}
		if ( array() === $labels ) {
			/* translators: Option in a variation's print size dropdown: print the side as the product's Pixonaut tab says, which gives no size this variation can be printed at. Admin product page. */
			return __( 'As the product (no size fits this variation)', 'pixonaut-for-woocommerce' );
		}
		return sprintf(
			/* translators: %s: the print sizes, e.g. "300 × 400 mm". Option in a variation's print size dropdown: print the side as the product's Pixonaut tab says. Admin product page. */
			__( 'As the product (%s)', 'pixonaut-for-woocommerce' ),
			implode( ', ', $labels )
		);
	}

	/**
	 * The name of a product's attribute a variation names by its slug,
	 * `pa_size` or a custom attribute's name as a slug.
	 *
	 * @param WC_Product $product The variable product.
	 * @param string     $slug    The attribute's slug.
	 */
	private static function attribute_name( WC_Product $product, string $slug ): string {
		foreach ( $product->get_attributes() as $attribute ) {
			if ( $attribute instanceof WC_Product_Attribute && sanitize_title( $attribute->get_name() ) === $slug ) {
				return $attribute->get_name();
			}
		}
		return $slug;
	}

	/**
	 * Why the size a side prints at, the variation's own or its attribute
	 * value's, cannot be printed, as the field says it; empty when it can.
	 *
	 * @param VariationSize  $own      The variation's sizes.
	 * @param Side           $side     The side.
	 * @param string|null    $blank_id The blank.
	 * @param string|null    $variant  The variant the variation sells.
	 * @param PrintSize|null $value    The size the product gives the variation's attribute value; null for none.
	 */
	private static function error( VariationSize $own, Side $side, ?string $blank_id, ?string $variant, ?PrintSize $value ): string {
		$entry = $own->sizes[ $side->key ] ?? null;
		if ( null !== $entry && VariationSize::is_value( $entry ) && ( null === $value || $value->stored() !== $entry['size']->stored() ) ) {
			$entry = null;
		}
		if ( null === $entry ) {
			return null === $value ? '' : InvalidReason::message( InvalidReason::VARIATION_SIZE_UNCHECKED );
		}
		// An attribute value's size is set on the product, the variation's own in its fields.
		$own_size = ! VariationSize::is_value( $entry );
		if ( null !== $entry['refused'] ) {
			return InvalidReason::message( $entry['refused'], InvalidDetail::of( null, null, $entry['code'] ?? null ), $own_size );
		}
		return VariationSize::basis( $side, $blank_id, $variant ) === $entry['basis'] ? '' : InvalidReason::message( InvalidReason::VARIATION_SIZE_UNCHECKED, null, $own_size );
	}
}
