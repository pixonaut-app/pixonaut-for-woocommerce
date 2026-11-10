<?php
/**
 * A variation's own print size, per side of its product.
 *
 * @package Pixonaut\WooCommerce
 */

declare(strict_types=1);

namespace Pixonaut\WooCommerce\Admin;

use Pixonaut\WooCommerce\Catalogue\InvalidReason;
use Pixonaut\WooCommerce\Catalogue\PostedSides;
use Pixonaut\WooCommerce\Catalogue\PrintSize;
use Pixonaut\WooCommerce\Catalogue\ProductConfig;
use Pixonaut\WooCommerce\Catalogue\ProductVariant;
use Pixonaut\WooCommerce\Catalogue\Side;
use Pixonaut\WooCommerce\Catalogue\VariationSize;
use Pixonaut\WooCommerce\Catalogue\VariationSizes;
use Pixonaut\WooCommerce\Templates;
use WC_Product;

/**
 * In a variation's Pixonaut section, each personalised side of what it
 * inherits says what the variation prints at (on a blank whose print areas
 * change with an option, its blank variant's area) and takes a size of the
 * variation's own instead, judged with Pixonaut on saving. A refused size
 * stays in the field, with the reason. A variation that overrides its whole
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
		$variant   = ProductVariant::sold_by( $variation, $config );
		$inherited = ( new VariationSize() )->applied_to( $config, $variant );
		$own       = VariationSize::stored_on( $variation );
		$rows      = array();
		foreach ( $sides as $side ) {
			$size   = $own->size_of( $side->key );
			$name   = $fields->name( PanelFields::PRINT_SIZE ) . '[' . $side->key . ']';
			$rows[] = array(
				'label'      => $side->label,
				'name'       => $name,
				'id'         => $fields->id( PanelFields::PRINT_SIZE . '_' . $side->key ),
				'inherited'  => self::inherited( $inherited, $side->key ),
				'custom'     => null !== $size,
				'width'      => null === $size ? '' : (string) $size->width_mm,
				'height'     => null === $size ? '' : (string) $size->height_mm,
				'size_label' => null === $size || PrintSize::label_for( $size->width_mm, $size->height_mm ) === $size->label ? '' : $size->label,
				'error'      => self::error( $own, $side, $config->blank_id, $variant ),
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
			$this->sizes->save( $variation, array() );
			return;
		}
		if ( ! isset( $posted[ PanelFields::PRINT_SIZE_LISTED ] ) ) {
			return;
		}
		$this->sizes->save( $variation, self::parsed( $posted[ PanelFields::PRINT_SIZE ] ?? null ) );
	}

	/**
	 * The sizes posted, by side key: each side set to a custom size with a
	 * usable width and height; any other is the product's.
	 *
	 * @param mixed $posted Rows of `kind`, `width`, `height` and `label`, by side key, unslashed.
	 * @return array<string, PrintSize>
	 */
	public static function parsed( mixed $posted ): array {
		$sizes = array();
		foreach ( is_array( $posted ) ? $posted : array() as $key => $row ) {
			if ( ! is_array( $row ) || self::CUSTOM !== ( $row['kind'] ?? null ) ) {
				continue;
			}
			$size = PostedSides::custom_size( map_deep( $row, 'sanitize_text_field' ) );
			if ( null !== $size ) {
				$sizes[ sanitize_text_field( (string) $key ) ] = $size;
			}
		}
		return $sizes;
	}

	/**
	 * The product's choice for a side, as the dropdown names it: the sizes
	 * the variation prints the side at without one of its own.
	 *
	 * @param ProductConfig $inherited What the variation sells without sizes of its own.
	 * @param string        $key       The side's key.
	 */
	private static function inherited( ProductConfig $inherited, string $key ): string {
		$labels = array();
		foreach ( $inherited->sides as $side ) {
			if ( $side->key === $key ) {
				$labels = array_map( static fn ( PrintSize $size ): string => $size->label, $side->print_sizes );
			}
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
	 * Why a side's own size cannot be printed, as the field says it; empty when it can.
	 *
	 * @param VariationSize $own      The variation's own sizes.
	 * @param Side          $side     The side.
	 * @param string|null   $blank_id The blank.
	 * @param string|null   $variant  The variant the variation sells.
	 */
	private static function error( VariationSize $own, Side $side, ?string $blank_id, ?string $variant ): string {
		$entry = $own->sizes[ $side->key ] ?? null;
		if ( null === $entry ) {
			return '';
		}
		if ( null !== $entry['refused'] ) {
			return InvalidReason::message( $entry['refused'] );
		}
		return VariationSize::basis( $side, $blank_id, $variant ) === $entry['basis'] ? '' : InvalidReason::message( InvalidReason::VARIATION_SIZE_UNCHECKED );
	}
}
