<?php
/**
 * Which blank variant each variation of a variable product is.
 *
 * @package Pixonaut\WooCommerce
 */

declare(strict_types=1);

namespace Pixonaut\WooCommerce\Admin;

use Pixonaut\WooCommerce\Catalogue\BlankVariant;
use Pixonaut\WooCommerce\Catalogue\ProductConfig;
use Pixonaut\WooCommerce\Catalogue\ProductVariant;
use Pixonaut\WooCommerce\Catalogue\ProductVariants;
use Pixonaut\WooCommerce\Templates;
use Pixonaut\WooCommerce\Vendor\Pixonaut\Sdk\Exception\ApiException;
use WC_Product;
use WC_Product_Variation;

/**
 * A variable product on a blank shows, in its own panel, which of the
 * blank's variants each of its variations is, and the print area that
 * variant prints at. Each row's choice is the variation's own Variant
 * field: matched by its attributes, or picked here. A variation that no
 * variant matches says so, listed first. Variations with a blank of their
 * own are left out: their variant is of that blank.
 */
final class VariantPairing {

	/**
	 * The posted picks, by variation id.
	 */
	public const FIELD = 'pixonaut_pairing';

	/**
	 * The picks as drawn, by variation id: a row left as it was is not
	 * saved, so a pick made in the variation's own field meanwhile stays.
	 */
	public const DRAWN_FIELD = 'pixonaut_pairing_drawn';

	/**
	 * The blank the rows were drawn for.
	 */
	public const BLANK_FIELD = 'pixonaut_pairing_blank';

	/**
	 * Most rows drawn; the rest are under Variations.
	 */
	public const MAX_ROWS = 50;

	/**
	 * @param ProductVariants $variants  Lists the blank's variants.
	 * @param LinkedBlank     $blank     Reads the variants' print areas.
	 * @param VariantField    $field     Saves a variation's pick.
	 * @param Templates       $templates Renders the rows.
	 */
	public function __construct(
		private readonly ProductVariants $variants,
		private readonly LinkedBlank $blank,
		private readonly VariantField $field,
		private readonly Templates $templates,
	) {
	}

	/**
	 * The rows, as `admin/product-variant-pairing.php` prints them; empty
	 * for a product with no variation on the blank, or while Pixonaut
	 * cannot list its variants.
	 *
	 * @param WC_Product    $product     A variable product.
	 * @param ProductConfig $config      What it is shown with: its blank, and the side whose print area is shown.
	 * @param string        $integration The print partner the blank is from, such as Gelato; empty for the store's own blank.
	 */
	public function html( WC_Product $product, ProductConfig $config, string $integration ): string {
		if ( null === $config->blank_id ) {
			return '';
		}
		$blank_id = $config->blank_id;
		try {
			$variants = $this->variants->variants( $blank_id );
			$named    = $this->variants->named( $blank_id );
		} catch ( ApiException $e ) {
			return '';
		}
		$choices = array();
		foreach ( $variants as $variant ) {
			$choices[ $variant->ref ] = null === $variant->retired
				? self::label( $variant )
				/* translators: %s: the variant's options, e.g. "Color: Red, Size: M". */
				: sprintf( __( '%s (retired)', 'pixonaut-for-woocommerce' ), self::label( $variant ) );
		}
		$placement = $config->sides[0]->placement_id ?? null;
		$areas     = null === $placement ? array() : $this->blank->variant_areas( $blank_id, $placement, $variants );
		$rows      = array();
		foreach ( $product->get_children() as $child ) {
			$variation = wc_get_product( $child );
			if ( ! $variation instanceof WC_Product_Variation || ProductConfig::stored_on( $variation )->override ) {
				continue;
			}
			$rows[] = $this->row( $variation, $blank_id, $named, $choices, $areas, $integration );
		}
		if ( array() === $rows ) {
			return '';
		}
		// The variations to see to first; otherwise in the product's order.
		usort( $rows, static fn ( array $a, array $b ): int => (int) $b['unpaired'] <=> (int) $a['unpaired'] );
		$paired = count( array_filter( $rows, static fn ( array $row ): bool => ! $row['unpaired'] ) );
		return $this->templates->html(
			'admin/product-variant-pairing.php',
			array(
				'rows'        => array_slice( $rows, 0, self::MAX_ROWS ),
				'more'        => max( 0, count( $rows ) - self::MAX_ROWS ),
				'total'       => count( $rows ),
				'paired'      => $paired,
				'printshop'   => '' === $integration ? __( 'Blank', 'pixonaut-for-woocommerce' ) : $integration,
				'blank_id'    => $blank_id,
				'field'       => self::FIELD,
				'drawn_field' => self::DRAWN_FIELD,
				'blank_field' => self::BLANK_FIELD,
			)
		);
	}

