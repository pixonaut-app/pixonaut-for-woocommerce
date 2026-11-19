<?php
/**
 * The number per blank its pictures are drawn under.
 *
 * @package Pixonaut\WooCommerce
 */

declare(strict_types=1);

namespace Pixonaut\WooCommerce\Catalogue;

use Pixonaut\WooCommerce\Schema;
use WC_Product;

/**
 * A blank's photos and print areas can change in Pixonaut under the same
 * ids. Moving the blank's number draws again the pictures of the products
 * using it, and only those: a product's pictures depend on the numbers of
 * the blanks it and its variations use (`ProductPictures::basis()`), and a
 * picture drawn on a blank on that blank's number. A blank never moved is
 * at 0, and adds nothing to what a picture depends on, so pictures drawn
 * before a blank first moves stay current.
 */
final class BlankPictureGenerations {

	public const OPTION = Schema::BLANK_PICTURE_GENERATIONS_OPTION;

	/**
	 * Moves a blank's number.
	 *
	 * @param string $blank_id The blank.
	 */
	public static function move( string $blank_id ): void {
		$all              = self::all();
		$all[ $blank_id ] = ( $all[ $blank_id ] ?? 0 ) + 1;
		update_option( self::OPTION, $all, false );
	}

	/**
	 * A blank's number; 0 until it first moves.
	 *
	 * @param string $blank_id The blank.
	 */
	public static function of( string $blank_id ): int {
		return self::all()[ $blank_id ] ?? 0;
	}

	/**
	 * The numbers of the blanks a product and its variations use, or a
	 * variation and its product and their siblings, that moved, by blank id;
	 * empty when none did. Read only once any blank moved.
	 *
	 * @param WC_Product $product A product or variation.
	 * @return array<string, int>
	 */
	public static function of_product( WC_Product $product ): array {
		$all = self::all();
		if ( array() === $all ) {
			return array();
		}
		$owner = $product->is_type( 'variation' ) ? $product->get_parent_id() : $product->get_id();
		return array_intersect_key( $all, array_flip( ProductIndex::blanks_of( $owner ) ) );
	}

	/**
	 * @return array<string, int>
	 */
	private static function all(): array {
		$all = get_option( self::OPTION, array() );
		if ( ! is_array( $all ) ) {
			return array();
		}
		$numbers = array();
		foreach ( $all as $blank_id => $number ) {
			if ( is_int( $number ) && $number > 0 ) {
				$numbers[ (string) $blank_id ] = $number;
			}
		}
		ksort( $numbers );
		return $numbers;
	}
}
