<?php
/**
 * The rows of a blank's print areas a product sells.
 *
 * @package Pixonaut\WooCommerce
 */

declare(strict_types=1);

namespace Pixonaut\WooCommerce\Admin;

use Pixonaut\WooCommerce\Catalogue\BlankVariant;
use Pixonaut\WooCommerce\Catalogue\MisfitSizes;
use Pixonaut\WooCommerce\Catalogue\ProductConfig;
use Pixonaut\WooCommerce\Catalogue\ProductVariant;
use Pixonaut\WooCommerce\Catalogue\ProductVariants;
use Pixonaut\WooCommerce\Vendor\Pixonaut\Sdk\Exception\ApiException;
use WC_Product;

/**
 * On a blank whose print areas change with an option (`areasVaryBy`), a
 * template can misfit some of the blank's rows, and Pixonaut names every
 * one of them. A product sells only the rows of its own blank variants:
 * a poster sold upright sells none of the blank's landscape rows, however
 * many the blank has. What the product screen says of a template's misfits
 * is about those rows alone.
 *
 * A setup sells the variant of each product or variation it is the setup
 * of: a simple product's or a variation's own, or, for a variable product,
 * that of each variation a customer can choose that follows the product's
 * setup. A variation with a setup of its own is judged by that setup, on
 * its own fields.
 */
final class SoldRows {

	/**
	 * @param LinkedBlank     $blank    Reads the options a blank's print areas change with.
	 * @param ProductVariants $variants Lists a blank's variants.
	 */
	public function __construct(
		private readonly LinkedBlank $blank,
		private readonly ProductVariants $variants,
	) {
	}

	/**
	 * The misfit sizes of a template the product sells under a setup, in
	 * the order given. All of them when the rows it sells cannot be told:
	 * Pixonaut cannot be asked, or the blank's print areas change with no
	 * option.
	 *
	 * @param WC_Product    $product A product or variation.
	 * @param ProductConfig $config  The setup shown for it, on the blank the misfits are of.
	 * @param string[]      $misfits The rows the template cannot be printed at, as `MisfitSizes::value()` names them.
	 * @phpstan-param list<string> $misfits
	 * @return list<string>
	 */
	public function misfits( WC_Product $product, ProductConfig $config, array $misfits ): array {
		if ( array() === $misfits ) {
			return array();
		}
		$sold = $this->of( $product, $config );
		return null === $sold ? $misfits : array_values( array_intersect( $misfits, $sold ) );
	}

	/**
	 * The rows the product sells under a setup, each named as Pixonaut
	 * names it among a template's misfits; null when they cannot be told.
	 *
	 * @param WC_Product    $product A product or variation.
	 * @param ProductConfig $config  The setup shown for it.
	 * @return list<string>|null
	 */
	public function of( WC_Product $product, ProductConfig $config ): ?array {
		if ( null === $config->blank_id ) {
			return null;
		}
		$varies_by = $this->blank->varies_by( $config->blank_id );
		if ( null === $varies_by || array() === $varies_by ) {
			return null;
		}
		try {
			$variants = $this->variants->named( $config->blank_id );
		} catch ( ApiException $e ) {
			return null;
		}
		$sold = self::variants_sold( $product, $config );
		$rows = array();
		foreach ( $variants as $variant ) {
			$row = in_array( $variant->ref, $sold, true ) ? self::row( $variant, $varies_by ) : null;
			if ( null !== $row ) {
				$rows[ MisfitSizes::value( $row ) ] = true;
			}
		}
		return array_map( 'strval', array_keys( $rows ) );
	}

	/**
	 * The refs of the blank variants sold under a setup.
	 *
	 * @param WC_Product    $product A product or variation.
	 * @param ProductConfig $config  The setup shown for it.
	 * @return list<string>
	 */
	private static function variants_sold( WC_Product $product, ProductConfig $config ): array {
		$sellers = array( $product );
		if ( $product->is_type( 'variable' ) ) {
			$sellers = array();
			foreach ( $product->get_children() as $child_id ) {
				$variation = wc_get_product( $child_id );
				if ( $variation instanceof WC_Product && ProductConfig::PUBLIC_STATUS === $variation->get_status() && ! ProductConfig::stored_on( $variation )->override ) {
					$sellers[] = $variation;
				}
			}
		}
		$sold = array();
		foreach ( $sellers as $seller ) {
			$ref = ProductVariant::sold_by( $seller, $config );
			if ( null !== $ref ) {
				$sold[] = $ref;
			}
		}
		return $sold;
	}

	/**
	 * The row a variant is in: its value of each option the print areas
	 * change with, as written in Pixonaut, in the options' order. Null for
	 * a variant lacking one of them, which is in no row.
	 *
	 * @param BlankVariant $variant   A variant of the blank.
	 * @param string[]     $varies_by The options the print areas change with.
	 * @phpstan-param list<string> $varies_by
	 * @return array<string, string>|null
	 */
	private static function row( BlankVariant $variant, array $varies_by ): ?array {
		$options = array() === $variant->written ? $variant->options : $variant->written;
		$row     = array();
		foreach ( $varies_by as $option ) {
			if ( ! isset( $options[ $option ] ) ) {
				return null;
			}
			$row[ $option ] = $options[ $option ];
		}
		return $row;
	}
}
