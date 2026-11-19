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
 * the blanks they can be drawn on (`ProductPictures::basis()`), and a
 * picture drawn on a blank on that blank's number. A blank never moved is
 * at 0 and adds nothing to what a picture depends on, so pictures drawn
 * before a blank first moves stay current.
 *
 * Each blank's number is an option of its own, moved in one statement:
 * events about several blanks are handled by jobs running side by side,
 * and a move lost to another's write would leave that blank's pictures as
 * they were.
 */
final class BlankPictureGenerations {

	public const OPTION_PREFIX = Schema::BLANK_PICTURE_GENERATION_OPTION_PREFIX;

	public const MOVED_OPTION = Schema::BLANK_PICTURE_GENERATIONS_MOVED_OPTION;

	/**
	 * Moves a blank's number.
	 *
	 * @param string $blank_id The blank.
	 */
	public static function move( string $blank_id ): void {
		global $wpdb;
		$option = self::option( $blank_id );
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- one atomic increment; the option's cached copies are dropped below.
		$wpdb->query(
			$wpdb->prepare(
				"INSERT INTO {$wpdb->options} ( option_name, option_value, autoload ) VALUES ( %s, '1', 'off' ) ON DUPLICATE KEY UPDATE option_value = option_value + 1",
				$option
			)
		);
		// phpcs:enable
		wp_cache_delete( $option, 'options' );
		$missing = wp_cache_get( 'notoptions', 'options' );
		if ( is_array( $missing ) && isset( $missing[ $option ] ) ) {
			unset( $missing[ $option ] );
			wp_cache_set( 'notoptions', $missing, 'options' );
		}
		add_option( self::MOVED_OPTION, '1', '', true );
	}

	/**
	 * A blank's number; 0 until it first moves.
	 *
	 * @param string $blank_id The blank.
	 */
	public static function of( string $blank_id ): int {
		return (int) get_option( self::option( $blank_id ), 0 );
	}

	/**
	 * The numbers that moved of the blanks a product's pictures can be
	 * drawn on, by blank id: a variation's own blank and its product's; a
	 * product's own and its variations', as its picture is one of a
	 * variation it sells. Empty while no blank ever moved.
	 *
	 * @param WC_Product $product A product or variation.
	 * @return array<string, int>
	 */
	public static function of_product( WC_Product $product ): array {
		if ( '1' !== get_option( self::MOVED_OPTION ) ) {
			return array();
		}
		if ( $product->is_type( 'variation' ) ) {
			$parent = wc_get_product( $product->get_parent_id() );
			$blanks = array( $product->get_meta( ProductIndex::BLANK_META ), $parent instanceof WC_Product ? $parent->get_meta( ProductIndex::BLANK_META ) : '' );
		} else {
			$blanks = ProductIndex::blanks_of( $product->get_id() );
		}
		$numbers = array();
		foreach ( $blanks as $blank_id ) {
			$number = is_string( $blank_id ) && '' !== $blank_id ? self::of( $blank_id ) : 0;
			if ( 0 !== $number ) {
				$numbers[ $blank_id ] = $number;
			}
		}
		ksort( $numbers );
		return $numbers;
	}

	/**
	 * @param string $blank_id A blank.
	 */
	private static function option( string $blank_id ): string {
		return self::OPTION_PREFIX . md5( $blank_id );
	}
}