	/**
	 * Saves the picks changed in the rows, for the variations of the
	 * product, and leaves each variation's notice to the product's.
	 *
	 * @param WC_Product   $product The variable product.
	 * @param array<mixed> $posted  The picks as posted, unslashed, by variation id.
	 * @param array<mixed> $drawn   The picks as drawn, by variation id.
	 * @param string|null  $bound   The blank the rows were drawn for.
	 */
	public function save( WC_Product $product, array $posted, array $drawn, ?string $bound ): void {
		$children = array_map( 'intval', $product->get_children() );
		foreach ( $posted as $id => $pick ) {
			$was = $drawn[ $id ] ?? null;
			if ( ! is_string( $pick ) || $pick === $was || ! in_array( (int) $id, $children, true ) ) {
				continue;
			}
			$variation = wc_get_product( (int) $id );
			if ( $variation instanceof WC_Product ) {
				$this->field->save( $variation, sanitize_text_field( $pick ), $bound );
			}
		}
	}

	/**
	 * A variant as a row lists it: under a column naming whose variants
	 * they are, a variant of one option is its value alone, `28x40" / 70x100cm`.
	 *
	 * @param BlankVariant $variant The variant.
	 */
	private static function label( BlankVariant $variant ): string {
		return 1 === count( $variant->options ) ? implode( '', $variant->options ) : $variant->label();
	}

	/**
	 * One variation's row.
	 *
	 * @param WC_Product_Variation                              $variation   The variation.
	 * @param string                                            $blank_id    The blank.
	 * @param BlankVariant[]                                    $named       Its variants, named as matching names them.
	 * @param array<string, string>                             $choices     The variants as listed, by ref.
	 * @param array<string, array{width: float, height: float}> $areas       Each variant's print area, by ref.
	 * @param string                                            $integration The print partner the blank is from.
	 * @phpstan-param list<BlankVariant> $named
	 * @return array{id: int, name: string, url: string, picked: string, matched: string, choices: array<string, string>, unpaired: bool, area: string}
	 */
	private function row( WC_Product_Variation $variation, string $blank_id, array $named, array $choices, array $areas, string $integration ): array {
		$stored = ProductVariant::stored_on( $variation );
		$stored = null !== $stored && $stored->blank_id === $blank_id && $stored->is_current( $variation )
			? $stored
			: ProductVariant::resolve( $blank_id, null, ProductVariants::attributes( $variation ), $named );
		if ( null !== $stored->pick && ! isset( $choices[ $stored->pick ] ) ) {
			$choices[ $stored->pick ] = '' === $stored->label ? $stored->pick : $stored->label;
		}
		$area = null === $stored->ref ? null : ( $areas[ $stored->ref ] ?? null );
		return array(
			'id'       => $variation->get_id(),
			'name'     => wc_get_formatted_variation( $variation, true, true, false ),
			'url'      => '#variable_product_options',
			'picked'   => $stored->pick ?? VariantField::MATCH_BY_OPTIONS,
			'matched'  => null === $stored->pick && null !== $stored->ref
				/* translators: %s: the variant found from the variation's attributes, such as "28x40\" / 70x100cm". Option in the variant pairing table of the product edit page. */
				? sprintf( __( '%s (found automatically)', 'pixonaut-for-woocommerce' ), $choices[ $stored->ref ] ?? $stored->label )
				: __( 'Find automatically', 'pixonaut-for-woocommerce' ),
			'choices'  => $choices,
			'unpaired' => null === $stored->ref,
			'area'     => null === $area
				? ''
				/* translators: 1: a print area's width in mm; 2: its height in mm. */
				: sprintf( __( '%1$s × %2$s mm', 'pixonaut-for-woocommerce' ), wc_format_localized_decimal( (string) $area['width'] ), wc_format_localized_decimal( (string) $area['height'] ) ),
		);
	}
}
